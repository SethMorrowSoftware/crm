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
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'that payload is not encodable', 'ms' => 0];
        }

        return self::postRaw($url, $body, $headers);
    }

    /**
     * POST a body THIS CALLER ALREADY ENCODED.
     *
     * Webhooks need it: the signature covers the exact bytes on the wire, and
     * re-encoding between signing and sending would produce a different
     * string — different key order, different unicode escaping — so every
     * signature a receiver checked would fail.
     *
     * @param array<string,string> $headers
     * @param bool $pinResolved Resolve the host and connect only to an address
     *        that passed the check. For a URL an operator typed, which a
     *        hostname could otherwise point anywhere.
     * @return array{ok:bool,status:int,body:string,error:?string,ms:int}
     */
    public static function postRaw(
        string $url,
        string $body,
        array $headers = [],
        bool $pinResolved = false
    ): array {
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

        $pin = null;

        if ($pinResolved) {
            $resolved = self::resolveSafely($url);

            if ($resolved['reason'] !== null) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $resolved['reason'], 'ms' => 0];
            }

            $pin = $resolved['pin'];
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

        /*
         * CONNECT TO THE ADDRESS WE CHECKED, not to whatever DNS says a
         * moment later.
         *
         * Without this, checking the resolved address is theatre: the guard
         * resolves `hook.example.com` to a public IP, approves it, and then
         * cURL resolves it again — and a name whose TTL is one second can
         * answer 169.254.169.254 the second time. Pinning closes that window
         * because the address cURL dials is the one that passed.
         */
        if ($pin !== null) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$pin]);
        }

        $started  = microtime(true);
        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($handle);

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

    /**
     * Resolve the host and refuse it if ANY address it answers with is
     * private, reserved or loopback.
     *
     * `refuse()` catches a URL written as a literal address. This catches the
     * other half — a HOSTNAME that points inwards, which is the shape that
     * matters for a URL an operator pasted from somewhere, and the one
     * `refuse()`'s own docblock says it does not handle.
     *
     * ANY address, not the first: a name answering with one public address
     * and one link-local is as dangerous as one answering only link-local,
     * because which one cURL picks is not ours to decide.
     *
     * @return array{pin:?string,reason:?string}
     */
    public static function resolveSafely(string $url): array
    {
        $parts = parse_url($url);
        $host  = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            return ['pin' => null, 'reason' => 'that is not a URL'];
        }

        // Already a literal address: `refuse()` has checked it, and there is
        // nothing to resolve or pin.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['pin' => null, 'reason' => null];
        }

        $addresses = self::addressesFor($host);

        if ($addresses === []) {
            /*
             * Not refused, and this is the one branch where that is safe.
             *
             * Nothing on this machine can resolve the name — `getaddrinfo`
             * included, which is the resolver cURL itself uses — so cURL
             * cannot reach anywhere either. It will fail honestly with "could
             * not resolve host", which is a clearer message to a venue than
             * one invented here.
             *
             * THE DANGEROUS VERSION OF THIS BRANCH asked only
             * `dns_get_record()`. That is a DNS query PHP builds itself, and
             * on a shared host it is frequently blocked or filtered while
             * `getaddrinfo` works perfectly — which is precisely the case the
             * old comment named as the reason to be lenient. So on exactly
             * those hosts the function returned "nothing to check, no pin",
             * cURL then resolved the name through the working resolver, and a
             * hostname answering 127.0.0.1 was dialled with NO address check
             * at all. Asking the same resolver cURL will ask removes the
             * disagreement the bypass depended on.
             */
            return ['pin' => null, 'reason' => null];
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return [
                    'pin'    => null,
                    'reason' => 'that hostname resolves to an address inside the network',
                ];
            }
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port   = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        return ['pin' => $host . ':' . $port . ':' . $addresses[0], 'reason' => null];
    }

    /**
     * Every address a hostname answers with, asked two ways.
     *
     * `gethostbynamel()` goes through the system resolver — `getaddrinfo`,
     * the same path cURL takes — so it sees what cURL will see, including
     * `/etc/hosts`, nsswitch and anything a host's own resolver does. That is
     * the authoritative answer for "where will this connection actually go".
     *
     * `dns_get_record()` is asked as well, and only for its IPv6 records:
     * `gethostbynamel` is IPv4-only, so without it a name with a private AAAA
     * and a public A would pass. It is additive, never a substitute — see the
     * empty-result branch in `resolveSafely()` for what happened when it was
     * the only question asked.
     *
     * @return list<string>
     */
    private static function addressesFor(string $host): array
    {
        $addresses = [];

        $system = @gethostbynamel($host);

        foreach (is_array($system) ? $system : [] as $address) {
            $addresses[] = (string) $address;
        }

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

                if ($address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
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
