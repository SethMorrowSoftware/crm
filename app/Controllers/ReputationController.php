<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Feedback;
use App\Services\Reputation;
use DateTimeImmutable;
use DateTimeZone;
use function count;

/**
 * Feedback and reviews — the venue's pages.
 *
 * Three classes make up this module, and the split is the usual one:
 *
 *   `Services\Reputation` — the rules. Pure: no database, no clock, no mail.
 *   `Services\Feedback`   — the engine. Sends the invitations, records an
 *                           answer, escalates a complaint, creates a review
 *                           ask. Called by cron, by the public page and by
 *                           the `send_survey` automation step.
 *   this class            — the venue's screens, and the figures on them.
 *
 * So nothing here decides anything. It reads, renders, and writes what an
 * operator typed; the gate that decides whether a guest is asked for a public
 * review lives in the service, where three callers can reach it and a test
 * can exercise it without a browser.
 */
final class ReputationController extends Controller
{
    /** Writing permissions, named once so the views and the routes agree. */
    public const RESOLVE_PERMISSION = 'feedback_resolve';

    public const REVIEW_PERMISSION = 'reviews_respond';

    /** The windows the pages offer, in days. */
    public const RANGES = [
        30  => 'Last 30 days',
        90  => 'Last 90 days',
        365 => 'Last 12 months',
    ];

    /** Rows read for a trend. Feedback is low-volume; this is a bound, not a page size. */
    private const TREND_MAX_ROWS = 20000;

    /** The events a survey can hang off, and what each surveys. */
    public const TRIGGERS = [
        'booking.completed' => 'After a party or event',
        'visit.recorded'    => 'After any visit',
    ];

    // =================================================================
    // Reading
    // =================================================================

    /**
     * The one screen that answers "how are we doing, and what needs me now".
     */
    public function index(Request $request): Response
    {
        $days   = $this->chosenRange($request);
        $window = $this->window($days);
        $prior  = $this->priorWindow($window, $days);

        $current = $this->summaryFor($window);
        $before  = $this->summaryFor($prior);

        $settings = Feedback::settings();

        return $this->view('reputation/index', [
            'pageTitle'   => 'Reputation',
            'days'        => $days,
            'ranges'      => self::RANGES,
            'window'      => $window,
            'prior'       => $prior,
            'summary'     => $current,
            'priorSummary' => $before,
            'trend'       => $this->trend($window, $days),
            'byHost'      => $this->byHost($window),
            'funnel'      => $this->funnel($window),
            'detractors'  => $this->openDetractors($settings['sla_hours']),
            'needReply'   => $this->reviewsNeedingReply(),
            'reviewStats' => $this->reviewSummary($window),
            'uninvited'   => $this->refusedInvitations($window),
            'settings'    => $settings,
            'surveys'     => $this->surveyRows(),
            'canResolve'  => Acl::can(self::RESOLVE_PERMISSION),
            'canReply'    => Acl::can(self::REVIEW_PERMISSION),
        ]);
    }

    /** The feedback list, filterable the way a manager actually asks. */
    public function responses(Request $request): Response
    {
        $filter    = $request->str('show', 'all');
        $days      = $this->chosenRange($request);
        $window    = $this->window($days);
        $settings  = Feedback::settings();
        $page      = $this->pagination($request, 50, 200);

        $where  = ['r.responded_at IS NOT NULL'];
        $params = [];

        // "Everything in the window" unless a filter narrows it. The window
        // is deliberately applied to the ANSWER date, not the invitation:
        // a parent who replies a week late belongs on the day they replied.
        $where[]        = 'r.responded_at >= :from AND r.responded_at < :to';
        $params['from'] = $window['from'];
        $params['to']   = $window['to'];

        if (in_array($filter, Reputation::SENTIMENTS, true)) {
            $where[]             = 'r.sentiment = :sentiment';
            $params['sentiment'] = $filter;
        } elseif ($filter === 'open') {
            // Open complaints, regardless of the window — an unresolved
            // detractor from five weeks ago is exactly what this page is for,
            // so the date filter is dropped rather than hiding it.
            $where  = ['r.sentiment = :sentiment', 'r.resolved_at IS NULL', 'r.responded_at IS NOT NULL'];
            $params = ['sentiment' => 'detractor'];
        } elseif ($filter === 'comments') {
            $where[] = "r.comment IS NOT NULL AND r.comment <> ''";
        }

        $sql   = implode(' AND ', $where);
        $total = (int) DB::value('SELECT COUNT(*) FROM {survey_responses} r WHERE ' . $sql, $params);

        $rows = DB::all(
            'SELECT r.*, s.name AS survey_name, s.type AS survey_type,
                    c.first_name, c.last_name, c.email, c.phone,
                    b.booking_number, b.event_date, b.type AS booking_type,
                    u.name AS host_name
               FROM {survey_responses} r
               LEFT JOIN {surveys} s ON s.id = r.survey_id
               LEFT JOIN {contacts} c ON c.id = r.contact_id
               LEFT JOIN {bookings} b ON b.id = r.booking_id
               LEFT JOIN {users} u ON u.id = r.host_user_id
              WHERE ' . $sql . '
              ORDER BY r.responded_at DESC, r.id DESC
              LIMIT ' . $page['perPage'] . ' OFFSET ' . $page['offset'],
            $params
        );

        return $this->view('reputation/feedback', [
            'pageTitle'  => 'Guest feedback',
            'rows'       => $rows,
            'filter'     => $filter,
            'days'       => $days,
            'ranges'     => self::RANGES,
            'total'      => $total,
            'page'       => $page,
            'slaHours'   => $settings['sla_hours'],
            'now'        => now_utc(),
            'canResolve' => Acl::can(self::RESOLVE_PERMISSION),
        ]);
    }

    /** One piece of feedback, with everything a manager needs to ring them. */
    public function showResponse(Request $request): Response
    {
        $row = $this->findResponse($request->routeInt('id'));

        if ($row === null) {
            return $this->notFound('That piece of feedback does not exist.');
        }

        $settings = Feedback::settings();

        return $this->view('reputation/feedback-show', [
            'pageTitle'  => 'Guest feedback',
            'row'        => $row,
            'slaHours'   => $settings['sla_hours'],
            'now'        => now_utc(),
            'overdue'    => Reputation::isOverdue($row, $settings['sla_hours'], now_utc()),
            'took'       => Reputation::resolutionHours(
                $row['responded_at'] === null ? null : (string) $row['responded_at'],
                $row['resolved_at'] === null ? null : (string) $row['resolved_at']
            ),
            'requests'   => DB::all(
                'SELECT * FROM {review_requests} WHERE survey_response_id = :id ORDER BY id DESC',
                ['id' => (int) $row['id']]
            ),
            'tasks'      => DB::all(
                "SELECT id, title, status, due_at, assigned_to
                   FROM {tasks}
                  WHERE entity_type = 'feedback' AND entity_id = :id
                  ORDER BY id DESC LIMIT 10",
                ['id' => (int) $row['id']]
            ),
            'canResolve' => Acl::can(self::RESOLVE_PERMISSION),
        ]);
    }

    /** The review inbox. */
    public function reviews(Request $request): Response
    {
        $filter = $request->str('show', 'all');
        $page   = $this->pagination($request, 50, 200);

        $where  = ['1 = 1'];
        $params = [];

        if ($filter === 'unreplied') {
            $where[] = 'r.replied_at IS NULL';
        } elseif ($filter === 'negative') {
            $where[]              = 'r.rating <= :maxrating';
            $params['maxrating']  = Reputation::REVIEW_NEGATIVE;
        } elseif ($filter === 'featured') {
            $where[] = 'r.is_featured = 1';
        } elseif (in_array($filter, Reputation::PLATFORMS, true)) {
            $where[]            = 'r.platform = :platform';
            $params['platform'] = $filter;
        }

        $sql = implode(' AND ', $where);

        $rows = DB::all(
            'SELECT r.*, u.name AS replier_name
               FROM {reviews} r
               LEFT JOIN {users} u ON u.id = r.reply_by
              WHERE ' . $sql . '
              ORDER BY COALESCE(r.posted_at, r.imported_at) DESC, r.id DESC
              LIMIT ' . $page['perPage'] . ' OFFSET ' . $page['offset'],
            $params
        );

        /*
         * Reply drafting, with no JavaScript.
         *
         * "Start from this wording" is a LINK carrying the template's slug
         * and the review's id, and the textarea comes back pre-filled. The
         * alternative is a script that copies a hidden block into a field,
         * which the Content-Security-Policy would not run inline and which
         * would stop working the moment anything else on the page threw.
         */
        $drafts = DB::all(
            "SELECT name, slug, body FROM {templates}
              WHERE category = 'review-reply' AND is_active = 1
              ORDER BY name"
        );

        return $this->view('reputation/reviews', [
            'pageTitle' => 'Reviews',
            'rows'      => $rows,
            'total'     => (int) DB::value('SELECT COUNT(*) FROM {reviews} r WHERE ' . $sql, $params),
            'filter'    => $filter,
            'page'      => $page,
            'platforms' => Reputation::PLATFORMS,
            'counts'    => $this->reviewCounts(),
            'drafts'    => $drafts,
            'draft'     => $this->chosenDraft($request, $rows, $drafts),
            'canReply'  => Acl::can(self::REVIEW_PERMISSION),
            'wallUrl'   => url('/reviews'),
        ]);
    }

    /** Every review ask, and what became of it. */
    public function requests(Request $request): Response
    {
        $page = $this->pagination($request, 50, 200);

        return $this->view('reputation/requests', [
            'pageTitle' => 'Review asks',
            'rows'      => DB::all(
                'SELECT q.*, c.first_name, c.last_name, b.booking_number,
                        r.score, r.sentiment
                   FROM {review_requests} q
                   LEFT JOIN {contacts} c ON c.id = q.contact_id
                   LEFT JOIN {bookings} b ON b.id = q.booking_id
                   LEFT JOIN {survey_responses} r ON r.id = q.survey_response_id
                  ORDER BY q.id DESC
                  LIMIT ' . $page['perPage'] . ' OFFSET ' . $page['offset']
            ),
            'total'     => (int) DB::value('SELECT COUNT(*) FROM {review_requests}'),
            'page'      => $page,
            'funnel'    => $this->funnel($this->window(365)),
            'settings'  => Feedback::settings(),
        ]);
    }

    /** The surveys themselves, and the module's settings. */
    public function surveys(Request $request): Response
    {
        return $this->view('reputation/surveys', [
            'pageTitle'  => 'Feedback settings',
            'surveys'    => $this->surveyRows(),
            'settings'   => Feedback::settings(),
            'triggers'   => self::TRIGGERS,
            'platforms'  => Reputation::PLATFORMS,
            'canConfig'  => Acl::can('settings'),
            'pending'    => (int) DB::value(
                'SELECT COUNT(*) FROM {survey_responses}
                  WHERE invited_at IS NULL AND channel IS NULL'
            ),
            'surveyUrl'  => url('/s/EXAMPLE'),
        ]);
    }

    // =================================================================
    // Writing
    // =================================================================

    /**
     * Mark a complaint dealt with.
     *
     * Fires `feedback.resolved`, which is what the seeded "Detractor resolved
     * — recovery offer" automation hangs off. The note is required: "resolved"
     * with no record of what was done is the state this page exists to
     * replace.
     */
    public function resolve(Request $request): Response
    {
        if ($denied = $this->authorize(self::RESOLVE_PERMISSION)) {
            return $denied;
        }

        $row = $this->findResponse($request->routeInt('id'));

        if ($row === null) {
            return $this->notFound('That piece of feedback does not exist.');
        }

        $result = $this->validate($request, [
            'resolution_note' => 'required|string|max:2000',
        ], ['resolution_note' => 'What you did']);

        if ($result instanceof Response) {
            return $result;
        }

        if ($row['resolved_at'] !== null) {
            // Idempotent on purpose: two managers both pressing the button is
            // a double-click, not an error, and firing the event twice would
            // send the recovery offer twice.
            return $this->success($request, 'That was already resolved.', '/reputation/feedback/' . (int) $row['id']);
        }

        DB::update('survey_responses', [
            'resolved_at'     => now_utc(),
            'resolution_note' => (string) $result['resolution_note'],
            'routed_to'       => Auth::id(),
        ], 'id = :id AND resolved_at IS NULL', ['id' => (int) $row['id']]);

        // Close the task this module raised, so the same complaint is not
        // still sitting in somebody's list after it has been dealt with.
        DB::update('tasks', [
            'status'     => 'done',
            'updated_at' => now_utc(),
        ], "entity_type = 'feedback' AND entity_id = :id AND status IN ('open','in_progress')", [
            'id' => (int) $row['id'],
        ]);

        Audit::record('feedback_resolved', 'survey_response', (int) $row['id'], null, [
            'note' => (string) $result['resolution_note'],
        ]);

        Event::fire('feedback.resolved', [
            'survey_response_id' => (int) $row['id'],
            'contact_id'         => $row['contact_id'] === null ? null : (int) $row['contact_id'],
            'booking_id'         => $row['booking_id'] === null ? null : (int) $row['booking_id'],
            'sentiment'          => (string) ($row['sentiment'] ?? ''),
            'score'              => $row['score'] === null ? null : (int) $row['score'],
            'hours_to_resolve'   => Reputation::resolutionHours(
                $row['responded_at'] === null ? null : (string) $row['responded_at'],
                now_utc()
            ),
        ]);

        return $this->success($request, 'Marked as resolved.', '/reputation/feedback/' . (int) $row['id']);
    }

    /**
     * Log a reply to a review.
     *
     * Castle CRM cannot POST a reply to Google or Yelp — that needs the
     * platform's own API and, for Google, a verified listing (Phase 7). So
     * this records that a reply was written and by whom, which is what makes
     * "reviews awaiting a reply" and the reply-time figure real. The view
     * copies the text to the clipboard and links straight to the review, so
     * the actual posting is two clicks rather than a retype.
     */
    public function reply(Request $request): Response
    {
        if ($denied = $this->authorize(self::REVIEW_PERMISSION)) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $review = DB::first('SELECT * FROM {reviews} WHERE id = :id', ['id' => $id]);

        if ($review === null) {
            return $this->notFound('That review does not exist.');
        }

        $result = $this->validate($request, [
            'reply_body' => 'required|string|max:4000',
        ], ['reply_body' => 'Your reply']);

        if ($result instanceof Response) {
            return $result;
        }

        DB::update('reviews', [
            'reply_body' => (string) $result['reply_body'],
            'replied_at' => now_utc(),
            'reply_by'   => Auth::id(),
        ], 'id = :id', ['id' => $id]);

        Audit::record('review_replied', 'review', $id);

        return $this->success($request, 'Reply logged.', '/reputation/reviews');
    }

    /** Feature a review on the public wall, or take it off. */
    public function feature(Request $request): Response
    {
        if ($denied = $this->authorize(self::REVIEW_PERMISSION)) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $review = DB::first('SELECT id, is_featured, rating FROM {reviews} WHERE id = :id', ['id' => $id]);

        if ($review === null) {
            return $this->notFound('That review does not exist.');
        }

        $on = (int) $review['is_featured'] === 0;

        DB::update('reviews', ['is_featured' => $on ? 1 : 0], 'id = :id', ['id' => $id]);

        Audit::record($on ? 'review_featured' : 'review_unfeatured', 'review', $id);

        if (!$on) {
            return $this->success($request, 'Taken off the review wall.', '/reputation/reviews');
        }

        /*
         * FEATURING SOMETHING THE WALL WILL NOT SHOW IS A DEAD END, and it
         * used to be a silent one. MEASURED by driving the page: featuring a
         * two-star review reported "Featured on the review wall", the wall
         * stayed empty, and nothing anywhere connected the two — the minimum
         * rating is a setting on a different page. An operator would
         * reasonably conclude the wall was broken.
         */
        $floor = Feedback::settings()['wall_min_rating'];

        if ((int) ($review['rating'] ?? 0) < $floor) {
            return $this->success(
                $request,
                'Featured — but the wall only shows ' . $floor . ' stars and above, so this one will'
                    . ' not appear until you lower that in the feedback settings.',
                '/reputation/reviews'
            );
        }

        return $this->success($request, 'Featured on the review wall.', '/reputation/reviews');
    }

    /**
     * Import reviews from a CSV.
     *
     * Paste or upload. Pasting is first because it is what actually happens:
     * a venue exports from Google Takeout or copies a column out of a
     * spreadsheet, and asking them to save a file and find it again on a
     * phone is a step that loses people.
     */
    public function import(Request $request): Response
    {
        if ($denied = $this->authorize(self::REVIEW_PERMISSION)) {
            return $denied;
        }

        $platform = $request->str('platform', 'google');
        $platform = in_array($platform, Reputation::PLATFORMS, true) ? $platform : 'google';

        $csv  = $request->str('csv');
        $file = $request->file('file');

        if ($file !== null && is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name'])) {
            $size = (int) ($file['size'] ?? 0);

            // A review export is kilobytes. A 2MB cap is generous and keeps a
            // mis-chosen file from being read into memory.
            if ($size > 2_097_152) {
                return $this->refuse($request, 'That file is too big to be a review export.', '/reputation/reviews');
            }

            $contents = @file_get_contents($file['tmp_name']);
            $csv      = $contents === false ? $csv : $contents;
        }

        $csv = trim($csv);

        if ($csv === '') {
            return $this->refuse($request, 'Paste the CSV, or choose a file.', '/reputation/reviews');
        }

        $parsed = Reputation::parseReviewCsv($this->csvRows($csv), $platform);

        $added   = 0;
        $updated = 0;

        foreach ($parsed['reviews'] as $review) {
            $existing = null;

            if ($review['external_id'] !== null) {
                $existing = DB::first(
                    'SELECT id FROM {reviews} WHERE platform = :p AND external_id = :x',
                    ['p' => $review['platform'], 'x' => $review['external_id']]
                );
            }

            if ($existing === null && $review['author_name'] !== null && $review['posted_at'] !== null) {
                // No external id on most exports, so the same review
                // re-imported would otherwise duplicate. Author plus platform
                // plus the posting day is as close to an identity as a CSV
                // gives, and a false match (one family, two reviews, one day)
                // loses nothing but the duplicate.
                $existing = DB::first(
                    'SELECT id FROM {reviews}
                      WHERE platform = :p AND author_name = :a AND DATE(posted_at) = DATE(:d)',
                    ['p' => $review['platform'], 'a' => $review['author_name'], 'd' => $review['posted_at']]
                );
            }

            if ($existing !== null) {
                DB::update('reviews', [
                    'rating'      => $review['rating'],
                    'body'        => $review['body'],
                    'review_url'  => $review['review_url'],
                    'sentiment'   => $review['sentiment'],
                    'posted_at'   => $review['posted_at'],
                    'imported_at' => now_utc(),
                ], 'id = :id', ['id' => (int) $existing['id']]);

                $updated++;
                continue;
            }

            $reviewId = (int) DB::insert('reviews', $review + ['imported_at' => now_utc()]);
            $added++;

            // Fires for a genuinely NEW review only, so re-importing the same
            // export does not page a manager about a three-star review from
            // last spring, over and over.
            Event::fire('review.received', [
                'review_id'  => $reviewId,
                'platform'   => $review['platform'],
                'rating'     => $review['rating'],
                'sentiment'  => $review['sentiment'],
                'author'     => $review['author_name'],
                'review_url' => $review['review_url'],
                'imported'   => true,
            ]);
        }

        Audit::record('reviews_imported', 'review', null, null, [
            'platform' => $platform,
            'added'    => $added,
            'updated'  => $updated,
            'problems' => count($parsed['problems']),
        ]);

        $message = $added . ' review' . ($added === 1 ? '' : 's') . ' added'
            . ($updated > 0 ? ', ' . $updated . ' updated' : '') . '.';

        if ($parsed['problems'] !== []) {
            // Reported, not swallowed. A venue reading a rating trend needs to
            // know it was computed from 180 of 200 rows.
            flash('warning', count($parsed['problems']) . ' row'
                . (count($parsed['problems']) === 1 ? '' : 's') . ' could not be used: '
                . implode(' ', array_slice($parsed['problems'], 0, 5)));
        }

        return $this->success($request, $message, '/reputation/reviews');
    }

    /** Edit one survey: whether it runs, what it hangs off, and the delay. */
    public function saveSurvey(Request $request): Response
    {
        if ($denied = $this->authorize('settings')) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $survey = DB::first('SELECT * FROM {surveys} WHERE id = :id', ['id' => $id]);

        if ($survey === null) {
            return $this->notFound('That survey does not exist.');
        }

        $result = $this->validate($request, [
            'name'          => 'required|string|max:120',
            'trigger_event' => 'nullable|in:' . implode(',', array_keys(self::TRIGGERS)),
            'delay_hours'   => 'required|int|min:0|max:720',
        ], [
            'name'          => 'Name',
            'trigger_event' => 'When to ask',
            'delay_hours'   => 'Hours afterwards',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        DB::update('surveys', [
            'name'          => (string) $result['name'],
            'trigger_event' => ($result['trigger_event'] ?? '') === '' ? null : (string) $result['trigger_event'],
            'delay_hours'   => (int) $result['delay_hours'],
            'is_active'     => $request->bool('is_active') ? 1 : 0,
            'updated_at'    => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::record('survey_updated', 'survey', $id, $survey, $result);

        return $this->success($request, 'Saved.', '/reputation/surveys');
    }

    /** The module's settings: the gate, the links, the rotation, the wall. */
    public function saveSettings(Request $request): Response
    {
        if ($denied = $this->authorize('settings')) {
            return $denied;
        }

        $before = Feedback::settings();

        Settings::set('reputation.ask_everyone', $request->bool('ask_everyone'));
        Settings::set('reputation.auto_escalate', $request->bool('auto_escalate'));
        Settings::set('reputation.sla_hours', max(1, min(168, $request->int('sla_hours', Feedback::DEFAULT_SLA_HOURS))));
        Settings::set('reputation.invite_channel', in_array($request->str('invite_channel'), ['email', 'sms'], true)
            ? $request->str('invite_channel')
            : 'email');
        Settings::set('reputation.invite_lookback_days', max(1, min(90, $request->int(
            'invite_lookback_days',
            Feedback::DEFAULT_LOOKBACK_DAYS
        ))));
        Settings::set('reputation.wall_enabled', $request->bool('wall_enabled'));
        Settings::set('reputation.wall_min_rating', max(1, min(5, $request->int('wall_min_rating', 4))));

        foreach (Reputation::PLATFORMS as $platform) {
            $link = trim($request->str('link_' . $platform));

            // Only http(s), because this URL is put in front of a guest.
            if ($link !== '' && !preg_match('~^https?://~i', $link)) {
                $link = '';
            }

            Settings::set('reputation.link_' . $platform, mb_substr($link, 0, 500));
            Settings::set('reputation.weight_' . $platform, max(0, min(99, $request->int('weight_' . $platform, 0))));
        }

        /*
         * FLATTENED for the audit log. `settings()` nests the links and
         * weights under two keys, and a diff of nested arrays is unreadable
         * in the viewer — "links: Array → Array" says nothing about which
         * link changed. One key per setting is what somebody reading the log
         * is actually asking about.
         */
        Audit::record(
            'reputation_settings_updated',
            'setting',
            null,
            self::auditable($before),
            self::auditable(Feedback::settings())
        );

        return $this->success($request, 'Settings saved.', '/reputation/surveys');
    }

    // =================================================================
    // Figures
    // =================================================================

    /**
     * @param array{from:string,to:string} $window
     * @return array<string,mixed>
     */
    private function summaryFor(array $window): array
    {
        /*
         * Counted in SQL and reduced in PHP. The three band counts and one
         * average are all the arithmetic needs — the NPS itself comes from
         * `Reputation::nps()` so the definition lives in exactly one place,
         * and the average is a single AVG over every answered row rather
         * than an average of the three bands' averages, which is only the
         * same number when the bands happen to be the same size.
         */
        $counts = array_map('intval', DB::pairs(
            'SELECT sentiment, COUNT(*) FROM {survey_responses}
              WHERE responded_at >= :from AND responded_at < :to AND sentiment IS NOT NULL
              GROUP BY sentiment',
            ['from' => $window['from'], 'to' => $window['to']]
        ));

        $average = DB::value(
            'SELECT AVG(score) FROM {survey_responses}
              WHERE responded_at >= :from AND responded_at < :to AND score IS NOT NULL',
            ['from' => $window['from'], 'to' => $window['to']]
        );

        $invited = (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses}
              WHERE invited_at >= :from AND invited_at < :to',
            ['from' => $window['from'], 'to' => $window['to']]
        );

        $promoters  = $counts['promoter'] ?? 0;
        $passives   = $counts['passive'] ?? 0;
        $detractors = $counts['detractor'] ?? 0;
        $answered   = $promoters + $passives + $detractors;

        return [
            'answered'      => $answered,
            'promoters'     => $promoters,
            'passives'      => $passives,
            'detractors'    => $detractors,
            'nps'           => Reputation::nps($promoters, $passives, $detractors),
            'average'       => $average === null ? null : round((float) $average, 1),
            'invited'       => $invited,
            'response_rate' => Reputation::rate($answered, $invited),
            'unresolved'    => (int) DB::value(
                "SELECT COUNT(*) FROM {survey_responses}
                  WHERE sentiment = 'detractor' AND resolved_at IS NULL AND responded_at IS NOT NULL"
            ),
        ];
    }

    /**
     * NPS over time, bucketed in the venue's own days or weeks.
     *
     * Bucketed in PHP rather than in SQL, because the group has to be a
     * VENUE-LOCAL day and MySQL's CONVERT_TZ needs the timezone tables
     * loaded — which shared hosting routinely does not have. (Nothing else in
     * this application groups by a local day in SQL either, for the same
     * reason.) The row count is bounded and feedback is low-volume, and the
     * statement is streamed rather than materialised.
     *
     * @param array{from:string,to:string} $window
     * @return list<array<string,mixed>>
     */
    private function trend(array $window, int $days): array
    {
        $grain   = $days <= 31 ? 'day' : 'week';
        $buckets = [];

        $statement = DB::query(
            'SELECT responded_at, sentiment, score
               FROM {survey_responses}
              WHERE responded_at >= :from AND responded_at < :to AND sentiment IS NOT NULL
              ORDER BY responded_at
              LIMIT ' . self::TREND_MAX_ROWS,
            ['from' => $window['from'], 'to' => $window['to']]
        );

        foreach ($statement as $row) {
            $local = local_date((string) $row['responded_at'], 'Y-m-d');

            if ($local === '') {
                continue;
            }

            $key = $grain === 'day'
                ? $local
                : (string) (new DateTimeImmutable($local))->modify('monday this week')->format('Y-m-d');

            $buckets[$key] ??= ['promoter' => 0, 'passive' => 0, 'detractor' => 0, 'scores' => []];
            $buckets[$key][(string) $row['sentiment']]++;

            if ($row['score'] !== null) {
                $buckets[$key]['scores'][] = (int) $row['score'];
            }
        }

        ksort($buckets);

        $out = [];

        foreach ($buckets as $key => $counts) {
            $out[] = [
                'key'        => $key,
                'grain'      => $grain,
                'promoters'  => $counts['promoter'],
                'passives'   => $counts['passive'],
                'detractors' => $counts['detractor'],
                'answered'   => $counts['promoter'] + $counts['passive'] + $counts['detractor'],
                'nps'        => Reputation::nps($counts['promoter'], $counts['passive'], $counts['detractor']),
                'average'    => Reputation::average($counts['scores']),
            ];
        }

        return $out;
    }

    /**
     * CSAT by party host — who the guests say ran a good party.
     *
     * @param array{from:string,to:string} $window
     * @return list<array<string,mixed>>
     */
    private function byHost(array $window): array
    {
        $rows = DB::all(
            "SELECT r.host_user_id, u.name,
                    SUM(r.sentiment = 'promoter')  AS promoters,
                    SUM(r.sentiment = 'passive')   AS passives,
                    SUM(r.sentiment = 'detractor') AS detractors,
                    AVG(r.score) AS avg_score,
                    COUNT(*) AS answered
               FROM {survey_responses} r
               LEFT JOIN {users} u ON u.id = r.host_user_id
              WHERE r.responded_at >= :from AND r.responded_at < :to
                AND r.sentiment IS NOT NULL AND r.host_user_id IS NOT NULL
              GROUP BY r.host_user_id, u.name
              HAVING answered >= 3
              ORDER BY avg_score DESC, answered DESC
              LIMIT 20",
            ['from' => $window['from'], 'to' => $window['to']]
        );

        return array_map(static function (array $row): array {
            $row['nps'] = Reputation::nps(
                (int) $row['promoters'],
                (int) $row['passives'],
                (int) $row['detractors']
            );
            $row['avg_score'] = $row['avg_score'] === null ? null : round((float) $row['avg_score'], 1);

            return $row;
        }, $rows);
    }

    /**
     * @param array{from:string,to:string} $window
     * @return array<string,mixed>
     */
    private function funnel(array $window): array
    {
        $byStatus = DB::pairs(
            'SELECT status, COUNT(*) FROM {review_requests}
              WHERE created_at >= :from AND created_at < :to
              GROUP BY status',
            ['from' => $window['from'], 'to' => $window['to']]
        );

        $funnel = Reputation::requestFunnel(array_map('intval', $byStatus));

        $funnel['by_platform'] = DB::all(
            "SELECT platform, COUNT(*) AS asked,
                    SUM(status = 'clicked') + SUM(status = 'reviewed') AS clicked,
                    SUM(status = 'reviewed') AS reviewed
               FROM {review_requests}
              WHERE created_at >= :from AND created_at < :to
              GROUP BY platform
              ORDER BY asked DESC",
            ['from' => $window['from'], 'to' => $window['to']]
        );

        return $funnel;
    }

    /** @return list<array<string,mixed>> */
    private function openDetractors(int $slaHours): array
    {
        $rows = DB::all(
            "SELECT r.*, c.first_name, c.last_name, c.email, c.phone,
                    b.booking_number, b.event_date
               FROM {survey_responses} r
               LEFT JOIN {contacts} c ON c.id = r.contact_id
               LEFT JOIN {bookings} b ON b.id = r.booking_id
              WHERE r.sentiment = 'detractor' AND r.resolved_at IS NULL
                AND r.responded_at IS NOT NULL
              ORDER BY r.responded_at
              LIMIT 25"
        );

        $now = now_utc();

        return array_map(static function (array $row) use ($slaHours, $now): array {
            $row['overdue'] = Reputation::isOverdue($row, $slaHours, $now);
            $row['due_at']  = Reputation::slaDueAt((string) $row['created_at'], $slaHours);

            return $row;
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function reviewsNeedingReply(): array
    {
        return DB::all(
            'SELECT id, platform, author_name, rating, body, posted_at, review_url
               FROM {reviews}
              WHERE replied_at IS NULL
              ORDER BY rating, COALESCE(posted_at, imported_at) DESC
              LIMIT 10'
        );
    }

    /**
     * @param array{from:string,to:string} $window
     * @return array<string,mixed>
     */
    private function reviewSummary(array $window): array
    {
        $row = DB::first(
            'SELECT COUNT(*) AS n, AVG(rating) AS avg_rating,
                    SUM(replied_at IS NOT NULL) AS replied
               FROM {reviews}
              WHERE COALESCE(posted_at, imported_at) >= :from
                AND COALESCE(posted_at, imported_at) < :to',
            ['from' => $window['from'], 'to' => $window['to']]
        ) ?? [];

        return [
            'count'      => (int) ($row['n'] ?? 0),
            'average'    => ($row['avg_rating'] ?? null) === null ? null : round((float) $row['avg_rating'], 2),
            'replied'    => (int) ($row['replied'] ?? 0),
            'unreplied'  => (int) DB::value('SELECT COUNT(*) FROM {reviews} WHERE replied_at IS NULL'),
            'all_time'   => (int) DB::value('SELECT COUNT(*) FROM {reviews}'),
            'all_rating' => ($v = DB::value('SELECT AVG(rating) FROM {reviews}')) === null
                ? null
                : round((float) $v, 2),
        ];
    }

    /**
     * The guests the module could not ask, and why.
     *
     * On the dashboard beside the response rate, because a low rate caused by
     * "we have no email address for 40 of them" is a different problem from
     * "nobody answers", and the two look identical without this.
     *
     * @param array{from:string,to:string} $window
     * @return array<string,int>
     */
    private function refusedInvitations(array $window): array
    {
        $rows = DB::all(
            "SELECT answers FROM {survey_responses}
              WHERE channel IN ('refused','gave_up')
                AND created_at >= :from AND created_at < :to
              LIMIT 2000",
            ['from' => $window['from'], 'to' => $window['to']]
        );

        $counts = [];

        foreach ($rows as $row) {
            $reason = (string) (Feedback::answers($row)['invite_refused'] ?? 'unknown');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /** @return array<string,int> */
    private function reviewCounts(): array
    {
        $counts = [
            'all'       => (int) DB::value('SELECT COUNT(*) FROM {reviews}'),
            'unreplied' => (int) DB::value('SELECT COUNT(*) FROM {reviews} WHERE replied_at IS NULL'),
            'negative'  => (int) DB::value(
                'SELECT COUNT(*) FROM {reviews} WHERE rating <= :r',
                ['r' => Reputation::REVIEW_NEGATIVE]
            ),
            'featured'  => (int) DB::value('SELECT COUNT(*) FROM {reviews} WHERE is_featured = 1'),
        ];

        foreach (DB::pairs('SELECT platform, COUNT(*) FROM {reviews} GROUP BY platform') as $platform => $n) {
            $counts[(string) $platform] = (int) $n;
        }

        return $counts;
    }

    /** @return list<array<string,mixed>> */
    private function surveyRows(): array
    {
        return DB::all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM {survey_responses} r WHERE r.survey_id = s.id
                      AND r.invited_at IS NOT NULL) AS invited,
                    (SELECT COUNT(*) FROM {survey_responses} r WHERE r.survey_id = s.id
                      AND r.responded_at IS NOT NULL) AS answered
               FROM {surveys} s
              ORDER BY s.id'
        );
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * The draft wording a "start from this" link asked for, filled in.
     *
     * Returns null unless the link named both a real draft and a review that
     * is actually on this page — so a hand-edited URL cannot make the page
     * render a reply box against a review somebody cannot see.
     *
     * @param list<array<string,mixed>> $rows the reviews on screen
     * @param list<array<string,mixed>> $drafts
     * @return array{review_id:int,body:string}|null
     */
    private function chosenDraft(Request $request, array $rows, array $drafts): ?array
    {
        $slug     = $request->str('draft');
        $reviewId = $request->int('review');

        if ($slug === '' || $reviewId <= 0) {
            return null;
        }

        $body = null;

        foreach ($drafts as $draft) {
            if ((string) $draft['slug'] === $slug) {
                $body = (string) $draft['body'];
                break;
            }
        }

        $review = null;

        foreach ($rows as $row) {
            if ((int) $row['id'] === $reviewId) {
                $review = $row;
                break;
            }
        }

        if ($body === null || $review === null) {
            return null;
        }

        return [
            'review_id' => $reviewId,
            'body'      => Mailer::mergeText($body, [
                'author_name' => trim((string) ($review['author_name'] ?? '')) === ''
                    ? 'there'
                    : (string) $review['author_name'],
                'venue_name'  => Settings::venueName(),
                'venue_phone' => (string) Settings::get('venue.phone_display', ''),
                'rating'      => (string) ($review['rating'] ?? ''),
            ]),
        ];
    }

    /** @return array<string,mixed>|null */
    private function findResponse(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT r.*, s.name AS survey_name, s.type AS survey_type,
                    c.first_name, c.last_name, c.email, c.phone,
                    b.booking_number, b.event_date, b.type AS booking_type,
                    u.name AS host_name, ru.name AS resolver_name
               FROM {survey_responses} r
               LEFT JOIN {surveys} s ON s.id = r.survey_id
               LEFT JOIN {contacts} c ON c.id = r.contact_id
               LEFT JOIN {bookings} b ON b.id = r.booking_id
               LEFT JOIN {users} u ON u.id = r.host_user_id
               LEFT JOIN {users} ru ON ru.id = r.routed_to
              WHERE r.id = :id',
            ['id' => $id]
        );
    }

    private function chosenRange(Request $request): int
    {
        $days = $request->int('days', 90);

        return isset(self::RANGES[$days]) ? $days : 90;
    }

    /**
     * A window of venue-local days, expressed in UTC.
     *
     * `to` is the start of TOMORROW locally, so today counts — a manager
     * looking at this at four in the afternoon expects to see the score a
     * parent left at lunchtime.
     *
     * @return array{from:string,to:string,from_local:string,to_local:string}
     */
    private function window(int $days): array
    {
        $today = new DateTimeImmutable('now', venue_tz());
        $start = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $end   = $today->modify('+1 day')->format('Y-m-d');

        return [
            'from'       => (string) Feedback::localToUtc($start . ' 00:00'),
            'to'         => (string) Feedback::localToUtc($end . ' 00:00'),
            'from_local' => $start,
            'to_local'   => $today->format('Y-m-d'),
        ];
    }

    /**
     * @param array{from:string,to:string} $window
     * @return array{from:string,to:string}
     */
    private function priorWindow(array $window, int $days): array
    {
        $from = new DateTimeImmutable($window['from'], new DateTimeZone('UTC'));

        return [
            'from' => $from->modify('-' . $days . ' days')->format('Y-m-d H:i:s'),
            'to'   => $window['from'],
        ];
    }

    /**
     * The settings as one flat key per value, for the audit log.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function auditable(array $settings): array
    {
        $flat = $settings;

        unset($flat['links'], $flat['weights']);

        foreach (Reputation::PLATFORMS as $platform) {
            $flat['link_' . $platform]   = $settings['links'][$platform] ?? '';
            $flat['weight_' . $platform] = $settings['weights'][$platform] ?? 0;
        }

        return $flat;
    }

    /**
     * Split pasted CSV text into rows.
     *
     * `str_getcsv` per line rather than a temporary file, because a venue
     * pastes into a textarea and a quoted field containing a newline is not
     * something a Google export produces. A line that cannot be split is
     * skipped by the parser, which reports it.
     *
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = array_map(static fn ($cell): string => (string) $cell, str_getcsv($line));
        }

        return $rows;
    }
}
