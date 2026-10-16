<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The triggers nothing fires: the ones that become true as time passes.
 *
 * Most of this venue's automations hang off these rather than off an event.
 * Nobody fires "the party is in fourteen days" — it simply becomes true one
 * morning, and something has to notice. Of the 112 seeded automations, 45 are
 * waiting on one of these.
 *
 * ## Why each scanner is a query and not a loop
 *
 * Every scanner finds the rows that have JUST crossed a threshold and leaves
 * the rest alone. Loading all bookings and filtering in PHP would read the
 * whole diary every minute for the handful of rows that moved, and this runs
 * on a cPanel host beside the venue's website.
 *
 * ## The two rules that stop a scanner messaging somebody twice
 *
 * 1. **A WINDOW, NOT A DEADLINE.** "Fourteen days before the party" is true
 *    for a whole day, and a scanner asking `= 14 days` on a datetime finds
 *    nothing at all on most ticks and everything on one. Each scanner matches
 *    a day — or a bounded range — so it works whether cron fires every minute
 *    or once an hour, and misses nothing if a tick is skipped.
 * 2. **DEDUPLICATION IS PER ENTITY, NOT PER GUEST.** A guest can book two
 *    parties, and the T-14 reminder has to go for each. `AutomationRunner`
 *    checks for an existing run on the same (automation, entity) when a
 *    scanner names one, which is what makes these safe to re-run.
 *
 * ## Cron triggers
 *
 * A five-field cron expression, evaluated in the VENUE's zone — "the first of
 * the month at 10am" means the venue's 10am, and a host in UTC would send it
 * at 5 or 6 in the morning. The last firing is recorded per automation, so a
 * cron automation fires once per matching minute however often it is checked.
 */
final class AutomationTriggers
{
    /**
     * Trigger types that no event fires.
     *
     * Kept here rather than in `Event::CATALOG` because they are not events:
     * nothing publishes them, and an automation using one is answered by a
     * scanner below or by somebody pressing a button. A static gate asserts
     * that every seeded automation's trigger is either in the catalogue or in
     * this list, so an automation that can never fire is a build failure
     * rather than a venue mystery.
     *
     * @var array<string,string>
     */
    public const SCANNED = [
        'cron'                      => 'On a schedule',
        'manual'                    => 'Started by hand, or for a whole segment',
        'booking.upcoming'          => 'A booking is a set time away',
        'booking.waiver_outstanding' => 'Waivers are still missing close to the event',
        'contact.birthday_soon'     => "A guest's birthday is approaching",
        'child.birthday_soon'       => "A child's birthday is approaching",
        'contact.lapsed'            => 'A guest has not visited for the lapse window',
        'deal.idle'                 => 'A deal has had no activity',
        'deal.sla_breached'         => "A deal has passed its stage's deadline",
        'membership.expiring'       => 'A membership is approaching expiry',
        'membership.lapsed'         => 'A membership has passed the lapse window',
        'membership.usage_idle'     => 'A pass has gone unused',
        'conversation.unanswered'   => 'A conversation has gone unanswered',
        'task.overdue'              => 'A task has passed its due date',
        'certificate.expiring'      => 'A donation certificate is close to expiry',
        'company.recurring_due'     => 'A recurring group booking is due again',
    ];

    /**
     * Every trigger an automation may use, with what answers it.
     *
     * The union of the two halves of the engine: `Event::CATALOG`, which
     * modules fire, and `SCANNED` above, which cron looks for. This is the
     * list the builder offers and the list the gates check against, so there
     * is one answer to "what can an automation wait for" rather than two
     * places to keep in step.
     *
     * `pending` is what a trigger is waiting on when this version cannot
     * deliver it yet — the automation pages say so instead of offering a
     * switch that does nothing. Null means it works today.
     *
     * @return array<string,array{label:string,kind:string,pending:?string}>
     */
    public static function catalogue(): array
    {
        $all = [];

        foreach (Event::CATALOG as $event => $label) {
            $all[$event] = [
                'label'   => $label,
                'kind'    => 'event',
                'pending' => Event::pendingOn($event),
            ];
        }

        foreach (self::SCANNED as $trigger => $label) {
            // A scanner WINS over an event of the same name. If both halves
            // ever claim one, the scanner is the one that actually runs, and
            // saying otherwise would send somebody looking for a fire() call
            // that is not there.
            $all[$trigger] = ['label' => $label, 'kind' => 'scanned', 'pending' => null];
        }

        return $all;
    }

    /** Can an automation on this trigger actually happen in this version? */
    public static function isDeliverable(string $trigger): bool
    {
        $entry = self::catalogue()[$trigger] ?? null;

        return $entry !== null && $entry['pending'] === null;
    }

    /** How many rows one scanner will enrol in a single pass. */
    public const SCAN_LIMIT = 200;

    /** The default "days before" when an automation does not say. */
    public const DEFAULT_DAYS = 7;

    /**
     * Run every scanner and enrol what it finds.
     *
     * Returns how many runs were started. Called from cron, once a tick —
     * each scanner is bounded, and one that throws does not stop the others:
     * a broken membership query must not also stop the party reminders.
     */
    public static function scan(): int
    {
        $automations = DB::all(
            'SELECT a.* FROM {automations} a
               LEFT JOIN {playbooks} p ON p.id = a.playbook_id
              WHERE a.is_active = 1
                AND a.trigger_type IN (' . self::placeholders() . ')
                AND (a.playbook_id IS NULL OR p.is_active = 1)
              ORDER BY a.priority, a.id',
            self::scannedParams()
        );

        $started = 0;

        foreach ($automations as $automation) {
            try {
                $started += self::runScanner($automation);
            } catch (Throwable $e) {
                Log::error('An automation scanner failed', [
                    'automation' => (int) $automation['id'],
                    'trigger'    => (string) $automation['trigger_type'],
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $started;
    }

    /** @param array<string,mixed> $automation */
    private static function runScanner(array $automation): int
    {
        $config = self::json($automation['trigger_config'] ?? null);

        return match ((string) $automation['trigger_type']) {
            'cron'                       => self::cron($automation, $config),
            'booking.upcoming'           => self::bookingUpcoming($automation, $config),
            'booking.waiver_outstanding' => self::waiverOutstanding($automation, $config),
            'contact.birthday_soon'      => self::contactBirthday($automation, $config),
            'child.birthday_soon'        => self::childBirthday($automation, $config),
            'contact.lapsed'             => self::contactLapsed($automation, $config),
            'deal.idle'                  => self::dealIdle($automation, $config),
            'deal.sla_breached'          => self::dealSlaBreached($automation, $config),
            'membership.expiring'        => self::membershipExpiring($automation, $config),
            'membership.lapsed'          => self::membershipLapsed($automation, $config),
            'membership.usage_idle'      => self::membershipUsageIdle($automation, $config),
            'conversation.unanswered'    => self::conversationUnanswered($automation, $config),
            'task.overdue'               => self::taskOverdue($automation, $config),
            // `manual` waits for somebody to press a button, and the two
            // remaining ones need tables this phase has not built. Listed in
            // SCANNED so the gate knows they are deliberate, and answered
            // with nothing rather than an error.
            default                      => 0,
        };
    }

    // =================================================================
    // Cron
    // =================================================================

    /**
     * A five-field cron expression, in the venue's own zone.
     *
     * "The first of the month at 10am" has to mean the venue's 10am. A host
     * in UTC — which is most shared hosting — would otherwise send the
     * monthly offer at five or six in the morning, which is both useless and
     * the sort of thing that gets a sender blocked.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function cron(array $automation, array $config): int
    {
        $expression = trim((string) ($config['cron'] ?? $config['expression'] ?? ''));

        if ($expression === '') {
            return 0;
        }

        $zone = self::zone();
        $now  = new DateTimeImmutable('now', $zone);

        if (!self::cronMatches($expression, $now)) {
            return 0;
        }

        /*
         * Fired at most once per matching MINUTE. Cron may run every minute
         * or every five, and a tick that lands twice inside the same minute
         * must not send twice — so the minute itself is the key, recorded on
         * the automation.
         */
        $minute = $now->format('Y-m-d H:i');
        $last   = (string) ($automation['last_run_at'] ?? '');
        $marker = 'cron:' . $minute;

        if (DB::exists(
            "SELECT id FROM {automation_runs}
              WHERE automation_id = :a AND context LIKE :marker LIMIT 1",
            ['a' => (int) $automation['id'], 'marker' => '%' . $marker . '%']
        )) {
            return 0;
        }

        /*
         * A cron automation acts on a SEGMENT, or on nobody in particular.
         * With a segment it enrols each member — that is how the monthly
         * Text Club offer and the weekly scorecard work. Without one it
         * starts a single run with no contact, for a step that does
         * something venue-wide.
         */
        $segmentId = (int) ($config['segment_id'] ?? 0);

        if ($segmentId <= 0) {
            return self::enrolOne($automation, ['cron_minute' => $minute], $marker) ? 1 : 0;
        }

        $contacts = self::segmentMembers($segmentId);
        $started  = 0;

        foreach ($contacts as $contactId) {
            if (self::enrolOne($automation, [
                'contact_id'  => $contactId,
                'cron_minute' => $minute,
            ], $marker)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * Does a five-field cron expression match this moment?
     *
     * Minute, hour, day of month, month, day of week. Supports `*`, a list,
     * a range, and a step (`*&#47;15`, `1-5`, `0,30`). Day-of-month and
     * day-of-week are OR'd when both are restricted, which is what cron
     * itself does and what surprises people — so it is stated here rather
     * than discovered.
     */
    public static function cronMatches(string $expression, DateTimeImmutable $moment): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            return false;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        if (!self::cronField($minute, (int) $moment->format('i'), 0, 59)) {
            return false;
        }

        if (!self::cronField($hour, (int) $moment->format('G'), 0, 23)) {
            return false;
        }

        if (!self::cronField($month, (int) $moment->format('n'), 1, 12)) {
            return false;
        }

        $domRestricted = trim($dayOfMonth) !== '*';
        $dowRestricted = trim($dayOfWeek) !== '*';

        $domMatches = self::cronField($dayOfMonth, (int) $moment->format('j'), 1, 31);
        // 0 and 7 both mean Sunday, as cron allows.
        $dowMatches = self::cronField($dayOfWeek, (int) $moment->format('w'), 0, 7)
            || ((int) $moment->format('w') === 0 && self::cronField($dayOfWeek, 7, 0, 7));

        if ($domRestricted && $dowRestricted) {
            return $domMatches || $dowMatches;
        }

        return $domMatches && $dowMatches;
    }

    private static function cronField(string $field, int $value, int $min, int $max): bool
    {
        $field = trim($field);

        if ($field === '' || $field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $step = 1;

            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                $step = max(1, (int) $stepText);
                $part = trim($part) === '' ? '*' : trim($part);
            }

            $from = $min;
            $to   = $max;

            if ($part !== '*') {
                if (str_contains($part, '-')) {
                    [$fromText, $toText] = explode('-', $part, 2);
                    $from = (int) $fromText;
                    $to   = (int) $toText;
                } else {
                    $from = (int) $part;
                    $to   = $from;
                }
            }

            if ($from > $to || $from < $min || $to > $max) {
                continue;
            }

            if ($value < $from || $value > $to) {
                continue;
            }

            if (($value - $from) % $step === 0) {
                return true;
            }
        }

        return false;
    }

    // =================================================================
    // Bookings
    // =================================================================

    /**
     * A booking N days away.
     *
     * Matched on the DAY, not the instant: "fourteen days before" is true for
     * a whole day, and asking for an exact difference finds nothing on most
     * ticks. Deduplicated per BOOKING, so a guest with two parties gets two
     * reminders.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function bookingUpcoming(array $automation, array $config): int
    {
        $days   = max(0, (int) ($config['days'] ?? self::DEFAULT_DAYS));
        $target = self::localDate('+' . $days . ' days');

        $statuses = is_array($config['statuses'] ?? null) && $config['statuses'] !== []
            ? array_values(array_filter(array_map('strval', $config['statuses'])))
            : ['confirmed', 'deposit_paid', 'paid'];

        $in = [];
        $params = ['date' => $target];

        foreach ($statuses as $index => $status) {
            $key        = 's' . $index;
            $in[]       = ':' . $key;
            $params[$key] = $status;
        }

        $rows = DB::all(
            'SELECT id, contact_id FROM {bookings}
              WHERE event_date = :date
                AND deleted_at IS NULL
                AND status IN (' . implode(', ', $in) . ')
              ORDER BY id
              LIMIT ' . self::SCAN_LIMIT,
            $params
        );

        return self::enrolEach($automation, $rows, 'booking');
    }

    /**
     * A booking close to its date with waivers still unsigned.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function waiverOutstanding(array $automation, array $config): int
    {
        $days   = max(0, (int) ($config['days'] ?? 3));
        $target = self::localDate('+' . $days . ' days');

        $rows = DB::all(
            "SELECT b.id, b.contact_id FROM {bookings} b
              WHERE b.event_date = :date
                AND b.deleted_at IS NULL
                AND b.status IN ('confirmed', 'deposit_paid', 'paid')
                AND NOT EXISTS (
                    SELECT 1 FROM {waiver_signatures} w WHERE w.booking_id = b.id
                )
              ORDER BY b.id
              LIMIT " . self::SCAN_LIMIT,
            ['date' => $target]
        );

        return self::enrolEach($automation, $rows, 'booking');
    }

    // =================================================================
    // Birthdays
    // =================================================================

    /**
     * A guest whose birthday is N days away.
     *
     * Matched on month and day rather than on a computed date, because a
     * birthday has no year — and comparing a 1987 date to today's would find
     * nobody. A leap-day birthday is matched when the target lands on MARCH 1
     * in a non-leap year (see `monthDay()`), so somebody born on February 29
     * is not skipped three years in four.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function contactBirthday(array $automation, array $config): int
    {
        $days   = max(0, (int) ($config['days'] ?? self::DEFAULT_DAYS));
        $target = self::localDate('+' . $days . ' days');
        [$month, $day] = self::monthDay($target);

        $rows = DB::all(
            'SELECT id, id AS contact_id FROM {contacts}
              WHERE deleted_at IS NULL
                AND birth_date IS NOT NULL
                AND MONTH(birth_date) = :m
                AND DAY(birth_date) = :d
              ORDER BY id
              LIMIT ' . self::SCAN_LIMIT,
            ['m' => $month, 'd' => $day]
        );

        return self::enrolEach($automation, $rows, 'contact');
    }

    /**
     * A child whose birthday is N days away.
     *
     * The guardian is the one messaged — a child is not a marketing
     * recipient, and the whole party industry addresses the parent. Reads
     * `birth_month` as well as `birth_date`, because the denormalised column
     * is the only thing filled in for a child whose parent gave the month and
     * not the day, which at a party venue is common and is exactly the child
     * this trigger exists for. A month-only match fires on the first.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function childBirthday(array $automation, array $config): int
    {
        $days   = max(0, (int) ($config['days'] ?? 45));
        $target = self::localDate('+' . $days . ' days');
        [$month, $day] = self::monthDay($target);

        $rows = DB::all(
            'SELECT c.id, c.contact_id FROM {children} c
               JOIN {contacts} g ON g.id = c.contact_id AND g.deleted_at IS NULL
              WHERE c.deleted_at IS NULL
                AND c.contact_id IS NOT NULL
                AND (
                    (c.birth_date IS NOT NULL AND MONTH(c.birth_date) = :m AND DAY(c.birth_date) = :d)
                    OR (c.birth_date IS NULL AND c.birth_month = :bm AND :bd = 1)
                )
              ORDER BY c.id
              LIMIT ' . self::SCAN_LIMIT,
            ['m' => $month, 'd' => $day, 'bm' => $month, 'bd' => $day]
        );

        return self::enrolEach($automation, $rows, 'child');
    }

    // =================================================================
    // Lapsing and idling
    // =================================================================

    /**
     * A guest who has not visited for the lapse window.
     *
     * Fired on the day they CROSS it, not every day afterwards: a window of
     * one day around the threshold, so a guest becomes lapsed once rather
     * than being re-enrolled every morning for the rest of time. The
     * run-once-per-guest flag is the second line of defence, not the first —
     * a win-back ladder is usually set to allow re-entry.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function contactLapsed(array $automation, array $config): int
    {
        $days = max(1, (int) ($config['days'] ?? 120));

        $rows = DB::all(
            'SELECT id, id AS contact_id FROM {contacts}
              WHERE deleted_at IS NULL
                AND last_visit_at IS NOT NULL
                AND DATE(last_visit_at) = DATE(:crossed)
              ORDER BY id
              LIMIT ' . self::SCAN_LIMIT,
            ['crossed' => self::utcDaysAgo($days)]
        );

        return self::enrolEach($automation, $rows, 'contact');
    }

    /**
     * A deal with no activity for N days.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function dealIdle(array $automation, array $config): int
    {
        $days = max(1, (int) ($config['days'] ?? 14));

        $rows = DB::all(
            "SELECT id, contact_id FROM {deals}
              WHERE deleted_at IS NULL
                AND status = 'open'
                AND DATE(COALESCE(last_activity_at, updated_at, created_at)) = DATE(:crossed)
              ORDER BY id
              LIMIT " . self::SCAN_LIMIT,
            ['crossed' => self::utcDaysAgo($days)]
        );

        return self::enrolEach($automation, $rows, 'deal');
    }

    /**
     * A deal that has passed its stage's response deadline.
     *
     * The stage carries `sla_hours` and `DealStage` stamps `sla_due_at` when
     * the deal enters it, so the breach is already computed — this only has to
     * notice it. A party enquiry that nobody answered for four hours is the
     * most expensive thing on this list: the family has already texted two
     * other venues.
     *
     * BOUNDED TO RECENT BREACHES. An unanswered enquiry from February is still
     * past its deadline and would be found by every tick for ever; the
     * enrolment dedup would refuse it each time, which is correct but means
     * 200 rows read every minute to do nothing. A deal breaches once, and this
     * is the window in which it is news.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function dealSlaBreached(array $automation, array $config): int
    {
        $window = max(1, (int) ($config['within_days'] ?? 2));

        $rows = DB::all(
            "SELECT id, contact_id FROM {deals}
              WHERE deleted_at IS NULL
                AND status = 'open'
                AND sla_due_at IS NOT NULL
                AND sla_due_at <= :now
                AND sla_due_at >= :floor
              ORDER BY sla_due_at
              LIMIT " . self::SCAN_LIMIT,
            ['now' => now_utc(), 'floor' => self::utcDaysAgo($window)]
        );

        return self::enrolEach($automation, $rows, 'deal');
    }

    // =================================================================
    // Memberships
    // =================================================================

    /**
     * A membership expiring in N days.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function membershipExpiring(array $automation, array $config): int
    {
        $days = max(0, (int) ($config['days'] ?? 30));

        /*
         * BOTH live states, not only `active`. The nightly sweep renames a
         * membership to `expiring` thirty days out (spec §8.10's state
         * machine), so a scanner looking only for `active` would match the
         * T-30 rung and then silently stop matching for T-14 and T-3 — the
         * ladder would fire its first notice and nothing else, which is the
         * sort of failure nobody notices until a season's renewals are down.
         */
        $rows = DB::all(
            "SELECT id, contact_id FROM {memberships}
              WHERE status IN ('active', 'expiring')
                AND expires_at IS NOT NULL
                AND DATE(expires_at) = DATE(:target)
              ORDER BY id
              LIMIT " . self::SCAN_LIMIT,
            ['target' => self::utcDaysAhead($days)]
        );

        return self::enrolEach($automation, $rows, 'membership');
    }

    /**
     * A membership that lapsed N days ago.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function membershipLapsed(array $automation, array $config): int
    {
        $days = max(0, (int) ($config['days'] ?? 30));

        $rows = DB::all(
            "SELECT id, contact_id FROM {memberships}
              WHERE status IN ('expired', 'lapsed')
                AND expires_at IS NOT NULL
                AND DATE(expires_at) = DATE(:crossed)
              ORDER BY id
              LIMIT " . self::SCAN_LIMIT,
            ['crossed' => self::utcDaysAgo($days)]
        );

        return self::enrolEach($automation, $rows, 'membership');
    }

    /**
     * A pass that has gone unused for N days.
     *
     * `last_used_at` null falls back to when it started, so a pass never used
     * at all is caught — which is the one most worth a nudge.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function membershipUsageIdle(array $automation, array $config): int
    {
        $days = max(1, (int) ($config['days'] ?? 35));

        $rows = DB::all(
            "SELECT id, contact_id FROM {memberships}
              WHERE status = 'active'
                AND DATE(COALESCE(last_used_at, started_at)) = DATE(:crossed)
              ORDER BY id
              LIMIT " . self::SCAN_LIMIT,
            ['crossed' => self::utcDaysAgo($days)]
        );

        return self::enrolEach($automation, $rows, 'membership');
    }

    // =================================================================
    // The queue
    // =================================================================

    /**
     * A conversation nobody has answered for N hours.
     *
     * Hours, not days, because this one is about a guest waiting. Only
     * conversations whose LAST message is inbound count: a thread the venue
     * replied to and the guest has not come back on is not unanswered.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function conversationUnanswered(array $automation, array $config): int
    {
        $hours = max(1, (int) ($config['hours'] ?? 4));
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);

        $rows = DB::all(
            "SELECT c.id, c.contact_id FROM {conversations} c
              WHERE c.status = 'open'
                AND c.last_message_at IS NOT NULL
                AND c.last_message_at <= :since
                AND (c.snoozed_until IS NULL OR c.snoozed_until <= :now)
                AND EXISTS (
                    SELECT 1 FROM {messages} m
                     WHERE m.conversation_id = c.id
                       AND m.direction = 'inbound'
                       AND m.created_at = c.last_message_at
                )
              ORDER BY c.id
              LIMIT " . self::SCAN_LIMIT,
            ['since' => $since, 'now' => now_utc()]
        );

        return self::enrolEach($automation, $rows, 'conversation');
    }

    /**
     * A task that has just gone overdue.
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $config
     */
    private static function taskOverdue(array $automation, array $config): int
    {
        $hours = max(0, (int) ($config['hours'] ?? 0));
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);

        /*
         * A task carries no `contact_id` — it points at an entity, which may
         * be a contact or may be a booking or a deal. So the guest is read
         * through that, and a task about nothing in particular still enrols
         * with no contact: an SLA escalation notifies a USER, and refusing to
         * run it because the task had no guest attached would silence
         * exactly the automation the venue most wants.
         */
        $rows = DB::all(
            "SELECT t.id,
                    CASE WHEN t.entity_type IN ('contact', 'contacts')
                         THEN t.entity_id ELSE NULL END AS contact_id
               FROM {tasks} t
              WHERE t.status = 'open'
                AND t.due_at IS NOT NULL
                AND t.due_at <= :since
              ORDER BY t.id
              LIMIT " . self::SCAN_LIMIT,
            ['since' => $since]
        );

        return self::enrolEach($automation, $rows, 'task');
    }

    // =================================================================
    // Enrolling what a scanner found
    // =================================================================

    /**
     * @param array<string,mixed>       $automation
     * @param list<array<string,mixed>> $rows
     */
    private static function enrolEach(array $automation, array $rows, string $entityType): int
    {
        $started = 0;

        foreach ($rows as $row) {
            $payload = [
                'entity_type' => $entityType,
                'entity_id'   => (int) $row['id'],
            ];

            if (($row['contact_id'] ?? null) !== null) {
                $payload['contact_id'] = (int) $row['contact_id'];
            }

            if (self::enrolOne($automation, $payload)) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $payload
     */
    private static function enrolOne(array $automation, array $payload, ?string $marker = null): bool
    {
        try {
            $runId = AutomationRunner::enrol(
                $automation,
                $payload,
                (string) $automation['trigger_type']
            );

            if ($runId === null) {
                return false;
            }

            /*
             * The cron marker is written into the run's context AFTER
             * enrolment, because that is what the next pass reads to know
             * this minute has already been handled. Written here rather than
             * passed through `enrol()` so the runner has no idea cron exists.
             */
            if ($marker !== null) {
                $context = DB::value('SELECT context FROM {automation_runs} WHERE id = :id', ['id' => $runId]);
                $decoded = self::json($context);
                $decoded['cron_marker'] = $marker;

                DB::update('automation_runs', [
                    'context' => json_encode($decoded),
                ], 'id = :id', ['id' => $runId]);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Could not enrol from a scanner', [
                'automation' => (int) $automation['id'],
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return list<int> */
    private static function segmentMembers(int $segmentId): array
    {
        $segment = DB::first('SELECT rules FROM {segments} WHERE id = :id', ['id' => $segmentId]);

        if ($segment === null) {
            return [];
        }

        try {
            $compiled = SegmentEngine::compile(self::json($segment['rules'] ?? null));
        } catch (Throwable $e) {
            // A segment that will not compile enrols NOBODY. Treating it as
            // "everybody" is how a cron automation comes to message the whole
            // contact list.
            Log::error('A cron automation\'s segment would not compile', [
                'segment' => $segmentId,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }

        $rows = DB::all(
            'SELECT c.id FROM {contacts} c WHERE ' . $compiled['sql']
                . ' ORDER BY c.id LIMIT ' . self::SCAN_LIMIT,
            $compiled['params']
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    // =================================================================
    // Clocks
    // =================================================================

    private static function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone(Settings::timezone());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** A local calendar date, N days from today. */
    private static function localDate(string $modifier): string
    {
        return (new DateTimeImmutable('now', self::zone()))->modify($modifier)->format('Y-m-d');
    }

    /** @return array{int,int} */
    private static function monthDay(string $date): array
    {
        $parts = explode('-', $date);

        $month = (int) ($parts[1] ?? 0);
        $day   = (int) ($parts[2] ?? 0);

        /*
         * A TARGET OF MARCH 1 IN A NON-LEAP YEAR ALSO MATCHES FEBRUARY 29.
         *
         * Otherwise somebody born on a leap day is skipped three years in
         * four: there is no February 29 to match in 2026, and the scanner
         * compares a target date's month and day against the stored birth
         * date's. March 1 rather than February 28 because that is the day
         * their birthday falls on when the year has no 29th — the same
         * reading most civil calendars take.
         */
        if ($month === 3 && $day === 1 && !self::isLeapYear((int) ($parts[0] ?? 0))) {
            return [2, 29];
        }

        return [$month, $day];
    }

    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function utcDaysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    private static function utcDaysAhead(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() + $days * 86400);
    }

    private static function placeholders(): string
    {
        return implode(', ', array_map(
            static fn (int $index): string => ':t' . $index,
            range(0, count(self::SCANNED) - 1)
        ));
    }

    /** @return array<string,string> */
    private static function scannedParams(): array
    {
        $params = [];

        foreach (array_keys(self::SCANNED) as $index => $trigger) {
            $params['t' . $index] = $trigger;
        }

        return $params;
    }

    /** @return array<string,mixed> */
    private static function json(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }
}
