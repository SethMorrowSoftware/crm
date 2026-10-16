<?php

declare(strict_types=1);

/**
 * The segment pages, end to end over HTTP.
 *
 * The engine's own correctness is pinned by `tests/unit/SegmentEngineTest.php`
 * and `tests/integration/SegmentTest.php`. What this file tests is the
 * decisions the CONTROLLER makes, and the two that matter are both about which
 * way the feature fails:
 *
 *   1. **A SEGMENT THAT WILL NOT RUN IS NEVER SAVED.** Storing it and finding
 *      out at send time puts the discovery at the worst possible moment.
 *   2. **CHANGING THE RULES CLEARS THE COUNT.** The old figure answered a
 *      different question, and a number beside new rules is read as being
 *      about them.
 *
 * Plus the gate: looking is `view_marketing`, building is `campaigns_manage`,
 * and neither is permission to CONTACT anybody — that is `campaigns_send`,
 * checked where the sending happens.
 */

use App\Core\DB;
use App\Services\SegmentEngine;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/segments');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('segments http suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('segments http suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/** A rule row as the editor posts it. */
function sh_rule(string $field, string $op, string $value = '', string $value2 = '', string $group = ''): array
{
    return ['field' => $field, 'op' => $op, 'value' => $value, 'value2' => $value2, 'group' => $group];
}

/**
 * @param list<array<string,string>> $rules
 * @return array<string,string>
 */
function sh_post(string $name, array $rules, string $match = 'all'): array
{
    $fields = ['name' => $name, 'match' => $match, 'description' => 'Written by the segment suite.'];

    foreach ($rules as $index => $rule) {
        foreach ($rule as $key => $value) {
            $fields['rules[' . $index . '][' . $key . ']'] = $value;
        }
    }

    return $fields;
}

/** @return array<string,mixed>|null */
function sh_row(string $name): ?array
{
    return DB::first('SELECT * FROM {segments} WHERE name = :n', ['n' => $name]);
}

function sh_name(string $hint): string
{
    return 'SegHttp ' . $hint . ' ' . bin2hex(random_bytes(3));
}

function sh_clear(): void
{
    DB::query("DELETE FROM {campaigns} WHERE name LIKE 'SegHttp%'");
    DB::query("DELETE FROM {segments} WHERE name LIKE 'SegHttp%'");
    DB::query("DELETE FROM {contacts} WHERE source = 'seghttp'");
}

sh_clear();

$owner = new TestBrowser($baseUrl, 'segments-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds no marketing permission at all in the seeds.
$desk = new TestBrowser($baseUrl, 'segments-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

$lookerPassword = 'segments-looker-passphrase-9';
$lookerId       = test_make_user('marketing', [
    'email'         => 'segments-looker@example.test',
    'name'          => 'Segments Looker',
    'password_hash' => App\Core\Auth::hash($lookerPassword),
]);

$looker = new TestBrowser($baseUrl, 'segments-looker');
$looker->login('segments-looker@example.test', $lookerPassword);

Harness::test('the fixtures signed in', function () use ($owner, $desk, $looker): void {
    foreach (['owner' => $owner, 'desk' => $desk, 'looker' => $looker] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }
});

// --------------------------------------------------------------------- gates

Harness::test('a role with no view_marketing cannot see segments', function () use ($desk): void {
    Assert::same(403, $desk->get('/segments')['status']);
    Assert::same(403, $desk->get('/segments/new')['status']);
});

Harness::test('marketing can both look and build', function () use ($looker): void {
    // The positive control for the gates. Marketing holds view_marketing AND
    // campaigns_manage in the seeds, so if either were refused here the
    // refusal tests above would be proving nothing.
    Assert::same(200, $looker->get('/segments')['status']);
    Assert::same(200, $looker->get('/segments/new')['status']);
});

Harness::test('A ROLE THAT MAY LOOK AND NOT BUILD CAN DO NEITHER MORE NOR LESS', function () use ($baseUrl, $owner): void {
    // No SEEDED role has this shape — Marketing holds both permissions and
    // Front Desk holds neither — so without building one, the split between
    // `view_marketing` and `campaigns_manage` is never actually exercised:
    // every test passes whichever of the two gates is removed. MEASURED by
    // mutation, which is how this gap was found.
    //
    // Roles are DATA in this application, editable in Settings, so a venue can
    // legitimately make somebody who reads the marketing reports and builds
    // nothing.
    $roleId = (int) DB::insert('roles', [
        'slug'        => 'segments-viewer-test',
        'name'        => 'Segment viewer (test)',
        'description' => 'Sees segments, builds none.',
        'is_system'   => 0,
        'sort_order'  => 901,
        'created_at'  => now_utc(),
        'updated_at'  => now_utc(),
    ]);

    $viewMarketing = (int) DB::value("SELECT id FROM {permissions} WHERE slug = 'view_marketing'");

    Assert::greaterThan(0, $viewMarketing, 'precondition: view_marketing exists');

    DB::insert('role_permissions', [
        'role_id'       => $roleId,
        'permission_id' => $viewMarketing,
        'scope'         => 'all',
    ]);

    $viewerId = test_make_user('segments-viewer-test', [
        'email'         => 'segments-viewer-only@example.test',
        'name'          => 'Segment Viewer Only',
        'password_hash' => App\Core\Auth::hash('segments-viewonly-passphrase-9'),
    ]);

    $name = sh_name('viewer');

    try {
        $viewer = new TestBrowser($baseUrl, 'segments-viewonly');
        $viewer->login('segments-viewer-only@example.test', 'segments-viewonly-passphrase-9');

        Assert::same(200, $viewer->get('/account')['status'], 'precondition: they are signed in');

        // The owner builds something for them to look at.
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
        ]));

        $id = (int) sh_row($name)['id'];

        // LOOKING: allowed, which is the precondition the seeded roles cannot
        // give us.
        Assert::same(200, $viewer->get('/segments')['status'], 'they can see the list');
        Assert::same(200, $viewer->get('/segments/' . $id)['status'], 'and a segment');
        Assert::contains($name, $viewer->get('/segments')['body'], 'with the segment on it');

        // BUILDING: refused, every way in.
        Assert::same(403, $viewer->get('/segments/new')['status'], 'no new-segment form');
        Assert::same(403, $viewer->get('/segments/' . $id . '/edit')['status'], 'no edit form');

        foreach ([
            ['/segments', sh_post(sh_name('sneaked'), [sh_rule('email_consent', 'is_true')])],
            ['/segments/' . $id, sh_post($name . ' changed', [sh_rule('sms_consent', 'is_true')])],
            ['/segments/' . $id . '/refresh', []],
            ['/segments/' . $id . '/delete', []],
        ] as [$path, $fields]) {
            Assert::same(
                403,
                $viewer->postWithToken('/segments', $path, $fields)['status'],
                $path . ' is refused'
            );
        }

        // Nothing moved.
        $row = sh_row($name);

        Assert::notNull($row, 'the segment is still there, unchanged');
        /** @var array<string,mixed> $row */
        Assert::null($row['cached_count'], 'and still uncounted');
        Assert::contains('email_consent', (string) $row['rules'], 'with its original rule');
        Assert::null(DB::first("SELECT id FROM {segments} WHERE name LIKE 'SegHttp sneaked%'"));
    } finally {
        sh_clear();
        DB::delete('users', 'id = :id', ['id' => $viewerId]);
        DB::delete('role_permissions', 'role_id = :id', ['id' => $roleId]);
        DB::delete('roles', 'id = :id', ['id' => $roleId]);
    }
});

// ------------------------------------------------------------------- saving

Harness::test('A SEGMENT WITH A NESTED OR GROUP SAVES AND READS BACK', function () use ($owner): void {
    // The shape a venue actually builds: consent and spend as hard
    // conditions, then a set of alternatives.
    $name = sh_name('july');

    try {
        $response = $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
            sh_rule('lifetime_value_cents', 'gte', '100'),
            sh_rule('child_birth_month', 'is', '7', '', '1'),
            sh_rule('has_booking', 'in_last_days', '365', '', '1'),
        ]));

        Assert::same(302, $response['status']);

        $row = sh_row($name);

        Assert::notNull($row, 'it is stored');
        /** @var array<string,mixed> $row */

        $rules = json_decode((string) $row['rules'], true);

        Assert::true(is_array($rules), 'the rules are readable JSON');
        Assert::same('all', $rules['match'], 'the top level is an AND');
        Assert::count(3, $rules['rules'], 'two plain rules and one group');

        // The group is the third entry and is an `any`.
        $group = $rules['rules'][2];

        Assert::same('any', $group['match'] ?? '', 'the grouped rows became an OR');
        Assert::count(2, $group['rules'] ?? [], 'with both rows in it');

        // And it compiles, which is the claim that matters.
        Assert::true(SegmentEngine::validate($rules)['ok']);

        // The page reads it back in English, with the group as an "or".
        $page = $owner->get('/segments/' . (int) $row['id']);

        Assert::same(200, $page['status']);
        Assert::contains('Said yes to email is yes', $page['body']);
        Assert::contains('July', $page['body'], 'the month is named, not numbered');
        Assert::contains(' or ', $page['body']);
    } finally {
        sh_clear();
    }
});

Harness::test('A CONDITION THAT CANNOT BE READ IS REFUSED, and the message names the field', function () use ($owner): void {
    // The engine throws rather than dropping it, and the controller reports
    // that. Storing it instead would move the discovery to send time.
    $name = sh_name('broken');

    try {
        // `gte` is a real operator on the wrong type of field.
        $response = $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('first_name', 'gte', 'x'),
        ]));

        Assert::same(302, $response['status'], 'it is sent back to the form');
        Assert::null(sh_row($name), 'and NOTHING is stored');

        // Following the redirect, the message says which field and which
        // operator — "invalid rule" would leave somebody guessing which of
        // six rows is wrong.
        $back = $owner->get('/segments/new');

        Assert::contains('First name', $back['body'], 'the field is named');
        Assert::contains('gte', $back['body'], 'and so is the operator');
    } finally {
        sh_clear();
    }
});

Harness::test('and an unknown FIELD is refused the same way', function () use ($owner): void {
    $name = sh_name('nofield');

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('password_hash', 'is', 'x'),
        ]));

        Assert::null(sh_row($name), 'a column nobody may filter on is not a segment');

        // The positive control: a real field with the same operator saves.
        $good = sh_name('field-ok');

        $owner->postWithToken('/segments/new', '/segments', sh_post($good, [
            sh_rule('first_name', 'is', 'Alex'),
        ]));

        Assert::notNull(sh_row($good), 'so the refusal above was the field');
    } finally {
        sh_clear();
    }
});

Harness::test('A SEGMENT WITH NO CONDITIONS IS REFUSED, not saved as "nobody"', function () use ($owner): void {
    // The engine reads an empty tree as "nobody", which is the right
    // compilation. But a FORM submitted with no rules is almost always
    // somebody who has not finished, and saving a segment that matches nobody
    // would look like the editor losing their work.
    $name = sh_name('empty');

    try {
        $response = $owner->postWithToken('/segments/new', '/segments', [
            'name'  => $name,
            'match' => 'all',
        ]);

        Assert::same(302, $response['status']);
        Assert::null(sh_row($name));

        // And a form with only BLANK rows is the same thing.
        $blank = sh_name('blank-rows');

        $owner->postWithToken('/segments/new', '/segments', sh_post($blank, [
            sh_rule('', ''),
            sh_rule('', ''),
        ]));

        Assert::null(sh_row($blank), 'blank slots are not conditions');
    } finally {
        sh_clear();
    }
});

Harness::test('a nameless segment is refused', function () use ($owner): void {
    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post('', [
            sh_rule('email_consent', 'is_true'),
        ]));

        Assert::same(
            0,
            (int) DB::value("SELECT COUNT(*) FROM {segments} WHERE name = ''"),
            'nothing nameless is stored'
        );
    } finally {
        sh_clear();
    }
});

Harness::test('two segments with the same name get different slugs', function () use ($owner): void {
    // The slug is unique in the schema, so a second segment called "Lapsed
    // members" must not fail with a database error.
    $name = sh_name('dup');

    try {
        foreach ([1, 2] as $attempt) {
            $response = $owner->postWithToken('/segments/new', '/segments', sh_post($name . ' ' . $attempt, [
                sh_rule('email_consent', 'is_true'),
            ]));

            Assert::same(302, $response['status'], 'save ' . $attempt . ' succeeds');
        }

        // Same NAME this time, which is what exercises the slug.
        $owner->postWithToken('/segments/new', '/segments', sh_post($name . ' 1', [
            sh_rule('sms_consent', 'is_true'),
        ]));

        $slugs = DB::all(
            "SELECT slug FROM {segments} WHERE name LIKE :n ORDER BY id",
            ['n' => $name . '%']
        );

        $values = array_map(static fn (array $row): string => (string) $row['slug'], $slugs);

        Assert::same(count($values), count(array_unique($values)), 'every slug is distinct');
        Assert::greaterThan(2, count($values), 'and all three saved');
    } finally {
        sh_clear();
    }
});

// ------------------------------------------------------------------ counting

Harness::test('A NEW SEGMENT IS "NOT COUNTED", never zero', function () use ($owner): void {
    // Zero reads as "nobody matches". Not counted reads as "nobody has
    // asked", which is the truth — and the difference decides whether
    // somebody goes looking for a bug.
    $name = sh_name('uncounted');

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
        ]));

        $row = sh_row($name);

        Assert::notNull($row);
        /** @var array<string,mixed> $row */
        Assert::null($row['cached_count'], 'no count is invented');
        Assert::null($row['cached_at']);

        $page = $owner->get('/segments/' . (int) $row['id']);

        Assert::contains('Not counted', $page['body']);
    } finally {
        sh_clear();
    }
});

Harness::test('counting records the figure AND when it was taken', function () use ($owner): void {
    $name = sh_name('count');

    // Two guests of this suite's own, one matching.
    $yes = DB::insert('contacts', [
        'first_name'      => 'SegHttpYes',
        'email_consent'   => 1,
        'source'          => 'seghttp',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
    DB::insert('contacts', [
        'first_name'      => 'SegHttpNo',
        'email_consent'   => 0,
        'source'          => 'seghttp',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('source', 'is', 'seghttp'),
            sh_rule('email_consent', 'is_true'),
        ]));

        $id = (int) sh_row($name)['id'];

        $response = $owner->postWithToken('/segments/' . $id, '/segments/' . $id . '/refresh', []);

        Assert::same(302, $response['status']);

        $row = sh_row($name);

        Assert::notNull($row);
        /** @var array<string,mixed> $row */
        Assert::same(1, (int) $row['cached_count'], 'exactly the one consented guest');
        Assert::notNull($row['cached_at'], 'with the time it was measured');

        // THE AGE IS SHOWN, so the number is never bare.
        $page = $owner->get('/segments/' . $id);

        Assert::contains('Counted', $page['body']);
    } finally {
        sh_clear();
    }
});

Harness::test('CHANGING THE RULES CLEARS THE COUNT', function () use ($owner): void {
    // The old figure answered a different question. Left in place beside the
    // new rules it would be read as being about them — and then sent against.
    $name = sh_name('recount');

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
        ]));

        $id = (int) sh_row($name)['id'];

        $owner->postWithToken('/segments/' . $id, '/segments/' . $id . '/refresh', []);

        Assert::notNull(sh_row($name)['cached_count'], 'precondition: it has a count');

        // Now change what it asks.
        $owner->postWithToken('/segments/' . $id . '/edit', '/segments/' . $id, sh_post($name, [
            sh_rule('sms_consent', 'is_true'),
        ]));

        $row = sh_row($name);

        Assert::notNull($row);
        /** @var array<string,mixed> $row */
        Assert::null($row['cached_count'], 'the count is cleared');
        Assert::null($row['cached_at']);
        Assert::contains('sms_consent', (string) $row['rules'], 'and the new rule is stored');
    } finally {
        sh_clear();
    }
});

Harness::test('counting a segment that will not run reports that rather than a number', function () use ($owner): void {
    // A stored segment can stop compiling — a field renamed in a later
    // version, say. The count must not come back as zero, which would read as
    // "nobody matches".
    $name = sh_name('stale-rules');

    try {
        $id = DB::insert('segments', [
            'name'       => $name,
            'slug'       => 'seghttp-stale-' . bin2hex(random_bytes(3)),
            'rules'      => json_encode([
                'match' => 'all',
                'rules' => [['field' => 'a_field_that_went_away', 'op' => 'is', 'value' => 'x']],
            ]),
            'is_dynamic' => 1,
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);

        $response = $owner->postWithToken('/segments/' . $id, '/segments/' . $id . '/refresh', []);

        Assert::same(302, $response['status'], 'it is refused');
        Assert::null(sh_row($name)['cached_count'], 'and no figure is recorded');

        // The page says so plainly rather than showing an empty sample.
        $page = $owner->get('/segments/' . $id);

        Assert::same(200, $page['status'], 'the page still opens');
        Assert::contains('will not run', $page['body']);
        Assert::contains('a_field_that_went_away', $page['body'], 'naming what broke');
        Assert::notContains('Nobody matches', $page['body'], 'and NOT as "nobody matches"');
    } finally {
        sh_clear();
    }
});

// ------------------------------------------------------------------- preview

Harness::test('the sample says it is a sample, and shows who is unreachable', function () use ($owner): void {
    // A segment selects people; it does not grant permission to contact them.
    // Seeing a consent-less guest in the sample is how somebody understands
    // why a count of 400 sends 180 messages.
    $name = sh_name('sample');

    DB::insert('contacts', [
        'first_name'      => 'SegHttpReachable',
        'email'           => 'seghttp.' . bin2hex(random_bytes(3)) . '@example.test',
        'email_consent'   => 1,
        'source'          => 'seghttp',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
    DB::insert('contacts', [
        'first_name'      => 'SegHttpSilent',
        'email'           => 'seghttp.' . bin2hex(random_bytes(3)) . '@example.test',
        'email_consent'   => 0,
        'sms_consent'     => 0,
        'source'          => 'seghttp',
        'lifecycle_stage' => 'lead',
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('source', 'is', 'seghttp'),
        ]));

        $page = $owner->get('/segments/' . (int) sh_row($name)['id']);

        Assert::contains('SegHttpReachable', $page['body']);
        Assert::contains('SegHttpSilent', $page['body'], 'both are in the segment');
        Assert::contains(
            'No marketing consent',
            $page['body'],
            'and the one nobody may write to is marked as such'
        );
        Assert::contains(
            'does not grant permission',
            $page['body'],
            'with the reason stated in words'
        );
    } finally {
        sh_clear();
    }
});

// ------------------------------------------------------------------ deleting

Harness::test('A SEGMENT A CAMPAIGN POINTS AT IS NOT DELETED', function () use ($owner): void {
    // That campaign's record of who it went to is how the venue answers "why
    // did I get this", and it would be left pointing at nothing.
    $name = sh_name('in-use');

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
        ]));

        $id = (int) sh_row($name)['id'];

        $campaignId = DB::insert('campaigns', [
            'name'       => 'SegHttp campaign',
            'channel'    => 'email',
            'status'     => 'sent',
            'segment_id' => $id,
            'created_at' => now_utc(),
        ]);

        $refused = $owner->postWithToken('/segments/' . $id, '/segments/' . $id . '/delete', []);

        Assert::same(302, $refused['status']);
        Assert::notNull(sh_row($name), 'it is still there');

        // The positive control: with the campaign gone, it deletes.
        DB::delete('campaigns', 'id = :id', ['id' => $campaignId]);

        $deleted = $owner->postWithToken('/segments/' . $id, '/segments/' . $id . '/delete', []);

        Assert::same(302, $deleted['status']);
        Assert::null(sh_row($name), 'so the refusal above was the campaign');
    } finally {
        sh_clear();
    }
});

// ------------------------------------------------------------------- editing

Harness::test('the editor shows back the rules it saved, groups and all', function () use ($owner): void {
    // A form that lost a rule on the way to the page would lose it on the way
    // back as well, silently widening the segment on the next save.
    $name = sh_name('roundtrip');

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
            sh_rule('lifetime_value_cents', 'gte', '250'),
            sh_rule('child_birth_month', 'is', '7', '', '1'),
            sh_rule('birth_month', 'is', '7', '', '1'),
        ]));

        $id   = (int) sh_row($name)['id'];
        $form = $owner->get('/segments/' . $id . '/edit');

        Assert::same(200, $form['status']);

        // Every field, operator and value is selected or filled.
        Assert::matches('/id="rule-field-0"[\s\S]*?value="email_consent" selected/', $form['body']);
        Assert::matches('/id="rule-op-0"[\s\S]*?value="is_true" selected/', $form['body']);
        Assert::matches('/id="rule-value-1"[^>]*value="250"/', $form['body'], 'the amount comes back');
        Assert::matches('/id="rule-group-2"[^>]*value="1"/', $form['body'], 'and the group number');

        // Re-saving unchanged must not change the rules.
        $before = (string) sh_row($name)['rules'];

        $owner->postWithToken('/segments/' . $id . '/edit', '/segments/' . $id, sh_post($name, [
            sh_rule('email_consent', 'is_true'),
            sh_rule('lifetime_value_cents', 'gte', '250'),
            sh_rule('child_birth_month', 'is', '7', '', '1'),
            sh_rule('birth_month', 'is', '7', '', '1'),
        ]));

        Assert::same(
            json_decode($before, true),
            json_decode((string) sh_row($name)['rules'], true),
            'a round trip through the editor changes nothing'
        );
    } finally {
        sh_clear();
    }
});

// ---------------------------------------------------------------------- CSRF

Harness::test('every write needs a CSRF token', function () use ($owner): void {
    $name = sh_name('csrf');

    try {
        $owner->postWithToken('/segments/new', '/segments', sh_post($name, [
            sh_rule('email_consent', 'is_true'),
        ]));

        $id = (int) sh_row($name)['id'];

        foreach (['refresh', 'delete'] as $action) {
            Assert::same(
                419,
                $owner->post('/segments/' . $id . '/' . $action, [])['status'],
                $action . ' refuses a tokenless post'
            );
        }

        Assert::notNull(sh_row($name), 'and nothing happened');

        Assert::same(
            419,
            $owner->post('/segments', sh_post(sh_name('tokenless'), [sh_rule('email_consent', 'is_true')]))['status']
        );
    } finally {
        sh_clear();
    }
});

Harness::test('the suite leaves nothing behind', function (): void {
    sh_clear();

    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {segments} WHERE name LIKE 'SegHttp%'"));
    Assert::same(0, (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE source = 'seghttp'"));
});
