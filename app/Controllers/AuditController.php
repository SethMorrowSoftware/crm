<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

/**
 * The audit log viewer.
 *
 * Read-only by design — an audit trail that can be edited is not an audit
 * trail. There is no delete action anywhere in this class; old rows are pruned
 * by the retention job in cron, never by a person.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        ['page' => $page, 'perPage' => $perPage, 'offset' => $offset] = $this->pagination($request, 50);

        [$where, $params] = $this->filters($request);

        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {audit_log} a WHERE ' . $where,
            $params
        );

        $rows = DB::all(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
               FROM {audit_log} a
               LEFT JOIN {users} u ON u.id = a.user_id
              WHERE ' . $where . '
              ORDER BY a.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return $this->view('settings/audit', [
            'pageTitle'  => 'Audit log',
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'lastPage'   => max(1, (int) ceil($total / $perPage)),
            'actions'    => $this->distinctActions(),
            'users'      => DB::all(
                'SELECT id, name FROM {users} WHERE deleted_at IS NULL ORDER BY name'
            ),
            'filters'    => [
                'action'      => $request->str('action'),
                'user_id'     => $request->str('user_id'),
                'entity_type' => $request->str('entity_type'),
                'from'        => $request->str('from'),
                'to'          => $request->str('to'),
            ],
        ]);
    }

    /**
     * Export the filtered log.
     *
     * Exporting is itself audited — knowing who took a copy of the activity
     * log matters as much as the log.
     */
    public function export(Request $request): Response
    {
        if ($denied = $this->authorize('reports_export')) {
            return $denied;
        }

        [$where, $params] = $this->filters($request);

        // Bounded: an unbounded export of a years-old log would exhaust memory
        // on a shared host and time out.
        $limit = 10000;

        $rows = DB::all(
            'SELECT a.created_at, u.name AS user_name, a.action, a.entity_type,
                    a.entity_id, a.ip, a.changes
               FROM {audit_log} a
               LEFT JOIN {users} u ON u.id = a.user_id
              WHERE ' . $where . '
              ORDER BY a.id DESC
              LIMIT ' . $limit,
            $params
        );

        Audit::event('audit.exported', ['rows' => count($rows), 'filters' => $request->all()]);

        $output = array_map(static fn (array $r): array => [
            local_time((string) $r['created_at'], 'Y-m-d H:i:s'),
            $r['user_name'] ?? 'system',
            $r['action'],
            $r['entity_type'] ?? '',
            $r['entity_id'] ?? '',
            $r['ip'] ?? '',
            $r['changes'] ?? '',
        ], $rows);

        return Response::csv(
            'castle-crm-audit-' . gmdate('Y-m-d') . '.csv',
            ['When', 'Who', 'Action', 'Entity', 'ID', 'IP', 'Changes'],
            $output
        );
    }

    /**
     * Build the WHERE clause from the filter inputs.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(Request $request): array
    {
        $clauses = ['1 = 1'];
        $params  = [];

        $action = $request->str('action');

        if ($action !== '') {
            $clauses[] = 'a.action = :action';
            $params['action'] = $action;
        }

        $userId = $request->str('user_id');

        if ($userId !== '' && is_numeric($userId)) {
            $clauses[] = 'a.user_id = :user_id';
            $params['user_id'] = (int) $userId;
        }

        $entityType = $request->str('entity_type');

        if ($entityType !== '') {
            $clauses[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }

        // Dates arrive as venue-local and are compared against UTC storage.
        $from = $request->str('from');

        if ($from !== '' && strtotime($from) !== false) {
            $clauses[] = 'a.created_at >= :from';
            $params['from'] = $this->localDateToUtc($from, '00:00:00');
        }

        $to = $request->str('to');

        if ($to !== '' && strtotime($to) !== false) {
            $clauses[] = 'a.created_at <= :to';
            $params['to'] = $this->localDateToUtc($to, '23:59:59');
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function localDateToUtc(string $date, string $time): string
    {
        try {
            return (new \DateTimeImmutable($date . ' ' . $time, venue_tz()))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return gmdate('Y-m-d H:i:s');
        }
    }

    /** @return list<string> */
    private function distinctActions(): array
    {
        return array_map('strval', DB::column(
            'SELECT DISTINCT action FROM {audit_log} ORDER BY action LIMIT 200'
        ));
    }
}
