-- Castle CRM — 010 what the reputation module needed that 007 did not have
--
-- A NEW FILE RATHER THAN AN EDIT TO 007, for the reason 009 states: the
-- migration runner records which FILES it has applied, so a change edited into
-- an earlier file would reach a fresh install and nobody who had already
-- installed. Every statement is safe to re-run — the runner tolerates "that
-- column is already there" (1060) and "that key exists" (1061).

-- WHEN THE INVITATION WENT OUT, as distinct from when it was answered.
--
-- `survey_responses` originally carried one timestamp, `created_at`, and the
-- module writes the row when it SENDS the invitation — so that column is the
-- invite time and there was nowhere to record the answer. Every figure on the
-- page needs the difference: an NPS trend plotted by invite date moves a
-- score onto the day of the party rather than the day the parent replied, and
-- a response RATE cannot be computed at all without knowing how many
-- invitations are still unanswered.
ALTER TABLE `{prefix}survey_responses`
    ADD COLUMN `invited_at` DATETIME DEFAULT NULL AFTER `created_at`;

ALTER TABLE `{prefix}survey_responses`
    ADD COLUMN `responded_at` DATETIME DEFAULT NULL AFTER `invited_at`;

-- Which channel carried the invitation, so "nobody answers our texts" is a
-- fact the venue can check rather than an impression.
ALTER TABLE `{prefix}survey_responses`
    ADD COLUMN `channel` VARCHAR(16) DEFAULT NULL AFTER `responded_at`;

-- CSAT BY ATTRACTION AND BY PARTY HOST (spec §8.9's reported figures).
--
-- Both are copied from the booking at invite time rather than joined at read
-- time, deliberately: a host leaves and a booking's `host_user_id` may be
-- reassigned months later, and a score has to stay attributed to whoever was
-- actually running that party. The same reasoning as stamping a waiver with
-- the version that was signed.
ALTER TABLE `{prefix}survey_responses`
    ADD COLUMN `attraction_id` BIGINT UNSIGNED DEFAULT NULL AFTER `visit_id`;

ALTER TABLE `{prefix}survey_responses`
    ADD COLUMN `host_user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `attraction_id`;

-- The invitation sweep's own query: unanswered rows for a survey, oldest
-- first. Without this it is a scan of every response ever recorded, once a
-- minute, forever.
ALTER TABLE `{prefix}survey_responses`
    ADD KEY `ix_survey_responses_pending` (`survey_id`, `responded_at`, `invited_at`);

-- The trend reads by the day a guest ANSWERED.
ALTER TABLE `{prefix}survey_responses`
    ADD KEY `ix_survey_responses_responded` (`responded_at`, `sentiment`);

ALTER TABLE `{prefix}survey_responses`
    ADD KEY `ix_survey_responses_host` (`host_user_id`, `responded_at`);

-- A visit can be surveyed as well as a booking, and the sweep needs to know
-- whether this visit already has an invitation.
ALTER TABLE `{prefix}survey_responses`
    ADD KEY `ix_survey_responses_visit` (`visit_id`);
