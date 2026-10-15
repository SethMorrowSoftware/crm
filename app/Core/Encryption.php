<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Authenticated symmetric encryption for secrets at rest.
 *
 * Used for provider credentials (Twilio auth token, SMTP password, OAuth
 * refresh tokens) stored in the settings table. AES-256-GCM gives us
 * confidentiality and integrity in one primitive, so a tampered ciphertext
 * fails to decrypt rather than decrypting to garbage.
 *
 * Passwords are NOT encrypted — they are hashed (see Auth). Encryption is for
 * values the application must be able to read back.
 */
final class Encryption
{
    private const CIPHER     = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const PREFIX     = 'cc1:';

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Encrypt a string. Returns "cc1:" + base64(iv || tag || ciphertext).
     * The version prefix lets us migrate ciphers later without ambiguity.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv  = random_bytes(openssl_cipher_iv_length(self::CIPHER) ?: 12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by encrypt(). Returns null when the payload is
     * not ours, is malformed, or fails authentication — callers treat null as
     * "unreadable secret" and prompt the operator to re-enter it, which is the
     * right behaviour after an app_key change.
     */
    public static function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);

        if ($raw === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER) ?: 12;

        if (strlen($raw) <= $ivLength + self::TAG_LENGTH) {
            return null;
        }

        $iv         = substr($raw, 0, $ivLength);
        $tag        = substr($raw, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($raw, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            Log::warning('Failed to decrypt a stored secret (wrong app_key, or tampered value).');

            return null;
        }

        return $plaintext;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Constant-time comparison, for verifying tokens we hold both sides of.
     */
    public static function hashEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /**
     * One-way token hash for storing API keys, reset tokens, and session
     * identifiers. SHA-256 is appropriate here because these are already
     * high-entropy random values, not guessable passwords.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * A URL-safe random token, for public links (quotes, waivers, unsubscribe).
     */
    public static function urlToken(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function key(): string
    {
        $encoded = (string) Config::get('app_key', '');

        if ($encoded === '') {
            throw new RuntimeException(
                'No app_key is configured. Re-run the installer or set app_key in config/config.php.'
            );
        }

        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('app_key must be 32 random bytes, base64 encoded.');
        }

        return $key;
    }
}
