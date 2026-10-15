<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Daily-rotating file logger.
 *
 * Writes to storage/logs/castle-YYYY-MM-DD.log. Deliberately file-based rather
 * than database-based: when the database is the thing that broke, the log still
 * needs to work.
 */
final class Log
{
    public const DEBUG     = 100;
    public const INFO      = 200;
    public const NOTICE    = 250;
    public const WARNING   = 300;
    public const ERROR     = 400;
    public const CRITICAL  = 500;

    private const LEVELS = [
        'debug'    => self::DEBUG,
        'info'     => self::INFO,
        'notice'   => self::NOTICE,
        'warning'  => self::WARNING,
        'error'    => self::ERROR,
        'critical' => self::CRITICAL,
    ];

    /**
     * Keys whose values are redacted before a context array is written, so a
     * stray credential in a payload never lands in a log file.
     */
    private const REDACT = [
        'password', 'password_confirmation', 'pass', 'secret', 'token',
        'api_key', 'apikey', 'auth_token', 'access_token', 'refresh_token',
        'card', 'card_number', 'cvv', 'signature_data', 'app_key',
        'password_hash', 'mfa_secret',
    ];

    private static ?string $path = null;

    private static int $minLevel = self::INFO;

    private static int $retentionDays = 30;

    public static function init(?string $storagePath = null, string $level = 'info'): void
    {
        $storagePath = $storagePath ?? Config::get('storage_path', CC_ROOT . '/storage');
        self::$path = rtrim((string) $storagePath, '/') . '/logs';
        self::$minLevel = self::LEVELS[strtolower($level)] ?? self::INFO;
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write(self::DEBUG, 'DEBUG', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write(self::INFO, 'INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function notice(string $message, array $context = []): void
    {
        self::write(self::NOTICE, 'NOTICE', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write(self::WARNING, 'WARNING', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write(self::ERROR, 'ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function critical(string $message, array $context = []): void
    {
        self::write(self::CRITICAL, 'CRITICAL', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private static function write(int $level, string $label, string $message, array $context): void
    {
        if ($level < self::$minLevel) {
            return;
        }

        if (self::$path === null) {
            self::init();
        }

        $dir = (string) self::$path;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // Nowhere to log to. Fall back to the PHP error log rather than
            // throwing — a logging failure must not break a request.
            error_log('[castle-crm] ' . $label . ': ' . $message);

            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $label,
            self::oneLine($message),
            $context === [] ? '' : ' ' . self::encodeContext($context)
        );

        $file = $dir . '/castle-' . gmdate('Y-m-d') . '.log';

        // LOCK_EX keeps concurrent PHP-FPM workers from interleaving lines.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        self::pruneOccasionally($dir);
    }

    /** @param array<string,mixed> $context */
    private static function encodeContext(array $context): string
    {
        $json = json_encode(
            self::redact($context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? '{"_log_error":"context not encodable"}' : $json;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT, true)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
                continue;
            }

            // Keep single log lines bounded; a dumped email body can be huge.
            if (is_string($value) && strlen($value) > 2000) {
                $data[$key] = substr($value, 0, 2000) . '…[truncated]';
            }
        }

        return $data;
    }

    private static function oneLine(string $message): string
    {
        return str_replace(["\r", "\n"], ' ', $message);
    }

    /**
     * Delete logs older than the retention window. Runs on roughly 1 in 200
     * writes so it costs nothing on a normal request.
     */
    private static function pruneOccasionally(string $dir): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }

        $cutoff = time() - (self::$retentionDays * 86400);

        foreach (glob($dir . '/castle-*.log') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
