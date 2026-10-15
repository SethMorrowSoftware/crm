-- Castle CRM — 007 forms, feedback, reputation, fundraising, coupons, tasks

CREATE TABLE IF NOT EXISTS `{prefix}forms` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                   VARCHAR(120) NOT NULL,
    `slug`                   VARCHAR(64)  NOT NULL,
    `type`                   VARCHAR(40)  DEFAULT NULL,
    `title`                  VARCHAR(191) DEFAULT NULL,
    `description`            TEXT         DEFAULT NULL,
    `submit_label`           VARCHAR(60)  NOT NULL DEFAULT 'Send',
    `success_message`        TEXT         DEFAULT NULL,
    `redirect_url`           VARCHAR(255) DEFAULT NULL,
    -- Where a submission lands in the sales process.
    `pipeline_id`            BIGINT UNSIGNED DEFAULT NULL,
    `stage_id`               BIGINT UNSIGNED DEFAULT NULL,
    `owner_strategy`         VARCHAR(24)  NOT NULL DEFAULT 'round_robin',
    `assign_to`              BIGINT UNSIGNED DEFAULT NULL,
    `notify_user_ids`        JSON         DEFAULT NULL,
    `autoresponder_template_id` BIGINT UNSIGNED DEFAULT NULL,
    `automation_id`          BIGINT UNSIGNED DEFAULT NULL,
    -- A field real users never fill but bots do; a filled honeypot is spam.
    `honeypot_field`         VARCHAR(40)  NOT NULL DEFAULT 'website_url',
    `requires_captcha`       TINYINT(1)   NOT NULL DEFAULT 0,
    `is_active`              TINYINT(1)   NOT NULL DEFAULT 1,
    `submission_count`       INT          NOT NULL DEFAULT 0,
    `embed_theme`            JSON         DEFAULT NULL,
    `created_by`             BIGINT UNSIGNED DEFAULT NULL,
    `created_at`             DATETIME     DEFAULT NULL,
    `updated_at`             DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_forms_slug` (`slug`),
    KEY `ix_forms_active` (`is_active`),
    CONSTRAINT `{prefix}fk_forms_pipeline`
        FOREIGN KEY (`pipeline_id`) REFERENCES `{prefix}pipelines` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_forms_stage`
        FOREIGN KEY (`stage_id`) REFERENCES `{prefix}pipeline_stages` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_forms_template`
        FOREIGN KEY (`autoresponder_template_id`) REFERENCES `{prefix}templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}form_fields` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id`     BIGINT UNSIGNED NOT NULL,
    `key`         VARCHAR(64)  NOT NULL,
    `label`       VARCHAR(191) NOT NULL,
    `type`        ENUM('text','email','tel','number','date','time','select','radio',
                       'checkbox','textarea','hidden','consent','file','child_repeater')
                      NOT NULL DEFAULT 'text',
    `placeholder` VARCHAR(191) DEFAULT NULL,
    `help_text`   VARCHAR(500) DEFAULT NULL,
    `options`     JSON         DEFAULT NULL,
    `is_required` TINYINT(1)   NOT NULL DEFAULT 0,
    `validation`  JSON         DEFAULT NULL,
    -- Which contact/deal/booking column this field writes to.
    `maps_to`     VARCHAR(64)  DEFAULT NULL,
    `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
    `width`       ENUM('full','half','third') NOT NULL DEFAULT 'full',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_form_fields` (`form_id`, `key`),
    KEY `ix_form_fields_form` (`form_id`, `sort_order`),
    CONSTRAINT `{prefix}fk_form_fields_form`
        FOREIGN KEY (`form_id`) REFERENCES `{prefix}forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}form_submissions` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_id`    BIGINT UNSIGNED NOT NULL,
    `contact_id` BIGINT UNSIGNED DEFAULT NULL,
    `deal_id`    BIGINT UNSIGNED DEFAULT NULL,
    `data`       JSON         NOT NULL,
    `ip`         VARCHAR(45)  DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `referrer`   VARCHAR(500) DEFAULT NULL,
    `utm`        JSON         DEFAULT NULL,
    `spam_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Spam is kept rather than discarded, so a false positive can be rescued.
    `is_spam`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_form_submissions_form` (`form_id`, `created_at`),
    KEY `ix_form_submissions_contact` (`contact_id`),
    KEY `ix_form_submissions_spam` (`is_spam`, `created_at`),
    CONSTRAINT `{prefix}fk_form_submissions_form`
        FOREIGN KEY (`form_id`) REFERENCES `{prefix}forms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_form_submissions_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_form_submissions_deal`
        FOREIGN KEY (`deal_id`) REFERENCES `{prefix}deals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}surveys` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120) NOT NULL,
    `type`          ENUM('nps','csat','custom') NOT NULL DEFAULT 'nps',
    `questions`     JSON         DEFAULT NULL,
    `trigger_event` VARCHAR(64)  DEFAULT NULL,
    `delay_hours`   SMALLINT     NOT NULL DEFAULT 20,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     DEFAULT NULL,
    `updated_at`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_surveys_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The feedback gate. `sentiment` is what decides whether a guest is asked for
-- a public review or routed to a manager — a low score NEVER produces a
-- review request.
CREATE TABLE IF NOT EXISTS `{prefix}survey_responses` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_id`       BIGINT UNSIGNED NOT NULL,
    `contact_id`      BIGINT UNSIGNED DEFAULT NULL,
    `booking_id`      BIGINT UNSIGNED DEFAULT NULL,
    `visit_id`        BIGINT UNSIGNED DEFAULT NULL,
    `score`           TINYINT UNSIGNED DEFAULT NULL,
    `sentiment`       ENUM('promoter','passive','detractor') DEFAULT NULL,
    `answers`         JSON         DEFAULT NULL,
    `comment`         TEXT         DEFAULT NULL,
    `routed_to`       BIGINT UNSIGNED DEFAULT NULL,
    `resolved_at`     DATETIME     DEFAULT NULL,
    `resolution_note` TEXT         DEFAULT NULL,
    `public_token`    VARCHAR(64)  DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_survey_responses_token` (`public_token`),
    -- The detractor queue and the NPS trend both read this.
    KEY `ix_survey_responses_sentiment` (`sentiment`, `created_at`),
    KEY `ix_survey_responses_contact` (`contact_id`),
    KEY `ix_survey_responses_booking` (`booking_id`),
    KEY `ix_survey_responses_unresolved` (`sentiment`, `resolved_at`),
    CONSTRAINT `{prefix}fk_survey_responses_survey`
        FOREIGN KEY (`survey_id`) REFERENCES `{prefix}surveys` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_survey_responses_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_survey_responses_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `{prefix}bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}review_requests` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id`          BIGINT UNSIGNED DEFAULT NULL,
    `booking_id`          BIGINT UNSIGNED DEFAULT NULL,
    `survey_response_id`  BIGINT UNSIGNED DEFAULT NULL,
    `platform`            ENUM('google','yelp','facebook','tripadvisor') NOT NULL DEFAULT 'google',
    `status`              ENUM('pending','sent','clicked','reviewed','declined')
                              NOT NULL DEFAULT 'pending',
    `sent_at`             DATETIME    DEFAULT NULL,
    `clicked_at`          DATETIME    DEFAULT NULL,
    `reviewed_at`         DATETIME    DEFAULT NULL,
    `token`               VARCHAR(64) DEFAULT NULL,
    `created_at`          DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_requests_token` (`token`),
    KEY `ix_review_requests_contact` (`contact_id`),
    KEY `ix_review_requests_status` (`status`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}reviews` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `platform`    ENUM('google','yelp','facebook','tripadvisor','internal') NOT NULL,
    `external_id` VARCHAR(191) DEFAULT NULL,
    `contact_id`  BIGINT UNSIGNED DEFAULT NULL,
    `author_name` VARCHAR(120) DEFAULT NULL,
    `rating`      TINYINT UNSIGNED DEFAULT NULL,
    `body`        TEXT         DEFAULT NULL,
    `review_url`  VARCHAR(500) DEFAULT NULL,
    `replied_at`  DATETIME     DEFAULT NULL,
    `reply_body`  TEXT         DEFAULT NULL,
    `reply_by`    BIGINT UNSIGNED DEFAULT NULL,
    `sentiment`   ENUM('positive','neutral','negative') DEFAULT NULL,
    `is_featured` TINYINT(1)   NOT NULL DEFAULT 0,
    `posted_at`   DATETIME     DEFAULT NULL,
    `imported_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- Re-importing the same review must update, not duplicate.
    UNIQUE KEY `uq_reviews_external` (`platform`, `external_id`),
    KEY `ix_reviews_posted` (`platform`, `posted_at`),
    KEY `ix_reviews_rating` (`rating`, `posted_at`),
    -- The "needs a reply" queue.
    KEY `ix_reviews_unreplied` (`replied_at`, `rating`),
    KEY `ix_reviews_featured` (`is_featured`, `rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donation requests, with a hard annual budget guard so the venue cannot
-- quietly give away more than it decided to.
CREATE TABLE IF NOT EXISTS `{prefix}donation_requests` (
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `organization_name`       VARCHAR(191) NOT NULL,
    `company_id`              BIGINT UNSIGNED DEFAULT NULL,
    `contact_name`            VARCHAR(120) DEFAULT NULL,
    `contact_email`           VARCHAR(191) DEFAULT NULL,
    `contact_phone`           VARCHAR(32)  DEFAULT NULL,
    `organization_type`       VARCHAR(40)  DEFAULT NULL,
    `event_name`              VARCHAR(191) DEFAULT NULL,
    `event_date`              DATE         DEFAULT NULL,
    `requested_items`         TEXT         DEFAULT NULL,
    `estimated_value_cents`   BIGINT       NOT NULL DEFAULT 0,
    `beneficiary`             VARCHAR(191) DEFAULT NULL,
    `tax_id`                  VARCHAR(40)  DEFAULT NULL,
    `status`                  ENUM('new','reviewing','approved','declined','fulfilled','expired')
                                  NOT NULL DEFAULT 'new',
    `decision_by`             BIGINT UNSIGNED DEFAULT NULL,
    `decision_at`             DATETIME     DEFAULT NULL,
    `decision_note`           TEXT         DEFAULT NULL,
    `certificate_number`      VARCHAR(32)  DEFAULT NULL,
    `certificate_value_cents` BIGINT       NOT NULL DEFAULT 0,
    `certificate_expires_at`  DATE         DEFAULT NULL,
    `redeemed_at`             DATETIME     DEFAULT NULL,
    `fiscal_year`             SMALLINT     DEFAULT NULL,
    `created_at`              DATETIME     NOT NULL,
    `updated_at`              DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_donation_certificate` (`certificate_number`),
    KEY `ix_donation_status` (`status`, `created_at`),
    KEY `ix_donation_fiscal` (`fiscal_year`, `status`),
    KEY `ix_donation_expiry` (`certificate_expires_at`, `redeemed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}donation_budget` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fiscal_year`     SMALLINT NOT NULL,
    `budget_cents`    BIGINT   NOT NULL DEFAULT 0,
    -- Approved but not yet redeemed, so the guard counts promises as spent.
    `committed_cents` BIGINT   NOT NULL DEFAULT 0,
    `redeemed_cents`  BIGINT   NOT NULL DEFAULT 0,
    `created_at`      DATETIME DEFAULT NULL,
    `updated_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_donation_budget_year` (`fiscal_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}referrals` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `referrer_contact_id`  BIGINT UNSIGNED NOT NULL,
    `referred_contact_id`  BIGINT UNSIGNED DEFAULT NULL,
    `code`                 VARCHAR(32) NOT NULL,
    `status`               ENUM('pending','signed_up','converted','rewarded','expired')
                               NOT NULL DEFAULT 'pending',
    `reward_type`          VARCHAR(40) DEFAULT NULL,
    `reward_value_cents`   BIGINT      NOT NULL DEFAULT 0,
    `converted_booking_id` BIGINT UNSIGNED DEFAULT NULL,
    `rewarded_at`          DATETIME    DEFAULT NULL,
    `created_at`           DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_referrals_code` (`code`),
    KEY `ix_referrals_referrer` (`referrer_contact_id`, `status`),
    CONSTRAINT `{prefix}fk_referrals_referrer`
        FOREIGN KEY (`referrer_contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}coupons` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`             VARCHAR(40)  NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `type`             ENUM('percent','fixed','bogo','free_item') NOT NULL DEFAULT 'percent',
    `value`            DECIMAL(10,2) NOT NULL DEFAULT 0,
    `applies_to`       JSON         DEFAULT NULL,
    `min_spend_cents`  BIGINT       NOT NULL DEFAULT 0,
    `max_redemptions`  INT          DEFAULT NULL,
    `max_per_contact`  SMALLINT     NOT NULL DEFAULT 1,
    `redemption_count` INT          NOT NULL DEFAULT 0,
    `starts_at`        DATETIME     DEFAULT NULL,
    `expires_at`       DATETIME     DEFAULT NULL,
    `campaign_id`      BIGINT UNSIGNED DEFAULT NULL,
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       DATETIME     DEFAULT NULL,
    `updated_at`       DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coupons_code` (`code`),
    KEY `ix_coupons_active` (`is_active`, `starts_at`, `expires_at`),
    CONSTRAINT `{prefix}fk_coupons_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `{prefix}campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Redemptions tie an offer back to real money, which is how Text Club and
-- campaign attribution stop being guesswork.
CREATE TABLE IF NOT EXISTS `{prefix}coupon_redemptions` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coupon_id`      BIGINT UNSIGNED NOT NULL,
    `contact_id`     BIGINT UNSIGNED DEFAULT NULL,
    `booking_id`     BIGINT UNSIGNED DEFAULT NULL,
    `visit_id`       BIGINT UNSIGNED DEFAULT NULL,
    `discount_cents` BIGINT   NOT NULL DEFAULT 0,
    `redeemed_at`    DATETIME NOT NULL,
    `redeemed_by`    BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_coupon_redemptions_coupon` (`coupon_id`, `redeemed_at`),
    KEY `ix_coupon_redemptions_contact` (`contact_id`),
    CONSTRAINT `{prefix}fk_coupon_redemptions_coupon`
        FOREIGN KEY (`coupon_id`) REFERENCES `{prefix}coupons` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_coupon_redemptions_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}tasks` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`             VARCHAR(191) NOT NULL,
    `description`       TEXT         DEFAULT NULL,
    `entity_type`       VARCHAR(40)  DEFAULT NULL,
    `entity_id`         BIGINT UNSIGNED DEFAULT NULL,
    `assigned_to`       BIGINT UNSIGNED DEFAULT NULL,
    `created_by`        BIGINT UNSIGNED DEFAULT NULL,
    `priority`          ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `status`            ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
    `due_at`            DATETIME     DEFAULT NULL,
    `completed_at`      DATETIME     DEFAULT NULL,
    `completed_by`      BIGINT UNSIGNED DEFAULT NULL,
    `automation_run_id` BIGINT UNSIGNED DEFAULT NULL,
    `reminder_sent_at`  DATETIME     DEFAULT NULL,
    `created_at`        DATETIME     NOT NULL,
    `updated_at`        DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- The "my tasks" widget and the daily digest both read this exact tuple.
    KEY `ix_tasks_assignee` (`assigned_to`, `status`, `due_at`),
    KEY `ix_tasks_entity` (`entity_type`, `entity_id`),
    KEY `ix_tasks_overdue` (`status`, `due_at`),
    CONSTRAINT `{prefix}fk_tasks_assignee`
        FOREIGN KEY (`assigned_to`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_tasks_creator`
        FOREIGN KEY (`created_by`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}task_comments` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id`    BIGINT UNSIGNED NOT NULL,
    `user_id`    BIGINT UNSIGNED DEFAULT NULL,
    `body`       TEXT     NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_task_comments_task` (`task_id`, `created_at`),
    CONSTRAINT `{prefix}fk_task_comments_task`
        FOREIGN KEY (`task_id`) REFERENCES `{prefix}tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
