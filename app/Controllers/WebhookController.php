<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Http;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\WebhookDispatcher;
use App\Services\Webhooks;

/**
 * Outbound webhook subscriptions.
 *
 * Behind `integrations_manage`, like API tokens and the mail settings, and
 * for the same reason: this page sends the venue's data to a URL somebody
 * typed, and that is a different decision from setting opening hours.
 *
 * **THE SECRET IS SHOWN ONCE.** Same rule as a token: a receiver needs it to
 * verify a signature, and after that nothing here does. It is stored because
 * every delivery has to be signed with it — so unlike a token it cannot be
 * hashed — which makes it all the more important that it is not also sitting
 * on a page in a browser tab somebody left open.
 */
final class WebhookController extends Controller
{
    public const PERMISSION = 'integrations_manage';

    /** Deliveries shown on the page. A log, not an archive. */
    private const RECENT = 40;

    public function index(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $webhooks = DB::all(
            'SELECT w.*, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM {webhook_deliveries} d
                      WHERE d.webhook_id = w.id AND d.status = :pending) AS pending
               FROM {webhooks} w
               LEFT JOIN {users} u ON u.id = w.created_by
              ORDER BY w.id DESC',
            ['pending' => 'pending']
        );

        return $this->view('settings/webhooks', [
            'pageTitle'  => 'Webhooks',
            'webhooks'   => $webhooks,
            'events'     => Webhooks::availableEvents(),
            'deliveries' => DB::all(
                'SELECT d.*, w.name AS webhook_name, w.target_url
                   FROM {webhook_deliveries} d
                   LEFT JOIN {webhooks} w ON w.id = d.webhook_id
                  ORDER BY d.id DESC
                  LIMIT ' . self::RECENT
            ),
            'freshSecret' => Session::pull('webhook_secret'),
            'testResult'  => Session::pull('webhook_test'),
            'maxAttempts' => Webhooks::MAX_ATTEMPTS,
            'disableAfter' => Webhooks::DISABLE_AFTER,
        ]);
    }

    public function store(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $data = $this->validate($request, [
            'name'       => 'required|max:120',
            'event'      => 'required|max:64',
            'target_url' => 'required|max:500',
        ], ['name' => 'Name', 'event' => 'Event', 'target_url' => 'URL']);

        if ($data instanceof Response) {
            return $data;
        }

        $event = trim((string) $data['event']);

        if (!Webhooks::isSubscribable($event)) {
            // A subscription to an event nothing fires would never do
            // anything, with nothing on the page to say why.
            flash('error', 'Nothing fires "' . $event . '", so a subscription to it would never deliver.');

            return $this->redirect('/settings/webhooks');
        }

        $url = trim((string) $data['target_url']);

        /*
         * REFUSED AT SAVE TIME, not at delivery time.
         *
         * A URL pointing at this server or inside the network is refused
         * here, where somebody is reading the error, rather than failing
         * silently in a cron tick at four in the morning. The DNS check runs
         * too: a hostname that resolves inwards is the shape that matters for
         * a URL somebody pasted, and it is the one the address check alone
         * cannot see.
         */
        $refusal = Http::refuse($url) ?? Http::resolveSafely($url)['reason'];

        if ($refusal !== null) {
            flash('error', 'That URL cannot be used: ' . $refusal . '.');

            return $this->redirect('/settings/webhooks');
        }

        $secret = Webhooks::newSecret();

        $id = DB::insert('webhooks', [
            'name'       => (string) $data['name'],
            'event'      => $event,
            'target_url' => $url,
            'secret'     => $secret,
            'is_active'  => 1,
            'created_by' => Auth::id(),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::event('webhook_created', [
            'webhook_id' => $id,
            'event'      => $event,
            // The URL, because where a venue's data is being sent is exactly
            // what an audit log is for. Never the secret.
            'target_url' => $url,
        ], 'webhooks', $id);

        Session::put('webhook_secret', ['id' => $id, 'secret' => $secret]);

        flash('success', 'Subscription created. Copy the signing secret now — it is not shown again.');

        return $this->redirect('/settings/webhooks');
    }

    public function toggle(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $webhook = DB::first('SELECT * FROM {webhooks} WHERE id = :id', ['id' => $id]);

        if ($webhook === null) {
            flash('error', 'That subscription is gone.');

            return $this->redirect('/settings/webhooks');
        }

        $active = (int) $webhook['is_active'] === 1 ? 0 : 1;

        DB::update('webhooks', [
            'is_active'  => $active,
            // Switching one back on clears the counter AND the reason: a
            // venue that has fixed the endpoint should not have to wait for
            // twenty more failures before the message goes away.
            'consecutive_failures' => $active === 1 ? 0 : (int) $webhook['consecutive_failures'],
            'disabled_reason'      => $active === 1 ? null : $webhook['disabled_reason'],
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        Audit::event($active === 1 ? 'webhook_enabled' : 'webhook_disabled', [
            'webhook_id' => $id,
            'name'       => (string) $webhook['name'],
        ], 'webhooks', $id);

        flash('success', $active === 1 ? 'Switched on.' : 'Switched off. Nothing more will be sent to it.');

        return $this->redirect('/settings/webhooks');
    }

    public function destroy(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $id      = $request->routeInt('id');
        $webhook = DB::first('SELECT * FROM {webhooks} WHERE id = :id', ['id' => $id]);

        if ($webhook === null) {
            flash('error', 'That subscription is already gone.');

            return $this->redirect('/settings/webhooks');
        }

        // The deliveries cascade with it. That is the right call: a log of
        // calls to a subscription nobody can see any more is noise, and the
        // audit row below is the durable record that it existed.
        DB::delete('webhooks', 'id = :id', ['id' => $id]);

        Audit::event('webhook_deleted', [
            'webhook_id' => $id,
            'name'       => (string) $webhook['name'],
            'target_url' => (string) $webhook['target_url'],
        ], 'webhooks', $id);

        flash('success', 'Deleted.');

        return $this->redirect('/settings/webhooks');
    }

    /** Send a sample delivery now and report exactly what came back. */
    public function test(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $id     = $request->routeInt('id');
        $result = WebhookDispatcher::test($id);

        Audit::event('webhook_tested', [
            'webhook_id' => $id,
            'ok'         => (bool) ($result['ok'] ?? false),
            'status'     => $result['status'] ?? null,
        ], 'webhooks', $id);

        Session::put('webhook_test', $result + ['webhook_id' => $id]);

        return $this->redirect('/settings/webhooks');
    }

    /** Queue the same delivery again. */
    public function replay(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $id  = $request->routeInt('id');
        $new = WebhookDispatcher::replay($id);

        if ($new === null) {
            flash('error', 'That delivery is no longer here.');

            return $this->redirect('/settings/webhooks');
        }

        Audit::event('webhook_replayed', [
            'original' => $id,
            'delivery' => $new,
        ], 'webhook_deliveries', $new);

        flash(
            'success',
            'Queued again. It carries the SAME delivery id as the original, so a receiver '
                . 'that deduplicates will recognise it as a repeat.'
        );

        return $this->redirect('/settings/webhooks');
    }
}
