<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One outbound HTTP POST, bounded.
 *
 * The automation engine's webhook step is the first caller; the webhooks
 * module will be the second. Both are "call a URL an operator typed, from a
 * cron process, on a shared host", which carries three risks worth naming:
 *
 * 1. **A SLOW URL BLOCKS THE QUEUE.** A cron tick that waits 90 seconds on a
 *    dead endpoint is a tick that did not send the party reminders behind it.
 *    The timeouts here are deliberately short and are NOT configurable.
 * 2. **THE RESPONSE IS NOT TO BE TRUSTED OR KEPT.** Only the status, the
 *    first few hundred bytes and the time taken come back. A step log is read
 *    by a venue manager in a browser; a megabyte of somebody's HTML in it is
 *    a stored-XSS surface and a bloated table.
 * 3. **A URL CAN POINT INWARDS.** On shared hosting, `http://127.0.0.1` and
 *    `http://169.254.169.254` are reachable and interesting. The guard below
 *    refuses the obvious cases by address; it cannot stop a hostname that
 *    resolves inwards, and does not pretend to — automations are authored
 *    behind `automations_manage`, so this is defence in depth rather than a
 *    boundary.
 */
final class Http
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT         = 10;

    /** How much of a reply is worth keeping. */
    public const MAX_BODY = 500;

    /**
     * POST a JSON body.
     *
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     * @return array{ok:bool,status:int,body:string,error:?string,ms:int}
     */
    public static function postJson(string $url, array $payload, array $headers = []): array
    {
        $refusal = self::refuse($url);

        if ($refusal !== null) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $refusal, 'ms' => 0];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false, 'status' => 0, 'body' => '',
                'error' => 'this host has no curl, so webhooks cannot be sent from it',
                'ms' => 0,
            ];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'that payload is not encodable', 'ms' => 0];
        }

        $lines = ['Content-Type: application/json', 'Accept: application/json'];

        foreach ($headers as $name => $value) {
            // One header per line, and no newline injection from a config
            // value an operator pasted.
            $lines[] = str_replace(["\r", "\n"], '', (string) $name . ': ' . (string) $value);
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl could not start', 'ms' => 0];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // A redirect is not followed: it is the simplest way to turn an
            // allowed URL into a request somewhere else.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $started  = microtime(true);
        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($handle);
        curl_close($handle);

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($response === false) {
            return [
                'ok' => false, 'status' => $status, 'body' => '',
                'error' => $error === '' ? 'the request failed' : $error,
                'ms' => $ms,
            ];
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => mb_substr((string) $response, 0, self::MAX_BODY),
            'error'  => null,
            'ms'     => $ms,
        ];
    }

    /** Why this URL will not be called, or null to go ahead. */
    public static function refuse(string $url): ?string
    {
        $url   = trim($url);
        $parts = $url === '' ? false : parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            return 'that is not a URL';
        }

        // THE SCHEME IS CHECKED BEFORE THE HOST, so the reason names what is
        // actually wrong. `file:///etc/passwd` parses with no host at all, and
        // "that is not a URL" would send somebody looking for a typo in a URL
        // that is perfectly well formed and simply not callable.
        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'only http and https URLs can be called';
        }

        if (!isset($parts['host'])) {
            return 'that is not a URL';
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return 'that URL points at this server';
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip === false) {
            // A hostname. Not resolved here: a lookup on every send costs a
            // round trip, and a name that resolves inwards would still have
            // to be caught at connect time to be caught at all.
            return null;
        }

        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return 'that URL points inside the network';
        }

        return null;
    }
}
