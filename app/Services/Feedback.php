<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Settings;
use App\Core\Sms;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The feedback module's engine: who gets asked, what their answer means, and
 * what happens next.
 *
 * The I/O half of `Reputation`, which holds the rules and touches nothing.
 * This reads, writes, sends and fires events, and makes no decision that
 * could be made there instead — the same split as `AutomationEngine` and
 * `AutomationRunner`.
 *
 * It is a SERVICE rather than part of `ReputationController` because three
 * different callers need it and only one of them is a web request: the public
 * feedback page records an answer, cron sends the invitations, and the
 * `send_survey` automation step asks one named guest. A controller holding
 * that would mean the automation engine depending on the web tier.
 *
 * Three things about it are load-bearing:
 *
 *   1. THE INVITATION SWEEP IS BOUNDED BY A LOOKBACK WINDOW. Switching this
 *      module on must not text every family who has ever had a party here.
 *      `reputation.invite_lookback_days` (14 by default) is the only thing
 *      standing between "we turned on feedback" and twenty years of guests
 *      being asked how their visit went. Do not remove it, and do not raise
 *      the default — a venue that genuinely wants to survey older visits can
 *      raise it themselves and watch what happens.
 *
 *   2. AN INVITATION IS A ROW FIRST AND A MESSAGE SECOND. The row is written
 *      with `invited_at` NULL, then the send is attempted and the row
 *      stamped. So the row is the de-duplication record AND the work item: a
 *      guest cannot be asked twice, and an invitation refused for a reason
 *      that will pass (quiet hours, a provider wobble) is tried again next
 *      tick instead of being lost. A refusal that will never pass — no
 *      consent, a suppressed address — is recorded ON the row with its
 *      reason, so the page can say "we could not ask 12 guests, and here is
 *      why" rather than quietly reporting a low response rate.
 *
 *   3. A DETRACTOR IS ESCALATED BY THIS MODULE, NOT BY AN AUTOMATION. The
 *      seeded "Detractor — escalate immediately" automation adds the manager
 *      notifications, but the TASK is raised here, because a complaint must
 *      reach somebody whether or not a venue has switched that automation on.
 *      That is also why the automation's own steps do not raise a second one.
 */
final class Feedback
{
    /** Who a complaint is handed to, when nobody is named. */
    public const RESOLVE_PERMISSION = 'feedback_resolve';

    /** How long a manager has to deal with a detractor, by default. */
    public const DEFAULT_SLA_HOURS = 4;

    /** How long after the visit the question is asked, when a survey does not say. */
    public const DEFAULT_DELAY_HOURS = 20;

    /**
     * How far back the sweep will look for something to survey.
     *
     * See point 1 above. This is a safety bound, not a preference.
     */
    public const DEFAULT_LOOKBACK_DAYS = 14;

    /** Invitations attempted per cron tick. */
    public const INVITE_BATCH = 25;

    /** Attempts before an invitation is given up on. */
    public const INVITE_MAX_ATTEMPTS = 4;

    /**
     * When a party is assumed to have ended, if the booking has no end time.
     *
     * The delay is measured from the END of the visit, so the question
     * arrives the following morning rather than while the cake is being cut.
     * A booking with no end time is the ordinary case for an enquiry that was
     * never fully filled in.
     */
    private const ASSUMED_BOOKING_END = '17:00';

    /** The same for a visit, which is a DATE with no times at all. */
    private const ASSUMED_VISIT_END = '18:00';


    // =================================================================
    // The gate — called by the public page, and the only path to a review ask
    // =================================================================

    /**
     * Record a guest's answer and act on it.
     *
     * The ONE place a score becomes a sentiment, a sentiment becomes an
     * escalation or a review ask, and the events fire. `PublicFeedbackController`
     * does the HTTP and calls this; nothing else writes a score.
     *
     * A second submission updates the COMMENT and leaves the score alone. A
     * guest adding detail must not flip the gate — re-running it would hand
     * out a second review request, or page a manager about a complaint that
     * has already been dealt with.
     *
     * @param array<string,mixed> $response the survey_responses row
     * @return array{sentiment:?string,first:bool,review:?array{platform:string,url:string}}
     */
    public static function record(array $response, int $score, ?string $comment): array
    {
        $type      = (string) ($response['survey_type'] ?? 'nps');
        $sentiment = Reputation::sentiment($type, $score);
        $already   = ($response['responded_at'] ?? null) !== null;
        $id        = (int) $response['id'];

        if ($sentiment === null) {
            return ['sentiment' => null, 'first' => false, 'review' => null];
        }

        if ($already) {
            if ($comment !== null && $comment !== '') {
                DB::update('survey_responses', ['comment' => $comment], 'id = :id', ['id' => $id]);
            }

            return [
                'sentiment' => (string) ($response['sentiment'] ?? $sentiment),
                'first'     => false,
                'review'    => null,
            ];
        }

        DB::update('survey_responses', [
            'score'        => $score,
            'sentiment'    => $sentiment,
            'comment'      => $comment === '' ? null : $comment,
            'responded_at' => now_utc(),
        ], 'id = :id AND responded_at IS NULL', ['id' => $id]);

        $settings = self::settings();

        Event::fire('survey.responded', [
            'survey_response_id' => $id,
            'survey_id'          => (int) $response['survey_id'],
            'contact_id'         => $response['contact_id'] === null ? null : (int) $response['contact_id'],
            'booking_id'         => $response['booking_id'] === null ? null : (int) $response['booking_id'],
            'visit_id'           => $response['visit_id'] === null ? null : (int) $response['visit_id'],
            'score'              => $score,
            'sentiment'          => $sentiment,
            'comment'            => $comment,
            'survey_type'        => $type,
        ]);

        if (Reputation::needsEscalation($sentiment)) {
            // A SECOND event, not a condition on the first. A venue must be
            // able to attach "tell a manager now" to low scores without
            // writing a condition step, and the seeded playbook does exactly
            // that — see Event::CATALOG's survey.detractor.
            Event::fire('survey.detractor', [
                'survey_response_id' => $id,
                'contact_id'         => $response['contact_id'] === null ? null : (int) $response['contact_id'],
                'booking_id'         => $response['booking_id'] === null ? null : (int) $response['booking_id'],
                'score'              => $score,
                'comment'            => $comment,
                'sla_hours'          => $settings['sla_hours'],
            ]);

            if ($settings['auto_escalate']) {
                self::escalate($response, $score, $comment, $settings['sla_hours']);
            }
        }

        $review = null;

        if (Reputation::askForReview($sentiment, $settings['ask_everyone'])) {
            $review = self::createReviewRequest($response, $settings);
        }

        return ['sentiment' => $sentiment, 'first' => true, 'review' => $review];
    }


    /**
     * Raise the internal alert for a low score.
     *
     * A task with a deadline, plus an in-app notification for whoever it
     * lands on. Deliberately NOT a guest-facing message: the apology is the
     * manager's job, and a template that said sorry automatically would be
     * worse than silence.
     *
     * @param array<string,mixed> $response
     */
    private static function escalate(array $response, int $score, ?string $comment, int $slaHours): void
    {
        $id   = (int) $response['id'];
        $name = trim((string) (($response['first_name'] ?? '') . ' ' . ($response['last_name'] ?? '')));
        $name = $name === '' ? 'A guest' : $name;

        try {
            $assignee = self::manager();

            $taskId = (int) DB::insert('tasks', [
                'title'       => $name . ' scored ' . $score . ' — call them today',
                'description' => ($comment === null || $comment === '' ? 'No comment was left.' : $comment)
                    . "\n\n" . 'Resolve it on the feedback page when you have spoken to them.',
                'entity_type' => 'feedback',
                'entity_id'   => $id,
                'assigned_to' => $assignee,
                'due_at'      => Reputation::slaDueAt(now_utc(), $slaHours),
                'priority'    => 'urgent',
                'status'      => 'open',
                'created_at'  => now_utc(),
                'updated_at'  => now_utc(),
            ]);

            Event::fire('task.created', ['task_id' => $taskId, 'from_reputation' => true]);

            if ($assignee !== null) {
                DB::insert('notifications', [
                    'user_id'    => $assignee,
                    'type'       => 'feedback',
                    'title'      => $name . ' scored ' . $score . ' out of 10',
                    'body'       => $comment === null || $comment === '' ? null : mb_substr($comment, 0, 500),
                    'link'       => '/reputation/feedback/' . $id,
                    'created_at' => now_utc(),
                ]);
            }
        } catch (Throwable $e) {
            // The guest's answer is already recorded, and that matters more
            // than the alert. Logged loudly rather than thrown: throwing here
            // would show the parent an error page after they told us what
            // went wrong, which is the worst possible moment to fail.
            Log::error('Could not escalate a detractor', ['response' => $id, 'error' => $e->getMessage()]);
        }
    }


    /**
     * Create the review ask for a promoter, and return where to send them.
     *
     * Null when the venue has configured no platform link — which the public
     * page treats as "thank them and stop", not as "send them to Google
     * anyway". A link to a listing the venue does not have is worse than no
     * ask: it sends a happy family somewhere confusing.
     *
     * @param array<string,mixed> $response
     * @param array<string,mixed> $settings
     * @return array{platform:string,url:string}|null
     */
    private static function createReviewRequest(array $response, array $settings): ?array
    {
        $weights = Reputation::platformWeights($settings['weights'], $settings['links']);

        if ($weights === []) {
            return null;
        }

        // The rotation's position is how many asks have been made, read from
        // the table rather than held anywhere — so it survives a restart and
        // cannot drift from what was actually sent.
        $sequence = (int) DB::value('SELECT COUNT(*) FROM {review_requests}');
        $platform = Reputation::platformFor($weights, $sequence);

        if ($platform === null) {
            return null;
        }

        $token = bin2hex(random_bytes(16));

        $requestId = (int) DB::insert('review_requests', [
            'contact_id'         => $response['contact_id'] === null ? null : (int) $response['contact_id'],
            'booking_id'         => $response['booking_id'] === null ? null : (int) $response['booking_id'],
            'survey_response_id' => (int) $response['id'],
            'platform'           => $platform,
            'status'             => 'sent',
            'sent_at'            => now_utc(),
            'token'              => $token,
            'created_at'         => now_utc(),
        ]);

        /*
         * NO EVENT HERE, deliberately. The ask is made by the page the guest
         * is already looking at — they are two seconds from clicking it — so
         * there is nothing for an automation to send. `review_requests` is
         * the record, and `/r/{token}` records the click.
         */

        return [
            'platform' => $platform,
            'url'      => url('/r/' . $token),
            'id'       => $requestId,
        ];
    }


    // =================================================================
    // The invitation sweep — called once a tick from cron.php
    // =================================================================

    /**
     * Ask the guests whose turn it is.
     *
     * Two halves, in one pass: PLAN (write a row for anything that has just
     * crossed its delay) then SEND (attempt every row not yet sent). See the
     * class comment for why the row comes first.
     *
     * Returns how many invitations actually went out.
     */
    public static function dispatchDue(?callable $budgetLeft = null): int
    {
        $within = static fn (): bool => $budgetLeft === null || $budgetLeft();

        $surveys = DB::all(
            "SELECT * FROM {surveys}
              WHERE is_active = 1 AND trigger_event IS NOT NULL AND trigger_event <> ''
              ORDER BY id"
        );

        if ($surveys === []) {
            return 0;
        }

        foreach ($surveys as $survey) {
            if (!$within()) {
                break;
            }

            try {
                self::plan($survey);
            } catch (Throwable $e) {
                Log::error('Could not plan survey invitations', [
                    'survey' => (int) $survey['id'],
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return self::sendPlanned($within);
    }


    /**
     * Write a row for everything that has just become due.
     *
     * The LOOKBACK is the guard that matters (see the class comment): only
     * visits inside the window are ever considered, so switching the module
     * on cannot reach into the archive.
     *
     * @param array<string,mixed> $survey
     */
    private static function plan(array $survey): int
    {
        $settings = self::settings();
        $delay    = (int) ($survey['delay_hours'] ?? self::DEFAULT_DELAY_HOURS);
        $trigger  = (string) $survey['trigger_event'];
        $surveyId = (int) $survey['id'];

        // The window, in venue-local days: everything that ended between
        // `lookback` days ago and `delay` hours ago.
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $oldest  = $now->modify('-' . $settings['lookback_days'] . ' days')->format('Y-m-d');
        $planned = 0;

        if ($trigger === 'booking.completed') {
            $rows = DB::all(
                "SELECT b.id, b.contact_id, b.event_date, b.end_time, b.host_user_id
                   FROM {bookings} b
                  WHERE b.status = 'completed'
                    AND b.deleted_at IS NULL
                    AND b.contact_id IS NOT NULL
                    AND b.event_date >= :oldest
                    AND b.event_date <= :today
                    AND NOT EXISTS (
                        SELECT 1 FROM {survey_responses} r
                         WHERE r.survey_id = :survey AND r.booking_id = b.id
                    )
                  ORDER BY b.event_date DESC
                  LIMIT 200",
                ['oldest' => $oldest, 'today' => $now->format('Y-m-d'), 'survey' => $surveyId]
            );

            foreach ($rows as $row) {
                $ended = self::endOfBooking((string) $row['event_date'], $row['end_time']);

                if ($ended === null || Reputation::dueAt($ended, $delay) > now_utc()) {
                    continue;
                }

                DB::insert('survey_responses', [
                    'survey_id'    => $surveyId,
                    'contact_id'   => (int) $row['contact_id'],
                    'booking_id'   => (int) $row['id'],
                    'host_user_id' => $row['host_user_id'] === null ? null : (int) $row['host_user_id'],
                    'public_token' => bin2hex(random_bytes(16)),
                    'created_at'   => now_utc(),
                ]);

                $planned++;
            }

            return $planned;
        }

        // visit.recorded
        $rows = DB::all(
            'SELECT v.id, v.contact_id, v.visit_date, v.booking_id
               FROM {visits} v
              WHERE v.contact_id IS NOT NULL
                AND v.visit_date >= :oldest
                AND v.visit_date <= :today
                AND NOT EXISTS (
                    SELECT 1 FROM {survey_responses} r
                     WHERE r.survey_id = :survey AND r.visit_id = v.id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM {survey_responses} r2
                     WHERE r2.contact_id = v.contact_id
                       AND r2.created_at >= :recent
                )
              ORDER BY v.visit_date DESC
              LIMIT 200',
            [
                'oldest' => $oldest,
                'today'  => $now->format('Y-m-d'),
                'survey' => $surveyId,
                // One ask per guest per fortnight, whatever the reason. A
                // family with a season pass visits weekly, and a venue that
                // asks them every time is the venue everybody mutes.
                'recent' => $now->modify('-14 days')->format('Y-m-d H:i:s'),
            ]
        );

        foreach ($rows as $row) {
            $ended = self::localToUtc((string) $row['visit_date'] . ' ' . self::ASSUMED_VISIT_END);

            if ($ended === null || Reputation::dueAt($ended, $delay) > now_utc()) {
                continue;
            }

            DB::insert('survey_responses', [
                'survey_id'    => $surveyId,
                'contact_id'   => (int) $row['contact_id'],
                'visit_id'     => (int) $row['id'],
                'booking_id'   => $row['booking_id'] === null ? null : (int) $row['booking_id'],
                'public_token' => bin2hex(random_bytes(16)),
                'created_at'   => now_utc(),
            ]);

            $planned++;
        }

        return $planned;
    }


    /**
     * Ask ONE named guest, now — the `send_survey` automation step.
     *
     * The module's own sweep asks everybody an active survey covers, on a
     * fixed delay. This is the other way round: a venue that wants the ask to
     * depend on something (only parties over twenty, only after the balance
     * is paid, three days later rather than twenty hours) sets the survey's
     * trigger to "never" and drives it from a sequence instead.
     *
     * The two cannot double up. Both go through the same de-duplication —
     * one invitation per survey per party, and one ask per guest per
     * fortnight — so with the survey ALSO triggered the sweep gets there
     * first and this reports `already_asked`. That is a skip, not a failure.
     *
     * NOTE a survey switched off still sends from here, deliberately: "off"
     * means the sweep does not send it, and a sequence naming it is the venue
     * saying to. The consequence is that a retryable refusal (quiet hours)
     * leaves a pending row the sweep will not pick up while the survey is
     * off, so the ask is simply missed rather than retried.
     *
     * @param array{survey?:mixed,contact_id?:?int,booking_id?:?int,visit_id?:?int} $opts
     * @return array{sent:bool,reason:string,response_id:?int}
     */
    public static function inviteFor(array $opts): array
    {
        $contactId = isset($opts['contact_id']) ? (int) $opts['contact_id'] : 0;
        $bookingId = isset($opts['booking_id']) ? (int) $opts['booking_id'] : 0;
        $visitId   = isset($opts['visit_id']) ? (int) $opts['visit_id'] : 0;

        if ($contactId <= 0) {
            return ['sent' => false, 'reason' => 'no_contact', 'response_id' => null];
        }

        $survey = self::resolveSurvey($opts['survey'] ?? null, $bookingId > 0);

        if ($survey === null) {
            return ['sent' => false, 'reason' => 'no_survey', 'response_id' => null];
        }

        $surveyId = (int) $survey['id'];

        // Already asked about this party or this visit.
        if ($bookingId > 0 && DB::exists(
            'SELECT id FROM {survey_responses} WHERE survey_id = :s AND booking_id = :b',
            ['s' => $surveyId, 'b' => $bookingId]
        )) {
            return ['sent' => false, 'reason' => 'already_asked', 'response_id' => null];
        }

        if ($visitId > 0 && DB::exists(
            'SELECT id FROM {survey_responses} WHERE survey_id = :s AND visit_id = :v',
            ['s' => $surveyId, 'v' => $visitId]
        )) {
            return ['sent' => false, 'reason' => 'already_asked', 'response_id' => null];
        }

        // And the frequency floor, which applies whatever the reason: a
        // season-pass family visits weekly, and a venue that asks them every
        // time is the venue everybody mutes.
        if (DB::exists(
            'SELECT id FROM {survey_responses} WHERE contact_id = :c AND created_at >= :since',
            [
                'c'     => $contactId,
                'since' => gmdate('Y-m-d H:i:s', time() - (14 * 86400)),
            ]
        )) {
            return ['sent' => false, 'reason' => 'asked_recently', 'response_id' => null];
        }

        $hostId = null;

        if ($bookingId > 0) {
            $hostId = DB::value('SELECT host_user_id FROM {bookings} WHERE id = :id', ['id' => $bookingId]);
            $hostId = $hostId === null ? null : (int) $hostId;
        }

        $responseId = (int) DB::insert('survey_responses', [
            'survey_id'    => $surveyId,
            'contact_id'   => $contactId,
            'booking_id'   => $bookingId > 0 ? $bookingId : null,
            'visit_id'     => $visitId > 0 ? $visitId : null,
            'host_user_id' => $hostId,
            'public_token' => bin2hex(random_bytes(16)),
            'created_at'   => now_utc(),
        ]);

        $row = DB::first(
            'SELECT r.*, s.name AS survey_name, s.type AS survey_type,
                    c.first_name, c.last_name, c.email, c.phone,
                    b.booking_number, b.event_date, b.guest_of_honor_name
               FROM {survey_responses} r
               JOIN {surveys} s ON s.id = r.survey_id
               JOIN {contacts} c ON c.id = r.contact_id
               LEFT JOIN {bookings} b ON b.id = r.booking_id
              WHERE r.id = :id',
            ['id' => $responseId]
        );

        if ($row === null) {
            return ['sent' => false, 'reason' => 'no_contact', 'response_id' => $responseId];
        }

        $outcome = self::invite($row, self::settings());

        return [
            'sent'        => $outcome['sent'],
            'reason'      => $outcome['reason'],
            'response_id' => $responseId,
        ];
    }


    /**
     * Which survey a step means.
     *
     * By id or by name when it says, else the one whose own trigger matches
     * what this run is about — so "send the survey" in a party sequence picks
     * the party survey without anybody naming it. An ACTIVE survey is
     * preferred over an inactive one of the same shape, because a venue that
     * has two and switched one off has already said which they mean.
     *
     * @return array<string,mixed>|null
     */
    private static function resolveSurvey(mixed $named, bool $aboutBooking): ?array
    {
        if (is_numeric($named) && (int) $named > 0) {
            $row = DB::first('SELECT * FROM {surveys} WHERE id = :id', ['id' => (int) $named]);

            return is_array($row) ? $row : null;
        }

        if (is_string($named) && trim($named) !== '') {
            $row = DB::first('SELECT * FROM {surveys} WHERE name = :n', ['n' => trim($named)]);

            if (is_array($row)) {
                return $row;
            }
        }

        $wanted = $aboutBooking ? 'booking.completed' : 'visit.recorded';

        $row = DB::first(
            'SELECT * FROM {surveys} WHERE trigger_event = :t ORDER BY is_active DESC, id LIMIT 1',
            ['t' => $wanted]
        );

        if (is_array($row)) {
            return $row;
        }

        $row = DB::first('SELECT * FROM {surveys} ORDER BY is_active DESC, id LIMIT 1');

        return is_array($row) ? $row : null;
    }


    /**
     * Attempt every invitation that has not gone yet.
     *
     * @param callable():bool $within
     */
    private static function sendPlanned(callable $within): int
    {
        $settings = self::settings();
        $sent     = 0;

        $rows = DB::all(
            'SELECT r.*, s.name AS survey_name, s.type AS survey_type,
                    c.first_name, c.last_name, c.email, c.phone,
                    b.booking_number, b.event_date, b.guest_of_honor_name
               FROM {survey_responses} r
               JOIN {surveys} s ON s.id = r.survey_id
               JOIN {contacts} c ON c.id = r.contact_id
               LEFT JOIN {bookings} b ON b.id = r.booking_id
              WHERE r.invited_at IS NULL AND r.channel IS NULL AND s.is_active = 1
              ORDER BY r.id
              LIMIT ' . self::INVITE_BATCH
        );

        foreach ($rows as $row) {
            if (!$within()) {
                break;
            }

            $outcome = self::invite($row, $settings);

            if ($outcome['sent']) {
                $sent++;
            }
        }

        return $sent;
    }


    /**
     * Send one invitation, and record what happened to it.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $settings
     * @return array{sent:bool,reason:string}
     */
    private static function invite(array $row, array $settings): array
    {
        $channel = $settings['invite_channel'] === 'sms' ? 'sms' : 'email';
        $link    = Mailer::absoluteUrl('/s/' . (string) $row['public_token']);
        $first   = trim((string) ($row['first_name'] ?? ''));

        $tags = [
            'first_name'  => $first,
            'last_name'   => trim((string) ($row['last_name'] ?? '')),
            'venue_name'  => Settings::venueName(),
            'survey_link' => $link,
            'child_name'  => trim((string) ($row['guest_of_honor_name'] ?? '')),
        ];

        $result = $channel === 'sms'
            ? self::inviteBySms($row, $tags)
            : self::inviteByEmail($row, $tags);

        $attempts = (int) (self::answers($row)['attempts'] ?? 0) + 1;

        if ($result['sent']) {
            DB::update('survey_responses', [
                'invited_at' => now_utc(),
                'channel'    => $channel,
            ], 'id = :id', ['id' => (int) $row['id']]);

            return ['sent' => true, 'reason' => 'ok'];
        }

        /*
         * RETRYABLE OR NOT.
         *
         * Quiet hours and a provider wobble will pass, so the row is left
         * pending and tried again — up to a cap, because a number that
         * reports a transient failure for ever would otherwise be retried on
         * every tick until the end of time.
         *
         * Everything else is final, and recorded WITH ITS REASON so the page
         * can say why a guest was never asked. `channel` is set to the
         * refusal so the row drops out of the pending query, and `invited_at`
         * stays NULL so the row is never counted as an invitation that went.
         */
        $retryable = in_array($result['reason'], [
            'quiet_hours', 'provider_error', 'send_failed', 'rate_limited',
        ], true);

        $answers = self::answers($row);
        $answers['attempts'] = $attempts;

        if ($retryable && $attempts < self::INVITE_MAX_ATTEMPTS) {
            DB::update('survey_responses', [
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            ], 'id = :id', ['id' => (int) $row['id']]);

            return ['sent' => false, 'reason' => $result['reason']];
        }

        $answers['invite_refused'] = $result['reason'];

        DB::update('survey_responses', [
            'channel' => $retryable ? 'gave_up' : 'refused',
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ], 'id = :id', ['id' => (int) $row['id']]);

        return ['sent' => false, 'reason' => $result['reason']];
    }


    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $tags
     * @return array{sent:bool,reason:string}
     */
    private static function inviteByEmail(array $row, array $tags): array
    {
        $to = trim((string) ($row['email'] ?? ''));

        if ($to === '') {
            return ['sent' => false, 'reason' => 'no_address'];
        }

        $result = Mailer::sendTemplate('survey-invitation', $to, $tags, [
            'to_name'    => trim($tags['first_name'] . ' ' . $tags['last_name']),
            'contact_id' => (int) $row['contact_id'],
            // TRANSACTIONAL would reach somebody who has opted out of
            // marketing, and a survey is not a confirmation they need. So
            // this goes as marketing, and consent applies.
            'kind'       => Mailer::MARKETING,
        ]);

        return [
            'sent'   => ($result['sent'] ?? false) === true,
            'reason' => (string) ($result['reason'] ?? 'send_failed'),
        ];
    }


    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $tags
     * @return array{sent:bool,reason:string}
     */
    private static function inviteBySms(array $row, array $tags): array
    {
        $to = trim((string) ($row['phone'] ?? ''));

        if ($to === '') {
            return ['sent' => false, 'reason' => 'no_number'];
        }

        $template = DB::first(
            "SELECT body FROM {templates}
              WHERE slug = 'survey-invitation-sms' AND channel = 'sms' AND is_active = 1"
        );

        if ($template === null) {
            return ['sent' => false, 'reason' => 'no_template'];
        }

        $result = Sms::send([
            'to'         => $to,
            'body'       => Mailer::mergeText((string) $template['body'], $tags),
            'contact_id' => (int) $row['contact_id'],
            'kind'       => Sms::MARKETING,
        ]);

        return [
            'sent'   => ($result['sent'] ?? false) === true,
            'reason' => (string) ($result['reason'] ?? 'send_failed'),
        ];
    }


    // =================================================================
    // The public review wall
    // =================================================================

    /**
     * The reviews the venue has chosen to show on its own site.
     *
     * FEATURED ONLY, never "the best ones automatically". Showing a review on
     * the venue's website is a publishing decision — the venue picks, and the
     * minimum rating is a floor on what they are ALLOWED to feature, not a
     * filter that promotes whatever happens to be five stars this week.
     *
     * @return list<array<string,mixed>>
     */
    public static function wallReviews(int $limit = 12): array
    {
        $settings = self::settings();

        if (!$settings['wall_enabled']) {
            return [];
        }

        return DB::all(
            'SELECT platform, author_name, rating, body, posted_at, review_url
               FROM {reviews}
              WHERE is_featured = 1 AND rating >= :min
              ORDER BY COALESCE(posted_at, imported_at) DESC
              LIMIT ' . max(1, min(50, $limit)),
            ['min' => $settings['wall_min_rating']]
        );
    }


    // =================================================================
    // Settings
    // =================================================================

    /**
     * Everything configurable, resolved once.
     *
     * @return array{ask_everyone:bool,auto_escalate:bool,sla_hours:int,invite_channel:string,
     *     lookback_days:int,wall_enabled:bool,wall_min_rating:int,links:array<string,string>,
     *     weights:array<string,int>}
     */
    public static function settings(): array
    {
        $links   = [];
        $weights = [];

        foreach (Reputation::PLATFORMS as $platform) {
            $links[$platform]   = (string) Settings::get('reputation.link_' . $platform, '');
            $weights[$platform] = (int) Settings::get(
                'reputation.weight_' . $platform,
                // Google by default, because it is the one that affects
                // whether a family finds the venue at all.
                $platform === 'google' ? 3 : ($platform === 'facebook' ? 1 : 0)
            );
        }

        return [
            'ask_everyone'    => (bool) Settings::get('reputation.ask_everyone', false),
            'auto_escalate'   => (bool) Settings::get('reputation.auto_escalate', true),
            'sla_hours'       => (int) Settings::get('reputation.sla_hours', self::DEFAULT_SLA_HOURS),
            'invite_channel'  => (string) Settings::get('reputation.invite_channel', 'email'),
            'lookback_days'   => (int) Settings::get(
                'reputation.invite_lookback_days',
                self::DEFAULT_LOOKBACK_DAYS
            ),
            'wall_enabled'    => (bool) Settings::get('reputation.wall_enabled', true),
            'wall_min_rating' => (int) Settings::get('reputation.wall_min_rating', 4),
            'links'           => $links,
            'weights'         => $weights,
        ];
    }


    /**
     * The end of a party, as a UTC stamp.
     *
     * A booking with no end time is assumed to finish mid-afternoon rather
     * than at midnight, so the question goes out the next morning instead of
     * at the start of the following evening.
     */
    private static function endOfBooking(string $eventDate, mixed $endTime): ?string
    {
        $time = is_string($endTime) && preg_match('/^\d{2}:\d{2}/', $endTime) === 1
            ? substr($endTime, 0, 5)
            : self::ASSUMED_BOOKING_END;

        return self::localToUtc($eventDate . ' ' . $time);
    }


    /**
     * A venue-local "Y-m-d H:i" as a UTC stamp.
     *
     * Built IN the venue's zone rather than by adding an offset, so an event
     * on either side of a clock change converts correctly. Adding seconds to
     * a wall-clock time is the bug this avoids.
     *
     * Public because the pages need it too: the date range on the Reputation
     * screens is a run of venue-local DAYS queried against UTC columns, and
     * two copies of this conversion is two places for a clock change to be
     * handled differently.
     */
    public static function localToUtc(string $local): ?string
    {
        try {
            return (new DateTimeImmutable($local, venue_tz()))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }


    /**
     * Whoever a complaint should land on.
     *
     * The first active user holding `feedback_resolve`, falling back to the
     * owner. Not a round-robin: a complaint wants the person who can do
     * something about it, not the next name on a list.
     */
    private static function manager(): ?int
    {
        $id = DB::value(
            'SELECT u.id
               FROM {users} u
               JOIN {role_permissions} rp ON rp.role_id = u.role_id
               JOIN {permissions} p ON p.id = rp.permission_id
              WHERE u.is_active = 1 AND p.slug = :perm
              ORDER BY u.id
              LIMIT 1',
            ['perm' => self::RESOLVE_PERMISSION]
        );

        if ($id !== null) {
            return (int) $id;
        }

        $owner = DB::value(
            "SELECT u.id FROM {users} u
               JOIN {roles} r ON r.id = u.role_id
              WHERE r.slug = 'owner' AND u.is_active = 1
              ORDER BY u.id LIMIT 1"
        );

        return $owner === null ? null : (int) $owner;
    }


    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function answers(array $row): array
    {
        $raw = $row['answers'] ?? null;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }
}
