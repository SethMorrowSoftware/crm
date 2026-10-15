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

    public function __construct(private string $baseUrl, string $name)
    {
        $this->jar = sys_get_temp_dir() . '/cc-test-' . $name . '-' . getmypid() . '.cookies';
        @unlink($this->jar);
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
