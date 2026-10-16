<?php

declare(strict_types=1);

/**
 * Contacts and households, end to end over HTTP.
 *
 * Three things here are worth more than the rest, and they are the three that
 * would be expensive to discover in production:
 *
 *   1. **Consent cannot be set as a side effect of editing a contact.** The
 *      form offers no checkbox, and posting `email_consent=1` to the update
 *      endpoint anyway must not set it. docs/PRIVACY.md §3 says consent is
 *      evidence of something a guest did; a flag somebody can flip while
 *      correcting a surname is not evidence of anything.
 *   2. **A scope is a scope on the record page, not only on the list.** An
 *      own-scoped role typing another rep's contact id into the URL gets a
 *      404, not a guest record.
 *   3. **Money is scrubbed server-side** for a role without view_revenue, so
 *      front-desk staff look a guest up without seeing what that guest is
 *      worth.
 *
 * Every test here was mutation-checked: the code it covers was broken, the
 * test was confirmed to fail, and the code was restored. A test that cannot
 * fail is worse than no test, because it reads as coverage.
 *
 * One honest qualification on that, because it affects how to read the
 * permission tests. Each gate exists TWICE — the `can:` middleware on the
 * route and an authorize() call inside the action — so removing either one on
 * its own leaves the other answering 403 and the test still passes. MEASURED:
 * the eight single-layer mutations survived for exactly that reason, and the
 * six that remove BOTH layers of a gate are each caught. So these tests pin
 * "this role cannot do this", not "this particular line is what stops them",
 * which is the right thing to pin and worth knowing when reading a green run.
 * The same is true of the money test: the view gate and the server-side scrub
 * are independent, and it is their pair that the test holds.
 */

use App\Core\Auth;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/contacts');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('contacts suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('contacts suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

/** A short unique marker, so a search assertion cannot match another suite's data. */
function contacts_tag(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(4));
}

/**
 * Create a role with exactly these permissions and scopes.
 *
 * The seeded roles all hold view_contacts at 'all', so an own-scoped role has
 * to be built here — and without one, the scope tests would pass for the wrong
 * reason (everything visible to everybody).
 *
 * @param array<string,string> $permissions slug => scope
 */
function contacts_make_role(string $slug, string $name, array $permissions): int
{
    $roleId = DB::insert('roles', [
        'slug'        => $slug,
        'name'        => $name,
        'description' => 'Created by the contacts test suite.',
        'is_system'   => 0,
        'sort_order'  => 200,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    foreach ($permissions as $permissionSlug => $scope) {
        $permissionId = (int) DB::value(
            'SELECT id FROM {permissions} WHERE slug = :s',
            ['s' => $permissionSlug]
        );

        Assert::greaterThan(0, $permissionId, 'permission should be seeded: ' . $permissionSlug);

        DB::insert('role_permissions', [
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'scope'         => $scope,
        ]);
    }

    return $roleId;
}

/** @return array{id:int,email:string,password:string} */
function contacts_make_account(int $roleId, string $label): array
{
    $email    = $label . '-' . bin2hex(random_bytes(4)) . '@test.invalid';
    $password = 'contacts-suite-passphrase-9';

    $id = DB::insert('users', [
        'name'          => ucfirst($label) . ' Tester',
        'email'         => $email,
        'password_hash' => Auth::hash($password),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'created_at'    => now_utc(),
        'updated_at'    => now_utc(),
    ]);

    return ['id' => $id, 'email' => $email, 'password' => $password];
}

/** @return array<string,mixed> */
function contacts_row(int $id): array
{
    $row = DB::first('SELECT * FROM {contacts} WHERE id = :id', ['id' => $id]);

    Assert::notNull($row, 'contact ' . $id . ' should exist');

    /** @var array<string,mixed> $row */
    return $row;
}

/** The id the response redirected to, for a Location of /contacts/123 or /households/123. */
function contacts_redirect_id(string $location): int
{
    Assert::matches('#/(contacts|households)/\d+#', $location, 'should redirect to the new record');

    preg_match('#/(?:contacts|households)/(\d+)#', $location, $match);

    return (int) ($match[1] ?? 0);
}

function contacts_audit_exists(string $action, int $entityId): bool
{
    return DB::exists(
        'SELECT id FROM {audit_log} WHERE action = :a AND entity_id = :e',
        ['a' => $action, 'e' => $entityId]
    );
}

// ---------------------------------------------------------------- actors

$ownerId = (int) DB::value("SELECT id FROM {users} WHERE email = 'owner@test.invalid'");

// view_contacts only: can read the list, must not be able to create or edit.
$viewerRole = contacts_make_role('contacts_test_viewer', 'Contacts Test Viewer', [
    'view_dashboard' => 'all',
    'view_contacts'  => 'all',
]);

// The party-host shape: their own guests only.
$repRole = contacts_make_role('contacts_test_rep', 'Contacts Test Rep', [
    'view_dashboard'  => 'all',
    'view_contacts'   => 'own',
    'contacts_manage' => 'own',
]);

$viewerAccount = contacts_make_account($viewerRole, 'viewer');
$repAccount    = contacts_make_account($repRole, 'rep');

$owner  = new TestBrowser($baseUrl, 'contacts-owner');
$desk   = new TestBrowser($baseUrl, 'contacts-desk');
$viewer = new TestBrowser($baseUrl, 'contacts-viewer');
$rep    = new TestBrowser($baseUrl, 'contacts-rep');

$owner->login('owner@test.invalid', 'owner-test-passphrase-9');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');
$viewer->login($viewerAccount['email'], $viewerAccount['password']);
$rep->login($repAccount['email'], $repAccount['password']);

// ------------------------------------------------------------ the list

Harness::test('the contact list renders a contact', function () use ($owner): void {
    $surname = contacts_tag('Listcheck');
    $id = test_make_contact(['first_name' => 'Marta', 'last_name' => $surname]);

    try {
        $response = $owner->get('/contacts');

        Assert::same(200, $response['status']);
        Assert::contains($surname, $response['body'], 'the list should show the contact');
        Assert::contains('Add a contact', $response['body'], 'and offer the create action to this role');
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('search by name finds one contact and leaves the other out', function () use ($owner): void {
    $wanted   = contacts_tag('Wanted');
    $unwanted = contacts_tag('Unwanted');

    $a = test_make_contact(['first_name' => 'Ada', 'last_name' => $wanted]);
    $b = test_make_contact(['first_name' => 'Bea', 'last_name' => $unwanted]);

    try {
        $response = $owner->get('/contacts?q=' . urlencode($wanted));

        Assert::same(200, $response['status']);
        Assert::contains($wanted, $response['body']);
        Assert::notContains(
            $unwanted,
            $response['body'],
            'a search that returns everybody is not a search'
        );
    } finally {
        DB::delete('contacts', 'id IN (:a, :b)', ['a' => $a, 'b' => $b]);
    }
});

Harness::test('search by phone matches however the number was typed', function () use ($owner): void {
    // The point of phone_normalized: the guest wrote it one way, staff type it
    // another, and both have to find the same record.
    $surname = contacts_tag('Phonecheck');
    $id = test_make_contact([
        'first_name'       => 'Dev',
        'last_name'        => $surname,
        'phone'            => '845-555-8812',
        'phone_normalized' => '+18455558812',
    ]);

    try {
        foreach (['(845) 555-8812', '845.555.8812', '18455558812', '5558812'] as $typed) {
            $response = $owner->get('/contacts?q=' . urlencode($typed));

            Assert::contains(
                $surname,
                $response['body'],
                'searching "' . $typed . '" should find the contact'
            );
        }
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('the stage filter narrows the list', function () use ($owner): void {
    $memberName = contacts_tag('Stagemember');
    $leadName   = contacts_tag('Stagelead');

    $a = test_make_contact(['last_name' => $memberName, 'lifecycle_stage' => 'member']);
    $b = test_make_contact(['last_name' => $leadName, 'lifecycle_stage' => 'lead']);

    try {
        $response = $owner->get('/contacts?lifecycle_stage=member');

        Assert::contains($memberName, $response['body']);
        Assert::notContains($leadName, $response['body'], 'a lead is not a member');
    } finally {
        DB::delete('contacts', 'id IN (:a, :b)', ['a' => $a, 'b' => $b]);
    }
});

Harness::test('a guest name is escaped, not executed', function () use ($owner): void {
    // Names reach this application from booking forms and a POS import, so
    // they are not trusted input. A record page is also where staff look a
    // guest up, which makes it the highest-value place to land a payload.
    $marker  = contacts_tag('xss');
    $payload = '<script>alert("' . $marker . '")</script>';

    $id = test_make_contact(['first_name' => 'Mallory', 'last_name' => $payload]);

    try {
        foreach (['/contacts', '/contacts/' . $id] as $path) {
            $body = $owner->get($path)['body'];

            Assert::contains($marker, $body, $path . ' should still show the name');
            Assert::notContains(
                '<script>alert("' . $marker,
                $body,
                $path . ' must escape it rather than emit a live tag'
            );
            Assert::contains('&lt;script&gt;', $body, $path . ' should render it as text');
        }
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

// ------------------------------------------------------------- creating

Harness::test('creating a contact stores it, normalises the phone, and is audited', function () use ($owner): void {
    $surname = contacts_tag('Created');

    $response = $owner->postWithToken('/contacts/new', '/contacts', [
        'first_name'      => 'Nadia',
        'last_name'       => $surname,
        'email'           => 'NADIA.' . $surname . '@example.test',
        'phone'           => '(845) 555-0199',
        'postal_code'     => '10918',
        'lifecycle_stage' => 'prospect',
    ]);

    $id = contacts_redirect_id($response['location']);

    try {
        $row = contacts_row($id);

        Assert::same($surname, $row['last_name']);
        Assert::same(
            '+18455550199',
            $row['phone_normalized'],
            'every write path stores E.164, or SMS matching silently misses this guest'
        );
        Assert::same('(845) 555-0199', $row['phone'], 'and keeps what was typed');
        Assert::same(
            strtolower('NADIA.' . $surname . '@example.test'),
            $row['email'],
            'addresses are stored lowercased so two spellings are one guest'
        );
        Assert::same('10918', $row['postal_code'], 'a ZIP is text, not a number to be bounded');
        Assert::same('prospect', $row['lifecycle_stage']);

        Assert::true(
            contacts_audit_exists('contact.created', $id),
            'creating a guest record must leave an audit row naming who did it'
        );

        $page = $owner->get('/contacts/' . $id);
        Assert::same(200, $page['status']);
        Assert::contains($surname, $page['body']);
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('an invalid email address is refused and nothing is written', function () use ($owner): void {
    $surname = contacts_tag('Bademail');

    $owner->postWithToken('/contacts/new', '/contacts', [
        'first_name'      => 'Broken',
        'last_name'       => $surname,
        'email'           => 'not-an-email',
        'lifecycle_stage' => 'lead',
    ]);

    Assert::false(
        DB::exists('SELECT id FROM {contacts} WHERE last_name = :n', ['n' => $surname]),
        'a contact with an unusable address should not be stored at all'
    );
});

Harness::test('a contact with no name and no way to reach them is refused', function () use ($owner): void {
    // Otherwise the form writes a row that is nothing but a lifecycle stage:
    // unsearchable, un-mergeable, and impossible to recognise as the same
    // guest next time.
    $marker = contacts_tag('identity-guard-');

    $owner->postWithToken('/contacts/new', '/contacts', [
        'lifecycle_stage' => 'lead',
        'source'          => $marker,
    ]);

    Assert::false(
        DB::exists('SELECT id FROM {contacts} WHERE source = :s', ['s' => $marker]),
        'a contact needs a name, an address, a phone or a card number'
    );
});

Harness::test('a phone number too short to dial is refused', function () use ($owner): void {
    $surname = contacts_tag('Badphone');

    $owner->postWithToken('/contacts/new', '/contacts', [
        'last_name'       => $surname,
        'phone'           => '555',
        'lifecycle_stage' => 'lead',
    ]);

    Assert::false(
        DB::exists('SELECT id FROM {contacts} WHERE last_name = :n', ['n' => $surname]),
        '"555" normalises to nothing, so storing it would lose the number silently'
    );
});

Harness::test('editing a contact updates it and is audited', function () use ($owner): void {
    $before = contacts_tag('Beforeedit');
    $after  = contacts_tag('Afteredit');

    $id = test_make_contact(['first_name' => 'Rosa', 'last_name' => $before]);

    try {
        $response = $owner->postWithToken('/contacts/' . $id . '/edit', '/contacts/' . $id, [
            'first_name'      => 'Rosa',
            'last_name'       => $after,
            'lifecycle_stage' => 'customer',
        ]);

        Assert::same(302, $response['status']);

        $row = contacts_row($id);

        Assert::same($after, $row['last_name']);
        Assert::same('customer', $row['lifecycle_stage']);
        Assert::true(contacts_audit_exists('contact.updated', $id), 'the edit should be audited');
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

// ------------------------------------------------------- permission gates

Harness::test('a role with view_contacts but not contacts_manage cannot create', function () use ($viewer): void {
    $list = $viewer->get('/contacts');

    Assert::same(200, $list['status'], 'reading the list is what view_contacts is for');
    Assert::notContains(
        'Add a contact',
        $list['body'],
        'a button whose POST will be refused should not be offered'
    );

    Assert::same(403, $viewer->get('/contacts/new')['status']);

    $marker = contacts_tag('viewer-write-');

    $post = $viewer->postWithToken('/contacts', '/contacts', [
        'last_name'       => contacts_tag('Viewerwrite'),
        'lifecycle_stage' => 'lead',
        'source'          => $marker,
    ]);

    Assert::same(403, $post['status'], 'and the endpoint must refuse it regardless of the UI');
    Assert::false(
        DB::exists('SELECT id FROM {contacts} WHERE source = :s', ['s' => $marker]),
        'nothing may be written by a role that cannot manage contacts'
    );
});

Harness::test('the front desk can edit a contact but cannot delete one', function () use ($desk): void {
    // The split that matters: front_desk holds contacts_manage and not
    // contacts_delete, so it has to be able to fix a surname and unable to
    // remove the guest.
    $surname = contacts_tag('Deskcheck');
    $id = test_make_contact(['first_name' => 'Owen', 'last_name' => $surname]);

    try {
        $edit = $desk->postWithToken('/contacts/' . $id . '/edit', '/contacts/' . $id, [
            'first_name'      => 'Owen',
            'last_name'       => $surname,
            'city'            => 'Chester',
            'lifecycle_stage' => 'customer',
        ]);

        Assert::same(302, $edit['status']);
        Assert::same('Chester', contacts_row($id)['city'], 'the desk should be able to correct a record');

        $delete = $desk->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/delete', []);

        Assert::same(403, $delete['status'], 'deleting a guest is a separate permission');
        Assert::null(
            contacts_row($id)['deleted_at'],
            'the contact must still be there after a refused delete'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('the owner can delete a contact, and the record is kept hidden', function () use ($owner): void {
    $surname = contacts_tag('Deleted');
    $id = test_make_contact(['first_name' => 'Gone', 'last_name' => $surname]);

    try {
        $response = $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/delete', []);

        Assert::same(302, $response['status']);

        $row = contacts_row($id);

        Assert::notNull(
            $row['deleted_at'],
            'a soft delete: visits, bookings and the consent trail reference this row'
        );

        Assert::same(404, $owner->get('/contacts/' . $id)['status'], 'and the page is gone');

        // Asserting the empty state rather than the absence of the surname:
        // the search box echoes the term back into its own value attribute, so
        // notContains($surname) would fail on a working filter and pass on a
        // broken one for the wrong reason.
        Assert::contains(
            'No contact matches that',
            $owner->get('/contacts?q=' . urlencode($surname))['body'],
            'and it is out of the list'
        );
        Assert::true(contacts_audit_exists('contact.deleted', $id));
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('money is scrubbed for a role without view_revenue', function () use ($owner, $desk): void {
    $surname = contacts_tag('Moneycheck');
    $id = test_make_contact([
        'last_name'            => $surname,
        'lifetime_value_cents' => 123456,
        'avg_spend_cents'      => 4115,
        'visit_count'          => 30,
    ]);

    try {
        $ownerPage = $owner->get('/contacts/' . $id);

        Assert::same(200, $ownerPage['status']);
        Assert::contains('1,234.56', $ownerPage['body'], 'an owner sees lifetime value');

        $deskPage = $desk->get('/contacts/' . $id);

        Assert::same(200, $deskPage['status'], 'the desk can still open the guest');
        Assert::contains($surname, $deskPage['body']);
        Assert::notContains(
            '1,234.56',
            $deskPage['body'],
            'front desk staff take a booking without seeing what the guest is worth'
        );
        Assert::notContains('41.15', $deskPage['body'], 'nor the average spend');
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('an own-scoped role cannot reach another owner\'s contact', function () use ($rep, $repAccount, $ownerId): void {
    $mine     = contacts_tag('Repsown');
    $somebody = contacts_tag('Repsnot');

    $ownContact   = test_make_contact(['last_name' => $mine, 'owner_id' => $repAccount['id']]);
    $otherContact = test_make_contact(['last_name' => $somebody, 'owner_id' => $ownerId]);

    try {
        $list = $rep->get('/contacts');

        Assert::same(200, $list['status']);
        Assert::contains($mine, $list['body'], 'their own guest is theirs to see');
        Assert::notContains($somebody, $list['body'], 'another rep\'s guest is not');

        Assert::same(
            200,
            $rep->get('/contacts/' . $ownContact)['status'],
            'the record page opens for their own guest'
        );

        // The half that is easy to forget: filtering the list and leaving the
        // record page open means the scope is decorative.
        Assert::same(
            404,
            $rep->get('/contacts/' . $otherContact)['status'],
            'an id typed into the URL must not bypass the scope'
        );

        Assert::same(
            404,
            $rep->get('/contacts/' . $otherContact . '/edit')['status'],
            'nor should the edit form open'
        );
    } finally {
        DB::delete('contacts', 'id IN (:a, :b)', ['a' => $ownContact, 'b' => $otherContact]);
    }
});

// ----------------------------------------------------------------- notes

Harness::test('a note is recorded against the contact and shown on the page', function () use ($owner): void {
    $id   = test_make_contact(['last_name' => contacts_tag('Noted')]);
    $body = 'Asked for the quiet party room — ' . contacts_tag('note');

    try {
        $response = $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/notes', [
            'body' => $body,
        ]);

        Assert::same(302, $response['status']);

        $note = DB::first(
            'SELECT * FROM {contact_notes} WHERE contact_id = :id ORDER BY id DESC',
            ['id' => $id]
        );

        Assert::notNull($note, 'the note should be stored');
        Assert::same($body, $note['body']);
        Assert::notNull($note['user_id'], 'and attributed to whoever wrote it');

        Assert::contains(
            'Asked for the quiet party room',
            $owner->get('/contacts/' . $id)['body'],
            'and appear on the record'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('an empty note is refused', function () use ($owner): void {
    $id = test_make_contact(['last_name' => contacts_tag('Emptynote')]);

    try {
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/notes', ['body' => '']);

        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {contact_notes} WHERE contact_id = :id', ['id' => $id]),
            'an empty note is not a note'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

// --------------------------------------------------------------- consent

Harness::test('the contact form offers no consent control', function () use ($owner): void {
    $id = test_make_contact(['last_name' => contacts_tag('Formcheck')]);

    try {
        $form = $owner->get('/contacts/' . $id . '/edit')['body'];

        foreach (['name="email_consent"', 'name="sms_consent"', 'name="do_not_contact"'] as $field) {
            Assert::notContains(
                $field,
                $form,
                'consent is evidence of something the guest did, not a field on an edit form'
            );
        }
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('posting consent fields to the update endpoint does not set them', function () use ($owner): void {
    // The test that actually protects the rule above. Hiding the checkbox is
    // cosmetic if the column is still fillable from a crafted POST.
    $surname = contacts_tag('Sneakyconsent');
    $id = test_make_contact([
        'last_name' => $surname,
        'email'     => 'sneaky-' . $surname . '@example.test',
    ]);

    try {
        $owner->postWithToken('/contacts/' . $id . '/edit', '/contacts/' . $id, [
            'last_name'       => $surname,
            'email'           => 'sneaky-' . $surname . '@example.test',
            'lifecycle_stage' => 'customer',
            'email_consent'   => '1',
            'sms_consent'     => '1',
            'do_not_contact'  => '1',
        ]);

        $row = contacts_row($id);

        Assert::same('customer', $row['lifecycle_stage'], 'the legitimate part of the edit applied');
        Assert::same(0, (int) $row['email_consent'], 'email consent must not be settable here');
        Assert::same(0, (int) $row['sms_consent'], 'nor SMS consent');
        Assert::same(0, (int) $row['do_not_contact'], 'nor the block');
        Assert::null($row['email_consent_at']);

        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id', ['id' => $id]),
            'and no consent event was invented'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('recording consent writes the flag, the time and an append-only log row', function () use ($owner): void {
    $surname = contacts_tag('Consented');
    $id = test_make_contact([
        'last_name' => $surname,
        'email'     => 'consent-' . $surname . '@example.test',
    ]);

    try {
        Assert::contains('No consent', $owner->get('/contacts/' . $id)['body'], 'it starts with none');

        $response = $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel'  => 'email',
            'action'   => 'granted',
            'source'   => 'Party booking form',
            'evidence' => 'Tick to hear about parties and offers at the Castle Fun Center.',
        ]);

        Assert::same(302, $response['status']);

        $row = contacts_row($id);

        Assert::same(1, (int) $row['email_consent']);
        Assert::notNull($row['email_consent_at'], 'when matters as much as whether');

        $log = DB::first(
            'SELECT * FROM {consent_log} WHERE contact_id = :id ORDER BY id DESC',
            ['id' => $id]
        );

        Assert::notNull($log, 'the consent_log row IS the proof; the flag is only a cache of it');
        Assert::same('email', $log['channel']);
        Assert::same('granted', $log['action']);
        Assert::same('Party booking form', $log['source']);
        Assert::contains(
            'hear about parties',
            (string) $log['evidence'],
            'the disclosure wording is what compliance turns on'
        );
        Assert::notNull($log['ip'], 'with the address it came from');

        $page = $owner->get('/contacts/' . $id)['body'];
        Assert::contains('Consent on record', $page, 'and the page says so in words');
        Assert::contains('Party booking form', $page, 'naming the source');
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('consent with no source is refused', function () use ($owner): void {
    $surname = contacts_tag('Nosource');
    $id = test_make_contact([
        'last_name' => $surname,
        'email'     => 'nosource-' . $surname . '@example.test',
    ]);

    try {
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel' => 'email',
            'action'  => 'granted',
            'source'  => '',
        ]);

        Assert::same(
            0,
            (int) contacts_row($id)['email_consent'],
            'consent with no provenance is exactly the thing that is not a defence'
        );
        Assert::same(
            0,
            (int) DB::value('SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id', ['id' => $id])
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('consent cannot be recorded for a channel with no address', function () use ($owner): void {
    // A record with no email that nonetheless says "may be emailed" reads as a
    // marketable guest and can never be acted on.
    $id = test_make_contact([
        'last_name'        => contacts_tag('Noaddress'),
        'email'            => null,
        'phone_normalized' => null,
    ]);

    try {
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel' => 'email',
            'action'  => 'granted',
            'source'  => 'Asked at the desk',
        ]);

        Assert::same(0, (int) contacts_row($id)['email_consent'], 'nothing to consent for');

        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel' => 'sms',
            'action'  => 'granted',
            'source'  => 'Asked at the desk',
        ]);

        Assert::same(0, (int) contacts_row($id)['sms_consent'], 'and no number to text');
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('withdrawing consent clears the flag and timestamps the opt-out', function () use ($owner): void {
    $surname = contacts_tag('Withdrawn');
    $id = test_make_contact([
        'last_name'        => $surname,
        'phone_normalized' => '+18455557711',
        'phone'            => '845-555-7711',
        'sms_consent'      => 1,
        'sms_consent_at'   => now_utc(),
    ]);

    try {
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel' => 'sms',
            'action'  => 'revoked',
            'source'  => 'Replied STOP',
        ]);

        $row = contacts_row($id);

        Assert::same(0, (int) $row['sms_consent']);
        Assert::null($row['sms_consent_at']);
        Assert::notNull($row['marketing_opt_out_at'], 'the date they withdrew is itself evidence');

        Assert::true(
            DB::exists(
                'SELECT id FROM {consent_log} WHERE contact_id = :id AND action = :a',
                ['id' => $id, 'a' => 'revoked']
            ),
            'a withdrawal is logged exactly like a grant'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('do-not-contact clears both consents, and lifting it does not restore them', function () use ($owner): void {
    $surname = contacts_tag('Blocked');
    $id = test_make_contact([
        'last_name'        => $surname,
        'email'            => 'blocked-' . $surname . '@example.test',
        'phone_normalized' => '+18455556622',
        'email_consent'    => 1,
        'email_consent_at' => now_utc(),
        'sms_consent'      => 1,
        'sms_consent_at'   => now_utc(),
    ]);

    try {
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/do-not-contact', [
            'state'  => 'on',
            'source' => 'Phone call to the office',
        ]);

        $blocked = contacts_row($id);

        Assert::same(1, (int) $blocked['do_not_contact']);
        Assert::same(0, (int) $blocked['email_consent'], 'a block that leaves consent set is not a block');
        Assert::same(0, (int) $blocked['sms_consent']);

        Assert::same(
            2,
            (int) DB::value(
                'SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id AND action = :a',
                ['id' => $id, 'a' => 'revoked']
            ),
            'one withdrawal row per channel that actually held consent'
        );

        Assert::contains('Do not contact', $owner->get('/contacts/' . $id)['body']);

        // Lifting the block is not the same as the guest opting back in.
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/do-not-contact', [
            'state'  => 'off',
            'source' => 'Guest asked at the desk',
        ]);

        $lifted = contacts_row($id);

        Assert::same(0, (int) $lifted['do_not_contact'], 'the block is lifted');
        Assert::same(
            0,
            (int) $lifted['email_consent'],
            'but consent has to be given again — restoring it would market to somebody who said stop'
        );
        Assert::same(0, (int) $lifted['sms_consent']);
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('consent cannot be granted while do-not-contact is set', function () use ($owner): void {
    $surname = contacts_tag('Stillblocked');
    $id = test_make_contact([
        'last_name'      => $surname,
        'email'          => 'still-' . $surname . '@example.test',
        'do_not_contact' => 1,
    ]);

    try {
        $owner->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel' => 'email',
            'action'  => 'granted',
            'source'  => 'Party booking form',
        ]);

        Assert::same(
            0,
            (int) contacts_row($id)['email_consent'],
            'the hard block overrides every consent flag, including a new one'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

Harness::test('a role without contacts_manage cannot record consent', function () use ($viewer): void {
    $surname = contacts_tag('Viewerconsent');
    $id = test_make_contact([
        'last_name' => $surname,
        'email'     => 'viewer-' . $surname . '@example.test',
    ]);

    try {
        $response = $viewer->postWithToken('/contacts/' . $id, '/contacts/' . $id . '/consent', [
            'channel' => 'email',
            'action'  => 'granted',
            'source'  => 'Said so, honestly',
        ]);

        Assert::same(403, $response['status']);
        Assert::same(0, (int) contacts_row($id)['email_consent']);
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $id]);
    }
});

// ------------------------------------------------------------ households

Harness::test('creating a household works and it appears in the list', function () use ($owner): void {
    $name = 'The ' . contacts_tag('Household') . ' family';

    $response = $owner->postWithToken('/households/new', '/households', [
        'name'        => $name,
        'address_1'   => '12 Jenkins Road',
        'city'        => 'Chester',
        'state'       => 'NY',
        'postal_code' => '10918',
    ]);

    $id = contacts_redirect_id($response['location']);

    try {
        $row = DB::first('SELECT * FROM {households} WHERE id = :id', ['id' => $id]);

        Assert::notNull($row);
        Assert::same($name, $row['name']);
        Assert::same('10918', $row['postal_code']);
        Assert::true(contacts_audit_exists('household.created', $id));

        Assert::contains($name, $owner->get('/households')['body'], 'and it is listed');
        Assert::contains('12 Jenkins Road', $owner->get('/households/' . $id)['body']);
    } finally {
        DB::delete('households', 'id = :id', ['id' => $id]);
    }
});

Harness::test('a household with no name is refused, as a form error not a crash', function () use ($owner): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {households}');

    $empty = $owner->postWithToken('/households/new', '/households', ['name' => '']);

    Assert::same(302, $empty['status'], 'a form problem comes back to the form');
    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {households}'),
        'an unnamed household cannot be recognised by staff'
    );

    // A name that is only spaces, which `required|min:2` lets through: the
    // validator measures length before trimming, so "   " passes it and then
    // trims away to nothing.
    //
    // The STATUS is the assertion that earns its keep here. Both the guard and
    // no guard at all leave the table unchanged — without one, the NOT NULL
    // column rejects the insert and the request dies as a 500. MEASURED:
    // removing the guard keeps the row count assertion green, so on its own it
    // proves nothing. A refusal a person can read is the actual requirement.
    $blank = $owner->postWithToken('/households/new', '/households', ['name' => '   ']);

    Assert::same(
        302,
        $blank['status'],
        'whitespace should be refused with a message, not a database error'
    );
    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {households}'),
        'nor should whitespace become a household called something generic'
    );
});

Harness::test('a contact can be added to a household and removed again', function () use ($owner): void {
    $name    = 'The ' . contacts_tag('Members') . ' family';
    $surname = contacts_tag('Memberone');

    $householdId = DB::insert('households', [
        'name'       => $name,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $contactId = test_make_contact(['first_name' => 'Pilar', 'last_name' => $surname]);

    try {
        $added = $owner->postWithToken(
            '/households/' . $householdId,
            '/households/' . $householdId . '/members',
            ['contact_id' => (string) $contactId]
        );

        Assert::same(302, $added['status']);
        Assert::same(
            $householdId,
            (int) contacts_row($contactId)['household_id'],
            'membership is the household_id on the contact'
        );

        $page = $owner->get('/households/' . $householdId)['body'];
        Assert::contains($surname, $page, 'and the member is listed');
        Assert::contains('1 person', $page, 'and counted');

        // The contact's own page should name the household it is now in.
        Assert::contains($name, $owner->get('/contacts/' . $contactId)['body']);

        $removed = $owner->postWithToken(
            '/households/' . $householdId,
            '/households/' . $householdId . '/members/remove',
            ['contact_id' => (string) $contactId]
        );

        Assert::same(302, $removed['status']);
        Assert::null(
            contacts_row($contactId)['household_id'],
            'removing a member unlinks them and leaves the contact itself alone'
        );
        Assert::null(contacts_row($contactId)['deleted_at'], 'the contact is not deleted');
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});

Harness::test('removing the main contact clears the household\'s main contact', function () use ($owner): void {
    // Otherwise the household keeps naming somebody who is no longer in it,
    // and a party confirmation is addressed to them.
    $householdId = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Primary') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $contactId = test_make_contact([
        'last_name'    => contacts_tag('Primaryone'),
        'household_id' => $householdId,
    ]);

    try {
        $set = $owner->postWithToken('/households/' . $householdId . '/edit', '/households/' . $householdId, [
            'name'               => 'The ' . contacts_tag('Primaryedit') . ' family',
            'primary_contact_id' => (string) $contactId,
        ]);

        Assert::same(302, $set['status']);
        Assert::same(
            $contactId,
            (int) DB::value('SELECT primary_contact_id FROM {households} WHERE id = :id', ['id' => $householdId]),
            'a member can be made the main contact'
        );

        $owner->postWithToken(
            '/households/' . $householdId,
            '/households/' . $householdId . '/members/remove',
            ['contact_id' => (string) $contactId]
        );

        Assert::null(
            DB::value('SELECT primary_contact_id FROM {households} WHERE id = :id', ['id' => $householdId]),
            'the main contact must not survive leaving the household'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});

Harness::test('moving a contact out through the contact form clears the main contact too', function () use ($owner): void {
    // The same event as removing them on the household page, reached from the
    // other side. If only one path clears it, the household goes on naming a
    // main contact who has moved out, and a party confirmation is addressed to
    // them.
    $householdId = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Movedout') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $surname   = contacts_tag('Movedoutone');
    $contactId = test_make_contact(['last_name' => $surname, 'household_id' => $householdId]);

    DB::update(
        'households',
        ['primary_contact_id' => $contactId],
        'id = :id',
        ['id' => $householdId]
    );

    try {
        $owner->postWithToken('/contacts/' . $contactId . '/edit', '/contacts/' . $contactId, [
            'last_name'       => $surname,
            'lifecycle_stage' => 'customer',
            'household_id'    => '',
        ]);

        Assert::null(contacts_row($contactId)['household_id'], 'they left the household');
        Assert::null(
            DB::value('SELECT primary_contact_id FROM {households} WHERE id = :id', ['id' => $householdId]),
            'so the household must stop naming them as its main contact'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});

Harness::test('joining another household clears the old one\'s main contact', function () use ($owner): void {
    // The third path that changes household_id, after the household page's
    // remove and the contact form's household field. All three have to clear a
    // main contact who moves out, or one of them leaves a household addressing
    // somebody who left.
    $from = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Fromhouse') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $to = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Tohouse') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $contactId = test_make_contact([
        'last_name'    => contacts_tag('Mover'),
        'household_id' => $from,
    ]);

    DB::update('households', ['primary_contact_id' => $contactId], 'id = :id', ['id' => $from]);

    try {
        $owner->postWithToken('/households/' . $to, '/households/' . $to . '/members', [
            'contact_id' => (string) $contactId,
        ]);

        Assert::same($to, (int) contacts_row($contactId)['household_id'], 'they joined the new household');
        Assert::null(
            DB::value('SELECT primary_contact_id FROM {households} WHERE id = :id', ['id' => $from]),
            'and the household they left stops naming them'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
        DB::delete('households', 'id IN (:a, :b)', ['a' => $from, 'b' => $to]);
    }
});

Harness::test('the main contact has to be a member of the household', function () use ($owner): void {
    $householdId = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Outsider') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $outsider = test_make_contact(['last_name' => contacts_tag('Outsiderone')]);

    try {
        $owner->postWithToken('/households/' . $householdId . '/edit', '/households/' . $householdId, [
            'name'               => 'The ' . contacts_tag('Outsider2') . ' family',
            'primary_contact_id' => (string) $outsider,
        ]);

        Assert::null(
            DB::value('SELECT primary_contact_id FROM {households} WHERE id = :id', ['id' => $householdId]),
            'a main contact who is not a member would render as a member who is not listed'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $outsider]);
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});

Harness::test('removing somebody who is not in the household changes nothing', function () use ($owner): void {
    $theirs = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Theirs') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $other = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Other') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $contactId = test_make_contact([
        'last_name'    => contacts_tag('Elsewhere'),
        'household_id' => $theirs,
    ]);

    try {
        $owner->postWithToken(
            '/households/' . $other,
            '/households/' . $other . '/members/remove',
            ['contact_id' => (string) $contactId]
        );

        Assert::same(
            $theirs,
            (int) contacts_row($contactId)['household_id'],
            'a remove aimed at the wrong household must not unlink the guest'
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
        DB::delete('households', 'id IN (:a, :b)', ['a' => $theirs, 'b' => $other]);
    }
});

Harness::test('a household shows only the members the viewer may see', function () use ($owner, $rep, $repAccount, $ownerId): void {
    // A household is not owner-scoped, but its members are. The count and the
    // list have to agree about that, or the page says "2 people" above one row.
    $householdId = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Mixed') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $theirs = contacts_tag('Mixedtheirs');
    $mine   = contacts_tag('Mixedmine');

    $ownersContact = test_make_contact([
        'last_name'    => $theirs,
        'owner_id'     => $ownerId,
        'household_id' => $householdId,
    ]);
    $repsContact = test_make_contact([
        'last_name'    => $mine,
        'owner_id'     => $repAccount['id'],
        'household_id' => $householdId,
    ]);

    try {
        $ownerPage = $owner->get('/households/' . $householdId)['body'];

        Assert::contains('2 people', $ownerPage, 'the owner sees both');
        Assert::contains($theirs, $ownerPage);
        Assert::contains($mine, $ownerPage);

        $repPage = $rep->get('/households/' . $householdId)['body'];

        Assert::contains('1 person', $repPage, 'and the count matches what is listed');
        Assert::contains($mine, $repPage, 'their own guest');
        Assert::notContains($theirs, $repPage, 'not the other rep\'s');
    } finally {
        DB::delete('contacts', 'id IN (:a, :b)', ['a' => $ownersContact, 'b' => $repsContact]);
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});

Harness::test('a role without contacts_manage cannot change household membership', function () use ($viewer): void {
    $householdId = DB::insert('households', [
        'name'       => 'The ' . contacts_tag('Viewerhousehold') . ' family',
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);
    $contactId = test_make_contact(['last_name' => contacts_tag('Viewermember')]);

    try {
        Assert::same(200, $viewer->get('/households/' . $householdId)['status'], 'but can look');

        $response = $viewer->postWithToken(
            '/households/' . $householdId,
            '/households/' . $householdId . '/members',
            ['contact_id' => (string) $contactId]
        );

        Assert::same(403, $response['status']);
        Assert::null(contacts_row($contactId)['household_id']);
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
        DB::delete('households', 'id = :id', ['id' => $householdId]);
    }
});
