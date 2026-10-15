-- Castle CRM — 006 conversations, messages, campaigns, Text Club
--
-- Compliance is modelled here rather than enforced only in the UI: the
-- suppression list, the consent columns on contacts, and the Text Club
-- double-opt-in states are all checked in the send path, so an agent
-- physically cannot text someone who replied STOP.

CREATE TABLE IF NOT EXISTS `{prefix}conversations` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id`        BIGINT UNSIGNED DEFAULT NULL,
    `channel`           ENUM('email','sms','webchat','phone','social','internal')
                            NOT NULL DEFAULT 'email',
    `subject`           VARCHAR(255) DEFAULT NULL,
    `status`            ENUM('open','pending','snoozed','closed') NOT NULL DEFAULT 'open',
    `assigned_to`       BIGINT UNSIGNED DEFAULT NULL,
    `last_message_at`   DATETIME    DEFAULT NULL,
    `snoozed_until`     DATETIME    DEFAULT NULL,
    `is_unread`         TINYINT(1)  NOT NULL DEFAULT 1,
    -- Stamped on the first outbound reply, which is what makes per-rep
    -- first-response-time reportable.
    `first_response_at` DATETIME    DEFAULT NULL,
    `closed_at`         DATETIME    DEFAULT NULL,
    `created_at`        DATETIME    NOT NULL,
    `updated_at`        DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_conversations_queue` (`status`, `assigned_to`, `last_message_at`),
    KEY `ix_conversations_contact` (`contact_id`, `last_message_at`),
    KEY `ix_conversations_unread` (`is_unread`, `status`),
    KEY `ix_conversations_snoozed` (`snoozed_until`),
    CONSTRAINT `{prefix}fk_conversations_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_conversations_user`
        FOREIGN KEY (`assigned_to`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}messages` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id`     BIGINT UNSIGNED DEFAULT NULL,
    `contact_id`          BIGINT UNSIGNED DEFAULT NULL,
    `direction`           ENUM('inbound','outbound') NOT NULL,
    `channel`             ENUM('email','sms','mms','webchat','phone','social','internal') NOT NULL,
    `from_address`        VARCHAR(191) DEFAULT NULL,
    `to_address`          VARCHAR(191) DEFAULT NULL,
    `subject`             VARCHAR(255) DEFAULT NULL,
    `body`                LONGTEXT     DEFAULT NULL,
    `body_text`           LONGTEXT     DEFAULT NULL,
    `user_id`             BIGINT UNSIGNED DEFAULT NULL,
    `template_id`         BIGINT UNSIGNED DEFAULT NULL,
    `campaign_id`         BIGINT UNSIGNED DEFAULT NULL,
    `automation_run_id`   BIGINT UNSIGNED DEFAULT NULL,
    `status`              ENUM('queued','sending','sent','delivered','read','failed',
                               'bounced','complained','undelivered','suppressed')
                              NOT NULL DEFAULT 'queued',
    `provider`            VARCHAR(40)  DEFAULT NULL,
    `provider_message_id` VARCHAR(191) DEFAULT NULL,
    `error_code`          VARCHAR(40)  DEFAULT NULL,
    `error_message`       VARCHAR(500) DEFAULT NULL,
    `cost_cents`          DECIMAL(10,4) NOT NULL DEFAULT 0,
    -- SMS segment count: a 400-character text is three messages and costs
    -- three times as much, which the composer shows before sending.
    `segments`            TINYINT UNSIGNED DEFAULT NULL,
    `sent_at`             DATETIME     DEFAULT NULL,
    `delivered_at`        DATETIME     DEFAULT NULL,
    `opened_at`           DATETIME     DEFAULT NULL,
    `clicked_at`          DATETIME     DEFAULT NULL,
    `created_at`          DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_messages_conversation` (`conversation_id`, `created_at`),
    KEY `ix_messages_contact` (`contact_id`, `created_at`),
    -- Delivery webhooks look messages up by the provider's own id.
    KEY `ix_messages_provider` (`provider_message_id`),
    KEY `ix_messages_status` (`status`, `created_at`),
    KEY `ix_messages_campaign` (`campaign_id`, `status`),
    CONSTRAINT `{prefix}fk_messages_conversation`
        FOREIGN KEY (`conversation_id`) REFERENCES `{prefix}conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_messages_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_messages_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}message_attachments` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `message_id` BIGINT UNSIGNED NOT NULL,
    `filename`   VARCHAR(191) NOT NULL,
    `path`       VARCHAR(255) NOT NULL,
    `mime`       VARCHAR(100) DEFAULT NULL,
    `size_bytes` BIGINT       NOT NULL DEFAULT 0,
    `created_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_message_attachments_message` (`message_id`),
    CONSTRAINT `{prefix}fk_message_attachments_message`
        FOREIGN KEY (`message_id`) REFERENCES `{prefix}messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}templates` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(120) NOT NULL,
    `slug`        VARCHAR(64)  NOT NULL,
    `channel`     ENUM('email','sms','letter') NOT NULL DEFAULT 'email',
    `category`    VARCHAR(40)  DEFAULT NULL,
    `subject`     VARCHAR(255) DEFAULT NULL,
    `body`        LONGTEXT     NOT NULL,
    `preheader`   VARCHAR(255) DEFAULT NULL,
    `merge_tags`  JSON         DEFAULT NULL,
    `playbook_id` BIGINT UNSIGNED DEFAULT NULL,
    `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_by`  BIGINT UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     DEFAULT NULL,
    `updated_at`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_templates_slug` (`slug`),
    KEY `ix_templates_channel` (`channel`, `is_active`),
    KEY `ix_templates_playbook` (`playbook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}canned_replies` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`      VARCHAR(120) NOT NULL,
    `body`       TEXT         NOT NULL,
    `channel`    ENUM('email','sms','any') NOT NULL DEFAULT 'any',
    `shortcut`   VARCHAR(32)  DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_canned_replies_shortcut` (`shortcut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Segment rules persist as JSON and are compiled to parameterised SQL by
-- SegmentEngine. Storing compiled SQL would be a stored-injection hazard.
CREATE TABLE IF NOT EXISTS `{prefix}segments` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(120) NOT NULL,
    `slug`         VARCHAR(64)  NOT NULL,
    `description`  VARCHAR(500) DEFAULT NULL,
    `rules`        JSON         DEFAULT NULL,
    `is_dynamic`   TINYINT(1)   NOT NULL DEFAULT 1,
    `cached_count` INT          DEFAULT NULL,
    `cached_at`    DATETIME     DEFAULT NULL,
    `created_by`   BIGINT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     DEFAULT NULL,
    `updated_at`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_segments_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}campaigns` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                VARCHAR(120) NOT NULL,
    `channel`             ENUM('email','sms','both') NOT NULL DEFAULT 'email',
    `template_id`         BIGINT UNSIGNED DEFAULT NULL,
    `segment_id`          BIGINT UNSIGNED DEFAULT NULL,
    `status`              ENUM('draft','scheduled','sending','paused','sent','cancelled')
                              NOT NULL DEFAULT 'draft',
    `subject`             VARCHAR(255) DEFAULT NULL,
    `body`                LONGTEXT     DEFAULT NULL,
    `from_name`           VARCHAR(120) DEFAULT NULL,
    `from_email`          VARCHAR(191) DEFAULT NULL,
    `reply_to`            VARCHAR(191) DEFAULT NULL,
    `scheduled_at`        DATETIME     DEFAULT NULL,
    -- Sends are confined to a window so a 7am blast never happens by accident.
    `send_window_start`   TIME         DEFAULT NULL,
    `send_window_end`     TIME         DEFAULT NULL,
    `timezone`            VARCHAR(64)  DEFAULT NULL,
    `ab_variant_b`        JSON         DEFAULT NULL,
    `ab_split_percent`    TINYINT UNSIGNED DEFAULT NULL,

    `recipients_total`    INT    NOT NULL DEFAULT 0,
    `sent_count`          INT    NOT NULL DEFAULT 0,
    `delivered_count`     INT    NOT NULL DEFAULT 0,
    `opened_count`        INT    NOT NULL DEFAULT 0,
    `clicked_count`       INT    NOT NULL DEFAULT 0,
    `bounced_count`       INT    NOT NULL DEFAULT 0,
    `unsubscribed_count`  INT    NOT NULL DEFAULT 0,
    `complained_count`    INT    NOT NULL DEFAULT 0,
    `failed_count`        INT    NOT NULL DEFAULT 0,

    `revenue_attributed_cents` BIGINT NOT NULL DEFAULT 0,
    -- Shown before sending, so nobody discovers the cost afterwards.
    `estimated_cost_cents`     BIGINT NOT NULL DEFAULT 0,
    `actual_cost_cents`        BIGINT NOT NULL DEFAULT 0,

    `created_by`          BIGINT UNSIGNED DEFAULT NULL,
    `started_at`          DATETIME DEFAULT NULL,
    `completed_at`        DATETIME DEFAULT NULL,
    `created_at`          DATETIME DEFAULT NULL,
    `updated_at`          DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_campaigns_status` (`status`, `scheduled_at`),
    KEY `ix_campaigns_segment` (`segment_id`),
    CONSTRAINT `{prefix}fk_campaigns_template`
        FOREIGN KEY (`template_id`) REFERENCES `{prefix}templates` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_campaigns_segment`
        FOREIGN KEY (`segment_id`) REFERENCES `{prefix}segments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}messages`
    ADD CONSTRAINT `{prefix}fk_messages_campaign`
    FOREIGN KEY (`campaign_id`) REFERENCES `{prefix}campaigns` (`id`) ON DELETE SET NULL;

ALTER TABLE `{prefix}messages`
    ADD CONSTRAINT `{prefix}fk_messages_template`
    FOREIGN KEY (`template_id`) REFERENCES `{prefix}templates` (`id`) ON DELETE SET NULL;

-- One row per intended recipient, written before sending. That is what makes a
-- send resumable: a cron tick that dies mid-blast picks up where it stopped
-- instead of starting over and double-messaging people.
CREATE TABLE IF NOT EXISTS `{prefix}campaign_recipients` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id`     BIGINT UNSIGNED NOT NULL,
    `contact_id`      BIGINT UNSIGNED NOT NULL,
    `variant`         CHAR(1)     NOT NULL DEFAULT 'A',
    `status`          ENUM('pending','sent','delivered','opened','clicked','bounced',
                           'failed','unsubscribed','skipped') NOT NULL DEFAULT 'pending',
    `skip_reason`     VARCHAR(80) DEFAULT NULL,
    `message_id`      BIGINT UNSIGNED DEFAULT NULL,
    `sent_at`         DATETIME    DEFAULT NULL,
    `opened_at`       DATETIME    DEFAULT NULL,
    `clicked_at`      DATETIME    DEFAULT NULL,
    `unsubscribed_at` DATETIME    DEFAULT NULL,
    `bounced_at`      DATETIME    DEFAULT NULL,
    `error`           VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- Hard guarantee that one campaign cannot message one contact twice.
    UNIQUE KEY `uq_campaign_recipients` (`campaign_id`, `contact_id`),
    KEY `ix_campaign_recipients_pending` (`campaign_id`, `status`),
    KEY `ix_campaign_recipients_contact` (`contact_id`),
    CONSTRAINT `{prefix}fk_campaign_recipients_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `{prefix}campaigns` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_campaign_recipients_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}link_clicks` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `message_id`  BIGINT UNSIGNED DEFAULT NULL,
    `campaign_id` BIGINT UNSIGNED DEFAULT NULL,
    `contact_id`  BIGINT UNSIGNED DEFAULT NULL,
    `url`         VARCHAR(500) NOT NULL,
    `clicked_at`  DATETIME     NOT NULL,
    `ip`          VARCHAR(45)  DEFAULT NULL,
    `user_agent`  VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_link_clicks_campaign` (`campaign_id`, `clicked_at`),
    KEY `ix_link_clicks_contact` (`contact_id`, `clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The suppression list. Checked in the send path on EVERY message, including
-- transactional ones where the reason is a hard bounce: sending to a known-bad
-- address damages the venue's sending reputation for everyone else.
CREATE TABLE IF NOT EXISTS `{prefix}suppressions` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel`    ENUM('email','sms') NOT NULL,
    `address`    VARCHAR(191) NOT NULL,
    `reason`     ENUM('unsubscribe','bounce','complaint','manual','invalid') NOT NULL,
    `source`     VARCHAR(80)  DEFAULT NULL,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_suppressions` (`channel`, `address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inbound SMS keywords. STOP/UNSTOP/HELP are handled here before anything
-- else looks at the message, and a STOP is honoured without a human in the loop.
CREATE TABLE IF NOT EXISTS `{prefix}sms_keywords` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `keyword`    VARCHAR(32) NOT NULL,
    `action`     ENUM('subscribe','unsubscribe','help','custom') NOT NULL,
    `reply_body` VARCHAR(500) DEFAULT NULL,
    `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
    `created_at` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sms_keywords` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The Text Club list, with its own double-opt-in state so a 'pending'
-- subscriber is never messaged as a confirmed one.
CREATE TABLE IF NOT EXISTS `{prefix}text_club` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id`           BIGINT UNSIGNED DEFAULT NULL,
    `phone_normalized`     VARCHAR(20) NOT NULL,
    `status`               ENUM('pending','subscribed','unsubscribed') NOT NULL DEFAULT 'pending',
    `joined_via`           VARCHAR(80) DEFAULT NULL,
    `double_optin_sent_at` DATETIME    DEFAULT NULL,
    `confirmed_at`         DATETIME    DEFAULT NULL,
    `unsubscribed_at`      DATETIME    DEFAULT NULL,
    `last_offer_sent_at`   DATETIME    DEFAULT NULL,
    `offers_redeemed`      SMALLINT    NOT NULL DEFAULT 0,
    `created_at`           DATETIME    NOT NULL,
    `updated_at`           DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_text_club_phone` (`phone_normalized`),
    KEY `ix_text_club_status` (`status`),
    KEY `ix_text_club_contact` (`contact_id`),
    CONSTRAINT `{prefix}fk_text_club_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10DLC registration state. Sending A2P SMS from an unregistered US local
-- number gets traffic filtered by carriers, so settings warns when this is
-- incomplete rather than letting messages silently vanish.
CREATE TABLE IF NOT EXISTS `{prefix}sms_numbers` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone`              VARCHAR(20) NOT NULL,
    `provider`           VARCHAR(40) DEFAULT NULL,
    `type`               ENUM('local','toll_free','short_code') NOT NULL DEFAULT 'local',
    `tendlc_campaign_id` VARCHAR(80) DEFAULT NULL,
    `tendlc_status`      ENUM('unregistered','pending','approved','rejected')
                             NOT NULL DEFAULT 'unregistered',
    `is_default`         TINYINT(1)  NOT NULL DEFAULT 0,
    `is_active`          TINYINT(1)  NOT NULL DEFAULT 1,
    `created_at`         DATETIME    DEFAULT NULL,
    `updated_at`         DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sms_numbers_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
