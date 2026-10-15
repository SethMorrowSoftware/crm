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
        'contact.birthday_soon'    => "A contact's birthday is approaching",
        'contact.lapsed'           => 'A contact has not visited for the lapse window',

        // Children (guardian-addressed; see the compliance note in the spec)
        'child.birthday_soon'      => "A child's birthday is approaching",

        // Deals
        'deal.created'             => 'A deal is created',
        'deal.stage_changed'       => 'A deal moves to another stage',
        'deal.won'                 => 'A deal is won',
        'deal.lost'                => 'A deal is lost',
        'deal.sla_breached'        => "A deal passes its stage's response deadline",
        'deal.idle'                => 'A deal has had no activity for N days',

        // Quotes
        'quote.sent'               => 'A quote is sent',
        'quote.viewed'             => 'A guest opens their quote',
        'quote.accepted'           => 'A guest accepts a quote',

        // Bookings
        'booking.created'          => 'A booking is created',
        'booking.held'             => 'A date is held pending deposit',
        'booking.confirmed'        => 'A booking is confirmed',
        'booking.deposit_paid'     => 'A deposit is received',
        'booking.paid_in_full'     => 'A booking is paid in full',
        'booking.upcoming'         => 'A booking is N days or hours away',
        'booking.completed'        => 'A booking is completed',
        'booking.cancelled'        => 'A booking is cancelled',
        'booking.rescheduled'      => 'A booking is rescheduled',
        'booking.no_show'          => 'A booking is marked a no-show',
        'booking.waiver_outstanding' => 'Waivers are still missing close to the event',

        // Visits
        'visit.recorded'           => 'A visit is recorded',
        'visit.first'              => 'A guest visits for the first time',

        // Memberships
        'membership.started'       => 'A membership or pass starts',
        'membership.expiring'      => 'A membership is approaching expiry',
        'membership.expired'       => 'A membership expires',
        'membership.lapsed'        => 'A membership passes the lapse window',
        'membership.usage_idle'    => 'A pass has gone unused for N weeks',
        'membership.cancelled'     => 'A membership is cancelled',

        // Communication
        'message.received'         => 'An inbound message arrives',
        'message.sent'             => 'An outbound message is sent',
        'message.opened'           => 'An email is opened',
        'message.clicked'          => 'A link in a message is clicked',
        'message.bounced'          => 'An email bounces',
        'message.failed'           => 'A message fails to send',
        'sms.keyword_received'     => 'An SMS keyword is received',
        'conversation.unanswered'  => 'A conversation has gone unanswered for N hours',

        // Forms & feedback
        'form.submitted'           => 'A web form is submitted',
        'survey.responded'         => 'A guest answers a survey',
        'survey.detractor'         => 'A guest gives a low score',
        'review.received'          => 'A review is received',

        // Commerce
        'coupon.redeemed'          => 'A coupon is redeemed',
        'referral.converted'       => 'A referral results in a booking',

        // Fundraising
        'donation.requested'       => 'A donation request is received',
        'donation.approved'        => 'A donation is approved',
        'certificate.expiring'     => 'A donation certificate is close to expiry',

        // Tasks
        'task.created'             => 'A task is created',
        'task.completed'           => 'A task is completed',
        'task.overdue'             => 'A task passes its due date',
    ];

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
