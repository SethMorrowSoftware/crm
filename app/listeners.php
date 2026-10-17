<?php

declare(strict_types=1);

/**
 * What listens to what.
 *
 * `Core\Event` is the seam between the modules and the automation engine, and
 * this file is the whole of the wiring. The point of keeping it in one place
 * is the rule the Event class states: modules FIRE events and never call the
 * engine, so an operator can attach an automation to any business moment
 * without a code change — and a reader can see every connection at once
 * rather than grepping for listeners.
 *
 * Two listeners, not sixty. `AutomationRunner::onEvent()` looks up which
 * automations are listening for the event it was handed, so one wildcard
 * listener covers every trigger in the catalogue and a new trigger type needs
 * no entry here.
 *
 * Nothing registered here may throw usefully: `Event::fire()` catches and
 * logs, so a broken automation cannot stop a contact being saved. That is
 * deliberate, and it is also why a failed RUN is audited separately — see
 * `AutomationRunner::fail()`.
 */

use App\Core\Event;
use App\Services\AutomationRunner;
use App\Services\MembershipLifecycle;
use App\Services\WebhookDispatcher;

/*
 * ENROLMENT. Every event in the catalogue can start an automation, so this is
 * registered as a wildcard rather than enumerated: the alternative is a list
 * that drifts out of step with `Event::CATALOG` and silently stops enrolling
 * for whichever trigger somebody forgot to add.
 *
 * `onEvent()` returns immediately when no active automation is listening,
 * which is the usual case — one indexed read per event.
 */
Event::listen('*', static function (array $payload, string $event): void {
    // The engine's own events are not triggers. An automation that enrolled
    // on `automation.failed` could fail, enrol, fail again, and do it for
    // ever at one per cron tick.
    if (str_starts_with($event, 'automation.')) {
        return;
    }

    AutomationRunner::onEvent($event, $payload);
});

/*
 * GOALS. Separate from enrolment because the same event means two different
 * things: `deal.won` may START one automation and END another. Reaching a
 * goal records the conversion and exits the run, which is what gives every
 * automation a measurable conversion rate instead of a send count.
 */
Event::listen('*', static function (array $payload, string $event): void {
    if (str_starts_with($event, 'automation.')) {
        return;
    }

    AutomationRunner::onGoalEvent($event, $payload);
});

/*
 * MEMBERSHIP USAGE. The one place a season pass learns it was used.
 *
 * Spec §8.10 wants `membership_usage` rows derived from visits, and the event
 * seam is how a module hears about another module's business without either
 * knowing the other exists: `VisitRecorder` fires, this records. The
 * alternative — VisitRecorder calling the membership module directly — would
 * make "record a visit" mean "and also these four other things", which is how
 * a check-in ends up taking two seconds.
 *
 * Everything that makes it safe lives in the module: one use per membership
 * per day (a family checked in one child at a time is one visit), only live
 * memberships, and family members counted through `membership_members` rather
 * than through the holder.
 */
Event::listen('visit.recorded', static function (array $payload): void {
    MembershipLifecycle::recordVisitUsage($payload);
});

/*
 * OUTBOUND WEBHOOKS. A wildcard, for the same reason enrolment is one: a
 * venue can subscribe to anything in the catalogue, and an enumerated list
 * would quietly stop delivering whichever event somebody forgot to add.
 *
 * IT ONLY QUEUES. Nothing is sent inside the request that fired the event:
 * a booking saved at the front desk must not wait on somebody's Zapier
 * endpoint, and if it did, a dead endpoint would make the save appear to
 * hang — which is how a member of staff ends up clicking Save three times
 * and creating three bookings. Cron sends what this queues.
 *
 * The engine's own events are excluded like everywhere else: an automation
 * that fails fires `automation.failed`, and a subscription to everything
 * would turn one failure into a delivery, every tick, for ever.
 */
Event::listen('*', static function (array $payload, string $event): void {
    if (str_starts_with($event, 'automation.') || str_starts_with($event, 'webhook.')) {
        return;
    }

    WebhookDispatcher::queue($event, $payload);
});
