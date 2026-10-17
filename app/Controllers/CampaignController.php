<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Sms;
use App\Services\AvailabilityEngine;
use App\Services\CampaignPlanner;
use App\Services\Inbox;
use App\Services\SegmentEngine;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Sending one message to a lot of people.
 *
 * The most dangerous button in the application, so the rules are stated here
 * and enforced below rather than left to the page.
 *
 * ## 1. EVERY RECIPIENT GOES THROUGH THE ORDINARY SEND PATH
 *
 * `Mailer::send()` and `Sms::send()`, one call per person, with the contact id
 * attached. So consent, the suppression list, do-not-contact, the frequency
 * cap, quiet hours, Safe Mode and the 10DLC check all apply to every single
 * recipient, exactly as they would to a reply typed in the inbox. A campaign
 * cannot have its own, looser copy of those rules, because the way that ends
 * is a bulk send that reaches people a one-to-one message could not.
 *
 * A refusal is recorded as `skipped` with its reason, never dropped: a
 * campaign that reached 180 of 400 with no explanation looks like a bug in the
 * sending, and the venue needs to answer "why didn't Dana get it" months
 * later.
 *
 * ## 2. THE AUDIENCE IS FROZEN WHEN SENDING STARTS
 *
 * The segment is evaluated ONCE, into `campaign_recipients`. Re-running it per
 * chunk would mean a guest who stopped matching half way through got nothing
 * and a guest who started matching got the message, and neither is a list
 * anybody chose. The unique key on (campaign, contact) makes a double-send
 * impossible even if this code is wrong.
 *
 * ## 3. THE COST IS SHOWN BEFORE, AND CONFIRMED ABOVE A THRESHOLD
 *
 * Not afterwards. A text costs per segment and an emoji can triple that, so
 * the estimate is computed from the real body and the confirmation is a typed
 * word rather than a second click — a click is what somebody does by reflex.
 *
 * ## 4. SENDING HAPPENS IN CHUNKS, FROM CRON
 *
 * Forty thousand recipients is not a web request. `sendChunk()` is called by
 * cron, does CampaignPlanner::CHUNK recipients and returns; the campaign walks
 * itself to completion over as many ticks as it takes, and the page says how
 * long that will be.
 */
final class CampaignController extends Controller
{
    /** The campaigns.status enum. */
    public const STATUSES = ['draft', 'scheduled', 'sending', 'paused', 'sent', 'cancelled'];

    /** Statuses from which a campaign may still be edited. */
    private const EDITABLE = ['draft', 'scheduled', 'paused'];

    /** What somebody must type to confirm a large send. */
    public const CONFIRM_WORD = 'SEND';

    /** How many recipients the preview lists. */
    public const PREVIEW_LIMIT = 10;

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_marketing')) {
            return $refusal;
        }

        $rows = DB::all(
            'SELECT c.*, s.name AS segment_name, u.name AS author
               FROM {campaigns} c
               LEFT JOIN {segments} s ON s.id = c.segment_id
               LEFT JOIN {users} u ON u.id = c.created_by
              ORDER BY FIELD(c.status, \'sending\', \'scheduled\', \'paused\', \'draft\', \'sent\', \'cancelled\'),
                       c.id DESC
              LIMIT 200'
        );

        return $this->view('campaigns/index', [
            'pageTitle' => 'Campaigns',
            'campaigns' => $rows,
            'canManage' => can('campaigns_manage'),
            'canSend'   => can('campaigns_send'),
        ]);
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('view_marketing')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        $id      = (int) $campaign['id'];
        $started = $campaign['started_at'] !== null;

        // BEFORE a send, the audience is computed from the segment. AFTER one,
        // it is read from campaign_recipients — because that is what actually
        // happened, and the segment may have moved on since.
        $audience = $started ? $this->recordedAudience($id) : $this->plannedAudience($campaign);

        $estimate = CampaignPlanner::estimate(
            (string) $campaign['channel'],
            (string) ($campaign['body'] ?? ''),
            $audience['reach'],
            $this->unitMillicents((string) $campaign['channel'])
        );

        return $this->view('campaigns/show', [
            'pageTitle'  => (string) $campaign['name'],
            'campaign'   => $campaign,
            'segment'    => $campaign['segment_id'] === null
                ? null
                : DB::first('SELECT * FROM {segments} WHERE id = :id', ['id' => (int) $campaign['segment_id']]),
            'audience'   => $audience,
            'audienceLine' => CampaignPlanner::describeAudience($audience),
            'estimate'   => $estimate,
            'blockers'   => $started ? [] : CampaignPlanner::blockers($campaign, $audience),
            'started'    => $started,
            'editable'   => in_array((string) $campaign['status'], self::EDITABLE, true),
            'duration'   => CampaignPlanner::durationLabel($audience['reach']),
            'skipped'    => $this->skippedBreakdown($id),
            'preview'    => $this->previewRecipients($campaign, $audience),
            'previewLimit' => self::PREVIEW_LIMIT,
            'confirmWord' => self::CONFIRM_WORD,
            'canManage'  => can('campaigns_manage'),
            'canSend'    => can('campaigns_send'),
            'segments'   => $this->segmentOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        return $this->view('campaigns/form', [
            'pageTitle' => 'New campaign',
            'campaign'  => null,
            'segments'  => $this->segmentOptions(),
            'channels'  => CampaignPlanner::CHANNELS,
        ]);
    }

    public function edit(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        if (!in_array((string) $campaign['status'], self::EDITABLE, true)) {
            return $this->refuse(
                $request,
                'A campaign that has started sending cannot be edited — its wording is what '
                    . 'people have already been sent.',
                '/campaigns/' . (int) $campaign['id']
            );
        }

        return $this->view('campaigns/form', [
            'pageTitle' => 'Edit ' . (string) $campaign['name'],
            'campaign'  => $campaign,
            'segments'  => $this->segmentOptions(),
            'channels'  => CampaignPlanner::CHANNELS,
        ]);
    }

    // =================================================================
    // Writing
    // =================================================================

    public function store(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_manage')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        // A CAMPAIGN THAT HAS STARTED IS IMMUTABLE. Its wording is what people
        // have already received, and changing it would leave the venue unable
        // to answer "what did you send me".
        if (!in_array((string) $campaign['status'], self::EDITABLE, true)) {
            return $this->refuse(
                $request,
                'That campaign has started sending, so its wording is fixed.',
                '/campaigns/' . (int) $campaign['id']
            );
        }

        return $this->save($request, $campaign);
    }

    /** @param array<string,mixed>|null $campaign */
    private function save(Request $request, ?array $campaign): Response
    {
        $isNew = $campaign === null;
        $back  = $isNew ? '/campaigns/new' : '/campaigns/' . (int) $campaign['id'] . '/edit';

        $name = trim($request->str('name'));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $this->refuse($request, 'Give the campaign a name.', $back);
        }

        $channel = $request->str('channel');

        if (!in_array($channel, CampaignPlanner::CHANNELS, true)) {
            return $this->refuse($request, 'Choose email or text.', $back);
        }

        $segmentId = $request->int('segment_id', 0);

        if ($segmentId > 0 && !DB::exists('SELECT id FROM {segments} WHERE id = :id', ['id' => $segmentId])) {
            return $this->refuse($request, 'That segment does not exist.', $back);
        }

        $columns = [
            'name'       => $name,
            'channel'    => $channel,
            'segment_id' => $segmentId > 0 ? $segmentId : null,
            'subject'    => mb_substr(trim($request->str('subject')), 0, 255) ?: null,
            'body'       => trim($request->str('body')),
            'from_name'  => mb_substr(trim($request->str('from_name')), 0, 120) ?: null,
            'reply_to'   => mb_substr(trim($request->str('reply_to')), 0, 191) ?: null,
            'updated_at' => now_utc(),
        ];

        // The send window, in the venue's own clock. BOTH halves or neither —
        // half a window is a half-filled form, and guessing the other end
        // would either stop all sending or allow all of it, silently.
        //
        // The HH:MM parser is AvailabilityEngine's, which is where a clock
        // time was first needed. Importing it from marketing code reads oddly
        // and is still better than a second copy: two parsers for "what does
        // 09:30 mean" is exactly the drift this codebase keeps warning about.
        $start = AvailabilityEngine::minutes($request->str('send_window_start'));
        $end   = AvailabilityEngine::minutes($request->str('send_window_end'));

        $columns['send_window_start'] = $start !== null && $end !== null
            ? sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60)
            : null;
        $columns['send_window_end'] = $start !== null && $end !== null
            ? sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60)
            : null;
        $columns['timezone'] = Settings::timezone();

        if ($isNew) {
            $columns['status']     = 'draft';
            $columns['created_by'] = Auth::id();
            $columns['created_at'] = now_utc();

            $id = DB::insert('campaigns', $columns);

            Audit::event('campaign.created', ['name' => $name, 'channel' => $channel], 'campaigns', $id);
        } else {
            $id = (int) $campaign['id'];

            DB::update('campaigns', $columns, 'id = :id', ['id' => $id]);

            Audit::event('campaign.updated', ['name' => $name, 'channel' => $channel], 'campaigns', $id);
        }

        return $this->success($request, 'Campaign saved.', '/campaigns/' . $id);
    }

    // =================================================================
    // Sending
    // =================================================================

    /**
     * Send one message to one address, to check it before the real thing.
     *
     * A SEED TEST, and it is deliberately NOT a campaign send: it carries no
     * campaign id, so it does not appear in the campaign's figures and does
     * not count against the recipient's frequency cap. What it is for is
     * seeing the thing arrive in a real inbox before forty thousand people do.
     */
    public function test(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_send')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        $id   = (int) $campaign['id'];
        $to   = trim($request->str('to'));
        $back = '/campaigns/' . $id;

        if ($to === '') {
            return $this->refuse($request, 'Where should the test go?', $back);
        }

        $body = (string) ($campaign['body'] ?? '');

        // Merged against a stand-in guest, so the tags are visibly filled in
        // rather than showing as {{first_name}} in the test and surprising
        // somebody in the real send.
        $tags = Inbox::mergeTags(
            ['first_name' => 'Alex', 'last_name' => 'Example', 'email' => $to],
            ['name' => Settings::venueName(), 'phone' => (string) Settings::get('venue.phone_display', '')]
        );

        $result = (string) $campaign['channel'] === 'sms'
            ? Sms::send([
                'to'                 => $to,
                'body'               => Mailer::mergeText($body, $tags),
                // TRANSACTIONAL, because the person asking for it is the
                // person receiving it — and quiet hours must not stop
                // somebody proof-reading at nine in the evening.
                'kind'               => Sms::TRANSACTIONAL,
                'ignore_quiet_hours' => true,
            ])
            : Mailer::send([
                'to'      => $to,
                'subject' => '[Test] ' . (string) ($campaign['subject'] ?? $campaign['name']),
                'html'    => Mailer::merge($body, $tags),
                'kind'    => Mailer::TRANSACTIONAL,
            ]);

        Audit::event('campaign.test_sent', [
            'campaign' => (string) $campaign['name'],
            'sent'     => $result['sent'],
            'reason'   => $result['reason'],
        ], 'campaigns', $id);

        if (!$result['sent']) {
            return $this->refuse(
                $request,
                'The test could not be sent: ' . (
                    (string) $campaign['channel'] === 'sms'
                        ? Sms::explain($result['reason'])
                        : $result['reason']
                ),
                $back
            );
        }

        return $this->success($request, 'Test sent to ' . $to . '.', $back);
    }

    /**
     * Start sending.
     *
     * Freezes the audience, records the estimate, and leaves the campaign in
     * `sending` for cron to walk. Nothing is actually sent in this request:
     * forty thousand recipients is not a web request, and a send that began
     * here would be at the mercy of a browser tab.
     */
    public function send(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_send')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        $id   = (int) $campaign['id'];
        $back = '/campaigns/' . $id;

        // A CAMPAIGN CANNOT BE SENT TWICE. Checked here, and guaranteed by the
        // unique key on (campaign, contact) even if this check were wrong.
        if ($campaign['started_at'] !== null) {
            return $this->refuse(
                $request,
                'That campaign has already been sent. Duplicate it if you want to send it again.',
                $back
            );
        }

        $audience = $this->plannedAudience($campaign);
        $blockers = CampaignPlanner::blockers($campaign, $audience);

        if ($blockers !== []) {
            return $this->refuse($request, $blockers[0], $back);
        }

        $estimate = CampaignPlanner::estimate(
            (string) $campaign['channel'],
            (string) $campaign['body'],
            $audience['reach'],
            $this->unitMillicents((string) $campaign['channel'])
        );

        // A TYPED WORD, not a second click. A click is what somebody does by
        // reflex; typing SEND is a decision.
        if ($estimate['needs_confirmation']
            && strtoupper(trim($request->str('confirm'))) !== self::CONFIRM_WORD) {
            return $this->refuse(
                $request,
                'This will cost about ' . money($estimate['total_cents'])
                    . ' and reach ' . number_format($audience['reach']) . ' people. '
                    . 'Type ' . self::CONFIRM_WORD . ' in the box to confirm.',
                $back
            );
        }

        try {
            DB::transaction(function () use ($id, $audience, $estimate): void {
                // THE AUDIENCE IS FROZEN HERE, once. Re-running the segment
                // per chunk would mean a guest who stopped matching half way
                // through got nothing and one who started matching got the
                // message — neither is a list anybody chose.
                foreach ($audience['sendable'] as $contactId) {
                    DB::query(
                        'INSERT IGNORE INTO {campaign_recipients}
                            (campaign_id, contact_id, status) VALUES (:c, :p, \'pending\')',
                        ['c' => $id, 'p' => $contactId]
                    );
                }

                // AND SO ARE THE SKIPS, with their reasons. A skipped
                // recipient is recorded rather than dropped: the venue has to
                // be able to answer "why didn't Dana get it" months later.
                foreach ($audience['skipped'] as $contactId => $reason) {
                    DB::query(
                        'INSERT IGNORE INTO {campaign_recipients}
                            (campaign_id, contact_id, status, skip_reason)
                         VALUES (:c, :p, \'skipped\', :r)',
                        ['c' => $id, 'p' => $contactId, 'r' => $reason]
                    );
                }

                DB::update('campaigns', [
                    'status'                => 'sending',
                    'recipients_total'      => $audience['reach'],
                    'estimated_cost_cents'  => $estimate['total_cents'],
                    'started_at'            => now_utc(),
                    'updated_at'            => now_utc(),
                ], 'id = :id', ['id' => $id]);
            });
        } catch (Throwable $e) {
            return $this->refuse($request, 'That campaign could not be started: ' . $e->getMessage(), $back);
        }

        Audit::event('campaign.started', [
            'name'       => (string) $campaign['name'],
            'channel'    => (string) $campaign['channel'],
            'recipients' => $audience['reach'],
            'skipped'    => count($audience['skipped']),
            'estimate'   => $estimate['total_cents'],
        ], 'campaigns', $id);

        Event::fire('campaign.started', ['campaign_id' => $id, 'recipients' => $audience['reach']]);

        return $this->success(
            $request,
            'Sending to ' . number_format($audience['reach']) . ' people — '
                . CampaignPlanner::durationLabel($audience['reach']) . '.',
            $back
        );
    }

    public function pause(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_send')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        $id = (int) $campaign['id'];

        if ((string) $campaign['status'] !== 'sending') {
            return $this->refuse($request, 'That campaign is not sending.', '/campaigns/' . $id);
        }

        DB::update('campaigns', [
            'status'     => 'paused',
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::event('campaign.paused', ['name' => (string) $campaign['name']], 'campaigns', $id);

        return $this->success($request, 'Paused. Nobody else will be sent to until you resume.', '/campaigns/' . $id);
    }

    public function resume(Request $request): Response
    {
        if ($refusal = $this->authorize('campaigns_send')) {
            return $refusal;
        }

        $campaign = $this->find((int) $request->route('id', '0'));

        if ($campaign === null) {
            return $this->notFound('That campaign is not here.');
        }

        $id = (int) $campaign['id'];

        if ((string) $campaign['status'] !== 'paused') {
            return $this->refuse($request, 'That campaign is not paused.', '/campaigns/' . $id);
        }

        DB::update('campaigns', [
            'status'     => 'sending',
            'updated_at' => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::event('campaign.resumed', ['name' => (string) $campaign['name']], 'campaigns', $id);

        return $this->success($request, 'Resumed.', '/campaigns/' . $id);
    }

    /**
     * Send the next chunk of a campaign. Called by cron, never by a request.
     *
     * Returns how many it attempted, so the caller can report it. Public and
     * static because cron is the only caller and there is exactly one correct
     * way to do this.
     */
    public static function sendChunk(int $campaignId, int $limit = CampaignPlanner::CHUNK): int
    {
        $campaign = DB::first(
            "SELECT * FROM {campaigns} WHERE id = :id AND status = 'sending'",
            ['id' => $campaignId]
        );

        if ($campaign === null) {
            return 0;
        }

        // THE SEND WINDOW, on the venue's own clock. Outside it the campaign
        // WAITS rather than failing — the same rule as a single text, and for
        // the same reason: a message nobody sends is worse than one sent an
        // hour late.
        $zone  = (string) ($campaign['timezone'] ?? '') ?: Settings::timezone();
        $local = new DateTimeImmutable('now', new DateTimeZone($zone));
        $now   = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        $start = AvailabilityEngine::minutes((string) ($campaign['send_window_start'] ?? ''));
        $end   = AvailabilityEngine::minutes((string) ($campaign['send_window_end'] ?? ''));

        if (!CampaignPlanner::windowAllows($now, $start, $end)) {
            return 0;
        }

        $pending = DB::all(
            "SELECT r.id AS recipient_id, r.contact_id,
                    c.first_name, c.last_name, c.email, c.phone, c.phone_normalized
               FROM {campaign_recipients} r
               JOIN {contacts} c ON c.id = r.contact_id
              WHERE r.campaign_id = :id AND r.status = 'pending'
                AND c.deleted_at IS NULL
              ORDER BY r.id
              LIMIT " . max(1, $limit),
            ['id' => $campaignId]
        );

        if ($pending === []) {
            self::finish($campaignId);

            return 0;
        }

        $venue = [
            'name'  => Settings::venueName(),
            'phone' => (string) Settings::get('venue.phone_display', ''),
        ];

        $attempted = 0;

        foreach ($pending as $row) {
            $attempted++;
            self::sendOne($campaign, $row, $venue);
        }

        self::recount($campaignId);

        return $attempted;
    }

    /**
     * One recipient, through the ordinary send path.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $row
     * @param array<string,string> $venue
     */
    private static function sendOne(array $campaign, array $row, array $venue): void
    {
        $recipientId = (int) $row['recipient_id'];
        $contactId   = (int) $row['contact_id'];
        $channel     = (string) $campaign['channel'];

        $tags = Inbox::mergeTags($row, $venue);
        $body = (string) ($campaign['body'] ?? '');

        try {
            // THE CONTACT ID IS ALWAYS ATTACHED, and the kind is MARKETING.
            // Together those are what make the send path apply consent, the
            // suppression list, do-not-contact, the frequency cap, quiet
            // hours and the opt-out requirement to this person. A campaign
            // that sent without them would reach people a one-to-one message
            // could not.
            $result = $channel === 'sms'
                ? Sms::send([
                    'to'          => (string) $row['phone_normalized'],
                    'body'        => Mailer::mergeText($body, $tags),
                    'kind'        => Sms::MARKETING,
                    'contact_id'  => $contactId,
                    'campaign_id' => (int) $campaign['id'],
                ])
                : Mailer::send([
                    'to'          => (string) $row['email'],
                    'subject'     => Mailer::mergeText((string) ($campaign['subject'] ?? ''), $tags),
                    'html'        => Mailer::merge($body, $tags),
                    'kind'        => Mailer::MARKETING,
                    'contact_id'  => $contactId,
                    'campaign_id' => (int) $campaign['id'],
                ]);
        } catch (Throwable $e) {
            DB::update('campaign_recipients', [
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ], 'id = :id', ['id' => $recipientId]);

            return;
        }

        if ($result['sent']) {
            DB::update('campaign_recipients', [
                'status'     => 'sent',
                'message_id' => $result['message_id'],
                'sent_at'    => now_utc(),
            ], 'id = :id', ['id' => $recipientId]);

            return;
        }

        // QUIET HOURS ARE A DEFERRAL, NOT A REFUSAL. The recipient stays
        // pending and the next tick inside the window picks them up —
        // marking them skipped would lose them for good over a clock.
        if (($result['reason'] ?? '') === 'quiet_hours') {
            return;
        }

        // A REFUSAL IS RECORDED WITH ITS REASON. The send path's own word for
        // it, so the campaign report and the send log cannot disagree.
        DB::update('campaign_recipients', [
            'status'      => 'skipped',
            'skip_reason' => mb_substr((string) $result['reason'], 0, 80),
            'message_id'  => $result['message_id'],
        ], 'id = :id', ['id' => $recipientId]);
    }

    /** Roll the per-recipient rows up onto the campaign. */
    private static function recount(int $campaignId): void
    {
        $row = DB::first(
            "SELECT
                SUM(status = 'sent') AS sent,
                SUM(status = 'delivered') AS delivered,
                SUM(status = 'opened') AS opened,
                SUM(status = 'clicked') AS clicked,
                SUM(status = 'bounced') AS bounced,
                SUM(status = 'failed') AS failed,
                SUM(status = 'unsubscribed') AS unsubscribed,
                SUM(status = 'pending') AS pending
               FROM {campaign_recipients} WHERE campaign_id = :id",
            ['id' => $campaignId]
        ) ?? [];

        // The REAL cost, from the messages actually sent — not the estimate.
        // A campaign that skipped half its audience did not cost what it was
        // estimated to, and the figure a venue reconciles against an invoice
        // has to be the real one.
        $actual = (int) round((float) DB::value(
            'SELECT COALESCE(SUM(m.cost_cents), 0) FROM {messages} m WHERE m.campaign_id = :id',
            ['id' => $campaignId]
        ));

        DB::update('campaigns', [
            'sent_count'         => (int) ($row['sent'] ?? 0),
            'delivered_count'    => (int) ($row['delivered'] ?? 0),
            'opened_count'       => (int) ($row['opened'] ?? 0),
            'clicked_count'      => (int) ($row['clicked'] ?? 0),
            'bounced_count'      => (int) ($row['bounced'] ?? 0),
            'failed_count'       => (int) ($row['failed'] ?? 0),
            'unsubscribed_count' => (int) ($row['unsubscribed'] ?? 0),
            'actual_cost_cents'  => $actual,
            'updated_at'         => now_utc(),
        ], 'id = :id', ['id' => $campaignId]);

        if ((int) ($row['pending'] ?? 0) === 0) {
            self::finish($campaignId);
        }
    }

    private static function finish(int $campaignId): void
    {
        DB::update('campaigns', [
            'status'       => 'sent',
            'completed_at' => now_utc(),
            'updated_at'   => now_utc(),
        ], "id = :id AND status = 'sending'", ['id' => $campaignId]);

        Event::fire('campaign.completed', ['campaign_id' => $campaignId]);
    }

    /**
     * Every campaign with work outstanding. Cron's entry point.
     *
     * @return list<int>
     */
    public static function dueCampaigns(int $limit = 5): array
    {
        $rows = DB::all(
            "SELECT id FROM {campaigns} WHERE status = 'sending' ORDER BY started_at LIMIT " . max(1, $limit)
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    // =================================================================
    // The audience
    // =================================================================

    /**
     * Who this campaign WOULD go to, from its segment.
     *
     * @param array<string,mixed> $campaign
     * @return array{sendable:list<int>,skipped:array<int,string>,counts:array<string,int>,total:int,reach:int}
     */
    private function plannedAudience(array $campaign): array
    {
        $empty = ['sendable' => [], 'skipped' => [], 'counts' => [], 'total' => 0, 'reach' => 0];

        if ($campaign['segment_id'] === null) {
            return $empty;
        }

        $segment = DB::first(
            'SELECT rules FROM {segments} WHERE id = :id',
            ['id' => (int) $campaign['segment_id']]
        );

        if ($segment === null) {
            return $empty;
        }

        $rules = json_decode((string) $segment['rules'], true);

        if (!is_array($rules)) {
            return $empty;
        }

        try {
            $compiled = SegmentEngine::compile($rules);
        } catch (RuntimeException) {
            // A segment that will not compile reaches NOBODY. The page shows
            // the segment's own error; silently sending to everybody who
            // happens to match a partial clause is the failure this whole
            // design exists to avoid.
            return $empty;
        }

        $contacts = DB::all(
            'SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.phone_normalized,
                    c.email_consent, c.sms_consent, c.do_not_contact
               FROM {contacts} c
              WHERE ' . $compiled['sql'],
            $compiled['params']
        );

        return CampaignPlanner::audience($contacts, (string) $campaign['channel']);
    }

    /**
     * Who it ACTUALLY went to, from the frozen list.
     *
     * @return array{sendable:list<int>,skipped:array<int,string>,counts:array<string,int>,total:int,reach:int}
     */
    private function recordedAudience(int $campaignId): array
    {
        $rows = DB::all(
            'SELECT contact_id, status, skip_reason FROM {campaign_recipients} WHERE campaign_id = :id',
            ['id' => $campaignId]
        );

        $sendable = [];
        $skipped  = [];
        $counts   = [];

        foreach ($rows as $row) {
            $contactId = (int) $row['contact_id'];

            if ((string) $row['status'] === 'skipped') {
                $reason           = (string) ($row['skip_reason'] ?? 'unknown');
                $skipped[$contactId] = $reason;
                $counts[$reason]  = ($counts[$reason] ?? 0) + 1;

                continue;
            }

            $sendable[] = $contactId;
        }

        return [
            'sendable' => $sendable,
            'skipped'  => $skipped,
            'counts'   => $counts,
            'total'    => count($rows),
            'reach'    => count($sendable),
        ];
    }

    /** @return array<string,int> */
    private function skippedBreakdown(int $campaignId): array
    {
        $rows = DB::all(
            "SELECT skip_reason, COUNT(*) AS n FROM {campaign_recipients}
              WHERE campaign_id = :id AND status = 'skipped'
              GROUP BY skip_reason ORDER BY n DESC",
            ['id' => $campaignId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) ($row['skip_reason'] ?? 'unknown')] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * A few of the people this will reach, by name.
     *
     * @param array<string,mixed> $campaign
     * @param array{sendable:list<int>} $audience
     * @return list<array<string,mixed>>
     */
    private function previewRecipients(array $campaign, array $audience): array
    {
        $ids = array_slice($audience['sendable'], 0, self::PREVIEW_LIMIT);

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params       = [];

        foreach ($ids as $index => $id) {
            $placeholders[]       = ':p' . $index;
            $params['p' . $index] = $id;
        }

        return DB::all(
            'SELECT id, first_name, last_name, email, phone FROM {contacts}
              WHERE id IN (' . implode(', ', $placeholders) . ')
              ORDER BY last_name, first_name',
            $params
        );
    }

    // =================================================================
    // Small helpers
    // =================================================================

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        return $id <= 0 ? null : DB::first('SELECT * FROM {campaigns} WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    private function segmentOptions(): array
    {
        return DB::all('SELECT id, name, cached_count FROM {segments} ORDER BY name');
    }

    /** The unit price in thousandths of a cent. */
    private function unitMillicents(string $channel): int
    {
        if ($channel === 'sms') {
            return max(0, (int) round((float) Settings::get('sms.cost_per_segment_millicents', 750)));
        }

        // The mail setting is in whole cents, so it is scaled to the same
        // units — mixing the two is how an email campaign comes to be
        // estimated at a thousand times its cost.
        return max(0, (int) round((float) Settings::get('mail.cost_per_email_cents', 0) * 1000));
    }
}
