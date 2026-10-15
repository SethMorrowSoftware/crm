<?php

declare(strict_types=1);

/**
 * Who may administer whom.
 *
 * This file exists because of a confirmed privilege-escalation hole: the role
 * system checked which role you may GRANT and never checked whose account you
 * may ACT ON. A non-owner holding users_manage could leave the Owner's role
 * untouched — so that check never fired — and set the Owner's PASSWORD, then
 * sign in as them.
 *
 * At that point every separation the roles draw is decorative, including the
 * backup_manage split that exists so whoever can export every guest's name,
 * email and phone number is not automatically whoever can take over the
 * account. docs/PRIVACY.md describes that separation as real. These tests are
 * what make it real, and they are written against HTTP rather than the ACL
 * alone because the hole was in a controller that never asked the ACL.
 */

use App\Core\Auth;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/user-admin');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('user-admin suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('user-admin suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

$gm = new TestBrowser($baseUrl, 'ua-gm');
$gm->login('gm@test.invalid', 'gm-test-passphrase-9');

/** @return array{id:int,hash:string} */
function account(string $email): array
{
    $row = DB::first(
        'SELECT id, password_hash FROM {users} WHERE email = :e AND deleted_at IS NULL',
        ['e' => $email]
    );

    Assert::notNull($row, 'fixture account missing: ' . $email);

    return ['id' => (int) $row['id'], 'hash' => (string) $row['password_hash']];
}

Harness::test('the general manager really does hold users_manage', function () use ($gm): void {
    // The precondition that makes every test below meaningful. If this role
    // ever loses users_manage, the escalation tests would pass for the wrong
    // reason — refused because the whole page is closed to them, not because
    // the target-side rule works.
    $response = $gm->get('/settings/users');

    Assert::same(200, $response['status'], 'the user admin page must be open to this role');
    Assert::contains('Owner', $response['body'], 'and must list the owner');
});

Harness::test('users_manage cannot set the owner password', function () use ($gm): void {
    // THE escalation. Everything else here is a guard around it.
    $owner = account('owner@test.invalid');

    // The token comes from a page this role CAN open — its own user list —
    // and is then aimed at the owner. That is what an attacker would do, and
    // it also keeps the test honest: minting it from the owner's own form now
    // fails outright, which would make these tests pass for the wrong reason.
    $response = $gm->postWithToken(
        '/settings/users',
        '/settings/users/' . $owner['id'],
        [
            'name'      => 'Owner',
            'email'     => 'owner@test.invalid',
            'role_id'   => (string) (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'"),
            'password'  => 'taken-over-by-the-manager',
            'is_active' => '1',
        ],
        true
    );

    Assert::same(200, $response['status']);

    $after = account('owner@test.invalid');

    Assert::same(
        $owner['hash'],
        $after['hash'],
        'the owner password hash must be untouched — if this changes, a manager owns the venue'
    );
    Assert::false(
        password_verify('taken-over-by-the-manager', $after['hash']),
        'and the attempted password must not work'
    );
    Assert::true(
        password_verify('owner-test-passphrase-9', $after['hash']),
        'while the real one still does'
    );
});

Harness::test('users_manage cannot open the owner edit form', function () use ($gm): void {
    $owner = account('owner@test.invalid');

    $response = $gm->get('/settings/users/' . $owner['id'], true);

    Assert::notContains(
        'name="password"',
        $response['body'],
        'a form that cannot be submitted should not be offered, and the owner\'s details '
            . 'should not be shown to somebody who may not act on the account'
    );
});

Harness::test('users_manage cannot delete the owner', function () use ($gm): void {
    $owner = account('owner@test.invalid');

    // A SECOND owner first, deliberately. The last-owner guard already refuses
    // to delete the only remaining owner, so without this the test passes
    // whatever mayManageUser() does — MEASURED: with the fix reverted it still
    // passed, which makes it worthless as a test of this rule. With another
    // owner present, that guard stands down and only the target-side check is
    // left to refuse.
    $spare = test_make_user('owner', ['email' => 'spare-owner@example.test']);

    try {
        $gm->postWithToken(
            '/settings/users',
            '/settings/users/' . $owner['id'] . '/delete',
            [],
            true
        );

        $still = DB::first(
            'SELECT id FROM {users} WHERE id = :id AND deleted_at IS NULL',
            ['id' => $owner['id']]
        );

        Assert::notNull($still, 'deleting the account above you removes the oversight above you');
    } finally {
        DB::delete('users', 'id = :id', ['id' => $spare]);
    }
});

Harness::test('users_manage can still administer a less privileged account', function () use ($gm): void {
    // The rule has to bite only upwards. A general manager resetting a front
    // desk password is the normal support action this page exists for, and a
    // fix that broke it would be worse than the hole.
    $desk = account('desk@test.invalid');

    $response = $gm->postWithToken(
        '/settings/users/' . $desk['id'],
        '/settings/users/' . $desk['id'],
        [
            'name'      => 'Front Desk Dana',
            'email'     => 'desk@test.invalid',
            'role_id'   => (string) (int) DB::value("SELECT id FROM {roles} WHERE slug = 'front_desk'"),
            'password'  => 'quiet-orbit-lantern-42',
            'is_active' => '1',
        ],
        true
    );

    Assert::same(200, $response['status']);

    $after = account('desk@test.invalid');

    Assert::true(
        password_verify('quiet-orbit-lantern-42', $after['hash']),
        'a manager must still be able to reset a front desk password'
    );

    // Put it back, so the shared fixture account still logs in for other suites.
    DB::update(
        'users',
        ['password_hash' => Auth::hash('desk-test-passphrase-9')],
        'id = :id',
        ['id' => $desk['id']]
    );
});
