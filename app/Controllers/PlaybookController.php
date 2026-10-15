<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * The 8 revenue playbooks.
 *
 * A playbook is a bundle — pipelines, forms, templates, automations, reports —
 * that the venue turns on as one unit. Activating a playbook never starts
 * messaging on its own: its automations remain individually inactive, and Safe
 * Mode still applies. Activation makes a playbook's assets *available*, which
 * is a different and much safer thing than switching on 20 sequences at once.
 */
final class PlaybookController extends Controller
{
    public function index(Request $request): Response
    {
        $playbooks = DB::all(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM {automations} a
                      WHERE a.playbook_id = p.id) AS automation_count,
                    (SELECT COUNT(*) FROM {automations} a
                      WHERE a.playbook_id = p.id AND a.is_active = 1) AS active_automation_count
               FROM {playbooks} p
              ORDER BY p.sort_order, p.number'
        );

        return $this->view('playbooks/index', [
            'pageTitle'  => 'Revenue playbooks',
            'playbooks'  => $playbooks,
            'activeCount' => count(array_filter($playbooks, static fn (array $p): bool => (bool) $p['is_active'])),
            'safeMode'   => Settings::safeMode(),
        ]);
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->route('slug', '');

        $playbook = DB::first('SELECT * FROM {playbooks} WHERE slug = :slug', ['slug' => $slug]);

        if ($playbook === null) {
            return $this->notFound('That playbook does not exist.');
        }

        $automations = DB::all(
            'SELECT id, name, description, trigger_type, is_active, run_count, goal_count
               FROM {automations}
              WHERE playbook_id = :pid
              ORDER BY name',
            ['pid' => (int) $playbook['id']]
        );

        return $this->view('playbooks/show', [
            'pageTitle'   => (string) $playbook['name'],
            'playbook'    => $playbook,
            'automations' => $automations,
            'assets'      => $this->assetSummary((int) $playbook['id']),
        ]);
    }

    public function activate(Request $request): Response
    {
        return $this->setActive($request, true);
    }

    public function deactivate(Request $request): Response
    {
        return $this->setActive($request, false);
    }

    private function setActive(Request $request, bool $active): Response
    {
        if ($denied = $this->authorize('playbooks_activate')) {
            return $denied;
        }

        $id = $request->routeInt('id');

        $playbook = DB::first('SELECT * FROM {playbooks} WHERE id = :id', ['id' => $id]);

        if ($playbook === null) {
            return $this->notFound('That playbook does not exist.');
        }

        DB::transaction(static function () use ($id, $active, $playbook): void {
            DB::update('playbooks', [
                'is_active'    => $active ? 1 : 0,
                'activated_at' => $active ? now_utc() : null,
                'updated_at'   => now_utc(),
            ], 'id = :id', ['id' => $id]);

            // Deactivating a playbook must stop its automations, or a sequence
            // would keep running from a playbook the venue thinks is off.
            if (!$active) {
                DB::update('automations', [
                    'is_active'  => 0,
                    'updated_at' => now_utc(),
                ], 'playbook_id = :pid', ['pid' => $id]);
            }

            Audit::record(
                $active ? 'playbook.activated' : 'playbook.deactivated',
                'playbooks',
                $id,
                $playbook,
                ['is_active' => $active ? 1 : 0]
            );
        });

        $message = $active
            ? sprintf(
                '%s is active. Its automations are still switched off individually — review them before turning any on.',
                (string) $playbook['name']
            )
            : sprintf('%s is off, and its automations have been stopped.', (string) $playbook['name']);

        return $this->success($request, $message, '/playbooks');
    }

    /**
     * What a playbook brings with it.
     *
     * @return array<string,int>
     */
    private function assetSummary(int $playbookId): array
    {
        $rows = DB::all(
            'SELECT asset_type, COUNT(*) AS n
               FROM {playbook_assets}
              WHERE playbook_id = :pid
              GROUP BY asset_type',
            ['pid' => $playbookId]
        );

        $summary = [];

        foreach ($rows as $row) {
            $summary[(string) $row['asset_type']] = (int) $row['n'];
        }

        return $summary;
    }
}
