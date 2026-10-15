-- Castle CRM — 003 pipelines, deals, quotes
--
-- This is the part of the venue's revenue work that exists nowhere today:
-- CenterEdge records the party once it is booked and paid, but the inquiry,
-- the quote, the follow-up and the reason a lead was lost have no home.

CREATE TABLE IF NOT EXISTS `{prefix}pipelines` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                   VARCHAR(80) NOT NULL,
    `slug`                   VARCHAR(64) NOT NULL,
    `type`                   ENUM('birthday','group','corporate','event_center',
                                  'membership','fundraising','custom') NOT NULL DEFAULT 'custom',
    `is_active`              TINYINT(1)  NOT NULL DEFAULT 1,
    `sort_order`             SMALLINT    NOT NULL DEFAULT 0,
    -- How a new deal gets an owner: manual, round_robin, load_balanced, by_source.
    `default_owner_strategy` VARCHAR(24) NOT NULL DEFAULT 'round_robin',
    `created_at`             DATETIME    DEFAULT NULL,
    `updated_at`             DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pipelines_slug` (`slug`),
    KEY `ix_pipelines_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}pipeline_stages` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pipeline_id` BIGINT UNSIGNED NOT NULL,
    `name`        VARCHAR(80) NOT NULL,
    `sort_order`  SMALLINT    NOT NULL DEFAULT 0,
    -- Weighting for the forecast.
    `probability` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- The response deadline. Entering this stage sets deals.sla_due_at; passing
    -- it fires deal.sla_breached, which the accountability playbook escalates.
    `sla_hours`   SMALLINT    DEFAULT NULL,
    `is_won`      TINYINT(1)  NOT NULL DEFAULT 0,
    `is_lost`     TINYINT(1)  NOT NULL DEFAULT 0,
    `color`       VARCHAR(7)  NOT NULL DEFAULT '#6b4ea8',
    `created_at`  DATETIME    DEFAULT NULL,
    `updated_at`  DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_pipeline_stages_pipeline` (`pipeline_id`, `sort_order`),
    CONSTRAINT `{prefix}fk_pipeline_stages_pipeline`
        FOREIGN KEY (`pipeline_id`) REFERENCES `{prefix}pipelines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}lost_reasons` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label`      VARCHAR(120) NOT NULL,
    `sort_order` SMALLINT     NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}deals` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pipeline_id`         BIGINT UNSIGNED NOT NULL,
    `stage_id`            BIGINT UNSIGNED NOT NULL,
    `title`               VARCHAR(191) NOT NULL,
    `contact_id`          BIGINT UNSIGNED DEFAULT NULL,
    `company_id`          BIGINT UNSIGNED DEFAULT NULL,
    `owner_id`            BIGINT UNSIGNED DEFAULT NULL,
    `value_cents`         BIGINT      NOT NULL DEFAULT 0,
    `probability`         TINYINT UNSIGNED DEFAULT NULL,
    `expected_close_date` DATE        DEFAULT NULL,
    -- The event itself, which is usually far later than the close date: a
    -- school books in March for June.
    `event_date`          DATE        DEFAULT NULL,
    `guest_count`         SMALLINT UNSIGNED DEFAULT NULL,
    `source`              VARCHAR(40) DEFAULT NULL,
    `source_detail`       VARCHAR(191) DEFAULT NULL,
    `utm`                 JSON        DEFAULT NULL,
    `status`              ENUM('open','won','lost','abandoned') NOT NULL DEFAULT 'open',
    `lost_reason_id`      BIGINT UNSIGNED DEFAULT NULL,
    `stage_entered_at`    DATETIME    DEFAULT NULL,
    `sla_due_at`          DATETIME    DEFAULT NULL,
    `last_activity_at`    DATETIME    DEFAULT NULL,
    `next_action`         VARCHAR(255) DEFAULT NULL,
    `next_action_at`      DATETIME    DEFAULT NULL,
    `won_at`              DATETIME    DEFAULT NULL,
    `lost_at`             DATETIME    DEFAULT NULL,
    `created_at`          DATETIME    DEFAULT NULL,
    `updated_at`          DATETIME    DEFAULT NULL,
    `deleted_at`          DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_deals_board` (`pipeline_id`, `stage_id`, `deleted_at`),
    KEY `ix_deals_owner` (`owner_id`, `status`),
    KEY `ix_deals_sla` (`sla_due_at`, `status`),
    KEY `ix_deals_event_date` (`event_date`),
    KEY `ix_deals_contact` (`contact_id`),
    KEY `ix_deals_company` (`company_id`),
    -- Drives the stale-deal report and the idle trigger.
    KEY `ix_deals_idle` (`status`, `last_activity_at`),
    CONSTRAINT `{prefix}fk_deals_pipeline`
        FOREIGN KEY (`pipeline_id`) REFERENCES `{prefix}pipelines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_deals_stage`
        FOREIGN KEY (`stage_id`) REFERENCES `{prefix}pipeline_stages` (`id`),
    CONSTRAINT `{prefix}fk_deals_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_deals_company`
        FOREIGN KEY (`company_id`) REFERENCES `{prefix}companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_deals_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_deals_lost_reason`
        FOREIGN KEY (`lost_reason_id`) REFERENCES `{prefix}lost_reasons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every stage move, with how long the deal sat in the previous stage. This is
-- what makes the velocity report possible rather than guessed.
CREATE TABLE IF NOT EXISTS `{prefix}deal_stage_history` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `deal_id`          BIGINT UNSIGNED NOT NULL,
    `from_stage_id`    BIGINT UNSIGNED DEFAULT NULL,
    `to_stage_id`      BIGINT UNSIGNED NOT NULL,
    `user_id`          BIGINT UNSIGNED DEFAULT NULL,
    `duration_seconds` INT         DEFAULT NULL,
    `created_at`       DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_deal_stage_history_deal` (`deal_id`, `created_at`),
    KEY `ix_deal_stage_history_stage` (`to_stage_id`),
    CONSTRAINT `{prefix}fk_deal_stage_history_deal`
        FOREIGN KEY (`deal_id`) REFERENCES `{prefix}deals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}quotes` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `deal_id`        BIGINT UNSIGNED DEFAULT NULL,
    `number`         VARCHAR(32) NOT NULL,
    `status`         ENUM('draft','sent','viewed','accepted','declined','expired')
                         NOT NULL DEFAULT 'draft',
    `subtotal_cents` BIGINT      NOT NULL DEFAULT 0,
    `discount_cents` BIGINT      NOT NULL DEFAULT 0,
    `tax_cents`      BIGINT      NOT NULL DEFAULT 0,
    `total_cents`    BIGINT      NOT NULL DEFAULT 0,
    `deposit_cents`  BIGINT      NOT NULL DEFAULT 0,
    `valid_until`    DATE        DEFAULT NULL,
    `sent_at`        DATETIME    DEFAULT NULL,
    `viewed_at`      DATETIME    DEFAULT NULL,
    `accepted_at`    DATETIME    DEFAULT NULL,
    -- A high-entropy token so a family can open their quote without an
    -- account. Not guessable, and it carries no authority beyond this quote.
    `public_token`   VARCHAR(64) DEFAULT NULL,
    `terms`          TEXT        DEFAULT NULL,
    `notes`          TEXT        DEFAULT NULL,
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME    DEFAULT NULL,
    `updated_at`     DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_quotes_number` (`number`),
    UNIQUE KEY `uq_quotes_token` (`public_token`),
    KEY `ix_quotes_deal` (`deal_id`),
    KEY `ix_quotes_status` (`status`),
    CONSTRAINT `{prefix}fk_quotes_deal`
        FOREIGN KEY (`deal_id`) REFERENCES `{prefix}deals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}quote_lines` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quote_id`    BIGINT UNSIGNED NOT NULL,
    `item_type`   VARCHAR(32)  DEFAULT NULL,
    `item_id`     BIGINT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) NOT NULL,
    `qty`         DECIMAL(10,2) NOT NULL DEFAULT 1,
    `unit_cents`  BIGINT       NOT NULL DEFAULT 0,
    `total_cents` BIGINT       NOT NULL DEFAULT 0,
    `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_quote_lines_quote` (`quote_id`, `sort_order`),
    CONSTRAINT `{prefix}fk_quote_lines_quote`
        FOREIGN KEY (`quote_id`) REFERENCES `{prefix}quotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
