<?php

declare(strict_types=1);

/**
 * The HTTP client the end-to-end suites share.
 *
 * Lives outside the *Test.php naming convention on purpose: the runner globs
 * for that suffix, so a helper named this way is loaded by the files that
 * need it rather than executed as a suite of its own. It used to sit inside
 * SmokeTest.php, which meant the second HTTP suite to want a browser could
 * not have one without redeclaring the class.
 */

/**
 * A tiny HTTP client with a cookie jar per "browser".
 */
final class TestBrowser
{
    private string $jar;

    private string $clientIp;

    private static int $browsers = 0;

    /**
     * Each browser gets its own client address, sent as X-Forwarded-For, which
     * the throwaway test config tells the server to trust.
     *
     * The routes that matter are rate limited per IP, and every request in
     * these suites otherwise arrives from 127.0.0.1 — so a suite making more
     * than a handful of POSTs to one route was answered 429 part way through,
     * and read as the feature being broken rather than as the limiter working.
     * Clearing the counter between requests was tried first and is not
     * reliable: it lives in whichever cache backend the SERVER process
     * resolved, and a test running in this process cannot be sure of reaching
     * it. Giving each browser its own address needs no such assumption.
     *
     * A browser keeps ONE address for its lifetime, deliberately: a test that
     * wants the limiter to trip reuses a browser (or passes $clientIp), and one
     * that does not want to fight it takes a fresh browser.
     *
     * 198.51.100.0/24 is TEST-NET-2, reserved for documentation, so these can
     * never collide with anything real.
     */
    public function __construct(private string $baseUrl, string $name, ?string $clientIp = null)
    {
        $this->jar = sys_get_temp_dir() . '/cc-test-' . $name . '-' . getmypid() . '.cookies';
        @unlink($this->jar);

        self::$browsers++;
        $this->clientIp = $clientIp ?? '198.51.100.' . (self::$browsers % 254 + 1);
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param array<string,string>|null $post
     * @return array{status:int,body:string,headers:string,location:string}
     */
    public function request(string $path, ?array $post = null, bool $follow = false): array
    {
        $handle = curl_init($this->baseUrl . $path);

        if ($handle === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => ['X-Forwarded-For: ' . $this->clientIp],
        ];

        if ($post !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('Request to ' . $path . ' failed: ' . $error);
        }

        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headers = substr((string) $raw, 0, $headerSize);
        $body    = substr((string) $raw, $headerSize);

        $location = '';

        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $match) === 1) {
            $location = trim($match[1]);
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers, 'location' => $location];
    }

    public function get(string $path, bool $follow = false): array
    {
        return $this->request($path, null, $follow);
    }

    /** @param array<string,string> $data */
    public function post(string $path, array $data, bool $follow = false): array
    {
        return $this->request($path, $data, $follow);
    }

    /** Fetch a page and pull its CSRF token out of the form. */
    public function csrfToken(string $path): string
    {
        $response = $this->get($path);

        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $response['body'], $match) === 1) {
            return $match[1];
        }

        if (preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $response['body'], $match) === 1) {
            return $match[1];
        }

        throw new RuntimeException('No CSRF token found on ' . $path);
    }

    /** @param array<string,string> $data */
    public function postWithToken(string $tokenPath, string $path, array $data, bool $follow = false): array
    {
        $data['_token'] = $this->csrfToken($tokenPath);

        return $this->post($path, $data, $follow);
    }

    public function login(string $email, string $password): array
    {
        return $this->postWithToken('/login', '/login', [
            'email'    => $email,
            'password' => $password,
        ]);
    }
}
