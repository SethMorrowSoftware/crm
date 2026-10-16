<?php

declare(strict_types=1);

/**
 * Castle CRM scheduled work.
 *
 * The one piece of server configuration Castle CRM needs:
 *
 *   * * * * * /usr/local/bin/php /home/USER/public_html/cron.php >/dev/null 2>&1
 *
 * Can also be triggered over HTTP where a host offers no CLI cron:
 *
 *   https://your-domain/cron.php?token=<cron_token from config.php>
 *
 * Design notes:
 *   - Every tick works to a WALL-CLOCK BUDGET and stops cleanly when it runs
 *     out, because shared hosts kill long-running processes. Unfinished work
 *     is simply picked up next minute.
 *   - A lock file prevents overlapping runs, which would double-send.
 *   - A heartbeat is written at the end, and the health page alerts when it
 *     goes stale. Silent cron failure is the most damaging failure mode this
 *     application has.
 */

define('CC_ROOT', __DIR__);
define('CC_START', microtime(true));

$isWeb = PHP_SAPI !== 'cli';

if ($isWeb) {
    // Tell bootstrap to do a full web-style boot but keep cron semantics.
    define('CC_WEB_CRON', true);
}

require CC_ROOT . '/app/bootstrap.php';

use App\Core\Backup;
use App\Core\Config;
use App\Core\DB;
use App\Core\Log;
use App\Core\Settings;

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

if ($isWeb) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');

    $expected = (string) Config::get('cron_token', '');
    $provided = (string) ($_GET['token'] ?? '');

    // No token configured means web cron is disabled entirely, rather than
    // open to anyone who guesses the URL.
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        Log::warning('Rejected web cron request', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
        exit("Forbidden.\n");
    }
}

if (!is_file(CC_ROOT . '/config/config.php')) {
    cron_out('Castle CRM is not installed yet. Nothing to do.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Lock — never let two ticks overlap
// ---------------------------------------------------------------------------

$lockPath = (string) Config::get('storage_path', CC_ROOT . '/storage') . '/cache/cron.lock';

if (!is_dir(dirname($lockPath))) {
    @mkdir(dirname($lockPath), 0775, true);
}

$lock = @fopen($lockPath, 'c');

if ($lock === false) {
    cron_out('Could not open the cron lock file. Check storage/cache permissions.');
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // A previous tick is still working. Expected occasionally; not an error.
    cron_out('Another cron run is still in progress. Skipping this tick.');
    fclose($lock);
    exit(0);
}

// ---------------------------------------------------------------------------
// Budget
// ---------------------------------------------------------------------------

// Leave headroom under a typical 60s max_execution_time so the run finishes on
// its own terms rather than being killed mid-write.
$budgetSeconds = (int) Settings::get('system.cron_budget_seconds', 50);
$deadline = microtime(true) + $budgetSeconds;

$budgetLeft = static fn (): bool => microtime(true) < $deadline;

$stats = [
    'jobs'        => 0,
    'automations' => 0,
    'triggers'    => 0,
    'rollups'     => 0,
    'woken'       => 0,
    'campaign_sends' => 0,
    'club_sends'     => 0,
    'auto_steps'     => 0,
    'auto_scanned'   => 0,
    'surveys'        => 0,
    'memberships'    => 0,
    'webhooks'       => 0,
    'maintenance' => 0,
    'errors'      => 0,
];

cron_out('Castle CRM cron starting (budget ' . $budgetSeconds . 's).');

// ---------------------------------------------------------------------------
// 1. Drain the job queue
// ---------------------------------------------------------------------------

try {
    $stats['jobs'] = cron_drainJobs($budgetLeft);
} catch (Throwable $e) {
    $stats['errors']++;
    Log::error('Cron: job queue failed', ['error' => $e->getMessage()]);
    cron_out('  ! job queue: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// 2. Count what is due, for the log
//
// The engine itself runs at step 7. This is one indexed count, printed so the
// tick's own log line says how much work was waiting BEFORE it started —
// which is the figure you want when a venue says "automations seem slow":
// the difference between 4 due and 4,000 due is the whole diagnosis.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $due = (int) DB::value(
            "SELECT COUNT(*) FROM {automation_runs}
              WHERE status = 'waiting' AND next_run_at <= :now",
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        $stats['automations'] = $due;
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: automation sweep failed', ['error' => $e->getMessage()]);
    }
}

// ---------------------------------------------------------------------------
// 3. Wake the snoozed conversations whose time has come
//
// EVERY TICK, not nightly. A snooze is somebody saying "remind me at nine" —
// a sweep that only ran once a day would hold a guest's message until the
// small hours of the following morning, which is worse than not offering the
// button at all. The step is deliberately dumb and idempotent: anything due
// goes back in the queue unread, and the next tick finds nothing.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $woken = App\Controllers\InboxController::wakeSnoozed();

        $stats['woken'] = $woken;

        if ($woken > 0) {
            cron_out('  ' . $woken . ' snoozed conversation(s) back in the queue.');
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: waking snoozed conversations failed', ['error' => $e->getMessage()]);
    }
}

// ---------------------------------------------------------------------------
// 4. Walk any sending campaign forward by one chunk
//
// Forty thousand recipients is not a web request. The send button freezes the
// audience and leaves the campaign in `sending`; this moves it along a batch
// at a time, so a big send proceeds at a rate the provider and the venue's
// hosting can both take, and a browser tab closing cannot interrupt it.
//
// Every recipient still goes through Mailer::send() / Sms::send() with their
// contact id attached, so consent, the suppression list, do-not-contact, the
// weekly cap and quiet hours apply per person exactly as they would to a
// reply typed in the inbox.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $sentThisTick = 0;

        foreach (App\Controllers\CampaignController::dueCampaigns() as $campaignId) {
            if (!$budgetLeft()) {
                // Out of time rather than out of work. The next tick picks up
                // where this one stopped — which is the whole point of
                // freezing the audience rather than re-deriving it.
                break;
            }

            $sentThisTick += App\Controllers\CampaignController::sendChunk($campaignId);
        }

        $stats['campaign_sends'] = $sentThisTick;

        if ($sentThisTick > 0) {
            cron_out('  ' . $sentThisTick . ' campaign message(s) attempted.');
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: campaign send failed', ['error' => $e->getMessage()]);
        cron_out('  ! campaign send: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 5. Walk this month's Text Club offer forward by one chunk
//
// The same shape as a campaign, and for the same reason: the list runs to
// tens of thousands and a monthly offer is not a web request. The difference
// is that nothing freezes an audience here — the list IS the audience, and it
// is re-read each tick so somebody who confirmed this morning is included and
// somebody who texted STOP an hour ago is not.
//
// What stops it sending twice is the per-subscriber frequency cap:
// `last_offer_sent_at` is stamped only on a message that really went, and the
// query only returns subscribers whose stamp is older than the floor. So this
// runs every minute of every day and does nothing at all for the other 29.
// There is no "has this month's offer been sent" flag to get out of step with
// what was actually delivered.
//
// Quiet hours are enforced inside Sms::send() like everywhere else, so an
// offer that becomes due at eleven at night waits for the morning rather than
// waking the list.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        // Cheap enough to check every tick: one indexed row, and it returns
        // false outright in a month with no offer written.
        if (App\Controllers\TextClubController::offerPending()) {
            $clubSent = 0;

            while ($budgetLeft()) {
                $attempted = App\Controllers\TextClubController::sendOfferChunk();

                if ($attempted === 0) {
                    break;
                }

                $clubSent += $attempted;
            }

            $stats['club_sends'] = $clubSent;

            if ($clubSent > 0) {
                cron_out('  ' . $clubSent . ' Text Club offer(s) attempted.');
            }
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: Text Club send failed', ['error' => $e->getMessage()]);
        cron_out('  ! Text Club send: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 6. Look for the triggers nothing fires
//
// Nobody fires "the party is in fourteen days" — it becomes true one morning,
// and something has to notice. Of the 111 seeded automations, 45 wait on one
// of these: the T-minus booking reminders, the birthday run-ups, the lapse
// and idle thresholds, and the cron schedules.
//
// Each scanner is a bounded query for the rows that have JUST crossed their
// threshold, and each enrols at most once per entity — so a family with two
// parties gets two reminders and the same party is never reminded twice.
// Runs BEFORE the advance below, so anything enrolled here takes its first
// step on the same tick rather than waiting for the next one.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $scanned = App\Services\AutomationTriggers::scan();

        $stats['auto_scanned'] = $scanned;

        if ($scanned > 0) {
            cron_out('  ' . $scanned . ' automation(s) started by a time-based trigger.');
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: automation scan failed', ['error' => $e->getMessage()]);
        cron_out('  ! automation scan: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. Advance automation runs that are due
//
// A nine-touch nurture is nine passes over nine days, not one pass that
// sleeps. Each tick picks up the runs whose next step has come due and walks
// each one as far as it goes without waiting — so a step with a real delay
// schedules itself and the run is put down again until then.
//
// Every message a step sends goes through Mailer::send() / Sms::send() with
// the contact attached, so consent, the suppression list, do-not-contact, the
// weekly frequency cap and quiet hours apply exactly as they do to a reply
// typed in the inbox. There is no automation-only path to a guest's phone.
//
// Bounded by AutomationRunner::BATCH rather than drained, so one busy
// automation cannot starve the rest of this tick.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $steps = 0;

        while ($budgetLeft()) {
            $advanced = App\Services\AutomationRunner::tick();

            if ($advanced === 0) {
                break;
            }

            $steps += $advanced;
        }

        $stats['auto_steps'] = $steps;

        if ($steps > 0) {
            cron_out('  ' . $steps . ' automation step(s) executed.');
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: automation tick failed', ['error' => $e->getMessage()]);
        cron_out('  ! automation tick: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 8. Ask the guests whose turn it is for feedback
//
// Two halves in one pass: write a row for every party or visit that has just
// crossed its survey delay, then attempt every invitation not yet sent. The
// row comes FIRST and is the de-duplication record, so a guest cannot be
// asked twice — and it is also the work item, so an invitation refused for a
// reason that will pass (quiet hours) is tried again next tick rather than
// lost. See ReputationController's class comment.
//
// Bounded three ways: the lookback window (a venue switching feedback on must
// not email twenty years of guests), the per-tick batch, and this budget.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $invited = App\Services\Feedback::dispatchDue($budgetLeft);

        $stats['surveys'] = $invited;

        if ($invited > 0) {
            cron_out('  ' . $invited . ' feedback invitation(s) sent.');
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: feedback invitations failed', ['error' => $e->getMessage()]);
        cron_out('  ! feedback invitations: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9. Advance every membership to where today says it should be
//
// The state machine from spec §8.10, applied once a tick. It is cheap (one
// bounded read, a write only where something actually changed) and
// idempotent, so running it every minute rather than nightly costs almost
// nothing and means a pass that starts this morning works this morning
// rather than tomorrow.
//
// The renewal LADDER is stamped here too. The notices themselves are sent by
// the seeded renewal automations, which trigger on `membership.expiring`;
// this records which rung each membership has reached so a restarted job
// cannot ask the same family to renew twice.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $swept = App\Services\MembershipLifecycle::sweep($budgetLeft);

        $stats['memberships'] = $swept['changed'];

        if ($swept['changed'] > 0) {
            cron_out('  ' . $swept['changed'] . ' membership(s) advanced: '
                . $swept['started'] . ' started, ' . $swept['expired'] . ' expired, '
                . $swept['lapsed'] . ' lapsed.');
        }

        App\Services\MembershipLifecycle::stampRenewalNotices();
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: membership sweep failed', ['error' => $e->getMessage()]);
        cron_out('  ! membership sweep: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 10. Send the webhook deliveries that are due
//
// EVERY TICK, not nightly. A webhook that told an integration about a booking
// six hours after it happened is worse than one that never fired: the
// integration acts on it, and acts late.
//
// Queueing happens in the request that fired the event; this is the only
// place anything is actually sent, so a dead endpoint costs a cron tick
// rather than a member of staff waiting at the front desk.
// ---------------------------------------------------------------------------

if ($budgetLeft()) {
    try {
        $hooks = App\Services\WebhookDispatcher::send($budgetLeft);

        $stats['webhooks'] = $hooks['delivered'];

        if ($hooks['attempted'] > 0) {
            cron_out('  Webhooks: ' . $hooks['attempted'] . ' attempted, '
                . $hooks['delivered'] . ' delivered, ' . $hooks['failed'] . ' to retry, '
                . $hooks['gave_up'] . ' given up on.');
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: webhook delivery failed', ['error' => $e->getMessage()]);
        cron_out('  ! webhooks: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 11. Nightly maintenance — once a day, not every minute
// ---------------------------------------------------------------------------

if ($budgetLeft() && cron_shouldRunDaily()) {
    try {
        $stats['maintenance'] = cron_dailyMaintenance();
        Settings::set('system.cron_daily_last_run', gmdate('Y-m-d H:i:s'));
        cron_out('  Daily maintenance complete.');
    } catch (Throwable $e) {
        $stats['errors']++;
        Log::error('Cron: daily maintenance failed', ['error' => $e->getMessage()]);
        cron_out('  ! daily maintenance: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 12. Heartbeat
// ---------------------------------------------------------------------------

Settings::set('system.cron_last_run', gmdate('Y-m-d H:i:s'));

// Clears the marker the health page's "test cron" button sets, which is how an
// operator confirms cron is alive without server access.
if (Settings::has('system.cron_test_requested_at')) {
    Settings::forget('system.cron_test_requested_at');
    cron_out('  Cleared the cron test marker — the health page will now show cron as working.');
}

$elapsed = round(microtime(true) - CC_START, 2);

cron_out(sprintf(
    'Done in %ss. Jobs: %d, automations due: %d, conversations woken: %d, '
        . 'campaign messages: %d, Text Club offers: %d, automations started: %d, '
        . 'automation steps: %d, feedback asks: %d, memberships advanced: %d, '
        . 'maintenance tasks: %d, errors: %d.',
    $elapsed,
    $stats['jobs'],
    $stats['automations'],
    $stats['woken'],
    $stats['campaign_sends'],
    $stats['club_sends'],
    $stats['auto_scanned'],
    $stats['auto_steps'],
    $stats['surveys'],
    $stats['memberships'],
    $stats['maintenance'],
    $stats['webhooks'],
    $stats['errors']
));

if ($stats['errors'] > 0) {
    Log::warning('Cron finished with errors', $stats);
}

flock($lock, LOCK_UN);
fclose($lock);

exit($stats['errors'] > 0 ? 1 : 0);

// ===========================================================================
// Functions
// ===========================================================================

function cron_out(string $message): void
{
    // CLI gets a timestamped line; a web trigger gets plain text.
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    } else {
        echo $message . "\n";
    }
}

/**
 * Claim and run queued jobs until the budget runs out.
 *
 * Claiming is a two-step reserve-then-verify, because a shared host may run
 * several cron ticks concurrently if one overruns: the UPDATE only succeeds for
 * whichever process gets there first, so a job cannot be executed twice.
 *
 * @param callable():bool $budgetLeft
 */
function cron_drainJobs(callable $budgetLeft): int
{
    $processed = 0;
    $maxAttempts = 3;

    while ($budgetLeft()) {
        $job = DB::first(
            "SELECT * FROM {jobs}
              WHERE status = 'pending' AND available_at <= :now
              ORDER BY available_at, id
              LIMIT 1",
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        if ($job === null) {
            break;
        }

        // Reserve it. A zero row count means another worker took it.
        $claimed = DB::update('jobs', [
            'status'      => 'reserved',
            'reserved_at' => gmdate('Y-m-d H:i:s'),
            'attempts'    => (int) $job['attempts'] + 1,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], "id = :id AND status = 'pending'", ['id' => (int) $job['id']]);

        if ($claimed === 0) {
            continue;
        }

        try {
            cron_runJob($job);

            DB::update('jobs', [
                'status'     => 'done',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $job['id']]);

            $processed++;
        } catch (Throwable $e) {
            $attempts = (int) $job['attempts'] + 1;

            if ($attempts >= $maxAttempts) {
                // Keep the payload for inspection rather than losing the work.
                DB::insert('job_failures', [
                    'job_id'    => (int) $job['id'],
                    'queue'     => (string) $job['queue'],
                    'type'      => (string) $job['type'],
                    'payload'   => $job['payload'],
                    'error'     => $e->getMessage(),
                    'failed_at' => gmdate('Y-m-d H:i:s'),
                ]);

                DB::update('jobs', [
                    'status'     => 'failed',
                    'last_error' => substr($e->getMessage(), 0, 1000),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => (int) $job['id']]);

                Log::error('Job failed permanently', [
                    'job_id' => (int) $job['id'],
                    'type'   => (string) $job['type'],
                    'error'  => $e->getMessage(),
                ]);
            } else {
                // Exponential backoff: 1, 4, 9 minutes.
                $delay = $attempts * $attempts * 60;

                DB::update('jobs', [
                    'status'       => 'pending',
                    'reserved_at'  => null,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                    'last_error'   => substr($e->getMessage(), 0, 1000),
                    'updated_at'   => gmdate('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => (int) $job['id']]);
            }
        }
    }

    // Recover jobs whose worker died mid-flight, leaving them reserved forever.
    DB::update('jobs', [
        'status'      => 'pending',
        'reserved_at' => null,
        'updated_at'  => gmdate('Y-m-d H:i:s'),
    ], "status = 'reserved' AND reserved_at < :stale", [
        'stale' => gmdate('Y-m-d H:i:s', time() - 600),
    ]);

    return $processed;
}

/**
 * Dispatch one job.
 *
 * Handlers land with their modules. An unknown type throws rather than being
 * silently dropped — a job nobody handles is a bug worth surfacing.
 *
 * @param array<string,mixed> $job
 */
function cron_runJob(array $job): void
{
    $type = (string) $job['type'];

    switch ($type) {
        case 'noop':
            // Used by the health page to prove the queue drains.
            return;

        default:
            throw new RuntimeException("No handler for job type '{$type}'.");
    }
}

/** Has today's maintenance already run? */
function cron_shouldRunDaily(): bool
{
    $last = Settings::get('system.cron_daily_last_run');

    if (!is_string($last) || $last === '') {
        return true;
    }

    // Compare venue-local dates, so "daily" means the venue's day.
    try {
        $lastLocal = (new DateTimeImmutable($last, new DateTimeZone('UTC')))
            ->setTimezone(venue_tz())
            ->format('Y-m-d');
    } catch (Exception) {
        return true;
    }

    $todayLocal = (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d');

    return $lastLocal !== $todayLocal;
}

/**
 * Housekeeping. Each task is independent so one failure does not stop the rest.
 */
function cron_dailyMaintenance(): int
{
    $done = 0;

    // --- Expired sessions
    try {
        $idle = (int) Config::get('session.idle_timeout', 28800);
        DB::delete('sessions', 'last_activity < :cutoff', [
            'cutoff' => gmdate('Y-m-d H:i:s', time() - $idle),
        ]);
        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: session purge failed', ['error' => $e->getMessage()]);
    }

    // --- Expired password reset tokens
    try {
        DB::delete('password_resets', 'expires_at < :now', ['now' => gmdate('Y-m-d H:i:s')]);
        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: reset purge failed', ['error' => $e->getMessage()]);
    }

    // --- API tokens that expired long ago, and old webhook deliveries
    //
    // Neither is a security measure — both are refused on use — but a
    // settings page that lists a museum of dead keys is one nobody reads,
    // and a delivery log that never shrinks buries this week's failure.
    try {
        App\Core\ApiToken::purgeExpired();
        App\Services\WebhookDispatcher::prune(
            (int) Settings::get('system.webhook_keep_days', App\Services\Webhooks::KEEP_DAYS)
        );
        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: token and delivery purge failed', ['error' => $e->getMessage()]);
    }

    // --- Completed jobs, kept a week for inspection
    try {
        DB::delete('jobs', "status = 'done' AND updated_at < :cutoff", [
            'cutoff' => gmdate('Y-m-d H:i:s', time() - 604800),
        ]);
        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: job purge failed', ['error' => $e->getMessage()]);
    }

    // --- Audit log retention
    //
    // Configurable, and deliberately long by default: the audit trail is the
    // record of who touched guest data, and 0 means keep it forever.
    try {
        $retentionDays = (int) Settings::get('system.audit_retention_days', 730);

        if ($retentionDays > 0) {
            DB::delete('audit_log', 'created_at < :cutoff', [
                'cutoff' => gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400)),
            ]);
        }

        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: audit purge failed', ['error' => $e->getMessage()]);
    }

    // --- The daily reporting rollup
    //
    // BEFORE the backup, so a slow backup cannot starve it, and before any
    // pruning that might one day trim the detail it is built from. It
    // recomputes a trailing window rather than accumulating, so running it
    // twice in a night is a no-op and a night it did not run is repaired by
    // the next one — see ReportRollup's note on permanent holes.
    //
    // Its own try, like everything else here: the reports read the real rows
    // and do not depend on this, so a failed rollup is a warning in the log
    // rather than a venue with no figures.
    try {
        $budget = static fn (): bool => (microtime(true) - CC_START) < 180.0;

        // The one-time backfill first, and only while it has not finished.
        // The trailing window fills FORWARD a day a night and never
        // backward, so a venue that imported five years of history would
        // otherwise have a rollup covering the fortnight since they
        // installed this — and every long-range figure would read as though
        // they opened last month.
        $backfill = App\Services\ReportRollup::backfill($budget);

        if ($backfill['ran']) {
            cron_out('  Rollup backfill: ' . $backfill['days'] . ' day(s) from '
                . $backfill['from'] . ' — ' . $backfill['reason'] . '.');
        }

        $rollup = App\Services\ReportRollup::refresh($budget);

        Settings::set('system.rollup_last_run', gmdate('Y-m-d H:i:s'));
        Settings::set('system.rollup_covered_through', $rollup['to']);

        cron_out('  Rollup: ' . $rollup['days'] . ' day(s), ' . $rollup['rows'] . ' figure(s)'
            . ($rollup['stopped'] ? ' — STOPPED on the time budget, the rest is next run.' : '.'));

        if ($rollup['stopped']) {
            // Said out loud rather than left to be inferred from a short
            // table. A venue whose rollup never finishes needs to know.
            Log::warning('Rollup ran out of time', $rollup);
        }

        App\Services\ReportRollup::prune((int) Settings::get('system.rollup_keep_days', 1460));
        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: rollup failed', ['error' => $e->getMessage()]);
        cron_out('  ! rollup: ' . $e->getMessage());
    }

    // --- Nightly database backup
    //
    // Last in the maintenance list, deliberately: a pruning sweep that failed
    // should not stop the backup, and a backup that runs long should not have
    // starved the cheap work that comes before it. The budget passed in is the
    // remainder of this tick, so an oversized database aborts cleanly and
    // leaves no half-written file — and says so on the backups page rather
    // than failing silently.
    try {
        if (Settings::get('system.backup_nightly', true)) {
            $result = Backup::create([
                'deadline' => CC_START + 240.0,
                'reason'   => 'nightly cron',
            ]);

            if ($result['ok']) {
                Settings::set('system.backup_last_run', gmdate('Y-m-d H:i:s'));
                Settings::forget('system.backup_last_error');
                Backup::prune((int) Settings::get('system.backup_keep', 7));
                cron_out('  Backup: ' . $result['filename'] . ' (' . $result['rows'] . ' rows).');
            } else {
                Settings::set('system.backup_last_error', (string) $result['error']);
                Log::error('Nightly backup failed', ['error' => $result['error']]);
                cron_out('  ! backup: ' . $result['error']);
            }
        }

        $done++;
    } catch (Throwable $e) {
        Settings::set('system.backup_last_error', $e->getMessage());
        Log::warning('Maintenance: backup failed', ['error' => $e->getMessage()]);
    }

    // --- Unlock accounts whose lockout has elapsed
    try {
        DB::update('users', [
            'locked_until'    => null,
            'failed_attempts' => 0,
        ], 'locked_until IS NOT NULL AND locked_until < :now', ['now' => gmdate('Y-m-d H:i:s')]);
        $done++;
    } catch (Throwable $e) {
        Log::warning('Maintenance: unlock sweep failed', ['error' => $e->getMessage()]);
    }

    return $done;
}
