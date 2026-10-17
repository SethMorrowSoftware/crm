<?php

declare(strict_types=1);

/**
 * Seeds the 8 Revenue Playbooks and their automation library.
 *
 * Every automation ships INACTIVE and every playbook ships DEACTIVATED.
 * Activation is always a deliberate human act, and Safe Mode still applies on
 * top of that — a fresh install cannot message a guest even by accident.
 *
 * @return array{playbooks:int,automations:int,steps:int,offers:int,segments:int}
 */

use App\Core\DB;

return static function (): array {
    $now = gmdate('Y-m-d H:i:s');

    /**
     * Each playbook: number, name, slug, tagline, revenue moment, what arrives
     * ready, what the venue tailors, and its automations.
     *
     * An automation is [name, trigger_type, description], where trigger_type is
     * an Event::CATALOG key, 'cron', or 'manual'. Step trees are built by the
     * Phase 5 engine; this seeds the registry the builder opens.
     */
    $playbooks = [
        [
            'number'  => 1,
            'name'    => 'Birthday & Celebration Sales',
            'slug'    => 'birthday-celebration-sales',
            'icon'    => 'cake',
            'tagline' => 'Turn every party inquiry into a booked party.',
            'moment'  => 'A parent asks about a birthday party.',
            'ready'   => 'A 7-stage pipeline with response deadlines, the party inquiry form, '
                . 'a quote template priced from your own packages, a 9-touch nurture sequence, '
                . 'deposit chasing, the pre-party readiness checklist, and an annual rebook nudge '
                . 'keyed to each child\'s birthday.',
            'tailor'  => 'Your package prices and what each includes, your deposit and release '
                . 'policy, the voice of your messages, and who owns a new inquiry.',
            /*
             * THE STEPS ARE AUTHORED HERE, and the fourth element of each
             * entry is the sequence. Everything in this playbook can be
             * switched on as it stands: every template it names is one the
             * installer seeds, and every delay is tied to the party's own
             * date rather than to a fixed wait, so a reminder lands fourteen
             * days before THAT party.
             *
             * Still inactive, and Safe Mode still applies on top. What
             * shipping the steps changes is that an operator can read what a
             * sequence will send, preview it against a real guest, and switch
             * it on — rather than opening an automation that says "no steps".
             */
            'automations' => [
                [
                    'Instant inquiry response',
                    'form.submitted',
                    'Email and text the parent within two minutes, and create a task for the owner with a two-hour deadline.',
                    [
                        ['type' => 'send_email', 'template' => 'party-inquiry-received', 'kind' => 'transactional'],
                        ['type' => 'send_sms', 'template' => 'party-inquiry-sms', 'kind' => 'transactional'],
                        ['type' => 'create_task', 'title' => 'Call {{first_name}} about the party enquiry',
                         'description' => 'They enquired through the website. Ring them while it is warm.',
                         'due_in_hours' => 2, 'priority' => 'high'],
                    ],
                    ['goal' => 'quote.accepted'],
                ],
                [
                    'Assign new party lead',
                    'deal.created',
                    'Notify the owner about a new birthday lead and create the claim task.',
                    [
                        ['type' => 'notify_user', 'message' => 'New party lead: {{full_name}}. Claim it and call them.',
                         'title' => 'New party lead'],
                        ['type' => 'create_task', 'title' => 'Claim and call the lead: {{full_name}}',
                         'due_in_hours' => 4],
                    ],
                ],
                [
                    'Quote nurture — 9 touches',
                    'quote.sent',
                    'A twelve-day sequence of reminders, a call task and a final nudge. Exits the moment they book.',
                    [
                        ['type' => 'send_email', 'template' => 'party-quote', 'kind' => 'transactional'],
                        ['type' => 'send_sms', 'template' => 'quote-nudge-sms', 'wait' => 2880],
                        ['type' => 'create_task', 'title' => 'Call {{full_name}} — quote sent three days ago',
                         'wait' => 1440, 'due_in_hours' => 8],
                        ['type' => 'send_email', 'template' => 'party-quote', 'wait' => 4320],
                        ['type' => 'send_sms', 'template' => 'quote-nudge-sms', 'wait' => 4320],
                        // Day twelve: 0, 2, 3, 6, 9, 12. The description says
                        // twelve days and the arithmetic has to agree with it.
                        ['type' => 'create_task', 'title' => 'Last try on {{full_name}} before the date goes',
                         'wait' => 4320, 'due_in_hours' => 24],
                    ],
                    [
                        // The whole point of a nurture: it stops the moment
                        // the family books, so nobody is chased for something
                        // they have already done.
                        'exit' => ['match' => 'any', 'rules' => [
                            ['field' => 'has_booking', 'op' => 'is_true', 'value' => '1'],
                            ['field' => 'quotes_status', 'op' => 'is', 'value' => 'accepted'],
                        ]],
                        'goal' => 'quote.accepted',
                    ],
                ],
                [
                    'Quote viewed — strike while warm',
                    'quote.viewed',
                    'Alert the owner that the family just opened the quote, with a task to call now.',
                    [
                        ['type' => 'notify_user', 'title' => 'A quote was just opened',
                         'message' => '{{full_name}} is reading their quote right now. This is the moment to call.'],
                        ['type' => 'create_task', 'title' => 'Call {{full_name}} — reading the quote now',
                         'due_in_hours' => 1, 'priority' => 'high'],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'quote.accepted'],
                ],
                [
                    'Quote accepted — hold the date',
                    'quote.accepted',
                    'Move the deal to Date Held, send the deposit request, and task the host with creating the booking.',
                    [
                        ['type' => 'move_deal_stage', 'stage' => 'Date Held'],
                        ['type' => 'send_email', 'template' => 'deposit-request', 'kind' => 'transactional'],
                        ['type' => 'create_task', 'title' => 'Create the booking for {{full_name}}',
                         'description' => 'The quote is accepted and the deposit request has gone out.',
                         'due_in_hours' => 4, 'priority' => 'high'],
                    ],
                ],
                [
                    'Deposit chase',
                    'booking.held',
                    'Day 1 email, day 3 text, day 5 email, then day 7 release the date and tell the host.',
                    [
                        ['type' => 'send_email', 'template' => 'deposit-request', 'kind' => 'transactional',
                         'wait' => 1440],
                        ['type' => 'send_sms', 'template' => 'deposit-reminder-sms', 'kind' => 'transactional',
                         'wait' => 2880],
                        ['type' => 'send_email', 'template' => 'deposit-request', 'kind' => 'transactional',
                         'wait' => 2880],
                        ['type' => 'create_task', 'title' => 'Release the held date for {{full_name}}',
                         'description' => 'Seven days with no deposit. Release it or give them one more day.',
                         'wait' => 2880, 'due_in_hours' => 4, 'priority' => 'high'],
                        ['type' => 'notify_user', 'title' => 'A held date is up for release',
                         'message' => 'No deposit from {{full_name}} after a week. The release task is on the list.'],
                    ],
                    [
                        // Paid is paid: the stamp on the booking is what the
                        // money ladder sets, so the chase stops without
                        // waiting for anybody to switch it off.
                        'exit' => ['match' => 'any', 'rules' => [
                            ['field' => 'bookings_deposit_paid_at', 'op' => 'is_set', 'value' => '1'],
                            ['field' => 'bookings_status', 'op' => 'in', 'value' => 'cancelled,no_show'],
                        ]],
                        'goal' => 'booking.deposit_paid',
                    ],
                ],
                [
                    'Deposit received — confirm',
                    'booking.deposit_paid',
                    'Send the confirmation with directions, parking and what to bring.',
                    [
                        ['type' => 'send_email', 'template' => 'booking-confirmation', 'kind' => 'transactional'],
                    ],
                ],
                [
                    'Pre-party readiness — T-14 waivers',
                    'booking.upcoming',
                    'Send the waiver link to the host parent fourteen days out.',
                    [
                        ['type' => 'send_email', 'template' => 'waiver-reminder', 'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 14, 'at' => '10:00']],
                    ],
                    ['when' => ['days' => 14]],
                ],
                [
                    'Pre-party readiness — T-7 headcount',
                    'booking.upcoming',
                    'Ask the parent to confirm the final headcount a week out.',
                    [
                        ['type' => 'send_sms', 'template' => 'headcount-sms', 'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 7, 'at' => '11:00']],
                    ],
                    ['when' => ['days' => 7]],
                ],
                [
                    'Pre-party readiness — T-3 details',
                    'booking.upcoming',
                    'Send the party plan three days out and task the host with checking allergies and the cake.',
                    [
                        ['type' => 'send_email', 'template' => 'booking-confirmation', 'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 3, 'at' => '10:00']],
                        ['type' => 'create_task', 'title' => 'Check allergies and cake for {{full_name}}',
                         'due_in_hours' => 24],
                    ],
                    ['when' => ['days' => 3]],
                ],
                [
                    'Pre-party reminder — T-1 text',
                    'booking.upcoming',
                    'A short text the day before with the arrival time.',
                    [
                        ['type' => 'send_sms', 'template' => 'party-tomorrow-sms', 'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 1, 'at' => '16:00']],
                    ],
                    ['when' => ['days' => 1]],
                ],
                [
                    'Waiver chase',
                    'booking.waiver_outstanding',
                    'Text the host parent about missing waivers, then task the front desk a day later.',
                    [
                        ['type' => 'send_sms', 'template' => 'waiver-chase-sms', 'kind' => 'transactional'],
                        ['type' => 'create_task', 'title' => 'Waivers still missing for {{full_name}}',
                         'description' => 'Catch them at the desk on arrival if they are still unsigned.',
                         'wait' => 1440, 'due_in_hours' => 24],
                    ],
                    ['when' => ['days' => 3]],
                ],
                [
                    'Day-of host brief',
                    'booking.upcoming',
                    'Tell the host their party is today, on the morning of it.',
                    [
                        ['type' => 'notify_user', 'title' => 'A party today',
                         'message' => 'Party today for {{full_name}}. The run sheet is on the booking.',
                         'before' => ['field' => 'event_date', 'days' => 0, 'at' => '08:00']],
                    ],
                    ['when' => ['days' => 0]],
                ],
                [
                    'Party completed — thank you',
                    'booking.completed',
                    'Thank the parent the same evening and task the host with asking for a review.',
                    [
                        ['type' => 'send_email', 'template' => 'party-thank-you', 'wait' => 180],
                        ['type' => 'create_task', 'title' => 'Ask {{full_name}} for a review',
                         'wait' => 1440, 'due_in_hours' => 48],
                    ],
                ],
                [
                    'Annual rebook — child birthday',
                    'child.birthday_soon',
                    'Forty-five days before a child\'s birthday, email the guardian about booking again.',
                    [
                        ['type' => 'send_email', 'template' => 'birthday-club-invite'],
                        ['type' => 'create_task', 'title' => 'Follow up the rebook offer to {{full_name}}',
                         'wait' => 10080, 'due_in_hours' => 48],
                    ],
                    ['when' => ['days' => 45], 'once' => 0, 'reentry' => 1, 'goal' => 'booking.created'],
                ],
                [
                    'Sibling birthday cross-sell',
                    'child.birthday_soon',
                    'Sixty days out, offer the same package for the next child in the household.',
                    [
                        ['type' => 'send_email', 'template' => 'birthday-club-invite'],
                    ],
                    ['when' => ['days' => 60], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Cancelled party — win it back',
                    'booking.cancelled',
                    'Task the host to call, then offer a new date after a week.',
                    [
                        ['type' => 'create_task', 'title' => 'Call {{full_name}} about the cancelled party',
                         'due_in_hours' => 24],
                        ['type' => 'send_email', 'template' => 'lapsed-guest-winback', 'wait' => 10080],
                    ],
                    ['goal' => 'booking.created'],
                ],
                [
                    'No-show follow-up',
                    'booking.no_show',
                    'Task the host to call, and put the deposit decision in front of a manager.',
                    [
                        ['type' => 'create_task', 'title' => 'Call {{full_name}} — they did not arrive',
                         'due_in_hours' => 8, 'priority' => 'high'],
                        ['type' => 'notify_user', 'title' => 'A no-show needs a decision',
                         'message' => '{{full_name}} did not arrive. Decide what happens to the deposit.'],
                    ],
                ],
                [
                    'Lost lead — 90 day re-approach',
                    'deal.lost',
                    'Three months after a lost birthday lead, try once more.',
                    [
                        ['type' => 'send_email', 'template' => 'lapsed-guest-winback', 'wait' => 129600],
                    ],
                    ['goal' => 'deal.won', 'goal_hours' => 1440],
                ],
                [
                    'Past party host — tag the family',
                    'booking.completed',
                    'Tag the family as a past party host, so next year\'s offer and the segments find them.',
                    [
                        ['type' => 'add_tag', 'tag' => 'party host'],
                    ],
                ],
                [
                    'Party room upsell',
                    'booking.confirmed',
                    'Two weeks out, task the host to offer the add-ons the family did not take.',
                    [
                        ['type' => 'create_task', 'title' => 'Offer add-ons to {{full_name}}',
                         'description' => 'Pizza, party bags, extra time. Check what they did not take.',
                         'before' => ['field' => 'event_date', 'days' => 14, 'at' => '09:00'],
                         'due_in_hours' => 48],
                    ],
                ],
                [
                    'Website booking request — instant response',
                    'booking.enquiry_received',
                    'The same instant reply for a request that comes through the booking page rather than the enquiry form.',
                    [
                        ['type' => 'send_email', 'template' => 'party-inquiry-received',
                         'kind' => 'transactional'],
                        ['type' => 'create_task', 'title' => 'Confirm the requested date for {{full_name}}',
                         'description' => 'They picked a date and time on the website. Check it is '
                            . 'really free, then call them.',
                         'due_in_hours' => 2, 'priority' => 'high'],
                    ],
                    ['goal' => 'booking.deposit_paid'],
                ],
                [
                    'Large party VIP handling',
                    'booking.confirmed',
                    'For a party above twenty children, assign a manager and book a planning call.',
                    [
                        ['type' => 'condition', 'field' => 'bookings_guest_count', 'op' => 'gte', 'value' => '20'],
                        ['type' => 'notify_user', 'branch' => 'yes', 'title' => 'A large party is booked',
                         'message' => '{{full_name}} has booked for {{bookings_guest_count}}. Assign a manager.'],
                        ['type' => 'create_task', 'branch' => 'yes',
                         'title' => 'Planning call for the large party: {{full_name}}',
                         'due_in_hours' => 48, 'priority' => 'high'],
                    ],
                ],
            ],
        ],
        [
            'number'  => 2,
            'name'    => 'Group & Corporate Events',
            'slug'    => 'group-corporate-events',
            'icon'    => 'users',
            'tagline' => 'Win the school trip, the camp and the company picnic.',
            'moment'  => 'A school, camp, corporate planner or Event Center enquiry arrives.',
            'ready'   => 'An 8-stage group pipeline, the field-trip and corporate forms, a proposal '
                . 'builder, headcount firming at three intervals, PO and invoice tracking, and an '
                . 'annual recurrence nudge for groups that come back every year.',
            'tailor'  => 'Your group rates and minimums, your Event Center packages, your deposit '
                . 'terms, and which schools and companies matter most.',
            /*
             * A GROUP BUYER IS NOT A PARENT, and every delay in here says so.
             * A parent decides over a weekend; a teacher decides at a staff
             * meeting, a camp director in February for August, and a company
             * in September for December. So the proposal ladder runs over
             * three weeks rather than twelve days, and the headcount is asked
             * for three times with the deadline named — because the kitchen
             * and the staffing are set from the last number given.
             *
             * The two cron sequences have no segment set, so they notify the
             * venue rather than messaging anybody: "reach out to every camp
             * that visited last summer" is a list somebody has to look at,
             * and the automation's job is to make sure they look at it in
             * February rather than in May.
             */
            'automations' => [
                [
                    'Instant group inquiry response',
                    'form.submitted',
                    'Acknowledge within minutes with the group information, and task the owner to call.',
                    [
                        ['type' => 'send_email', 'template' => 'group-event-followup',
                         'kind' => 'transactional'],
                        ['type' => 'create_task', 'title' => 'Call {{full_name}} about the group visit',
                         'description' => 'Group enquiry from the website. Ask dates, numbers and budget.',
                         'due_in_hours' => 4, 'priority' => 'high'],
                    ],
                    ['goal' => 'quote.accepted'],
                ],
                [
                    'Qualify the group',
                    'deal.created',
                    'Create the qualification task: headcount, date flexibility, budget and who signs.',
                    [
                        ['type' => 'create_task', 'title' => 'Qualify: {{title}}',
                         'description' => 'How many? Which dates can move? What is the budget, '
                            . 'and who has to approve it?',
                         'due_in_hours' => 24],
                    ],
                ],
                [
                    'Proposal follow-up ladder',
                    'quote.sent',
                    'A twenty-one day ladder pitched at a planning cycle, not a weekend. Stops when they book.',
                    [
                        ['type' => 'send_email', 'template' => 'group-proposal-followup',
                         'kind' => 'transactional', 'wait' => 5760],
                        ['type' => 'create_task', 'title' => 'Call {{full_name}} about the proposal',
                         'wait' => 5760, 'due_in_hours' => 24],
                        ['type' => 'send_email', 'template' => 'group-proposal-followup', 'wait' => 10080],
                        ['type' => 'create_task', 'title' => 'Last check on the proposal to {{full_name}}',
                         'description' => 'Three weeks. Either it is live or the date goes back in the diary.',
                         'wait' => 10080, 'due_in_hours' => 48],
                    ],
                    [
                        'exit' => ['match' => 'any', 'rules' => [
                            ['field' => 'has_booking', 'op' => 'is_true', 'value' => '1'],
                            ['field' => 'quotes_status', 'op' => 'is', 'value' => 'accepted'],
                        ]],
                        'goal' => 'quote.accepted',
                    ],
                ],
                [
                    'Site visit booked — prepare',
                    'deal.stage_changed',
                    'Task the host to send directions and an agenda, and to walk the route first.',
                    [
                        ['type' => 'create_task', 'title' => 'Prepare the site visit for {{full_name}}',
                         'description' => 'Send directions and an agenda. Walk the route they will '
                            . 'walk, and know which rooms are free that day.',
                         'due_in_hours' => 24],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Contract chase',
                    'deal.stage_changed',
                    'Chase the signed contract or PO at day 3, 7 and 14 after a verbal commitment.',
                    [
                        ['type' => 'create_task', 'title' => 'Chase the contract: {{title}}',
                         'wait' => 4320, 'due_in_hours' => 24],
                        ['type' => 'create_task', 'title' => 'Chase the contract again: {{title}}',
                         'wait' => 5760, 'due_in_hours' => 24],
                        ['type' => 'notify_user', 'title' => 'A contract is two weeks overdue',
                         'message' => 'Still nothing signed for {{title}}. Decide whether the date '
                            . 'stays held.', 'wait' => 10080],
                    ],
                    [
                        'exit' => ['match' => 'any', 'rules' => [
                            ['field' => 'has_booking', 'op' => 'is_true', 'value' => '1'],
                            ['field' => 'deals_status', 'op' => 'in', 'value' => 'won,lost,abandoned'],
                        ]],
                        'once' => 0,
                        'reentry' => 1,
                    ],
                ],
                [
                    'Headcount firming — T-21',
                    'booking.upcoming',
                    'The first headcount request, three weeks out, asking only for an estimate.',
                    [
                        ['type' => 'send_email', 'template' => 'group-headcount-request',
                         'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 21, 'at' => '09:00']],
                    ],
                    ['when' => ['days' => 21]],
                ],
                [
                    'Headcount firming — T-10',
                    'booking.upcoming',
                    'The second request, ten days out, with the catering deadline named.',
                    [
                        ['type' => 'send_email', 'template' => 'group-headcount-request',
                         'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 10, 'at' => '09:00']],
                    ],
                    ['when' => ['days' => 10]],
                ],
                [
                    'Headcount firming — T-3',
                    'booking.upcoming',
                    'Final numbers three days out, escalating to a phone task the same day.',
                    [
                        ['type' => 'send_email', 'template' => 'group-headcount-request',
                         'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 3, 'at' => '09:00']],
                        ['type' => 'create_task', 'title' => 'Ring {{full_name}} for final numbers',
                         'description' => 'Three days out. The kitchen and the staffing are set from '
                            . 'whatever number we have tonight.',
                         'due_in_hours' => 6, 'priority' => 'high'],
                    ],
                    ['when' => ['days' => 3]],
                ],
                [
                    'Group waiver pack',
                    'booking.confirmed',
                    'Send the waiver link for the whole group as soon as the booking is confirmed.',
                    [
                        ['type' => 'send_email', 'template' => 'waiver-reminder', 'kind' => 'transactional'],
                        ['type' => 'create_task', 'title' => 'Check the waiver returns for {{full_name}}',
                         'wait' => 10080, 'due_in_hours' => 48],
                    ],
                ],
                [
                    'Chaperone brief',
                    'booking.upcoming',
                    'Two days out, the ratios, the rules and where the bus goes.',
                    [
                        ['type' => 'send_email', 'template' => 'group-chaperone-brief',
                         'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 2, 'at' => '10:00']],
                    ],
                    ['when' => ['days' => 2]],
                ],
                [
                    'Invoice issued',
                    'booking.confirmed',
                    'Task the office to raise the invoice or take the PO details.',
                    [
                        ['type' => 'create_task', 'title' => 'Invoice or PO for {{full_name}}',
                         'description' => 'Raise it, send it to whoever handles their paperwork, and '
                            . 'note the terms on the booking.',
                         'due_in_hours' => 48],
                    ],
                ],
                [
                    'Balance due reminder',
                    'booking.upcoming',
                    'A week before the event, remind the organiser of anything outstanding.',
                    [
                        ['type' => 'send_email', 'template' => 'group-balance-due', 'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 7, 'at' => '09:00']],
                    ],
                    ['when' => ['days' => 7]],
                ],
                [
                    'Post-event thank you and feedback',
                    'booking.completed',
                    'Thank the organiser the next morning and task the host to ask how it went.',
                    [
                        ['type' => 'send_email', 'template' => 'group-event-followup', 'wait' => 1080],
                        ['type' => 'create_task', 'title' => 'Ask {{full_name}} how the visit went',
                         'description' => 'One question: would you bring them again? The answer is '
                            . 'next year\'s booking.',
                         'wait' => 1440, 'due_in_hours' => 48],
                    ],
                ],
                [
                    'Annual recurrence — rebook the school',
                    'company.recurring_due',
                    'Eight months after the trip, email the organiser and task the owner to follow it up.',
                    [
                        ['type' => 'send_email', 'template' => 'group-annual-rebook'],
                        ['type' => 'create_task', 'title' => 'Follow up the rebook with {{full_name}}',
                         'wait' => 7200, 'due_in_hours' => 48],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'booking.created'],
                ],
                [
                    'Camp season planning',
                    'cron',
                    'Each February, a nudge to go through last summer\'s camps while the dates are open.',
                    [
                        ['type' => 'notify_user', 'title' => 'Camp season starts now',
                         'message' => 'Camps book their summer in February. Pull last year\'s camp '
                            . 'bookings and call them before somebody else does.'],
                    ],
                    ['when' => ['expression' => '34 8 3 2 *'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Corporate holiday party outreach',
                    'cron',
                    'Each September, a nudge to offer the Event Center for the holiday season.',
                    [
                        ['type' => 'notify_user', 'title' => 'Holiday party season',
                         'message' => 'Companies book their December party in September. The Event '
                            . 'Center diary is still empty — start with last year\'s list.'],
                    ],
                    ['when' => ['expression' => '47 8 2 9 *'], 'once' => 0, 'reentry' => 1],
                ],
            ],
        ],
        [
            'number'  => 3,
            'name'    => 'Visit & Ticketing Automation',
            'slug'    => 'visit-ticketing-automation',
            'icon'    => 'ticket',
            'tagline' => 'Make every visit arrive prepared and come back sooner.',
            'moment'  => 'A guest buys tickets, loads a Castle Card, or simply shows up.',
            'ready'   => 'First-time visitor onboarding, pre-visit preparation, a weather advisory '
                . 'for outdoor-dependent bookings, abandoned-cart recovery, and the lapsed-guest '
                . 'trigger that feeds the win-back ladders.',
            'tailor'  => 'What a first-time family should try first, your weather policy, and how '
                . 'long a guest is "away" before they count as lapsed.',
            /*
             * TWO OF THESE THIRTEEN SHIP WITH NO STEPS, ON PURPOSE.
             *
             * `ticket.purchased` and `ticket.abandoned` are in the event
             * catalogue but nothing fires them yet — online ticketing is
             * Phase 7. Authoring sequences behind a trigger that cannot fire
             * would put two automations in the library that read as finished
             * and do nothing, for ever, with no way to tell them from the
             * eleven that work. The automation page already says what each is
             * waiting on, and that is the honest answer until the module
             * exists.
             *
             * THE VISIT TRIGGERS ARE NOISY, and the sequences are written
             * around that. `visit.recorded` fires on EVERY visit, so anything
             * hanging off it either runs once per contact (the milestone) or
             * is cheap and idempotent (tagging). Only the first fires per
             * visit, which is what `once => 0` means on the one that must.
             */
            'automations' => [
                [
                    'First visit — welcome',
                    'visit.first',
                    'Day 1 thank you, day 3 "what to try next", day 10 a reason to return.',
                    [
                        ['type' => 'add_tag', 'tag' => 'first visit'],
                        ['type' => 'send_email', 'template' => 'first-visit-welcome', 'wait' => 1440],
                        ['type' => 'send_email', 'template' => 'first-visit-what-next', 'wait' => 2880],
                        ['type' => 'send_sms', 'template' => 'return-offer-sms', 'wait' => 10080],
                    ],
                    [
                        // It stops the moment they come back: the whole point
                        // of the ladder is a second visit, and the day-ten
                        // text is an apology for not having had one.
                        'goal' => 'visit.recorded', 'goal_hours' => 336,
                    ],
                ],
                [
                    'Returning guest — recognise them',
                    'visit.recorded',
                    'Tag frequency bands so later messages can speak to a regular differently.',
                    [
                        /*
                         * Tagging only, and deliberately nothing else: this
                         * runs on EVERY visit by every guest, so a message
                         * here would be a message every time somebody walked
                         * in. What it buys is that every other sequence —
                         * and every segment — can ask "is this a regular?"
                         * without counting visits itself.
                         */
                        ['type' => 'condition', 'field' => 'visit_count', 'op' => 'gte', 'value' => '10'],
                        ['type' => 'add_tag', 'tag' => 'regular', 'branch' => 'yes'],
                        ['type' => 'condition', 'field' => 'visit_count', 'op' => 'gte', 'value' => '3'],
                        ['type' => 'add_tag', 'tag' => 'returning', 'branch' => 'yes'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Pre-visit preparation',
                    'booking.upcoming',
                    'Height rules, socks for Ballocity, parking and what the Castle Card is.',
                    [
                        ['type' => 'send_email', 'template' => 'pre-visit-prep', 'kind' => 'transactional'],
                        // The morning before, not the morning of: a family
                        // reading "bring grip socks" as they park has been
                        // told too late to act on it.
                        ['type' => 'send_sms', 'template' => 'party-tomorrow-sms', 'kind' => 'transactional',
                         'before' => ['field' => 'event_date', 'days' => 1, 'at' => '10:00']],
                    ],
                    ['when' => ['days' => 3], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Weather advisory for outdoor bookings',
                    'booking.upcoming',
                    'Two days out, if the forecast threatens an outdoor-led party, offer a rebooking.',
                    [
                        /*
                         * NO FORECAST IS READ. This application has no weather
                         * feed and pretending otherwise would be worse than
                         * useless — a venue would trust a judgement nothing is
                         * making. So the sequence puts the decision in front of
                         * a person with the booking in hand, and the guest is
                         * only emailed once somebody has looked.
                         */
                        ['type' => 'create_task',
                         'title' => 'Check the forecast for {{full_name}} on {{party_date}}',
                         'description' => 'An outdoor-led booking two days out. If the forecast '
                            . 'is poor, send the weather email from this task and offer to move '
                            . 'the date. Castle CRM reads no forecast — this is a human call.',
                         'due_in_hours' => 6, 'priority' => 'high'],
                        ['type' => 'notify_user',
                         'title' => 'Outdoor booking, two days out',
                         'message' => '{{full_name}} is booked on {{party_date}} for something '
                            . 'outdoor-led. Worth a look at the forecast.'],
                    ],
                    ['when' => ['days' => 2], 'once' => 0, 'reentry' => 1],
                ],
                ['Abandoned ticket cart', 'ticket.abandoned', 'An hour later, one reminder with the cart restored.'],
                ['Ticket purchased — prepare', 'ticket.purchased', 'Confirm, add to calendar, and set expectations for the day.'],
                [
                    'High spender recognition',
                    'visit.recorded',
                    'When a visit exceeds a spend threshold, tag the household for VIP treatment.',
                    [
                        /*
                         * CENTS, and not already tagged.
                         *
                         * The threshold is in cents: written as 150 it would
                         * tag every guest who spent more than a dollar fifty,
                         * which is all of them, and the tag would mean
                         * nothing.
                         *
                         * The tag is also the GUARD, for the same reason as
                         * the milestone below — `visit.recorded` names an
                         * entity, so every visit enrols a fresh run whatever
                         * `once` says. Without the second rule a family who
                         * always spends well would notify a manager every
                         * single visit, and a notification that arrives every
                         * time stops being read.
                         */
                        ['type' => 'condition', 'rules' => [
                            'match' => 'all',
                            'rules' => [
                                ['field' => 'visits_spend_cents', 'op' => 'gte', 'value' => '15000'],
                                ['field' => 'tags', 'op' => 'not_contains', 'value' => 'high spender'],
                            ],
                        ]],
                        ['type' => 'add_tag', 'tag' => 'high spender', 'branch' => 'yes'],
                        ['type' => 'notify_user', 'branch' => 'yes',
                         'title' => 'A big visit',
                         'message' => '{{full_name}} has just spent well above average. Worth a '
                            . 'hello from a manager next time they are in.'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Tenth visit milestone',
                    'visit.recorded',
                    'Recognise a tenth visit with a thank-you. The tag it leaves is what stops it congratulating a regular every week thereafter.',
                    [
                        /*
                         * THE TAG IS THE GUARD, NOT `once`.
                         *
                         * `run_once_per_contact` is deliberately bypassed
                         * when the trigger names an ENTITY, and
                         * `visit.recorded` names a visit — so every visit
                         * enrols a new run however that flag is set. The
                         * condition stays true from the tenth visit onwards,
                         * so without a second rule this emails a regular
                         * EVERY TIME THEY COME, for ever. MEASURED on twelve
                         * visits before this rule existed: twelve runs, three
                         * of them past the threshold.
                         *
                         * The tag is written by this same sequence, so the
                         * next run sees it and takes the no branch. Tags
                         * arrive comma-wrapped (",first visit,regular,"), so
                         * `not_contains` on the bare name is the right test.
                         */
                        ['type' => 'condition', 'rules' => [
                            'match' => 'all',
                            'rules' => [
                                ['field' => 'visit_count', 'op' => 'gte', 'value' => '10'],
                                ['field' => 'tags', 'op' => 'not_contains', 'value' => 'milestone 10'],
                            ],
                        ]],
                        // Tagged FIRST, so a second visit recorded moments
                        // later finds the guard already in place rather than
                        // racing the email.
                        ['type' => 'add_tag', 'tag' => 'milestone 10', 'branch' => 'yes'],
                        ['type' => 'send_email', 'template' => 'visit-milestone', 'branch' => 'yes'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Lapsing soon — pre-emptive nudge',
                    'contact.lapsed',
                    'Before a guest is fully lapsed, one light "we miss you" touch.',
                    [
                        ['type' => 'send_email', 'template' => 'lapsed-guest-winback'],
                        ['type' => 'send_sms', 'template' => 'winback-sms', 'wait' => 5760],
                        ['type' => 'add_tag', 'tag' => 'lapsing'],
                    ],
                    [
                        'goal' => 'visit.recorded', 'goal_hours' => 720,
                        // And it stops if they come back between the email and
                        // the text — four days is long enough for a weekend.
                        'exit' => ['match' => 'any', 'rules' => [
                            ['field' => 'days_since_last_visit', 'op' => 'lt', 'value' => '7'],
                        ]],
                    ],
                ],
                [
                    'Seasonal reopening announcement',
                    'cron',
                    'When outdoor attractions come back into season, tell the people who used them.',
                    [
                        ['type' => 'send_email', 'template' => 'seasonal-reopening'],
                    ],
                    [
                        // Once a year, in the spring. A venue moves this to
                        // its own opening date; what matters is that it is a
                        // date and not a wait, so it cannot drift.
                        //
                        // AND A SEGMENT, which is what makes it send to
                        // anybody at all — see the note above the segments.
                        'when' => ['expression' => '23 9 15 3 *', 'segment' => 'recent-guests'],
                        'once' => 0, 'reentry' => 1,
                    ],
                ],
                [
                    'Rainy day indoor push',
                    'manual',
                    'A same-day text to local guests when the weather turns, respecting quiet hours.',
                    [
                        ['type' => 'send_sms', 'template' => 'flash-offer-sms'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'School holiday reminder',
                    'cron',
                    'Ahead of each local school break, remind families the Castle is open.',
                    [
                        ['type' => 'send_email', 'template' => 'school-holiday-open'],
                    ],
                    [
                        // Mid-February, a fortnight before most of the breaks
                        // in this district. The venue moves it to its own.
                        'when' => ['expression' => '17 9 14 2 *', 'segment' => 'recent-guests'],
                        'once' => 0, 'reentry' => 1,
                    ],
                ],
                [
                    'Castle Card balance nudge',
                    'cron',
                    'Remind guests holding a balance that it is waiting for them.',
                    [
                        /*
                         * No condition on the step. "Been a while" is the
                         * SEGMENT's job and stating it twice is how the two
                         * definitions drift — a venue widening the segment to
                         * 90 days would then still be filtered to 60 here,
                         * with nothing on the page to explain the gap.
                         */
                        ['type' => 'send_email', 'template' => 'card-balance-nudge'],
                    ],
                    [
                        'when' => ['expression' => '31 10 1 */3 *', 'segment' => 'been-a-while'],
                        'once' => 0, 'reentry' => 1,
                    ],
                ],
            ],
        ],
        [
            'number'  => 4,
            'name'    => 'Reputation Protection',
            'slug'    => 'reputation-protection',
            'icon'    => 'star',
            'tagline' => 'Happy guests go public. Unhappy ones reach a manager first.',
            'moment'  => 'A visit just ended.',
            'ready'   => 'The feedback gate, promoter routing to Google and Yelp, detractor '
                . 'escalation with a response deadline, review reply drafting, and the embeddable '
                . 'review wall for your website.',
            'tailor'  => 'Which review platforms you want to grow, who owns a complaint, and what '
                . 'you are willing to offer to put a bad visit right.',
            /*
             * THE FEEDBACK MODULE OWNS THE QUESTION, not these sequences.
             *
             * The invitation carries a one-use token, it must be written
             * before it is sent, and it must never go twice — so
             * `Services\Feedback` creates and sends it, and the Reputation
             * settings page is where a venue switches it on. These sequences
             * hand off to it through the `send_survey` step, which is the
             * same code path the nightly sweep uses and shares its
             * de-duplication: with the survey also triggered, whichever gets
             * there first wins and the other reports "already asked".
             *
             * That is why "ask for feedback" appears both here and on the
             * settings page without the guest being asked twice, and it is
             * also why a venue who wants the ask to depend on something — only
             * parties over twenty, only once the balance is paid — sets the
             * survey's own trigger to "never" and uses the sequence.
             */
            'automations' => [
                [
                    'Post-visit feedback request',
                    'booking.completed',
                    'Twenty hours after a party, ask the one question that predicts a review.',
                    [
                        // Twenty hours, from the party being marked complete.
                        // The module's own sweep measures the same delay from
                        // the END of the event; this is the sequence's
                        // version of the same intention.
                        ['type' => 'send_survey', 'survey' => 'After a party', 'wait' => 1200],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Promoter — ask for the review',
                    'survey.responded',
                    'A 9 or 10 gets a thank-you and the review link again, in case they closed the tab.',
                    [
                        // The gate has already decided and already offered
                        // the link on the page. This is the second ask, a day
                        // later, for the guest who meant to and forgot.
                        ['type' => 'condition', 'field' => 'event_sentiment', 'op' => 'is', 'value' => 'promoter'],
                        ['type' => 'add_tag', 'branch' => 'yes', 'tag' => 'promoter'],
                        ['type' => 'send_email', 'branch' => 'yes',
                         'template' => 'feedback-thanks-promoter', 'wait' => 1440],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Passive — ask what was missing',
                    'survey.responded',
                    'A 7 or 8 gets a person asking what would have made it a ten. No review ask.',
                    [
                        ['type' => 'condition', 'field' => 'event_sentiment', 'op' => 'is', 'value' => 'passive'],
                        ['type' => 'create_task', 'branch' => 'yes',
                         'title' => 'Ask {{full_name}} what would have made it a ten',
                         'description' => 'They scored {{score}}. The comment they left, if any, is on '
                            . 'the feedback page.',
                         'due_in_hours' => 48],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Detractor — escalate immediately',
                    'survey.detractor',
                    'No review ask. A manager is told at once, by email and in the app.',
                    [
                        // NO create_task HERE, deliberately: the feedback
                        // module raises one itself, with the deadline, so a
                        // complaint reaches somebody whether or not this
                        // sequence is switched on. A second task would mean
                        // two people chasing one family.
                        ['type' => 'notify_user', 'title' => 'A guest scored us {{score}}',
                         'message' => '{{full_name}} scored {{score}}. There is a task with a deadline '
                            . 'on the Reputation page — ring them today, then record what you agreed.'],
                        ['type' => 'add_tag', 'tag' => 'unhappy'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Detractor resolved — recovery offer',
                    'feedback.resolved',
                    'Only after a manager has spoken to them: a thank-you and something for next time.',
                    [
                        // Hangs off RESOLVED, not off the low score. An
                        // automatic apology that arrives before a person does
                        // is worse than no apology at all.
                        ['type' => 'issue_coupon', 'code' => 'COMEBACK'],
                        ['type' => 'send_email', 'template' => 'feedback-recovery'],
                        ['type' => 'remove_tag', 'tag' => 'unhappy'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Unresolved complaint escalation',
                    'task.overdue',
                    'If a complaint passes its deadline, the general manager hears about it.',
                    [
                        ['type' => 'notify_user', 'title' => 'A complaint has passed its deadline',
                         'message' => 'A guest complaint is past the response deadline on the '
                            . 'Reputation page. Somebody needs to ring them now.'],
                    ],
                    ['when' => ['hours' => 0], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'New low review alert',
                    'review.received',
                    'A public review of three stars or fewer pages a manager at once.',
                    [
                        ['type' => 'condition', 'field' => 'event_rating', 'op' => 'lte', 'value' => '3'],
                        ['type' => 'notify_user', 'branch' => 'yes', 'title' => 'A low public review has arrived',
                         'message' => 'A new review scored us {{event_rating}}. It is in the review '
                            . 'inbox with a reply draft ready.'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Review reply reminder',
                    'review.received',
                    'Every new review gets a reply within two days, or somebody is asked why.',
                    [
                        ['type' => 'create_task',
                         'title' => 'Reply to a new public review',
                         'description' => 'The review inbox has a draft to start from. A reply is read '
                            . 'by everybody who comes after.',
                         'due_in_hours' => 48],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Positive review — thank and feature',
                    'review.received',
                    'A five-star review is put forward for the website wall.',
                    [
                        // A TASK rather than featuring it automatically.
                        // Putting a guest's words on the venue's own website
                        // is a publishing decision, and a person makes it.
                        ['type' => 'condition', 'field' => 'event_rating', 'op' => 'gte', 'value' => '5'],
                        ['type' => 'create_task', 'branch' => 'yes',
                         'title' => 'Feature a five-star review on the wall?',
                         'description' => 'Open the review inbox, read it, and feature it if you are '
                            . 'happy for it to appear on the website.',
                         'due_in_hours' => 72, 'priority' => 'low'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Visit feedback for non-party guests',
                    'visit.recorded',
                    'Ask ordinary visitors how it went too, not just party hosts.',
                    [
                        ['type' => 'send_survey', 'survey' => 'After any visit', 'wait' => 1200],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Monthly reputation digest',
                    'cron',
                    'On the 1st, a nudge to read the score, the new reviews and anything unresolved.',
                    [
                        ['type' => 'notify_user', 'title' => 'This month\'s reputation',
                         'message' => 'Open the Reputation page: the score against last month, the '
                            . 'reviews nobody has answered, and any complaint still open.'],
                    ],
                    ['when' => ['expression' => '14 9 1 * *'], 'once' => 0, 'reentry' => 1],
                ],
            ],
        ],
        [
            'number'  => 5,
            'name'    => 'Membership & Season Pass Lifecycle',
            'slug'    => 'membership-season-pass',
            'icon'    => 'card',
            'tagline' => 'Sell the pass once. Keep it renewing.',
            'moment'  => 'A guest holds, under-uses, or lets a pass lapse.',
            'ready'   => 'A welcome series, usage nudges, the renewal ladder at 30, 14 and 3 days, '
                . 'a win-back ladder after expiry, and family-plan cross-sell.',
            'tailor'  => 'Your pass tiers and prices, what each includes, and your renewal incentive.',
            /*
             * THE LADDERS ARE THREE SEQUENCES EACH, NOT ONE WITH THREE
             * WAITS, and that is deliberate.
             *
             * `membership.expiring` is a SCANNED trigger: cron looks each
             * morning for passes exactly N days from their end date, where N
             * is the automation's own `days`. So the rungs are three
             * automations with three different N, each enrolling the people
             * who crossed its own threshold that day — which means a venue
             * can switch one rung off, reword it, or change its timing
             * without touching the others, and a pass renewed after the
             * first notice simply never crosses the second.
             *
             * One sequence with two long waits would instead hold every
             * member in flight for a month and need an exit condition to
             * stop asking somebody who has already renewed.
             */
            'automations' => [
                [
                    'Pass welcome series',
                    'membership.started',
                    'Day 0 welcome and how to use it, then a check-in a month in.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-welcome'],
                        ['type' => 'add_tag', 'tag' => 'pass holder'],
                        ['type' => 'create_task', 'wait' => 43200,
                         'title' => 'Check in with {{full_name}} — a month on their pass',
                         'description' => 'A quick hello. A member who has not been back in the '
                            . 'first month usually does not come back at all.',
                         'due_in_hours' => 48, 'priority' => 'low'],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'visit.recorded', 'goal_hours' => 720],
                ],
                [
                    'Unused pass nudge',
                    'membership.usage_idle',
                    'After five weeks with no visit, a reminder that it is paid for and waiting.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-unused'],
                    ],
                    ['when' => ['days' => 35], 'once' => 0, 'reentry' => 1,
                     'goal' => 'visit.recorded', 'goal_hours' => 720],
                ],
                [
                    'Renewal — 30 days out',
                    'membership.expiring',
                    'The first renewal notice, naming the date and what they got out of the year.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-renewal'],
                    ],
                    ['when' => ['days' => 30], 'once' => 0, 'reentry' => 1,
                     'goal' => 'membership.renewed', 'goal_hours' => 1440],
                ],
                [
                    'Renewal — 14 days out',
                    'membership.expiring',
                    'A second notice a fortnight out, for anybody who has not renewed.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-renewal'],
                    ],
                    ['when' => ['days' => 14], 'once' => 0, 'reentry' => 1,
                     'goal' => 'membership.renewed', 'goal_hours' => 720],
                ],
                [
                    'Renewal — 3 days out',
                    'membership.expiring',
                    'Final notice, and somebody rings the members worth ringing.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-renewal'],
                        ['type' => 'create_task',
                         'title' => 'Ring {{full_name}} — pass ends in three days',
                         'description' => 'Member {{member_number}}, {{visits_used}} visits this '
                            . 'year. Three emails have gone; a call is what is left.',
                         'due_in_hours' => 24, 'priority' => 'high'],
                    ],
                    ['when' => ['days' => 3], 'once' => 0, 'reentry' => 1,
                     'goal' => 'membership.renewed', 'goal_hours' => 336],
                ],
                [
                    'Expiry day',
                    'membership.expired',
                    'On the day it ends: what they used it for, and how to start again.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-expired'],
                        ['type' => 'remove_tag', 'tag' => 'pass holder'],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'membership.renewed', 'goal_hours' => 720],
                ],
                [
                    'Win-back — 14 days after expiry',
                    'membership.lapsed',
                    'First win-back touch, a fortnight after it ended.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-winback'],
                    ],
                    ['when' => ['days' => 14], 'once' => 0, 'reentry' => 1,
                     'goal' => 'membership.started', 'goal_hours' => 2160],
                ],
                [
                    'Win-back — 45 days after expiry',
                    'membership.lapsed',
                    'Second touch, with an offer the venue chooses.',
                    [
                        ['type' => 'issue_coupon', 'code' => 'COMEBACK'],
                        ['type' => 'send_email', 'template' => 'membership-winback'],
                    ],
                    ['when' => ['days' => 45], 'once' => 0, 'reentry' => 1,
                     'goal' => 'membership.started', 'goal_hours' => 2160],
                ],
                [
                    'Win-back — 90 days after expiry',
                    'membership.lapsed',
                    'The last one. After this we stop asking.',
                    [
                        ['type' => 'send_email', 'template' => 'membership-winback'],
                        ['type' => 'add_tag', 'tag' => 'lapsed member'],
                        // Said out loud, because the temptation is always to
                        // add a fourth: asking again costs more goodwill than
                        // it recovers.
                        ['type' => 'exit'],
                    ],
                    ['when' => ['days' => 90], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Family plan upgrade',
                    'membership.started',
                    'A household with more than one single pass is a family plan waiting to happen.',
                    [
                        // A TASK rather than a mailshot: whether a household
                        // is better off on a family plan depends on what they
                        // already hold, and a person can see that in two
                        // clicks where a rule cannot.
                        ['type' => 'create_task', 'wait' => 1440,
                         'title' => 'Would {{full_name}} be better on a family plan?',
                         'description' => 'Check what else the household holds. If there are two '
                            . 'or more single passes, the family plan is usually cheaper.',
                         'due_in_hours' => 72, 'priority' => 'low'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Pass holder — party cross-sell',
                    'child.birthday_soon',
                    'Pass-holding families get the birthday offer first.',
                    [
                        ['type' => 'condition', 'field' => 'tags', 'op' => 'contains', 'value' => 'pass holder'],
                        ['type' => 'send_email', 'branch' => 'yes', 'template' => 'birthday-club-invite'],
                    ],
                    ['when' => ['days' => 45], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Cancellation — ask why',
                    'membership.cancelled',
                    'One short question, so the churn report has reasons in it rather than a count.',
                    [
                        ['type' => 'create_task',
                         'title' => 'Why did {{full_name}} cancel?',
                         'description' => 'If the cancellation note is blank, ring them. A churn '
                            . 'report with no reasons in it cannot be acted on.',
                         'due_in_hours' => 72],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Heavy user recognition',
                    'cron',
                    'A monthly nudge to thank the members who come most. They are your advocates.',
                    [
                        ['type' => 'notify_user', 'title' => 'Thank your heaviest members',
                         'message' => 'Open Memberships and sort by visits. The handful at the top '
                            . 'are the people who recommend you — a note from a manager costs '
                            . 'nothing and is remembered.'],
                    ],
                    ['when' => ['expression' => '11 9 8 * *'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Monthly membership report',
                    'cron',
                    'On the 1st: who is live, who is up for renewal, who is not using it.',
                    [
                        ['type' => 'notify_user', 'title' => 'This month\'s memberships',
                         'message' => 'Open Memberships: the renewal queue, the passes nobody is '
                            . 'using, and anybody who lapsed last month.'],
                    ],
                    ['when' => ['expression' => '21 9 1 * *'], 'once' => 0, 'reentry' => 1],
                ],
            ],
        ],
        [
            'number'  => 6,
            'name'    => 'Text Club & Repeat Visits',
            'slug'    => 'text-club-repeat-visits',
            'icon'    => 'message',
            'tagline' => 'A permission-based list that fills slow days.',
            'moment'  => 'A guest opts in to texts.',
            'ready'   => 'Double opt-in, a welcome coupon, the monthly offer calendar, a '
                . 'birthday-month coupon, slow-day flash sends, and the lapsed-guest win-back ladder.',
            'tailor'  => 'Your monthly offer calendar, what a slow day is worth discounting, and '
                . 'how often a guest may hear from you.',
            /*
             * WHAT THE TEXT CLUB PAGE ALREADY DOES, THESE DO NOT DO AGAIN.
             *
             * The module itself owns the double opt-in, the confirmation
             * reply and the monthly send — it has its own calendar, its own
             * subscriber list and its own compliance checks. An automation
             * that also texted a welcome would send two texts to somebody who
             * had just joined, which is both a cost and the fastest way to be
             * reported.
             *
             * So the sequences here do the things the module does not: record
             * the offer against the guest so its redemption can be traced,
             * tag who is on the list and who has left, run the three-touch
             * win-back ladder, and put the venue's own list work on somebody's
             * screen in the week it matters.
             */
            'automations' => [
                [
                    'Text Club joiner — tag them',
                    'contact.consent_granted',
                    'Tag a guest who has just granted consent, so the reports and segments can find them. The opt-in itself is handled by the Text Club page.',
                    [
                        ['type' => 'add_tag', 'tag' => 'text club'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Text Club welcome offer',
                    'text_club.confirmed',
                    'Record the joining offer against the guest so its redemption can be traced. The welcome text itself comes from the Text Club page.',
                    [
                        ['type' => 'issue_coupon', 'code' => 'WELCOME10'],
                    ],
                ],
                [
                    'Monthly offer — set it up',
                    'cron',
                    'A nudge on the 25th to set next month\'s offer, before the Text Club sends it on the 1st.',
                    [
                        ['type' => 'notify_user', 'title' => 'Next month\'s Text Club offer',
                         'message' => 'The Text Club sends next month\'s offer on the 1st. Check the '
                            . 'calendar on the Text Club page and change it if it is not right.'],
                    ],
                    ['when' => ['expression' => '32 9 25 * *'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Birthday month coupon',
                    'contact.birthday_soon',
                    'A week before their birthday, issue the free-game offer and text it to them.',
                    [
                        ['type' => 'issue_coupon', 'code' => 'BIRTHDAY'],
                        ['type' => 'send_sms', 'template' => 'birthday-coupon-sms'],
                    ],
                    ['when' => ['days' => 7], 'once' => 0, 'reentry' => 1, 'goal' => 'visit.recorded'],
                ],
                [
                    'Slow day flash',
                    'manual',
                    'Started by hand on a quiet day. The wording changes every time, so the text it sends is a placeholder you edit before you start it.',
                    [
                        /*
                         * This shipped EMPTY, on the reasoning that the
                         * wording is different every time. True, and still
                         * the wrong default: an automation with no steps
                         * cannot be previewed, cannot be tested against a
                         * real guest, and gives a venue no idea what shape a
                         * flash text takes. What it sends is a template whose
                         * body says to edit it — so the venue changes one
                         * sentence rather than building the thing, and the
                         * opt-out line and the marketing `kind` are right
                         * before they start.
                         */
                        ['type' => 'send_sms', 'template' => 'flash-offer-sms'],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'visit.recorded', 'goal_hours' => 72],
                ],
                [
                    'Lapsed guest — touch 1',
                    'contact.lapsed',
                    'On the day they cross the lapse window, one light "we miss you".',
                    [
                        ['type' => 'send_email', 'template' => 'lapsed-guest-winback'],
                        ['type' => 'add_tag', 'tag' => 'lapsed'],
                    ],
                    ['goal' => 'visit.recorded', 'goal_hours' => 1440],
                ],
                [
                    'Lapsed guest — touch 2',
                    'contact.lapsed',
                    'Two weeks after the first touch, a real offer by text.',
                    [
                        ['type' => 'issue_coupon', 'code' => 'COMEBACK', 'wait' => 20160],
                        ['type' => 'send_sms', 'template' => 'winback-sms'],
                    ],
                    ['goal' => 'visit.recorded', 'goal_hours' => 1440],
                ],
                [
                    'Lapsed guest — touch 3',
                    'contact.lapsed',
                    'A final touch six weeks out, then tag them dormant and stop asking.',
                    [
                        ['type' => 'send_email', 'template' => 'lapsed-guest-winback', 'wait' => 60480],
                        ['type' => 'add_tag', 'tag' => 'dormant'],
                        ['type' => 'remove_tag', 'tag' => 'lapsed'],
                        // Said out loud, because the temptation is always to
                        // add a fourth: asking a fourth time costs more
                        // goodwill than it recovers.
                        ['type' => 'exit'],
                    ],
                    ['goal' => 'visit.recorded', 'goal_hours' => 1440],
                ],
                [
                    'Post-visit return offer',
                    'visit.recorded',
                    'Three days after a visit, an offer timed to the next natural trip.',
                    [
                        ['type' => 'issue_coupon', 'code' => 'COMEBACK', 'wait' => 4320],
                        ['type' => 'send_sms', 'template' => 'return-offer-sms'],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'visit.recorded', 'goal_hours' => 2160],
                ],
                [
                    'Weekend reminder',
                    'cron',
                    'A Thursday nudge to post the weekend hours and what is open.',
                    [
                        ['type' => 'notify_user', 'title' => 'The weekend',
                         'message' => 'Post the weekend hours and anything that is closed, while '
                            . 'people are still making plans.'],
                    ],
                    ['when' => ['expression' => '38 9 * * 4'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Offer issued — remind them it is waiting',
                    'coupon.issued',
                    'Five days after an offer goes out unused, one reminder by text.',
                    [
                        ['type' => 'send_sms', 'template' => 'return-offer-sms', 'wait' => 7200],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'coupon.redeemed', 'goal_hours' => 720],
                ],
                [
                    'Opt-out — tag and tell the team',
                    'contact.consent_revoked',
                    'Tag the guest and tell the team, so nobody chases them by hand. The STOP itself is honoured by the sending layer before this runs.',
                    [
                        ['type' => 'add_tag', 'tag' => 'opted out'],
                        ['type' => 'notify_user', 'title' => 'Somebody opted out',
                         'message' => '{{full_name}} has withdrawn consent. Nothing more will be sent '
                            . 'to them, and nobody should add them back by hand.'],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Re-engagement of silent subscribers',
                    'cron',
                    'Each quarter, a nudge to ask the subscribers who never redeem whether they still want texts.',
                    [
                        ['type' => 'notify_user', 'title' => 'The quiet half of the list',
                         'message' => 'Some subscribers have never redeemed anything. Asking them '
                            . 'whether they still want texts costs one send and saves the rest.'],
                    ],
                    ['when' => ['expression' => '44 9 1 1,4,7,10 *'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'List growth report',
                    'cron',
                    'Monday morning: a nudge to read the week\'s joins, opt-outs and redemption rate.',
                    [
                        ['type' => 'notify_user', 'title' => 'The list, last week',
                         'message' => 'Joins, confirmations, opt-outs and redemptions are on the Text '
                            . 'Club page. A rising opt-out rate is the number to watch.'],
                    ],
                    ['when' => ['expression' => '49 7 * * 1'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'New attraction announcement',
                    'manual',
                    'Started by hand when something new opens. An email first, then a text to anybody the email did not bring back.',
                    [
                        ['type' => 'send_email', 'template' => 'new-attraction'],
                        /*
                         * The text goes only to the people the email did not
                         * reach back. A new attraction is worth one message,
                         * not two in a week down two channels — and the
                         * condition is on their own behaviour rather than on
                         * an open, which this version does not track.
                         */
                        ['type' => 'condition', 'field' => 'days_since_last_visit', 'op' => 'gte',
                         'value' => '14', 'wait' => 10080],
                        ['type' => 'send_sms', 'template' => 'flash-offer-sms', 'branch' => 'yes'],
                    ],
                    ['once' => 0, 'reentry' => 1, 'goal' => 'visit.recorded', 'goal_hours' => 720],
                ],
            ],
        ],
        [
            'number'  => 7,
            'name'    => 'Sales Accountability',
            'slug'    => 'sales-accountability',
            'icon'    => 'target',
            'tagline' => 'No lead goes quiet without someone knowing.',
            'moment'  => 'A lead has gone quiet, or a deadline has passed.',
            'ready'   => 'Response deadlines per stage, stale-deal escalation, round-robin '
                . 'reassignment, a daily task digest for each rep, and a weekly manager scorecard.',
            'tailor'  => 'Your response-time commitments, who escalation reaches, and your targets.',
            /*
             * EVERY SEQUENCE IN THIS PLAYBOOK IS INTERNAL. Nothing here
             * messages a guest: it notifies the venue's own people and puts
             * work on somebody's list. That makes it the playbook a venue can
             * switch on first — the only thing it can get wrong is to
             * interrupt a member of staff — and it needs no templates at all.
             *
             * Which is also why the notifications say what they say: a
             * "digest" here is one line telling somebody where to look,
             * because composing a report is not something a step can do, and
             * a notification that pretends to be a report is worse than one
             * that points at the page holding it.
             */
            'automations' => [
                [
                    'Response deadline breached',
                    'deal.sla_breached',
                    'Notify the owner, then task a manager two hours later, then raise the reassignment at four.',
                    [
                        ['type' => 'notify_user', 'title' => 'A response deadline has passed',
                         'message' => '{{full_name}} has been waiting past the deadline on this stage. '
                            . 'They are almost certainly talking to another venue.'],
                        ['type' => 'create_task', 'title' => 'Chase the overdue lead: {{full_name}}',
                         'wait' => 120, 'due_in_hours' => 2, 'priority' => 'high'],
                        ['type' => 'create_task', 'title' => 'Reassign {{full_name}} — still no response',
                         'description' => 'Four hours past the deadline. Give it to somebody who can call today.',
                         'wait' => 120, 'due_in_hours' => 2, 'priority' => 'high'],
                    ],
                ],
                [
                    'Stale deal — no activity',
                    'deal.idle',
                    'After seven quiet days, a task for the owner to move it on or close it.',
                    [
                        ['type' => 'create_task', 'title' => 'Move on or close: {{title}}',
                         'description' => 'A week with no activity. Either there is a next step or there is not.',
                         'due_in_hours' => 24],
                    ],
                    ['when' => ['days' => 7]],
                ],
                [
                    'Very stale deal — manager review',
                    'deal.idle',
                    'After twenty-one days, put it in front of a manager for a decision.',
                    [
                        ['type' => 'notify_user', 'title' => 'A deal has been quiet for three weeks',
                         'message' => '{{title}} has had no activity for twenty-one days. Decide whether it is real.'],
                        ['type' => 'create_task', 'title' => 'Decide on the stale deal: {{title}}',
                         'due_in_hours' => 48],
                    ],
                    ['when' => ['days' => 21]],
                ],
                [
                    'Unanswered conversation',
                    'conversation.unanswered',
                    'A guest message unanswered for four hours alerts the team and lands on a list.',
                    [
                        ['type' => 'notify_user', 'title' => 'A guest is still waiting',
                         'message' => 'A message from {{full_name}} has gone four hours without an answer.'],
                        ['type' => 'create_task', 'title' => 'Answer {{full_name}}',
                         'due_in_hours' => 2, 'priority' => 'high'],
                    ],
                    ['when' => ['hours' => 4], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Unassigned lead',
                    'deal.created',
                    'A lead still with no owner after fifteen minutes notifies a manager and lands on a list.',
                    [
                        ['type' => 'condition', 'field' => 'deals_owner_id', 'op' => 'is_empty', 'value' => '1',
                         'wait' => 15],
                        ['type' => 'notify_user', 'branch' => 'yes', 'title' => 'A lead has nobody on it',
                         'message' => '{{title}} has had no owner for fifteen minutes. Give it to somebody.'],
                        ['type' => 'create_task', 'branch' => 'yes', 'title' => 'Assign an owner: {{title}}',
                         'due_in_hours' => 1, 'priority' => 'high'],
                    ],
                ],
                [
                    'Daily rep digest',
                    'cron',
                    'At 6:30am, a nudge to work the day\'s list: tasks, overdue items and today\'s parties.',
                    [
                        ['type' => 'notify_user', 'title' => 'Today at the Castle',
                         'message' => 'Today\'s tasks, the overdue ones and today\'s parties are on the dashboard. '
                            . 'Clear the overdue list first.'],
                    ],
                    ['when' => ['expression' => '28 6 * * *'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Weekly manager scorecard',
                    'cron',
                    'Monday morning, a nudge to read the week\'s numbers before the week starts.',
                    [
                        ['type' => 'notify_user', 'title' => 'Last week, in numbers',
                         'message' => 'Leads, first response times, quotes sent and win rate are on the reports '
                            . 'page. Worth ten minutes before the week starts.'],
                    ],
                    ['when' => ['expression' => '52 6 * * 1'], 'once' => 0, 'reentry' => 1],
                ],
                [
                    'Quote with no follow-up',
                    'quote.sent',
                    'Three days after a quote with nothing happening, task the owner. Stops if they book.',
                    [
                        ['type' => 'create_task', 'title' => 'Follow up the quote to {{full_name}}',
                         'description' => 'Sent three days ago and nothing has moved.',
                         'wait' => 4320, 'due_in_hours' => 8],
                    ],
                    [
                        'exit' => ['match' => 'any', 'rules' => [
                            ['field' => 'has_booking', 'op' => 'is_true', 'value' => '1'],
                            ['field' => 'quotes_status', 'op' => 'is', 'value' => 'accepted'],
                        ]],
                        'goal' => 'quote.accepted',
                    ],
                ],
                [
                    'Deal missing a next action',
                    'deal.stage_changed',
                    'Every open deal needs a next action; prompt the owner when the stage changes without one.',
                    [
                        ['type' => 'condition', 'field' => 'deals_next_action', 'op' => 'is_empty', 'value' => '1',
                         'wait' => 60],
                        ['type' => 'create_task', 'branch' => 'yes',
                         'title' => 'What happens next on {{title}}?',
                         'description' => 'The stage moved and there is no next action on the deal.',
                         'due_in_hours' => 24],
                    ],
                    ['once' => 0, 'reentry' => 1],
                ],
                [
                    'Won deal — handoff check',
                    'deal.won',
                    'Check the booking exists, the host is assigned and the deposit is tracked.',
                    [
                        ['type' => 'create_task', 'title' => 'Handoff check: {{title}}',
                         'description' => 'Booking created? Host assigned? Deposit tracked? Run sheet started?',
                         'due_in_hours' => 24, 'priority' => 'high'],
                        ['type' => 'add_tag', 'tag' => 'booked'],
                    ],
                ],
                [
                    'Lost deal — reason required',
                    'deal.lost',
                    'Prompt for a loss reason when one is missing, so the win/loss report is worth reading.',
                    [
                        ['type' => 'condition', 'field' => 'deals_lost_reason_id', 'op' => 'is_empty', 'value' => '1'],
                        ['type' => 'create_task', 'branch' => 'yes', 'title' => 'Why did we lose {{title}}?',
                         'description' => 'One line on the deal. Without it the win/loss report says nothing.',
                         'due_in_hours' => 48],
                    ],
                ],
                [
                    'Monthly pipeline hygiene',
                    'cron',
                    'On the first of the month, a nudge to clear out deals whose event date has passed.',
                    [
                        ['type' => 'notify_user', 'title' => 'Pipeline tidy-up',
                         'message' => 'Some deals have event dates in the past and are still open. '
                            . 'Twenty minutes on the board keeps every forecast honest.'],
                    ],
                    ['when' => ['expression' => '41 7 1 * *'], 'once' => 0, 'reentry' => 1],
                ],
            ],
        ],
        [
            'number'  => 8,
            'name'    => 'Fundraising, Donations & Referrals',
            'slug'    => 'fundraising-referrals',
            'icon'    => 'gift',
            'tagline' => 'Give generously, track it properly, and get something back.',
            'moment'  => 'A nonprofit, school or guest asks.',
            'ready'   => 'The donation request form, an approval workflow with an annual budget '
                . 'guard, numbered certificates with expiry and redemption tracking, and a guest '
                . 'referral programme.',
            'tailor'  => 'Your annual donation budget, who approves, and what a certificate is worth.',
            /*
             * FIVE OF THESE EIGHT SHIP WITH NO STEPS, ON PURPOSE.
             *
             * `donation.requested`, `donation.approved`, `donation.declined`,
             * `coupon.redeemed` and `referral.converted` are catalogued
             * events that nothing fires yet — the donation workflow and the
             * referral programme are their own modules and are not built. A
             * sequence behind a trigger that cannot fire reads as finished
             * and does nothing for ever, which is worse than an honest gap:
             * the automation page says what each is waiting on.
             *
             * The three that ARE authored need nothing that does not exist:
             * two are cron and the third hangs off `certificate.expiring`,
             * which the coupon expiry scan already fires.
             */
            'automations' => [
                ['Donation request received', 'donation.requested', 'Acknowledge the request, task the general manager, and check it against the annual budget.'],
                [
                    'Donation decision reminder',
                    'cron',
                    'Nudge the approver about requests still undecided after seven days.',
                    [
                        /*
                         * A TASK FOR A PERSON, not a message to a donor. The
                         * requests themselves live in the fundraising
                         * pipeline, so the useful thing a weekly job can do
                         * before that module exists is put the review in
                         * somebody's list — a nonprofit waiting three weeks
                         * for an answer remembers that longer than the
                         * donation.
                         */
                        ['type' => 'create_task',
                         'title' => 'Review the donation requests nobody has answered',
                         'description' => 'Anything in the fundraising pipeline sitting undecided '
                            . 'for more than a week. A decline given quickly costs the venue '
                            . 'nothing and keeps the relationship; a decision never made costs '
                            . 'both.',
                         'due_in_hours' => 48, 'priority' => 'normal'],
                        ['type' => 'notify_user',
                         'title' => 'Donation requests waiting',
                         'message' => 'There are donation requests that have been waiting a week '
                            . 'or more. They are in the fundraising pipeline.'],
                    ],
                    ['when' => ['expression' => '19 8 * * 1'], 'once' => 0, 'reentry' => 1],
                ],
                ['Donation approved — issue certificate', 'donation.approved', 'Send the numbered certificate with its value, expiry and terms.'],
                ['Donation declined — decline kindly', 'donation.declined', 'A respectful decline that leaves the relationship intact.'],
                [
                    'Certificate expiring',
                    'certificate.expiring',
                    'Three weeks before expiry, remind the organisation to use it.',
                    [
                        ['type' => 'send_email', 'template' => 'certificate-expiring',
                         'kind' => 'transactional'],
                        ['type' => 'create_task',
                         'title' => 'Certificate for {{organisation}} expires in three weeks',
                         'description' => 'They have been emailed. If it was auctioned the winner '
                            . 'may not know, so a call is usually worth more than the email.',
                         'due_in_hours' => 72, 'priority' => 'low'],
                    ],
                    ['when' => ['days' => 21], 'once' => 0, 'reentry' => 1],
                ],
                ['Certificate redeemed — capture the contact', 'coupon.redeemed', 'When a certificate is redeemed, invite the redeeming guest to join the list.'],
                ['Referral converted — reward', 'referral.converted', 'Reward the referrer as soon as the referred booking completes.'],
                [
                    'Advocate outreach',
                    'cron',
                    'Each quarter, ask your most frequent guests to refer a friend.',
                    [
                        /*
                         * ONLY THE REGULARS, and that is the SEGMENT's
                         * definition rather than a condition here. A tag
                         * would have been the tempting gate — playbook 3
                         * applies one — but it is only applied once that
                         * automation is switched on, so this would send to
                         * nobody on an install that left it off, and nothing
                         * would say why.
                         */
                        ['type' => 'send_email', 'template' => 'referral-invite'],
                        ['type' => 'add_tag', 'tag' => 'asked to refer'],
                    ],
                    [
                        // Quarterly. The email itself promises not to ask
                        // again this year, so a venue that wants that
                        // literally narrows the audience rather than leaning
                        // on the schedule.
                        'when' => ['expression' => '29 9 5 */3 *', 'segment' => 'regulars'],
                        'once' => 0, 'reentry' => 1,
                    ],
                ],
            ],
        ],
    ];

    /*
     * THREE STANDING SEGMENTS, because four of the automations above send to
     * guests on a SCHEDULE and a cron automation with no segment enrols one
     * run with NO CONTACT on it.
     *
     * That is the trap this seed nearly shipped: `send_email` on a
     * contactless run returns "there is no guest on this run to email" and
     * records the step as DONE. The run succeeds, the automation reports
     * success, and the seasonal announcement goes to nobody — for ever, and
     * with nothing on any page to say so. Every cron automation that was
     * already here notifies a USER rather than a guest, which is why nothing
     * caught it before.
     *
     * Each of these is defined on columns every install has, with no
     * reference to this venue's attractions or geography, so they are
     * populated on day one rather than being three empty lists:
     *
     *   - `visit_count` and `last_visit_at` are maintained by VisitRecorder
     *     from the visits themselves.
     *   - No tag is used as a gate. A segment that depended on a tag would
     *     be empty until somebody switched on the automation that applies it,
     *     which is a dependency between two features nothing states.
     *
     * They are ordinary segments: editable, deletable, and visible on the
     * Segments page, which otherwise ships empty.
     */
    $segments = [
        [
            'slug'        => 'regulars',
            'name'        => 'Regulars',
            'description' => 'Ten visits or more. The families the venue runs on.',
            'rules'       => ['match' => 'all', 'rules' => [
                ['field' => 'visit_count', 'op' => 'gte', 'value' => 10],
            ]],
        ],
        [
            'slug'        => 'recent-guests',
            'name'        => 'Been in the last year',
            'description' => 'Anybody who has visited in the last twelve months. The list for a '
                . 'seasonal announcement.',
            'rules'       => ['match' => 'all', 'rules' => [
                ['field' => 'visit_count', 'op' => 'gte', 'value' => 1],
                ['field' => 'last_visit_at', 'op' => 'in_last_days', 'value' => 365],
            ]],
        ],
        [
            'slug'        => 'been-a-while',
            'name'        => 'Been, but not lately',
            'description' => 'Visited at least once, but not in the last two months. Not yet '
                . 'lapsed — just drifting.',
            'rules'       => ['match' => 'all', 'rules' => [
                ['field' => 'visit_count', 'op' => 'gte', 'value' => 1],
                ['field' => 'last_visit_at', 'op' => 'not_in_last_days', 'value' => 60],
            ]],
        ],
    ];

    $segmentIds  = [];
    $segmentCount = 0;

    foreach ($segments as $segment) {
        $existing = DB::first(
            'SELECT id FROM {segments} WHERE slug = :slug',
            ['slug' => $segment['slug']]
        );

        if ($existing !== null) {
            // Left exactly as it is, like everything else in this file: a
            // venue may have narrowed it, and a re-seed must not widen an
            // audience somebody tightened on purpose.
            $segmentIds[$segment['slug']] = (int) $existing['id'];

            continue;
        }

        $segmentIds[$segment['slug']] = DB::insert('segments', [
            'name'        => $segment['name'],
            'slug'        => $segment['slug'],
            'description' => $segment['description'],
            'rules'       => json_encode($segment['rules'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_dynamic'  => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $segmentCount++;
    }

    /**
     * Turn a `when` block's `segment` slug into the `segment_id` the trigger
     * reads, and drop the slug.
     *
     * @param array<string,mixed> $when
     * @return array<string,mixed>
     */
    $whenConfig = static function (array $when) use ($segmentIds): array {
        $slug = (string) ($when['segment'] ?? '');
        unset($when['segment']);

        if ($slug !== '' && isset($segmentIds[$slug])) {
            $when['segment_id'] = $segmentIds[$slug];
        }

        return $when;
    };

    /**
     * Write one automation's steps.
     *
     * The seed describes a step the way somebody would say it out loud —
     * `['type' => 'send_email', 'template' => 'party-quote', 'wait' => 2880]`
     * — and this turns that into the columns the engine reads. Written here
     * rather than in each entry because two of those columns are easy to get
     * wrong and the mistake is silent: a delay tied to a date goes in
     * `delay_until_config` as `field`/`days`/`at`, and a condition's rules go
     * in as a GROUP with `match` and `op`, not as a bare list with
     * `operator`. Either mistake saves cleanly and then behaves as though the
     * step were not there.
     *
     * `branch` attaches a step to the condition or split before it, which is
     * what makes a linear list able to describe a fork.
     *
     * @param list<array<string,mixed>> $steps
     */
    $writeSteps = static function (int $automationId, array $steps) use ($now): int {
        $written = 0;
        $parent  = null;
        $order   = 0;

        foreach ($steps as $spec) {
            $type = (string) ($spec['type'] ?? '');

            if ($type === '') {
                continue;
            }

            $branch = (string) ($spec['branch'] ?? 'main');
            $config = [];

            // Everything that is not a step property is config for the step.
            foreach ($spec as $key => $value) {
                if (!in_array($key, ['type', 'branch', 'wait', 'before', 'after'], true)) {
                    $config[$key] = $value;
                }
            }

            if ($type === 'condition' && isset($spec['field'])) {
                $config = [
                    'rules' => [
                        'match' => 'all',
                        'rules' => [[
                            'field' => (string) $spec['field'],
                            'op'    => (string) ($spec['op'] ?? 'is'),
                            'value' => $spec['value'] ?? '',
                        ]],
                    ],
                ];
            }

            $delayType   = null;
            $delayConfig = null;

            foreach (['before' => 'days_before_field', 'after' => 'days_after_field'] as $key => $kind) {
                if (!is_array($spec[$key] ?? null)) {
                    continue;
                }

                $delayType   = $kind;
                $delayConfig = json_encode($spec[$key], JSON_UNESCAPED_SLASHES);
            }

            $stepId = DB::insert('automation_steps', [
                'automation_id'      => $automationId,
                'parent_step_id'     => $branch === 'main' ? null : $parent,
                'branch'             => $branch === 'main' ? 'main' : $branch,
                'sort_order'         => $order++,
                'type'               => $type,
                'config'             => $config === [] ? null : json_encode($config, JSON_UNESCAPED_SLASHES),
                'delay_minutes'      => (int) ($spec['wait'] ?? 0),
                'delay_until_type'   => $delayType,
                'delay_until_config' => $delayConfig,
                'is_enabled'         => 1,
                'created_at'         => $now,
            ]);

            // A fork remembers itself, so the steps written after it with a
            // branch land inside it.
            if (in_array($type, ['condition', 'split_test'], true)) {
                $parent = $stepId;
            }

            $written++;
        }

        return $written;
    };

    /**
     * The offers the sequences hand out.
     *
     * An `issue_coupon` step records that a guest has been GIVEN an offer,
     * against an offer the venue has created — it never invents one, because
     * a step that could create an offer could create one with any value. So
     * the three the Text Club sequences name are seeded here, with modest
     * values the venue is expected to change: they are a starting point in
     * the same way the templates are, and the playbook's own page says so.
     *
     * Re-seedable by code, like everything else in this file.
     */
    $writeOffers = static function () use ($now): int {
        $offers = [
            [
                'code'            => 'WELCOME10',
                'name'            => 'Text Club welcome offer',
                'type'            => 'percent',
                'value'           => 10,
                // Once per family: it is the joining offer, not a standing
                // discount.
                'max_per_contact' => 1,
            ],
            [
                'code'            => 'BIRTHDAY',
                'name'            => 'Birthday month - free game',
                'type'            => 'free_item',
                'value'           => 0,
                // Once a year, for as long as they stay on the list.
                'max_per_contact' => 10,
            ],
            [
                'code'            => 'COMEBACK',
                'name'            => 'Come back soon',
                'type'            => 'fixed',
                'value'           => 5,
                'max_per_contact' => 2,
            ],
        ];

        $written = 0;

        foreach ($offers as $offer) {
            if (DB::exists('SELECT id FROM {coupons} WHERE code = :c', ['c' => $offer['code']])) {
                continue;
            }

            DB::insert('coupons', $offer + [
                'min_spend_cents'  => 0,
                'redemption_count' => 0,
                'is_active'        => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $written++;
        }

        return $written;
    };

    $playbookCount = 0;
    $automationCount = 0;
    $stepCount = 0;
    $offerCount = $writeOffers();

    foreach ($playbooks as $playbook) {
        $existing = DB::first('SELECT id FROM {playbooks} WHERE slug = :slug', ['slug' => $playbook['slug']]);

        if ($existing !== null) {
            $playbookId = (int) $existing['id'];
        } else {
            $playbookId = DB::insert('playbooks', [
                'number'         => $playbook['number'],
                'name'           => $playbook['name'],
                'slug'           => $playbook['slug'],
                'tagline'        => $playbook['tagline'],
                'revenue_moment' => $playbook['moment'],
                'arrives_ready'  => $playbook['ready'],
                'you_tailor'     => $playbook['tailor'],
                'icon'           => $playbook['icon'],
                // Deactivated until an operator turns it on.
                'is_active'      => 0,
                'sort_order'     => $playbook['number'] * 10,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $playbookCount++;
        }

        foreach ($playbook['automations'] as $index => $spec) {
            [$name, $trigger, $description] = $spec;

            $steps   = is_array($spec[3] ?? null) ? $spec[3] : [];
            $options = is_array($spec[4] ?? null) ? $spec[4] : [];
            $slug    = $playbook['slug'] . '-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            $already = DB::first('SELECT id FROM {automations} WHERE slug = :slug', ['slug' => $slug]);

            if ($already !== null) {
                /*
                 * Already here, so it is left exactly as it is — STEPS
                 * INCLUDED.
                 *
                 * The tempting alternative is to top up an automation that
                 * has none, which would help an install that predates these
                 * sequences shipping. It would also refill one a venue
                 * emptied on purpose, on a re-seed, possibly while it is
                 * switched on — and then the venue's own sequence starts
                 * sending again with nothing to say why. This file's existing
                 * rule is the right one: never clobber what somebody may have
                 * edited.
                 */
                continue;
            }

            $automationId = DB::insert('automations', [
                'playbook_id'          => $playbookId,
                'name'                 => $name,
                'slug'                 => $slug,
                'description'          => $description,
                'trigger_type'         => $trigger,
                // Inactive. Nothing seeded here can send anything until a
                // person reviews it and switches it on.
                'is_active'            => 0,
                'run_once_per_contact' => (int) ($options['once'] ?? 1),
                'allow_reentry'        => (int) ($options['reentry'] ?? 0),
                'quiet_hours_respect'  => 1,
                'priority'             => 100,
                // The window a scanner is asked about ("fourteen days
                // before"), and the conditions that stop a sequence the
                // moment it stops being true for somebody.
                //
                // `segment` is written here as a SLUG and resolved to the id
                // the trigger wants, because an id is not knowable when the
                // sequence above is written and hard-coding one would break
                // on any install whose segments came out in another order.
                // A slug that resolves to nothing is left out entirely
                // rather than written as 0 — `segment_id` 0 means "no
                // segment", which is the contactless run this is here to
                // prevent, so a typo must not quietly produce one.
                'trigger_config'       => isset($options['when'])
                    ? json_encode($whenConfig($options['when']), JSON_UNESCAPED_SLASHES)
                    : null,
                'exit_conditions'      => isset($options['exit'])
                    ? json_encode($options['exit'], JSON_UNESCAPED_SLASHES)
                    : null,
                'goal_event'           => $options['goal'] ?? null,
                'goal_window_hours'    => isset($options['goal']) ? (int) ($options['goal_hours'] ?? 720) : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

            $stepCount += $writeSteps($automationId, $steps);

            DB::insert('playbook_assets', [
                'playbook_id' => $playbookId,
                'asset_type'  => 'automation',
                'asset_id'    => $automationId,
            ]);

            $automationCount++;
        }
    }

    // Link the seeded pipelines and forms to the playbooks that own them, so
    // the playbook page can show its full asset inventory.
    $pipelineLinks = [
        'birthday-celebration-sales' => ['birthday'],
        'group-corporate-events'     => ['group', 'event-center'],
        'fundraising-referrals'      => ['fundraising'],
    ];

    foreach ($pipelineLinks as $playbookSlug => $pipelineSlugs) {
        $playbookId = DB::value('SELECT id FROM {playbooks} WHERE slug = :slug', ['slug' => $playbookSlug]);

        if ($playbookId === null) {
            continue;
        }

        foreach ($pipelineSlugs as $pipelineSlug) {
            $pipelineId = DB::value('SELECT id FROM {pipelines} WHERE slug = :slug', ['slug' => $pipelineSlug]);

            if ($pipelineId === null) {
                continue;
            }

            if (DB::exists(
                'SELECT id FROM {playbook_assets}
                  WHERE playbook_id = :pb AND asset_type = :t AND asset_id = :a',
                ['pb' => (int) $playbookId, 't' => 'pipeline', 'a' => (int) $pipelineId]
            )) {
                continue;
            }

            DB::insert('playbook_assets', [
                'playbook_id' => (int) $playbookId,
                'asset_type'  => 'pipeline',
                'asset_id'    => (int) $pipelineId,
            ]);
        }
    }

    return [
        'playbooks'   => $playbookCount,
        'automations' => $automationCount,
        'steps'       => $stepCount,
        'offers'      => $offerCount,
        'segments'    => $segmentCount,
    ];
};
