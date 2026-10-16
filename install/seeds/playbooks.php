<?php

declare(strict_types=1);

/**
 * Seeds the 8 Revenue Playbooks and their automation library.
 *
 * Every automation ships INACTIVE and every playbook ships DEACTIVATED.
 * Activation is always a deliberate human act, and Safe Mode still applies on
 * top of that — a fresh install cannot message a guest even by accident.
 *
 * @return array{playbooks:int,automations:int,steps:int,offers:int}
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
            'automations' => [
                ['First visit — welcome', 'visit.first', 'Day 1 thank you, day 3 "what to try next", day 10 a reason to return.'],
                ['Returning guest — recognise them', 'visit.recorded', 'Tag frequency bands so later messages can speak to a regular differently.'],
                ['Pre-visit preparation', 'booking.upcoming', 'Height rules, socks for Ballocity, parking and what the Castle Card is.'],
                ['Weather advisory for outdoor bookings', 'booking.upcoming', 'Two days out, if the forecast threatens an outdoor-led party, offer a rebooking.'],
                ['Abandoned ticket cart', 'ticket.abandoned', 'An hour later, one reminder with the cart restored.'],
                ['Ticket purchased — prepare', 'ticket.purchased', 'Confirm, add to calendar, and set expectations for the day.'],
                ['High spender recognition', 'visit.recorded', 'When a visit exceeds a spend threshold, tag the household for VIP treatment.'],
                ['Tenth visit milestone', 'visit.recorded', 'Recognise a tenth visit with a thank-you and a small offer.'],
                ['Lapsing soon — pre-emptive nudge', 'contact.lapsed', 'Before a guest is fully lapsed, one light "we miss you" touch.'],
                ['Seasonal reopening announcement', 'cron', 'When outdoor attractions come back into season, tell the people who used them.'],
                ['Rainy day indoor push', 'manual', 'A same-day text to local guests when the weather turns, respecting quiet hours.'],
                ['School holiday reminder', 'cron', 'Ahead of each local school break, remind families the Castle is open.'],
                ['Castle Card balance nudge', 'cron', 'Remind guests holding a balance that it is waiting for them.'],
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
            'automations' => [
                ['Post-visit feedback request', 'booking.completed', 'Twenty hours after a party, ask the one question that predicts a review.'],
                ['Promoter — ask for the review', 'survey.responded', 'A 9 or 10 gets a thank-you and a direct link, rotating across platforms.'],
                ['Passive — ask what was missing', 'survey.responded', 'A 7 or 8 gets an open question instead of a review ask.'],
                ['Detractor — escalate immediately', 'survey.detractor', 'No review ask. A manager alert by email, text and in-app, plus a task with a four-hour deadline.'],
                ['Detractor resolved — recovery offer', 'feedback.resolved', 'Once a manager has resolved it, send the agreed goodwill offer.'],
                ['Unresolved complaint escalation', 'task.overdue', 'If a detractor task passes its deadline, escalate to the general manager.'],
                ['New low review alert', 'review.received', 'A public review of three stars or fewer pages a manager at once.'],
                ['Review reply reminder', 'review.received', 'Task someone to reply to any new review within two days.'],
                ['Positive review — thank and feature', 'review.received', 'Thank a five-star reviewer and flag the review for the website wall.'],
                ['Visit feedback for non-party guests', 'visit.recorded', 'Ask ordinary visitors for feedback too, not just party hosts.'],
                ['Monthly reputation digest', 'cron', 'Email managers the rating trend, new reviews and unresolved complaints.'],
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
            'automations' => [
                ['Pass welcome series', 'membership.started', 'Day 0 welcome and how to use it, day 7 what to try, day 30 a check-in.'],
                ['Unused pass nudge', 'membership.usage_idle', 'After five weeks with no visit, remind them the pass is waiting.'],
                ['Renewal — 30 days out', 'membership.expiring', 'The first renewal notice, with what they got out of the pass this year.'],
                ['Renewal — 14 days out', 'membership.expiring', 'A second notice with the early-renewal incentive.'],
                ['Renewal — 3 days out', 'membership.expiring', 'Final notice, and a task to call a high-value member.'],
                ['Expiry day', 'membership.expired', 'Tell them it has lapsed and how to restart in one click.'],
                ['Win-back — 14 days after expiry', 'membership.lapsed', 'First win-back touch with a modest offer.'],
                ['Win-back — 45 days after expiry', 'membership.lapsed', 'Second touch with a stronger offer.'],
                ['Win-back — 90 days after expiry', 'membership.lapsed', 'Final touch, then stop. Repeated asking costs goodwill.'],
                ['Family plan upgrade', 'membership.started', 'Where a household holds several single passes, offer the family plan.'],
                ['Pass holder — party cross-sell', 'child.birthday_soon', 'Offer pass-holding families a birthday package at their member rate.'],
                ['Cancellation — ask why', 'membership.cancelled', 'One short question, so the churn report has a real reason in it.'],
                ['Heavy user recognition', 'membership.usage_idle', 'Thank the members who visit most; they are your advocates.'],
                ['Monthly membership report', 'cron', 'Active count, renewal rate, utilisation and churn, emailed to managers.'],
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
                    'Started by hand on a quiet day. Write the text here first — the wording is different every time, so nothing is shipped for it.',
                    [],
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
                    'Started by hand when something new opens. The wording is yours — write it here, then start it for a segment.',
                    [],
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
            'automations' => [
                ['Donation request received', 'donation.requested', 'Acknowledge the request, task the general manager, and check it against the annual budget.'],
                ['Donation decision reminder', 'cron', 'Nudge the approver about requests still undecided after seven days.'],
                ['Donation approved — issue certificate', 'donation.approved', 'Send the numbered certificate with its value, expiry and terms.'],
                ['Donation declined — decline kindly', 'donation.declined', 'A respectful decline that leaves the relationship intact.'],
                ['Certificate expiring', 'certificate.expiring', 'Three weeks before expiry, remind the organisation to use it.'],
                ['Certificate redeemed — capture the contact', 'coupon.redeemed', 'When a certificate is redeemed, invite the redeeming guest to join the list.'],
                ['Referral converted — reward', 'referral.converted', 'Reward the referrer as soon as the referred booking completes.'],
                ['Advocate outreach', 'cron', 'Each quarter, ask your most frequent guests to refer a friend.'],
            ],
        ],
    ];

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
                'trigger_config'       => isset($options['when'])
                    ? json_encode($options['when'], JSON_UNESCAPED_SLASHES)
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
    ];
};
