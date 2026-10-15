<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An HTTP response, built up then sent once.
 *
 * Holding the response as an object rather than echoing as we go means
 * middleware can add headers after a controller has produced a body, and a
 * redirect can replace a body that was already rendered.
 */
final class Response
{
    private int $status = 200;

    /** @var array<string,string> */
    private array $headers = [];

    private string $body = '';

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    private bool $sent = false;

    public function __construct(string $body = '', int $status = 200)
    {
        $this->body   = $body;
        $this->status = $status;
    }

    // -----------------------------------------------------------------
    // Factories
    // -----------------------------------------------------------------

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return (new self($encoded === false ? '{"error":"encoding failed"}' : $encoded, $status))
            ->header('Content-Type', 'application/json; charset=UTF-8');
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return self::json(array_merge(['error' => $message], $extra), $status);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return (new self('', $status))->header('Location', $url);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * A CSV download, streamed through a buffer so a large export does not
     * need to be assembled in memory as one string.
     *
     * @param list<string>        $headerRow
     * @param iterable<list<mixed>> $rows
     */
    public static function csv(string $filename, array $headerRow, iterable $rows): self
    {
        $handle = fopen('php://temp/maxmemory:5242880', 'r+');

        if ($handle === false) {
            return self::text('Could not open a stream for the export.', 500);
        }

        // A BOM so Excel on Windows reads UTF-8 names correctly.
        fwrite($handle, "\xEF\xBB\xBF");

        if ($headerRow !== []) {
            fputcsv($handle, $headerRow);
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (mixed $v): string => self::csvCell($v), $row));
        }

        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return (new self($body))
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . self::safeFilename($filename) . '"')
            ->header('Content-Length', (string) strlen($body));
    }

    /**
     * Neutralise spreadsheet formula injection: a cell beginning with =, +, -
     * or @ is executed by Excel and Sheets when the file is opened, which turns
     * a guest's "name" into code running on a staff machine.
     */
    private static function csvCell(mixed $value): string
    {
        $string = $value === null ? '' : (string) $value;

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $string;
        }

        return $string;
    }

    // -----------------------------------------------------------------
    // Mutators
    // -----------------------------------------------------------------

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string,string> $headers */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @param array<string,mixed> $options */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function noCache(): self
    {
        return $this
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;

        if (headers_sent($file, $line)) {
            // Something printed before the response was ready — usually a stray
            // echo or a BOM in an edited file. Log where, and still emit a body.
            Log::error('Headers already sent; response headers dropped.', [
                'file' => $file,
                'line' => $line,
            ]);
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        foreach ($this->cookies as $cookie) {
            $options = $cookie['options'];
            setcookie($cookie['name'], $cookie['value'], [
                'expires'  => $options['expires']  ?? 0,
                'path'     => $options['path']     ?? '/',
                'domain'   => $options['domain']   ?? '',
                'secure'   => $options['secure']   ?? false,
                'httponly' => $options['httponly'] ?? true,
                'samesite' => $options['samesite'] ?? 'Lax',
            ]);
        }

        // 204 and 304 must not carry a body.
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }

        if (function_exists('fastcgi_finish_request')) {
            // Return the response now; any deferred work keeps running.
            fastcgi_finish_request();
        }
    }

    private static function safeFilename(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $name === '' ? 'export.csv' : $name;
    }
}
