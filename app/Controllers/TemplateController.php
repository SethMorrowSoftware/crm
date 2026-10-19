<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Services\SmsPolicy;

/**
 * Settings → Message templates: the words guests actually receive.
 *
 * Until this existed there was no way to change a confirmation, a reminder or
 * a thank-you short of editing the database, though the permission catalogue
 * had described `templates_manage` since phase 1 and nothing used it.
 *
 * Three rules, each because getting it wrong breaks something a guest sees:
 *
 *  - **A slug never changes.** Automation steps name their template by slug,
 *    so renaming must not quietly orphan a step.
 *  - **A template a switched-on automation sends cannot be switched off.**
 *    The step would fail on its next run, and the guest would be half-told.
 *    The system's own messages (the password reset) cannot be switched off
 *    at all.
 *  - **A tag the template is not sent with is named on save.** `{{frist_name}}`
 *    comes out blank in every message, and nobody notices until a parent
 *    replies to ask who "Hi ," is.
 *
 * New templates start as a copy of an existing one: a full HTML email is not
 * something to ask a manager to write from a blank page.
 */
final class TemplateController extends Controller
{
    private const MANAGE = 'templates_manage';

    /**
     * What each tag reads as in a preview. Plausible on purpose — a preview
     * full of "[first_name]" hides exactly the line-length and wording
     * problems a preview exists to show.
     */
    public const SAMPLES = [
        'first_name' => 'Maria', 'name' => 'Maria Lopez', 'child_name' => 'Emma', 'next_age' => '7',
        'owner_name' => 'Jess', 'author_name' => 'Jess', 'invited_by' => 'Jess', 'role_name' => 'Front Desk',
        'organisation' => 'Chester Elementary PTA', 'member_number' => 'CF-10482',
        'venue_name' => 'The Castle Fun Center', 'venue_phone' => '(845) 469-2116',
        'venue_address' => '109 Brookside Ave, Chester, NY 10918',
        'package_name' => 'InflataPark Birthday', 'last_package_name' => 'Laser Tag Birthday',
        'party_date' => 'Saturday, November 14', 'party_time' => '2:00 PM', 'arrival_time' => '1:45 PM',
        'event_date' => 'Friday, December 4', 'room_name' => 'Party Room 2', 'guest_count' => '12',
        'group_size' => '45', 'headcount_deadline' => 'Monday, November 30',
        'total' => '$519.88', 'balance_due' => '$419.88', 'deposit_due' => '$100.00',
        'offer_text' => '20% off your next party', 'offer_code' => 'CASTLE20', 'offer_expires' => 'December 31',
        'birthday_month' => 'November', 'months_since_visit' => '7', 'visits_used' => '9',
        'expires_at' => 'December 31, 2026', 'expires_on' => 'December 31, 2026', 'expires_in' => '60 minutes',
        'release_date' => 'Friday, November 6', 'outstanding_count' => '3',
        'alert_subject' => 'A deal has had no reply for 48 hours', 'alert_summary' => 'Emma’s 7th birthday enquiry',
        'alert_detail' => 'First call was due Tuesday at 2:00 PM.',
        'reset_link' => '#', 'booking_link' => '#', 'payment_link' => '#', 'quote_link' => '#',
        'waiver_link' => '#', 'review_link' => '#', 'survey_link' => '#', 'record_link' => '#',
    ];

    // =================================================================
    // Reading
    // =================================================================

    public function index(Request $request): Response
    {
        $templates = DB::all(
            'SELECT id, name, slug, channel, category, subject, body, is_system, is_active, updated_at
               FROM {templates} ORDER BY channel, category, name'
        );

        $uses = $this->uses();

        foreach ($templates as &$template) {
            $template['used_by']  = $uses[(string) $template['slug']]['all'] ?? 0;
            $template['live_use'] = $uses[(string) $template['slug']]['live'] ?? 0;
        }
        unset($template);

        return $this->view('templates/index', [
            'pageTitle' => 'Message templates',
            'templates' => $templates,
        ]);
    }

    public function edit(Request $request): Response
    {
        $template = $this->find($request->routeInt('id'));

        if ($template === null) {
            return $this->notFound('That template does not exist.');
        }

        $uses = $this->uses()[(string) $template['slug']] ?? ['all' => 0, 'live' => 0];

        return $this->view('templates/form', [
            'pageTitle' => (string) $template['name'],
            'template'  => $template,
            'tags'      => self::tagsFor($template),
            'usedBy'    => (int) $uses['all'],
            'liveUse'   => (int) $uses['live'],
            'sms'       => (string) $template['channel'] === 'sms'
                ? ['describe' => SmsPolicy::describe(Mailer::mergeText((string) $template['body'], self::SAMPLES)),
                   'optOut'   => SmsPolicy::hasOptOut((string) $template['body'])]
                : null,
        ]);
    }

    /**
     * The template rendered with sample values, for the preview frame.
     *
     * GET shows what is saved; POST shows the draft in the form, so a change
     * can be looked at before it reaches anybody. The page is served with a
     * sandbox CSP, so whatever HTML a template holds runs nothing.
     */
    public function preview(Request $request): Response
    {
        $template = $this->find($request->routeInt('id'));

        if ($template === null) {
            return $this->notFound('That template does not exist.');
        }

        if ($request->method() === 'POST') {
            $template['subject'] = (string) $request->input('subject', '');
            $template['body']    = (string) $request->input('body', '');
        }

        if ((string) $template['channel'] === 'sms') {
            $text = Mailer::mergeText((string) $template['body'], self::SAMPLES);
            $html = '<!doctype html><meta charset="utf-8"><body style="font:16px/1.45 system-ui,sans-serif;'
                . 'margin:24px;max-width:22rem"><div style="background:#e9e9eb;border-radius:18px;padding:10px 14px;'
                . 'white-space:pre-wrap">' . e($text) . '</div><p style="color:#666;font-size:13px">'
                . e(SmsPolicy::describe($text)) . '</p></body>';
        } else {
            $html = Mailer::merge((string) $template['body'], self::SAMPLES);
        }

        return Response::html($html)
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline' *; font-src * data:; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    // =================================================================
    // Writing
    // =================================================================

    public function update(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $id = $request->routeInt('id');
        $template = $this->find($id);

        if ($template === null) {
            return $this->notFound('That template does not exist.');
        }

        $email = (string) $template['channel'] === 'email';

        $result = $this->validate($request, [
            'name'      => 'required|string|max:120',
            'subject'   => ($email ? 'required' : 'nullable') . '|string|max:255',
            'preheader' => 'nullable|string|max:255',
            'body'      => 'required|string|max:200000',
        ], [
            'name'      => 'Name',
            'subject'   => 'Subject',
            'preheader' => 'Preview text',
            'body'      => 'Message',
        ]);

        if ($result instanceof Response) {
            return $result;
        }

        $active = $request->bool('is_active');

        if (!$active && (int) $template['is_active'] === 1) {
            $live = $this->uses()[(string) $template['slug']]['live'] ?? 0;

            if ((int) $template['is_system'] === 1) {
                return $this->refuse(
                    $request,
                    (string) $template['name'] . ' is one of the system’s own messages, so it cannot be switched off.',
                    '/settings/templates/' . $id
                );
            }

            if ($live > 0) {
                return $this->refuse(
                    $request,
                    sprintf(
                        '%d switched-on automation%s send%s this template, so switching it off would make %s fail. '
                        . 'Switch %s off first.',
                        $live,
                        $live === 1 ? '' : 's',
                        $live === 1 ? 's' : '',
                        $live === 1 ? 'it' : 'them',
                        $live === 1 ? 'it' : 'them'
                    ),
                    '/settings/templates/' . $id
                );
            }
        }

        $columns = [
            'name'       => trim((string) $result['name']),
            'subject'    => $email ? trim((string) $result['subject']) : null,
            'preheader'  => $email && trim((string) ($result['preheader'] ?? '')) !== ''
                ? trim((string) $result['preheader'])
                : null,
            'body'       => (string) $result['body'],
            'is_active'  => $active ? 1 : 0,
            'updated_at' => now_utc(),
        ];

        DB::update('templates', $columns, 'id = :id', ['id' => $id]);
        Audit::record('template_updated', 'template', $id, $template, $columns);

        $unknown = self::unknownTags((string) $columns['subject'] . ' ' . $columns['body'], self::tagsFor($template));

        if ($unknown !== []) {
            flash('warning', 'Saved — but ' . implode(', ', array_map(static fn (string $t): string => '{{' . $t . '}}', $unknown))
                . (count($unknown) === 1 ? ' is not a tag this message is sent with, so it will come out blank.'
                    : ' are not tags this message is sent with, so they will come out blank.'));
        }

        return $this->success($request, 'Template saved.', '/settings/templates/' . $id);
    }

    /** A new template starts as a copy of one that already works. */
    public function duplicate(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $source = $this->find($request->routeInt('id'));

        if ($source === null) {
            return $this->notFound('That template does not exist.');
        }

        $name = mb_substr('Copy of ' . (string) $source['name'], 0, 120);
        $now  = now_utc();

        $newId = (int) DB::insert('templates', [
            'name'        => $name,
            'slug'        => $this->slug($name),
            'channel'     => (string) $source['channel'],
            'category'    => $source['category'],
            'subject'     => $source['subject'],
            'body'        => (string) $source['body'],
            'preheader'   => $source['preheader'],
            'merge_tags'  => $source['merge_tags'],
            'playbook_id' => null,
            'is_system'   => 0,
            'is_active'   => 1,
            'created_by'  => Auth::id(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        Audit::record('template_created', 'template', $newId, null, ['copied_from' => (int) $source['id']]);

        return $this->success($request, 'Copied. Rename it and change the words.', '/settings/templates/' . $newId);
    }

    /** A test to the person editing it, through the real send path. */
    public function test(Request $request): Response
    {
        if ($denied = $this->authorize(self::MANAGE)) {
            return $denied;
        }

        $template = $this->find($request->routeInt('id'));

        if ($template === null) {
            return $this->notFound('That template does not exist.');
        }

        if ((string) $template['channel'] !== 'email') {
            return $this->refuse($request, 'Test sends are for email templates. A text costs money and goes to a phone.',
                '/settings/templates/' . (int) $template['id']);
        }

        $to = (string) (Auth::user()['email'] ?? '');

        // Straight through Mailer::send() rather than sendTemplate(), so an
        // inactive template can be tested before it is switched on.
        $result = Mailer::send([
            'to'          => $to,
            'subject'     => '[Test] ' . Mailer::mergeText((string) $template['subject'], self::SAMPLES),
            'html'        => Mailer::merge((string) $template['body'], self::SAMPLES),
            'template_id' => (int) $template['id'],
            'kind'        => Mailer::TRANSACTIONAL,
        ]);

        return $result['sent']
            ? $this->success($request, 'Test sent to ' . $to . ', with sample details filled in.', '/settings/templates/' . (int) $template['id'])
            : $this->refuse($request, 'The test was not sent: ' . (string) $result['reason'], '/settings/templates/' . (int) $template['id']);
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * The tags a template is sent with: its own declared list, plus the
     * venue's, which every message can use.
     *
     * @param array<string,mixed> $template
     * @return list<string>
     */
    public static function tagsFor(array $template): array
    {
        $declared = json_decode((string) ($template['merge_tags'] ?? '[]'), true);
        $declared = is_array($declared) ? array_map('strval', $declared) : [];

        return array_values(array_unique(array_merge($declared, ['venue_name', 'venue_phone', 'venue_address'])));
    }

    /**
     * Tags in `$text` that are not in `$known`.
     *
     * @param list<string> $known
     * @return list<string>
     */
    public static function unknownTags(string $text, array $known): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $text, $matches);

        $used = array_unique(array_map('strtolower', $matches[1]));

        return array_values(array_diff($used, $known));
    }

    /**
     * How many automation steps send each template, and how many of those
     * belong to a switched-on automation.
     *
     * @return array<string,array{all:int,live:int}>
     */
    private function uses(): array
    {
        $uses = [];

        foreach (DB::all(
            'SELECT s.config, a.is_active FROM {automation_steps} s JOIN {automations} a ON a.id = s.automation_id'
        ) as $step) {
            $config = json_decode((string) $step['config'], true);
            $slug   = is_array($config) ? (string) ($config['template'] ?? '') : '';

            if ($slug === '') {
                continue;
            }

            $uses[$slug] ??= ['all' => 0, 'live' => 0];
            $uses[$slug]['all']++;

            if ((int) $step['is_active'] === 1) {
                $uses[$slug]['live']++;
            }
        }

        return $uses;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        return $id > 0 ? DB::first('SELECT * FROM {templates} WHERE id = :id', ['id' => $id]) : null;
    }

    private function slug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'template' : substr($base, 0, 56);
        $slug = $base;

        for ($n = 2; DB::exists('SELECT id FROM {templates} WHERE slug = :s', ['s' => $slug]); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }
}
