-- Castle CRM — 004 attractions, packages, bookings, resources
--
-- Seasonality is modelled as data, not a comment: a quote must not offer
-- go-karts in February, and the availability engine answers that from
-- attractions.season_* plus the operating calendar.

CREATE TABLE IF NOT EXISTS `{prefix}attractions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(120) NOT NULL,
    `slug`              VARCHAR(64)  NOT NULL,
    `category`          VARCHAR(40)  DEFAULT NULL,
    `is_indoor`         TINYINT(1)   NOT NULL DEFAULT 1,
    `is_seasonal`       TINYINT(1)   NOT NULL DEFAULT 0,
    -- MM-DD, so a season survives a year rollover without annual maintenance.
    `season_start_md`   CHAR(5)      DEFAULT NULL,
    `season_end_md`     CHAR(5)      DEFAULT NULL,
    `min_age`           TINYINT UNSIGNED DEFAULT NULL,
    `min_height_in`     TINYINT UNSIGNED DEFAULT NULL,
    `max_height_in`     TINYINT UNSIGNED DEFAULT NULL,
    `capacity_per_slot` SMALLINT UNSIGNED DEFAULT NULL,
    `slot_minutes`      SMALLINT UNSIGNED DEFAULT NULL,
    `price_cents`       BIGINT       NOT NULL DEFAULT 0,
    `is_party_eligible` TINYINT(1)   NOT NULL DEFAULT 1,
    -- Can a party be built AROUND this attraction, as opposed to merely
    -- including it? Bowling can; the arcade cannot.
    `is_party_main`     TINYINT(1)   NOT NULL DEFAULT 0,
    `requires_waiver`   TINYINT(1)   NOT NULL DEFAULT 0,
    `description`       TEXT         DEFAULT NULL,
    `image_path`        VARCHAR(255) DEFAULT NULL,
    `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order`        SMALLINT     NOT NULL DEFAULT 0,
    `created_at`        DATETIME     DEFAULT NULL,
    `updated_at`        DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attractions_slug` (`slug`),
    KEY `ix_attractions_active` (`is_active`, `sort_order`),
    KEY `ix_attractions_party` (`is_party_main`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}children`
    ADD CONSTRAINT `{prefix}fk_children_attraction`
    FOREIGN KEY (`favourite_attraction_id`) REFERENCES `{prefix}attractions` (`id`) ON DELETE SET NULL;

-- The venue's hours vary by season, so a row is scoped by an effective window
-- rather than being a single weekly grid.
CREATE TABLE IF NOT EXISTS `{prefix}operating_hours` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- 0 = Sunday, matching PHP's `w` format.
    `day_of_week`    TINYINT UNSIGNED NOT NULL,
    `opens_at`       TIME        NOT NULL,
    `closes_at`      TIME        NOT NULL,
    `season_label`   VARCHAR(40) DEFAULT NULL,
    `effective_from` DATE        DEFAULT NULL,
    `effective_to`   DATE        DEFAULT NULL,
    `created_at`     DATETIME    DEFAULT NULL,
    `updated_at`     DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_operating_hours_day` (`day_of_week`, `effective_from`, `effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-off closures and special hours. Overrides operating_hours for that date.
CREATE TABLE IF NOT EXISTS `{prefix}operating_exceptions` (
    `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `date`      DATE        NOT NULL,
    `is_closed` TINYINT(1)  NOT NULL DEFAULT 0,
    `opens_at`  TIME        DEFAULT NULL,
    `closes_at` TIME        DEFAULT NULL,
    `note`      VARCHAR(191) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_operating_exceptions_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-attraction availability overrides: a ride down for maintenance, or an
-- outdoor attraction opened early for an unseasonably warm weekend.
CREATE TABLE IF NOT EXISTS `{prefix}attraction_availability` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attraction_id` BIGINT UNSIGNED NOT NULL,
    `date`          DATE        NOT NULL,
    `is_available`  TINYINT(1)  NOT NULL DEFAULT 1,
    `note`          VARCHAR(191) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attraction_availability` (`attraction_id`, `date`),
    CONSTRAINT `{prefix}fk_attraction_availability_attraction`
        FOREIGN KEY (`attraction_id`) REFERENCES `{prefix}attractions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The physical things a booking consumes. Double-booking a party room is the
-- failure this table exists to prevent.
CREATE TABLE IF NOT EXISTS `{prefix}resources` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(80) NOT NULL,
    `type`          ENUM('party_room','bowling_lane','kart','table','event_space',
                         'skate_rink','laser_arena','other') NOT NULL DEFAULT 'other',
    `capacity`      SMALLINT UNSIGNED DEFAULT NULL,
    `attraction_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
    `sort_order`    SMALLINT    NOT NULL DEFAULT 0,
    `created_at`    DATETIME    DEFAULT NULL,
    `updated_at`    DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_resources_type` (`type`, `is_active`),
    CONSTRAINT `{prefix}fk_resources_attraction`
        FOREIGN KEY (`attraction_id`) REFERENCES `{prefix}attractions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}party_packages` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(120) NOT NULL,
    `slug`                  VARCHAR(64)  NOT NULL,
    `main_attraction_id`    BIGINT UNSIGNED DEFAULT NULL,
    `base_price_cents`      BIGINT      NOT NULL DEFAULT 0,
    `included_guests`       SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    `additional_guest_cents` BIGINT     NOT NULL DEFAULT 0,
    `duration_minutes`      SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    -- The Castle's online booking rule: inside this window the family must
    -- call. The booking form turns this into a "please call" fallback rather
    -- than a dead end.
    `min_notice_days`       SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    `is_seasonal`           TINYINT(1)  NOT NULL DEFAULT 0,
    `deposit_cents`         BIGINT      NOT NULL DEFAULT 0,
    `deposit_percent`       DECIMAL(5,2) DEFAULT NULL,
    `includes`              JSON        DEFAULT NULL,
    `description`           TEXT        DEFAULT NULL,
    `is_active`             TINYINT(1)  NOT NULL DEFAULT 1,
    `sort_order`            SMALLINT    NOT NULL DEFAULT 0,
    `created_at`            DATETIME    DEFAULT NULL,
    `updated_at`            DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_party_packages_slug` (`slug`),
    KEY `ix_party_packages_active` (`is_active`, `sort_order`),
    CONSTRAINT `{prefix}fk_party_packages_attraction`
        FOREIGN KEY (`main_attraction_id`) REFERENCES `{prefix}attractions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}package_addons` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120) NOT NULL,
    `price_cents`   BIGINT      NOT NULL DEFAULT 0,
    -- How the price multiplies, which the quote calculator reads directly.
    `unit`          ENUM('flat','per_guest','per_hour') NOT NULL DEFAULT 'flat',
    `attraction_id` BIGINT UNSIGNED DEFAULT NULL,
    `description`   VARCHAR(500) DEFAULT NULL,
    `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
    `sort_order`    SMALLINT    NOT NULL DEFAULT 0,
    `created_at`    DATETIME    DEFAULT NULL,
    `updated_at`    DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_package_addons_active` (`is_active`, `sort_order`),
    CONSTRAINT `{prefix}fk_package_addons_attraction`
        FOREIGN KEY (`attraction_id`) REFERENCES `{prefix}attractions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A booking in Castle CRM MIRRORS the CenterEdge reservation once one exists
-- (external_pos_id links them). Before that it is the venue's only record of
-- an intended party, which is exactly the gap this application closes.
CREATE TABLE IF NOT EXISTS `{prefix}bookings` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_number`      VARCHAR(32) NOT NULL,
    `deal_id`             BIGINT UNSIGNED DEFAULT NULL,
    `contact_id`          BIGINT UNSIGNED DEFAULT NULL,
    `company_id`          BIGINT UNSIGNED DEFAULT NULL,
    `type`                ENUM('birthday','group','corporate','field_trip','camp',
                               'event_center','fundraiser','other') NOT NULL DEFAULT 'birthday',
    `package_id`          BIGINT UNSIGNED DEFAULT NULL,
    `status`              ENUM('inquiry','held','confirmed','deposit_paid','paid',
                               'completed','cancelled','no_show') NOT NULL DEFAULT 'inquiry',
    `event_date`          DATE        NOT NULL,
    `start_time`          TIME        DEFAULT NULL,
    `end_time`            TIME        DEFAULT NULL,
    `guest_count`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `adult_count`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `child_count`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `guest_of_honor_name` VARCHAR(120) DEFAULT NULL,
    `guest_of_honor_age`  TINYINT UNSIGNED DEFAULT NULL,

    `subtotal_cents`      BIGINT      NOT NULL DEFAULT 0,
    `discount_cents`      BIGINT      NOT NULL DEFAULT 0,
    `tax_cents`           BIGINT      NOT NULL DEFAULT 0,
    `total_cents`         BIGINT      NOT NULL DEFAULT 0,
    `deposit_cents`       BIGINT      NOT NULL DEFAULT 0,
    `deposit_paid_at`     DATETIME    DEFAULT NULL,
    `balance_due_cents`   BIGINT      NOT NULL DEFAULT 0,
    `paid_in_full_at`     DATETIME    DEFAULT NULL,
    -- An opaque reference only. Castle CRM stores NO card data: no PAN, no
    -- CVV, no expiry. Payments are taken in the POS or a hosted page.
    `payment_reference`   VARCHAR(80) DEFAULT NULL,

    `host_user_id`        BIGINT UNSIGNED DEFAULT NULL,
    `special_requests`    TEXT        DEFAULT NULL,
    -- Printed in bold on the run sheet. Operationally the most important
    -- free-text field in the application.
    `allergies`           VARCHAR(500) DEFAULT NULL,
    `dietary_notes`       VARCHAR(500) DEFAULT NULL,
    `cake_notes`          VARCHAR(500) DEFAULT NULL,
    `internal_notes`      TEXT        DEFAULT NULL,
    `source`              VARCHAR(40) DEFAULT NULL,

    `confirmed_at`        DATETIME    DEFAULT NULL,
    `reminder_sent_at`    DATETIME    DEFAULT NULL,
    `run_sheet_printed_at` DATETIME   DEFAULT NULL,
    `cancelled_at`        DATETIME    DEFAULT NULL,
    `cancellation_reason` VARCHAR(255) DEFAULT NULL,

    `external_pos_id`     VARCHAR(64) DEFAULT NULL,
    `created_at`          DATETIME    DEFAULT NULL,
    `updated_at`          DATETIME    DEFAULT NULL,
    `deleted_at`          DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bookings_number` (`booking_number`),
    -- The calendar and the "today's parties" widget both drive off this.
    KEY `ix_bookings_date_status` (`event_date`, `status`, `deleted_at`),
    KEY `ix_bookings_contact` (`contact_id`),
    KEY `ix_bookings_company` (`company_id`),
    KEY `ix_bookings_deal` (`deal_id`),
    KEY `ix_bookings_host` (`host_user_id`, `event_date`),
    KEY `ix_bookings_pos` (`external_pos_id`),
    CONSTRAINT `{prefix}fk_bookings_deal`
        FOREIGN KEY (`deal_id`) REFERENCES `{prefix}deals` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_bookings_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_bookings_company`
        FOREIGN KEY (`company_id`) REFERENCES `{prefix}companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_bookings_package`
        FOREIGN KEY (`package_id`) REFERENCES `{prefix}party_packages` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_bookings_host`
        FOREIGN KEY (`host_user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}booking_addons` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`  BIGINT UNSIGNED NOT NULL,
    `addon_id`    BIGINT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(191) DEFAULT NULL,
    `qty`         DECIMAL(10,2) NOT NULL DEFAULT 1,
    `unit_cents`  BIGINT      NOT NULL DEFAULT 0,
    `total_cents` BIGINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_booking_addons_booking` (`booking_id`),
    CONSTRAINT `{prefix}fk_booking_addons_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_booking_addons_addon`
        FOREIGN KEY (`addon_id`) REFERENCES `{prefix}package_addons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The schedule WITHIN a party: 30 minutes of laser tag, then pizza, then cake.
CREATE TABLE IF NOT EXISTS `{prefix}booking_attractions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`    BIGINT UNSIGNED NOT NULL,
    `attraction_id` BIGINT UNSIGNED NOT NULL,
    `start_time`    TIME     DEFAULT NULL,
    `end_time`      TIME     DEFAULT NULL,
    `sort_order`    SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_booking_attractions_booking` (`booking_id`, `sort_order`),
    CONSTRAINT `{prefix}fk_booking_attractions_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_booking_attractions_attraction`
        FOREIGN KEY (`attraction_id`) REFERENCES `{prefix}attractions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resource allocation. The availability engine checks this table for overlaps.
CREATE TABLE IF NOT EXISTS `{prefix}booking_resources` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`  BIGINT UNSIGNED NOT NULL,
    `resource_id` BIGINT UNSIGNED NOT NULL,
    `start_time`  DATETIME NOT NULL,
    `end_time`    DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_booking_resources_booking` (`booking_id`),
    -- The overlap query: resource, then time window.
    KEY `ix_booking_resources_window` (`resource_id`, `start_time`, `end_time`),
    CONSTRAINT `{prefix}fk_booking_resources_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_booking_resources_resource`
        FOREIGN KEY (`resource_id`) REFERENCES `{prefix}resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}booking_guests` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`   BIGINT UNSIGNED NOT NULL,
    `name`         VARCHAR(120) NOT NULL,
    `age`          TINYINT UNSIGNED DEFAULT NULL,
    `parent_name`  VARCHAR(120) DEFAULT NULL,
    `parent_phone` VARCHAR(32)  DEFAULT NULL,
    `waiver_id`    BIGINT UNSIGNED DEFAULT NULL,
    `checked_in_at` DATETIME    DEFAULT NULL,
    `rsvp_status`  ENUM('unknown','yes','no','maybe') NOT NULL DEFAULT 'unknown',
    `created_at`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_booking_guests_booking` (`booking_id`),
    CONSTRAINT `{prefix}fk_booking_guests_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}booking_tasks` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`   BIGINT UNSIGNED NOT NULL,
    `title`        VARCHAR(191) NOT NULL,
    `assigned_to`  BIGINT UNSIGNED DEFAULT NULL,
    `due_at`       DATETIME     DEFAULT NULL,
    `completed_at` DATETIME     DEFAULT NULL,
    `sort_order`   SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `ix_booking_tasks_booking` (`booking_id`, `sort_order`),
    CONSTRAINT `{prefix}fk_booking_tasks_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_booking_tasks_user`
        FOREIGN KEY (`assigned_to`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A payment LEDGER rather than a balance field, so deposits, balances and
-- refunds reconcile against the POS line by line.
CREATE TABLE IF NOT EXISTS `{prefix}booking_payments` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id`   BIGINT UNSIGNED NOT NULL,
    `amount_cents` BIGINT      NOT NULL,
    `type`         ENUM('deposit','balance','refund','adjustment') NOT NULL DEFAULT 'deposit',
    `method`       VARCHAR(32) DEFAULT NULL,
    `reference`    VARCHAR(80) DEFAULT NULL,
    `recorded_by`  BIGINT UNSIGNED DEFAULT NULL,
    `occurred_at`  DATETIME    NOT NULL,
    `note`         VARCHAR(255) DEFAULT NULL,
    `created_at`   DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_booking_payments_booking` (`booking_id`, `occurred_at`),
    CONSTRAINT `{prefix}fk_booking_payments_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_booking_payments_user`
        FOREIGN KEY (`recorded_by`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
