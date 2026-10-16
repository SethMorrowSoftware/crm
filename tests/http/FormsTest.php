<?php

declare(strict_types=1);

/**
 * Web forms, over real HTTP.
 *
 * The public half of this module is the one page in the application a stranger
 * can POST to, and the one place the app can honestly record consent. Both of
 * those are tested here against OUTCOMES — rows in the database — rather than
 * against the absence of an error, because "no error" is exactly what a form
 * that silently swallows a guest's enquiry looks like.
 *
 * Three rules get the most attention, because each one is a promise made
 * elsewhere in writing:
 *
 *   - a submission never overwrites an existing contact's details;
 *   - consent is recorded only when a guest ticked a consent box, and an
 *     enquiry on its own records none (docs/PRIVACY.md §2);
 *   - a public page shows the form and nothing else — never another guest's
 *     submission.
 *
 * Every test here was mutation-checked: the code it covers was broken, the
 * test was confirmed red, and the code was restored.
 */

use App\Core\Auth;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/forms');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('forms suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('forms suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// A browser per logical actor, so the per-IP rate limiter never decides a
// test for us (see TestBrowser's constructor).
$owner = new TestBrowser($baseUrl, 'forms-owner');
$desk  = new TestBrowser($baseUrl, 'forms-desk');
$anon  = new TestBrowser($baseUrl, 'forms-anon');

$owner->login('owner@test.invalid', 'owner-test-passphrase-9');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

/**
 * A signed-in account holding `view_marketing` but NOT `forms_manage`.
 *
 * The read/build split is the module's own gate, and testing it with the
 * front desk alone would prove nothing: the front desk cannot see the page at
 * all, so a refusal there is the visibility gate firing, not this one. The
 * seeded Read Only role is exactly the profile needed.
 */
function forms_reader_browser(string $baseUrl): TestBrowser
{
    $email    = 'forms-reader@example.test';
    $password = 'reader-test-passphrase-9';

    $existing = DB::first('SELECT id FROM {users} WHERE email = :e', ['e' => $email]);

    if ($existing === null) {
        test_make_user('read_only', [
            'email'         => $email,
            'name'          => 'Read Only Robin',
            'password_hash' => Auth::hash($password),
        ]);
    } else {
        DB::update(
            'users',
            ['password_hash' => Auth::hash($password), 'is_active' => 1, 'deleted_at' => null],
            'id = :id',
            ['id' => (int) $existing['id']]
        );
    }

    $browser = new TestBrowser($baseUrl, 'forms-reader');
    $browser->login($email, $password);

    return $browser;
}

$reader = forms_reader_browser($baseUrl);

/** A slug nothing else in the suite is using. */
function forms_slug(string $hint): string
{
    static $counter = 0;
    $counter++;

    return 'test-' . $hint . '-' . $counter . '-' . bin2hex(random_bytes(3));
}

/**
 * Flatten a form definition into the body the builder posts.
 *
 * @param array<string,string>       $form
 * @param list<array<string,string>> $fields
 * @return array<string,string>
 */
function forms_body(array $form, array $fields): array
{
    $body = $form;

    foreach ($fields as $index => $field) {
        foreach ($field as $key => $value) {
            $body['fields[' . $index . '][' . $key . ']'] = $value;
        }
    }

    return $body;
}

/** The field definitions a party enquiry form carries in most of these tests. */
function forms_enquiry_fields(bool $withOptIn = false): array
{
    $fields = [
        ['label' => 'Your name',     'type' => 'text',  'maps_to' => 'first_name', 'is_required' => '1'],
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email',      'is_required' => '1'],
        ['label' => 'Phone',         'type' => 'tel',   'maps_to' => 'phone'],
        ['label' => 'Party date',    'type' => 'date'],
        ['label' => 'Anything else', 'type' => 'textarea'],
    ];

    if ($withOptIn) {
        $fields[] = [
            'label'     => 'Email me about offers',
            'type'      => 'consent',
            'maps_to'   => 'email_consent',
            'help_text' => 'About one email a month about parties and events. Unsubscribe any time.',
        ];
    }

    return $fields;
}

/**
 * Build a form through the HTTP builder and return its id.
 *
 * Deliberately not an INSERT: a fixture that bypasses the controller would
 * leave every test below exercising a form the builder may not actually be
 * able to produce.
 */
function forms_build(TestBrowser $browser, string $slug, array $fields, array $overrides = []): int
{
    $body = forms_body(array_merge([
        'name'         => 'Party enquiry ' . $slug,
        'slug'         => $slug,
        'title'        => 'Plan a party at the Castle',
        'description'  => 'Tell us what you have in mind and we will come back to you.',
        'submit_label' => 'Send my enquiry',
        'is_active'    => '1',
    ], $overrides), $fields);

    $response = $browser->postWithToken('/forms/new', '/forms', $body, true);

    Assert::same(200, $response['status'], 'building a form should land on a page');

    $id = DB::value('SELECT id FROM {forms} WHERE slug = :s', ['s' => $slug]);

    Assert::notNull($id, 'the form should exist after the builder accepted it: ' . $slug);

    return (int) $id;
}

/**
 * The POST names of a form's questions, by label.
 *
 * Read back from the database rather than recomputed here, so a test posts
 * the names the form really published.
 *
 * @return array<string,string>
 */
function forms_keys(int $formId): array
{
    return DB::pairs(
        'SELECT label, `key` FROM {form_fields} WHERE form_id = :id ORDER BY sort_order',
        ['id' => $formId]
    );
}

/** @return list<array<string,mixed>> */
function forms_submissions(int $formId): array
{
    return DB::all(
        'SELECT id, contact_id, data, is_spam, spam_score, ip, utm
           FROM {form_submissions} WHERE form_id = :id ORDER BY id',
        ['id' => $formId]
    );
}

/** @return array<string,mixed>|null */
function forms_contact(string $email): ?array
{
    return DB::first(
        'SELECT * FROM {contacts} WHERE email = :e AND deleted_at IS NULL ORDER BY id LIMIT 1',
        ['e' => strtolower($email)]
    );
}

// ========================================================== the admin list

Harness::test('the forms list renders for the owner', function () use ($owner): void {
    $response = $owner->get('/forms');

    Assert::same(200, $response['status']);
    Assert::contains('Build a form', $response['body'], 'an owner may build one');
    Assert::contains('only a ticked opt-in box', $response['body'], 'and should be told what a form is');
});

Harness::test('the front desk cannot reach the forms list', function () use ($desk): void {
    $response = $desk->get('/forms');

    Assert::inArray(
        $response['status'],
        [302, 403],
        'front desk holds neither view_marketing nor forms_manage'
    );
});

Harness::test('the forms list is closed to a visitor with no session', function () use ($anon): void {
    $response = $anon->get('/forms');

    Assert::same(302, $response['status']);
    Assert::contains('/login', $response['location'], 'and it should send them to sign in');
});

// ========================================================== building a form

Harness::test('the owner can build a form, and its questions are stored in order', function () use ($owner): void {
    $slug = forms_slug('build');
    $id   = forms_build($owner, $slug, forms_enquiry_fields(true));

    $form = DB::first('SELECT * FROM {forms} WHERE id = :id', ['id' => $id]);

    Assert::notNull($form);
    Assert::same('Plan a party at the Castle', (string) $form['title']);
    Assert::same('Send my enquiry', (string) $form['submit_label']);
    Assert::same(1, (int) $form['is_active']);

    $fields = DB::all(
        'SELECT `key`, label, type, maps_to, is_required, sort_order
           FROM {form_fields} WHERE form_id = :id ORDER BY sort_order',
        ['id' => $id]
    );

    Assert::count(6, $fields, 'all six questions should be stored');

    Assert::same('your_name', (string) $fields[0]['key'], 'the POST name comes from the label');
    Assert::same('first_name', (string) $fields[0]['maps_to']);
    Assert::same(1, (int) $fields[0]['is_required']);

    Assert::same('email', (string) $fields[1]['type']);
    Assert::same('email', (string) $fields[1]['maps_to']);

    Assert::same('tel', (string) $fields[2]['type'], 'a phone question is stored as the schema\'s tel');
    Assert::same(0, (int) $fields[2]['is_required'], 'and this one was left optional');

    Assert::same('consent', (string) $fields[5]['type']);
    Assert::same('email_consent', (string) $fields[5]['maps_to']);

    // Order is what the public page renders in, so it has to be the order the
    // builder listed, not insertion id.
    Assert::same([1, 2, 3, 4, 5, 6], array_map(
        static fn (array $f): int => (int) $f['sort_order'],
        $fields
    ));
});

Harness::test('building a form writes an audit row naming the actor', function () use ($owner): void {
    $slug = forms_slug('audit');

    $ownerId = (int) DB::value("SELECT id FROM {users} WHERE email = 'owner@test.invalid'");

    $id = forms_build($owner, $slug, forms_enquiry_fields());

    $audit = DB::first(
        'SELECT user_id, action, entity_type, entity_id, changes
           FROM {audit_log}
          WHERE action = :action AND entity_type = :type AND entity_id = :id
          ORDER BY id DESC LIMIT 1',
        ['action' => 'form.created', 'type' => 'forms', 'id' => $id]
    );

    Assert::notNull($audit, 'creating a form must be auditable');
    Assert::same($ownerId, (int) $audit['user_id'], 'and must name who did it');
    Assert::contains($slug, (string) $audit['changes'], 'the recorded change should name the form');
});

Harness::test('the builder refuses a form whose question rows are all blank', function () use ($owner): void {
    // What a browser really sends when somebody fills in the name and slug and
    // leaves the question rows alone: every field name is present and empty.
    // Testing only the no-`fields`-key-at-all case (below) misses this path
    // entirely, and this is the one an operator actually hits.
    $slug   = forms_slug('blankrows');
    $before = (int) DB::value('SELECT COUNT(*) FROM {forms}');

    $response = $owner->postWithToken('/forms/new', '/forms', forms_body([
        'name'      => 'Nothing to answer',
        'slug'      => $slug,
        'is_active' => '1',
    ], [
        ['label' => '', 'type' => 'text', 'maps_to' => '', 'help_text' => '', 'options' => ''],
        ['label' => '', 'type' => 'text', 'maps_to' => '', 'help_text' => '', 'options' => ''],
    ]), true);

    Assert::same(200, $response['status']);
    Assert::contains('Add at least one question', $response['body'], 'and should say why');
    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {forms}'),
        'a refused form must not be half-created'
    );
});

Harness::test('the builder refuses a form submitted with no question rows at all', function () use ($owner): void {
    $slug   = forms_slug('empty');
    $before = (int) DB::value('SELECT COUNT(*) FROM {forms}');

    $response = $owner->postWithToken('/forms/new', '/forms', [
        'name'      => 'Nothing to answer either',
        'slug'      => $slug,
        'is_active' => '1',
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('Add at least one question', $response['body']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {forms}'));
});

Harness::test('the builder refuses a slug that is not URL-safe', function () use ($owner): void {
    $response = $owner->postWithToken('/forms/new', '/forms', forms_body([
        'name'      => 'Bad address',
        'slug'      => 'Not A Slug!',
        'is_active' => '1',
    ], forms_enquiry_fields()), true);

    Assert::same(200, $response['status']);
    Assert::false(
        DB::exists('SELECT id FROM {forms} WHERE name = :n', ['n' => 'Bad address']),
        'a form with an unusable public address must not be stored'
    );
    Assert::contains('lowercase letters', $response['body'], 'and the message should say what is allowed');
});

Harness::test('the builder refuses a slug another form already uses', function () use ($owner): void {
    $slug = forms_slug('dupe');

    forms_build($owner, $slug, forms_enquiry_fields());

    $response = $owner->postWithToken('/forms/new', '/forms', forms_body([
        'name'      => 'Second claim on the same address',
        'slug'      => $slug,
        'is_active' => '1',
    ], forms_enquiry_fields()), true);

    Assert::same(200, $response['status']);
    Assert::same(
        1,
        (int) DB::value('SELECT COUNT(*) FROM {forms} WHERE slug = :s', ['s' => $slug]),
        'two forms cannot share one public address'
    );
    Assert::contains('already in use', $response['body']);
});

Harness::test('the builder refuses a consent question that grants nothing', function () use ($owner): void {
    // A consent box with no channel would render, be ticked, and record
    // nothing — a form telling a guest they had opted in when they had not.
    $response = $owner->postWithToken('/forms/new', '/forms', forms_body([
        'name'      => 'Consent with no channel',
        'slug'      => forms_slug('noconsent'),
        'is_active' => '1',
    ], [
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email', 'is_required' => '1'],
        ['label' => 'Keep me posted', 'type' => 'consent', 'maps_to' => ''],
    ]), true);

    Assert::same(200, $response['status']);
    Assert::false(
        DB::exists('SELECT id FROM {forms} WHERE name = :n', ['n' => 'Consent with no channel']),
        'a consent box that grants nothing must not reach a guest'
    );
    Assert::contains('email or SMS consent', $response['body']);
});

Harness::test('the builder refuses a list question with fewer than two options', function () use ($owner): void {
    $response = $owner->postWithToken('/forms/new', '/forms', forms_body([
        'name'      => 'One-option list',
        'slug'      => forms_slug('onelist'),
        'is_active' => '1',
    ], [
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email', 'is_required' => '1'],
        ['label' => 'How many children', 'type' => 'select', 'options' => "Ten\n"],
    ]), true);

    Assert::same(200, $response['status']);
    Assert::false(
        DB::exists('SELECT id FROM {forms} WHERE name = :n', ['n' => 'One-option list']),
        'a list with one choice is not a choice'
    );
    Assert::contains('at least two options', $response['body']);
});

Harness::test('a form with no reachable question saves, and says so', function () use ($owner): void {
    // The honest middle: an anonymous comment card is a real thing to want,
    // so this is a warning rather than a refusal — but it must be said, since
    // nothing else on the page reveals that submissions will produce no lead.
    $slug = forms_slug('anon');

    $response = $owner->postWithToken('/forms/new', '/forms', forms_body([
        'name'      => 'Anonymous comment card',
        'slug'      => $slug,
        'is_active' => '1',
    ], [
        ['label' => 'How was your visit', 'type' => 'textarea'],
    ]), true);

    Assert::same(200, $response['status']);
    Assert::true(
        DB::exists('SELECT id FROM {forms} WHERE slug = :s', ['s' => $slug]),
        'an anonymous form is allowed'
    );
    Assert::contains('without creating a contact', $response['body'], 'but the operator is warned');
});

// =============================================== the read / build permission split

Harness::test('view_marketing alone can read the forms list', function () use ($reader): void {
    // The precondition for the two tests below: a refusal there has to be
    // this module's build gate firing, not the page being closed outright.
    $response = $reader->get('/forms');

    Assert::same(200, $response['status'], 'view_marketing opens the page');
    Assert::notContains(
        'Build a form',
        $response['body'],
        'but a button whose POST would be refused should not be offered'
    );
});

Harness::test('view_marketing alone cannot open the builder', function () use ($reader): void {
    $response = $reader->get('/forms/new');

    Assert::inArray($response['status'], [302, 403], 'building needs forms_manage');
});

Harness::test('view_marketing alone cannot build a form', function () use ($reader, $owner): void {
    // The gate that matters is the POST, not the hidden button. The token is
    // minted on a page this role CAN open, exactly as an attacker would.
    $slug = forms_slug('reader');

    $response = $reader->postWithToken('/forms', '/forms', forms_body([
        'name'      => 'Built without permission',
        'slug'      => $slug,
        'is_active' => '1',
    ], forms_enquiry_fields()), true);

    Assert::inArray($response['status'], [200, 302, 403]);
    Assert::false(
        DB::exists('SELECT id FROM {forms} WHERE slug = :s', ['s' => $slug]),
        'a role without forms_manage must not be able to publish a page on the open internet'
    );
});

Harness::test('view_marketing alone can still read submissions', function () use ($reader, $owner, $baseUrl): void {
    // The split has to bite only on building. A marketing read-only account
    // that could not see the enquiries would make the permission useless.
    $slug = forms_slug('readersees');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-readersees');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Robin Reader',
        $keys['Email address'] => 'robin-reader-' . bin2hex(random_bytes(3)) . '@example.test',
    ], true);

    $response = $reader->get('/forms/' . $id . '/submissions');

    Assert::same(200, $response['status']);
    Assert::contains('Robin Reader', $response['body']);
});

// ======================================================== the public page

Harness::test('the public form renders with no login at all', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('public');
    forms_build($owner, $slug, forms_enquiry_fields(true));

    $guest    = new TestBrowser($baseUrl, 'forms-public-guest');
    $response = $guest->get('/f/' . $slug);

    Assert::same(200, $response['status'], 'a parent on the venue website has no account');
    Assert::contains('Plan a party at the Castle', $response['body'], 'the heading');
    Assert::contains('Your name', $response['body'], 'and every question');
    Assert::contains('Email me about offers', $response['body']);
    Assert::contains('Send my enquiry', $response['body'], 'and the operator\'s button label');
    Assert::matches(
        '/name="_token" value="[a-f0-9]{64}"/',
        $response['body'],
        'with a CSRF token, because the POST is not exempt'
    );
    Assert::contains('name="website_url"', $response['body'], 'and the honeypot');
});

Harness::test('two questions with the same label still get distinct POST names', function () use ($owner, $baseUrl): void {
    // form_fields is UNIQUE on (form_id, key), so a collision is a 500 on
    // save rather than a quiet overwrite — and a venue really does ask "Your
    // name" twice, once for the parent and once for the child.
    $slug = forms_slug('dupelabel');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email', 'is_required' => '1'],
        ['label' => 'Your name', 'type' => 'text', 'maps_to' => 'first_name'],
        ['label' => 'Your name', 'type' => 'text'],
    ]);

    $keys = DB::column(
        'SELECT `key` FROM {form_fields} WHERE form_id = :id ORDER BY sort_order',
        ['id' => $id]
    );

    Assert::count(3, $keys, 'all three questions are stored');
    Assert::count(3, array_unique($keys), 'and each has its own POST name: ' . implode(', ', $keys));

    // Both are answerable independently, which is the point of distinct names.
    $guest = new TestBrowser($baseUrl, 'forms-dupelabel-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys[0] => 'dupe-' . bin2hex(random_bytes(3)) . '@example.test',
        $keys[1] => 'Parent Pat',
        $keys[2] => 'Child Charlie',
    ], true);

    $data = json_decode((string) forms_submissions($id)[0]['data'], true);

    Assert::same('Parent Pat', (string) $data[$keys[1]]['value']);
    Assert::same('Child Charlie', (string) $data[$keys[2]]['value'], 'the second answer is not lost');
});

Harness::test('a question that would collide with the honeypot is renamed', function () use ($owner, $baseUrl): void {
    // A venue asking corporate enquirers for their website is entirely
    // ordinary — and the default honeypot is called website_url. Letting the
    // two share a name would make every answered submission read as spam.
    $slug = forms_slug('honeyclash');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email', 'is_required' => '1'],
        ['label' => 'Website URL', 'type' => 'text'],
    ]);

    $keys = forms_keys($id);

    Assert::notSame(
        'website_url',
        (string) $keys['Website URL'],
        'a real question must not be named after the trap'
    );

    $guest = new TestBrowser($baseUrl, 'forms-honeyclash-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Email address'] => 'clash-' . bin2hex(random_bytes(3)) . '@example.test',
        $keys['Website URL']   => 'https://example.test',
    ], true);

    $rows = forms_submissions($id);

    Assert::count(1, $rows);
    Assert::same(0, (int) $rows[0]['is_spam'], 'answering a real question is not a honeypot trip');
    Assert::notNull($rows[0]['contact_id'], 'and it still becomes a lead');
});

Harness::test('the thank-you message is not shown before anything is sent', function () use ($owner, $baseUrl): void {
    $slug   = forms_slug('thanks');
    $canary = 'ThanksCanary' . bin2hex(random_bytes(3));

    forms_build($owner, $slug, forms_enquiry_fields(), ['success_message' => $canary]);

    $guest = new TestBrowser($baseUrl, 'forms-thanks-guest');
    $blank = $guest->get('/f/' . $slug);

    Assert::same(200, $blank['status']);
    Assert::notContains($canary, $blank['body'], 'a blank form must not already say thank you');
    Assert::contains('Your name', $blank['body'], 'it should be showing the questions');

    $keys = forms_keys(
        (int) DB::value('SELECT id FROM {forms} WHERE slug = :s', ['s' => $slug])
    );

    $sent = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Said Thanks',
        $keys['Email address'] => 'thanks-' . bin2hex(random_bytes(3)) . '@example.test',
    ], true);

    Assert::contains($canary, $sent['body'], 'and afterwards it must say the operator\'s own words');
    Assert::notContains('Your name', $sent['body'], 'without offering the form again inline');
});

Harness::test('the public form is kept out of search engines and caches', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('noindex');
    forms_build($owner, $slug, forms_enquiry_fields());

    $guest    = new TestBrowser($baseUrl, 'forms-noindex-guest');
    $response = $guest->get('/f/' . $slug);

    Assert::matches('/X-Robots-Tag:\s*noindex/i', $response['headers']);
    Assert::matches('/Cache-Control:[^\r\n]*no-store/i', $response['headers']);
});

Harness::test('a form that is not live answers as though it never existed', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('off');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());

    $guest = new TestBrowser($baseUrl, 'forms-off-guest');

    Assert::same(200, $guest->get('/f/' . $slug)['status'], 'live to begin with');

    // Through the builder, so the switch itself is what is being tested.
    $owner->postWithToken('/forms/' . $id, '/forms/' . $id, forms_body([
        'name' => 'Party enquiry ' . $slug,
        'slug' => $slug,
    ], forms_enquiry_fields()), true);

    Assert::same(
        0,
        (int) DB::value('SELECT is_active FROM {forms} WHERE id = :id', ['id' => $id]),
        'omitting the Live checkbox turns the form off'
    );

    $after = $guest->get('/f/' . $slug);

    Assert::same(404, $after['status'], 'taking a form off the website has to actually take it off');
});

Harness::test('an unknown slug is a plain 404', function () use ($baseUrl): void {
    $guest    = new TestBrowser($baseUrl, 'forms-404-guest');
    $response = $guest->get('/f/no-such-form-' . bin2hex(random_bytes(4)));

    Assert::same(404, $response['status']);
});

// ======================================================== submitting

Harness::test('a submission is stored, creates a contact, and is audited', function () use ($owner, $baseUrl): void {
    $slug  = forms_slug('submit');
    $id    = forms_build($owner, $slug, forms_enquiry_fields());
    $keys  = forms_keys($id);
    $email = 'party-' . bin2hex(random_bytes(4)) . '@example.test';

    $guest = new TestBrowser($baseUrl, 'forms-submit-guest');

    $response = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Alex Parent',
        $keys['Email address'] => $email,
        $keys['Phone']         => '(845) 469-2116',
        $keys['Party date']    => '2026-07-04',
        $keys['Anything else'] => "Eight children.\nOne is allergic to peanuts.",
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('Thank you', $response['body'], 'the guest should be told it arrived');

    $rows = forms_submissions($id);

    Assert::count(1, $rows, 'the enquiry must be stored');
    Assert::same(0, (int) $rows[0]['is_spam']);

    $data = json_decode((string) $rows[0]['data'], true);

    Assert::true(is_array($data));
    Assert::same('Alex Parent', (string) $data[$keys['Your name']]['value']);
    Assert::same(
        'Your name',
        (string) $data[$keys['Your name']]['label'],
        'each answer keeps the question it was answering, so editing the form later cannot orphan it'
    );
    Assert::contains('peanuts', (string) $data[$keys['Anything else']]['value']);

    $contact = forms_contact($email);

    Assert::notNull($contact, 'a reachable enquiry becomes a lead');
    Assert::same('Alex Parent', (string) $contact['first_name']);
    Assert::same('+18454692116', (string) $contact['phone_normalized'], 'normalised for matching');
    Assert::same('lead', (string) $contact['lifecycle_stage']);
    Assert::same('form', (string) $contact['source']);
    Assert::same((int) $contact['id'], (int) $rows[0]['contact_id'], 'and the two are linked');

    Assert::same(
        1,
        (int) DB::value('SELECT submission_count FROM {forms} WHERE id = :id', ['id' => $id]),
        'the counter on the form should move'
    );

    $audit = DB::first(
        'SELECT user_id, action, entity_id, changes FROM {audit_log}
          WHERE action = :a AND entity_type = :t AND entity_id = :id ORDER BY id DESC LIMIT 1',
        ['a' => 'form.submitted', 't' => 'forms', 'id' => $id]
    );

    Assert::notNull($audit, 'a public write must be auditable even with nobody signed in');
    Assert::null($audit['user_id'], 'and the actor is genuinely nobody');
    Assert::contains($slug, (string) $audit['changes']);
});

Harness::test('a submission with no CSRF token is refused', function () use ($owner, $baseUrl): void {
    // The public POST is deliberately NOT exempt. Without this, any site could
    // aim a form submission at a visitor's browser.
    $slug = forms_slug('csrf');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-csrf-guest');

    // A GET first, so the guest holds a real session and the only thing
    // missing is the token itself.
    $guest->get('/f/' . $slug);

    $response = $guest->post('/f/' . $slug, [
        $keys['Your name']     => 'Forged Frank',
        $keys['Email address'] => 'forged-' . bin2hex(random_bytes(3)) . '@example.test',
    ]);

    Assert::same(419, $response['status'], 'a tokenless submission is refused');
    Assert::count(0, forms_submissions($id), 'and nothing is stored');
});

Harness::test('a missing required answer is refused, and comes back on the form', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('required');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-required-guest');

    $response = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        // The required email is left out entirely.
        $keys['Your name']     => 'Half Filled',
        $keys['Anything else'] => 'A bouncy castle, please.',
    ], true);

    Assert::same(200, $response['status']);
    Assert::count(0, forms_submissions($id), 'nothing is stored');
    Assert::contains(
        'Email address is required',
        $response['body'],
        'and the guest is told which question, not just that something went wrong'
    );
    Assert::contains(
        'bouncy castle',
        $response['body'],
        'and does not have to type their enquiry again'
    );
});

Harness::test('an unusable email address is refused', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('bademail');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-bademail-guest');

    $response = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Typo Terry',
        // A local part with no dotted domain: syntactically arguable, never
        // deliverable, and it would become a lead nobody can answer.
        $keys['Email address'] => 'terry@localhost',
    ], true);

    Assert::same(200, $response['status']);
    Assert::count(0, forms_submissions($id));
    Assert::contains('valid email address', $response['body']);
});

Harness::test('an answer that is not on the list is refused', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('select');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email', 'is_required' => '1'],
        ['label' => 'Package', 'type' => 'select', 'options' => "Bronze\nSilver\nGold", 'is_required' => '1'],
    ]);
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-select-guest');

    $refused = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Email address'] => 'select-' . bin2hex(random_bytes(3)) . '@example.test',
        $keys['Package']       => 'Platinum, invented by the sender',
    ], true);

    Assert::same(200, $refused['status']);
    Assert::count(0, forms_submissions($id), 'a select is validated on the server, not just in the browser');
    Assert::contains('one of the listed options', $refused['body']);

    // And the listed ones are accepted, or the check above would pass by
    // refusing everything.
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Email address'] => 'select-ok-' . bin2hex(random_bytes(3)) . '@example.test',
        $keys['Package']       => 'Gold',
    ], true);

    Assert::count(1, forms_submissions($id), 'a real option goes through');
});

Harness::test('an enquiry with no way to reach anybody creates no contact', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('nocontact');
    $id   = forms_build($owner, $slug, [
        ['label' => 'How was your visit', 'type' => 'textarea', 'is_required' => '1'],
    ]);
    $keys = forms_keys($id);

    $before = (int) DB::value('SELECT COUNT(*) FROM {contacts}');

    $guest = new TestBrowser($baseUrl, 'forms-nocontact-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['How was your visit'] => 'The go-karts were the best bit.',
    ], true);

    $rows = forms_submissions($id);

    Assert::count(1, $rows, 'the answer is still kept');
    Assert::null($rows[0]['contact_id'], 'but there is nobody to attach it to');
    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {contacts}'),
        'a contact row with neither an email nor a phone is litter, not a lead'
    );
});

Harness::test('a blank submission is refused rather than stored', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('blank');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email'],
        ['label' => 'Anything else', 'type' => 'textarea'],
    ]);

    $guest    = new TestBrowser($baseUrl, 'forms-blank-guest');
    $response = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [], true);

    Assert::same(200, $response['status']);
    Assert::count(0, forms_submissions($id), 'somebody leaning on the button is not an enquiry');
    Assert::contains('at least one question', $response['body']);
});

Harness::test('the honeypot holds a submission as spam and creates no contact', function () use ($owner, $baseUrl): void {
    $slug  = forms_slug('spam');
    $id    = forms_build($owner, $slug, forms_enquiry_fields());
    $keys  = forms_keys($id);
    $email = 'spambot-' . bin2hex(random_bytes(4)) . '@example.test';

    $bot = new TestBrowser($baseUrl, 'forms-spam-bot');

    $response = $bot->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Cheap Watches',
        $keys['Email address'] => $email,
        // The field no human is ever shown.
        'website_url'          => 'http://spam.invalid',
    ], true);

    Assert::same(200, $response['status'], 'a bot is told nothing');
    Assert::contains('Thank you', $response['body']);

    $rows = forms_submissions($id);

    Assert::count(1, $rows, 'spam is kept, so a false positive can be rescued');
    Assert::same(1, (int) $rows[0]['is_spam']);
    Assert::null($rows[0]['contact_id'], 'but it must not become a lead');
    Assert::null(forms_contact($email), 'and no contact is created for it');
    Assert::same(
        0,
        (int) DB::value('SELECT submission_count FROM {forms} WHERE id = :id', ['id' => $id]),
        'nor counted as a real submission'
    );
});

// =========================================== matching without overwriting

Harness::test('a submission fills a contact\'s blanks and overwrites nothing', function () use ($owner, $baseUrl): void {
    // THE rule. A web form is typed by whoever is at the keyboard, so letting
    // it rewrite a record staff have curated loses real information to a typo.
    $email = 'returning-' . bin2hex(random_bytes(4)) . '@example.test';

    $contactId = test_make_contact([
        'email'            => $email,
        'first_name'       => 'Margaret',
        'last_name'        => 'Okonkwo-Reyes',
        'phone'            => null,
        'phone_normalized' => null,
        'city'             => null,
        'lifecycle_stage'  => 'customer',
    ]);

    $slug = forms_slug('match');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Your name', 'type' => 'text', 'maps_to' => 'first_name', 'is_required' => '1'],
        ['label' => 'Surname', 'type' => 'text', 'maps_to' => 'last_name'],
        ['label' => 'Email address', 'type' => 'email', 'maps_to' => 'email', 'is_required' => '1'],
        ['label' => 'Phone', 'type' => 'tel', 'maps_to' => 'phone'],
        ['label' => 'Town', 'type' => 'text', 'maps_to' => 'city'],
    ]);
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-match-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Maggie',
        $keys['Surname']       => 'Reyes',
        $keys['Email address'] => $email,
        $keys['Phone']         => '845-469-2116',
        $keys['Town']          => 'Chester',
    ], true);

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {contacts} WHERE email = :e AND deleted_at IS NULL',
            ['e' => $email]
        ),
        'the same guest must not become a second contact'
    );

    $after = DB::first('SELECT * FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Assert::notNull($after);
    Assert::same('Margaret', (string) $after['first_name'], 'a populated name is left exactly as it was');
    Assert::same('Okonkwo-Reyes', (string) $after['last_name'], 'even when the guest typed a shorter one');
    Assert::same('customer', (string) $after['lifecycle_stage'], 'and they are not demoted back to a lead');

    // The other half of the rule: blanks ARE filled, or "never overwrite"
    // would just mean "never learn anything".
    Assert::same('Chester', (string) $after['city'], 'a blank field is filled in');
    Assert::same('+18454692116', (string) $after['phone_normalized'], 'including the matching column');

    $rows = forms_submissions($id);

    Assert::count(1, $rows);
    Assert::same($contactId, (int) $rows[0]['contact_id'], 'the submission links to the existing contact');

    $data = json_decode((string) $rows[0]['data'], true);

    Assert::same(
        'Maggie',
        (string) $data[$keys['Your name']]['value'],
        'and what they actually typed is still on the submission, so nothing they said is lost'
    );

    $audit = DB::first(
        'SELECT changes FROM {audit_log}
          WHERE action = :a AND entity_id = :id ORDER BY id DESC LIMIT 1',
        ['a' => 'form.submitted', 'id' => $id]
    );

    Assert::notNull($audit);
    Assert::contains(
        'first_name',
        (string) $audit['changes'],
        'the audit row names the fields that were withheld, so a human can reconcile them'
    );
});

Harness::test('a submission with only a phone number matches on the phone', function () use ($owner, $baseUrl): void {
    $contactId = test_make_contact([
        'email'            => null,
        'first_name'       => 'Dale',
        'last_name'        => null,
        'phone'            => '(845) 555-0177',
        'phone_normalized' => '+18455550177',
    ]);

    $slug = forms_slug('phone');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Phone', 'type' => 'tel', 'maps_to' => 'phone', 'is_required' => '1'],
        ['label' => 'Surname', 'type' => 'text', 'maps_to' => 'last_name'],
    ]);
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-phone-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        // A different rendering of the same number, which is the whole reason
        // phone_normalized exists.
        $keys['Phone']   => '18455550177',
        $keys['Surname'] => 'Hargreaves',
    ], true);

    $rows = forms_submissions($id);

    Assert::count(1, $rows);
    Assert::same(
        $contactId,
        (int) $rows[0]['contact_id'],
        'two renderings of one number are one guest'
    );

    $after = DB::first('SELECT last_name FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Assert::same('Hargreaves', (string) $after['last_name'], 'and the blank surname is filled');
});

// ================================================================= consent

Harness::test('a ticked opt-in records consent with its time, source and wording', function () use ($owner, $baseUrl): void {
    $slug  = forms_slug('consent');
    $id    = forms_build($owner, $slug, forms_enquiry_fields(true));
    $keys  = forms_keys($id);
    $email = 'optin-' . bin2hex(random_bytes(4)) . '@example.test';

    $guest = new TestBrowser($baseUrl, 'forms-consent-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']              => 'Opted In Olu',
        $keys['Email address']          => $email,
        $keys['Email me about offers']  => '1',
    ], true);

    $contact = forms_contact($email);

    Assert::notNull($contact);
    Assert::same(1, (int) $contact['email_consent'], 'the flag is set');
    Assert::notNull($contact['email_consent_at'], 'with the time it was given');

    $log = DB::first(
        'SELECT channel, action, source, ip, user_agent, evidence, created_at
           FROM {consent_log} WHERE contact_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => (int) $contact['id']]
    );

    Assert::notNull($log, 'and the evidence is recorded, not just the flag');
    Assert::same('email', (string) $log['channel']);
    Assert::same('granted', (string) $log['action']);
    Assert::contains('web form', (string) $log['source']);
    Assert::same($guest->clientIp(), (string) $log['ip'], 'from where');

    $evidence = json_decode((string) $log['evidence'], true);

    Assert::true(is_array($evidence));
    Assert::same($slug, (string) $evidence['form_slug']);
    Assert::contains(
        'About one email a month',
        (string) $evidence['disclosure'],
        'the wording the guest was actually shown is what a consent record has to hold'
    );
    Assert::same(
        (int) forms_submissions($id)[0]['id'],
        (int) $evidence['submission_id'],
        'and it points at the submission it came from'
    );
});

Harness::test('an enquiry with no opt-in question records no consent', function () use ($owner, $baseUrl): void {
    // docs/PRIVACY.md §2: a submitted enquiry is not permission to market.
    $slug  = forms_slug('noconsentfield');
    $id    = forms_build($owner, $slug, forms_enquiry_fields());
    $keys  = forms_keys($id);
    $email = 'noconsent-' . bin2hex(random_bytes(4)) . '@example.test';

    $guest = new TestBrowser($baseUrl, 'forms-noconsent-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Just Asking',
        $keys['Email address'] => $email,
    ], true);

    $contact = forms_contact($email);

    Assert::notNull($contact, 'they are still a lead');
    Assert::same(0, (int) $contact['email_consent'], 'but not a subscriber');
    Assert::null($contact['email_consent_at']);
    Assert::same(0, (int) $contact['sms_consent']);
    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id',
            ['id' => (int) $contact['id']]
        ),
        'and no consent event is invented for them'
    );
});

Harness::test('an opt-in left unticked records nothing and revokes nothing', function () use ($owner, $baseUrl): void {
    // Not ticking a box is not a withdrawal. Treating it as one would cancel
    // consent given at the till or by text, silently, on every enquiry.
    $email = 'already-' . bin2hex(random_bytes(4)) . '@example.test';

    $contactId = test_make_contact([
        'email'            => $email,
        'first_name'       => 'Already',
        'email_consent'    => 1,
        'email_consent_at' => '2026-01-02 03:04:05',
    ]);

    $slug = forms_slug('unticked');
    $id   = forms_build($owner, $slug, forms_enquiry_fields(true));
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-unticked-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Already',
        $keys['Email address'] => $email,
        // The opt-in box is simply absent, as an unticked checkbox is.
    ], true);

    Assert::count(1, forms_submissions($id), 'the enquiry went through');

    $after = DB::first(
        'SELECT email_consent, email_consent_at, marketing_opt_out_at
           FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );

    Assert::same(1, (int) $after['email_consent'], 'consent they already gave stands');
    Assert::same(
        '2026-01-02 03:04:05',
        (string) $after['email_consent_at'],
        'with its original timestamp, not today\'s'
    );
    Assert::null($after['marketing_opt_out_at'], 'and they are not opted out either');
    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id',
            ['id' => $contactId]
        ),
        'nothing is written to the consent record at all'
    );
});

Harness::test('a ticked opt-in with nobody to attach it to records no consent', function () use ($owner, $baseUrl): void {
    // A consent row for a channel the venue cannot reach is a record of
    // permission to message nobody.
    $slug = forms_slug('orphanconsent');
    $id   = forms_build($owner, $slug, [
        ['label' => 'How was your visit', 'type' => 'textarea', 'is_required' => '1'],
        [
            'label'     => 'Email me about offers',
            'type'      => 'consent',
            'maps_to'   => 'email_consent',
            'help_text' => 'Monthly news.',
        ],
    ]);
    $keys = forms_keys($id);

    $before = (int) DB::value('SELECT COUNT(*) FROM {consent_log}');

    $guest = new TestBrowser($baseUrl, 'forms-orphan-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['How was your visit']    => 'Good.',
        $keys['Email me about offers'] => '1',
    ], true);

    Assert::count(1, forms_submissions($id));
    Assert::same(
        $before,
        (int) DB::value('SELECT COUNT(*) FROM {consent_log}'),
        'no contact, so no consent'
    );
});

// ============================================================ not leaking

Harness::test('the public page never shows another guest\'s submission', function () use ($owner, $baseUrl): void {
    $slug  = forms_slug('leak');
    $id    = forms_build($owner, $slug, forms_enquiry_fields());
    $keys  = forms_keys($id);
    $email = 'private-' . bin2hex(random_bytes(4)) . '@example.test';
    $secret = 'MyChildIsCalledRosalind' . bin2hex(random_bytes(3));

    $first = new TestBrowser($baseUrl, 'forms-leak-first');
    $first->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'First Family',
        $keys['Email address'] => $email,
        $keys['Anything else'] => $secret,
    ], true);

    Assert::count(1, forms_submissions($id), 'the first enquiry really is stored');

    // A different visitor, with their own cookie jar, opening the same page.
    $second   = new TestBrowser($baseUrl, 'forms-leak-second');
    $response = $second->get('/f/' . $slug);

    Assert::same(200, $response['status']);
    Assert::notContains($secret, $response['body'], 'one guest must not read another\'s answers');
    Assert::notContains($email, $response['body'], 'nor their email address');
    Assert::notContains('First Family', $response['body'], 'nor their name');

    // Nor through the admin pages without a session.
    $anonymous = new TestBrowser($baseUrl, 'forms-leak-anon');

    foreach (['/forms', '/forms/' . $id . '/submissions', '/forms/' . $id] as $path) {
        $page = $anonymous->get($path);

        Assert::same(302, $page['status'], $path . ' must not answer a stranger');
        Assert::notContains($secret, $page['body']);
    }
});

// ================================================== reading submissions back

Harness::test('the submissions list and detail show the answers and the consent evidence', function () use ($owner, $baseUrl): void {
    $slug  = forms_slug('read');
    $id    = forms_build($owner, $slug, forms_enquiry_fields(true));
    $keys  = forms_keys($id);
    $email = 'readback-' . bin2hex(random_bytes(4)) . '@example.test';

    $guest = new TestBrowser($baseUrl, 'forms-read-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']             => 'Readable Rita',
        $keys['Email address']         => $email,
        $keys['Party date']            => '2026-08-15',
        $keys['Anything else']         => 'A dinosaur theme.',
        $keys['Email me about offers'] => '1',
    ], true);

    $list = $owner->get('/forms/' . $id . '/submissions');

    Assert::same(200, $list['status']);
    Assert::contains('Readable Rita', $list['body']);
    Assert::contains($email, $list['body']);

    $submissionId = (int) forms_submissions($id)[0]['id'];

    $detail = $owner->get('/forms/' . $id . '/submissions/' . $submissionId);

    Assert::same(200, $detail['status']);
    Assert::contains('A dinosaur theme.', $detail['body'], 'every answer');
    Assert::contains('Party date', $detail['body'], 'labelled with its question');
    Assert::contains('2026-08-15', $detail['body']);
    Assert::contains('Granted', $detail['body'], 'and the consent event');
    Assert::contains(
        'About one email a month',
        $detail['body'],
        'with the wording the guest agreed to, which is what the evidence is for'
    );
    Assert::contains($guest->clientIp(), $detail['body'], 'and where it came from');
});

Harness::test('a submission belonging to another form is not served under this one', function () use ($owner, $baseUrl): void {
    $slugA = forms_slug('mixa');
    $idA   = forms_build($owner, $slugA, forms_enquiry_fields());
    $keysA = forms_keys($idA);

    $slugB = forms_slug('mixb');
    $idB   = forms_build($owner, $slugB, forms_enquiry_fields());

    $guest = new TestBrowser($baseUrl, 'forms-mix-guest');
    $guest->postWithToken('/f/' . $slugA, '/f/' . $slugA, [
        $keysA['Your name']     => 'Belongs To A',
        $keysA['Email address'] => 'mix-' . bin2hex(random_bytes(3)) . '@example.test',
    ], true);

    $submissionId = (int) forms_submissions($idA)[0]['id'];

    $response = $owner->get('/forms/' . $idB . '/submissions/' . $submissionId);

    Assert::same(404, $response['status'], 'the id in the path is not authority on its own');
    Assert::notContains('Belongs To A', $response['body']);
});

// ================================================================ deleting

Harness::test('a form holding submissions cannot be deleted', function () use ($owner, $baseUrl): void {
    // form_submissions cascades, so this delete would take the venue's
    // enquiries and the evidence behind any consent recorded with them.
    $slug = forms_slug('keep');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-keep-guest');
    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Do Not Lose Me',
        $keys['Email address'] => 'keep-' . bin2hex(random_bytes(3)) . '@example.test',
    ], true);

    Assert::count(1, forms_submissions($id));

    $response = $owner->postWithToken('/forms/' . $id, '/forms/' . $id . '/delete', [], true);

    Assert::same(200, $response['status']);
    Assert::true(
        DB::exists('SELECT id FROM {forms} WHERE id = :id', ['id' => $id]),
        'the form survives'
    );
    Assert::count(1, forms_submissions($id), 'and so does the enquiry');
    Assert::contains('unticking Live', $response['body'], 'with the alternative spelled out');
});

Harness::test('an unused form can be deleted, and takes its questions with it', function () use ($owner): void {
    $slug = forms_slug('drop');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());

    Assert::same(
        5,
        (int) DB::value('SELECT COUNT(*) FROM {form_fields} WHERE form_id = :id', ['id' => $id]),
        'it has questions to begin with'
    );

    $response = $owner->postWithToken('/forms/' . $id, '/forms/' . $id . '/delete', [], true);

    Assert::same(200, $response['status']);
    Assert::false(
        DB::exists('SELECT id FROM {forms} WHERE id = :id', ['id' => $id]),
        'a form nobody has used is safe to remove'
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {form_fields} WHERE form_id = :id', ['id' => $id]),
        'and its questions go with it'
    );
    Assert::true(
        DB::exists(
            'SELECT id FROM {audit_log} WHERE action = :a AND entity_id = :id',
            ['a' => 'form.deleted', 'id' => $id]
        ),
        'recorded, because a page vanishing from the website is worth knowing about'
    );
});

Harness::test('view_marketing alone cannot delete a form', function () use ($reader, $owner): void {
    $slug = forms_slug('readerdrop');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());

    $reader->postWithToken('/forms', '/forms/' . $id . '/delete', [], true);

    Assert::true(
        DB::exists('SELECT id FROM {forms} WHERE id = :id', ['id' => $id]),
        'deleting needs forms_manage, not merely the right to look'
    );
});

// ============================================================== campaigns

Harness::test('a campaign link is carried through to the submission', function () use ($owner, $baseUrl): void {
    $slug = forms_slug('utm');
    $id   = forms_build($owner, $slug, forms_enquiry_fields());
    $keys = forms_keys($id);

    $guest = new TestBrowser($baseUrl, 'forms-utm-guest');

    $page = $guest->get('/f/' . $slug . '?utm_source=facebook&utm_campaign=birthday-spring');

    Assert::contains(
        'name="utm_source"',
        $page['body'],
        'the form carries the campaign forward as a hidden input, with no JavaScript'
    );

    $email = 'utm-' . bin2hex(random_bytes(4)) . '@example.test';

    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']     => 'Came From Facebook',
        $keys['Email address'] => $email,
        'utm_source'           => 'facebook',
        'utm_campaign'         => 'birthday-spring',
    ], true);

    $rows = forms_submissions($id);

    Assert::count(1, $rows);

    $utm = json_decode((string) $rows[0]['utm'], true);

    Assert::true(is_array($utm));
    Assert::same('facebook', (string) $utm['utm_source']);
    Assert::same('birthday-spring', (string) $utm['utm_campaign']);

    $contact = forms_contact($email);

    Assert::notNull($contact);
    Assert::contains(
        'facebook',
        (string) $contact['utm'],
        'and onto the new contact, which is what makes source reporting possible'
    );
});

// ============================== consent for a channel we cannot reach

Harness::test('an email opt-in on a phone-only submission records no consent', function () use (
    $owner,
    $baseUrl
): void {
    // A form can carry an "email me offers" box and no email field — or take
    // an email and offer an SMS box — so a guest can honestly tick a box for
    // a channel this submission gives no address for. Stored, that is a
    // permission nobody can exercise which still reads as a marketable guest
    // in every segment and count, and ContactController::updateConsent()
    // refuses exactly this with "Add an email address before recording email
    // consent." The public door into the same columns must not be the lenient
    // one.
    //
    // Broken to check: removing the $reachable guard from recordConsent() —
    // email_consent comes back 1 with no address on the row.
    $slug = forms_slug('unreachable');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Your name', 'type' => 'text', 'maps_to' => 'first_name', 'is_required' => '1'],
        ['label' => 'Phone', 'type' => 'tel', 'maps_to' => 'phone', 'is_required' => '1'],
        ['label' => 'Email me offers', 'type' => 'consent', 'maps_to' => 'email_consent'],
    ]);
    $keys = forms_keys($id);

    $phone = '845555' . random_int(1000, 9999);
    $guest = new TestBrowser($baseUrl, 'forms-unreachable-guest');

    $response = $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']       => 'Unreachable Uma',
        $keys['Phone']           => $phone,
        $keys['Email me offers'] => '1',
    ], true);

    Assert::same(200, $response['status']);

    $rows = forms_submissions($id);

    Assert::count(1, $rows, 'the enquiry itself is still kept — the lead is not lost');

    $contactId = (int) $rows[0]['contact_id'];

    Assert::greaterThan(0, $contactId, 'and a contact was made from the phone number');

    $contact = DB::first('SELECT * FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Assert::notNull($contact);
    Assert::same('', (string) ($contact['email'] ?? ''), 'precondition: there is no email address');
    Assert::same(
        0,
        (int) $contact['email_consent'],
        'so there is nothing to consent FOR, and no consent is recorded'
    );
    Assert::null($contact['email_consent_at'], 'nor a time it was given');

    Assert::same(
        0,
        (int) DB::value(
            "SELECT COUNT(*) FROM {consent_log} WHERE contact_id = :id AND channel = 'email'",
            ['id' => $contactId]
        ),
        'and the append-only log stays clean too — a logged grant reads as evidence'
    );

    DB::delete('consent_log', 'contact_id = :id', ['id' => $contactId]);
    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
});

Harness::test('an SMS opt-in is recorded when the guest gave a number in the same submission', function () use (
    $owner,
    $baseUrl
): void {
    // The other half, and the reason the reachability is read from the
    // CONTACT ROW inside the transaction rather than from the form's answers:
    // the number arrives in this very submission, so a guard that looked at
    // the stored contact BEFORE resolveContact() filled it in would refuse a
    // perfectly good opt-in. Without this test, guarding everything would
    // pass the test above.
    $slug = forms_slug('smsoptin');
    $id   = forms_build($owner, $slug, [
        ['label' => 'Your name', 'type' => 'text', 'maps_to' => 'first_name', 'is_required' => '1'],
        ['label' => 'Mobile', 'type' => 'tel', 'maps_to' => 'phone', 'is_required' => '1'],
        ['label' => 'Text me about parties', 'type' => 'consent', 'maps_to' => 'sms_consent'],
    ]);
    $keys = forms_keys($id);

    $phone = '845556' . random_int(1000, 9999);
    $guest = new TestBrowser($baseUrl, 'forms-smsoptin-guest');

    $guest->postWithToken('/f/' . $slug, '/f/' . $slug, [
        $keys['Your name']              => 'Texty Tess',
        $keys['Mobile']                 => $phone,
        $keys['Text me about parties']  => '1',
    ], true);

    $rows      = forms_submissions($id);
    $contactId = (int) ($rows[0]['contact_id'] ?? 0);

    Assert::greaterThan(0, $contactId);

    $contact = DB::first('SELECT * FROM {contacts} WHERE id = :id', ['id' => $contactId]);

    Assert::same(1, (int) $contact['sms_consent'], 'a reachable channel is consented normally');
    Assert::notNull($contact['sms_consent_at'], 'with the time it was given');
    Assert::contains(
        'web form',
        (string) $contact['sms_consent_source'],
        'and where it came from, which docs/PRIVACY.md §3 requires'
    );

    $logged = DB::first(
        "SELECT * FROM {consent_log} WHERE contact_id = :id AND channel = 'sms' ORDER BY id DESC LIMIT 1",
        ['id' => $contactId]
    );

    Assert::notNull($logged, 'and the grant is in the append-only log');
    Assert::same('granted', (string) $logged['action']);

    DB::delete('consent_log', 'contact_id = :id', ['id' => $contactId]);
    DB::delete('contacts', 'id = :id', ['id' => $contactId]);
});
