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

    /**
     * Headers an application-wide default must not add back.
     *
     * See `removeHeader()`. Held separately from `$headers` because the thing
     * being recorded is an ABSENCE, and an absence cannot be expressed as a
     * value: `X-Frame-Options` has no "allow anybody" setting, so the only
     * way to permit framing is to not send the header at all.
     *
     * @var array<string,true>
     */
    private array $suppressed = [];

    /**
     * A body produced at send time rather than held in memory.
     *
     * A database backup or a full contacts export can be larger than the whole
     * PHP memory limit on shared hosting, so those responses hand over a
     * callable that writes to the output buffer instead of a string.
     *
     * @var (callable():void)|null
     */
    private $streamer = null;

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
            fputcsv($handle, $headerRow, escape: '');
        }

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (mixed $v): string => self::csvCell($v), $row), escape: '');
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
     * Send a file from disk as a download, read in chunks.
     *
     * The file is never loaded into a string: a 600 MB backup downloads from a
     * process with a 128 MB memory limit, which is the whole point.
     */
    public static function file(string $path, string $downloadName, string $contentType = 'application/octet-stream'): self
    {
        if (!is_file($path) || !is_readable($path)) {
            return self::text('That file is no longer available.', 404);
        }

        $response = (new self())
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'attachment; filename="' . self::safeFilename($downloadName) . '"')
            ->header('Content-Length', (string) filesize($path))
            // A backup must never sit in a proxy cache or a browser's disk
            // cache on a shared front-desk machine.
            ->noCache()
            ->header('X-Content-Type-Options', 'nosniff');

        $response->streamer = static function () use ($path): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            while (!feof($handle)) {
                echo (string) fread($handle, 262144);
                flush();
            }

            fclose($handle);
        };

        return $response;
    }

    /**
     * A download whose body is generated as it is sent.
     *
     * @param callable():void $writer Writes directly to php://output.
     */
    public static function stream(callable $writer, string $downloadName, string $contentType = 'text/plain; charset=UTF-8'): self
    {
        $response = (new self())
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'attachment; filename="' . self::safeFilename($downloadName) . '"')
            ->noCache()
            ->header('X-Content-Type-Options', 'nosniff');

        $response->streamer = $writer;

        return $response;
    }

    /**
     * Neutralise spreadsheet formula injection: a cell beginning with =, +, -
     * or @ is executed by Excel and Sheets when the file is opened, which turns
     * a guest's "name" into code running on a staff machine.
     *
     * Public because there is more than one way out of this application: this
     * builds a CSV in memory, and App\Core\Backup streams one row by row for
     * exports too large to assemble. Both must neutralise cells identically.
     * While this was private, the streamed export — the one the Backups page
     * offers, and the one that carries the whole contacts table — wrote raw
     * values, under a UI hint promising it did exactly this.
     */
    public static function csvCell(mixed $value): string
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

    /**
     * Add several headers at once.
     *
     * `$overwrite` false leaves a header the response has already set alone,
     * which is how a single route can opt out of an application-wide default.
     * `bootstrap.php` applies the security headers that way: almost every
     * page wants `frame-ancestors 'self'`, and the one page that exists to be
     * embedded on the venue's own website cannot have it — so that route sets
     * its own policy and this leaves it standing. Overwriting is still the
     * default, because a caller merging in headers it just computed normally
     * means them.
     *
     * @param array<string,string> $headers
     */
    public function headers(array $headers, bool $overwrite = true): self
    {
        foreach ($headers as $name => $value) {
            if (!$overwrite && (isset($this->headers[$name]) || isset($this->suppressed[$name]))) {
                continue;
            }

            $this->headers[$name] = $value;
        }

        return $this;
    }

    /**
     * Drop a header, and refuse to have it added back by a later default.
     *
     * The only caller is the public review wall, which must be frameable by
     * the venue's own website: `X-Frame-Options: SAMEORIGIN` forbids that and
     * the header has no value that permits it, so it has to be absent. The
     * page still constrains framing — through `Content-Security-Policy:
     * frame-ancestors`, which browsers honour in preference to
     * X-Frame-Options when both are present, and which this one page sets
     * itself.
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        $this->suppressed[$name] = true;

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
            if ($this->streamer !== null) {
                ($this->streamer)();
            } else {
                echo $this->body;
            }

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
            if ($this->streamer !== null) {
                ($this->streamer)();
            } else {
                echo $this->body;
            }
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
