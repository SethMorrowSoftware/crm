<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;

/**
 * What entering a stage does to a deal.
 *
 * Extracted from `DealController` when the automation engine gained a
 * "move the deal" step. A stage move sets six columns, writes a history row
 * whose duration is the whole basis of the pipeline's velocity report, and
 * fires the won or lost event the venue's own automations hang off. Two
 * copies of that would drift, and the copy that drifted would be the one
 * nobody is watching: a deal moved by an automation at four in the morning
 * with no `sla_due_at` set, or won without `deal.won` ever firing.
 *
 * Static and I/O-bearing, like the other services here. The controller keeps
 * its own validation, permission checks and audit trail; this is only the part
 * that must be identical whoever does the moving.
 */
final class DealStage
{
    /**
     * The deal columns a stage decides.
     *
     * @param array<string,mixed> $deal  The row as it stands (empty on create).
     * @param array<string,mixed> $stage The stage being entered.
     * @return array<string,mixed>
     */
    public static function columns(array $deal, array $stage, string $now): array
    {
        $isWon  = (int) $stage['is_won'] === 1;
        $isLost = (int) $stage['is_lost'] === 1;

        $sla = ($stage['sla_hours'] ?? null) === null || $stage['sla_hours'] === ''
            ? null
            : gmdate('Y-m-d H:i:s', self::stamp($now) + ((int) $stage['sla_hours'] * 3600));

        return [
            'stage_id'         => (int) $stage['id'],
            'stage_entered_at' => $now,
            'sla_due_at'       => $sla,
            'status'           => $isWon ? 'won' : ($isLost ? 'lost' : 'open'),
            // Keep the original timestamp when a deal re-enters a won or lost
            // stage, so "when did we win this?" is not rewritten by a tidy-up.
            'won_at'           => $isWon ? ($deal['won_at'] ?? $now) : null,
            'lost_at'          => $isLost ? ($deal['lost_at'] ?? $now) : null,
            'last_activity_at' => $now,
        ];
    }

    /**
     * Write the history row for a move.
     *
     * @param array<string,mixed> $deal The row BEFORE the move.
     */
    public static function recordMove(int $dealId, int $fromStageId, int $toStageId, array $deal, string $now): void
    {
        $entered = (string) ($deal['stage_entered_at'] ?? '');

        if ($entered === '') {
            $entered = (string) ($deal['created_at'] ?? $now);
        }

        $duration = $entered === '' ? null : max(0, self::stamp($now) - self::stamp($entered));

        DB::insert('deal_stage_history', [
            'deal_id'          => $dealId,
            'from_stage_id'    => $fromStageId > 0 ? $fromStageId : null,
            'to_stage_id'      => $toStageId,
            // Null when an automation did it, which is the honest answer:
            // the history page says "moved automatically" rather than naming
            // whoever happened to be logged in when cron ran.
            'user_id'          => Auth::id(),
            'duration_seconds' => $duration,
            'created_at'       => $now,
        ]);
    }

    /** @param array<string,mixed> $stage */
    public static function fireOutcome(int $dealId, array $stage): void
    {
        if ((int) $stage['is_won'] === 1) {
            Event::fire('deal.won', ['id' => $dealId]);
        }

        if ((int) $stage['is_lost'] === 1) {
            Event::fire('deal.lost', ['id' => $dealId]);
        }
    }

    /**
     * Find a stage by id, or by name inside a pipeline.
     *
     * An automation step names its stage in words ("Deposit chased"), because
     * a numbered id in a playbook that ships with the product would point at
     * whatever stage happened to be created fourth on this install. The name
     * is matched inside the deal's OWN pipeline first — two pipelines may
     * both have a "Won" — and only then anywhere.
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $wanted, int $pipelineId = 0): ?array
    {
        $wanted = trim($wanted);

        if ($wanted === '') {
            return null;
        }

        if (ctype_digit($wanted)) {
            $row = DB::first('SELECT * FROM {pipeline_stages} WHERE id = :id', ['id' => (int) $wanted]);

            if ($row !== null) {
                return $row;
            }
        }

        if ($pipelineId > 0) {
            $row = DB::first(
                'SELECT * FROM {pipeline_stages} WHERE pipeline_id = :p AND name = :n ORDER BY sort_order LIMIT 1',
                ['p' => $pipelineId, 'n' => $wanted]
            );

            if ($row !== null) {
                return $row;
            }
        }

        return DB::first(
            'SELECT * FROM {pipeline_stages} WHERE name = :n ORDER BY pipeline_id, sort_order LIMIT 1',
            ['n' => $wanted]
        );
    }

    /**
     * Move a deal, history row and events included.
     *
     * Returns what happened, in words the step log can keep. A deal already
     * in the stage is NOT moved: re-entering a stage would reset
     * `stage_entered_at`, which is what the velocity report measures, and
     * would fire `deal.won` a second time.
     *
     * @return array{moved:bool,from:?string,to:?string,reason:?string}
     */
    public static function move(int $dealId, string $wanted): array
    {
        $deal = DB::first('SELECT * FROM {deals} WHERE id = :id AND deleted_at IS NULL', ['id' => $dealId]);

        if ($deal === null) {
            return ['moved' => false, 'from' => null, 'to' => null, 'reason' => 'that deal is gone'];
        }

        $stage = self::resolve($wanted, (int) ($deal['pipeline_id'] ?? 0));

        if ($stage === null) {
            return ['moved' => false, 'from' => null, 'to' => null, 'reason' => 'there is no stage called "' . $wanted . '"'];
        }

        $fromId = (int) ($deal['stage_id'] ?? 0);
        $toId   = (int) $stage['id'];

        if ($fromId === $toId) {
            return [
                'moved'  => false,
                'from'   => (string) $stage['name'],
                'to'     => (string) $stage['name'],
                'reason' => 'the deal is already there',
            ];
        }

        /*
         * A stage in ANOTHER pipeline is refused.
         *
         * Moving a party booking's deal into the fundraising pipeline would
         * take it off the board it belongs to and out of every report keyed
         * to that pipeline, and nothing in the UI would explain where it
         * went. A mistyped stage name in a playbook is the likely cause.
         */
        if ((int) ($deal['pipeline_id'] ?? 0) > 0
            && (int) $stage['pipeline_id'] !== (int) $deal['pipeline_id']) {
            return [
                'moved'  => false,
                'from'   => null,
                'to'     => (string) $stage['name'],
                'reason' => 'that stage belongs to another pipeline',
            ];
        }

        $now  = now_utc();
        $from = $fromId > 0
            ? (string) (DB::value('SELECT name FROM {pipeline_stages} WHERE id = :id', ['id' => $fromId]) ?? '')
            : '';

        DB::transaction(static function () use ($dealId, $deal, $stage, $now, $fromId, $toId): void {
            DB::update(
                'deals',
                array_merge(self::columns($deal, $stage, $now), ['updated_at' => $now]),
                'id = :id',
                ['id' => $dealId]
            );

            self::recordMove($dealId, $fromId, $toId, $deal, $now);
        });

        Event::fire('deal.stage_changed', [
            'id'            => $dealId,
            'from_stage_id' => $fromId > 0 ? $fromId : null,
            'to_stage_id'   => $toId,
            'automated'     => Auth::id() === null,
        ]);

        self::fireOutcome($dealId, $stage);

        return [
            'moved'  => true,
            'from'   => $from === '' ? null : $from,
            'to'     => (string) $stage['name'],
            'reason' => null,
        ];
    }

    /** A UTC database timestamp as a unix time. */
    public static function stamp(string $utc): int
    {
        $time = strtotime($utc . ' UTC');

        return $time === false ? time() : $time;
    }
}
