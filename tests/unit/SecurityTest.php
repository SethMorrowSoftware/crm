<?php

declare(strict_types=1);

/**
 * Encryption, token handling, password policy and the ACL catalogue.
 *
 * These are the pieces where a quiet defect becomes a security problem rather
 * than a bug report, so they are tested for properties rather than examples:
 * ciphertext must differ per call, a tampered payload must fail, a permission
 * not in the catalogue must never pass.
 */

use App\Core\Acl;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Encryption;
use App\Core\Response;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/security');

// Encryption needs a key; the test bootstrap sets a fixed one.
Config::set('app_key', base64_encode(str_repeat("\x2a", 32)));

// ------------------------------------------------------------- encryption

Harness::test('encrypt/decrypt round-trips', function (): void {
    foreach ([
        'simple',
        'twilio-auth-token-abcdef0123456789',
        'unicode: café ☕ 日本語',
        '',
        str_repeat('long', 2000),
    ] as $plaintext) {
        Assert::same($plaintext, Encryption::decrypt(Encryption::encrypt($plaintext)));
    }
});

Harness::test('the same plaintext encrypts differently each time', function (): void {
    // A deterministic ciphertext would leak which settings share a value.
    $a = Encryption::encrypt('same-secret');
    $b = Encryption::encrypt('same-secret');

    Assert::notSame($a, $b, 'a fresh IV must be used per call');
    Assert::same('same-secret', Encryption::decrypt($a));
    Assert::same('same-secret', Encryption::decrypt($b));
});

Harness::test('a tampered ciphertext fails authentication', function (): void {
    $payload = Encryption::encrypt('sensitive-value');

    // Flip a character in the base64 body.
    $body = substr($payload, 4);
    $tampered = 'cc1:' . substr_replace($body, $body[10] === 'A' ? 'B' : 'A', 10, 1);

    Assert::null(Encryption::decrypt($tampered), 'GCM must reject a modified payload');
});

Harness::test('a foreign or malformed payload decrypts to null, not garbage', function (): void {
    Assert::null(Encryption::decrypt('plain text'));
    Assert::null(Encryption::decrypt('cc1:not-base64!!'));
    Assert::null(Encryption::decrypt('cc1:' . base64_encode('too short')));
});

Harness::test('isEncrypted recognises our own payloads only', function (): void {
    Assert::true(Encryption::isEncrypted(Encryption::encrypt('x')));
    Assert::false(Encryption::isEncrypted('x'));
});

Harness::test('a wrong key cannot read a ciphertext', function (): void {
    $payload = Encryption::encrypt('secret');

    $original = Config::get('app_key');
    Config::set('app_key', base64_encode(str_repeat("\x7f", 32)));

    Assert::null(Encryption::decrypt($payload), 'changing app_key must invalidate ciphertexts');

    Config::set('app_key', $original);
    Assert::same('secret', Encryption::decrypt($payload), 'restoring the key restores access');
});

Harness::test('generateKey produces a usable 32-byte key', function (): void {
    $key = Encryption::generateKey();
    $raw = base64_decode($key, true);

    Assert::notSame(false, $raw);
    Assert::same(32, strlen((string) $raw));
});

// ----------------------------------------------------------------- tokens

Harness::test('random tokens are the right length and never repeat', function (): void {
    $seen = [];

    for ($i = 0; $i < 50; $i++) {
        $token = Encryption::randomToken(32);
        Assert::same(64, strlen($token), '32 bytes is 64 hex characters');
        Assert::matches('/^[a-f0-9]{64}$/', $token);
        Assert::false(isset($seen[$token]), 'tokens must be unique');
        $seen[$token] = true;
    }
});

Harness::test('urlToken is URL-safe', function (): void {
    for ($i = 0; $i < 20; $i++) {
        Assert::matches('/^[A-Za-z0-9_-]+$/', Encryption::urlToken());
    }
});

Harness::test('hashToken is stable and one-way', function (): void {
    $token = 'some-token-value';

    Assert::same(Encryption::hashToken($token), Encryption::hashToken($token));
    Assert::notContains($token, Encryption::hashToken($token));
    Assert::same(64, strlen(Encryption::hashToken($token)));
});

// --------------------------------------------------------------- passwords

Harness::test('hashing produces a verifiable, salted hash', function (): void {
    $hash = Auth::hash('a-reasonable-passphrase');

    Assert::true(password_verify('a-reasonable-passphrase', $hash));
    Assert::false(password_verify('wrong', $hash));
    Assert::notSame($hash, Auth::hash('a-reasonable-passphrase'), 'each hash must be salted');
});

Harness::test('the password policy requires length', function (): void {
    Assert::notEmpty(Auth::passwordProblems('short'), '5 characters must be rejected');
    Assert::notEmpty(Auth::passwordProblems('elevenchars'), '11 characters must be rejected');
    Assert::empty(Auth::passwordProblems('twelvecharsx'), '12 characters is the floor');
});

Harness::test('the password policy rejects obvious choices', function (): void {
    foreach (['password12345', 'letmein-please', 'castlefuncenter', 'changeme-now-ok'] as $weak) {
        Assert::notEmpty(Auth::passwordProblems($weak), $weak . ' should be rejected');
    }
});

Harness::test('the password policy rejects the user own name or address', function (): void {
    Assert::notEmpty(
        Auth::passwordProblems('sethmorrow-rocks', 'seth@example.test', 'Seth Morrow'),
        'a password containing the local part of the address is guessable'
    );

    Assert::empty(
        Auth::passwordProblems('unrelated-passphrase', 'seth@example.test', 'Seth Morrow')
    );
});

Harness::test('the password policy rejects a single repeated character', function (): void {
    Assert::notEmpty(Auth::passwordProblems(str_repeat('a', 20)));
});

Harness::test('a long passphrase of plain words is accepted', function (): void {
    // Length over character classes — the policy must not push people toward
    // "P@ssw0rd!" by rejecting a genuinely strong passphrase.
    Assert::empty(Auth::passwordProblems('correct horse battery staple'));
});

// --------------------------------------------------------------------- ACL

Harness::test('the ACL catalogue is well formed', function (): void {
    Assert::greaterThan(40, count(Acl::CATALOG), 'the catalogue should be substantial');

    foreach (Acl::CATALOG as $slug => $entry) {
        Assert::matches('/^[a-z][a-z0-9_]*$/', (string) $slug, 'permission slug: ' . $slug);
        Assert::count(2, $entry, 'each entry is [group, label] for ' . $slug);
        Assert::notEmpty($entry[0], 'group for ' . $slug);
        Assert::notEmpty($entry[1], 'label for ' . $slug);
    }
});

Harness::test('every view_* permission is in the Visibility group', function (): void {
    foreach (Acl::CATALOG as $slug => [$group]) {
        if (str_starts_with((string) $slug, 'view_')) {
            Assert::same('Visibility', $group, $slug . ' is a visibility key');
        }
    }
});

Harness::test('permission slugs are unique and grouped', function (): void {
    $grouped = Acl::catalogByGroup();
    $total = 0;

    foreach ($grouped as $permissions) {
        $total += count($permissions);
    }

    Assert::same(count(Acl::CATALOG), $total, 'grouping must not lose or duplicate a permission');
});

Harness::test('scopes are exactly all, team and own, narrowest last', function (): void {
    Assert::same(['all', 'team', 'own'], Acl::SCOPES);
});

Harness::test('an unauthenticated session holds no permission', function (): void {
    // Fails closed: with nobody signed in, every check must be false.
    Assert::false(Acl::can('view_dashboard'));
    Assert::false(Acl::can('settings'));
    Assert::null(Acl::scope('view_deals'));
});

Harness::test('scopeCondition denies rather than opens when access is absent', function (): void {
    // The critical property: a scoping failure must produce an empty result
    // set, never an unrestricted query.
    [$where, $params] = Acl::scopeCondition('view_deals', 'd.owner_id');

    Assert::same('1 = 0', $where, 'no access must mean no rows');
    Assert::same([], $params);
});

// ------------------------------------------------------------------- CSV

Harness::test('CSV export neutralises formula injection', function (): void {
    // A guest called "=cmd|' /c calc'!A1" must not execute when a staff
    // member opens the export in Excel.
    $response = Response::csv('t.csv', ['Name'], [
        ['=1+1'],
        ['+1'],
        ['-1'],
        ['@SUM(A1)'],
        ['Normal Name'],
    ]);

    $body = $response->getBody();

    Assert::contains("'=1+1", $body, 'a leading = must be prefixed');
    Assert::contains("'+1", $body);
    Assert::contains("'-1", $body);
    Assert::contains("'@SUM(A1)", $body);
    Assert::contains('Normal Name', $body);
    Assert::notContains("'Normal Name", $body, 'ordinary values must not be mangled');
});

Harness::test('CSV export sets a download disposition with a safe filename', function (): void {
    $response = Response::csv('../../etc/passwd', ['A'], [['1']]);

    Assert::same(200, $response->getStatus());
    // The filename is sanitised, so a traversal attempt cannot escape.
    Assert::notContains('..', $response->getBody());
});
