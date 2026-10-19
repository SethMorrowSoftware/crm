<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Services\Importer;
use App\Services\ImportRunner;
use App\Services\VisitRecorder;
use Throwable;

/**
 * CSV import: upload, map, dry run, commit, read the refusals.
 *
 * The flow is deliberately four screens rather than one button, because an
 * import is irreversible in the way that matters — nobody can look at 50,000
 * contacts afterwards and tell which 300 are wrong — and because the mapping
 * is the step where almost every mistake happens.
 *
 * **BEHIND `contacts_manage` AND `backup_manage` TOGETHER.** Importing is the
 * mirror image of exporting: one takes the guest database out of the building
 * and the other puts a file of unknown provenance into it, with consent
 * claims attached. `backup_manage` is already the narrower permission for
 * exactly that reason, so the import borrows it rather than inventing a
 * fifth key nobody will remember to grant.
 */
final class ImportController extends Controller
{
    /** How many report rows one page shows. */
    private const PER_PAGE = 100;

    public function index(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        return $this->view('imports/index', [
            'pageTitle' => 'Import a file',
            'jobs'      => DB::all(
                'SELECT i.*, u.name AS created_by_name
                   FROM {imports} i
                   LEFT JOIN {users} u ON u.id = i.created_by
                  ORDER BY i.id DESC
                  LIMIT 25'
            ),
            'maxBytes' => Importer::MAX_BYTES,
            'uploadMax' => $this->uploadLimit(),
        ]);
    }

    /**
     * Take the file, read its header, and go to the mapping screen.
     *
     * NOTHING IS PARSED BEYOND THE HEADER HERE. A 30MB file is not read in a
     * web request; the dry run does that, from cron, where it has a budget and
     * can resume.
     */
    public function store(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        $entity = (string) $request->input('entity', 'contacts');

        if (!Importer::isEntity($entity)) {
            flash('error', 'Pick whether this file is guests, visits or children.');

            return $this->redirect('/imports');
        }

        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', $this->uploadError(is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE));

            return $this->redirect('/imports');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            flash('error', 'That file is empty.');

            return $this->redirect('/imports');
        }

        if ($size > Importer::MAX_BYTES) {
            flash('error', 'That file is ' . bytes_human($size) . ', and the limit is '
                . bytes_human(Importer::MAX_BYTES) . '. Split it and import the parts.');

            return $this->redirect('/imports');
        }

        $temp = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($temp)) {
            // Not a real upload: the only way here is a forged request.
            flash('error', 'That upload could not be read.');

            return $this->redirect('/imports');
        }

        /*
         * THE HEADER IS READ FROM THE TEMPORARY FILE, BEFORE IT IS STORED, so
         * a file that is not a CSV at all is refused without ever landing on
         * the disk. Checking the extension would not do: a .csv is whatever
         * somebody renamed, and the thing that matters is whether there is a
         * row of column names in it.
         */
        $handle = @fopen($temp, 'rb');
        $header = $handle === false ? false : fgetcsv($handle, escape: '');

        if ($handle !== false) {
            fclose($handle);
        }

        if ($header === false || $header === [null]) {
            flash('error', 'That file has no column headings in its first row, so there is '
                . 'nothing to map. Export it again as CSV.');

            return $this->redirect('/imports');
        }

        $header = array_map(static fn ($cell): string => (string) $cell, $header);

        /*
         * IS IT ACTUALLY A CSV? `fgetcsv()` has no concept of "not a CSV" and
         * will read a PDF, so the heading row has to be examined. MEASURED: a
         * six-byte binary file was accepted as an import before this, and sat
         * on the page with one column named by a control character.
         */
        if (($headerProblem = Importer::headerProblem($header)) !== null) {
            flash('error', $headerProblem);

            return $this->redirect('/imports');
        }

        if (count($header) > Importer::MAX_COLUMNS) {
            flash('error', 'That file has ' . count($header) . ' columns, which is more than '
                . 'this importer will read.');

            return $this->redirect('/imports');
        }

        // A name of our own choosing, never the one from the request.
        $stored = 'import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.csv';
        $target = ImportRunner::directory() . '/' . $stored;

        if (!@move_uploaded_file($temp, $target)) {
            flash('error', 'The file could not be saved. Check that storage/imports is writable.');

            return $this->redirect('/imports');
        }

        @chmod($target, 0640);

        $id = DB::insert('imports', [
            'entity'        => $entity,
            'original_name' => Importer::cap((string) ($file['name'] ?? 'upload.csv'), 255),
            'stored_path'   => $stored,
            'bytes'         => $size,
            'checksum'      => hash_file('sha256', $target) ?: null,
            'status'        => 'mapping',
            'headers'       => json_encode($header),
            'mapping'       => json_encode(Importer::guessMapping($header, $entity)),
            'created_by'    => Auth::id(),
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::event('import_uploaded', [
            'import_id' => $id,
            'type'      => $entity,
            'file'      => (string) ($file['name'] ?? ''),
            'bytes'     => $size,
        ], 'imports', $id);

        return $this->redirect('/imports/' . $id);
    }

    /** The mapping screen, the progress screen and the report, in one route. */
    public function show(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        $job = $this->job($request->routeInt('id'));

        if ($job === null) {
            return $this->notFound('That import does not exist.');
        }

        $headers = is_array($decoded = json_decode((string) $job['headers'], true)) ? $decoded : [];
        $mapping = ImportRunner::decodeMapping($job['mapping'] ?? null);
        $options = is_array($o = json_decode((string) ($job['options'] ?? '{}'), true)) ? $o : [];

        $outcome = (string) $request->query('show', 'refused');

        if (!in_array($outcome, ['refused', 'skipped', 'created', 'updated', 'all'], true)) {
            $outcome = 'refused';
        }

        $page = max(1, (int) $request->query('page', '1'));

        return $this->view('imports/show', [
            'pageTitle' => 'Import · ' . (string) $job['original_name'],
            'job'       => $job,
            'headers'   => array_map(static fn ($h): string => (string) $h, $headers),
            'mapping'   => $mapping,
            'options'   => $options,
            'fields'    => Importer::fields((string) $job['entity']),
            'problems'  => Importer::mappingProblems($mapping, (string) $job['entity']),
            'summary'   => Importer::summary([
                'created'    => (int) $job['created'],
                'updated'    => (int) $job['updated'],
                'skipped'    => (int) $job['skipped'],
                'refused'    => (int) $job['refused'],
                'total_rows' => $job['total_rows'] === null ? null : (int) $job['total_rows'],
            ], (string) $job['status'], (string) $job['status'] === 'dry_run'
                || (string) $job['status'] === 'dry_run_done'),
            'reasons'   => $this->reasonCounts((int) $job['id']),
            'rows'      => $this->reportRows((int) $job['id'], $outcome, $page),
            'rowTotal'  => $this->reportCount((int) $job['id'], $outcome),
            'reportCapped' => $this->reportCount((int) $job['id'], 'all') >= Importer::MAX_REPORTED,
            'outcome'   => $outcome,
            'page'      => $page,
            'perPage'   => self::PER_PAGE,
            'duplicateModes' => Importer::ON_DUPLICATE,
            'visitSources'   => array_keys(VisitRecorder::SOURCES),
        ]);
    }

    /** Save the mapping and the options, then start either pass. */
    public function start(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        $job = $this->job($request->routeInt('id'));

        if ($job === null) {
            return $this->notFound('That import does not exist.');
        }

        $id     = (int) $job['id'];
        $entity = (string) $job['entity'];
        $commit = $request->input('commit') !== null;

        if (in_array((string) $job['status'], ['dry_run', 'importing'], true)) {
            flash('error', 'That import is already running.');

            return $this->redirect('/imports/' . $id);
        }

        if ($commit && (string) $job['status'] === 'done') {
            flash('error', 'That file has already been imported.');

            return $this->redirect('/imports/' . $id);
        }

        /*
         * A COMMIT REQUIRES A DRY RUN FIRST, and this is not a formality.
         *
         * The dry run is the only thing between a mis-mapped column and a
         * guest database where every town is a postcode. It costs one cron
         * tick, and refusing to skip it is the single most useful guardrail in
         * this module.
         */
        if ($commit && (string) $job['status'] !== 'dry_run_done') {
            flash('error', 'Run the check first. It reads the whole file without writing '
                . 'anything, so you can see exactly what would happen.');

            return $this->redirect('/imports/' . $id);
        }

        $headers = is_array($decoded = json_decode((string) $job['headers'], true)) ? $decoded : [];
        $mapping = [];

        /** @var array<int|string,mixed> $submitted */
        $submitted = $request->input('map');
        $submitted = is_array($submitted) ? $submitted : [];
        $fields    = Importer::fields($entity);

        foreach ($submitted as $index => $field) {
            $index = (int) $index;
            $field = (string) $field;

            if ($field === '' || !isset($fields[$field]) || !array_key_exists($index, $headers)) {
                continue;
            }

            $mapping[$index] = $field;
        }

        $problems = Importer::mappingProblems($mapping, $entity);
        $blockers = Importer::blockers($problems);

        if ($blockers !== []) {
            // Only the blockers stop it. The advice is drawn on the page and
            // is often exactly what the venue wants.
            flash('error', $blockers[0]);

            return $this->redirect('/imports/' . $id);
        }

        $order = (string) $request->input('date_order', '');
        $mode  = (string) $request->input('on_duplicate', 'fill');
        $source = (string) $request->input('visit_source', 'pos_import');

        $options = [
            'date_order'      => in_array($order, ['mdy', 'dmy'], true) ? $order : '',
            'on_duplicate'    => isset(Importer::ON_DUPLICATE[$mode]) ? $mode : 'fill',
            'consent_claimed' => $request->input('consent_claimed') !== null,
            'visit_source'    => isset(VisitRecorder::SOURCES[$source]) ? $source : 'pos_import',
        ];

        /*
         * The counters and the report are reset for each pass. A dry run's
         * numbers are not the import's, and leaving them would make the
         * progress on the commit start at "4,812 added" before it had written
         * anything.
         */
        DB::delete('import_rows', 'import_id = :i', ['i' => $id]);

        DB::update('imports', [
            'status'      => $commit ? 'importing' : 'dry_run',
            'mapping'     => json_encode($mapping),
            'options'     => json_encode($options),
            'cursor'      => 0,
            'created'     => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'refused'     => 0,
            'total_rows'  => null,
            'error'       => null,
            'started_at'  => gmdate('Y-m-d H:i:s'),
            'finished_at' => null,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        Audit::event($commit ? 'import_started' : 'import_checked', [
            'import_id'       => $id,
            'type'            => $entity,
            // What consent was claimed is the single most important thing in
            // this audit row: it is the answer to "who said these people
            // agreed to be emailed".
            'consent_claimed' => $options['consent_claimed'],
            'on_duplicate'    => $options['on_duplicate'],
            'columns'         => count($mapping),
        ], 'imports', $id);

        /*
         * ONE SLICE NOW, so a small file is finished by the time the page
         * redraws and the operator never wonders whether cron is running. A
         * big one carries on from cron, which is the only way a 50,000-row
         * file can work on shared hosting.
         */
        try {
            ImportRunner::work($id);
        } catch (Throwable $e) {
            /*
             * RECORDED ON THE JOB, not swallowed.
             *
             * This catch said nothing at first, and that hid a real bug for a
             * whole test run: `work()` called a DB method that does not exist
             * in this application, the exception came here, the page redrew
             * perfectly, and the job sat in `dry_run` for ever with zero rows
             * and no error anywhere — a progress screen reporting that a file
             * with 65 guests in it contained nothing importable.
             *
             * `work()` records a failure it CATCHES, but it cannot record one
             * that escapes it. So this does.
             */
            DB::update('imports', [
                'status'      => 'failed',
                'error'       => Importer::cap('Stopped straight away: ' . $e->getMessage(), 500),
                'finished_at' => gmdate('Y-m-d H:i:s'),
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);

            Log::error('Import failed on its first slice', [
                'import' => $id,
                'error'  => $e->getMessage(),
            ]);
        }

        return $this->redirect('/imports/' . $id);
    }

    /** Stop a running job where it is. */
    public function cancel(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        $job = $this->job($request->routeInt('id'));

        if ($job === null) {
            return $this->notFound('That import does not exist.');
        }

        DB::update('imports', [
            'status'      => 'cancelled',
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $job['id']]);

        Audit::event('import_cancelled', [
            'import_id' => (int) $job['id'],
            'created'   => (int) $job['created'],
            'updated'   => (int) $job['updated'],
        ], 'imports', (int) $job['id']);

        /*
         * WHAT WAS WRITTEN STAYS WRITTEN, and the page says so. Rolling back
         * would mean deleting contacts that may already have been edited, and
         * guessing which of them. The report names every row that landed,
         * which is what somebody needs to finish the job by hand.
         */
        flash('success', 'Stopped. Anything already imported is still here — the report below '
            . 'lists every row that landed.');

        return $this->redirect('/imports/' . (int) $job['id']);
    }

    /** Delete the job, its report and the uploaded file. */
    public function destroy(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        $job = $this->job($request->routeInt('id'));

        if ($job === null) {
            return $this->notFound('That import does not exist.');
        }

        if (in_array((string) $job['status'], ['dry_run', 'importing'], true)) {
            flash('error', 'Stop it first, then delete it.');

            return $this->redirect('/imports/' . (int) $job['id']);
        }

        @unlink(ImportRunner::pathFor((string) $job['stored_path']));

        DB::delete('imports', 'id = :id', ['id' => (int) $job['id']]);

        Audit::event('import_deleted', [
            'import_id' => (int) $job['id'],
            'file'      => (string) $job['original_name'],
        ], 'imports', (int) $job['id']);

        flash('success', 'Deleted, along with the uploaded file. Nothing that was imported '
            . 'has been removed.');

        return $this->redirect('/imports');
    }

    /**
     * The report as a CSV.
     *
     * **THE WHOLE POINT OF THE MODULE'S REPORTING.** A refusal list on screen
     * is something to read; a refusal list as a CSV is something to fix and
     * re-upload. It carries the line number first so it can be sorted against
     * the original file.
     */
    public function export(Request $request): Response
    {
        if (($denied = $this->authorize('backup_manage')) !== null) {
            return $denied;
        }

        $job = $this->job($request->routeInt('id'));

        if ($job === null) {
            return $this->notFound('That import does not exist.');
        }

        $outcome = (string) $request->query('show', 'refused');

        if (!in_array($outcome, ['refused', 'skipped', 'created', 'updated', 'all'], true)) {
            $outcome = 'refused';
        }

        Audit::event('import_report_exported', [
            'import_id' => (int) $job['id'],
            'outcome'   => $outcome,
        ], 'imports', (int) $job['id']);

        $where  = $outcome === 'all' ? '' : ' AND outcome = :o';
        $params = ['i' => (int) $job['id']] + ($outcome === 'all' ? [] : ['o' => $outcome]);

        $rows = DB::all(
            'SELECT line, outcome, reason, label, message
               FROM {import_rows}
              WHERE import_id = :i' . $where . '
              ORDER BY line',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                (string) $row['line'],
                (string) $row['outcome'],
                (string) ($row['reason'] ?? ''),
                (string) ($row['label'] ?? ''),
                (string) ($row['message'] ?? ''),
            ];
        }

        return Response::csv(
            'import-' . (int) $job['id'] . '-' . $outcome . '.csv',
            // "Row in your file" rather than "line": it is the number a
            // spreadsheet shows when the operator opens the file to fix it,
            // which is the only reason this column exists.
            ['Row in your file', 'What happened', 'Reason', 'Who', 'Detail'],
            $out
        );
    }

    // ------------------------------------------------------------- internals

    /** @return array<string,mixed>|null */
    private function job(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first(
            'SELECT i.*, u.name AS created_by_name
               FROM {imports} i
               LEFT JOIN {users} u ON u.id = i.created_by
              WHERE i.id = :id',
            ['id' => $id]
        );
    }

    /**
     * How many rows each reason accounts for.
     *
     * A grouped count rather than a list, because the first question about 188
     * refusals is "is it one problem or a hundred" — and it is almost always
     * one.
     *
     * @return list<array<string,mixed>>
     */
    private function reasonCounts(int $importId): array
    {
        return DB::all(
            "SELECT outcome, reason, COUNT(*) AS rows_count,
                    MIN(line) AS first_line, MAX(message) AS example
               FROM {import_rows}
              WHERE import_id = :i AND outcome IN ('refused','skipped')
              GROUP BY outcome, reason
              ORDER BY rows_count DESC
              LIMIT 25",
            ['i' => $importId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function reportRows(int $importId, string $outcome, int $page): array
    {
        $where  = $outcome === 'all' ? '' : ' AND outcome = :o';
        $params = ['i' => $importId] + ($outcome === 'all' ? [] : ['o' => $outcome]);

        return DB::all(
            'SELECT * FROM {import_rows}
              WHERE import_id = :i' . $where . '
              ORDER BY line
              LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
    }

    private function reportCount(int $importId, string $outcome): int
    {
        $where  = $outcome === 'all' ? '' : ' AND outcome = :o';
        $params = ['i' => $importId] + ($outcome === 'all' ? [] : ['o' => $outcome]);

        return (int) DB::value(
            'SELECT COUNT(*) FROM {import_rows} WHERE import_id = :i' . $where,
            $params
        );
    }

    /**
     * What PHP's own upload limit is, in bytes.
     *
     * Reported on the page because it is usually SMALLER than this module's
     * limit and it is set by the host, not by us — so an operator whose 12MB
     * file silently becomes "no file was uploaded" needs to be told the real
     * number rather than ours.
     */
    private function uploadLimit(): int
    {
        $limits = array_filter([
            bytes_from_ini((string) ini_get('upload_max_filesize')),
            bytes_from_ini((string) ini_get('post_max_size')),
        ]);

        return $limits === [] ? Importer::MAX_BYTES : (int) min($limits);
    }

    /** PHP's upload error, in words a venue can act on. */
    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'That file is bigger than this server accepts ('
                    . bytes_human($this->uploadLimit()) . '). Split it and import the parts.',
            UPLOAD_ERR_PARTIAL   => 'The upload was cut off part way. Try again.',
            UPLOAD_ERR_NO_FILE   => 'Choose a file first.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'This server could not store the upload. Its temporary directory is not writable.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension on this server blocked the upload.',
            default              => 'That upload could not be read.',
        };
    }
}
