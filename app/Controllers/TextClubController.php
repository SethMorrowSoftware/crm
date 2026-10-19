<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Event;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Sms;
use App\Services\SmsPolicy;
use App\Services\TextClub;
use Throwable;

/**
 * The Text Club: the I/O half.
 *
 * `Services\TextClub` decides; this fetches, writes and sends. The division
 * is worth keeping strictly here because the decisions are the compliance
 * rules — who may be contacted and on what evidence — and those have to be
 * testable without a database, a network or a clock.
 *
 * Three jobs:
 *
 * 1. **The join paths.** A guest texts JOIN, or fills in a form on the
 *    venue's website. Either way they get an invite and are subscribed to
 *    nothing until they confirm.
 * 2. **The admin page.** The list, how it is growing, the twelve-month offer
 *    calendar, and the button that runs a blast.
 * 3. **The sending**, in chunks from cron, exactly like a campaign — because
 *    it IS a campaign, and reusing that machinery means the frequency cap,
 *    the quiet hours and the per-recipient consent checks are the same code
 *    rather than a second implementation that drifts.
 *
 * ## The one thing this class must never do
 *
 * Write `sms_consent = 1` anywhere except where `TextClub::decide()` has
 * returned `grant_consent`. Every other route to that column is a route to
 * texting somebody who did not agree, and the fine is per message.
 */
final class TextClubController extends Controller
{
    /** How many subscribers one cron tick texts. */
    public const CHUNK = 50;

    /** The honeypot on the public form. Bots fill it; people never see it. */
    public const HONEYPOT = 'company_website';

    /** How many join attempts one address may make in a day. */
    public const MAX_JOINS_PER_DAY = 5;

    /** Where the settings live. */
    public const KEY_CALENDAR = 'text_club.calendar';
    public const KEY_CONFIRM  = 'text_club.confirm_message';
    public const KEY_WELCOME  = 'text_club.welcome_message';
    public const KEY_ENABLED  = 'text_club.public_enabled';

    // =================================================================
    // The admin page
    // =================================================================

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_marketing')) {
            return $refusal;
        }

        $filter = $request->str('status');
        $where  = in_array($filter, TextClub::STATUSES, true) ? 'WHERE t.status = :status' : '';
        $params = $where === '' ? [] : ['status' => $filter];

        $rows = DB::all(
            'SELECT t.*, c.first_name, c.last_name, c.email
               FROM {text_club} t
               LEFT JOIN {contacts} c ON c.id = t.contact_id
             ' . $where . '
              ORDER BY t.created_at DESC
              LIMIT 200',
            $params
        );

        // The figures are over the WHOLE list, never the filtered page: a
        // "subscribers" number that changed when somebody clicked a tab
        // would be read as the list changing.
        $stats = TextClub::growth(DB::all('SELECT status, last_offer_sent_at, offers_redeemed FROM {text_club}'));

        $calendar = $this->calendar();
        $month    = TextClub::monthIn(now_utc(), Settings::timezone());

        return $this->view('text-club/index', [
            'pageTitle'  => 'Text Club',
            'title'      => 'Text Club',
            'rows'       => $rows,
            'stats'      => $stats,
            'filter'     => in_array($filter, TextClub::STATUSES, true) ? $filter : '',
            'calendar'   => $calendar,
            'month'      => $month,
            'thisOffer'  => TextClub::offerForMonth($calendar, $month),
            'venue'      => $this->venue(),
            'publicOn'   => (bool) Settings::get(self::KEY_ENABLED, false),
            'confirmText' => (string) Settings::get(self::KEY_CONFIRM, ''),
            'welcomeText' => (string) Settings::get(self::KEY_WELCOME, ''),
            'canManage'  => can('campaigns_manage'),
            'canSend'    => can('campaigns_send'),
            'statuses'   => TextClub::STATUSES,
        ]);
    }

    /** Save the offer calendar and the wording. */
    public function saveSettings(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $back     = '/text-club';
        $calendar = TextClub::normalizeCalendar($request->input('offers'));

        // EVERY month is validated before ANY is saved. A calendar saved
        // half-way is a year with a hole in it that nobody is told about.
        foreach ($calendar as $month => $offer) {
            $problem = TextClub::validateOffer($offer);

            if ($problem !== null) {
                return $this->refuse($request, 'Month ' . $month . ': ' . $problem, $back);
            }

            if (trim((string) $offer['body']) === '') {
                continue;
            }

            /*
             * And the message as it will really be SENT is checked, not the
             * wording as typed. `offerMessage()` appends the opt-out line
             * when the venue has not written one, so checking the raw body
             * would refuse a perfectly good offer; checking the composed
             * message is what catches one too long to be worth sending.
             */
            $sent = TextClub::offerMessage($this->venue(), $offer);

            if (SmsPolicy::segments($sent) > SmsPolicy::MAX_SEGMENTS) {
                return $this->refuse(
                    $request,
                    'Month ' . $month . '\'s offer is too long to send as a text. '
                        . SmsPolicy::describe($sent),
                    $back
                );
            }
        }

        $confirm = trim($request->str('confirm_message'));
        $welcome = trim($request->str('welcome_message'));

        // The wording is refused on its DISCLOSURES, not its style. This is
        // the spec's "the validator blocks the save": an invite missing its
        // rates notice is otherwise discovered by a regulator.
        if ($confirm !== '') {
            $missing = TextClub::missingDisclosures($confirm, 'confirm');

            if ($missing !== []) {
                return $this->refuse(
                    $request,
                    'The confirmation text still needs: ' . implode('; ', $missing) . '.',
                    $back
                );
            }
        }

        if ($welcome !== '') {
            $missing = TextClub::missingDisclosures($welcome, 'welcome');

            if ($missing !== []) {
                return $this->refuse(
                    $request,
                    'The welcome text still needs: ' . implode('; ', $missing) . '.',
                    $back
                );
            }
        }

        Settings::set(self::KEY_CALENDAR, $calendar);
        Settings::set(self::KEY_CONFIRM, $confirm);
        Settings::set(self::KEY_WELCOME, $welcome);
        Settings::set(self::KEY_ENABLED, $request->bool('public_enabled'));

        Audit::event('text_club.settings_saved', [
            'months_with_offers' => count(array_filter(
                $calendar,
                static fn (array $offer): bool => trim((string) $offer['body']) !== ''
            )),
            'public_form'        => $request->bool('public_enabled'),
        ], 'settings', null);

        return $this->success($request, 'Text Club settings saved.', $back);
    }

    // =================================================================
    // Joining
    // =================================================================

    /**
     * A guest texted JOIN or YES. Called from the inbound path.
     *
     * Static because the inbound webhook has no signed-in user, and
     * deliberately returns the reply rather than sending it: the caller owns
     * the thread the reply belongs on.
     *
     * @param 'join'|'confirm'|'stop' $event
     * @return array{changed:bool, status:?string, reply:?string, reason:string}
     */
    public static function handle(string $event, string $phone, ?int $contactId, string $via = 'keyword'): array
    {
        $number = normalize_phone($phone);

        if ($number === null) {
            return ['changed' => false, 'status' => null, 'reply' => null, 'reason' => 'not a usable number'];
        }

        $row      = self::rowFor($number);
        $decision = TextClub::decide($row, $event, now_utc(), self::confirmWindow());
        $venue    = self::venueStatic();
        $offer    = self::currentOffer();

        $reply = TextClub::replyFor(
            $decision,
            $venue,
            $offer,
            (string) Settings::get(self::KEY_CONFIRM, '') ?: null,
            (string) Settings::get(self::KEY_WELCOME, '') ?: null
        );

        if ($decision['status'] === null) {
            return [
                'changed' => false,
                'status'  => $row === null ? null : (string) $row['status'],
                'reply'   => $reply,
                'reason'  => (string) $decision['reason'],
            ];
        }

        /*
         * ORDER MATTERS HERE, and it is the opposite of the obvious one.
         *
         * The suppression is cleared BEFORE the row is written and before
         * anything is sent, because `Sms::send()` reads it: clearing it
         * afterwards means the confirmation message this whole exchange
         * exists to deliver is the one message the suppression still blocks.
         */
        if ($decision['clear_suppression'] === true) {
            Sms::clearSuppression($number, 'text club ' . $event);
        }

        self::store($number, $contactId, (string) $decision['status'], $event, $via);

        if ($decision['grant_consent'] === true && $contactId !== null) {
            self::grantConsent($contactId, $number, $via);
        }

        Audit::event('text_club.' . $event, [
            'status' => $decision['status'],
            'reason' => $decision['reason'],
            'via'    => $via,
        ], 'text_club', null);

        Event::fire('text_club.' . ($decision['status'] === TextClub::SUBSCRIBED ? 'confirmed' : $event), [
            'contact_id' => $contactId,
            'phone'      => $number,
            'status'     => $decision['status'],
        ]);

        return [
            'changed' => true,
            'status'  => (string) $decision['status'],
            'reply'   => $reply,
            'reason'  => (string) $decision['reason'],
        ];
    }

    /**
     * The public sign-up form.
     *
     * One indistinguishable 404 when the form is switched off, so a venue
     * that has not set up a sending number does not advertise a page that
     * cannot work.
     */
    public function publicForm(Request $request): Response
    {
        if (!(bool) Settings::get(self::KEY_ENABLED, false)) {
            return $this->notFound('That page is not available.');
        }

        return $this->view('public/text-club', [
            'title'      => 'Text Club',
            'venue'      => $this->venue(),
            // The exact words they are agreeing to, shown on the page as
            // well as sent in the text. A disclosure somebody only sees
            // AFTER they have handed over their number is not a disclosure.
            'disclosure' => TextClub::confirmationMessage(
                $this->venue(),
                (string) Settings::get(self::KEY_CONFIRM, '') ?: null
            ),
            'honeypot'   => self::HONEYPOT,
            'bareWide'   => false,
        ], 'layouts/bare');
    }

    /** Somebody filled the form in. */
    public function publicJoin(Request $request): Response
    {
        if (!(bool) Settings::get(self::KEY_ENABLED, false)) {
            return $this->notFound('That page is not available.');
        }

        $back = '/text-club/join';

        // A bot filled the hidden field. Answered with the ordinary thank
        // you, because telling it which check it failed is telling it how to
        // pass next time.
        if (trim($request->str(self::HONEYPOT)) !== '') {
            return $this->success($request, $this->thanks(), $back);
        }

        $phone = normalize_phone($request->str('phone'));

        if ($phone === null) {
            return $this->refuse(
                $request,
                'That does not look like a mobile number. A 10-digit US number is what we need.',
                $back
            );
        }

        if (!$this->withinDailyAllowance($phone)) {
            // Same wording as success. A form that says "you have tried too
            // often" to somebody who typed their own number twice reads as an
            // accusation, and to a bot it is a signal.
            return $this->success($request, $this->thanks(), $back);
        }

        $contactId = $this->resolveContact($phone, $request);

        $outcome = self::handle('join', $phone, $contactId, 'web');

        if ($outcome['reply'] !== null) {
            Sms::send([
                'to'         => $phone,
                'body'       => $outcome['reply'],
                'contact_id' => $contactId,
                // TRANSACTIONAL because its entire purpose is to obtain the
                // consent a marketing message would require. Sent as
                // marketing it would be refused for want of the very consent
                // it is asking for.
                'kind'       => Sms::TRANSACTIONAL,
            ]);
        }

        return $this->success($request, $this->thanks(), $back);
    }

    // =================================================================
    // Sending the offer
    // =================================================================

    /** Start this month's offer going out. */
    public function sendOffer(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_send')) {
            return $refusal;
        }

        $back     = '/text-club';
        $calendar = $this->calendar();
        $month    = (int) ($request->str('month') !== '' ? $request->int('month') : TextClub::monthIn(now_utc(), Settings::timezone()));
        $offer    = TextClub::offerForMonth($calendar, $month);

        if ($offer === null) {
            return $this->refuse($request, 'There is no offer written for that month yet.', $back);
        }

        $body = TextClub::offerMessage($this->venue(), $offer);

        if ($body === '') {
            return $this->refuse($request, 'That month\'s offer has no wording.', $back);
        }

        $due = $this->dueSubscribers();

        if ($due === []) {
            /*
             * Two quite different situations, and one sentence for both reads
             * as a fault in the second case. "Nobody is due an offer" to a
             * venue with no subscribers at all sounds like the send is
             * broken; what they need to know is that the list is empty.
             */
            $subscribers = (int) DB::value(
                'SELECT COUNT(*) FROM {text_club} WHERE status = :s',
                ['s' => TextClub::SUBSCRIBED]
            );

            return $this->refuse(
                $request,
                $subscribers === 0
                    ? 'Nobody has confirmed yet, so there is nobody to send to. Guests join by '
                        . 'texting JOIN to the venue\'s number, and are on the list once they reply YES.'
                    : 'All ' . number_format($subscribers) . ' subscribers have had an offer within the '
                        . 'last ' . TextClub::MIN_DAYS_BETWEEN_OFFERS . ' days, so none is due another yet.',
                $back
            );
        }

        Audit::event('text_club.offer_started', [
            'month'       => $month,
            'offer'       => (string) ($offer['name'] ?? ''),
            'code'        => (string) ($offer['code'] ?? ''),
            'subscribers' => count($due),
            'texts_each'  => SmsPolicy::segments($body),
        ], 'text_club', null);

        $sent = self::sendOfferChunk($month, self::CHUNK);

        return $this->success(
            $request,
            'Sending to ' . number_format(count($due)) . ' subscriber'
                . (count($due) === 1 ? '' : 's') . '. ' . $sent . ' gone so far; '
                . 'the rest follow a batch a minute.',
            $back
        );
    }

    /**
     * One batch of this month's offer. Called from cron and from the button.
     *
     * Returns how many were attempted, so cron can keep calling until it
     * returns 0 — the same shape as a campaign chunk, for the same reason.
     */
    public static function sendOfferChunk(?int $month = null, int $limit = self::CHUNK): int
    {
        $calendar = TextClub::normalizeCalendar(Settings::get(self::KEY_CALENDAR, TextClub::defaultCalendar()));
        $zone     = Settings::timezone();
        $month    = $month ?? TextClub::monthIn(now_utc(), $zone);
        $offer    = TextClub::offerForMonth($calendar, $month);

        if ($offer === null) {
            return 0;
        }

        $body = TextClub::offerMessage(self::venueStatic(), $offer);

        if ($body === '') {
            return 0;
        }

        $rows = self::dueSubscribersStatic($limit);

        if ($rows === []) {
            return 0;
        }

        $attempted = 0;

        foreach ($rows as $row) {
            $attempted++;

            $result = Sms::send([
                'to'         => (string) $row['phone_normalized'],
                'body'       => $body,
                'contact_id' => $row['contact_id'] === null ? null : (int) $row['contact_id'],
                // MARKETING, so every one of the send path's own consent
                // checks applies. A subscriber who has since been suppressed,
                // or whose contact was marked do-not-contact, is refused
                // there rather than here — one implementation of the rules,
                // not two.
                'kind'       => Sms::MARKETING,
            ]);

            /*
             * `last_offer_sent_at` is stamped only on a message that really
             * went. Stamping it on a refusal would start the frequency cap
             * running for somebody who was never sent anything, and they
             * would then be skipped for the next three weeks as well.
             *
             * A quiet-hours deferral is NOT a failure and is not stamped
             * either: the message is coming later tonight, and this
             * subscriber stays due until it does.
             */
            if ($result['sent'] === true) {
                DB::update('text_club', [
                    'last_offer_sent_at' => now_utc(),
                    'updated_at'         => now_utc(),
                ], 'phone_normalized = :p', ['p' => (string) $row['phone_normalized']]);
            }
        }

        return $attempted;
    }

    /**
     * Subscribers a cron tick should look at.
     *
     * @return list<array<string,mixed>>
     */
    private static function dueSubscribersStatic(int $limit): array
    {
        /*
         * The frequency cap is applied IN SQL as well as in the pure helper.
         * The list can run to tens of thousands and a chunk of 50 has to be
         * 50 people who are actually due — filtering in PHP after a LIMIT
         * reads 50 rows, discards 49 of them and sends one, so a list that
         * has mostly been sent to this month never finishes.
         */
        $cutoff = gmdate('Y-m-d H:i:s', time() - TextClub::MIN_DAYS_BETWEEN_OFFERS * 86400);

        return DB::all(
            'SELECT t.phone_normalized, t.contact_id, t.last_offer_sent_at
               FROM {text_club} t
              WHERE t.status = :status
                AND (t.last_offer_sent_at IS NULL OR t.last_offer_sent_at < :cutoff)
              ORDER BY t.last_offer_sent_at IS NULL DESC, t.id
              LIMIT ' . max(1, $limit),
            ['status' => TextClub::SUBSCRIBED, 'cutoff' => $cutoff]
        );
    }

    /** @return list<array<string,mixed>> */
    private function dueSubscribers(): array
    {
        return self::dueSubscribersStatic(100000);
    }

    /**
     * Is there an offer to send, and anybody to send it to?
     *
     * Read by cron so a tick does no work at all in a month with no offer.
     */
    public static function offerPending(): bool
    {
        $calendar = TextClub::normalizeCalendar(Settings::get(self::KEY_CALENDAR, TextClub::defaultCalendar()));
        $month    = TextClub::monthIn(now_utc(), Settings::timezone());

        if (TextClub::offerForMonth($calendar, $month) === null) {
            return false;
        }

        return self::dueSubscribersStatic(1) !== [];
    }

    // =================================================================
    // Storage
    // =================================================================

    /** @return array<string,mixed>|null */
    private static function rowFor(string $number): ?array
    {
        return DB::first(
            'SELECT * FROM {text_club} WHERE phone_normalized = :p',
            ['p' => $number]
        );
    }

    private static function store(
        string $number,
        ?int $contactId,
        string $status,
        string $event,
        string $via
    ): void {
        $now = now_utc();

        $columns = [
            'status'     => $status,
            'updated_at' => $now,
        ];

        // The contact is only ever ATTACHED, never detached: an inbound text
        // from a number we have since linked to a guest should fill it in,
        // and a later message with no contact resolved must not blank it.
        if ($contactId !== null) {
            $columns['contact_id'] = $contactId;
        }

        if ($event === 'join') {
            $columns['double_optin_sent_at'] = $now;
        }

        if ($status === TextClub::SUBSCRIBED) {
            $columns['confirmed_at'] = $now;
        }

        if ($status === TextClub::UNSUBSCRIBED) {
            $columns['unsubscribed_at'] = $now;
        }

        try {
            if (self::rowFor($number) !== null) {
                DB::update('text_club', $columns, 'phone_normalized = :p', ['p' => $number]);

                return;
            }

            DB::insert('text_club', $columns + [
                'phone_normalized' => $number,
                'contact_id'       => $contactId,
                'joined_via'       => mb_substr($via, 0, 80),
                'created_at'       => $now,
            ]);
        } catch (Throwable $e) {
            /*
             * The unique key on the number means two simultaneous JOINs race
             * here, and the loser's insert throws. That is not a failure
             * worth telling the guest about — the row exists either way —
             * so it is retried as an update and left at that.
             */
            try {
                DB::update('text_club', $columns, 'phone_normalized = :p', ['p' => $number]);
            } catch (Throwable) {
                \App\Core\Log::error('Could not record a Text Club change', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Grant SMS consent, with the evidence.
     *
     * The ONLY place in this class that writes `sms_consent = 1`, reached
     * only when `TextClub::decide()` returned `grant_consent`. The
     * `consent_log` row is written in the same breath because the consent is
     * worth nothing without the evidence: what matters months later is when
     * they agreed, to what, and by what route.
     */
    private static function grantConsent(int $contactId, string $number, string $via): void
    {
        $now = now_utc();

        try {
            DB::update('contacts', [
                'sms_consent'        => 1,
                'sms_consent_at'     => $now,
                'sms_consent_source' => mb_substr('text club (' . $via . ')', 0, 80),
                'updated_at'         => $now,
            ], 'id = :id', ['id' => $contactId]);

            DB::insert('consent_log', [
                'contact_id' => $contactId,
                'channel'    => 'sms',
                'action'     => 'granted',
                'source'     => mb_substr('text club (' . $via . ')', 0, 80),
                'evidence'   => json_encode([
                    'route'      => $via,
                    'phone'      => $number,
                    // The exact wording they were shown and agreed to, stored
                    // as it was at the time: the disclosure a venue has to be
                    // able to produce is the one from that day, not whatever
                    // the setting says now.
                    'disclosure' => TextClub::confirmationMessage(
                        self::venueStatic(),
                        (string) Settings::get(self::KEY_CONFIRM, '') ?: null
                    ),
                    'confirmed_by' => 'YES',
                ]),
                'created_at' => $now,
            ]);

            /*
             * The Text Club's own `text_club.confirmed` says somebody joined
             * the LIST; this says they granted SMS consent, which is a
             * different fact with different consequences — it is what the
             * send path checks, and what a venue's "now you can text them"
             * automations hang off. This method is the only writer of
             * `sms_consent = 1` on this path, so it is where the event
             * belongs.
             */
            Event::fire('contact.consent_granted', [
                'contact_id' => $contactId,
                'channel'    => 'sms',
                'source'     => 'text club (' . $via . ')',
            ]);
        } catch (Throwable $e) {
            \App\Core\Log::error('Could not record Text Club consent', ['error' => $e->getMessage()]);
        }
    }

    // =================================================================
    // Odds and ends
    // =================================================================

    /** @return array<int, array<string,mixed>> */
    private function calendar(): array
    {
        $stored = Settings::get(self::KEY_CALENDAR);
        $raw    = $stored === null || $stored === '' ? TextClub::defaultCalendar() : $stored;

        return TextClub::normalizeCalendar($raw);
    }

    /** @return array<string,mixed>|null */
    private static function currentOffer(): ?array
    {
        $calendar = TextClub::normalizeCalendar(Settings::get(self::KEY_CALENDAR, TextClub::defaultCalendar()));

        return TextClub::offerForMonth($calendar, TextClub::monthIn(now_utc(), Settings::timezone()));
    }

    /** @return array{name:string, phone:string} */
    private function venue(): array
    {
        return self::venueStatic();
    }

    /** @return array{name:string, phone:string} */
    private static function venueStatic(): array
    {
        return [
            'name'  => Settings::venueName(),
            'phone' => (string) Settings::get('venue.phone_display', ''),
        ];
    }

    private static function confirmWindow(): int
    {
        return max(1, (int) Settings::get('text_club.confirm_window_days', TextClub::CONFIRM_WINDOW_DAYS));
    }

    /** The same sentence whatever happened, so the form tells a bot nothing. */
    private function thanks(): string
    {
        return 'Thank you. If that number can receive texts, a message is on its way — '
            . 'reply YES to it and you are in.';
    }

    /**
     * Has this number already asked today?
     *
     * Counted on the NUMBER rather than the IP: a whole family on one phone
     * network shares an address, and the thing worth limiting is repeated
     * texts to one handset.
     */
    private function withinDailyAllowance(string $phone): bool
    {
        try {
            $since = gmdate('Y-m-d H:i:s', time() - 86400);

            $attempts = (int) DB::value(
                "SELECT COUNT(*) FROM {messages}
                  WHERE channel = 'sms' AND direction = 'outbound'
                    AND to_address = :p AND created_at >= :since",
                ['p' => $phone, 'since' => $since]
            );

            return $attempts < self::MAX_JOINS_PER_DAY;
        } catch (Throwable) {
            // A counting failure must not stop somebody joining. The cost of
            // being wrong this way is one extra text; the other way is a
            // sign-up form that quietly refuses everybody.
            return true;
        }
    }

    /**
     * The contact this number belongs to, created if need be.
     *
     * A sign-up from a number the venue has never seen is a LEAD, not an
     * error — list growth is the entire point of the feature. The contact
     * carries no consent until the confirmation; it is only somebody to
     * attach the subscription and its evidence to.
     */
    private function resolveContact(string $phone, Request $request): ?int
    {
        try {
            $existing = DB::first(
                'SELECT id FROM {contacts} WHERE phone_normalized = :p AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['p' => $phone]
            );

            if ($existing !== null) {
                return (int) $existing['id'];
            }

            $first = trim($request->str('first_name'));

            return (int) DB::insert('contacts', [
                'first_name'       => $first === '' ? null : mb_substr($first, 0, 80),
                'phone'            => $phone,
                'phone_normalized' => $phone,
                'source'           => 'text club',
                'lifecycle_stage'  => 'lead',
                // NOT consented. The form is a request for an invite, and the
                // invite is what asks for consent.
                'sms_consent'      => 0,
                'created_at'       => now_utc(),
                'updated_at'       => now_utc(),
            ]);
        } catch (Throwable $e) {
            \App\Core\Log::error('Could not resolve a Text Club sign-up', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
