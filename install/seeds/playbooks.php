<?php

declare(strict_types=1);

/**
 * Seeds the 8 Revenue Playbooks and their automation library.
 *
 * Every automation ships INACTIVE and every playbook ships DEACTIVATED.
 * Activation is always a deliberate human act, and Safe Mode still applies on
 * top of that — a fresh install cannot message a guest even by accident.
 *
 * @return array{playbooks:int,automations:int}
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
            'automations' => [
                ['Instant inquiry response', 'form.submitted', 'Email and text the parent within two minutes, create a task for the owner, and start the response timer.'],
                ['Assign new party lead', 'deal.created', 'Round-robin a new birthday lead to an available host and notify them.'],
                ['Quote nurture — 9 touches', 'quote.sent', 'A twelve-day sequence of reminders, a call task and a final incentive. Exits the moment the deal is won.'],
                ['Quote viewed — strike while warm', 'quote.viewed', 'Alert the owner that the family just opened the quote, with a task to call now.'],
                ['Quote accepted — move to held', 'quote.accepted', 'Move the deal to Date Held, create the booking, and send the deposit request.'],
                ['Deposit chase', 'booking.held', 'Day 1 email, day 3 text, day 5 release warning, day 7 release the date and notify the host.'],
                ['Deposit received — confirm', 'booking.deposit_paid', 'Send the confirmation with directions, parking and what to bring.'],
                ['Pre-party readiness — T-14 waivers', 'booking.upcoming', 'Send the waiver link to the host parent fourteen days out.'],
                ['Pre-party readiness — T-7 headcount', 'booking.upcoming', 'Ask the parent to confirm the final headcount a week out.'],
                ['Pre-party readiness — T-3 details', 'booking.upcoming', 'Send the full party plan, timings, allergy check and cake arrangements.'],
                ['Pre-party reminder — T-1 text', 'booking.upcoming', 'A short text the day before with the arrival time and room.'],
                ['Waiver chase', 'booking.waiver_outstanding', 'Remind the host parent about missing waivers, then create a task for the front desk.'],
                ['Day-of host brief', 'booking.upcoming', 'Email the assigned host their run sheet on the morning of the party.'],
                ['Party completed — thank you', 'booking.completed', 'Thank the parent and tee up the feedback request.'],
                ['Annual rebook — child birthday', 'child.birthday_soon', 'Forty-five days before a child\'s birthday, email the guardian with last year\'s package pre-filled.'],
                ['Sibling birthday cross-sell', 'child.birthday_soon', 'When a household has another child with a birthday coming, offer the same package.'],
                ['Cancelled party — win it back', 'booking.cancelled', 'Acknowledge the cancellation, then offer a new date after a week.'],
                ['No-show follow-up', 'booking.no_show', 'Create a task to call, and hold the deposit decision with a manager.'],
                ['Lost lead — 90 day re-approach', 'deal.lost', 'Three months after a lost birthday lead, try once more with a seasonal offer.'],
                ['Guest-of-honour age milestone', 'booking.completed', 'Tag the household with the child\'s new age so next year\'s offer fits.'],
                ['Party room upsell', 'booking.confirmed', 'Offer add-ons the family did not take, two weeks out.'],
                ['Large party VIP handling', 'booking.confirmed', 'For parties above a guest threshold, assign a manager and create a planning call task.'],
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
            'automations' => [
                ['Instant group inquiry response', 'form.submitted', 'Acknowledge within minutes with your group information pack, and task the owner.'],
                ['Qualify the group', 'deal.created', 'Create a qualification task covering headcount, date flexibility and budget.'],
                ['Proposal follow-up ladder', 'quote.sent', 'Group buyers are slower than parents: a 21-day sequence pitched at a planning cycle.'],
                ['Site visit booked — prepare', 'deal.stage_changed', 'Send directions and a visit agenda, and brief the host.'],
                ['Contract chase', 'deal.stage_changed', 'Chase a signed contract or PO at day 3, 7 and 14 after a verbal commitment.'],
                ['Headcount firming — T-21', 'booking.upcoming', 'First formal headcount request three weeks out.'],
                ['Headcount firming — T-10', 'booking.upcoming', 'Second request, with the catering deadline named.'],
                ['Headcount firming — T-3', 'booking.upcoming', 'Final numbers, escalating to a phone task if unanswered.'],
                ['Group waiver pack', 'booking.confirmed', 'Send the bulk waiver link and a chaperone brief.'],
                ['Chaperone brief', 'booking.upcoming', 'Two days out, send the chaperone ratio, rules and arrival process.'],
                ['Invoice issued', 'booking.confirmed', 'Create the invoice task and send payment terms for PO-based groups.'],
                ['Balance due reminder', 'booking.upcoming', 'Remind a group of its outstanding balance a week before the event.'],
                ['Post-event thank you and feedback', 'booking.completed', 'Thank the organiser and ask the one question that matters.'],
                ['Annual recurrence — rebook the school', 'company.recurring_due', 'Eight months after last year\'s trip, email the teacher and task the owner.'],
                ['Camp season planning', 'cron', 'Each February, reach out to every camp that visited last summer.'],
                ['Corporate holiday party outreach', 'cron', 'Each September, offer the Event Center to local companies for the holiday season.'],
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
            'automations' => [
                ['Text Club double opt-in', 'contact.consent_granted', 'Confirm the opt-in before anything else is sent. No confirmation, no messages.'],
                ['Text Club welcome coupon', 'text_club.confirmed', 'Deliver the joining offer immediately, while intent is high.'],
                ['Monthly offer', 'cron', 'On the first of each month, send that month\'s offer to confirmed subscribers only.'],
                ['Birthday month coupon', 'contact.birthday_soon', 'A coupon to the guardian during their birthday month.'],
                ['Slow day flash', 'manual', 'A same-day offer to nearby subscribers, inside quiet hours and under the frequency cap.'],
                ['Lapsed guest — touch 1', 'contact.lapsed', 'After the lapse window, a light "we miss you".'],
                ['Lapsed guest — touch 2', 'contact.lapsed', 'Two weeks later, a real offer.'],
                ['Lapsed guest — touch 3', 'contact.lapsed', 'A final offer, then move them to dormant and stop.'],
                ['Post-visit return offer', 'visit.recorded', 'Three days after a visit, an offer timed to the next natural trip.'],
                ['Weekend reminder', 'cron', 'A Thursday reminder of weekend hours and what is open.'],
                ['Coupon expiring', 'coupon.issued', 'Remind a guest two days before their coupon expires.'],
                ['Opt-out handling', 'contact.consent_revoked', 'Honour STOP instantly, confirm once, and never message again.'],
                ['Re-engagement of silent subscribers', 'cron', 'Ask subscribers who never redeem whether they still want texts.'],
                ['List growth report', 'cron', 'Weekly: joins, confirmations, opt-outs and redemption rate.'],
                ['New attraction announcement', 'manual', 'Tell the list when something new opens.'],
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
            'automations' => [
                ['Response deadline breached', 'deal.sla_breached', 'Notify the owner, then the manager two hours later, then reassign at four.'],
                ['Stale deal — no activity', 'deal.idle', 'After seven quiet days, a task for the owner and a note on the deal.'],
                ['Very stale deal — manager review', 'deal.idle', 'After twenty-one days, surface it to a manager for a decision.'],
                ['Unanswered conversation', 'conversation.unanswered', 'A guest message unanswered for four hours alerts the assigned rep.'],
                ['Unassigned lead', 'deal.created', 'A lead with no owner after fifteen minutes goes round-robin and notifies the manager.'],
                ['Daily rep digest', 'cron', 'At 6:30am: today\'s tasks, overdue items, breaching deals and today\'s parties.'],
                ['Weekly manager scorecard', 'cron', 'Monday 7am: per-rep leads, median first response, quotes sent, win rate and revenue.'],
                ['Quote with no follow-up', 'quote.sent', 'If nothing happens three days after a quote, task the owner.'],
                ['Deal missing a next action', 'deal.stage_changed', 'Require a next action on every open deal; prompt when it is missing.'],
                ['Won deal — handoff check', 'deal.won', 'Confirm the booking exists, the host is assigned and the deposit is tracked.'],
                ['Lost deal — reason required', 'deal.lost', 'Prompt for a loss reason so the win/loss report is worth reading.'],
                ['Monthly pipeline hygiene', 'cron', 'List deals with past event dates still marked open, for cleanup.'],
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

    $playbookCount = 0;
    $automationCount = 0;

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

        foreach ($playbook['automations'] as $index => [$name, $trigger, $description]) {
            $slug = $playbook['slug'] . '-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            if (DB::exists('SELECT id FROM {automations} WHERE slug = :slug', ['slug' => $slug])) {
                continue;
            }

            $automationId = DB::insert('automations', [
                'playbook_id'          => $playbookId,
                'name'                 => $name,
                'slug'                 => $slug,
                'description'          => $description,
                'trigger_type'         => $trigger,
                'trigger_config'       => null,
                // Inactive. Nothing seeded here can send anything until a
                // person reviews it and switches it on.
                'is_active'            => 0,
                'run_once_per_contact' => 1,
                'quiet_hours_respect'  => 1,
                'priority'             => 100,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

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
    ];
};
