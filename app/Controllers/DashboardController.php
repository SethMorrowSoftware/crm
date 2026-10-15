<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * The dashboard.
 *
 * In Phase 1 it shows what genuinely exists — install health, playbook
 * activation state, team, and the Safe Mode posture — plus honest placeholders
 * for the revenue widgets that need Phase 2–6 data. Showing a zero where a
 * number will later live is worse than saying "no data yet": a manager would
 * read it as "no bookings today".
 */
final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('dashboard/index', [
            'pageTitle'  => 'Dashboard',
            'greeting'   => $this->greeting(),
            'setup'      => $this->setupChecklist(),
            'playbooks'  => $this->playbookSummary(),
            'stats'      => $this->stats(),
            'health'     => $this->healthSummary(),
            'recentAudit' => Acl::can('view_logs') ? $this->recentActivity() : [],
        ]);
    }

    /** A time-of-day greeting in the venue's timezone, not the server's. */
    private function greeting(): string
    {
        $hour = (int) (new \DateTimeImmutable('now', venue_tz()))->format('G');

        $part = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default    => 'Good evening',
        };

        $name = trim((string) (Auth::user()['name'] ?? ''));
        $first = $name === '' ? '' : explode(' ', $name)[0];

        return $first === '' ? $part : $part . ', ' . $first;
    }

    /**
     * The post-install checklist.
     *
     * A fresh install has a lot of "do this next", and burying it in Settings
     * means it never gets done. Each item knows whether it is complete, so the
     * card disappears once the venue is fully configured.
     *
     * @return array{items:list<array{label:string,done:bool,url:?string,detail:string}>,complete:int,total:int}
     */
    private function setupChecklist(): array
    {
        $items = [];

        $items[] = [
            'label'  => 'Confirm your venue details',
            'done'   => (bool) Settings::get('venue.confirmed', false),
            'url'    => can('settings') ? '/settings/venue' : null,
            'detail' => 'Address, phone, timezone and tax rate drive quotes and message footers.',
        ];

        $userCount = (int) DB::value('SELECT COUNT(*) FROM {users} WHERE deleted_at IS NULL AND is_active = 1');

        $items[] = [
            'label'  => 'Invite your team',
            'done'   => $userCount > 1,
            'url'    => can('users_manage') ? '/settings/users' : null,
            'detail' => $userCount > 1
                ? $userCount . ' active accounts.'
                : 'Only the owner account exists. Add the people who handle party and group inquiries.',
        ];

        $activePlaybooks = (int) DB::value('SELECT COUNT(*) FROM {playbooks} WHERE is_active = 1');

        $items[] = [
            'label'  => 'Activate your first playbook',
            'done'   => $activePlaybooks > 0,
            'url'    => can('view_automations') ? '/playbooks' : null,
            'detail' => $activePlaybooks > 0
                ? $activePlaybooks . ' of 8 playbooks active.'
                : 'Start with Birthday & Celebration Sales — it covers the inquiries you get most.',
        ];

        $cronAge = $this->cronAgeSeconds();

        $items[] = [
            'label'  => 'Set up the cron job',
            'done'   => $cronAge !== null && $cronAge < 900,
            'url'    => can('settings') ? '/settings/health' : null,
            'detail' => $cronAge === null
                ? 'Cron has never run. Reminders, follow-ups and digests cannot fire without it.'
                : 'Last run ' . time_ago(gmdate('Y-m-d H:i:s', time() - $cronAge)) . '.',
        ];

        $items[] = [
            'label'  => 'Leave Safe Mode',
            'done'   => !Settings::safeMode(),
            'url'    => can('sending_override') ? '/settings/system' : null,
            'detail' => Settings::safeMode()
                ? 'Messages are being redirected to the owner. Nothing reaches a guest until you turn this off.'
                : 'Live messaging is enabled.',
        ];

        $complete = count(array_filter($items, static fn (array $i): bool => $i['done']));

        return ['items' => $items, 'complete' => $complete, 'total' => count($items)];
    }

    /**
     * Playbook activation at a glance.
     *
     * @return array{active:int,total:int,rows:list<array<string,mixed>>}
     */
    private function playbookSummary(): array
    {
        $rows = DB::all(
            'SELECT id, number, name, slug, tagline, is_active
               FROM {playbooks} ORDER BY sort_order, number'
        );

        return [
            'active' => count(array_filter($rows, static fn (array $r): bool => (bool) $r['is_active'])),
            'total'  => count($rows),
            'rows'   => $rows,
        ];
    }

    /**
     * Headline counts.
     *
     * Each carries a `ready` flag: false means the module that fills it has
     * not shipped, and the tile says so rather than showing a misleading zero.
     *
     * @return list<array{label:string,value:string,ready:bool,note:string,url:?string}>
     */
    private function stats(): array
    {
        // Cached briefly: the dashboard is the most-hit page in the app and
        // these counts do not need to be to-the-second.
        return Cache::remember('dashboard.stats.' . (Auth::id() ?? 0), 60, function (): array {
            $stats = [];

            $contacts = DB::tableExists('contacts')
                ? (int) DB::value('SELECT COUNT(*) FROM {contacts} WHERE deleted_at IS NULL')
                : 0;

            // The metric that matters is reachable-and-consented guests, not
            // raw rows — see the spec's note on POS-derived contacts.
            $consented = DB::tableExists('contacts')
                ? (int) DB::value(
                    'SELECT COUNT(*) FROM {contacts}
                      WHERE deleted_at IS NULL
                        AND do_not_contact = 0
                        AND (email_consent = 1 OR sms_consent = 1)'
                )
                : 0;

            $stats[] = [
                'label' => 'Contacts',
                'value' => number_format($contacts),
                'ready' => true,
                'note'  => $contacts === 0
                    ? 'No contacts yet'
                    : number_format($consented) . ' reachable with consent',
                'url'   => can('view_contacts') ? '/contacts' : null,
            ];

            $openDeals = DB::tableExists('deals')
                ? (int) DB::value("SELECT COUNT(*) FROM {deals} WHERE status = 'open' AND deleted_at IS NULL")
                : 0;

            $stats[] = [
                'label' => 'Open opportunities',
                'value' => number_format($openDeals),
                'ready' => DB::tableExists('deals'),
                'note'  => $openDeals === 0 ? 'Pipelines land in Phase 2' : 'Across all pipelines',
                'url'   => can('view_deals') ? '/deals' : null,
            ];

            $upcoming = DB::tableExists('bookings')
                ? (int) DB::value(
                    "SELECT COUNT(*) FROM {bookings}
                      WHERE deleted_at IS NULL
                        AND status IN ('confirmed','deposit_paid','paid')
                        AND event_date >= :today",
                    ['today' => (new \DateTimeImmutable('now', venue_tz()))->format('Y-m-d')]
                )
                : 0;

            $stats[] = [
                'label' => 'Upcoming bookings',
                'value' => number_format($upcoming),
                'ready' => DB::tableExists('bookings'),
                'note'  => $upcoming === 0 ? 'Bookings land in Phase 3' : 'Confirmed and paid',
                'url'   => can('view_bookings') ? '/bookings' : null,
            ];

            $openTasks = DB::tableExists('tasks')
                ? (int) DB::value(
                    "SELECT COUNT(*) FROM {tasks}
                      WHERE status IN ('open','in_progress') AND assigned_to = :uid",
                    ['uid' => Auth::id() ?? 0]
                )
                : 0;

            $stats[] = [
                'label' => 'My open tasks',
                'value' => number_format($openTasks),
                'ready' => DB::tableExists('tasks'),
                'note'  => $openTasks === 0 ? 'Nothing assigned to you' : 'Assigned to you',
                'url'   => can('view_tasks') ? '/tasks' : null,
            ];

            return $stats;
        });
    }

    /**
     * @return array{cron_age:?int,queue_depth:int,failed_jobs:int,safe_mode:bool,sending_paused:bool}
     */
    private function healthSummary(): array
    {
        return [
            'cron_age'       => $this->cronAgeSeconds(),
            'queue_depth'    => DB::tableExists('jobs')
                ? (int) DB::value("SELECT COUNT(*) FROM {jobs} WHERE status = 'pending'")
                : 0,
            'failed_jobs'    => DB::tableExists('job_failures')
                ? (int) DB::value('SELECT COUNT(*) FROM {job_failures}')
                : 0,
            'safe_mode'      => Settings::safeMode(),
            'sending_paused' => Settings::sendingPaused(),
        ];
    }

    /** Seconds since cron last completed, or null if it never has. */
    private function cronAgeSeconds(): ?int
    {
        $last = Settings::get('system.cron_last_run');

        if (!is_string($last) || $last === '') {
            return null;
        }

        $timestamp = strtotime($last . ' UTC');

        return $timestamp === false ? null : max(0, time() - $timestamp);
    }

    /** @return list<array<string,mixed>> */
    private function recentActivity(): array
    {
        return DB::all(
            'SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.name AS user_name
               FROM {audit_log} a
               LEFT JOIN {users} u ON u.id = a.user_id
              ORDER BY a.id DESC
              LIMIT 8'
        );
    }
}
