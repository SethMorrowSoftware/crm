-- Castle CRM — 001 core platform
--
-- Conventions used throughout every schema file:
--   * InnoDB / utf8mb4_unicode_ci
--   * id BIGINT UNSIGNED AUTO_INCREMENT primary key
--   * created_at / updated_at on every table; all timestamps stored UTC
--   * deleted_at soft deletes on business entities only
--   * indexed VARCHARs capped at 191 so a UNIQUE index fits the 767-byte
--     limit on older InnoDB row formats as well as the 3072-byte modern one
--   * {prefix} is replaced with the configured table prefix by the installer
--   * reserved words (`key`, `group`, `value`, `action`) are always backticked

-- Roles come before users, because users reference them.
CREATE TABLE IF NOT EXISTS `{prefix}roles` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`        VARCHAR(64)  NOT NULL,
    `name`        VARCHAR(80)  NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    -- A system role cannot be deleted; `owner` additionally cannot be edited,
    -- so there is always a way back into a misconfigured install.
    `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
    `sort_order`  SMALLINT     NOT NULL DEFAULT 100,
    `created_at`  DATETIME     DEFAULT NULL,
    `updated_at`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}permissions` (
    `id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`   VARCHAR(64)  NOT NULL,
    `group`  VARCHAR(40)  NOT NULL DEFAULT 'General',
    `label`  VARCHAR(160) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissions_slug` (`slug`),
    KEY `ix_permissions_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}role_permissions` (
    `role_id`       BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    -- Record-level reach for this grant. 'all' sees everything, 'team' sees
    -- the owner's teams, 'own' sees only their own records.
    `scope`         ENUM('all','team','own') NOT NULL DEFAULT 'all',
    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `ix_role_permissions_permission` (`permission_id`),
    CONSTRAINT `{prefix}fk_role_permissions_role`
        FOREIGN KEY (`role_id`) REFERENCES `{prefix}roles` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_role_permissions_permission`
        FOREIGN KEY (`permission_id`) REFERENCES `{prefix}permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}users` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`               VARCHAR(120) NOT NULL,
    `email`              VARCHAR(191) NOT NULL,
    `password_hash`      VARCHAR(255) NOT NULL,
    `phone`              VARCHAR(32)  DEFAULT NULL,
    `avatar_path`        VARCHAR(255) DEFAULT NULL,
    `role_id`            BIGINT UNSIGNED DEFAULT NULL,
    `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at`      DATETIME     DEFAULT NULL,
    -- Consecutive failures; reset on success. Drives the lockout below.
    `failed_attempts`    SMALLINT     NOT NULL DEFAULT 0,
    `locked_until`       DATETIME     DEFAULT NULL,
    `timezone`           VARCHAR(64)  DEFAULT NULL,
    `notification_prefs` JSON         DEFAULT NULL,
    `mfa_secret`         VARCHAR(255) DEFAULT NULL,
    `created_at`         DATETIME     DEFAULT NULL,
    `updated_at`         DATETIME     DEFAULT NULL,
    `deleted_at`         DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- Unique across live rows only; a deleted user's address is rewritten to
    -- deleted+{id}@castle-crm.invalid so the address can be reused.
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `ix_users_role` (`role_id`),
    KEY `ix_users_active` (`is_active`, `deleted_at`),
    CONSTRAINT `{prefix}fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `{prefix}roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}teams` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(80) NOT NULL,
    `manager_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME    DEFAULT NULL,
    `updated_at` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_teams_manager` (`manager_id`),
    CONSTRAINT `{prefix}fk_teams_manager`
        FOREIGN KEY (`manager_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}team_members` (
    `team_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`team_id`, `user_id`),
    KEY `ix_team_members_user` (`user_id`),
    CONSTRAINT `{prefix}fk_team_members_team`
        FOREIGN KEY (`team_id`) REFERENCES `{prefix}teams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_team_members_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions live in the database rather than PHP's file handler: shared hosts
-- garbage-collect /tmp on their own schedule and sometimes share the directory
-- between accounts, which loses sessions unpredictably and leaks them.
CREATE TABLE IF NOT EXISTS `{prefix}sessions` (
    `id`            CHAR(64)     NOT NULL,
    `user_id`       BIGINT UNSIGNED DEFAULT NULL,
    `payload`       LONGTEXT     DEFAULT NULL,
    `ip`            VARCHAR(45)  DEFAULT NULL,
    `user_agent`    VARCHAR(500) DEFAULT NULL,
    `last_activity` DATETIME     NOT NULL,
    `created_at`    DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_sessions_user` (`user_id`),
    KEY `ix_sessions_activity` (`last_activity`),
    CONSTRAINT `{prefix}fk_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only the token's hash is stored, so a database copy does not yield usable
-- reset links.
CREATE TABLE IF NOT EXISTS `{prefix}password_resets` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(191) NOT NULL,
    `token_hash` CHAR(64)     NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_password_resets_email` (`email`),
    UNIQUE KEY `uq_password_resets_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}settings` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group`        VARCHAR(40)  NOT NULL DEFAULT 'general',
    `key`          VARCHAR(191) NOT NULL,
    `value`        LONGTEXT     DEFAULT NULL,
    `type`         VARCHAR(16)  NOT NULL DEFAULT 'string',
    -- Encrypted values hold provider credentials (AES-256-GCM, app_key).
    `is_encrypted` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`   DATETIME     DEFAULT NULL,
    `updated_at`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`key`),
    KEY `ix_settings_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The audit trail. Append-only by convention: nothing in the application
-- updates or deletes these rows except the retention job in cron.
CREATE TABLE IF NOT EXISTS `{prefix}audit_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED DEFAULT NULL,
    `action`      VARCHAR(64)  NOT NULL,
    `entity_type` VARCHAR(64)  DEFAULT NULL,
    `entity_id`   BIGINT UNSIGNED DEFAULT NULL,
    -- Field-level diff: {"column": {"from": …, "to": …}}, with secrets redacted.
    `changes`     JSON         DEFAULT NULL,
    `ip`          VARCHAR(45)  DEFAULT NULL,
    `user_agent`  VARCHAR(500) DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_audit_user` (`user_id`),
    KEY `ix_audit_action` (`action`),
    KEY `ix_audit_entity` (`entity_type`, `entity_id`),
    KEY `ix_audit_created` (`created_at`),
    -- The actor is kept even if the user row goes: an audit row that loses its
    -- subject is still evidence.
    CONSTRAINT `{prefix}fk_audit_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The job queue. Campaign sends and automation steps are chunked through here
-- so a 20,000-recipient blast never meets max_execution_time.
CREATE TABLE IF NOT EXISTS `{prefix}jobs` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `queue`        VARCHAR(40)  NOT NULL DEFAULT 'default',
    `type`         VARCHAR(80)  NOT NULL,
    `payload`      JSON         DEFAULT NULL,
    `available_at` DATETIME     NOT NULL,
    `attempts`     SMALLINT     NOT NULL DEFAULT 0,
    `reserved_at`  DATETIME     DEFAULT NULL,
    `status`       ENUM('pending','reserved','done','failed') NOT NULL DEFAULT 'pending',
    `last_error`   TEXT         DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL,
    `updated_at`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- The claim query orders by this exact tuple.
    KEY `ix_jobs_claim` (`status`, `available_at`, `id`),
    KEY `ix_jobs_queue` (`queue`, `status`),
    KEY `ix_jobs_reserved` (`reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}job_failures` (
    `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_id`    BIGINT UNSIGNED DEFAULT NULL,
    `queue`     VARCHAR(40) NOT NULL DEFAULT 'default',
    `type`      VARCHAR(80) NOT NULL,
    `payload`   JSON        DEFAULT NULL,
    `error`     LONGTEXT    DEFAULT NULL,
    `failed_at` DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_job_failures_type` (`type`),
    KEY `ix_job_failures_failed` (`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}notifications` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `type`       VARCHAR(64)  NOT NULL,
    `title`      VARCHAR(200) NOT NULL,
    `body`       TEXT         DEFAULT NULL,
    `link`       VARCHAR(255) DEFAULT NULL,
    `read_at`    DATETIME     DEFAULT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_notifications_user_unread` (`user_id`, `read_at`),
    CONSTRAINT `{prefix}fk_notifications_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API tokens: only the hash is stored, same reasoning as password resets.
CREATE TABLE IF NOT EXISTS `{prefix}api_tokens` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `name`         VARCHAR(80) NOT NULL,
    `token_hash`   CHAR(64)    NOT NULL,
    `abilities`    JSON        DEFAULT NULL,
    `last_used_at` DATETIME    DEFAULT NULL,
    `expires_at`   DATETIME    DEFAULT NULL,
    `created_at`   DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
    KEY `ix_api_tokens_user` (`user_id`),
    CONSTRAINT `{prefix}fk_api_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}webhooks` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event`      VARCHAR(64)  NOT NULL,
    `target_url` VARCHAR(500) NOT NULL,
    `secret`     VARCHAR(255) DEFAULT NULL,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL,
    `updated_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_webhooks_event` (`event`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}webhook_deliveries` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webhook_id`    BIGINT UNSIGNED NOT NULL,
    `payload`       JSON        DEFAULT NULL,
    `response_code` SMALLINT    DEFAULT NULL,
    `attempts`      SMALLINT    NOT NULL DEFAULT 0,
    `delivered_at`  DATETIME    DEFAULT NULL,
    `created_at`    DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_webhook_deliveries_webhook` (`webhook_id`),
    CONSTRAINT `{prefix}fk_webhook_deliveries_webhook`
        FOREIGN KEY (`webhook_id`) REFERENCES `{prefix}webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}migrations` (
    `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(191) NOT NULL,
    `batch`    SMALLINT     NOT NULL DEFAULT 1,
    `ran_at`   DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usage metering. Castle CRM is owned software, so this is a budgeting tool,
-- not a billing engine: it exists so the venue can see what a campaign costs
-- BEFORE sending it, at the provider's own rate with no markup.
CREATE TABLE IF NOT EXISTS `{prefix}usage_ledger` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel`         ENUM('email','sms','mms','voice','ai','workflow') NOT NULL,
    `direction`       ENUM('outbound','inbound','internal') NOT NULL DEFAULT 'outbound',
    `units`           INT          NOT NULL DEFAULT 1,
    `unit_cost_cents` DECIMAL(10,4) NOT NULL DEFAULT 0,
    `entity_type`     VARCHAR(64)  DEFAULT NULL,
    `entity_id`       BIGINT UNSIGNED DEFAULT NULL,
    `provider`        VARCHAR(40)  DEFAULT NULL,
    `provider_ref`    VARCHAR(191) DEFAULT NULL,
    `occurred_at`     DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_usage_channel_date` (`channel`, `occurred_at`),
    KEY `ix_usage_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
