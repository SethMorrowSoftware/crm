-- Castle CRM — 002 contacts, households, companies
--
-- The identity model follows the venue's reality (see spec §3.8): the
-- CenterEdge card number is the join key, not email. A POS-derived contact
-- arrives with a visit history and NO consent, and only becomes marketable
-- when the guest supplies a channel themselves.

CREATE TABLE IF NOT EXISTS `{prefix}households` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`               VARCHAR(160) NOT NULL,
    `primary_contact_id` BIGINT UNSIGNED DEFAULT NULL,
    `address_1`          VARCHAR(160) DEFAULT NULL,
    `address_2`          VARCHAR(160) DEFAULT NULL,
    `city`               VARCHAR(80)  DEFAULT NULL,
    `state`              VARCHAR(40)  DEFAULT NULL,
    `postal_code`        VARCHAR(20)  DEFAULT NULL,
    `notes`              TEXT         DEFAULT NULL,
    `created_at`         DATETIME     DEFAULT NULL,
    `updated_at`         DATETIME     DEFAULT NULL,
    `deleted_at`         DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_households_postal` (`postal_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}companies` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(191) NOT NULL,
    `type`                  ENUM('school','corporate','camp','nonprofit','sports','church','other')
                                NOT NULL DEFAULT 'other',
    -- School district matters for field-trip targeting in the Castle's draw area.
    `district`              VARCHAR(160) DEFAULT NULL,
    `website`               VARCHAR(200) DEFAULT NULL,
    `phone`                 VARCHAR(32)  DEFAULT NULL,
    `address_1`             VARCHAR(160) DEFAULT NULL,
    `address_2`             VARCHAR(160) DEFAULT NULL,
    `city`                  VARCHAR(80)  DEFAULT NULL,
    `state`                 VARCHAR(40)  DEFAULT NULL,
    `postal_code`           VARCHAR(20)  DEFAULT NULL,
    `owner_id`              BIGINT UNSIGNED DEFAULT NULL,
    `annual_potential_cents` BIGINT      NOT NULL DEFAULT 0,
    -- Schools and camps rebook on an annual cycle; this flag drives the
    -- recurrence automation in the Group & Corporate playbook.
    `is_recurring_group`    TINYINT(1)   NOT NULL DEFAULT 0,
    `last_event_at`         DATE         DEFAULT NULL,
    `notes`                 TEXT         DEFAULT NULL,
    `created_at`            DATETIME     DEFAULT NULL,
    `updated_at`            DATETIME     DEFAULT NULL,
    `deleted_at`            DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_companies_type` (`type`),
    KEY `ix_companies_owner` (`owner_id`),
    KEY `ix_companies_recurring` (`is_recurring_group`, `last_event_at`),
    KEY `ix_companies_name` (`name`),
    CONSTRAINT `{prefix}fk_companies_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}contacts` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `first_name`          VARCHAR(80)  DEFAULT NULL,
    `last_name`           VARCHAR(80)  DEFAULT NULL,
    `email`               VARCHAR(191) DEFAULT NULL,
    `phone`               VARCHAR(32)  DEFAULT NULL,
    -- E.164. Every SMS send, Text Club dedup and inbound-message match uses
    -- this column, never `phone`, so two renderings compare equal.
    `phone_normalized`    VARCHAR(20)  DEFAULT NULL,
    `alt_phone`           VARCHAR(32)  DEFAULT NULL,
    `address_1`           VARCHAR(160) DEFAULT NULL,
    `address_2`           VARCHAR(160) DEFAULT NULL,
    `city`                VARCHAR(80)  DEFAULT NULL,
    `state`               VARCHAR(40)  DEFAULT NULL,
    `postal_code`         VARCHAR(20)  DEFAULT NULL,
    `country`             VARCHAR(2)   NOT NULL DEFAULT 'US',
    `lifecycle_stage`     ENUM('lead','prospect','customer','member','lapsed','advocate')
                              NOT NULL DEFAULT 'lead',
    `source`              VARCHAR(40)  DEFAULT NULL,
    `source_detail`       VARCHAR(191) DEFAULT NULL,
    `utm`                 JSON         DEFAULT NULL,
    `owner_id`            BIGINT UNSIGNED DEFAULT NULL,
    `household_id`        BIGINT UNSIGNED DEFAULT NULL,
    `company_id`          BIGINT UNSIGNED DEFAULT NULL,

    -- Consent is modelled per channel with its own timestamp and provenance,
    -- because TCPA compliance turns on WHEN and HOW consent was given, not
    -- merely that a box is ticked. The evidence lives in consent_log.
    `email_consent`        TINYINT(1)  NOT NULL DEFAULT 0,
    `email_consent_at`     DATETIME    DEFAULT NULL,
    `sms_consent`          TINYINT(1)  NOT NULL DEFAULT 0,
    `sms_consent_at`       DATETIME    DEFAULT NULL,
    `sms_consent_source`   VARCHAR(80) DEFAULT NULL,
    `marketing_opt_out_at` DATETIME    DEFAULT NULL,
    -- A hard block that overrides every consent flag above.
    `do_not_contact`       TINYINT(1)  NOT NULL DEFAULT 0,

    `birth_date`          DATE         DEFAULT NULL,
    `first_visit_at`      DATETIME     DEFAULT NULL,
    `last_visit_at`       DATETIME     DEFAULT NULL,
    `visit_count`         INT          NOT NULL DEFAULT 0,
    `lifetime_value_cents` BIGINT      NOT NULL DEFAULT 0,
    `avg_spend_cents`     BIGINT       NOT NULL DEFAULT 0,
    `last_contacted_at`   DATETIME     DEFAULT NULL,

    -- The CenterEdge card number. This is the primary POS join key.
    `external_pos_id`     VARCHAR(64)  DEFAULT NULL,
    `external_ids`        JSON         DEFAULT NULL,
    `notes_summary`       VARCHAR(500) DEFAULT NULL,

    `created_at`          DATETIME     DEFAULT NULL,
    `updated_at`          DATETIME     DEFAULT NULL,
    `deleted_at`          DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_contacts_email` (`email`),
    KEY `ix_contacts_phone` (`phone_normalized`),
    KEY `ix_contacts_pos` (`external_pos_id`),
    KEY `ix_contacts_lifecycle` (`lifecycle_stage`, `deleted_at`),
    KEY `ix_contacts_owner` (`owner_id`),
    KEY `ix_contacts_household` (`household_id`),
    KEY `ix_contacts_company` (`company_id`),
    KEY `ix_contacts_last_visit` (`last_visit_at`),
    -- The dashboard's "reachable with consent" count and every campaign
    -- audience query filter on exactly these three columns.
    KEY `ix_contacts_reachable` (`do_not_contact`, `email_consent`, `sms_consent`),
    KEY `ix_contacts_name` (`last_name`, `first_name`),
    CONSTRAINT `{prefix}fk_contacts_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_contacts_household`
        FOREIGN KEY (`household_id`) REFERENCES `{prefix}households` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_contacts_company`
        FOREIGN KEY (`company_id`) REFERENCES `{prefix}companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `{prefix}households`
    ADD CONSTRAINT `{prefix}fk_households_primary_contact`
    FOREIGN KEY (`primary_contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE SET NULL;

-- Children exist for party operations only. Castle CRM never messages a child:
-- every birthday message addresses the guardian contact, enforced in the send
-- path. These rows therefore hold the minimum a party host needs.
CREATE TABLE IF NOT EXISTS `{prefix}children` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `household_id`          BIGINT UNSIGNED DEFAULT NULL,
    -- The guardian this child belongs to; the message recipient.
    `contact_id`            BIGINT UNSIGNED DEFAULT NULL,
    `first_name`            VARCHAR(80) NOT NULL,
    `birth_date`            DATE        DEFAULT NULL,
    -- Denormalised from birth_date so the birthday-month campaign is one
    -- indexed lookup rather than a function over every row.
    `birth_month`           TINYINT UNSIGNED DEFAULT NULL,
    `allergies`             VARCHAR(500) DEFAULT NULL,
    `notes`                 VARCHAR(500) DEFAULT NULL,
    `favourite_attraction_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at`            DATETIME    DEFAULT NULL,
    `updated_at`            DATETIME    DEFAULT NULL,
    `deleted_at`            DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_children_household` (`household_id`),
    KEY `ix_children_contact` (`contact_id`),
    KEY `ix_children_birth_month` (`birth_month`),
    KEY `ix_children_birth_date` (`birth_date`),
    CONSTRAINT `{prefix}fk_children_household`
        FOREIGN KEY (`household_id`) REFERENCES `{prefix}households` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_children_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}contact_companies` (
    `contact_id` BIGINT UNSIGNED NOT NULL,
    `company_id` BIGINT UNSIGNED NOT NULL,
    `job_title`  VARCHAR(120) DEFAULT NULL,
    `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`contact_id`, `company_id`),
    KEY `ix_contact_companies_company` (`company_id`),
    CONSTRAINT `{prefix}fk_contact_companies_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_contact_companies_company`
        FOREIGN KEY (`company_id`) REFERENCES `{prefix}companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}tags` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(80) NOT NULL,
    `color`      VARCHAR(7)  NOT NULL DEFAULT '#6b4ea8',
    `group`      VARCHAR(40) DEFAULT NULL,
    `created_at` DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tags_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic tagging, so tags work on contacts, companies, deals and bookings
-- without four join tables.
CREATE TABLE IF NOT EXISTS `{prefix}taggables` (
    `tag_id`      BIGINT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(40) NOT NULL,
    `entity_id`   BIGINT UNSIGNED NOT NULL,
    `created_at`  DATETIME    DEFAULT NULL,
    PRIMARY KEY (`tag_id`, `entity_type`, `entity_id`),
    KEY `ix_taggables_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `{prefix}fk_taggables_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `{prefix}tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}custom_fields` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` VARCHAR(40) NOT NULL,
    `key`         VARCHAR(64) NOT NULL,
    `label`       VARCHAR(120) NOT NULL,
    `type`        ENUM('text','textarea','number','date','select','multiselect','checkbox','email','phone','url')
                      NOT NULL DEFAULT 'text',
    `options`     JSON        DEFAULT NULL,
    `is_required` TINYINT(1)  NOT NULL DEFAULT 0,
    `sort_order`  SMALLINT    NOT NULL DEFAULT 0,
    `created_at`  DATETIME    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_custom_fields` (`entity_type`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}custom_field_values` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `field_id`    BIGINT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(40) NOT NULL,
    `entity_id`   BIGINT UNSIGNED NOT NULL,
    `value`       LONGTEXT    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_custom_field_values` (`field_id`, `entity_type`, `entity_id`),
    KEY `ix_custom_field_values_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `{prefix}fk_custom_field_values_field`
        FOREIGN KEY (`field_id`) REFERENCES `{prefix}custom_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}contact_notes` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id` BIGINT UNSIGNED NOT NULL,
    `user_id`    BIGINT UNSIGNED DEFAULT NULL,
    `body`       TEXT       NOT NULL,
    `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME   NOT NULL,
    `updated_at` DATETIME   DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_contact_notes_contact` (`contact_id`, `is_pinned`, `created_at`),
    CONSTRAINT `{prefix}fk_contact_notes_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `{prefix}fk_contact_notes_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A merge keeps the losing record's full payload so the operation is
-- reversible. Merging guests is easy to get wrong and expensive to undo by hand.
CREATE TABLE IF NOT EXISTS `{prefix}contact_merges` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kept_id`    BIGINT UNSIGNED NOT NULL,
    `merged_id`  BIGINT UNSIGNED NOT NULL,
    `payload`    JSON       NOT NULL,
    `user_id`    BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME   NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_contact_merges_kept` (`kept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The TCPA audit trail. Every grant and revocation, with the exact disclosure
-- text that was shown. This is the record that answers "prove they opted in".
CREATE TABLE IF NOT EXISTS `{prefix}consent_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contact_id` BIGINT UNSIGNED NOT NULL,
    `channel`    ENUM('email','sms','phone','mail') NOT NULL,
    `action`     ENUM('granted','revoked') NOT NULL,
    `source`     VARCHAR(80) NOT NULL,
    `ip`         VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    -- The disclosure wording, form id, keyword or staff member — whatever
    -- evidences this particular consent event.
    `evidence`   JSON        DEFAULT NULL,
    `created_at` DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_consent_contact` (`contact_id`, `channel`, `created_at`),
    CONSTRAINT `{prefix}fk_consent_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic activity timeline, shared by contacts, deals, companies and
-- bookings. One table means a contact's timeline is one indexed query.
CREATE TABLE IF NOT EXISTS `{prefix}activities` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type`      VARCHAR(40) NOT NULL,
    `entity_id`        BIGINT UNSIGNED NOT NULL,
    `type`             ENUM('call','email','sms','meeting','note','stage_change',
                            'task','booking','payment','visit','system') NOT NULL,
    `user_id`          BIGINT UNSIGNED DEFAULT NULL,
    `contact_id`       BIGINT UNSIGNED DEFAULT NULL,
    `subject`          VARCHAR(255) DEFAULT NULL,
    `body`             TEXT        DEFAULT NULL,
    `outcome`          VARCHAR(80) DEFAULT NULL,
    `duration_seconds` INT         DEFAULT NULL,
    `occurred_at`      DATETIME    NOT NULL,
    `created_at`       DATETIME    NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_activities_entity` (`entity_type`, `entity_id`, `occurred_at`),
    KEY `ix_activities_contact` (`contact_id`, `occurred_at`),
    KEY `ix_activities_user` (`user_id`, `occurred_at`),
    CONSTRAINT `{prefix}fk_activities_user`
        FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `{prefix}fk_activities_contact`
        FOREIGN KEY (`contact_id`) REFERENCES `{prefix}contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
