<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * The audit trail.
 *
 * Castle CRM holds guest contact details, consent decisions, and money, so
 * "who changed this, and what did it look like before?" has to be answerable.
 * Every create/update/delete on a business table writes a row here with the
 * actor, their IP, and a field-level diff.
 *
 * Values that must never be stored are redacted before the diff is written —
 * a password hash or an API token in an audit row is a liability, not a record.
 */
final class Audit
{
    private static bool $enabled = true;

    private static ?Request $request = null;

    /** Fields never written to the diff. */
    private const REDACT = [
        'password', 'password_hash', 'mfa_secret', 'token', 'token_hash',
        'secret', 'api_key', 'signature_data', 'payload',
    ];

    /**
     * Fields whose change is recorded but whose value is masked — we need to
     * know an email was changed without the log becoming a second copy of the
     * contact database.
     */
    private const MASK = [];

    public static function init(Request $request): void
    {
        self::$request = $request;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** Suspend auditing — used by bulk importers, which log once in summary. */
    public static function suspend(): void
    {
        self::$enabled = false;
    }

    public static function resume(): void
    {
        self::$enabled = true;
    }

    /**
     * Record a change.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function record(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null
    ): void {
        if (!self::$enabled) {
            return;
        }

        $changes = self::diff($before, $after);

        try {
            DB::insert('audit_log', [
                'user_id'     => Auth::id(),
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'changes'     => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
                'ip'          => self::$request?->ip(),
                'user_agent'  => self::$request?->userAgent(),
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // An audit failure is serious but must not roll back the business
            // change the user just made — log it loudly instead.
            Log::error('Audit write failed', [
                'action' => $action,
                'entity' => $entityType . '#' . $entityId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record something that is not a row change — a login, an export, a send,
     * a Safe Mode toggle. These matter as much as data edits.
     *
     * @param array<string,mixed> $context
     */
    public static function event(string $action, array $context = [], ?string $entityType = null, ?int $entityId = null): void
    {
        if (!self::$enabled) {
            return;
        }

        try {
            DB::insert('audit_log', [
                'user_id'     => Auth::id(),
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'changes'     => $context === [] ? null : json_encode(self::redact($context), JSON_UNESCAPED_UNICODE),
                'ip'          => self::$request?->ip(),
                'user_agent'  => self::$request?->userAgent(),
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit event write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Field-level diff: only what actually changed, old and new.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private static function diff(?array $before, ?array $after): array
    {
        if ($after === null) {
            return $before === null ? [] : ['_deleted' => ['from' => true, 'to' => true]];
        }

        $changes = [];

        foreach ($after as $column => $newValue) {
            if (in_array($column, self::REDACT, true)) {
                // Note that it changed, never what to.
                $changes[$column] = ['from' => '[redacted]', 'to' => '[redacted]'];
                continue;
            }

            // updated_at changes on every write and tells us nothing.
            if ($column === 'updated_at') {
                continue;
            }

            $oldValue = $before[$column] ?? null;

            // Loose comparison on purpose: MySQL hands back "1" where PHP set
            // 1, and recording that as a change would make every diff noise.
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            $changes[$column] = [
                'from' => self::truncate($oldValue),
                'to'   => self::truncate(in_array($column, self::MASK, true) ? '[changed]' : $newValue),
            ];
        }

        return $changes;
    }

    private static function truncate(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 500) {
            return substr($value, 0, 500) . '…';
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }
}
