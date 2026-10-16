<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The Text Club: the opt-in SMS list, and the rules that decide who is on it.
 *
 * Everything here is decidable without a database, a network or a clock — the
 * times are passed in. Its I/O half is `TextClubController`, which fetches,
 * writes and sends but decides nothing.
 *
 * ## Why this is a state machine and not three functions
 *
 * A guest can text JOIN twice, text YES having never joined, text YES to a
 * prompt from four months ago, text JOIN after a STOP, or text STOP and then
 * JOIN and then YES. Every one of those happens, and every one of them has a
 * different right answer — several of which are "do nothing". Written as
 * three handlers the awkward combinations end up as whichever branch was
 * written last; written as one table they are each a row somebody can read
 * and a test can pin. `decide()` is that table.
 *
 * ## The two rules that are not negotiable
 *
 * 1. **CONSENT IS GRANTED ONLY BY A CONFIRMATION OF A REAL, RECENT INVITE.**
 *    A `YES` with no pending invite subscribes nobody. This is not caution
 *    for its own sake: "yes" is the most ordinary word in a text message, and
 *    a guest answering "yes" to *are you still coming Saturday?* has not
 *    joined a marketing list. The venue's number receives both kinds of
 *    message on the same thread. So `YES` confirms only when this guest was
 *    actually asked, and only within `CONFIRM_WINDOW_DAYS` — a confirmation
 *    of something nobody remembers requesting is not consent either.
 *
 * 2. **A JOIN CLEARS THE "DO NOT TEXT" INSTRUCTION; ONLY A YES GRANTS
 *    CONSENT.** These come apart, and the reason is the rejoin: somebody who
 *    texted STOP last summer and texts JOIN today has asked to be texted, so
 *    the suppression that would otherwise block even the confirmation message
 *    has to go — but they have not yet consented to marketing, so nothing
 *    marketing may be sent until the YES. Conflating the two either blocks
 *    every rejoin for ever or treats a JOIN as full consent, and the second
 *    is the expensive one.
 *
 * The confirmation message itself is TRANSACTIONAL, because its entire
 * purpose is to obtain the consent that a marketing message would require.
 */
final class TextClub
{
    public const PENDING      = 'pending';
    public const SUBSCRIBED   = 'subscribed';
    public const UNSUBSCRIBED = 'unsubscribed';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::SUBSCRIBED, self::UNSUBSCRIBED];

    /**
     * How long an unanswered invite stays confirmable.
     *
     * A week. Long enough that somebody who joined on Saturday and read
     * their texts on Tuesday is still in, short enough that a `YES` months
     * later is treated as the ordinary word it almost certainly is.
     */
    public const CONFIRM_WINDOW_DAYS = 7;

    /**
     * The least time between two offers to the same subscriber.
     *
     * The calendar is monthly, so this only has to stop a hand-run blast
     * landing on top of the monthly offer. Twenty days rather than thirty:
     * a thirty-day floor means a month whose offer went out on the 2nd
     * cannot send on the 1st of the next month, and the monthly offer then
     * drifts later every month until it falls off the end.
     */
    public const MIN_DAYS_BETWEEN_OFFERS = 20;

    /** Where a subscriber came from. Recorded, because consent needs a source. */
    public const SOURCES = ['keyword', 'web', 'staff', 'form', 'booking', 'waiver', 'import'];

    /** The events a subscriber's state can turn on. */
    public const EVENTS = ['join', 'confirm', 'stop'];

    // =================================================================
    // The state machine
    // =================================================================

    /**
     * What an event means, given the row that already exists.
     *
     * Returns an instruction rather than performing one, so the awkward
     * combinations can be tested without a database and the I/O half has no
     * decisions left in it.
     *
     * @param array{status?:string, double_optin_sent_at?:?string, confirmed_at?:?string}|null $row
     *        The subscriber's current row, or null if they have none.
     * @param string $event     One of EVENTS.
     * @param string $nowUtc    'Y-m-d H:i:s' UTC.
     * @param int    $windowDays How long an invite stays confirmable.
     *
     * @return array{
     *     status: ?string,
     *     reply: ?string,
     *     send_confirmation: bool,
     *     grant_consent: bool,
     *     clear_suppression: bool,
     *     welcome: bool,
     *     reason: string
     * }
     */
    public static function decide(
        ?array $row,
        string $event,
        string $nowUtc,
        int $windowDays = self::CONFIRM_WINDOW_DAYS
    ): array {
        $status = $row === null ? null : (string) ($row['status'] ?? self::PENDING);

        return match ($event) {
            'join'    => self::decideJoin($status),
            'confirm' => self::decideConfirm($row, $status, $nowUtc, $windowDays),
            'stop'    => self::decideStop($status),
            default   => self::nothing('not an event this list knows'),
        };
    }

    /** @return array<string,mixed> */
    private static function decideJoin(?string $status): array
    {
        // Already in. Re-confirming would mean a second welcome coupon for
        // somebody who is already a subscriber, which is how a venue ends up
        // giving the same guest free go-karts every time they text.
        if ($status === self::SUBSCRIBED) {
            return self::nothing('already subscribed', 'already_in');
        }

        /*
         * Everything else — never seen, invited but unanswered, or a rejoin
         * after a STOP — gets the same treatment: an invite.
         *
         * The rejoin is the case worth looking at. The suppression is cleared
         * here, at the JOIN, or `Sms::send()` would refuse the confirmation
         * message and the guest could never get back on the list however
         * often they asked. Consent is NOT granted here; that waits for the
         * YES, which is the whole point of a double opt-in.
         */
        return [
            'status'            => self::PENDING,
            'reply'             => 'confirm_prompt',
            'send_confirmation' => true,
            'grant_consent'     => false,
            'clear_suppression' => true,
            'welcome'           => false,
            'reason'            => $status === self::UNSUBSCRIBED
                ? 'rejoining after opting out'
                : ($status === self::PENDING ? 'asked again before confirming' : 'new invite'),
        ];
    }

    /**
     * @param array{double_optin_sent_at?:?string}|null $row
     * @return array<string,mixed>
     */
    private static function decideConfirm(?array $row, ?string $status, string $nowUtc, int $windowDays): array
    {
        // A YES from somebody who was never invited is just the word yes.
        // See rule 1 in the class comment: this is the case that keeps an
        // answer to "are you still coming Saturday?" off the marketing list.
        if ($row === null) {
            return self::nothing('no invite is outstanding, so this is an ordinary message');
        }

        if ($status === self::SUBSCRIBED) {
            return self::nothing('already subscribed', 'already_in');
        }

        // A YES after a STOP is not a rejoin. JOIN is how somebody comes
        // back, and it is deliberately the more deliberate word.
        if ($status === self::UNSUBSCRIBED) {
            return self::nothing('they opted out; JOIN is how to come back');
        }

        $sentAt = isset($row['double_optin_sent_at']) ? (string) $row['double_optin_sent_at'] : '';

        if ($sentAt === '') {
            return self::nothing('no invite was ever sent, so there is nothing to confirm');
        }

        if (!self::withinDays($sentAt, $nowUtc, $windowDays)) {
            return self::nothing('the invite is too old to confirm', 'stale_confirm');
        }

        return [
            'status'            => self::SUBSCRIBED,
            'reply'             => 'welcome',
            'send_confirmation' => false,
            'grant_consent'     => true,
            // Cleared again, not instead: a rejoin's JOIN already cleared it,
            // but a number suppressed between the invite and the YES — by a
            // STOP on another thread, or by a bounce — would otherwise be
            // confirmed onto a list it can never be sent anything from.
            'clear_suppression' => true,
            'welcome'           => true,
            'reason'            => 'confirmed a recent invite',
        ];
    }

    /** @return array<string,mixed> */
    private static function decideStop(?string $status): array
    {
        /*
         * A STOP is honoured whether or not this list has ever heard of them.
         * `Sms::optOut()` writes the suppression itself and does not wait for
         * a lookup, so this only has to settle the LIST row — and when there
         * is no row there is nothing for it to settle.
         */
        if ($status === null) {
            return self::nothing('not on the list; the suppression is what matters');
        }

        return [
            'status'            => self::UNSUBSCRIBED,
            'reply'             => null,
            'send_confirmation' => false,
            'grant_consent'     => false,
            'clear_suppression' => false,
            'welcome'           => false,
            'reason'            => 'opted out',
        ];
    }

    /**
     * Change nothing.
     *
     * @return array<string,mixed>
     */
    private static function nothing(string $reason, ?string $reply = null): array
    {
        return [
            'status'            => null,
            'reply'             => $reply,
            'send_confirmation' => false,
            'grant_consent'     => false,
            'clear_suppression' => false,
            'welcome'           => false,
            'reason'            => $reason,
        ];
    }

    // =================================================================
    // Time
    // =================================================================

    /** Is `$stamp` within `$days` of `$nowUtc`? Both 'Y-m-d H:i:s' UTC. */
    public static function withinDays(string $stamp, string $nowUtc, int $days): bool
    {
        $then = self::parse($stamp);
        $now  = self::parse($nowUtc);

        if ($then === null || $now === null) {
            return false;
        }

        // A stamp in the FUTURE is not within the window either. A clock that
        // jumped, or a row written by a server an hour ahead, would otherwise
        // make an invite confirmable for ever.
        if ($then > $now) {
            return false;
        }

        return ($now->getTimestamp() - $then->getTimestamp()) <= $days * 86400;
    }

    /**
     * Is this subscriber due another offer?
     *
     * Never sent one, or the last was longer ago than the floor. A null
     * `$lastSent` means due: the frequency cap exists to stop a second
     * message, not to delay the first.
     */
    public static function offerDue(
        ?string $lastSent,
        string $nowUtc,
        int $minDays = self::MIN_DAYS_BETWEEN_OFFERS
    ): bool {
        if ($lastSent === null || trim($lastSent) === '') {
            return true;
        }

        return !self::withinDays($lastSent, $nowUtc, $minDays);
    }

    private static function parse(string $stamp): ?DateTimeImmutable
    {
        $stamp = trim($stamp);

        if ($stamp === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($stamp, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    // =================================================================
    // The offer calendar
    // =================================================================

    /**
     * The venue's year of offers, as shipped.
     *
     * A twelve-month calendar rather than one standing offer, because an
     * arcade's quiet months are not its busy ones and the offer that fills a
     * February Tuesday is not the one to give away in July. Each is written
     * to fit a single GSM-7 text once the opt-out line is added — a two-text
     * monthly offer to a list of ten thousand doubles the bill for wording
     * nobody chose.
     *
     * @return array<int, array{name:string, body:string, code:string, type:string, value:float}>
     */
    public static function defaultCalendar(): array
    {
        return [
            1  => ['name' => 'New Year free game',    'code' => 'CASTLEJAN', 'type' => 'free_item', 'value' => 0,
                   'body' => 'Beat the January quiet: one free arcade game with any card load this month.'],
            2  => ['name' => 'Presidents week BOGO',  'code' => 'CASTLEFEB', 'type' => 'bogo',      'value' => 0,
                   'body' => 'School break at the Castle: buy one go-kart ride, get one free all February.'],
            3  => ['name' => 'Spring $5 off',         'code' => 'CASTLEMAR', 'type' => 'fixed',     'value' => 5,
                   'body' => 'Spring is here: $5 off any $25 card load this month.'],
            4  => ['name' => 'Rainy day 20%',         'code' => 'CASTLEAPR', 'type' => 'percent',   'value' => 20,
                   'body' => 'April showers? 20% off indoor attractions all month.'],
            5  => ['name' => 'Mini golf opening',     'code' => 'CASTLEMAY', 'type' => 'bogo',      'value' => 0,
                   'body' => 'Mini golf is open: two rounds for the price of one this May.'],
            6  => ['name' => 'Last day of school',    'code' => 'CASTLEJUN', 'type' => 'fixed',     'value' => 10,
                   'body' => 'School is out: $10 off any $40 card load to start the summer.'],
            7  => ['name' => 'Summer weekday deal',   'code' => 'CASTLEJUL', 'type' => 'percent',   'value' => 15,
                   'body' => '15% off Monday to Thursday all July. Beat the weekend queues.'],
            8  => ['name' => 'Back to school',        'code' => 'CASTLEAUG', 'type' => 'bogo',      'value' => 0,
                   'body' => 'One more before school: buy one laser tag game, get one free this August.'],
            9  => ['name' => 'Autumn karts',          'code' => 'CASTLESEP', 'type' => 'fixed',     'value' => 5,
                   'body' => 'Cooler weather, faster laps: $5 off go-karts this September.'],
            10 => ['name' => 'Halloween weekend',     'code' => 'CASTLEOCT', 'type' => 'percent',   'value' => 20,
                   'body' => '20% off the whole arcade this Halloween weekend.'],
            11 => ['name' => 'Thanksgiving break',    'code' => 'CASTLENOV', 'type' => 'bogo',      'value' => 0,
                   'body' => 'Thanksgiving break: buy one bowling game, get one free.'],
            12 => ['name' => 'Holiday card bonus',    'code' => 'CASTLEDEC', 'type' => 'fixed',     'value' => 10,
                   'body' => 'Holiday bonus: $10 extra on every $50 game card this December.'],
        ];
    }

    /**
     * This month's offer, or null if the venue has not written one.
     *
     * @param array<int|string, array<string,mixed>> $calendar
     * @return array<string,mixed>|null
     */
    public static function offerForMonth(array $calendar, int $month): ?array
    {
        if ($month < 1 || $month > 12) {
            return null;
        }

        $offer = $calendar[$month] ?? $calendar[(string) $month] ?? null;

        if (!is_array($offer)) {
            return null;
        }

        // A month present but blank is "no offer this month", which is a
        // legitimate thing for a venue to want and must not read as one.
        return trim((string) ($offer['body'] ?? '')) === '' ? null : $offer;
    }

    /**
     * Read a stored or posted calendar into the shape the rest of this uses.
     *
     * Unknown months are dropped and unknown keys ignored, so a hand-edited
     * setting or an older stored shape cannot put something unprintable into
     * a text message.
     *
     * @return array<int, array{name:string, body:string, code:string, type:string, value:float}>
     */
    public static function normalizeCalendar(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        for ($month = 1; $month <= 12; $month++) {
            $offer = $raw[$month] ?? $raw[(string) $month] ?? null;

            if (!is_array($offer)) {
                continue;
            }

            $type = (string) ($offer['type'] ?? 'percent');

            $out[$month] = [
                'name'  => mb_substr(trim((string) ($offer['name'] ?? '')), 0, 120),
                'body'  => mb_substr(trim((string) ($offer['body'] ?? '')), 0, 400),
                'code'  => mb_strtoupper(mb_substr(trim((string) ($offer['code'] ?? '')), 0, 40)),
                'type'  => in_array($type, ['percent', 'fixed', 'bogo', 'free_item'], true) ? $type : 'percent',
                'value' => max(0.0, round((float) ($offer['value'] ?? 0), 2)),
            ];
        }

        return $out;
    }

    /**
     * What is wrong with one month's offer, or null if nothing is.
     *
     * @param array<string,mixed> $offer
     */
    public static function validateOffer(array $offer): ?string
    {
        $body = trim((string) ($offer['body'] ?? ''));
        $code = trim((string) ($offer['code'] ?? ''));

        if ($body === '') {
            return null; // No offer this month. Not an error.
        }

        if ($code === '') {
            return 'An offer needs a code for staff to put into the till.';
        }

        if (preg_match('/^[A-Z0-9]{3,40}$/', mb_strtoupper($code)) !== 1) {
            return 'A code should be 3 to 40 letters and numbers, so it can be read out over a counter.';
        }

        $type  = (string) ($offer['type'] ?? '');
        $value = (float) ($offer['value'] ?? 0);

        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            return 'A percentage off should be between 1 and 100.';
        }

        if ($type === 'fixed' && $value <= 0) {
            return 'An amount off should be more than nothing.';
        }

        return null;
    }

    // =================================================================
    // The wording
    // =================================================================

    /**
     * What a bulk text is missing before it may be sent.
     *
     * The spec's rule is that the validator blocks the save, not that the
     * send fails: a monthly offer whose opt-out line was edited out is
     * discovered on the first of the month, at ten in the morning, after it
     * has gone to the whole list.
     *
     * @param 'offer'|'confirm'|'welcome' $kind
     * @return list<string>
     */
    public static function missingDisclosures(string $body, string $kind): array
    {
        $missing = [];

        if (!SmsPolicy::hasOptOut($body)) {
            $missing[] = 'how to stop (for example "Reply STOP to opt out")';
        }

        if ($kind === 'confirm') {
            // The one disclosure a confirmation carries that the others need
            // not: the guest has not agreed to anything yet, and what they
            // are agreeing to has to include that it may cost them money.
            if (preg_match('/\b(msg|message|data)\b.*\brates?\b/i', $body) !== 1) {
                $missing[] = 'that message and data rates may apply';
            }

            if (stripos($body, 'YES') === false) {
                $missing[] = 'the word YES, which is what they have to reply';
            }
        }

        return $missing;
    }

    /**
     * The invite, which is also the consent disclosure.
     *
     * @param array{name?:string, phone?:string} $venue
     */
    public static function confirmationMessage(array $venue, ?string $custom = null): string
    {
        $custom = trim((string) $custom);

        if ($custom !== '' && self::missingDisclosures($custom, 'confirm') === []) {
            return self::fill($custom, $venue);
        }

        return 'Thanks for joining the ' . self::venueName($venue) . ' Text Club! '
            . 'Reply YES to confirm. About 1 message a month. '
            . 'Msg & data rates may apply. Reply STOP to opt out.';
    }

    /**
     * The welcome, sent the moment they confirm.
     *
     * Immediately, while they are still holding the phone — a welcome offer
     * that arrives the next morning is an offer somebody has to remember.
     *
     * @param array{name?:string, phone?:string} $venue
     * @param array<string,mixed>|null           $offer
     */
    public static function welcomeMessage(array $venue, ?array $offer = null, ?string $custom = null): string
    {
        $custom = trim((string) $custom);

        if ($custom !== '' && self::missingDisclosures($custom, 'welcome') === []) {
            return self::fill($custom, $venue, $offer);
        }

        $tail = '';

        if ($offer !== null && trim((string) ($offer['body'] ?? '')) !== '') {
            $tail = ' ' . trim((string) $offer['body']);
            $code = mb_strtoupper(trim((string) ($offer['code'] ?? '')));

            if ($code !== '' && stripos($tail, $code) === false) {
                $tail .= ' Show code ' . $code . '.';
            }
        }

        $tail .= ' Reply STOP to opt out.';

        /*
         * THE PREAMBLE GIVES WAY, NOT THE OFFER.
         *
         * MEASURED: the full greeting plus July's shipped offer came to 161
         * characters — one over a single text — so every new subscriber cost
         * two. The welcome is the one message every single joiner gets, so
         * that is the worst place in the feature to spend a second segment,
         * and it was being spent on a word.
         *
         * Only our own wording is shortened. The venue's offer and the
         * opt-out line are never touched: a venue that writes a 300-character
         * offer has chosen a two-text message, and silently cutting their
         * words to save a segment would be worse than the segment.
         */
        $full = 'You are in! Welcome to the ' . self::venueName($venue) . ' Text Club.' . $tail;

        if (SmsPolicy::segments($full) <= 1) {
            return $full;
        }

        $short = 'You are in!' . $tail;

        return SmsPolicy::segments($short) < SmsPolicy::segments($full) ? $short : $full;
    }

    /**
     * One month's offer as it will actually be sent.
     *
     * @param array{name?:string, phone?:string} $venue
     * @param array<string,mixed>                $offer
     */
    public static function offerMessage(array $venue, array $offer): string
    {
        $body = trim((string) ($offer['body'] ?? ''));
        $code = mb_strtoupper(trim((string) ($offer['code'] ?? '')));

        if ($body === '') {
            return '';
        }

        if ($code !== '' && stripos($body, $code) === false) {
            $body .= ' Show code ' . $code . '.';
        }

        // Added only when the venue's own wording does not already say it, so
        // an offer that ends "reply STOP to quit" does not get a second
        // instruction stapled to it.
        if (!SmsPolicy::hasOptOut($body)) {
            $body .= ' Reply STOP to opt out.';
        }

        return self::fill($body, $venue, $offer);
    }

    /** @param array{name?:string, phone?:string} $venue */
    public static function alreadyInMessage(array $venue): string
    {
        return 'You are already in the ' . self::venueName($venue)
            . ' Text Club. Reply STOP to opt out.';
    }

    /** @param array{name?:string, phone?:string} $venue */
    public static function staleConfirmMessage(array $venue): string
    {
        // Named rather than silent: a guest who replies YES to an old invite
        // and hears nothing concludes the venue is broken, and the one thing
        // they must not be told is that they are now subscribed.
        return 'That invitation has expired. Text JOIN to start again at '
            . self::venueName($venue) . '. Reply STOP to opt out.';
    }

    /**
     * The reply for a decision, as words.
     *
     * @param array{reply?:?string}              $decision
     * @param array{name?:string, phone?:string} $venue
     * @param array<string,mixed>|null           $offer
     */
    public static function replyFor(
        array $decision,
        array $venue,
        ?array $offer = null,
        ?string $confirmText = null,
        ?string $welcomeText = null
    ): ?string {
        return match ($decision['reply'] ?? null) {
            'confirm_prompt' => self::confirmationMessage($venue, $confirmText),
            'welcome'        => self::welcomeMessage($venue, $offer, $welcomeText),
            'already_in'     => self::alreadyInMessage($venue),
            'stale_confirm'  => self::staleConfirmMessage($venue),
            default          => null,
        };
    }

    /**
     * Merge tags, in the venue's own wording.
     *
     * @param array{name?:string, phone?:string} $venue
     * @param array<string,mixed>|null           $offer
     */
    private static function fill(string $body, array $venue, ?array $offer = null): string
    {
        return str_replace(
            ['{{venue_name}}', '{{venue_phone}}', '{{code}}', '{{offer}}'],
            [
                self::venueName($venue),
                (string) ($venue['phone'] ?? ''),
                mb_strtoupper(trim((string) ($offer['code'] ?? ''))),
                trim((string) ($offer['name'] ?? '')),
            ],
            $body
        );
    }

    /** @param array{name?:string} $venue */
    private static function venueName(array $venue): string
    {
        $name = trim((string) ($venue['name'] ?? ''));

        return $name === '' ? 'the Castle Fun Center' : $name;
    }

    // =================================================================
    // Reporting
    // =================================================================

    /**
     * The list in numbers.
     *
     * `confirm_rate` is the measure that actually matters and the one a
     * single "subscribers" figure hides: invites that are never answered are
     * the normal failure of a double opt-in, and a venue whose rate has
     * fallen has a wording or a timing problem, not a footfall problem.
     *
     * @param list<array{status?:string, confirmed_at?:?string, last_offer_sent_at?:?string, offers_redeemed?:int}> $rows
     * @return array{
     *     total:int, pending:int, subscribed:int, unsubscribed:int,
     *     confirm_rate:?float, redeemed:int, redemption_rate:?float
     * }
     */
    public static function growth(array $rows): array
    {
        $counts   = [self::PENDING => 0, self::SUBSCRIBED => 0, self::UNSUBSCRIBED => 0];
        $redeemed = 0;
        $sentAny  = 0;

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            $redeemed += max(0, (int) ($row['offers_redeemed'] ?? 0));

            if (trim((string) ($row['last_offer_sent_at'] ?? '')) !== '') {
                $sentAny++;
            }
        }

        $total = $counts[self::PENDING] + $counts[self::SUBSCRIBED] + $counts[self::UNSUBSCRIBED];

        /*
         * Invited = everybody who ever got an invite, which is everybody:
         * a subscriber confirmed one and an unsubscriber confirmed one before
         * leaving. Dividing by the SUBSCRIBED count instead would always
         * report 100%.
         */
        $invited = $total;

        return [
            'total'           => $total,
            'pending'         => $counts[self::PENDING],
            'subscribed'      => $counts[self::SUBSCRIBED],
            'unsubscribed'    => $counts[self::UNSUBSCRIBED],
            'confirm_rate'    => $invited === 0
                ? null
                : round(($counts[self::SUBSCRIBED] + $counts[self::UNSUBSCRIBED]) / $invited * 100, 1),
            'redeemed'        => $redeemed,
            // Null rather than zero when nothing has been sent: a list that
            // has never been offered anything has no redemption rate, and 0%
            // reads as an offer nobody wanted.
            'redemption_rate' => $sentAny === 0 ? null : round($redeemed / $sentAny * 100, 1),
        ];
    }

    /**
     * The month a date falls in, in the venue's own zone.
     *
     * The monthly offer is a calendar thing, so which month it is has to be
     * decided locally: a cron firing at 10am Eastern on the 1st is 15:00 UTC
     * the same day, but one firing at 8pm Eastern on the 31st is already the
     * 1st in UTC, and would send the wrong month's offer.
     */
    public static function monthIn(string $nowUtc, string $timezone): int
    {
        $now = self::parse($nowUtc);

        if ($now === null) {
            return 0;
        }

        try {
            return (int) $now->setTimezone(new DateTimeZone($timezone))->format('n');
        } catch (\Exception) {
            return (int) $now->format('n');
        }
    }

    /** Is the local date the first of the month? */
    public static function isFirstOfMonth(string $nowUtc, string $timezone): bool
    {
        $now = self::parse($nowUtc);

        if ($now === null) {
            return false;
        }

        try {
            return $now->setTimezone(new DateTimeZone($timezone))->format('j') === '1';
        } catch (\Exception) {
            return false;
        }
    }
}
