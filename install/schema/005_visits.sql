-- Castle CRM — 005 visits, tickets, memberships, waivers
--
-- Admission at the Castle is free and there is no gate scan, so a "visit" is
-- DERIVED (spec §8.3). The source column records which derivation produced the
-- row, and its priority decides which wins when two sources describe the same
-- real visit.

CREATE TABLE IF NOT EXISTS `{prefix}visits` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id`   BIGINT UNSIGNED DEFAULT NULL,
    `household_id` BIGINT UNSIGNED DEFAULT NULL,
    `visit_date`   DATE        NOT NULL,
    -- Priority order, highest first: pos_import (the CenterEdge card ledger),
    -- pos_event (a party/group event payment), booking, online_ticket,
    -- check_in, manual. A lower-priority source never overwrites a higher one.
    `source`       ENUM('pos_import','pos_event','booking','online_ticket','check_in','manual')
                       NOT NULL DEFAULT 'manual',
    `spend_cents`  BIGINT      NOT NULL DEFAULT 0,
    -- What they played, as read from the card ledger's game plays.
    `attractions`  JSON        DEFAULT NULL,
    `party_size`   SMALLINT UNSIGNED DEFAULT NULL,
    `booking_id`   BIGINT UNSIGNED DEFAULT NULL,
    -- The CenterEdge card number this visit was derived from.
    `external_ref` VARCHAR(64) DEFAULT NULL,
    `created_at`   DATETIME    NOT NULL,
    `updated_at`   DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- One visit per contact per day: the dedup key described in spec §8.3.
    UNIQUE KEY `uq_visits_contact_date` (`contact_id`, `visit_date`),
    KEY `ix_visits_date` (`visit_date`),
    KEY `ix_visits_household` (`household_id`, `visit_date`),
    KEY `ix_visits_external` (`external_ref`),
    KEY `ix_visits_booking` (`booking_id`),
    CONSTRAINT `{prefix}fk_visits_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_visits_household`
        FOREIGN KEY (`household_id`) REFERENCES `{prefix}households` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_visits_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}tickets` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id`   BIGINT UNSIGNED DEFAULT NULL,
    `order_ref`    VARCHAR(80) DEFAULT NULL,
    `visit_date`   DATE        DEFAULT NULL,
    `qty`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `total_cents`  BIGINT      NOT NULL DEFAULT 0,
    `status`       ENUM('pending','paid','redeemed','refunded','abandoned')
                       NOT NULL DEFAULT 'pending',
    `channel`      VARCHAR(40) DEFAULT NULL,
    `utm`          JSON        DEFAULT NULL,
    `purchased_at` DATETIME    DEFAULT NULL,
    `redeemed_at`  DATETIME    DEFAULT NULL,
    `created_at`   DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_tickets_contact` (`contact_id`),
    KEY `ix_tickets_status` (`status`, `visit_date`),
    KEY `ix_tickets_order` (`order_ref`),
    CONSTRAINT `{prefix}fk_tickets_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}membership_plans` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(120) NOT NULL,
    `slug`              VARCHAR(64)  NOT NULL,
    `type`              ENUM('season_pass','membership','multi_visit') NOT NULL DEFAULT 'season_pass',
    `price_cents`       BIGINT      NOT NULL DEFAULT 0,
    `billing_period`    ENUM('one_time','monthly','annual') NOT NULL DEFAULT 'one_time',
    `duration_days`     SMALLINT UNSIGNED DEFAULT NULL,
    `visit_allowance`   SMALLINT UNSIGNED DEFAULT NULL,
    `benefits`          JSON        DEFAULT NULL,
    `attraction_access` JSON        DEFAULT NULL,
    `is_family_plan`    TINYINT(1)  NOT NULL DEFAULT 0,
    `max_members`       TINYINT UNSIGNED DEFAULT NULL,
    `is_active`         TINYINT(1)  NOT NULL DEFAULT 1,
    `sort_order`        SMALLINT    NOT NULL DEFAULT 0,
    `created_at`        DATETIME    DEFAULT NULL,
    `updated_at`        DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_membership_plans_slug` (`slug`),
    KEY `ix_membership_plans_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The lifecycle state machine from spec §8.10. Castle CRM owns these states;
-- CenterEdge owns the physical card and its privileges.
CREATE TABLE IF NOT EXISTS `{prefix}memberships` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id`             BIGINT UNSIGNED NOT NULL,
    `contact_id`          BIGINT UNSIGNED DEFAULT NULL,
    `household_id`        BIGINT UNSIGNED DEFAULT NULL,
    `member_number`       VARCHAR(32) NOT NULL,
    `status`              ENUM('pending','active','expiring','expired','cancelled','lapsed','frozen')
                              NOT NULL DEFAULT 'pending',
    `started_at`          DATE        DEFAULT NULL,
    `expires_at`          DATE        DEFAULT NULL,
    `cancelled_at`        DATETIME    DEFAULT NULL,
    `auto_renew`          TINYINT(1)  NOT NULL DEFAULT 0,
    `price_paid_cents`    BIGINT      NOT NULL DEFAULT 0,
    `visits_used`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_used_at`        DATETIME    DEFAULT NULL,
    -- Stamped when each renewal notice goes out, so a restarted job cannot
    -- send the same reminder twice.
    `renewal_notice_30_at` DATETIME   DEFAULT NULL,
    `renewal_notice_14_at` DATETIME   DEFAULT NULL,
    `renewal_notice_3_at`  DATETIME   DEFAULT NULL,
    `winback_sent_at`     DATETIME    DEFAULT NULL,
    -- The CenterEdge card this pass lives on.
    `external_ref`        VARCHAR(64) DEFAULT NULL,
    `created_at`          DATETIME    DEFAULT NULL,
    `updated_at`          DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_memberships_number` (`member_number`),
    -- The renewal and lapse sweeps query exactly this pair.
    KEY `ix_memberships_status_expiry` (`status`, `expires_at`),
    KEY `ix_memberships_contact` (`contact_id`),
    KEY `ix_memberships_household` (`household_id`),
    KEY `ix_memberships_idle` (`status`, `last_used_at`),
    CONSTRAINT `{prefix}fk_memberships_plan`
        FOREIGN KEY (`plan_id`) REFERENCES `{prefix}membership_plans` (`id`),
    CONSTRAINT `{prefix}fk_memberships_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_memberships_household`
        FOREIGN KEY (`household_id`) REFERENCES `{prefix}households` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}membership_members` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `contact_id`    BIGINT UNSIGNED DEFAULT NULL,
    `child_id`      BIGINT UNSIGNED DEFAULT NULL,
    `relationship`  VARCHAR(40) DEFAULT NULL,
    `is_primary`    TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_membership_members_membership` (`membership_id`),
    KEY `ix_membership_members_contact` (`contact_id`),
    CONSTRAINT `{prefix}fk_membership_members_membership`
        FOREIGN KEY (`membership_id`) REFERENCES `{prefix}memberships` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_membership_members_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_membership_members_child`
        FOREIGN KEY (`child_id`) REFERENCES `{prefix}children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usage, so "your pass has not been used in five weeks" is a fact rather than
-- a guess, and so pass utilisation vs break-even can be reported.
CREATE TABLE IF NOT EXISTS `{prefix}membership_usage` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `contact_id`    BIGINT UNSIGNED DEFAULT NULL,
    `visit_id`      BIGINT UNSIGNED DEFAULT NULL,
    `attraction_id` BIGINT UNSIGNED DEFAULT NULL,
    `used_at`       DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_membership_usage_membership` (`membership_id`, `used_at`),
    CONSTRAINT `{prefix}fk_membership_usage_membership`
        FOREIGN KEY (`membership_id`) REFERENCES `{prefix}memberships` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_membership_usage_visit`
        FOREIGN KEY (`visit_id`) REFERENCES `{prefix}visits` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}membership_payments` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `membership_id` BIGINT UNSIGNED NOT NULL,
    `amount_cents`  BIGINT      NOT NULL,
    `status`        ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'paid',
    `method`        VARCHAR(32) DEFAULT NULL,
    `reference`     VARCHAR(80) DEFAULT NULL,
    `occurred_at`   DATETIME    NOT NULL,
    `created_at`    DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_membership_payments_membership` (`membership_id`, `occurred_at`),
    CONSTRAINT `{prefix}fk_membership_payments_membership`
        FOREIGN KEY (`membership_id`) REFERENCES `{prefix}memberships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Waiver TEXT is versioned, and a signature records which version was signed,
-- so a signature stays defensible after the wording changes.
CREATE TABLE IF NOT EXISTS `{prefix}waivers` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version`           VARCHAR(20)  NOT NULL,
    `title`             VARCHAR(191) NOT NULL,
    `body`              LONGTEXT     NOT NULL,
    `requires_guardian` TINYINT(1)   NOT NULL DEFAULT 1,
    `is_active`         TINYINT(1)   NOT NULL DEFAULT 0,
    `published_at`      DATETIME     DEFAULT NULL,
    `created_at`        DATETIME     DEFAULT NULL,
    `updated_at`        DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_waivers_version` (`version`),
    KEY `ix_waivers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}waiver_signatures` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `waiver_id`      BIGINT UNSIGNED NOT NULL,
    `contact_id`     BIGINT UNSIGNED DEFAULT NULL,
    `booking_id`     BIGINT UNSIGNED DEFAULT NULL,
    `signer_name`    VARCHAR(120) NOT NULL,
    `signer_email`   VARCHAR(191) DEFAULT NULL,
    `signer_phone`   VARCHAR(32)  DEFAULT NULL,
    `is_guardian`    TINYINT(1)   NOT NULL DEFAULT 0,
    -- The minors this signature covers: [{name, birth_date}].
    `minors`         JSON         DEFAULT NULL,
    `signature_data` LONGTEXT     DEFAULT NULL,
    -- IP, agent and timestamp are part of what makes an e-signature hold up.
    `ip`             VARCHAR(45)  DEFAULT NULL,
    `user_agent`     VARCHAR(500) DEFAULT NULL,
    `signed_at`      DATETIME     NOT NULL,
    `expires_at`     DATE         DEFAULT NULL,
    `public_token`   VARCHAR(64)  DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_waiver_signatures_token` (`public_token`),
    KEY `ix_waiver_signatures_contact` (`contact_id`),
    KEY `ix_waiver_signatures_booking` (`booking_id`),
    KEY `ix_waiver_signatures_waiver` (`waiver_id`),
    CONSTRAINT `{prefix}fk_waiver_signatures_waiver`
        FOREIGN KEY (`waiver_id`) REFERENCES `{prefix}waivers` (`id`),
    CONSTRAINT `{prefix}fk_waiver_signatures_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_waiver_signatures_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}booking_guests`
    ADD CONSTRAINT `{prefix}fk_booking_guests_waiver`
    FOREIGN KEY (`waiver_id`) REFERENCES `{prefix}waiver_signatures` (`id`) ON DELETE SET NULL;
