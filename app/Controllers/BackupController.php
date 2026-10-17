<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Backup;
use App\Core\DB;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use RuntimeException;
use Throwable;

/**
 * Backups and data export.
 *
 * Everything here is behind `backup_manage`, which only the owner and the
 * general manager hold by default, because a downloaded backup is the entire
 * guest database in one file. A front-desk account that can see one contact at
 * a time should not be able to walk out with all of them.
 *
 * Every download and every export is audited with the actor, the file and the
 * row count, so there is a record of who took a copy of the guest list and
 * when. That record is the point: it is the difference between a data
 * protection story that ends "and we know exactly what left" and one that
 * does not.
 */
final class BackupController extends Controller
{
    public function index(Request $request): Response
    {
        $backups = Backup::all();

        return $this->view('settings/backups', [
            'pageTitle'  => 'Backups',
            'backups'    => $backups,
            'totalBytes' => array_sum(array_column($backups, 'bytes')),
            'keep'       => (int) Settings::get('system.backup_keep', 7),
            'nightly'    => (bool) Settings::get('system.backup_nightly', true),
            'lastRun'    => Settings::get('system.backup_last_run'),
            'lastError'  => Settings::get('system.backup_last_error'),
            'canCompress' => function_exists('gzopen'),
            'tables'     => Backup::exportableTables(),
            'rowCounts'  => $this->rowCounts(),
        ]);
    }

    /**
     * Take a backup now.
     *
     * The deadline is set from the host's own max_execution_time rather than a
     * constant, so on a host that allows 30 seconds the run aborts cleanly at
     * 22 instead of being killed mid-write.
     */
    public function create(Request $request): Response
    {
        if ($denied = $this->authorize('backup_manage')) {
            return $denied;
        }

        $limit = (int) ini_get('max_execution_time');
        $budget = $limit > 0 ? max(10.0, $limit - 8.0) : 240.0;

        $result = Backup::create([
            'include_volatile' => $request->bool('include_volatile'),
            'deadline'         => microtime(true) + $budget,
            'reason'           => 'manual',
        ]);

        Audit::event('backup.created', [
            'ok'       => $result['ok'],
            'filename' => $result['filename'],
            'bytes'    => $result['bytes'],
            'tables'   => $result['tables'],
            'rows'     => $result['rows'],
            'error'    => $result['error'],
        ]);

        if (!$result['ok']) {
            Settings::set('system.backup_last_error', (string) $result['error']);

            return $this->refuse($request, (string) $result['error'], '/settings/backups');
        }

        Settings::set('system.backup_last_run', now_utc());
        Settings::forget('system.backup_last_error');

        Backup::prune((int) Settings::get('system.backup_keep', 7));

        return $this->success(
            $request,
            sprintf(
                'Backup written: %s (%s, %d tables, %s rows in %ss).',
                (string) $result['filename'],
                $this->humanBytes($result['bytes']),
                $result['tables'],
                number_format($result['rows']),
                $result['seconds']
            ),
            '/settings/backups'
        );
    }

    /**
     * Stream a backup to the browser.
     *
     * Sent through PHP rather than linked directly because storage/ is not
     * web-servable — by design, since a guessable URL to a file containing
     * every guest's phone number would be the worst bug in the application.
     */
    public function download(Request $request): Response
    {
        if ($denied = $this->authorize('backup_manage')) {
            return $denied;
        }

        $filename = (string) $request->route('filename');
        $path = Backup::pathFor($filename);

        if ($path === null) {
            return $this->notFound('That backup no longer exists.');
        }

        Audit::event('backup.downloaded', [
            'filename' => $filename,
            'bytes'    => (int) filesize($path),
        ]);

        return Response::file(
            $path,
            $filename,
            str_ends_with($filename, '.gz') ? 'application/gzip' : 'application/sql'
        );
    }

    public function destroy(Request $request): Response
    {
        if ($denied = $this->authorize('backup_manage')) {
            return $denied;
        }

        $filename = (string) $request->route('filename');

        if (!Backup::delete($filename)) {
            return $this->refuse($request, 'That backup could not be deleted.', '/settings/backups');
        }

        Audit::event('backup.deleted', ['filename' => $filename]);

        return $this->success($request, 'Backup deleted.', '/settings/backups');
    }

    public function saveSettings(Request $request): Response
    {
        if ($denied = $this->authorize('backup_manage')) {
            return $denied;
        }

        $data = $this->validate($request, [
            'keep' => 'required|int|min:1|max:60',
        ], ['keep' => 'Backups to keep']);

        if ($data instanceof Response) {
            return $data;
        }

        Settings::setMany([
            'system.backup_keep'    => (int) $data['keep'],
            'system.backup_nightly' => $request->bool('nightly'),
        ]);

        Audit::event('backup.settings_updated', [
            'keep'    => (int) $data['keep'],
            'nightly' => $request->bool('nightly'),
        ]);

        $removed = Backup::prune((int) $data['keep']);

        return $this->success(
            $request,
            $removed > 0
                ? 'Saved. ' . $removed . ' older backup(s) were removed to match the new limit.'
                : 'Backup settings saved.',
            '/settings/backups'
        );
    }

    /**
     * Export one table as CSV.
     *
     * Streamed rather than built in memory: a venue with five years of visits
     * has a contacts export that will not fit in a 128 MB PHP process, and
     * "export silently produces a truncated file" is a worse failure than
     * "export is slow".
     */
    public function export(Request $request): Response
    {
        if ($denied = $this->authorize('backup_manage')) {
            return $denied;
        }

        $table = (string) $request->str('table');

        if (!in_array($table, Backup::exportableTables(), true)) {
            return $this->refuse($request, 'That table cannot be exported.', '/settings/backups');
        }

        $filename = sprintf('%s-%s.csv', $table, gmdate('Ymd-His'));

        // Audited BEFORE the stream starts, not after it finishes.
        //
        // The headers are already sent by the time the writer runs, so a
        // failure part way through cannot become an error page — and the row
        // that said who took a copy of the guest list used to be written only
        // on success. An export that died after streaming 40,000 contacts left
        // no trace at all, which is the one case the audit trail exists for.
        Audit::event('data.exported', ['table' => $table, 'started' => true]);

        return Response::stream(static function () use ($table): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException('Could not open the output stream.');
            }

            try {
                $rows = Backup::exportCsv($table, $handle);

                Audit::event('data.exported', ['table' => $table, 'rows' => $rows, 'complete' => true]);
            } catch (Throwable $e) {
                // The response is already a 200 with a CSV content type, so
                // there is no way to turn this into an error page — and the
                // bootstrap handler appending one would corrupt the download
                // silently. Mark the file instead: a reader who opens it sees
                // the export stopped, rather than trusting a short file.
                Log::error('A CSV export failed part way through', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);

                Audit::event('data.exported', [
                    'table'    => $table,
                    'complete' => false,
                    'error'    => $e->getMessage(),
                ]);

                echo "\n#### EXPORT INCOMPLETE — this file stopped early and is missing rows ####\n";
            } finally {
                fclose($handle);
            }
        }, $filename, 'text/csv; charset=UTF-8');
    }

    /**
     * Row counts for the tables an operator can export, so the page says how
     * big each download will be before they start it.
     *
     * @return array<string,int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (Backup::exportableTables() as $table) {
            try {
                $counts[$table] = (int) DB::value('SELECT COUNT(*) FROM {' . $table . '}');
            } catch (RuntimeException) {
                // A Phase 2+ table that exists in the schema but has not been
                // populated yet still counts as zero, not as an error page.
                $counts[$table] = 0;
            }
        }

        return $counts;
    }

    /**
     * Kept as a thin pass-through to `bytes_human()`.
     *
     * The body used to live here, and then the import screens needed the same
     * thing — at which point one of the two would have drifted and the same
     * file would have read as 12.3 MB on one page and 12 MB on another.
     */
    private function humanBytes(int $bytes): string
    {
        return bytes_human($bytes);
    }
}
