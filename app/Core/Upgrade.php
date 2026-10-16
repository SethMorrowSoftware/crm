<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Applies pending schema files after the application files are replaced.
 *
 * An upgrade on cPanel is "extract the new zip over the old one". Nothing
 * else runs: there is no deploy hook, no SSH for most operators, no migration
 * command they will think to type. So without this, a release that adds a
 * table ships a database that does not have it, and the first page to need it
 * returns a 500 — to the venue, during a shift, with no explanation.
 *
 * Three things keep that from being reckless:
 *
 * 1. **It runs once per deploy, not once per request.** The check is a string
 *    comparison against a settings row, which costs nothing on the hot path.
 * 2. **It is serialised.** Two concurrent requests after an upload would
 *    otherwise both start running DDL. A lock file means one applies and the
 *    other waits, then finds nothing to do.
 * 3. **It cannot leave the version marker lying about what happened.** The
 *    marker is only advanced when every pending file applied. A failure logs
 *    loudly and leaves the marker alone, so the next request tries again
 *    rather than pretending the upgrade succeeded.
 *
 * **IT IS NOT TRIGGERED BY `VERSION` ALONE, AND THAT MATTERS.** MEASURED here:
 * `VERSION` still read `1.0.0-phase1` three phases later, so the check in
 * `cc_apply_pending_upgrade()` returned on its first line and three schema
 * files were never applied by any upgrade — the reporting, API-token and
 * webhook pages would each have answered a venue with a bare 500, mid-shift,
 * for the want of one string somebody forgot to edit. The whole point of this
 * class is that an operator should never have to run a migration; hanging it
 * on a hand-maintained constant put a human back in the loop at the one place
 * there was supposed not to be one.
 *
 * So the trigger is now the SCHEMA DIRECTORY'S OWN CONTENTS as well
 * (`schemaMarker()` — the set of filenames, which is what the runner records
 * applied files by). A forgotten version bump costs nothing; the files speak
 * for themselves.
 */
final class Upgrade
{
    /** Seconds to wait for another process's upgrade before giving up. */
    private const LOCK_WAIT = 20;

    /**
     * A fingerprint of the shipped schema files.
     *
     * NAMES ONLY, not sizes or mtimes, for two reasons: the runner records
     * which FILES it has applied, so a name is exactly the granularity that
     * can change what is pending; and an unzip or a checkout rewrites every
     * mtime, which would make the marker differ after a deploy that changed
     * no schema at all.
     */
    public static function schemaMarker(?string $directory = null): string
    {
        $directory ??= CC_ROOT . '/install/schema';
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];

        $names = array_map('basename', $files);
        sort($names);

        return substr(hash('sha256', implode('|', $names)), 0, 16);
    }

    /**
     * Bring the database up to date with the shipped code.
     *
     * @param string|null $schemaMarker The fingerprint this run is bringing
     *     the database up to. Recorded only on full success, like the version.
     *
     * @return array{ran:bool,applied:list<string>,error:?string}
     */
    public static function run(string $shippedVersion, ?string $schemaMarker = null): array
    {
        $schemaMarker ??= self::schemaMarker();

        $migration = new Migration();

        // The common case after an upgrade that changed no schema: the version
        // moved, nothing is pending. Record the new version and move on.
        if ($migration->pending() === []) {
            Settings::set('system.version', $shippedVersion);
            Settings::set('system.schema_files', $schemaMarker);
            Cache::flush();

            return ['ran' => false, 'applied' => [], 'error' => null];
        }

        $lock = self::acquireLock();

        if ($lock === null) {
            // Another process is doing it. Serving this request against the
            // old schema is wrong, but so is serving it after a timeout, and
            // the next request will be fine. Say so rather than guess.
            Log::warning('Upgrade already in progress elsewhere; this request may see the old schema.');

            return ['ran' => false, 'applied' => [], 'error' => 'locked'];
        }

        try {
            // Re-check inside the lock: the process we queued behind may have
            // finished the work while we waited.
            if ($migration->pending() === []) {
                Settings::set('system.version', $shippedVersion);
                Settings::set('system.schema_files', $schemaMarker);
                Cache::flush();

                return ['ran' => false, 'applied' => [], 'error' => null];
            }

            Log::notice('Applying pending schema files', [
                'to'      => $shippedVersion,
                'from'    => (string) Settings::get('system.version', 'unknown'),
                'pending' => $migration->pending(),
            ]);

            $result = $migration->run();

            if ($migration->pending() !== []) {
                Log::critical('Upgrade incomplete; some schema files did not apply', [
                    'still_pending' => $migration->pending(),
                    'messages'      => $result['messages'],
                ]);

                return [
                    'ran'     => true,
                    'applied' => $result['applied'],
                    'error'   => 'Some schema files did not apply. See storage/logs/.',
                ];
            }

            Settings::set('system.version', $shippedVersion);
            Settings::set('system.schema_files', $schemaMarker);
            Settings::set('system.upgraded_at', gmdate('Y-m-d H:i:s'));
            Cache::flush();

            Log::notice('Upgrade complete', [
                'version' => $shippedVersion,
                'applied' => $result['applied'],
            ]);

            return ['ran' => true, 'applied' => $result['applied'], 'error' => null];
        } catch (Throwable $e) {
            // The version marker is deliberately NOT advanced, so the next
            // request retries. Every CREATE uses IF NOT EXISTS and the runner
            // tolerates "already exists", so a retry completes a partial file
            // rather than failing on what it already made.
            Log::critical('Upgrade failed', ['error' => $e->getMessage()]);

            return ['ran' => true, 'applied' => [], 'error' => $e->getMessage()];
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * @return array{0:resource|false,1:string}|null
     *     The handle to unlock, or null when another process holds the lock.
     *     A `false` handle means no lock could be taken at all.
     */
    private static function acquireLock(): ?array
    {
        $path = (string) Config::get('storage_path', CC_ROOT . '/storage') . '/cache/upgrade.lock';

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            // No lock is possible, so take the risk rather than refuse to
            // upgrade at all: a single-process shared host is the normal case,
            // and an unmigrated database is the worse outcome.
            Log::warning('Could not open the upgrade lock; proceeding without it.', ['path' => $path]);

            return [false, $path];
        }

        $deadline = time() + self::LOCK_WAIT;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);

                return null;
            }

            usleep(250000);
        }

        return [$handle, $path];
    }

    /** @param array{0:resource|false,1:string} $lock */
    private static function releaseLock(array $lock): void
    {
        if (is_resource($lock[0])) {
            @flock($lock[0], LOCK_UN);
            @fclose($lock[0]);
        }
    }
}
