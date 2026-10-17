-- Castle CRM — 011 what outbound webhooks needed that 001 did not have
--
-- A NEW FILE RATHER THAN AN EDIT TO 001, for the reason 009 and 010 state:
-- the migration runner records which FILES it has applied, so a change edited
-- into an earlier file would reach a fresh install and nobody who had already
-- installed. Every statement here is safe to re-run — the runner tolerates
-- "that column is already there" (1060) and "that key exists" (1061).

-- A NAME, so a venue can tell three subscriptions apart.
--
-- `webhooks` originally held only the event and the URL, which is enough to
-- deliver and not enough to manage: three rows pointing at three different
-- Zapier hooks are three identical-looking lines, and the one somebody wants
-- to switch off cannot be identified from the page.
ALTER TABLE `{prefix}webhooks`
    ADD COLUMN `name` VARCHAR(120) NOT NULL DEFAULT '' AFTER `id`;

-- WHO MADE IT, and when it last did anything. Both are what a venue asks
-- first when they find a subscription nobody remembers creating.
ALTER TABLE `{prefix}webhooks`
    ADD COLUMN `created_by` BIGINT UNSIGNED DEFAULT NULL AFTER `is_active`;

ALTER TABLE `{prefix}webhooks`
    ADD COLUMN `last_delivery_at` DATETIME DEFAULT NULL AFTER `created_by`;

-- CONSECUTIVE FAILURES, so a subscription that has been dead for a month can
-- be switched off automatically rather than retried for ever against an
-- endpoint that went away when somebody deleted a Zap.
ALTER TABLE `{prefix}webhooks`
    ADD COLUMN `consecutive_failures` SMALLINT NOT NULL DEFAULT 0 AFTER `last_delivery_at`;

ALTER TABLE `{prefix}webhooks`
    ADD COLUMN `disabled_reason` VARCHAR(255) DEFAULT NULL AFTER `consecutive_failures`;

-- ------------------------------------------------------------------------
-- The delivery queue
-- ------------------------------------------------------------------------
--
-- `webhook_deliveries` was a LOG: payload, response code, attempts, delivered
-- time. A log cannot be a queue, and the difference is the whole feature —
-- without a status and a next-attempt time there is no way to ask "what is
-- due?", so a failed delivery is a row nobody ever looks at again rather than
-- something that retries.

-- The event this delivery is about. Already on the subscription, repeated
-- here because a subscription can be edited or deleted and the log has to
-- stay readable afterwards.
ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `event` VARCHAR(64) NOT NULL DEFAULT '' AFTER `webhook_id`;

ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `status` ENUM('pending','delivered','failed','gave_up')
        NOT NULL DEFAULT 'pending' AFTER `event`;

-- When to try again. NULL means never — delivered, or given up on.
ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `next_attempt_at` DATETIME DEFAULT NULL AFTER `attempts`;

ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `last_attempt_at` DATETIME DEFAULT NULL AFTER `next_attempt_at`;

-- WHAT WENT WRONG, in words. A response code alone does not distinguish
-- "connection refused" from "DNS does not resolve" from "the URL points
-- inside the network" — and those three send a venue to three different
-- places.
ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `error` VARCHAR(500) DEFAULT NULL AFTER `response_code`;

-- The first few hundred bytes of the reply. Enough for an endpoint that
-- answers 400 with a reason; never enough to be a place somebody's HTML is
-- stored and rendered back to a manager.
ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `response_body` VARCHAR(500) DEFAULT NULL AFTER `error`;

ALTER TABLE `{prefix}webhook_deliveries`
    ADD COLUMN `duration_ms` INT DEFAULT NULL AFTER `response_body`;

-- THE QUEUE'S OWN INDEX. Cron asks "what is pending and due?" every tick, and
-- without this that is a full scan of every delivery ever made.
ALTER TABLE `{prefix}webhook_deliveries`
    ADD KEY `ix_webhook_deliveries_due` (`status`, `next_attempt_at`);

-- And the log's: "show me this subscription's recent deliveries", newest
-- first, which is what the page draws.
ALTER TABLE `{prefix}webhook_deliveries`
    ADD KEY `ix_webhook_deliveries_recent` (`webhook_id`, `id`);
