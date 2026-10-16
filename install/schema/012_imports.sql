-- Castle CRM — 012 CSV import jobs
--
-- A NEW FILE RATHER THAN AN EDIT TO AN EARLIER ONE, for the reason 009-011
-- state: the migration runner records which FILES it has applied, so a change
-- edited into an earlier file would reach a fresh install and nobody who had
-- already installed.

-- ------------------------------------------------------------------------
-- The job
-- ------------------------------------------------------------------------
--
-- WHY A TABLE AND NOT A REQUEST.
--
-- A venue migrating off a previous system arrives with one CSV of every guest
-- they have ever had. Fifty thousand rows is not a web request on shared
-- hosting: `max_execution_time` is commonly 30 seconds, the browser tab will
-- be closed halfway through, and a half-finished import that cannot say where
-- it stopped is worse than no import at all — nobody can tell which rows
-- landed, so the only safe move is to delete everything and start again.
--
-- So an import is a JOB. It is uploaded, mapped, dry-run, and then walked
-- forward a batch at a time by cron, exactly like a campaign send. `cursor`
-- is the byte offset in the file, so a resumed job re-opens and seeks rather
-- than re-reading and re-deciding what it already wrote.

CREATE TABLE IF NOT EXISTS `{prefix}imports` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- What is being imported. One entity per job: a file of contacts and a
    -- file of visits are two uploads, because the mapping, the validation and
    -- the duplicate rules have nothing in common.
    `entity`       ENUM('contacts','visits') NOT NULL,

    `original_name` VARCHAR(255) NOT NULL,
    -- Relative to the storage path, never a path from the request.
    `stored_path`  VARCHAR(255) NOT NULL,
    `bytes`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- SHA-256 of the file. Two uploads of the same file are a mistake worth
    -- naming rather than a second import.
    `checksum`     CHAR(64) DEFAULT NULL,

    /*
     * THE STATES, AND WHY `dry_run` IS ONE OF THEM.
     *
     * An import is irreversible in the way that matters: a venue cannot look
     * at 50,000 contacts afterwards and tell which 300 were wrong. So the
     * default path is upload → map → DRY RUN → read the refusals → commit.
     * The dry run is a real pass over the real file writing nothing, so what
     * it reports is what will happen, not an estimate of it.
     */
    `status`       ENUM('mapping','dry_run','dry_run_done','importing','done','failed','cancelled')
                   NOT NULL DEFAULT 'mapping',

    -- column index => field name, as JSON. The operator's own mapping, so a
    -- re-run of the same file does not have to be mapped again.
    `mapping`      JSON DEFAULT NULL,
    -- Per-job choices: what to do with a duplicate, whether consent is
    -- claimed, which visit source to attribute.
    `options`      JSON DEFAULT NULL,

    -- The header row as read, so the mapping screen can be redrawn without
    -- re-opening the file.
    `headers`      JSON DEFAULT NULL,

    -- Byte offset of the next row to read. 0 = the first data row.
    `cursor`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `total_rows`   INT UNSIGNED DEFAULT NULL,

    `created`      INT UNSIGNED NOT NULL DEFAULT 0,
    `updated`      INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped`      INT UNSIGNED NOT NULL DEFAULT 0,
    `refused`      INT UNSIGNED NOT NULL DEFAULT 0,

    `started_at`   DATETIME DEFAULT NULL,
    `finished_at`  DATETIME DEFAULT NULL,
    `error`        VARCHAR(500) DEFAULT NULL,

    `created_by`   BIGINT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME NOT NULL,
    `updated_at`   DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    KEY `ix_imports_status` (`status`, `id`),
    KEY `ix_imports_created` (`created_at`),
    CONSTRAINT `{prefix}fk_imports_user`
        FOREIGN KEY (`created_by`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- Every row that was not imported, and why
-- ------------------------------------------------------------------------
--
-- A COUNT IS NOT A REPORT. "4,812 of 5,000 imported" tells a venue that 188
-- guests are missing and nothing about which ones, so the only thing they can
-- do with it is worry. This table holds the row NUMBER, the reason in words,
-- and enough of the row to recognise the person — so the refusals download as
-- a CSV the operator can fix and re-upload.
--
-- `skipped` rows are kept too, not only refusals: "this was already here"
-- is the answer to "why is my count lower than my file", and it is the most
-- common one.

CREATE TABLE IF NOT EXISTS `{prefix}import_rows` (
    `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_id` BIGINT UNSIGNED NOT NULL,

    -- 1-based, counting the header as row 1, so it matches what a spreadsheet
    -- shows the operator when they open the file to fix it.
    `line`      INT UNSIGNED NOT NULL,

    `outcome`   ENUM('created','updated','skipped','refused') NOT NULL,
    -- A machine reason (`bad_email`, `duplicate_in_file`, `no_identity`…)
    -- beside the sentence, so the report can group as well as list.
    `reason`    VARCHAR(40) DEFAULT NULL,
    `message`   VARCHAR(300) DEFAULT NULL,

    -- Which contact or visit it became, when it became one.
    `entity_id` BIGINT UNSIGNED DEFAULT NULL,

    -- Enough to recognise the row: the mapped identity fields only, never the
    -- whole line. A 60-column CSV stored verbatim per row would multiply the
    -- database by the size of every file ever imported.
    `label`     VARCHAR(191) DEFAULT NULL,

    `created_at` DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    -- "Show me this job's refusals", which is what the page draws.
    KEY `ix_import_rows_job` (`import_id`, `outcome`, `id`),
    CONSTRAINT `{prefix}fk_import_rows_job`
        FOREIGN KEY (`import_id`) REFERENCES `{prefix}imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
