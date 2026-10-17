-- Castle CRM — 009 what the automation engine needed that 001-008 did not have
--
-- A NEW FILE RATHER THAN AN EDIT TO AN EARLIER ONE. The migration runner
-- records which FILES it has applied, so a change edited into 007 would reach
-- a fresh install and nobody who had already installed. Every statement here
-- is written to be safe to re-run: the runner tolerates "that column is
-- already there" (1060) so a file that failed halfway can be completed.

-- A coupon a guest has been GIVEN, as distinct from one they have used.
--
-- `coupon_redemptions` started as one row per redemption, which left the
-- "issue a coupon" automation step nowhere to record that a family had been
-- given an offer — and so no way to honour `max_per_contact`, which is the
-- venue's own rule about how many times one family may have it. Re-running a
-- playbook would hand out the same offer again, every time.
--
-- So a row is written when the offer goes out and completed when it comes
-- back. `redeemed_at IS NULL` now means "issued, not yet used", which is also
-- the figure an offer's report needs to state a redemption RATE rather than
-- just a count.
ALTER TABLE `{prefix}coupon_redemptions`
    ADD COLUMN `issued_at` DATETIME DEFAULT NULL AFTER `discount_cents`;

-- Which automation run gave it out, so a venue can ask what an offer cost
-- them and which sequence spent it.
ALTER TABLE `{prefix}coupon_redemptions`
    ADD COLUMN `issued_by_run_id` BIGINT UNSIGNED DEFAULT NULL AFTER `issued_at`;

-- Nullable from here on. Rows written before this change are real
-- redemptions and keep their timestamp, so nothing is reinterpreted.
ALTER TABLE `{prefix}coupon_redemptions`
    MODIFY `redeemed_at` DATETIME DEFAULT NULL;

ALTER TABLE `{prefix}coupon_redemptions`
    ADD KEY `ix_coupon_redemptions_issued` (`coupon_id`, `issued_at`);
