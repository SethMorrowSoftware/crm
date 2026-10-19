-- Castle CRM — 013 The Castle's real party list
--
-- The first seed carried four birthday packages taken from the website. The
-- venue's own system, CenterEdge, sells eleven, and the one it sells most —
-- InflataPark, 792 of the year's parties, about half — was not among the
-- four. This brings an EXISTING install to the list install/seeds/venue.php
-- now seeds, and a test holds the two to producing identical rows.
--
-- THREE RULES, because this runs against a venue's live data:
--
-- 1. A package is added only if its slug is not already there, so a venue
--    that made its own "InflataPark Birthday" keeps theirs.
-- 2. A value is corrected only where it still holds the ORIGINAL seed's
--    value. Laser Tag goes from $349.00 to CenterEdge's $349.99 only if
--    nobody has changed it since install; a price the venue set is theirs.
-- 3. Nothing is inserted on a FRESH install. There the schema runs before the
--    seed, so there are no attractions yet to link a package to — and the
--    seed is about to write these same rows properly. "The venue seed has
--    run" is read as "an attraction exists".

-- NO SEMICOLON MAY APPEAR INSIDE A STRING in this file: the migration runner
-- splits statements on every `;`, quoted or not.

-- ------------------------------------------------------------------------
-- What a family can book on the website
-- ------------------------------------------------------------------------
--
-- CenterEdge's WebEnabled. Four of the parties it sells are booked by phone
-- or at the desk only, and `is_active` cannot say that: switching one off
-- would take it out of the staff booking form too. Defaults to 1, so every
-- package an install already has stays exactly as bookable as it was.

ALTER TABLE `{prefix}party_packages`
    ADD COLUMN `is_online` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_active`;

-- ------------------------------------------------------------------------
-- Corrections to the original four, only where untouched
-- ------------------------------------------------------------------------

UPDATE `{prefix}party_packages` SET `base_price_cents` = 34999
 WHERE `slug` = 'laser-tag-birthday' AND `base_price_cents` = 34900;

UPDATE `{prefix}party_packages` SET `base_price_cents` = 34999
 WHERE `slug` = 'mini-golf-birthday' AND `base_price_cents` = 34900;

-- The new order puts InflataPark first. Each is moved only from the position
-- the original seed gave it.
UPDATE `{prefix}party_packages` SET `sort_order` = 20 WHERE `slug` = 'roller-skating-birthday' AND `sort_order` = 10;
UPDATE `{prefix}party_packages` SET `sort_order` = 30 WHERE `slug` = 'laser-tag-birthday'      AND `sort_order` = 20;
UPDATE `{prefix}party_packages` SET `sort_order` = 40 WHERE `slug` = 'bowling-birthday'        AND `sort_order` = 30;
UPDATE `{prefix}party_packages` SET `sort_order` = 80 WHERE `slug` = 'mini-golf-birthday'      AND `sort_order` = 40;

-- ------------------------------------------------------------------------
-- The seven the first seed did not have
-- ------------------------------------------------------------------------

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'InflataPark Birthday', 'inflatapark-birthday',
       (SELECT `id` FROM `{prefix}attractions` WHERE `slug` = 'inflatapark'),
       39900, 8, 2999, 100, 7, 0, 10000,
       '["60 minutes of InflataPark","A pizza slice and soft drink for each child","40 minutes in the party room","1,000 arcade points"]',
       'Up to 8 kids, $29.99 each additional child. Guests must be 42" or taller for the main InflataPark floor. Under 42" have the Toddler Zone. Online booking requires 7 or more days notice — inside that window the family must call.',
       1, 1, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'inflatapark-birthday');

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'All Access Party', 'all-access-party', NULL,
       44999, 10, 3999, 120, 7, 0, 10000, NULL,
       'Up to 10 guests, $39.99 each additional person. Booked by phone or at the front desk.',
       1, 0, 50, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'all-access-party');

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'All Access Party (Winter)', 'all-access-party-winter', NULL,
       39900, 10, 3999, 120, 7, 0, 10000, NULL,
       'Winter pricing. Up to 10 guests, $39.99 each additional person. Booked by phone or at the front desk.',
       1, 0, 60, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'all-access-party-winter');

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'Ballocity Birthday', 'ballocity-birthday',
       (SELECT `id` FROM `{prefix}attractions` WHERE `slug` = 'ballocity'),
       34999, 8, 2699, 120, 7, 0, 10000, NULL,
       'Up to 8 kids, $26.99 each additional child. Online booking requires 7 or more days notice — inside that window the family must call.',
       1, 1, 70, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'ballocity-birthday');

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'InflataPark Toddler Party', 'inflatapark-toddler-party',
       (SELECT `id` FROM `{prefix}attractions` WHERE `slug` = 'inflatapark'),
       29900, 8, 2699, 100, 7, 0, 10000,
       '["60 minutes of InflataPark","A pizza slice, soft drink and ice cream cup for each child","40 minutes in the party room","500 arcade points"]',
       'For guests 42" and under. Up to 8 kids, $26.99 each additional child. Saturday and Sunday at 10:00, 10:30 and 11:00 am only, with the full InflataPark floor during Toddler Hours. Booked by phone or at the front desk.',
       1, 0, 90, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'inflatapark-toddler-party');

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'Glow Room Party', 'glow-room-party', NULL,
       19900, 8, 300, 120, 7, 0, 0, NULL,
       'Up to 8 kids, $3.00 each additional child. Booked by phone or at the front desk.',
       1, 0, 100, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'glow-room-party');

INSERT INTO `{prefix}party_packages`
    (`name`, `slug`, `main_attraction_id`, `base_price_cents`, `included_guests`, `additional_guest_cents`,
     `duration_minutes`, `min_notice_days`, `is_seasonal`, `deposit_cents`, `includes`, `description`,
     `is_active`, `is_online`, `sort_order`, `created_at`, `updated_at`)
SELECT 'Bounce Party', 'bounce-party', NULL,
       29900, 8, 2699, 120, 7, 0, 10000, NULL,
       'Up to 8 kids, $26.99 each additional child. Booked by phone or at the front desk.',
       1, 0, 110, UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `{prefix}attractions`)
   AND NOT EXISTS (SELECT 1 FROM `{prefix}party_packages` WHERE `slug` = 'bounce-party');
