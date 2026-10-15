<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Install health.
 *
 * This page exists because the most common failure on shared hosting is silent:
 * cron is never set up, so reminders and follow-ups simply never fire and
 * nobody notices for a month. Every check here answers "is this actually
 * working?" and says what to do when it is not.
 */
final class HealthController extends Controller
{
    /** Cron is considered stale after this many seconds. */
    private const CRON_STALE_SECONDS = 900;

    public function index(Request $request): Response
    {
        return $this->view('settings/health', [
            'pageTitle' => 'System health',
            'checks'    => $this->runChecks(),
            'cronLine'  => $this->cronLine(),
            'webCron'   => $this->webCronUrl(),
            'phpInfo'   => [
                'version'      => PHP_VERSION,
                'sapi'         => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution' => ini_get('max_execution_time'),
                'upload_max'   => ini_get('upload_max_filesize'),
            ],
        ]);
    }

    /**
     * A machine-readable probe for uptime monitoring.
     *
     * Deliberately sparse: liveness and a schema version, never configuration,
     * paths, versions of anything exploitable, or counts that would leak
     * business information to an unauthenticated caller.
     */
    public function probe(Request $request): Response
    {
        $dbOk = false;

        try {
            $dbOk = DB::value('SELECT 1') !== null;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $cronAge = $this->cronAgeSeconds();

        $healthy = $dbOk && $cronAge !== null && $cronAge < self::CRON_STALE_SECONDS;

        return Response::json([
            'status'     => $healthy ? 'ok' : 'degraded',
            'database'   => $dbOk,
            'cron_fresh' => $cronAge !== null && $cronAge < self::CRON_STALE_SECONDS,
            'time'       => gmdate('c'),
        ], $healthy ? 200 : 503)->noCache();
    }

    /**
     * Write a marker the next cron run will clear, to prove cron is firing.
     */
    public function testCron(Request $request): Response
    {
        Settings::set('system.cron_test_requested_at', now_utc());

        return $this->success(
            $request,
            'Marker set. If cron is working it will clear within a minute or two — reload this page to check.',
            '/settings/health'
        );
    }

    /**
     * @return list<array{
     *   name:string, status:'ok'|'warn'|'fail', detail:string, fix:?string
     * }>
     */
    private function runChecks(): array
    {
        $checks = [];

        // --- PHP -----------------------------------------------------
        $checks[] = [
            'name'   => 'PHP version',
            'status' => PHP_VERSION_ID >= 80100 ? 'ok' : 'fail',
            'detail' => 'Running PHP ' . PHP_VERSION . '.',
            'fix'    => PHP_VERSION_ID >= 80100
                ? null
                : 'Castle CRM needs PHP 8.1 or newer. In cPanel open MultiPHP Manager and select a newer version for this domain.',
        ];

        // --- Required extensions -------------------------------------
        $required = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl', 'fileinfo'];
        $missing  = array_values(array_filter($required, static fn (string $e): bool => !extension_loaded($e)));

        $checks[] = [
            'name'   => 'Required PHP extensions',
            'status' => $missing === [] ? 'ok' : 'fail',
            'detail' => $missing === []
                ? 'All present: ' . implode(', ', $required) . '.'
                : 'Missing: ' . implode(', ', $missing) . '.',
            'fix'    => $missing === []
                ? null
                : 'In cPanel open "Select PHP Version" and tick the missing extensions.',
        ];

        // --- Optional extensions -------------------------------------
        $optional = [
            'zip'      => 'backup export',
            'gd'       => 'image resizing',
            'intl'     => 'locale-aware formatting',
            'apcu'     => 'faster caching',
        ];

        $missingOptional = [];

        foreach ($optional as $extension => $purpose) {
            if (!extension_loaded($extension)) {
                $missingOptional[] = $extension . ' (' . $purpose . ')';
            }
        }

        $checks[] = [
            'name'   => 'Optional PHP extensions',
            'status' => $missingOptional === [] ? 'ok' : 'warn',
            'detail' => $missingOptional === []
                ? 'All optional extensions present.'
                : 'Not installed: ' . implode(', ', $missingOptional) . '.',
            'fix'    => $missingOptional === []
                ? null
                : 'These are not required — the matching features degrade gracefully without them.',
        ];

        // --- POS driver (optional capability, per the spec) -----------
        $posDrivers = array_values(array_filter(
            ['sqlsrv', 'dblib', 'odbc'],
            static fn (string $d): bool => in_array($d, \PDO::getAvailableDrivers(), true)
        ));

        $checks[] = [
            'name'   => 'CenterEdge POS driver',
            'status' => $posDrivers === [] ? 'warn' : 'ok',
            'detail' => $posDrivers === []
                ? 'No MSSQL PDO driver found, so the POS feed cannot run from this host.'
                : 'Available: ' . implode(', ', $posDrivers) . '.',
            'fix'    => $posDrivers === []
                ? 'Optional. Without it, visits come from bookings and front-desk check-in instead of the '
                    . 'CenterEdge card ledger. Installing pdo_sqlsrv or pdo_dblib needs server-level access, '
                    . 'so on shared hosting the POS feed may need to be pushed from the venue instead.'
                : null,
        ];

        // --- Writable paths ------------------------------------------
        $paths = [
            'storage/logs'     => Config::get('storage_path') . '/logs',
            'storage/cache'    => Config::get('storage_path') . '/cache',
            'storage/uploads'  => Config::get('storage_path') . '/uploads',
            'storage/exports'  => Config::get('storage_path') . '/exports',
            'storage/backups'  => Config::get('storage_path') . '/backups',
        ];

        $unwritable = [];

        foreach ($paths as $label => $path) {
            if (!is_dir((string) $path) || !is_writable((string) $path)) {
                $unwritable[] = $label;
            }
        }

        $checks[] = [
            'name'   => 'Writable directories',
            'status' => $unwritable === [] ? 'ok' : 'fail',
            'detail' => $unwritable === []
                ? 'All storage directories are writable.'
                : 'Not writable: ' . implode(', ', $unwritable) . '.',
            'fix'    => $unwritable === []
                ? null
                : 'Set these to 0775 in cPanel File Manager (Permissions), or 0755 if PHP runs as your own user.',
        ];

        // --- Database -------------------------------------------------
        try {
            $version = DB::serverVersion();
            $tables  = (int) DB::value(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name LIKE :prefix',
                ['prefix' => DB::prefix() . '%']
            );

            $checks[] = [
                'name'   => 'Database',
                'status' => 'ok',
                'detail' => sprintf('Connected to MySQL %s. %d Castle CRM tables.', $version, $tables),
                'fix'    => null,
            ];
        } catch (\Throwable $e) {
            $checks[] = [
                'name'   => 'Database',
                'status' => 'fail',
                'detail' => 'Could not query the database.',
                'fix'    => 'Check the credentials in config/config.php against cPanel → MySQL Databases.',
            ];
        }

        // --- Schema version -------------------------------------------
        $applied = DB::tableExists('migrations')
            ? (int) DB::value('SELECT COUNT(*) FROM {migrations}')
            : 0;

        $onDisk = count(glob(CC_ROOT . '/install/schema/*.sql') ?: []);

        $checks[] = [
            'name'   => 'Schema',
            'status' => $applied >= $onDisk ? 'ok' : 'warn',
            'detail' => sprintf('%d of %d schema files applied.', $applied, $onDisk),
            'fix'    => $applied >= $onDisk
                ? null
                : 'Run update.php to apply the pending migrations.',
        ];

        // --- Cron -----------------------------------------------------
        $cronAge = $this->cronAgeSeconds();

        $checks[] = [
            'name'   => 'Cron',
            'status' => match (true) {
                $cronAge === null                      => 'fail',
                $cronAge < self::CRON_STALE_SECONDS    => 'ok',
                default                                => 'warn',
            },
            'detail' => $cronAge === null
                ? 'Cron has never run.'
                : 'Last run ' . time_ago(gmdate('Y-m-d H:i:s', time() - $cronAge)) . '.',
            'fix'    => $cronAge !== null && $cronAge < self::CRON_STALE_SECONDS
                ? null
                : 'Add the cron line shown below in cPanel → Cron Jobs. Without it, no reminder, '
                    . 'follow-up, digest or automation will ever fire.',
        ];

        // --- Queue ----------------------------------------------------
        $pending = DB::tableExists('jobs')
            ? (int) DB::value("SELECT COUNT(*) FROM {jobs} WHERE status = 'pending'")
            : 0;
        $failed = DB::tableExists('job_failures')
            ? (int) DB::value('SELECT COUNT(*) FROM {job_failures}')
            : 0;

        $checks[] = [
            'name'   => 'Job queue',
            'status' => match (true) {
                $failed > 0     => 'warn',
                $pending > 5000 => 'warn',
                default         => 'ok',
            },
            'detail' => sprintf('%d pending, %d failed.', $pending, $failed),
            'fix'    => $failed > 0 ? 'Review the failures in the log; they are retained for inspection.' : null,
        ];

        // --- Installer left in place ----------------------------------
        $installerPresent = is_dir(CC_ROOT . '/install');
        $locked           = is_file(CC_ROOT . '/install/.lock');

        $checks[] = [
            'name'   => 'Installer',
            'status' => !$installerPresent ? 'ok' : ($locked ? 'warn' : 'fail'),
            'detail' => !$installerPresent
                ? 'The install directory has been removed.'
                : ($locked
                    ? 'Still present but locked against re-running.'
                    : 'Present and UNLOCKED — anyone could reconfigure this install.'),
            'fix'    => !$installerPresent
                ? null
                : 'Delete the install/ directory in cPanel File Manager now that setup is finished.',
        ];

        // --- Safe Mode ------------------------------------------------
        $checks[] = [
            'name'   => 'Safe Mode',
            'status' => Settings::safeMode() ? 'warn' : 'ok',
            'detail' => Settings::safeMode()
                ? 'On — every outbound message is redirected to the owner.'
                : 'Off — messages reach real guests.',
            'fix'    => Settings::safeMode()
                ? 'Expected on a new install. Turn it off in Settings → System when you are ready to go live.'
                : null,
        ];

        // --- HTTPS ----------------------------------------------------
        $secure = request()->isSecure();

        $checks[] = [
            'name'   => 'HTTPS',
            'status' => $secure ? 'ok' : 'fail',
            'detail' => $secure ? 'This page was served over HTTPS.' : 'This page was served over plain HTTP.',
            'fix'    => $secure
                ? null
                : 'Castle CRM handles guest contact details and staff passwords. Enable AutoSSL in '
                    . 'cPanel → SSL/TLS Status, then uncomment the HTTPS redirect block in .htaccess.',
        ];

        return $checks;
    }

    private function cronAgeSeconds(): ?int
    {
        $last = Settings::get('system.cron_last_run');

        if (!is_string($last) || $last === '') {
            return null;
        }

        $timestamp = strtotime($last . ' UTC');

        return $timestamp === false ? null : max(0, time() - $timestamp);
    }

    /** The exact cron line to paste into cPanel. */
    private function cronLine(): string
    {
        $php = PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'fpm')
            ? PHP_BINARY
            : '/usr/local/bin/php';

        return sprintf('* * * * * %s %s/cron.php >/dev/null 2>&1', $php, CC_ROOT);
    }

    /** A token-guarded URL for hosts that do not offer CLI cron. */
    private function webCronUrl(): ?string
    {
        $token = (string) Config::get('cron_token', '');

        if ($token === '') {
            return null;
        }

        $base = (string) (Config::get('app_url') ?: ('https://' . request()->host() . request()->basePath()));

        return rtrim($base, '/') . '/cron.php?token=' . $token;
    }
}
