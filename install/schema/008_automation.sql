-- Castle CRM — 008 playbooks, automations, dashboards, reporting rollups
--
-- The automation engine's tables. The guardrails that matter are the
-- idempotency key on automation_runs (one run per contact per automation,
-- which is what stops a restarted cron double-messaging a family) and the
-- (status, next_run_at) index the scheduler claims work with.

CREATE TABLE IF NOT EXISTS `{prefix}playbooks` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `number`         TINYINT UNSIGNED NOT NULL,
    `name`           VARCHAR(120) NOT NULL,
    `slug`           VARCHAR(64)  NOT NULL,
    `tagline`        VARCHAR(255) DEFAULT NULL,
    `description`    TEXT         DEFAULT NULL,
    `revenue_moment` VARCHAR(255) DEFAULT NULL,
    -- The two halves of the pitch: what ships ready, and what the venue
    -- supplies. Kept as content so the UI can state it honestly.
    `arrives_ready`  TEXT         DEFAULT NULL,
    `you_tailor`     TEXT         DEFAULT NULL,
    `icon`           VARCHAR(40)  DEFAULT NULL,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 0,
    `activated_at`   DATETIME     DEFAULT NULL,
    `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
    `created_at`     DATETIME     DEFAULT NULL,
    `updated_at`     DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_playbooks_slug` (`slug`),
    UNIQUE KEY `uq_playbooks_number` (`number`),
    KEY `ix_playbooks_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}templates`
    ADD CONSTRAINT `{prefix}fk_templates_playbook`
    FOREIGN KEY (`playbook_id`) REFERENCES `{prefix}playbooks` (`id`) ON DELETE SET NULL;

-- What a playbook brings with it, so the UI can show "3 forms, 22 automations,
-- 2 pipelines" without a hard-coded manifest.
CREATE TABLE IF NOT EXISTS `{prefix}playbook_assets` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `playbook_id` BIGINT UNSIGNED NOT NULL,
    `asset_type`  ENUM('pipeline','form','template','automation','segment',
                       'report','widget','survey') NOT NULL,
    `asset_id`    BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_playbook_assets` (`playbook_id`, `asset_type`, `asset_id`),
    CONSTRAINT `{prefix}fk_playbook_assets_playbook`
        FOREIGN KEY (`playbook_id`) REFERENCES `{prefix}playbooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}automations` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `playbook_id`           BIGINT UNSIGNED DEFAULT NULL,
    `name`                  VARCHAR(160) NOT NULL,
    `slug`                  VARCHAR(80)  NOT NULL,
    `description`           TEXT         DEFAULT NULL,
    -- One of Event::CATALOG, or a time/cron/manual trigger.
    `trigger_type`          VARCHAR(64)  NOT NULL,
    `trigger_config`        JSON         DEFAULT NULL,
    -- Entry filter, evaluated once at enrolment.
    `conditions`            JSON         DEFAULT NULL,
    -- Every seeded automation ships INACTIVE. Activation is always a
    -- deliberate human act.
    `is_active`             TINYINT(1)   NOT NULL DEFAULT 0,
    `run_once_per_contact`  TINYINT(1)   NOT NULL DEFAULT 1,
    `allow_reentry`         TINYINT(1)   NOT NULL DEFAULT 0,
    `reentry_cooldown_hours` SMALLINT    DEFAULT NULL,
    `quiet_hours_respect`   TINYINT(1)   NOT NULL DEFAULT 1,
    -- The conversion this automation is trying to cause, which gives every
    -- automation a measurable rate rather than just a send count.
    `goal_event`            VARCHAR(64)  DEFAULT NULL,
    `goal_window_hours`     SMALLINT     DEFAULT NULL,
    -- Checked before EVERY step, so a guest who books mid-sequence stops
    -- receiving "still thinking about it?" messages immediately.
    `exit_conditions`       JSON         DEFAULT NULL,
    `priority`              SMALLINT     NOT NULL DEFAULT 100,
    `run_count`             INT          NOT NULL DEFAULT 0,
    `goal_count`            INT          NOT NULL DEFAULT 0,
    `last_run_at`           DATETIME     DEFAULT NULL,
    `created_by`            BIGINT UNSIGNED DEFAULT NULL,
    `created_at`            DATETIME     DEFAULT NULL,
    `updated_at`            DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_automations_slug` (`slug`),
    -- The dispatcher's lookup: which active automations listen to this event?
    KEY `ix_automations_trigger` (`trigger_type`, `is_active`),
    KEY `ix_automations_playbook` (`playbook_id`),
    CONSTRAINT `{prefix}fk_automations_playbook`
        FOREIGN KEY (`playbook_id`) REFERENCES `{prefix}playbooks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}forms`
    ADD CONSTRAINT `{prefix}fk_forms_automation`
    FOREIGN KEY (`automation_id`) REFERENCES `{prefix}automations` (`id`) ON DELETE SET NULL;

-- Steps form a tree: parent_step_id plus branch gives if/else without a
-- separate edges table.
CREATE TABLE IF NOT EXISTS `{prefix}automation_steps` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `automation_id`     BIGINT UNSIGNED NOT NULL,
    `parent_step_id`    BIGINT UNSIGNED DEFAULT NULL,
    `branch`            ENUM('main','yes','no') NOT NULL DEFAULT 'main',
    `sort_order`        SMALLINT     NOT NULL DEFAULT 0,
    `type`              VARCHAR(40)  NOT NULL,
    `config`            JSON         DEFAULT NULL,
    `delay_minutes`     INT          NOT NULL DEFAULT 0,
    -- 'relative' (delay_minutes), 'time_of_day', or 'before_date'/'after_date'
    -- relative to a field such as a booking's event_date.
    `delay_until_type`  VARCHAR(24)  DEFAULT NULL,
    `delay_until_config` JSON        DEFAULT NULL,
    `is_enabled`        TINYINT(1)   NOT NULL DEFAULT 1,
    -- Per-step counters for the builder's stats column.
    `entered_count`     INT          NOT NULL DEFAULT 0,
    `completed_count`   INT          NOT NULL DEFAULT 0,
    `created_at`        DATETIME     DEFAULT NULL,
    `updated_at`        DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_automation_steps_automation` (`automation_id`, `parent_step_id`, `sort_order`),
    CONSTRAINT `{prefix}fk_automation_steps_automation`
        FOREIGN KEY (`automation_id`) REFERENCES `{prefix}automations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_automation_steps_parent`
        FOREIGN KEY (`parent_step_id`) REFERENCES `{prefix}automation_steps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One contact's journey through one automation.
CREATE TABLE IF NOT EXISTS `{prefix}automation_runs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `automation_id`   BIGINT UNSIGNED NOT NULL,
    `contact_id`      BIGINT UNSIGNED DEFAULT NULL,
    `entity_type`     VARCHAR(40)  DEFAULT NULL,
    `entity_id`       BIGINT UNSIGNED DEFAULT NULL,
    `status`          ENUM('running','waiting','completed','exited','failed','cancelled')
                          NOT NULL DEFAULT 'running',
    `current_step_id` BIGINT UNSIGNED DEFAULT NULL,
    `next_run_at`     DATETIME     DEFAULT NULL,
    `context`         JSON         DEFAULT NULL,
    `goal_reached_at` DATETIME     DEFAULT NULL,
    `attempts`        SMALLINT     NOT NULL DEFAULT 0,
    `started_at`      DATETIME     NOT NULL,
    `finished_at`     DATETIME     DEFAULT NULL,
    `error`           TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- The scheduler's claim query: waiting runs whose time has come.
    KEY `ix_automation_runs_due` (`status`, `next_run_at`),
    KEY `ix_automation_runs_lookup` (`automation_id`, `contact_id`, `status`),
    KEY `ix_automation_runs_entity` (`entity_type`, `entity_id`),
    KEY `ix_automation_runs_contact` (`contact_id`),
    CONSTRAINT `{prefix}fk_automation_runs_automation`
        FOREIGN KEY (`automation_id`) REFERENCES `{prefix}automations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_automation_runs_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}messages`
    ADD CONSTRAINT `{prefix}fk_messages_automation_run`
    FOREIGN KEY (`automation_run_id`) REFERENCES `{prefix}automation_runs` (`id`) ON DELETE SET NULL;

ALTER TABLE `{prefix}tasks`
    ADD CONSTRAINT `{prefix}fk_tasks_automation_run`
    FOREIGN KEY (`automation_run_id`) REFERENCES `{prefix}automation_runs` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `{prefix}automation_step_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id`      BIGINT UNSIGNED NOT NULL,
    `step_id`     BIGINT UNSIGNED DEFAULT NULL,
    `status`      ENUM('ok','skipped','failed') NOT NULL DEFAULT 'ok',
    `output`      JSON     DEFAULT NULL,
    `error`       TEXT     DEFAULT NULL,
    `executed_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_automation_step_log_run` (`run_id`, `executed_at`),
    CONSTRAINT `{prefix}fk_automation_step_log_run`
        FOREIGN KEY (`run_id`) REFERENCES `{prefix}automation_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Dashboards and reporting
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `{prefix}dashboards` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(120) NOT NULL,
    `role_id`    BIGINT UNSIGNED DEFAULT NULL,
    `user_id`    BIGINT UNSIGNED DEFAULT NULL,
    `layout`     JSON        DEFAULT NULL,
    `is_default` TINYINT(1)  NOT NULL DEFAULT 0,
    `created_at` DATETIME    DEFAULT NULL,
    `updated_at` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_dashboards_role` (`role_id`),
    KEY `ix_dashboards_user` (`user_id`),
    CONSTRAINT `{prefix}fk_dashboards_role`
        FOREIGN KEY (`role_id`) REFERENCES `{prefix}roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_dashboards_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}dashboard_widgets` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dashboard_id` BIGINT UNSIGNED NOT NULL,
    `type`         VARCHAR(40)  NOT NULL,
    `title`        VARCHAR(120) DEFAULT NULL,
    `config`       JSON         DEFAULT NULL,
    `position`     JSON         DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_dashboard_widgets_dashboard` (`dashboard_id`),
    CONSTRAINT `{prefix}fk_dashboard_widgets_dashboard`
        FOREIGN KEY (`dashboard_id`) REFERENCES `{prefix}dashboards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}saved_reports` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120) NOT NULL,
    `type`          VARCHAR(40)  NOT NULL,
    `filters`       JSON         DEFAULT NULL,
    `columns`       JSON         DEFAULT NULL,
    `group_by`      VARCHAR(64)  DEFAULT NULL,
    `chart_type`    VARCHAR(24)  DEFAULT NULL,
    `schedule_cron` VARCHAR(64)  DEFAULT NULL,
    `recipients`    JSON         DEFAULT NULL,
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `last_run_at`   DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     DEFAULT NULL,
    `updated_at`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_saved_reports_schedule` (`schedule_cron`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nightly materialised aggregates. The dashboard reads these rather than
-- scanning the transaction tables, which is what keeps it fast on a shared
-- host as the data grows.
CREATE TABLE IF NOT EXISTS `{prefix}report_daily_rollup` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `date`            DATE         NOT NULL,
    `metric`          VARCHAR(64)  NOT NULL,
    `dimension`       VARCHAR(64)  NOT NULL DEFAULT 'total',
    `dimension_value` VARCHAR(191) NOT NULL DEFAULT 'all',
    `value_numeric`   DECIMAL(18,4) NOT NULL DEFAULT 0,
    `value_cents`     BIGINT       NOT NULL DEFAULT 0,
    `updated_at`      DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- Recomputing a day is an UPSERT against this key, so a re-run is safe.
    UNIQUE KEY `uq_report_daily_rollup` (`date`, `metric`, `dimension`, `dimension_value`),
    KEY `ix_report_rollup_metric` (`metric`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}goals` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `metric`       VARCHAR(64) NOT NULL,
    `period`       ENUM('day','week','month','quarter','year') NOT NULL DEFAULT 'month',
    `target_value` DECIMAL(18,4) DEFAULT NULL,
    `target_cents` BIGINT      DEFAULT NULL,
    `starts_on`    DATE        NOT NULL,
    `ends_on`      DATE        NOT NULL,
    `owner_id`     BIGINT UNSIGNED DEFAULT NULL,
    `team_id`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME    DEFAULT NULL,
    `updated_at`   DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_goals_period` (`metric`, `starts_on`, `ends_on`),
    CONSTRAINT `{prefix}fk_goals_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_goals_team`
        FOREIGN KEY (`team_id`) REFERENCES `{prefix}teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
