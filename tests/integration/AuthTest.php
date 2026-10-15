<?php

declare(strict_types=1);

/**
 * Authentication, authorization scoping, auditing and the job queue, against
 * a real database.
 */

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/auth');

if (!test_boot()) {
    Harness::skip('auth suite', 'no database available');

    return;
}

test_reset_transactional_data();

/** A request object standing in for an HTTP request. */
function fake_request(): Request
{
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $_SERVER['HTTP_USER_AGENT'] = 'Castle CRM test runner';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/login';

    return Request::capture();
}

// ------------------------------------------------------------------- login

Harness::test('a correct password authenticates', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-ok@example.test',
        'password_hash' => Auth::hash('a-good-long-passphrase'),
    ]);

    $result = Auth::attempt('auth-ok@example.test', 'a-good-long-passphrase', fake_request());

    Assert::true($result['ok'], $result['error'] ?? '');

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a wrong password is refused', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-bad@example.test',
        'password_hash' => Auth::hash('the-real-passphrase'),
    ]);

    $result = Auth::attempt('auth-bad@example.test', 'the-wrong-one', fake_request());

    Assert::false($result['ok']);
    Assert::notEmpty($result['error']);

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('an unknown address is refused with the same wording as a wrong password', function (): void {
    // Different wording would let an attacker enumerate staff accounts.
    $id = test_make_user('owner', [
        'email'         => 'auth-enum@example.test',
        'password_hash' => Auth::hash('the-real-passphrase'),
    ]);

    $unknown = Auth::attempt('nobody-here@example.test', 'whatever', fake_request());
    $wrong   = Auth::attempt('auth-enum@example.test', 'wrong', fake_request());

    Assert::false($unknown['ok']);
    Assert::false($wrong['ok']);
    Assert::same(
        $unknown['error'],
        $wrong['error'],
        'the message must not reveal whether the account exists'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('an inactive account cannot sign in', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-inactive@example.test',
        'password_hash' => Auth::hash('a-good-long-passphrase'),
        'is_active'     => 0,
    ]);

    $result = Auth::attempt('auth-inactive@example.test', 'a-good-long-passphrase', fake_request());

    Assert::false($result['ok'], 'a deactivated account must be refused even with the right password');
    Assert::contains('not active', strtolower($result['error'] ?? ''));

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a soft-deleted account cannot sign in', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-deleted@example.test',
        'password_hash' => Auth::hash('a-good-long-passphrase'),
        'deleted_at'    => gmdate('Y-m-d H:i:s'),
    ]);

    $result = Auth::attempt('auth-deleted@example.test', 'a-good-long-passphrase', fake_request());

    Assert::false($result['ok']);

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('repeated failures lock the account', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-lock@example.test',
        'password_hash' => Auth::hash('the-real-passphrase'),
    ]);

    $request = fake_request();

    // Eight consecutive failures is the configured threshold.
    for ($i = 0; $i < 8; $i++) {
        Auth::attempt('auth-lock@example.test', 'wrong-' . $i, $request);
    }

    $lockedUntil = DB::value('SELECT locked_until FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::notNull($lockedUntil, 'the account should now be locked');

    // And the CORRECT password is refused while the lock holds — this is the
    // property that makes the lockout worth having.
    $result = Auth::attempt('auth-lock@example.test', 'the-real-passphrase', $request);

    Assert::false($result['ok'], 'a locked account must be refused even with the right password');
    Assert::hasKey('retry_after', $result);

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a successful login clears the failure counter', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-reset@example.test',
        'password_hash' => Auth::hash('the-real-passphrase'),
    ]);

    $request = fake_request();

    Auth::attempt('auth-reset@example.test', 'wrong', $request);
    Assert::greaterThan(0, (int) DB::value('SELECT failed_attempts FROM {users} WHERE id = :id', ['id' => $id]));

    Auth::attempt('auth-reset@example.test', 'the-real-passphrase', $request);

    Assert::same(0, (int) DB::value('SELECT failed_attempts FROM {users} WHERE id = :id', ['id' => $id]));
    Assert::notNull(DB::value('SELECT last_login_at FROM {users} WHERE id = :id', ['id' => $id]));

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('every login attempt is audited', function (): void {
    DB::delete('audit_log', '1 = 1');

    $id = test_make_user('owner', [
        'email'         => 'auth-audit@example.test',
        'password_hash' => Auth::hash('the-real-passphrase'),
    ]);

    $request = fake_request();

    Auth::attempt('auth-audit@example.test', 'wrong', $request);
    Auth::attempt('auth-audit@example.test', 'the-real-passphrase', $request);

    $actions = DB::column('SELECT action FROM {audit_log} ORDER BY id');

    Assert::inArray('login.failed', $actions);
    Assert::inArray('login.success', $actions);

    // The IP must be recorded, since this is the forensic record.
    $ip = DB::value("SELECT ip FROM {audit_log} WHERE action = 'login.failed' LIMIT 1");
    Assert::same('198.51.100.7', $ip);

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('an audit row never stores a password or its hash', function (): void {
    DB::delete('audit_log', '1 = 1');

    $id = test_make_user('owner', [
        'email'         => 'auth-nosecret@example.test',
        'password_hash' => Auth::hash('super-secret-passphrase'),
    ]);

    Auth::attempt('auth-nosecret@example.test', 'super-secret-passphrase', fake_request());

    $rows = DB::column('SELECT IFNULL(changes, "") FROM {audit_log}');
    $all = implode(' ', array_map('strval', $rows));

    Assert::notContains('super-secret-passphrase', $all);
    Assert::notContains('argon2', strtolower($all));

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a rehash-needed hash is upgraded on a successful login', function (): void {
    // An old bcrypt hash should quietly become Argon2id next time the user
    // signs in, without them noticing.
    $id = test_make_user('owner', [
        'email'         => 'auth-rehash@example.test',
        'password_hash' => password_hash('a-good-long-passphrase', PASSWORD_BCRYPT, ['cost' => 4]),
    ]);

    $before = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);
    Assert::contains('$2y$04$', $before, 'starts as a weak-cost bcrypt hash');

    Auth::attempt('auth-rehash@example.test', 'a-good-long-passphrase', fake_request());

    $after = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::notSame($before, $after, 'the hash should have been upgraded');
    Assert::true(password_verify('a-good-long-passphrase', $after), 'and still verify');

    DB::delete('users', 'id = :id', ['id' => $id]);
});

// --------------------------------------------------------- password change

Harness::test('changing a password revokes other sessions', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'auth-sessions@example.test',
        'password_hash' => Auth::hash('the-old-passphrase'),
    ]);

    // Two sessions, as if the user were signed in on two devices.
    foreach (['a', 'b'] as $suffix) {
        DB::insert('sessions', [
            'id'            => str_repeat($suffix, 64),
            'user_id'       => $id,
            'payload'       => '{}',
            'last_activity' => gmdate('Y-m-d H:i:s'),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    Assert::same(2, (int) DB::value('SELECT COUNT(*) FROM {sessions} WHERE user_id = :id', ['id' => $id]));

    Auth::changePassword($id, 'a-brand-new-passphrase', fake_request());

    // No session is current in this context, so all of them go.
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {sessions} WHERE user_id = :id', ['id' => $id]),
        'a password change must end other sessions'
    );

    Assert::true(password_verify(
        'a-brand-new-passphrase',
        (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id])
    ));

    DB::delete('users', 'id = :id', ['id' => $id]);
});

// ------------------------------------------------------------------- audit

Harness::test('Audit::record stores a field-level diff', function (): void {
    DB::delete('audit_log', '1 = 1');

    Audit::init(fake_request());
    Audit::record(
        'contact.updated',
        'contacts',
        99,
        ['first_name' => 'Old', 'email' => 'old@example.test', 'updated_at' => 'x'],
        ['first_name' => 'New', 'email' => 'old@example.test', 'updated_at' => 'y']
    );

    $row = DB::first('SELECT * FROM {audit_log} ORDER BY id DESC LIMIT 1');
    $changes = json_decode((string) $row['changes'], true);

    Assert::hasKey('first_name', $changes, 'a changed field must be recorded');
    Assert::same('Old', $changes['first_name']['from']);
    Assert::same('New', $changes['first_name']['to']);

    Assert::false(
        array_key_exists('email', $changes),
        'an unchanged field must not be recorded'
    );
    Assert::false(
        array_key_exists('updated_at', $changes),
        'updated_at changes on every write and is pure noise'
    );
});

Harness::test('Audit redacts sensitive fields from the diff', function (): void {
    DB::delete('audit_log', '1 = 1');

    Audit::init(fake_request());
    Audit::record('user.updated', 'users', 1,
        ['password_hash' => 'old-hash'],
        ['password_hash' => 'new-hash']
    );

    $changes = (string) DB::value('SELECT changes FROM {audit_log} ORDER BY id DESC LIMIT 1');

    Assert::notContains('new-hash', $changes);
    Assert::notContains('old-hash', $changes);
    Assert::contains('redacted', $changes, 'the fact of the change is still recorded');
});

Harness::test('Audit::event records an action with no row change', function (): void {
    DB::delete('audit_log', '1 = 1');

    Audit::init(fake_request());
    Audit::event('audit.exported', ['rows' => 500]);

    $row = DB::first('SELECT * FROM {audit_log} ORDER BY id DESC LIMIT 1');

    Assert::same('audit.exported', $row['action']);
    Assert::contains('500', (string) $row['changes']);
});

// --------------------------------------------------------------------- ACL

Harness::test('the owner holds every permission', function (): void {
    $id = test_make_user('owner');
    Auth::login($id, fake_request());
    Acl::forget();

    Assert::true(Auth::isOwner());

    foreach (array_keys(Acl::CATALOG) as $permission) {
        Assert::true(Acl::can($permission), 'owner should hold ' . $permission);
    }

    Auth::logout(fake_request());
    Acl::forget();
    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('front desk holds bookings but not settings or revenue', function (): void {
    $id = test_make_user('front_desk');
    Auth::login($id, fake_request());
    Acl::forget();

    Assert::true(Acl::can('view_bookings'));
    Assert::true(Acl::can('bookings_manage'));
    Assert::true(Acl::can('checkin'));

    Assert::false(Acl::can('settings'), 'front desk must not reach settings');
    Assert::false(Acl::can('view_revenue'), 'front desk must not see money');
    Assert::false(Acl::can('users_manage'));
    Assert::false(Acl::can('campaigns_send'));
    Assert::false(Acl::can('sending_override'));

    Auth::logout(fake_request());
    Acl::forget();
    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('scopeCondition narrows a query to the user own records', function (): void {
    $id = test_make_user('party_host');
    Auth::login($id, fake_request());
    Acl::forget();

    Assert::same('own', Acl::scope('view_deals'));

    [$where, $params] = Acl::scopeCondition('view_deals', 'd.owner_id');

    Assert::contains('d.owner_id', $where);
    Assert::same($id, $params['acl_user_id'] ?? null);

    Auth::logout(fake_request());
    Acl::forget();
    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a user with no role holds nothing', function (): void {
    // Fail closed: a role deleted out from under a user must not open access.
    $id = test_make_user('owner', ['role_id' => null]);
    Auth::login($id, fake_request());
    Acl::forget();

    Assert::false(Auth::isOwner());
    Assert::same([], Acl::permissions());
    Assert::false(Acl::can('view_dashboard'));

    Auth::logout(fake_request());
    Acl::forget();
    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a stale permission row is ignored if it is not in the catalogue', function (): void {
    // A permission removed from the code must stop working even if a row
    // survives in the database.
    $roleId = DB::insert('roles', [
        'slug' => 'test-stale-' . bin2hex(random_bytes(3)),
        'name' => 'Stale Test',
        'is_system' => 0, 'sort_order' => 999,
        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $permissionId = DB::insert('permissions', [
        'slug' => 'removed_from_code', 'group' => 'Legacy', 'label' => 'Gone',
    ]);

    DB::insert('role_permissions', [
        'role_id' => $roleId, 'permission_id' => $permissionId, 'scope' => 'all',
    ]);

    $userId = test_make_user('owner', ['role_id' => $roleId]);
    Auth::login($userId, fake_request());
    Acl::forget();

    Assert::false(
        isset(Acl::permissions()['removed_from_code']),
        'an uncatalogued permission must be discarded'
    );

    Auth::logout(fake_request());
    Acl::forget();
    DB::delete('users', 'id = :id', ['id' => $userId]);
    DB::delete('role_permissions', 'role_id = :r', ['r' => $roleId]);
    DB::delete('roles', 'id = :r', ['r' => $roleId]);
    DB::delete('permissions', 'id = :p', ['p' => $permissionId]);
});

Harness::test('mayAssignRole prevents privilege escalation', function (): void {
    $marketingUser = test_make_user('marketing');
    Auth::login($marketingUser, fake_request());
    Acl::forget();

    $ownerRole = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'");
    $frontRole = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'front_desk'");

    Assert::false(
        Acl::mayAssignRole($ownerRole),
        'a marketing user must not be able to mint an owner'
    );

    // They also lack users_manage, so they cannot assign anything at all.
    Assert::false(Acl::mayAssignRole($frontRole));

    Auth::logout(fake_request());
    Acl::forget();
    DB::delete('users', 'id = :id', ['id' => $marketingUser]);
});

// ------------------------------------------------------------- job queue

Harness::test('the job queue claims a job exactly once', function (): void {
    DB::delete('jobs', '1 = 1');

    $jobId = DB::insert('jobs', [
        'queue' => 'default', 'type' => 'noop', 'payload' => '{}',
        'available_at' => gmdate('Y-m-d H:i:s'), 'status' => 'pending',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    // Two workers racing for the same row: the conditional UPDATE means only
    // one can win, which is what stops a message being sent twice.
    $first = DB::update('jobs', [
        'status' => 'reserved', 'reserved_at' => gmdate('Y-m-d H:i:s'),
    ], "id = :id AND status = 'pending'", ['id' => $jobId]);

    $second = DB::update('jobs', [
        'status' => 'reserved', 'reserved_at' => gmdate('Y-m-d H:i:s'),
    ], "id = :id AND status = 'pending'", ['id' => $jobId]);

    Assert::same(1, $first, 'the first worker claims it');
    Assert::same(0, $second, 'the second must find nothing to claim');

    DB::delete('jobs', '1 = 1');
});

Harness::test('a transaction rolls back on failure', function (): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {contacts}');

    try {
        DB::transaction(static function (): void {
            test_make_contact(['first_name' => 'Rollback']);

            throw new RuntimeException('deliberate failure');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {contacts}'),
        'a failed transaction must leave nothing behind'
    );
});

Harness::test('nested transactions roll back to their savepoint', function (): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {contacts}');

    DB::transaction(static function (): void {
        test_make_contact(['first_name' => 'Outer']);

        try {
            DB::transaction(static function (): void {
                test_make_contact(['first_name' => 'Inner']);

                throw new RuntimeException('inner failure');
            });
        } catch (RuntimeException) {
            // The inner work is rolled back; the outer continues.
        }
    });

    Assert::same(
        $before + 1,
        (int) DB::value('SELECT COUNT(*) FROM {contacts}'),
        'only the inner transaction should have been discarded'
    );

    DB::delete('contacts', "first_name IN ('Outer','Inner')");
});

// ------------------------------------------------------------ SQL safety

Harness::test('a bound parameter cannot inject SQL', function (): void {
    $id = test_make_contact(['last_name' => "O'Brien; DROP TABLE cc_contacts; --"]);

    // The table must still exist and the value must round-trip verbatim.
    Assert::true(DB::tableExists('contacts'));

    $stored = DB::value('SELECT last_name FROM {contacts} WHERE id = :id', ['id' => $id]);

    Assert::same("O'Brien; DROP TABLE cc_contacts; --", $stored);

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('quoteIdentifier refuses an unsafe identifier', function (): void {
    Assert::same('`contacts`', DB::quoteIdentifier('contacts'));

    foreach (['con`tacts', 'contacts; DROP', 'a b', '1abc', ''] as $unsafe) {
        Assert::throws(
            RuntimeException::class,
            static fn () => DB::quoteIdentifier($unsafe),
            'must reject: ' . $unsafe
        );
    }
});

Harness::test('Model::safeOrderBy rejects an injected sort column', function (): void {
    // A list view that passes a user-supplied sort column must not become an
    // injection point.
    $method = new ReflectionMethod(App\Core\Model::class, 'safeOrderBy');
    $method->setAccessible(true);

    $model = new class extends App\Core\Model {
        protected static string $table = 'contacts';
    };

    Assert::same('`last_name` ASC', $method->invoke($model, 'last_name ASC'));
    Assert::same('`id` DESC', $method->invoke($model, 'id; DROP TABLE x'), 'falls back safely');
    Assert::same('`id` DESC', $method->invoke($model, '(SELECT 1)'), 'falls back safely');
});
