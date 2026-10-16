<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Backup;
use App\Core\Cache;
use App\Core\DB;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * System settings.
 *
 * Safe Mode and the sending pause live here but carry their own permission
 * (`sending_override`), because turning Safe Mode off is the moment Castle CRM
 * starts contacting real guests. That is not a settings tweak; it is a
 * decision, and it is audited as one.
 */
final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('settings/index', [
            'pageTitle' => 'Settings',
            'counts'    => [
                'users'     => (int) DB::value('SELECT COUNT(*) FROM {users} WHERE deleted_at IS NULL'),
                'roles'     => (int) DB::value('SELECT COUNT(*) FROM {roles}'),
                'playbooks' => (int) DB::value('SELECT COUNT(*) FROM {playbooks} WHERE is_active = 1'),
            ],
            'mailDriverLabel' => $this->mailDriverLabel(),
            'backupLabel'     => $this->backupLabel(),
            'apiTokenLabel'   => $this->apiTokenLabel(),
            'webhookLabel'    => $this->webhookLabel(),
        ]);
    }

    /**
     * The tile answers "is anything being told about what happens here, and is
     * it working?"
     *
     * A subscription that has switched itself off after an outage is the whole
     * reason this sentence names a state rather than just a count: it is the
     * failure a venue otherwise discovers months later, when somebody asks why
     * the spreadsheet stopped filling in.
     */
    private function webhookLabel(): string
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM {webhooks}');

        if ($total === 0) {
            return 'Nothing outside this application is being told anything.';
        }

        $off = (int) DB::value('SELECT COUNT(*) FROM {webhooks} WHERE is_active = 0');
        $on  = $total - $off;

        return $on . ' sending'
            . ($off > 0 ? ', ' . $off . ' switched off' : '') . '.';
    }

    /**
     * The tile answers "is anything outside this application reaching it?"
     *
     * A count of LIVE tokens, with expired ones named separately — a venue
     * reading "3 tokens" wants to know that one of them stopped working in
     * March rather than discovering it when an integration goes quiet.
     */
    private function apiTokenLabel(): string
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM {api_tokens}');

        if ($total === 0) {
            return 'Nothing outside this application can reach it.';
        }

        $expired = (int) DB::value(
            'SELECT COUNT(*) FROM {api_tokens} WHERE expires_at IS NOT NULL AND expires_at <= :now',
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        $live = $total - $expired;

        return $live . ' live ' . ($live === 1 ? 'token' : 'tokens')
            . ($expired > 0 ? ', ' . $expired . ' expired' : '') . '.';
    }

    /** The tile subtitle should answer "is this configured?", not just name the page. */
    private function mailDriverLabel(): string
    {
        return match (Mailer::driver()) {
            'smtp'     => 'SMTP — ' . (Settings::get('mail.smtp_host') ?: 'no host set yet'),
            'php_mail' => "Sending through this server's own mail.",
            default    => 'Not sending yet — mail is written to the log only.',
        };
    }

    private function backupLabel(): string
    {
        $count = count(Backup::all());
        $last  = Settings::get('system.backup_last_run');

        if ($count === 0) {
            return 'No backups taken yet.';
        }

        return $count . ' stored'
            . ($last !== null ? ', last ' . time_ago((string) $last) : '')
            . '.';
    }

    // -----------------------------------------------------------------
    // Venue profile
    // -----------------------------------------------------------------

    public function venue(Request $request): Response
    {
        return $this->view('settings/venue', [
            'pageTitle' => 'Venue details',
            'venue'     => Settings::group('venue'),
            'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::AMERICA),
        ]);
    }

    public function saveVenue(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'        => 'required|string|max:120',
            'email'       => 'nullable|email|max:160',
            'phone'       => 'nullable|phone',
            'address_1'   => 'nullable|string|max:160',
            'address_2'   => 'nullable|string|max:160',
            'city'        => 'nullable|string|max:80',
            'state'       => 'nullable|string|max:40',
            'postal_code' => 'nullable|string|max:20',
            'website'     => 'nullable|url|max:200',
            'timezone'    => 'required|timezone',
            'tax_rate'    => 'nullable|numeric|min:0|max:30',
            'brand_color' => 'nullable|color',
        ], [
            'name'        => 'Venue name',
            'tax_rate'    => 'Sales tax rate',
            'brand_color' => 'Brand colour',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $before = Settings::group('venue');

        Settings::setMany([
            'venue.name'        => $data['name'],
            'venue.email'       => $data['email'] ?? null,
            'venue.phone'       => normalize_phone($data['phone'] ?? null),
            'venue.phone_display' => $data['phone'] ?? null,
            'venue.address_1'   => $data['address_1'] ?? null,
            'venue.address_2'   => $data['address_2'] ?? null,
            'venue.city'        => $data['city'] ?? null,
            'venue.state'       => $data['state'] ?? null,
            'venue.postal_code' => $data['postal_code'] ?? null,
            'venue.website'     => $data['website'] ?? null,
            'venue.timezone'    => $data['timezone'],
            'venue.tax_rate'    => (float) ($data['tax_rate'] ?? 0),
            'venue.brand_color' => $data['brand_color'] ?? '#6b4ea8',
            // Ticks the dashboard checklist item.
            'venue.confirmed'   => true,
        ]);

        Cache::flush();

        Audit::record('settings.venue_updated', 'settings', null, $before, Settings::group('venue'));

        return $this->success($request, 'Venue details saved.', '/settings/venue');
    }

    // -----------------------------------------------------------------
    // System
    // -----------------------------------------------------------------

    public function system(Request $request): Response
    {
        return $this->view('settings/system', [
            'pageTitle'     => 'System',
            'system'        => Settings::group('system'),
            'safeMode'      => Settings::safeMode(),
            'sendingPaused' => Settings::sendingPaused(),
            'ownerEmail'    => (string) DB::value(
                "SELECT u.email FROM {users} u
                   JOIN {roles} r ON r.id = u.role_id
                  WHERE r.slug = 'owner' AND u.deleted_at IS NULL
                  ORDER BY u.id LIMIT 1"
            ),
        ]);
    }

    public function saveSystem(Request $request): Response
    {
        $data = $this->validate($request, [
            'quiet_hours_start'    => 'nullable|time',
            'quiet_hours_end'      => 'nullable|time',
            'message_cap_per_week' => 'nullable|int|min:0|max:50',
            'lapse_days'           => 'nullable|int|min:30|max:730',
            'attribution_days'     => 'nullable|int|min:1|max:180',
            'audit_retention_days' => 'nullable|int|min:0|max:3650',
        ], [
            'quiet_hours_start'    => 'Quiet hours start',
            'quiet_hours_end'      => 'Quiet hours end',
            'message_cap_per_week' => 'Marketing message cap',
            'lapse_days'           => 'Lapsed-guest window',
            'attribution_days'     => 'Attribution window',
            'audit_retention_days' => 'Audit log retention',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        Settings::setMany([
            'system.quiet_hours_start'    => $data['quiet_hours_start'] ?? '09:00',
            'system.quiet_hours_end'      => $data['quiet_hours_end'] ?? '20:00',
            'system.message_cap_per_week' => (int) ($data['message_cap_per_week'] ?? 4),
            'system.lapse_days'           => (int) ($data['lapse_days'] ?? 120),
            'system.attribution_days'     => (int) ($data['attribution_days'] ?? 30),
            'system.force_https'          => $request->bool('force_https'),
            'system.audit_retention_days' => (int) ($data['audit_retention_days'] ?? 730),
        ]);

        Cache::flush();
        Audit::event('settings.system_updated', $data);

        return $this->success($request, 'System settings saved.', '/settings/system');
    }

    /**
     * Turn Safe Mode on or off.
     *
     * Leaving Safe Mode requires typing the venue name to confirm — the same
     * pattern GitHub uses for destructive repository actions, and for the same
     * reason: it makes an irreversible, outward-facing change deliberate.
     */
    public function toggleSafeMode(Request $request): Response
    {
        if ($denied = $this->authorize('sending_override')) {
            return $denied;
        }

        $enable = $request->bool('enable');

        if (!$enable) {
            $typed    = trim($request->str('confirm_name'));
            $expected = Settings::venueName();

            if (strcasecmp($typed, $expected) !== 0) {
                return $this->refuse(
                    $request,
                    'To leave Safe Mode, type the venue name exactly: ' . $expected,
                    '/settings/system'
                );
            }
        }

        Settings::set('system.safe_mode', $enable, 'bool');
        Cache::flush();

        Audit::event($enable ? 'system.safe_mode_enabled' : 'system.safe_mode_disabled', [
            'note' => $enable
                ? 'Outbound messages are redirected to the owner.'
                : 'Outbound messages will now reach real guests.',
        ]);

        return $this->success(
            $request,
            $enable
                ? 'Safe Mode is on. Messages are redirected to the owner and no guest will be contacted.'
                : 'Safe Mode is off. Castle CRM can now message real guests.',
            '/settings/system'
        );
    }

    /** The global outbound kill switch. Distinct from Safe Mode: this stops sending entirely. */
    public function togglePauseSending(Request $request): Response
    {
        if ($denied = $this->authorize('sending_override')) {
            return $denied;
        }

        $pause = $request->bool('pause');

        Settings::set('system.sending_paused', $pause, 'bool');
        Cache::flush();

        Audit::event($pause ? 'system.sending_paused' : 'system.sending_resumed');

        return $this->success(
            $request,
            $pause
                ? 'All outbound sending is paused. Queued messages will wait, not fail.'
                : 'Sending has resumed.',
            '/settings/system'
        );
    }
}
