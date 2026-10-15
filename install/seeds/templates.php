<?php

declare(strict_types=1);

/**
 * Seeds the email templates Castle CRM sends itself, plus a starter library
 * the venue can edit.
 *
 * This seed is NOT optional. `Mailer::sendTemplate()` looks a template up by
 * slug and refuses to send when it is missing, so a password reset on a fresh
 * install depends on the `password-reset` row existing. Everything else here
 * is a starting point for the venue's own voice.
 *
 * Authoring notes, learned the hard way about email:
 *
 * - **Tables, not flexbox.** Outlook renders through Word's engine, which has
 *   no flexbox and no float. A centred single-column table is the only layout
 *   that survives every client.
 * - **Inline styles only.** Gmail strips <style> blocks on forwarded mail.
 * - **Merge tags are escaped.** Mailer::merge() runs every value through e(),
 *   so a tag is only ever safe for text and attribute values — never for
 *   injecting markup. A guest called "<script>" cannot smuggle anything into
 *   their own confirmation.
 * - **No images.** Nothing here depends on a hosted asset, so no template
 *   breaks when the venue moves hosts, and nothing is hidden behind a
 *   "display images?" prompt.
 *
 * Every template is re-seedable: an existing slug is left exactly as the
 * venue edited it, so re-running the installer never overwrites their work.
 *
 * @return array{templates:int,skipped:int}
 */

use App\Core\DB;

return static function (): array {
    $now = gmdate('Y-m-d H:i:s');

    // Palette kept in step with app/assets/css — see docs/SPEC.md §11.
    $ink    = '#1f2430';
    $muted  = '#5c6475';
    $brand  = '#6b4ea8';
    $rule   = '#e4e6ec';
    $paper  = '#f5f6f9';

    /**
     * Wrap body markup in the shared shell.
     *
     * @param string $heading Plain text; merge tags allowed.
     * @param string $body    Markup; must already be email-safe.
     * @param array{label:string,url:string}|null $cta
     */
    $shell = static function (string $heading, string $body, ?array $cta = null, string $footnote = '') use (
        $ink,
        $muted,
        $brand,
        $rule,
        $paper
    ): string {
        $button = '';

        if ($cta !== null) {
            // A table-wrapped anchor: Outlook ignores padding on an inline
            // element, so the padding has to live on the cell.
            $button = <<<HTML

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0;">
                            <tr>
                                <td align="center" bgcolor="{$brand}" style="border-radius:6px;">
                                    <a href="{$cta['url']}" style="display:inline-block;padding:13px 26px;font-family:Helvetica,Arial,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">{$cta['label']}</a>
                                </td>
                            </tr>
                        </table>
            HTML;
        }

        $footnoteBlock = $footnote === '' ? '' : <<<HTML

                        <p style="margin:22px 0 0;padding-top:18px;border-top:1px solid {$rule};font-family:Helvetica,Arial,sans-serif;font-size:13px;line-height:20px;color:{$muted};">{$footnote}</p>
            HTML;

        return <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$paper};padding:24px 0;">
            <tr>
                <td align="center">
                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid {$rule};border-radius:8px;">
                        <tr>
                            <td style="padding:28px 32px 0;font-family:Helvetica,Arial,sans-serif;font-size:14px;font-weight:bold;letter-spacing:0.08em;text-transform:uppercase;color:{$brand};">{{venue_name}}</td>
                        </tr>
                        <tr>
                            <td style="padding:14px 32px 32px;">
                                <h1 style="margin:0 0 16px;font-family:Helvetica,Arial,sans-serif;font-size:23px;line-height:30px;color:{$ink};">{$heading}</h1>
        {$body}{$button}{$footnoteBlock}
                            </td>
                        </tr>
                    </table>
                    <p style="margin:16px 0 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:{$muted};">{{venue_name}}</p>
                </td>
            </tr>
        </table>
        HTML;
    };

    /** A standard paragraph. */
    $p = static fn (string $text): string =>
        '                                <p style="margin:0 0 14px;font-family:Helvetica,Arial,sans-serif;'
        . 'font-size:16px;line-height:24px;color:' . $ink . ';">' . $text . '</p>';

    // ------------------------------------------------------------------
    // The templates
    // ------------------------------------------------------------------
    //
    // [slug, name, category, subject, preheader, merge tags, body, is_system]
    //
    // is_system marks a template the application itself sends by slug. Those
    // must not be deleted, and the templates UI will refuse to: losing
    // `password-reset` would mean nobody can recover an account.

    $templates = [
        // --- Account and access (system) ------------------------------
        [
            'slug'      => 'password-reset',
            'name'      => 'Password reset',
            'category'  => 'account',
            'subject'   => 'Reset your {{venue_name}} password',
            'preheader' => 'The link is good for {{expires_in}}.',
            'tags'      => ['name', 'reset_link', 'venue_name', 'expires_in'],
            'is_system' => true,
            'body'      => $shell(
                'Reset your password',
                $p('Hello {{name}},')
                . "\n" . $p('Somebody asked to reset the password for your {{venue_name}} account. '
                    . 'If that was you, use the button below.')
                . "\n" . $p('The link works once and expires in {{expires_in}}.'),
                ['label' => 'Choose a new password', 'url' => '{{reset_link}}'],
                'If you did not ask for this, you can ignore this email — your password has not changed. '
                . 'If the button does not work, copy this address into your browser:<br>'
                . '<span style="word-break:break-all;">{{reset_link}}</span>'
            ),
        ],
        [
            'slug'      => 'user-invitation',
            'name'      => 'Staff invitation',
            'category'  => 'account',
            'subject'   => 'Your {{venue_name}} CRM account is ready',
            'preheader' => 'Set a password and you are in.',
            'tags'      => ['name', 'invited_by', 'role_name', 'reset_link', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'Welcome to the team',
                $p('Hello {{name}},')
                . "\n" . $p('{{invited_by}} has set you up with a {{venue_name}} CRM account as '
                    . '<strong>{{role_name}}</strong>. Choose a password and you can sign in.')
                . "\n" . $p('Use a password you do not use anywhere else — this account can see guest details.'),
                ['label' => 'Set your password', 'url' => '{{reset_link}}'],
                'This invitation link expires in 24 hours. Ask {{invited_by}} for a new one if it lapses.'
            ),
        ],

        // --- Parties and bookings -------------------------------------
        [
            'slug'      => 'party-inquiry-received',
            'name'      => 'Party inquiry — instant reply',
            'category'  => 'parties',
            'subject'   => 'We got your party inquiry, {{first_name}}!',
            'preheader' => 'A real person will be in touch shortly.',
            'tags'      => ['first_name', 'child_name', 'preferred_date', 'owner_name', 'venue_phone', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'Thanks for thinking of us',
                $p('Hi {{first_name}},')
                . "\n" . $p('We have your inquiry about a party for {{child_name}} around '
                    . '{{preferred_date}}. {{owner_name}} will come back to you with options and '
                    . 'availability shortly — usually within the hour while we are open.')
                . "\n" . $p('If it is easier to talk it through, call us on {{venue_phone}}.'),
                null,
                'Replies to this email reach us directly.'
            ),
        ],
        [
            'slug'      => 'party-quote',
            'name'      => 'Party quote',
            'category'  => 'parties',
            'subject'   => '{{child_name}}\'s party at {{venue_name}} — your quote',
            'preheader' => 'Everything included, and what it comes to.',
            'tags'      => ['first_name', 'child_name', 'package_name', 'party_date', 'party_time',
                'guest_count', 'total', 'deposit_due', 'quote_link', 'expires_on', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'Your party quote',
                $p('Hi {{first_name}},')
                . "\n" . $p('Here is what we have put together for {{child_name}}:')
                . "\n" . '                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" '
                . 'style="width:100%;margin:0 0 18px;font-family:Helvetica,Arial,sans-serif;font-size:15px;'
                . 'line-height:22px;color:' . $ink . ';">'
                . "\n" . '                                    <tr><td style="padding:7px 0;border-bottom:1px solid ' . $rule . ';color:' . $muted . ';">Package</td>'
                . '<td style="padding:7px 0;border-bottom:1px solid ' . $rule . ';text-align:right;"><strong>{{package_name}}</strong></td></tr>'
                . "\n" . '                                    <tr><td style="padding:7px 0;border-bottom:1px solid ' . $rule . ';color:' . $muted . ';">Date</td>'
                . '<td style="padding:7px 0;border-bottom:1px solid ' . $rule . ';text-align:right;">{{party_date}} at {{party_time}}</td></tr>'
                . "\n" . '                                    <tr><td style="padding:7px 0;border-bottom:1px solid ' . $rule . ';color:' . $muted . ';">Guests</td>'
                . '<td style="padding:7px 0;border-bottom:1px solid ' . $rule . ';text-align:right;">{{guest_count}}</td></tr>'
                . "\n" . '                                    <tr><td style="padding:7px 0;color:' . $muted . ';">Total</td>'
                . '<td style="padding:7px 0;text-align:right;font-size:18px;"><strong>{{total}}</strong></td></tr>'
                . "\n" . '                                </table>'
                . "\n" . $p('A deposit of {{deposit_due}} holds the date. The rest is due on the day.'),
                ['label' => 'View and accept', 'url' => '{{quote_link}}'],
                'This quote holds until {{expires_on}}. Dates are first come, first served until the deposit is in.'
            ),
        ],
        [
            'slug'      => 'booking-confirmation',
            'name'      => 'Booking confirmed',
            'category'  => 'bookings',
            'subject'   => 'Confirmed: {{party_date}} at {{venue_name}}',
            'preheader' => 'Everything you need for the day.',
            'tags'      => ['first_name', 'child_name', 'package_name', 'party_date', 'party_time',
                'arrival_time', 'room_name', 'guest_count', 'balance_due', 'waiver_link',
                'venue_address', 'venue_phone', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'You are booked in',
                $p('Hi {{first_name}},')
                . "\n" . $p('{{child_name}}\'s party is confirmed for <strong>{{party_date}} at '
                    . '{{party_time}}</strong> in {{room_name}}, with {{guest_count}} guests on the '
                    . '{{package_name}}.')
                . "\n" . $p('<strong>Arrive at {{arrival_time}}</strong> — fifteen minutes early lets us '
                    . 'get the room ready with you rather than around you.')
                . "\n" . $p('A balance of {{balance_due}} is due on the day. Every guest needs a signed '
                    . 'waiver before they play, and doing it in advance saves a queue at the desk.'),
                ['label' => 'Sign waivers now', 'url' => '{{waiver_link}}'],
                'We are at {{venue_address}}. Parking is free and right outside. '
                . 'Anything at all, call {{venue_phone}}.'
            ),
        ],
        [
            'slug'      => 'deposit-request',
            'name'      => 'Deposit request',
            'category'  => 'bookings',
            'subject'   => 'Hold {{party_date}} with a deposit',
            'preheader' => 'The date is yours once the deposit is in.',
            'tags'      => ['first_name', 'child_name', 'party_date', 'deposit_due', 'release_date',
                'payment_link', 'venue_phone', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'One step to lock in the date',
                $p('Hi {{first_name}},')
                . "\n" . $p('We are holding {{party_date}} for {{child_name}}, and a deposit of '
                    . '{{deposit_due}} makes it final.')
                . "\n" . $p('We can only hold an unpaid date until {{release_date}}, after which it goes '
                    . 'back out to other families — weekends go quickly.'),
                ['label' => 'Pay the deposit', 'url' => '{{payment_link}}'],
                'Prefer to pay over the phone? Call {{venue_phone}} and we will take it there.'
            ),
        ],
        [
            'slug'      => 'party-thank-you',
            'name'      => 'Post-party thank you',
            'category'  => 'bookings',
            'subject'   => 'Thank you from {{venue_name}}',
            'preheader' => 'We hope {{child_name}} had a good one.',
            'tags'      => ['first_name', 'child_name', 'review_link', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'Thank you for celebrating with us',
                $p('Hi {{first_name}},')
                . "\n" . $p('Thank you for bringing {{child_name}}\'s party to us. We hope it was '
                    . 'everything you hoped for.')
                . "\n" . $p('If you have a minute, telling other parents how it went genuinely helps a '
                    . 'local business like ours.'),
                ['label' => 'Leave a review', 'url' => '{{review_link}}'],
                'If anything fell short, reply to this email and tell us instead — we would rather hear '
                . 'it from you and put it right.'
            ),
        ],

        // --- Marketing ---------------------------------------------------
        //
        // Mailer::appendCompliance() adds the postal address and unsubscribe
        // line to every marketing send, so these bodies deliberately do not
        // hard-code one. It cannot be forgotten and it cannot be duplicated.
        [
            'slug'      => 'birthday-club-invite',
            'name'      => 'Birthday club invitation',
            'category'  => 'marketing',
            'subject'   => '{{child_name}} turns {{next_age}} soon — shall we throw the party?',
            'preheader' => 'Last year\'s package, ready to go.',
            'tags'      => ['first_name', 'child_name', 'next_age', 'birthday_month',
                'last_package_name', 'booking_link', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                '{{child_name}}\'s birthday is coming',
                $p('Hi {{first_name}},')
                . "\n" . $p('Our calendar says {{child_name}} turns {{next_age}} in {{birthday_month}}. '
                    . 'The good dates get taken early, so here is first refusal.')
                . "\n" . $p('Last time you chose the {{last_package_name}} — we can set the same thing up '
                    . 'again in a couple of clicks, or change it however you like.'),
                ['label' => 'See available dates', 'url' => '{{booking_link}}']
            ),
        ],
        [
            'slug'      => 'lapsed-guest-winback',
            'name'      => 'Lapsed guest win-back',
            'category'  => 'marketing',
            'subject'   => 'It has been a while, {{first_name}}',
            'preheader' => 'Here is what is new since your last visit.',
            'tags'      => ['first_name', 'months_since_visit', 'offer_text', 'offer_code',
                'offer_expires', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'We have missed you',
                $p('Hi {{first_name}},')
                . "\n" . $p('It has been about {{months_since_visit}} months since we last saw you at '
                    . '{{venue_name}}, and a fair bit has changed.')
                . "\n" . $p('{{offer_text}}')
                . "\n" . $p('Use code <strong>{{offer_code}}</strong> before {{offer_expires}}.')
            ),
        ],
        [
            'slug'      => 'group-event-followup',
            'name'      => 'Group & field trip follow-up',
            'category'  => 'marketing',
            'subject'   => 'Planning a group visit to {{venue_name}}?',
            'preheader' => 'Group rates, one invoice, no queueing.',
            'tags'      => ['first_name', 'organisation', 'group_size', 'owner_name',
                'venue_phone', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'Bringing a group?',
                $p('Hi {{first_name}},')
                . "\n" . $p('We host school trips, camps, church groups and company outings for '
                    . '{{organisation}}-sized parties all season. For around {{group_size}} people we can '
                    . 'put together a rate, one invoice, and a schedule that keeps everyone moving.')
                . "\n" . $p('{{owner_name}} handles group bookings and can talk through dates — '
                    . '{{venue_phone}}.')
            ),
        ],

        // --- Operational ---------------------------------------------------
        [
            'slug'      => 'waiver-reminder',
            'name'      => 'Waiver reminder',
            'category'  => 'operations',
            'subject'   => '{{outstanding_count}} waivers still to sign for {{party_date}}',
            'preheader' => 'Two minutes now saves a queue on the day.',
            'tags'      => ['first_name', 'party_date', 'outstanding_count', 'waiver_link', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                'Still waiting on a few waivers',
                $p('Hi {{first_name}},')
                . "\n" . $p('{{outstanding_count}} of your guests for {{party_date}} have not signed a '
                    . 'waiver yet. Anyone without one has to do it at the desk before they can play, '
                    . 'which eats into your party time.')
                . "\n" . $p('Forwarding this email to the other parents is the quickest fix.'),
                ['label' => 'Sign a waiver', 'url' => '{{waiver_link}}']
            ),
        ],
        [
            'slug'      => 'internal-alert',
            'name'      => 'Internal alert to staff',
            'category'  => 'operations',
            'subject'   => '[{{venue_name}}] {{alert_subject}}',
            'preheader' => '{{alert_summary}}',
            'tags'      => ['alert_subject', 'alert_summary', 'alert_detail', 'record_link', 'venue_name'],
            'is_system' => false,
            'body'      => $shell(
                '{{alert_subject}}',
                $p('{{alert_summary}}')
                . "\n" . $p('{{alert_detail}}'),
                ['label' => 'Open in Castle CRM', 'url' => '{{record_link}}'],
                'You are getting this because of your notification settings in Castle CRM.'
            ),
        ],
    ];

    $created = 0;
    $skipped = 0;

    foreach ($templates as $template) {
        // Re-seedable: never clobber a template the venue has edited.
        if (DB::exists('SELECT id FROM {templates} WHERE slug = :slug', ['slug' => $template['slug']])) {
            $skipped++;
            continue;
        }

        DB::insert('templates', [
            'name'       => $template['name'],
            'slug'       => $template['slug'],
            'channel'    => 'email',
            'category'   => $template['category'],
            'subject'    => $template['subject'],
            'body'       => $template['body'],
            'preheader'  => $template['preheader'],
            'merge_tags' => json_encode($template['tags'], JSON_THROW_ON_ERROR),
            'is_system'  => $template['is_system'] ? 1 : 0,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created++;
    }

    return ['templates' => $created, 'skipped' => $skipped];
};
