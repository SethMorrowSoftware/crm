<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use Throwable;

/**
 * CSV import — the I/O half.
 *
 * `Importer` holds the rules; this reads the file, resolves who each row is
 * about, writes, and records what happened. Four properties carry the whole
 * design:
 *
 * **IT RESUMES.** A venue migrating off a previous system arrives with one
 * file of every guest they have ever had. Fifty thousand rows is not a web
 * request on shared hosting — `max_execution_time` is commonly 30 seconds and
 * the browser tab will be closed halfway through — so cron walks the job
 * forward a batch at a time and `cursor` is the byte offset of the next row.
 * A resumed job seeks rather than re-reading, so it can neither double-write
 * nor lose its place.
 *
 * **THE DRY RUN IS THE SAME PASS.** It reads the real file, resolves real
 * duplicates against the real database, and writes nothing. So what it reports
 * is what will happen rather than an estimate of it — which matters because an
 * import is irreversible in the way that counts: nobody can look at 50,000
 * contacts afterwards and tell which 300 were wrong.
 *
 * **EVERY ROW IS ACCOUNTED FOR.** "4,812 of 5,000 imported" tells a venue that
 * 188 guests are missing and nothing about which ones, so the only thing they
 * can do with it is worry. Every row lands in `import_rows` with its line
 * number, its outcome and the reason in words.
 *
 * **IT CANNOT FLOOD THE AUTOMATIONS.** `contact.created` fires for a guest the
 * desk types in, and a welcome sequence is the right response to that. Firing
 * it fifty thousand times during a migration would enrol the venue's entire
 * history into a welcome sequence and then message all of them. So an import
 * fires NOTHING per row; `import.finished` fires once, with the counts.
 */
final class ImportRunner
{
    /**
     * Seconds one `work()` call may spend.
     *
     * Below cron's own budget so a tick that imports still has time for the
     * steps behind it, and well below any shared host's execution limit.
     */
    public const SLICE_SECONDS = 8;

    /** The statuses `work()` will pick up. */
    public const WORKABLE = ['dry_run', 'importing'];

    /**
     * The jobs cron should walk forward, oldest first.
     *
     * @return list<int>
     */
    public static function dueJobs(int $limit = 3): array
    {
        $rows = DB::all(
            "SELECT id FROM {imports}
              WHERE status IN ('dry_run','importing')
              ORDER BY id
              LIMIT " . max(1, $limit)
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Walk one job forward by up to one batch, or until the slice runs out.
     *
     * @return array{rows:int,finished:bool,error:?string}
     */
    public static function work(int $importId, ?callable $budgetLeft = null): array
    {
        $job = DB::first('SELECT * FROM {imports} WHERE id = :id', ['id' => $importId]);

        if ($job === null || !in_array((string) $job['status'], self::WORKABLE, true)) {
            return ['rows' => 0, 'finished' => true, 'error' => null];
        }

        $dryRun = (string) $job['status'] === 'dry_run';
        $path   = self::pathFor((string) $job['stored_path']);

        if (!is_file($path) || !is_readable($path)) {
            self::fail($importId, 'The uploaded file is no longer on the server.');

            return ['rows' => 0, 'finished' => true, 'error' => 'file_missing'];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            self::fail($importId, 'The uploaded file could not be opened.');

            return ['rows' => 0, 'finished' => true, 'error' => 'unreadable'];
        }

        $mapping = self::decodeMapping($job['mapping'] ?? null);
        $options = is_array($decoded = json_decode((string) ($job['options'] ?? '{}'), true))
            ? $decoded
            : [];
        $entity  = (string) $job['entity'];

        if ($mapping === []) {
            fclose($handle);
            self::fail($importId, 'This import has no column mapping, so there is nothing to read.');

            return ['rows' => 0, 'finished' => true, 'error' => 'no_mapping'];
        }

        $cursor = (int) $job['cursor'];

        /*
         * A FRESH JOB SKIPS THE HEADER; A RESUMED ONE DOES NOT.
         *
         * `cursor` 0 means "nothing read yet", so the header is consumed here
         * and the offset recorded. Treating 0 as "seek to 0" instead would
         * import the header row as a guest called "First Name" on every
         * resume, which is the kind of thing that looks like a one-off typo in
         * the data.
         */
        if ($cursor === 0) {
            $header = fgetcsv($handle);

            if ($header === false) {
                fclose($handle);
                self::fail($importId, 'The file is empty.');

                return ['rows' => 0, 'finished' => true, 'error' => 'empty'];
            }

            $cursor = (int) ftell($handle);
            $line   = 1;
        } else {
            fseek($handle, $cursor);
            // The line number is stored with the cursor so the report's line
            // numbers survive a resume; without it every batch would restart
            // at 1 and the report would name the wrong rows.
            $line = (int) ($options['_line'] ?? 1);
        }

        $counts = [
            'created' => (int) $job['created'],
            'updated' => (int) $job['updated'],
            'skipped' => (int) $job['skipped'],
            'refused' => (int) $job['refused'],
        ];

        $deadline = microtime(true) + self::SLICE_SECONDS;
        $reported = (int) DB::value(
            'SELECT COUNT(*) FROM {import_rows} WHERE import_id = :i',
            ['i' => $importId]
        );

        $seenInFile = self::seenKeys($importId);
        $rowsDone   = 0;
        $finished   = false;
        $pending    = [];

        try {
            while ($rowsDone < Importer::BATCH) {
                /*
                 * A SLICE ALWAYS ADVANCES BY AT LEAST ONE ROW.
                 *
                 * The budget is checked AFTER the first row, not before it.
                 * MEASURED: with the check at the top, a call that arrives
                 * with its budget already spent breaks out immediately, does
                 * nothing, and returns `finished: false` — so a cron tick
                 * that was running late made no progress at all, and a job on
                 * a consistently busy minute could starve for ever while
                 * looking like it was being worked on.
                 *
                 * One row of overshoot is nothing; a job that never finishes
                 * is a migration that silently stops half way.
                 */
                if ($rowsDone > 0
                    && (microtime(true) >= $deadline || ($budgetLeft !== null && !$budgetLeft()))
                ) {
                    break;
                }

                $row = fgetcsv($handle);

                if ($row === false) {
                    $finished = true;

                    break;
                }

                $cursor = (int) ftell($handle);
                $line++;
                $rowsDone++;

                // A blank line at the end of a file is not a row.
                if ($row === [null] || (count($row) === 1 && trim((string) ($row[0] ?? '')) === '')) {
                    continue;
                }

                if (count($row) > Importer::MAX_COLUMNS) {
                    $counts['refused']++;
                    $pending[] = self::reportRow($line, 'refused', 'too_wide',
                        'That row has ' . count($row) . ' columns, which is more than this '
                        . 'importer will read.', '', null);

                    continue;
                }

                $outcome = self::handleRow($entity, $row, $mapping, $options, $dryRun, $seenInFile);

                $counts[$outcome['outcome'] === 'created' ? 'created'
                    : ($outcome['outcome'] === 'updated' ? 'updated'
                    : ($outcome['outcome'] === 'skipped' ? 'skipped' : 'refused'))]++;

                /*
                 * The report STOPS before the file does. A mapping that is
                 * wrong refuses every row, and storing 50,000 identical
                 * refusals is a report nobody reads and a table nobody wanted.
                 * The counts above stay exact, and the page says which it is
                 * showing.
                 */
                if ($reported + count($pending) < Importer::MAX_REPORTED) {
                    $pending[] = self::reportRow(
                        $line,
                        $outcome['outcome'],
                        $outcome['reason'],
                        $outcome['message'],
                        $outcome['label'],
                        $outcome['entity_id']
                    );
                }
            }
        } catch (Throwable $e) {
            fclose($handle);
            self::saveReport($importId, $pending);
            self::fail($importId, 'Stopped on an unexpected error: ' . $e->getMessage());
            Log::error('Import failed mid-file', ['import' => $importId, 'error' => $e->getMessage()]);

            return ['rows' => $rowsDone, 'finished' => true, 'error' => $e->getMessage()];
        }

        fclose($handle);
        self::saveReport($importId, $pending);

        $options['_line'] = $line;

        $status = $finished
            ? ($dryRun ? 'dry_run_done' : 'done')
            : (string) $job['status'];

        DB::update('imports', [
            'cursor'      => $cursor,
            'created'     => $counts['created'],
            'updated'     => $counts['updated'],
            'skipped'     => $counts['skipped'],
            'refused'     => $counts['refused'],
            'options'     => json_encode($options),
            'status'      => $status,
            'total_rows'  => $finished ? ($counts['created'] + $counts['updated']
                + $counts['skipped'] + $counts['refused']) : $job['total_rows'],
            'finished_at' => $finished ? gmdate('Y-m-d H:i:s') : null,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $importId]);

        if ($finished && !$dryRun) {
            /*
             * ONE EVENT FOR THE WHOLE JOB, never one per row.
             *
             * `contact.created` firing fifty thousand times would enrol the
             * venue's entire history into every welcome sequence they have
             * switched on, and then message all of them. A migration is not
             * fifty thousand people walking in.
             */
            Event::fire('import.finished', [
                'import_id' => $importId,
                'type'      => $entity,
                'source'    => 'csv',
            ]);
        }

        return ['rows' => $rowsDone, 'finished' => $finished, 'error' => null];
    }

    /**
     * Decide and apply one row.
     *
     * @param list<string> $row
     * @param array<int,string> $mapping
     * @param array<string,mixed> $options
     * @param array<string,true> $seenInFile Identity keys already used by this file.
     * @return array{outcome:string,reason:?string,message:string,label:string,entity_id:?int}
     */
    private static function handleRow(
        string $entity,
        array $row,
        array $mapping,
        array $options,
        bool $dryRun,
        array &$seenInFile
    ): array {
        $values = Importer::readRow($row, $mapping);

        return $entity === 'visits'
            ? self::handleVisit($values, $options, $dryRun, $seenInFile)
            : self::handleContact($values, $options, $dryRun, $seenInFile);
    }

    /**
     * @param array<string,string> $values
     * @param array<string,mixed> $options
     * @param array<string,true> $seenInFile
     * @return array{outcome:string,reason:?string,message:string,label:string,entity_id:?int}
     */
    private static function handleContact(
        array $values,
        array $options,
        bool $dryRun,
        array &$seenInFile
    ): array {
        $read = Importer::contactRow($values, [
            'consent_claimed' => (bool) ($options['consent_claimed'] ?? false),
            'date_order'      => (string) ($options['date_order'] ?? ''),
        ]);

        if (!$read['ok']) {
            return [
                'outcome'   => 'refused',
                'reason'    => (string) $read['reason'],
                'message'   => (string) $read['message'],
                'label'     => $read['label'],
                'entity_id' => null,
            ];
        }

        $columns = $read['columns'];
        $warning = $read['warnings'] === [] ? '' : implode(' ', $read['warnings']);

        /*
         * A DUPLICATE WITHIN THE FILE ITSELF, which is a different thing from
         * one already in the database and deserves its own words. A list
         * exported twice and concatenated has every guest twice; without this
         * the second copy would "update" the first and the operator would see
         * 5,000 added and 5,000 updated from a file of 10,000 rows and no idea
         * why.
         */
        foreach (array_filter([$read['identity']['email'], $read['identity']['phone']]) as $key) {
            if (isset($seenInFile[(string) $key])) {
                return [
                    'outcome'   => 'skipped',
                    'reason'    => 'duplicate_in_file',
                    'message'   => 'The same person appears earlier in this file.',
                    'label'     => $read['label'],
                    'entity_id' => null,
                ];
            }
        }

        foreach (array_filter([$read['identity']['email'], $read['identity']['phone']]) as $key) {
            $seenInFile[(string) $key] = true;
        }

        $existing = self::findContact($read['identity']['email'], $read['identity']['phone']);
        $mode     = (string) ($options['on_duplicate'] ?? 'fill');

        if ($existing !== null) {
            $changes = Importer::mergeContact($columns, $existing, $mode);

            if ($changes === []) {
                return [
                    'outcome'   => 'skipped',
                    'reason'    => $mode === 'skip' ? 'already_here' : 'nothing_to_change',
                    'message'   => trim(($mode === 'skip'
                        ? 'Already here, and left alone.'
                        : 'Already here, with nothing in the file to add.') . ' ' . $warning),
                    'label'     => $read['label'],
                    'entity_id' => (int) $existing['id'],
                ];
            }

            if (!$dryRun) {
                $changes['updated_at'] = gmdate('Y-m-d H:i:s');
                DB::update('contacts', $changes, 'id = :id', ['id' => (int) $existing['id']]);
            }

            unset($changes['updated_at']);

            return [
                'outcome'   => 'updated',
                'reason'    => null,
                'message'   => trim('Already here. ' . self::describeChanges($changes) . ' ' . $warning),
                'label'     => $read['label'],
                'entity_id' => (int) $existing['id'],
            ];
        }

        if ($dryRun) {
            return [
                'outcome'   => 'created',
                'reason'    => null,
                'message'   => $warning,
                'label'     => $read['label'],
                'entity_id' => null,
            ];
        }

        $columns['country']    = 'US';
        $columns['source']     = 'import';
        $columns['created_at'] = gmdate('Y-m-d H:i:s');
        $columns['updated_at'] = gmdate('Y-m-d H:i:s');

        if (!isset($columns['lifecycle_stage'])) {
            // `lead` rather than `customer`: the file does not say they have
            // ever bought anything, and a stage is what segments and
            // automations key off.
            $columns['lifecycle_stage'] = 'lead';
        }

        $id = DB::insert('contacts', $columns);

        return [
            'outcome'   => 'created',
            'reason'    => null,
            'message'   => $warning,
            'label'     => $read['label'],
            'entity_id' => (int) $id,
        ];
    }

    /**
     * @param array<string,string> $values
     * @param array<string,mixed> $options
     * @param array<string,true> $seenInFile
     * @return array{outcome:string,reason:?string,message:string,label:string,entity_id:?int}
     */
    private static function handleVisit(
        array $values,
        array $options,
        bool $dryRun,
        array &$seenInFile
    ): array {
        $read = Importer::visitRow($values, [
            'date_order' => (string) ($options['date_order'] ?? ''),
            'source'     => (string) ($options['visit_source'] ?? 'pos_import'),
        ]);

        if (!$read['ok']) {
            return [
                'outcome'   => 'refused',
                'reason'    => (string) $read['reason'],
                'message'   => (string) $read['message'],
                'label'     => $read['label'],
                'entity_id' => null,
            ];
        }

        $contactId = self::findContactId(
            $read['identity']['email'],
            $read['identity']['phone'],
            $read['identity']['external_pos_id']
        );

        if ($contactId === null) {
            /*
             * REFUSED, NOT CREATED, and this is the most important decision in
             * the visit importer.
             *
             * Creating the contact here would turn a POS export into tens of
             * thousands of nameless records whose only field is a card number
             * — and a venue cannot tell afterwards which of their contacts are
             * real people and which are import residue. The operator imports
             * the guests first; then this file matches.
             */
            return [
                'outcome'   => 'refused',
                'reason'    => 'no_such_contact',
                'message'   => 'Nobody here matches this guest. Import your contacts first, '
                    . 'then this file will find them.',
                'label'     => $read['label'],
                'entity_id' => null,
            ];
        }

        $visit = $read['visit'] + ['contact_id' => $contactId];
        $key   = $contactId . '@' . $visit['visit_date'];

        if (isset($seenInFile[$key])) {
            return [
                'outcome'   => 'skipped',
                'reason'    => 'duplicate_in_file',
                'message'   => 'This guest already has a row for ' . $visit['visit_date']
                    . ' earlier in this file. A visit is one day, however many '
                    . 'transactions it contained.',
                'label'     => $read['label'],
                'entity_id' => null,
            ];
        }

        $seenInFile[$key] = true;

        if ($dryRun) {
            $already = DB::first(
                'SELECT id, source FROM {visits} WHERE contact_id = :c AND visit_date = :d',
                ['c' => $contactId, 'd' => $visit['visit_date']]
            );

            return [
                'outcome'   => $already === null ? 'created' : 'updated',
                'reason'    => null,
                'message'   => $already === null
                    ? trim(implode(' ', $read['warnings']))
                    : trim('A visit on that day is already recorded, from ' . $already['source']
                        . '. ' . implode(' ', $read['warnings'])),
                'label'     => $read['label'],
                'entity_id' => $already === null ? null : (int) $already['id'],
            ];
        }

        /*
         * THROUGH `VisitRecorder`, never a direct insert. It is the one place
         * that knows a visit is one per contact per day, that a lower-priority
         * source never overwrites a higher one, and that the contact's own
         * figures are DERIVED rather than incremented — so re-importing the
         * same file changes nothing rather than tripling somebody's lifetime
         * value.
         */
        $result = VisitRecorder::record($visit);

        if ($result['visit_id'] === null) {
            return [
                'outcome'   => 'refused',
                'reason'    => (string) ($result['skipped'] ?? 'not_recorded'),
                'message'   => 'The visit could not be recorded (' . (string) ($result['skipped'] ?? '?') . ').',
                'label'     => $read['label'],
                'entity_id' => null,
            ];
        }

        return [
            'outcome'   => $result['recorded'] ? 'created' : 'updated',
            'reason'    => null,
            'message'   => trim(($result['recorded'] ? '' : 'Folded into the visit already recorded for that day.')
                . ' ' . implode(' ', $read['warnings'])),
            'label'     => $read['label'],
            'entity_id' => (int) $result['visit_id'],
        ];
    }

    /**
     * The contact this identity belongs to, if any.
     *
     * **EMAIL FIRST, THEN PHONE.** An email address is one person; a phone
     * number is a household — two parents and a teenager share it. So matching
     * on the phone first would fold a family onto whichever of them was
     * imported first, and that is not something an import can undo.
     *
     * @return array<string,mixed>|null
     */
    private static function findContact(?string $email, ?string $phone): ?array
    {
        if ($email !== null && $email !== '') {
            $found = DB::first(
                'SELECT * FROM {contacts} WHERE email = :e AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['e' => $email]
            );

            if ($found !== null) {
                return $found;
            }
        }

        if ($phone !== null && $phone !== '') {
            return DB::first(
                'SELECT * FROM {contacts}
                  WHERE phone_normalized = :p AND deleted_at IS NULL
                  ORDER BY id LIMIT 1',
                ['p' => $phone]
            );
        }

        return null;
    }

    /** The same, plus the old system's card number, for a visit row. */
    private static function findContactId(?string $email, ?string $phone, ?string $card): ?int
    {
        $found = self::findContact($email, $phone);

        if ($found !== null) {
            return (int) $found['id'];
        }

        if ($card !== null && $card !== '') {
            $byCard = DB::first(
                'SELECT id FROM {contacts}
                  WHERE external_pos_id = :c AND deleted_at IS NULL
                  ORDER BY id LIMIT 1',
                ['c' => $card]
            );

            if ($byCard !== null) {
                return (int) $byCard['id'];
            }
        }

        return null;
    }

    /**
     * The identity labels this job has already used.
     *
     * **RE-READ AT THE START OF EVERY SLICE**, because a 50,000-row file is
     * imported across many cron ticks and an in-memory set does not survive
     * between them. Without this, a duplicate that straddles a batch boundary
     * would be missed — and a venue would see a handful of double-imports with
     * nothing in common except how far through the file they happened to be.
     *
     * Bounded by what the report holds, which is also what makes it safe on a
     * 128M shared host: the alternative is every identity in a 200,000-row
     * file in memory at once. Past that bound the within-file duplicate check
     * stops being exact, and the page says the report is capped rather than
     * hiding it.
     *
     * @return array<string,true>
     */
    private static function seenKeys(int $importId): array
    {
        $labels = DB::column(
            "SELECT label FROM {import_rows}
              WHERE import_id = :i AND outcome <> 'refused' AND label IS NOT NULL
              ORDER BY id
              LIMIT " . Importer::MAX_REPORTED,
            ['i' => $importId]
        );

        $keys = [];

        foreach ($labels as $label) {
            $label = trim((string) $label);

            if ($label !== '') {
                $keys[$label] = true;
            }
        }

        return $keys;
    }

    /**
     * What an update changed, for the report.
     *
     * NAMES THE COLUMNS, not the values: the report is read by whoever ran the
     * import, and "email, phone" answers "what did this do to my data" where a
     * list of values answers nothing and is long.
     *
     * @param array<string,mixed> $changes
     */
    private static function describeChanges(array $changes): string
    {
        unset($changes['phone_normalized']);

        $columns = array_keys($changes);

        if ($columns === []) {
            return '';
        }

        $labels = [];

        foreach ($columns as $column) {
            $labels[] = Importer::CONTACT_FIELDS[$column]['label']
                ?? str_replace('_', ' ', (string) $column);
        }

        sort($labels);

        return 'Filled in: ' . implode(', ', array_slice($labels, 0, 8))
            . (count($labels) > 8 ? ' and ' . (count($labels) - 8) . ' more' : '') . '.';
    }

    /**
     * @return array<string,mixed>
     */
    private static function reportRow(
        int $line,
        string $outcome,
        ?string $reason,
        string $message,
        string $label,
        ?int $entityId
    ): array {
        return [
            'line'      => $line,
            'outcome'   => $outcome,
            'reason'    => $reason,
            'message'   => $message === '' ? null : Importer::cap($message, 300),
            'label'     => $label === '' ? null : Importer::cap($label, 191),
            'entity_id' => $entityId,
        ];
    }

    /**
     * Write the batch's report rows.
     *
     * ONE STATEMENT for the batch rather than one per row: 500 inserts a tick
     * is 500 round trips, and the report is the least interesting thing in the
     * job to spend them on.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function saveReport(int $importId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now   = gmdate('Y-m-d H:i:s');
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = [
                'import_id' => $importId,
                'line'      => $row['line'],
                'outcome'   => $row['outcome'],
                'reason'    => $row['reason'],
                'message'   => $row['message'],
                'entity_id' => $row['entity_id'],
                'label'     => $row['label'],
                'created_at' => $now,
            ];
        }

        DB::insertMany('import_rows', $batch);
    }

    /** Stop the job and say why, on the job itself. */
    private static function fail(int $importId, string $message): void
    {
        DB::update('imports', [
            'status'      => 'failed',
            'error'       => Importer::cap($message, 500),
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $importId]);
    }

    /**
     * @param mixed $stored
     * @return array<int,string>
     */
    public static function decodeMapping(mixed $stored): array
    {
        $decoded = is_array($stored) ? $stored : json_decode((string) $stored, true);

        if (!is_array($decoded)) {
            return [];
        }

        $mapping = [];

        foreach ($decoded as $index => $field) {
            if (is_string($field) && $field !== '' && is_numeric($index)) {
                $mapping[(int) $index] = $field;
            }
        }

        return $mapping;
    }

    /**
     * The absolute path of an uploaded file.
     *
     * **BUILT FROM THE BASENAME ONLY.** `stored_path` comes out of the
     * database, which is one SQL injection or one careless admin edit away
     * from `../../config/config.php` — and this function's answer is handed
     * straight to `fopen()`. Stripping to a basename means the worst a bad
     * value can do is name a file that does not exist in the import
     * directory.
     */
    public static function pathFor(string $stored): string
    {
        return self::directory() . '/' . basename($stored);
    }

    /** Where uploads live: outside the web root, under the storage path. */
    public static function directory(): string
    {
        $path = (string) Config::get('storage_path', CC_ROOT . '/storage') . '/imports';

        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }
}
