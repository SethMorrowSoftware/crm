<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * The integration seam between modules and the automation engine.
 *
 * Modules fire events; they never call the automation engine directly. That
 * one rule is what lets Phase 5 add the engine without touching Phase 2's
 * controllers, and lets an operator add an automation to any business moment
 * without a code change.
 *
 * Events are fired synchronously to registered listeners, but anything slow —
 * sending a message, running an automation — is a listener that enqueues a job
 * rather than doing the work inline. A guest saving a contact should not wait
 * for an email to send.
 */
final class Event
{
    /** @var array<string, list<callable(array<string,mixed>, string):void>> */
    private static array $listeners = [];

    /** @var list<array{event:string,payload:array<string,mixed>,at:string}> */
    private static array $fired = [];

    private static bool $recording = false;

    /**
     * The events the application fires, as a catalogue. Keeping this list
     * explicit means the automation builder can offer real triggers instead of
     * asking an operator to type an event name.
     *
     * EVENTS ONLY — something in the application reaches this point and says
     * so. The triggers that become true as time passes ("the party is in
     * fourteen days", "a task is overdue") are not events and are not here:
     * they live in `AutomationTriggers::SCANNED` beside the queries that find
     * them. They used to be in both lists, which meant two labels for one
     * trigger and no way to tell, from this file, which half of the engine
     * answered a given automation. `AutomationTriggers::catalogue()` is what
     * the builder offers, and it is the union.
     *
     * @var array<string,string>
     */
    public const CATALOG = [
        // Contacts
        'contact.created'          => 'A contact is created',
        'contact.updated'          => 'A contact is updated',
        'contact.tagged'           => 'A tag is added to a contact',
        'contact.untagged'         => 'A tag is removed from a contact',
        'contact.lifecycle_changed' => "A contact's lifecycle stage changes",
        'contact.consent_granted'  => 'A contact grants email or SMS consent',
        'contact.consent_revoked'  => 'A contact revokes consent or replies STOP',

        // Deals
        'deal.created'             => 'A deal is created',
        'deal.stage_changed'       => 'A deal moves to another stage',
        'deal.won'                 => 'A deal is won',
        'deal.lost'                => 'A deal is lost',

        // Quotes
        'quote.sent'               => 'A quote is sent',
        'quote.viewed'             => 'A guest opens their quote',
        'quote.accepted'           => 'A guest accepts a quote',

        // Bookings
        'booking.enquiry_received' => 'A party enquiry arrives from the website',
        'booking.created'          => 'A booking is created',
        'booking.held'             => 'A date is held pending deposit',
        'booking.confirmed'        => 'A booking is confirmed',
        'booking.deposit_paid'     => 'A deposit is received',
        'booking.paid_in_full'     => 'A booking is paid in full',
        'booking.payment_recorded' => 'Any payment is recorded against a booking',
        'booking.completed'        => 'A booking is completed',
        'booking.cancelled'        => 'A booking is cancelled',
        'booking.rescheduled'      => 'A booking is rescheduled',
        'booking.no_show'          => 'A booking is marked a no-show',
        'waiver.signed'            => 'A waiver is signed',
        'guest.checked_in'         => 'A guest is checked in at the desk',

        // Visits
        'visit.recorded'           => 'A visit is recorded',
        'visit.first'              => 'A guest visits for the first time',

        // Memberships
        'membership.started'       => 'A membership or pass starts',
        'membership.expired'       => 'A membership expires',
        'membership.cancelled'     => 'A membership is cancelled',

        // Communication
        'message.received'         => 'An inbound message arrives',
        'message.sent'             => 'An outbound message is sent',
        'message.opened'           => 'An email is opened',
        'message.clicked'          => 'A link in a message is clicked',
        'message.bounced'          => 'An email bounces',
        'message.failed'           => 'A message fails to send',
        'sms.keyword_received'     => 'An SMS keyword is received',
        'conversation.replied'     => 'Somebody at the venue answers a conversation',
        'conversation.closed'      => 'A conversation is closed',

        // Text Club
        'text_club.confirmed'      => 'A guest confirms their Text Club opt-in',
        'text_club.join'           => 'A guest asks to join the Text Club',
        'text_club.stop'           => 'A guest leaves the Text Club',

        // Forms & feedback
        'form.submitted'           => 'A web form is submitted',
        'survey.responded'         => 'A guest answers a survey',
        'survey.detractor'         => 'A guest gives a low score',
        'review.received'          => 'A review is received',
        'feedback.resolved'        => 'A piece of guest feedback is resolved',

        // Commerce
        'coupon.issued'            => 'A coupon is issued to a guest',
        'coupon.redeemed'          => 'A coupon is redeemed',
        'referral.converted'       => 'A referral results in a booking',

        // Online ticketing
        'ticket.purchased'         => 'A ticket is bought online',
        'ticket.abandoned'         => 'A ticket checkout is abandoned',

        // Fundraising
        'donation.requested'       => 'A donation request is received',
        'donation.approved'        => 'A donation is approved',
        'donation.declined'        => 'A donation request is declined',

        // Tasks
        'task.created'             => 'A task is created',
        'task.completed'           => 'A task is completed',
    ];

    /**
     * Events this version NAMES but does not yet FIRE, and what will.
     *
     * The catalogue above is the application's event vocabulary, and the
     * automation builder offers it — so an entry nothing fires is a trigger
     * somebody can choose and that will never happen. A venue switches the
     * automation on, waits, and nothing occurs; the automation is working
     * exactly as written and nothing in the UI or the log could say so.
     *
     * Rather than leave that to be discovered, the gap is listed. The
     * automation pages read this to say "waiting on a feature that is not
     * built yet" instead of offering a switch that does nothing, and
     * `integration/schema` asserts that every seeded automation's trigger is
     * either deliverable or named here — so a typo is a build failure and a
     * genuine gap is a documented one.
     *
     * @var array<string,string>
     */
    public const PENDING = [
        'membership.started'   => 'the memberships module',
        'membership.expired'   => 'the memberships module',
        'membership.cancelled' => 'the memberships module',
        'coupon.redeemed'      => 'coupon redemption at the till',
        'referral.converted'   => 'the referral module',
        'donation.requested'   => 'the fundraising module',
        'donation.approved'    => 'the fundraising module',
        'donation.declined'    => 'the fundraising module',
        'ticket.purchased'     => 'online ticketing',
        'ticket.abandoned'     => 'online ticketing',
        'message.opened'       => 'email open tracking',
        'message.clicked'      => 'email click tracking',
        'message.bounced'      => "the mail provider's delivery webhook",
    ];

    /**
     * Events the application fires that are NOT triggers.
     *
     * The automation builder must not offer these, and a gate asserts that
     * every event fired anywhere is either in the catalogue or here — so a
     * new business moment is a catalogue entry or a deliberate exclusion,
     * never an oversight.
     *
     * Each is excluded for a reason, not because nobody got round to it:
     * `campaign.*` describes the venue's own sending rather than a guest's
     * behaviour, and an automation that sent on it would send once per
     * campaign to whoever happened to be the subject; `waiver.published` is a
     * staff admin action; `automation.*` would let the engine trigger itself
     * (see the guard in `app/listeners.php`).
     *
     * @var array<string,string>
     */
    public const INTERNAL = [
        'campaign.started'   => 'the venue starts sending a campaign',
        'campaign.completed' => 'a campaign finishes sending',
        'waiver.published'   => 'a new waiver version is published',
        'automation.failed'  => 'an automation run fails',
    ];

    /** Can an automation on this trigger actually happen yet? */
    public static function isDeliverable(string $event): bool
    {
        return isset(self::CATALOG[$event]) && !isset(self::PENDING[$event]);
    }

    /**
     * What a trigger is waiting on, in words for the automation pages, or
     * null when it is deliverable.
     */
    public static function pendingOn(string $event): ?string
    {
        return self::PENDING[$event] ?? null;
    }

    /**
     * @param callable(array<string,mixed>, string):void $listener
     */
    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Fire an event.
     *
     * A listener that throws is logged and skipped: one broken automation must
     * not prevent a contact from being saved, and must not take down the other
     * listeners on the same event.
     *
     * @param array<string,mixed> $payload
     */
    public static function fire(string $event, array $payload = []): void
    {
        if (self::$recording) {
            self::$fired[] = [
                'event'   => $event,
                'payload' => $payload,
                'at'      => gmdate('Y-m-d H:i:s'),
            ];
        }

        // Exact listeners, then wildcard listeners ("deal.*", then "*").
        $candidates = self::$listeners[$event] ?? [];

        $namespace = strtok($event, '.');

        if ($namespace !== false && isset(self::$listeners[$namespace . '.*'])) {
            $candidates = array_merge($candidates, self::$listeners[$namespace . '.*']);
        }

        if (isset(self::$listeners['*'])) {
            $candidates = array_merge($candidates, self::$listeners['*']);
        }

        foreach ($candidates as $listener) {
            try {
                $listener($payload, $event);
            } catch (Throwable $e) {
                Log::error('Event listener failed', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /** Record fired events, for tests and the automation test-mode preview. */
    public static function record(bool $on = true): void
    {
        self::$recording = $on;

        if ($on) {
            self::$fired = [];
        }
    }

    /** @return list<array{event:string,payload:array<string,mixed>,at:string}> */
    public static function recorded(): array
    {
        return self::$fired;
    }

    /** Remove all listeners — used between test cases. */
    public static function flush(): void
    {
        self::$listeners = [];
        self::$fired = [];
    }

    /** @return array<string,string> */
    public static function catalogByGroup(): array
    {
        return self::CATALOG;
    }
}
