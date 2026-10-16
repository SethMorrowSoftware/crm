<?php

declare(strict_types=1);

/**
 * Waivers and check-in.
 *
 * A waiver's whole value is evidential: months later somebody has to be able
 * to say *this person agreed to exactly this text, at this time*. Four rules
 * carry that, and each is tested here against the thing that would destroy it:
 *
 *   1. A PUBLISHED VERSION IS IMMUTABLE. Editing text somebody has signed
 *      would leave a row claiming they agreed to wording they never saw.
 *   2. A SIGNATURE NAMES ITS VERSION, so publishing a new one tomorrow does
 *      not retroactively change what anybody agreed to.
 *   3. EXACTLY ONE VERSION IS ACTIVE, because two would make the public
 *      page's answer to "what am I signing?" depend on row order.
 *   4. A VERSION WITH SIGNATURES IS NEVER DELETED.
 *
 * The check-in half is tested on the two things it refuses to guess: it never
 * links a waiver to a guest on a near-match, and it never reconciles the
 * booking's headcount with the length of the guest list.
 */

use App\Controllers\WaiverController as W;
use App\Core\Auth;
use App\Core\DB;
use App\Services\AvailabilityEngine as A;
use App\Services\BookingRepository as R;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/waivers');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('waivers suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('waivers suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/** A body long enough to pass validation and distinctive enough to find. */
function wv_body(string $marker): string
{
    return 'WAIVER TEXT ' . $marker . '. ' . str_repeat(
        'The participant accepts the risks of the activity. ',
        3
    );
}

function wv_version(string $hint): string
{
    return $hint . '-' . bin2hex(random_bytes(3));
}

/** A waiver written straight to the database, draft by default. */
function wv_make(array $overrides = []): int
{
    static $counter = 0;
    $counter++;

    return DB::insert('waivers', array_merge([
        'version'           => wv_version('T' . $counter),
        'title'             => 'Test waiver ' . $counter,
        'body'              => wv_body('number ' . $counter),
        'requires_guardian' => 1,
        'is_active'         => 0,
        'created_at'        => now_utc(),
        'updated_at'        => now_utc(),
    ], $overrides));
}

/** @return array<string,mixed> */
function wv_row(int $id): array
{
    $row = DB::first('SELECT * FROM {waivers} WHERE id = :id', ['id' => $id]);

    Assert::notNull($row, 'waiver ' . $id . ' should exist');

    /** @var array<string,mixed> $row */
    return $row;
}

function wv_clear(): void
{
    DB::delete('waiver_signatures', 'signer_name LIKE :p', ['p' => 'WV %']);
    DB::query('UPDATE {booking_guests} SET waiver_id = NULL WHERE waiver_id IS NOT NULL');
    DB::delete('waiver_signatures', "signature_data LIKE '%typed_name%'", []);
    DB::delete('waivers', 'title LIKE :p', ['p' => 'Test waiver%']);
    DB::delete('waivers', 'title LIKE :p', ['p' => 'WV %']);
}

wv_clear();

$owner = new TestBrowser($baseUrl, 'waivers-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds `checkin` and NOT `waivers_manage` — the whole split.
$deskBrowser = new TestBrowser($baseUrl, 'waivers-desk');
$deskBrowser->login('desk@test.invalid', 'desk-test-passphrase-9');

Harness::test('the fixtures signed in', function () use ($owner, $deskBrowser): void {
    foreach (['owner' => $owner, 'desk' => $deskBrowser] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }
});

// ------------------------------------------------------------ the admin side

Harness::test('a version is created as a draft, never live', function () use ($owner): void {
    // Publishing changes what every guest is shown from that moment on, so it
    // is its own decision with its own button.
    $version = wv_version('Draft');

    $response = $owner->postWithToken('/waivers/new', '/waivers', [
        'version' => $version,
        'title'   => 'WV new draft',
        'body'    => wv_body('fresh draft'),
    ]);

    Assert::same(302, $response['status']);

    $row = DB::first('SELECT * FROM {waivers} WHERE version = :v', ['v' => $version]);

    Assert::notNull($row);
    Assert::same(0, (int) $row['is_active'], 'a new version is a draft');
    Assert::null($row['published_at']);

    DB::delete('waivers', 'id = :id', ['id' => (int) $row['id']]);
});

Harness::test('two versions cannot share a name', function () use ($owner): void {
    // The version is what a signature records, so it has to mean one thing.
    $version = wv_version('Dup');
    $first   = wv_make(['version' => $version, 'title' => 'WV first']);

    try {
        $response = $owner->postWithToken('/waivers/new', '/waivers', [
            'version' => $version,
            'title'   => 'WV second',
            'body'    => wv_body('a clash'),
        ]);

        Assert::same(302, $response['status']);
        Assert::same(
            1,
            (int) DB::value('SELECT COUNT(*) FROM {waivers} WHERE version = :v', ['v' => $version]),
            'and the second is refused rather than stored'
        );
    } finally {
        DB::delete('waivers', 'id = :id', ['id' => $first]);
    }
});

Harness::test('PUBLISHING RETIRES WHATEVER WAS LIVE, leaving exactly one', function () use ($owner): void {
    // Two active versions would make the public page's answer to "what am I
    // signing?" depend on row order.
    $first  = wv_make(['title' => 'WV first live']);
    $second = wv_make(['title' => 'WV second live']);

    try {
        $owner->postWithToken('/waivers/' . $first, '/waivers/' . $first . '/publish', []);

        Assert::same(1, (int) wv_row($first)['is_active']);
        Assert::notNull(wv_row($first)['published_at'], 'and the date it came into force is stamped');

        $stamped = (string) wv_row($first)['published_at'];

        $owner->postWithToken('/waivers/' . $second, '/waivers/' . $second . '/publish', []);

        Assert::same(0, (int) wv_row($first)['is_active'], 'the first is retired');
        Assert::same(1, (int) wv_row($second)['is_active']);
        Assert::same(
            $stamped,
            (string) wv_row($first)['published_at'],
            'and a retired version keeps the date its wording came into force'
        );

        Assert::same(
            1,
            (int) DB::value('SELECT COUNT(*) FROM {waivers} WHERE is_active = 1'),
            'exactly one version is live, always'
        );

        // RE-PUBLISHING THE SAME VERSION must not restamp it either. The
        // date is when that wording came into force, and a venue reverting to
        // an older version has not changed when it was written.
        //
        // The stamp is moved into the PAST first, because now_utc() has
        // second resolution: asserting "unchanged" moments after the original
        // publish passes whether or not the code restamps, which is how this
        // test first passed with the stamp unconditional.
        $long_ago = '2020-01-02 03:04:05';

        DB::update('waivers', ['published_at' => $long_ago], 'id = :id', ['id' => $first]);

        $owner->postWithToken('/waivers/' . $first, '/waivers/' . $first . '/publish', []);

        Assert::same(1, (int) wv_row($first)['is_active'], 'it is live again');
        Assert::same(
            $long_ago,
            (string) wv_row($first)['published_at'],
            'with the date its wording first came into force'
        );
    } finally {
        DB::delete('waivers', 'id IN (:a, :b)', ['a' => $first, 'b' => $second]);
    }
});

Harness::test('A PUBLISHED VERSION CANNOT BE EDITED', function () use ($owner): void {
    // THE RULE THE WHOLE MODULE EXISTS FOR. Rewriting signed text would leave
    // every signature on it pointing at wording nobody ever saw.
    $id = wv_make(['title' => 'WV published']);

    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    try {
        $before = (string) wv_row($id)['body'];

        Assert::same(
            302,
            $owner->get('/waivers/' . $id . '/edit')['status'],
            'the form is not even offered'
        );

        $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id, [
            'version' => (string) wv_row($id)['version'],
            'title'   => 'WV rewritten',
            'body'    => wv_body('REWRITTEN AFTER PUBLISHING'),
        ]);

        $after = wv_row($id);

        Assert::same($before, (string) $after['body'], 'the text is untouched');
        Assert::same('WV published', (string) $after['title'], 'and so is the heading');

        // The positive control: a DRAFT is editable, or this test would pass
        // with every edit refused.
        $draft = wv_make(['title' => 'WV editable draft']);

        $owner->postWithToken('/waivers/' . $draft . '/edit', '/waivers/' . $draft, [
            'version' => (string) wv_row($draft)['version'],
            'title'   => 'WV edited draft',
            'body'    => wv_body('an edited draft'),
        ]);

        Assert::same('WV edited draft', (string) wv_row($draft)['title'], 'a draft is editable');

        DB::delete('waivers', 'id = :id', ['id' => $draft]);
    } finally {
        DB::update('waivers', ['is_active' => 0], 'id = :id', ['id' => $id]);
        DB::delete('waivers', 'id = :id', ['id' => $id]);
    }
});

Harness::test('a version with signatures is never deleted', function () use ($owner, $baseUrl): void {
    // Those signatures are the record of what each guest agreed to. The
    // foreign key would refuse it anyway, and that refusal would reach the
    // operator as a 500 rather than as an explanation.
    $id = wv_make(['title' => 'WV signed version']);

    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-signer-del');
    $guest->postWithToken('/waiver', '/waiver', [
        'signer_name'  => 'WV Signer Delete',
        'signer_email' => 'wv-delete@example.test',
        'for_self'     => '1',
        'agree'        => '1',
    ], true);

    try {
        Assert::same(
            1,
            (int) DB::value('SELECT COUNT(*) FROM {waiver_signatures} WHERE waiver_id = :id', ['id' => $id]),
            'precondition: it has been signed'
        );

        // Retire it so the "that is the live version" refusal is not what
        // answers — the signature rule is what is being tested.
        $other = wv_make(['title' => 'WV replacement']);
        $owner->postWithToken('/waivers/' . $other, '/waivers/' . $other . '/publish', []);

        $response = $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/delete', [], true);

        Assert::notNull(
            DB::first('SELECT id FROM {waivers} WHERE id = :id', ['id' => $id]),
            'the version is still there'
        );

        // AND IT SAYS WHY. The foreign key would refuse this delete anyway —
        // MEASURED: removing the guard leaves the row intact — but the
        // refusal would reach the operator as a 500 with no explanation. The
        // guard exists for the sentence, so the sentence is what is tested.
        Assert::same(200, $response['status'], 'a refusal, not a database error');
        Assert::contains('signature', $response['body'], 'and it explains itself');
        Assert::contains('Publish a new version instead', $response['body'], 'and says what to do');

        DB::update('waivers', ['is_active' => 0], 'id = :id', ['id' => $other]);
        DB::delete('waivers', 'id = :id', ['id' => $other]);
    } finally {
        wv_clear();
    }
});

Harness::test('the live version cannot be deleted even with no signatures', function () use ($owner): void {
    // Guests must always have something to sign.
    $id = wv_make(['title' => 'WV live undeletable']);

    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    try {
        $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/delete', []);

        Assert::notNull(DB::first('SELECT id FROM {waivers} WHERE id = :id', ['id' => $id]));

        // A retired draft with nothing against it IS deletable.
        DB::update('waivers', ['is_active' => 0], 'id = :id', ['id' => $id]);

        $draft = wv_make(['title' => 'WV deletable draft']);

        $owner->postWithToken('/waivers/' . $draft, '/waivers/' . $draft . '/delete', []);

        Assert::null(
            DB::first('SELECT id FROM {waivers} WHERE id = :id', ['id' => $draft]),
            'a draft nobody has signed is deletable'
        );
    } finally {
        DB::delete('waivers', 'id = :id', ['id' => $id]);
    }
});

Harness::test('an empty waiver cannot be published', function () use ($owner): void {
    // There would be nothing to agree to.
    $id = wv_make(['title' => 'WV empty', 'body' => '   ']);

    try {
        $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

        Assert::same(0, (int) wv_row($id)['is_active']);
    } finally {
        DB::delete('waivers', 'id = :id', ['id' => $id]);
    }
});

Harness::test('front desk cannot manage versions but can still check guests in', function () use (
    $deskBrowser
): void {
    // Front Desk holds `checkin` and not `waivers_manage`. The wording of a
    // legal document is not a counter decision.
    Assert::same(403, $deskBrowser->get('/waivers')['status']);
    Assert::same(403, $deskBrowser->get('/waivers/new')['status']);
    Assert::same(200, $deskBrowser->get('/checkin')['status'], 'but check-in is their page');

    $before = (int) DB::value('SELECT COUNT(*) FROM {waivers}');

    $response = $deskBrowser->postWithToken('/checkin', '/waivers', [
        'version' => wv_version('Desk'),
        'title'   => 'WV by the desk',
        'body'    => wv_body('should not exist'),
    ]);

    Assert::same(403, $response['status']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {waivers}'));
});

// ----------------------------------------------------------- public signing

Harness::test('the public page says so honestly when nothing is published', function () use ($baseUrl): void {
    // A venue with no waiver should not show a guest an empty form to agree
    // to.
    DB::query('UPDATE {waivers} SET is_active = 0 WHERE is_active = 1');

    $guest    = new TestBrowser($baseUrl, 'waivers-guest-none');
    $response = $guest->get('/waiver');

    Assert::same(200, $response['status']);
    Assert::contains('Nothing to sign just yet', $response['body']);
    Assert::notContains('name="agree"', $response['body'], 'and offers no agreement to tick');
});

Harness::test('A GUEST SIGNS, AND THE SIGNATURE RECORDS ITS VERSION AND ITS EVIDENCE', function () use (
    $owner,
    $baseUrl
): void {
    $id = wv_make(['title' => 'WV signing version']);

    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-sign');

    try {
        $page = $guest->get('/waiver');

        Assert::same(200, $page['status'], 'the page is reachable with no account');
        Assert::contains('WAIVER TEXT', $page['body'], 'and shows the text being agreed to');
        Assert::contains('name="_token"', $page['body'], 'with a CSRF token minted into the session');
        Assert::contains('noindex', strtolower((string) $page['headers']), 'and is not indexed');

        $response = $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'      => 'WV Parent Signing',
            'signer_email'     => 'wv-parent@example.test',
            'minors[0][name]'  => 'WV Child One',
            'minors[0][age]'   => '7',
            'minors[1][name]'  => 'WV Child Two',
            'agree'            => '1',
        ], true);

        Assert::same(200, $response['status']);
        Assert::contains('Thank you', $response['body']);

        $signature = DB::first(
            'SELECT * FROM {waiver_signatures} WHERE signer_name = :n ORDER BY id DESC LIMIT 1',
            ['n' => 'WV Parent Signing']
        );

        Assert::notNull($signature);
        Assert::same($id, (int) $signature['waiver_id'], 'the version signed is recorded');
        Assert::same(1, (int) $signature['is_guardian'], 'signing for children makes them a guardian');
        Assert::notNull($signature['ip'], 'with the address it came from');
        Assert::notEmpty((string) $signature['user_agent']);
        Assert::notNull($signature['expires_at'], 'and a date it runs out');

        $minors = json_decode((string) $signature['minors'], true);

        Assert::count(2, $minors, 'both children');
        Assert::same('WV Child One', (string) $minors[0]['name']);
        Assert::same(7, (int) $minors[0]['age']);
        Assert::null($minors[1]['age'], 'an age nobody gave stays unknown rather than becoming zero');

        // WHAT WAS AGREED TO, which is what a dispute turns on.
        $evidence = json_decode((string) $signature['signature_data'], true);

        Assert::same('typed_name', (string) $evidence['method']);
        Assert::same('WV Parent Signing', (string) $evidence['typed']);
        Assert::same(W::AGREEMENT, (string) $evidence['agreement'], 'the wording they ticked');
        Assert::same(
            (string) wv_row($id)['version'],
            (string) $evidence['version'],
            'and the version, twice over'
        );
    } finally {
        wv_clear();
    }
});

Harness::test('PUBLISHING A NEW VERSION DOES NOT CHANGE WHAT WAS ALREADY SIGNED', function () use (
    $owner,
    $baseUrl
): void {
    // The point of recording waiver_id. Without it, a signature would always
    // appear to be against whatever is live today.
    $first = wv_make(['title' => 'WV version one', 'body' => wv_body('THE FIRST WORDING')]);

    $owner->postWithToken('/waivers/' . $first, '/waivers/' . $first . '/publish', []);

    // The NEXT version exists as a draft BEFORE the signature is given, so
    // "the live version" and "the newest version" are different rows. Without
    // that they are the same row, and a signature that recorded whichever was
    // newest would look correct. MEASURED: it did.
    $second = wv_make(['title' => 'WV version two', 'body' => wv_body('THE SECOND WORDING')]);

    Assert::greaterThan($first, $second, 'the draft is the newer row');

    $guest = new TestBrowser($baseUrl, 'waivers-guest-versions');
    $guest->postWithToken('/waiver', '/waiver', [
        'signer_name'  => 'WV Early Signer',
        'signer_email' => 'wv-early@example.test',
        'for_self'     => '1',
        'agree'        => '1',
    ], true);

    $owner->postWithToken('/waivers/' . $second, '/waivers/' . $second . '/publish', []);

    try {
        $signature = DB::first(
            'SELECT s.waiver_id, s.public_token, w.body
               FROM {waiver_signatures} s
               JOIN {waivers} w ON w.id = s.waiver_id
              WHERE s.signer_name = :n ORDER BY s.id DESC LIMIT 1',
            ['n' => 'WV Early Signer']
        );

        Assert::notNull($signature);
        Assert::same($first, (int) $signature['waiver_id'], 'still the version they actually signed');
        Assert::contains('THE FIRST WORDING', (string) $signature['body']);
        Assert::notContains('THE SECOND WORDING', (string) $signature['body']);

        // And their own receipt shows the text they agreed to, not today's.
        $receipt = $guest->get('/waiver/' . (string) $signature['public_token']);

        Assert::same(200, $receipt['status']);
        Assert::contains('THE FIRST WORDING', $receipt['body']);
        Assert::notContains('THE SECOND WORDING', $receipt['body']);
    } finally {
        wv_clear();
    }
});

Harness::test('a signature with nothing agreed to, no name or no contact is refused', function () use (
    $owner,
    $baseUrl
): void {
    $id = wv_make(['title' => 'WV validation']);

    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-invalid');

    try {
        foreach ([
            'no agreement' => [
                'signer_name'  => 'WV No Agreement',
                'signer_email' => 'wv-noagree@example.test',
                'for_self'     => '1',
            ],
            'no name' => [
                'signer_email' => 'wv-noname@example.test',
                'for_self'     => '1',
                'agree'        => '1',
            ],
            'no way to reach them' => [
                'signer_name' => 'WV No Contact',
                'for_self'    => '1',
                'agree'       => '1',
            ],
            // `for_self` is set so the GUARDIAN rule cannot be what refuses
            // this: without it the submission was already invalid for naming
            // nobody at all, and the test passed with the half-child check
            // removed. Silently dropping a row with an age and no name would
            // leave a child uncovered by a waiver their parent believes they
            // signed.
            'a child with an age and no name' => [
                'signer_name'     => 'WV Half Child',
                'signer_email'    => 'wv-halfchild@example.test',
                'for_self'        => '1',
                'minors[0][age]'  => '8',
                'agree'           => '1',
            ],
        ] as $what => $body) {
            $before = (int) DB::value('SELECT COUNT(*) FROM {waiver_signatures}');

            $guest->postWithToken('/waiver', '/waiver', $body, true);

            Assert::same(
                $before,
                (int) DB::value('SELECT COUNT(*) FROM {waiver_signatures}'),
                $what . ' must not be stored'
            );
        }
    } finally {
        wv_clear();
    }
});

Harness::test('a signature attaches to a booking by its number, and leaks nothing else', function () use (
    $owner,
    $baseUrl
): void {
    // A guest typing a booking number into a URL must not be able to read the
    // party's figures, its notes or the family's details out of this page.
    //
    // Two layers hold that: the SELECT fetches four columns, and the view
    // echoes three of them. MEASURED: widening the SELECT to `*` alone leaves
    // this test green, because the view still prints only what it prints. So
    // this pins "the page does not leak", not "the narrow SELECT is what stops
    // it" — the SELECT is defence against a future change to the view, which
    // is exactly the change a test cannot anticipate.
    $bookingId = DB::insert('bookings', [
        'booking_number'      => 'WV-' . bin2hex(random_bytes(4)),
        'type'                => 'birthday',
        'status'              => 'confirmed',
        'event_date'          => A::addDays(R::today(), 7),
        'start_time'          => '14:00:00',
        'guest_count'         => 12,
        'guest_of_honor_name' => 'Priya',
        'allergies'           => 'WVSECRETALLERGY',
        'internal_notes'      => 'WVSECRETNOTES',
        'total_cents'         => 987654,
        'created_at'          => now_utc(),
        'updated_at'          => now_utc(),
    ]);

    $number = (string) DB::value('SELECT booking_number FROM {bookings} WHERE id = :id', ['id' => $bookingId]);

    $id = wv_make(['title' => 'WV booking link']);
    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-booking');

    try {
        $page = $guest->get('/waiver?booking=' . urlencode($number));

        Assert::same(200, $page['status']);
        Assert::contains('Priya', $page['body'], 'it says which party is being signed for');
        Assert::notContains('WVSECRETALLERGY', $page['body'], 'and not the allergy note');
        Assert::notContains('WVSECRETNOTES', $page['body'], 'nor the internal notes');
        Assert::notContains('9876.54', $page['body'], 'nor the figure');

        $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'  => 'WV Booking Parent',
            'signer_email' => 'wv-booking@example.test',
            'booking'      => $number,
            'for_self'     => '1',
            'agree'        => '1',
        ], true);

        $signature = DB::first(
            'SELECT booking_id FROM {waiver_signatures} WHERE signer_name = :n ORDER BY id DESC LIMIT 1',
            ['n' => 'WV Booking Parent']
        );

        Assert::notNull($signature);
        Assert::same($bookingId, (int) $signature['booking_id'], 'the signature is on that party');

        // An unknown or cancelled booking number attaches nothing rather than
        // failing — the signature itself still matters.
        $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'  => 'WV Unknown Booking',
            'signer_email' => 'wv-unknown@example.test',
            'booking'      => 'B-9999-9999',
            'for_self'     => '1',
            'agree'        => '1',
        ], true);

        $loose = DB::first(
            'SELECT booking_id FROM {waiver_signatures} WHERE signer_name = :n ORDER BY id DESC LIMIT 1',
            ['n' => 'WV Unknown Booking']
        );

        Assert::notNull($loose, 'the signature is still recorded');
        Assert::null($loose['booking_id'], 'just not against a party');
    } finally {
        wv_clear();
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('a signature is matched to an existing guest by email', function () use ($owner, $baseUrl): void {
    $contactId = test_make_contact([
        'first_name' => 'Known',
        'last_name'  => 'Signer',
        'email'      => 'wv-known@example.test',
    ]);

    $id = wv_make(['title' => 'WV contact match']);
    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-contact');

    try {
        $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'  => 'WV Known Signer',
            // Mixed case on purpose: contacts are stored lowercased.
            'signer_email' => 'WV-Known@Example.Test',
            'for_self'     => '1',
            'agree'        => '1',
        ], true);

        $signature = DB::first(
            'SELECT contact_id, signer_email FROM {waiver_signatures} WHERE signer_name = :n ORDER BY id DESC LIMIT 1',
            ['n' => 'WV Known Signer']
        );

        Assert::notNull($signature);
        Assert::same($contactId, (int) $signature['contact_id'], 'tied to the guest already on file');
        Assert::same('wv-known@example.test', (string) $signature['signer_email'], 'stored lowercased');
    } finally {
        wv_clear();
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('a receipt link shows only what its own signer already knows', function () use (
    $owner,
    $baseUrl
): void {
    $id = wv_make(['title' => 'WV receipt']);
    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-receipt');
    $guest->postWithToken('/waiver', '/waiver', [
        'signer_name'  => 'WV Receipt Holder',
        'signer_email' => 'wv-receipt@example.test',
        'for_self'     => '1',
        'agree'        => '1',
    ], true);

    // Somebody else's signature, which must not be reachable.
    $guest->postWithToken('/waiver', '/waiver', [
        'signer_name'  => 'WV Other Person',
        'signer_email' => 'wv-other@example.test',
        'for_self'     => '1',
        'agree'        => '1',
    ], true);

    try {
        $token = (string) DB::value(
            'SELECT public_token FROM {waiver_signatures} WHERE signer_name = :n',
            ['n' => 'WV Receipt Holder']
        );

        $receipt = $guest->get('/waiver/' . $token);

        Assert::same(200, $receipt['status']);
        Assert::contains('WV Receipt Holder', $receipt['body']);
        Assert::notContains('WV Other Person', $receipt['body'], 'and nobody else');
        Assert::contains('WAIVER TEXT', $receipt['body'], 'with the text they agreed to');

        // A wrong token is a 404, not a hint.
        Assert::same(404, $guest->get('/waiver/' . str_repeat('a', 32))['status']);
        Assert::same(404, $guest->get('/waiver/short')['status']);
    } finally {
        wv_clear();
    }
});

// ---------------------------------------------------------------- check-in

Harness::test('check-in lists the day\'s parties with the allergy note, and no money', function () use (
    $deskBrowser
): void {
    $date = A::addDays(R::today(), 3);

    $bookingId = DB::insert('bookings', [
        'booking_number'      => 'WV-' . bin2hex(random_bytes(4)),
        'type'                => 'birthday',
        'status'              => 'confirmed',
        'event_date'          => $date,
        'start_time'          => '14:00:00',
        'end_time'            => '16:00:00',
        'guest_count'         => 14,
        'guest_of_honor_name' => 'Amara',
        'allergies'           => 'WVDESKALLERGY',
        'total_cents'         => 876543,
        'created_at'          => now_utc(),
        'updated_at'          => now_utc(),
    ]);

    try {
        $page = $deskBrowser->get('/checkin?date=' . $date);

        Assert::same(200, $page['status']);
        Assert::contains('Amara', $page['body']);
        Assert::contains('WVDESKALLERGY', $page['body'], 'the allergy note is unmissable');
        Assert::notContains('8765.43', $page['body'], 'and there is no money on the page');
        Assert::contains('/checkin/' . $bookingId . '"', $page['body']);

        $detail = $deskBrowser->get('/checkin/' . $bookingId);

        Assert::same(200, $detail['status']);
        Assert::contains('WVDESKALLERGY', $detail['body']);
        Assert::notContains('8765.43', $detail['body']);
        Assert::contains('14 expected', $detail['body'], 'the booking\'s own headcount');
        Assert::contains('0 of 0 listed guests in', $detail['body'], 'and the list\'s, unreconciled');
    } finally {
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('a guest is ticked in and back out again', function () use ($deskBrowser): void {
    $bookingId = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '14:00:00',
        'guest_count'    => 4,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $guestId = DB::insert('booking_guests', [
        'booking_id' => $bookingId,
        'name'       => 'WV Arriving Child',
        'rsvp_status' => 'yes',
        'created_at' => now_utc(),
    ]);

    try {
        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/guest',
            ['guest_id' => (string) $guestId]
        );

        Assert::notNull(
            DB::value('SELECT checked_in_at FROM {booking_guests} WHERE id = :id', ['id' => $guestId]),
            'in'
        );

        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/guest',
            ['guest_id' => (string) $guestId]
        );

        Assert::null(
            DB::value('SELECT checked_in_at FROM {booking_guests} WHERE id = :id', ['id' => $guestId]),
            'and a mis-tap is undone'
        );

        // A guest on another booking cannot be ticked through this one.
        $otherBooking = DB::insert('bookings', [
            'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
            'type'           => 'birthday',
            'status'         => 'confirmed',
            'event_date'     => R::today(),
            'start_time'     => '17:00:00',
            'guest_count'    => 4,
            'created_at'     => now_utc(),
            'updated_at'     => now_utc(),
        ]);

        $otherGuest = DB::insert('booking_guests', [
            'booking_id'  => $otherBooking,
            'name'        => 'WV Other Party Child',
            'rsvp_status' => 'yes',
            'created_at'  => now_utc(),
        ]);

        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/guest',
            ['guest_id' => (string) $otherGuest]
        );

        Assert::null(
            DB::value('SELECT checked_in_at FROM {booking_guests} WHERE id = :id', ['id' => $otherGuest]),
            'a guest on another party is not this door list\'s to tick'
        );

        DB::delete('bookings', 'id = :id', ['id' => $otherBooking]);
    } finally {
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('ARRIVE-ALL ONLY EVER MARKS PEOPLE AS ARRIVED', function () use ($deskBrowser): void {
    // A mis-tap on a button that unchecks forty guests is not recoverable
    // from memory, and the row buttons already undo one at a time.
    $bookingId = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'field_trip',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '10:00:00',
        'guest_count'    => 40,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        $ids[] = DB::insert('booking_guests', [
            'booking_id'  => $bookingId,
            'name'        => 'WV Coach Child ' . $i,
            'rsvp_status' => 'yes',
            'created_at'  => now_utc(),
        ]);
    }

    try {
        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/arrive-all',
            []
        );

        Assert::same(
            3,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id AND checked_in_at IS NOT NULL',
                ['id' => $bookingId]
            ),
            'everybody in'
        );

        // The arrival times are moved into the past before pressing again,
        // because now_utc() has second resolution: comparing stamps taken in
        // the same second passes whether or not the second press restamps
        // them, which is how this assertion first passed with the
        // "only those not yet in" condition removed. A restamped arrival time
        // is not cosmetic — it is the record of when a child actually got
        // here.
        $earlier = '2020-01-02 03:04:05';

        DB::query(
            'UPDATE {booking_guests} SET checked_in_at = :then WHERE booking_id = :id',
            ['then' => $earlier, 'id' => $bookingId]
        );

        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/arrive-all',
            []
        );

        Assert::same(
            3,
            (int) DB::value(
                'SELECT COUNT(*) FROM {booking_guests} WHERE booking_id = :id AND checked_in_at IS NOT NULL',
                ['id' => $bookingId]
            ),
            'and pressing it twice does not undo anything'
        );
        Assert::same(
            [$earlier, $earlier, $earlier],
            array_column(
                DB::all(
                    'SELECT checked_in_at FROM {booking_guests} WHERE booking_id = :id ORDER BY id',
                    ['id' => $bookingId]
                ),
                'checked_in_at'
            ),
            'nor restamp the times they arrived'
        );
    } finally {
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('an unlisted guest is added at the desk, already in', function () use ($deskBrowser): void {
    $bookingId = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '14:00:00',
        'guest_count'    => 10,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    try {
        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/guests',
            [
                'name'         => 'WV Walk Up',
                'age'          => '6',
                'parent_name'  => 'WV Walk Up Parent',
                'parent_phone' => '(845) 555-0177',
            ]
        );

        $guest = DB::first(
            'SELECT * FROM {booking_guests} WHERE booking_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $bookingId]
        );

        Assert::notNull($guest);
        Assert::same('WV Walk Up', (string) $guest['name']);
        Assert::notNull($guest['checked_in_at'], 'added at the desk means they are here');
        Assert::same('yes', (string) $guest['rsvp_status']);
        Assert::same('+18455550177', (string) $guest['parent_phone'], 'the number is normalised');
    } finally {
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('A WAIVER LINKS TO A GUEST ON AN EXACT NAME AND NEVER ON A NEAR ONE', function () use (
    $owner,
    $deskBrowser,
    $baseUrl
): void {
    // A wrong tick at a check-in desk is worse than no tick: somebody walks
    // past a waiver nobody signed. So the automatic link is exact-match only,
    // and everything else is matched by hand.
    $bookingId = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '14:00:00',
        'guest_count'    => 6,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $number = (string) DB::value('SELECT booking_number FROM {bookings} WHERE id = :id', ['id' => $bookingId]);

    // One name matches the signature exactly (bar case), one is close but not
    // the same person.
    $exact = DB::insert('booking_guests', [
        'booking_id'  => $bookingId,
        'name'        => 'wv exact child',
        'rsvp_status' => 'yes',
        'created_at'  => now_utc(),
    ]);
    $near = DB::insert('booking_guests', [
        'booking_id'  => $bookingId,
        'name'        => 'WV Exact Childs',
        'rsvp_status' => 'yes',
        'created_at'  => now_utc(),
    ]);

    $id = wv_make(['title' => 'WV linking']);
    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-link');

    try {
        $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'     => 'WV Linking Parent',
            'signer_email'    => 'wv-link@example.test',
            'booking'         => $number,
            'minors[0][name]' => 'WV Exact Child',
            'agree'           => '1',
        ], true);

        $signatureId = (int) DB::value(
            'SELECT id FROM {waiver_signatures} WHERE signer_name = :n ORDER BY id DESC LIMIT 1',
            ['n' => 'WV Linking Parent']
        );

        Assert::greaterThan(0, $signatureId);
        Assert::same(
            $signatureId,
            (int) DB::value('SELECT waiver_id FROM {booking_guests} WHERE id = :id', ['id' => $exact]),
            'the exact name (case aside) is covered automatically'
        );
        Assert::null(
            DB::value('SELECT waiver_id FROM {booking_guests} WHERE id = :id', ['id' => $near]),
            'and the nearly-matching name is NOT'
        );

        // The desk matches it by hand.
        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/waiver',
            ['guest_id' => (string) $near, 'signature_id' => (string) $signatureId]
        );

        Assert::same(
            $signatureId,
            (int) DB::value('SELECT waiver_id FROM {booking_guests} WHERE id = :id', ['id' => $near]),
            'which is what the by-hand match is for'
        );

        // And unlinking is a legitimate correction.
        $deskBrowser->postWithToken(
            '/checkin/' . $bookingId,
            '/checkin/' . $bookingId . '/waiver',
            ['guest_id' => (string) $near, 'signature_id' => '0']
        );

        Assert::null(
            DB::value('SELECT waiver_id FROM {booking_guests} WHERE id = :id', ['id' => $near])
        );
    } finally {
        wv_clear();
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('A WAIVER FROM ANOTHER PARTY CANNOT BE MADE TO COVER THIS ONE', function () use (
    $owner,
    $deskBrowser,
    $baseUrl
): void {
    // The crafted-id version of the wrong tick this module refuses to make by
    // guessing.
    $mine = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '14:00:00',
        'guest_count'    => 4,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);
    $theirs = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '17:00:00',
        'guest_count'    => 4,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $theirNumber = (string) DB::value('SELECT booking_number FROM {bookings} WHERE id = :id', ['id' => $theirs]);

    $myGuest = DB::insert('booking_guests', [
        'booking_id'  => $mine,
        'name'        => 'WV My Child',
        'rsvp_status' => 'yes',
        'created_at'  => now_utc(),
    ]);

    $id = wv_make(['title' => 'WV cross party']);
    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-cross');

    try {
        $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'  => 'WV Their Parent',
            'signer_email' => 'wv-their@example.test',
            'booking'      => $theirNumber,
            'for_self'     => '1',
            'agree'        => '1',
        ], true);

        $theirSignature = (int) DB::value(
            'SELECT id FROM {waiver_signatures} WHERE signer_name = :n ORDER BY id DESC LIMIT 1',
            ['n' => 'WV Their Parent']
        );

        Assert::greaterThan(0, $theirSignature);

        $deskBrowser->postWithToken(
            '/checkin/' . $mine,
            '/checkin/' . $mine . '/waiver',
            ['guest_id' => (string) $myGuest, 'signature_id' => (string) $theirSignature]
        );

        Assert::null(
            DB::value('SELECT waiver_id FROM {booking_guests} WHERE id = :id', ['id' => $myGuest]),
            'a waiver signed for another party does not cover this one'
        );
    } finally {
        wv_clear();
        DB::delete('bookings', 'id IN (:a, :b)', ['a' => $mine, 'b' => $theirs]);
    }
});

Harness::test('an expired signature reads as expired rather than as covered', function () use (
    $owner,
    $deskBrowser,
    $baseUrl
): void {
    $bookingId = DB::insert('bookings', [
        'booking_number' => 'WV-' . bin2hex(random_bytes(4)),
        'type'           => 'birthday',
        'status'         => 'confirmed',
        'event_date'     => R::today(),
        'start_time'     => '14:00:00',
        'guest_count'    => 4,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);

    $number  = (string) DB::value('SELECT booking_number FROM {bookings} WHERE id = :id', ['id' => $bookingId]);
    $guestId = DB::insert('booking_guests', [
        'booking_id'  => $bookingId,
        'name'        => 'WV Expired Child',
        'rsvp_status' => 'yes',
        'created_at'  => now_utc(),
    ]);

    $id = wv_make(['title' => 'WV expiry']);
    $owner->postWithToken('/waivers/' . $id, '/waivers/' . $id . '/publish', []);

    $guest = new TestBrowser($baseUrl, 'waivers-guest-expiry');

    try {
        $guest->postWithToken('/waiver', '/waiver', [
            'signer_name'     => 'WV Expiry Parent',
            'signer_email'    => 'wv-expiry@example.test',
            'booking'         => $number,
            'minors[0][name]' => 'WV Expired Child',
            'agree'           => '1',
        ], true);

        $page = $deskBrowser->get('/checkin/' . $bookingId);

        Assert::contains('Signed', $page['body'], 'precondition: it reads as signed');

        DB::query(
            'UPDATE {waiver_signatures} SET expires_at = :past WHERE signer_name = :n',
            ['past' => A::addDays(R::today(), -1), 'n' => 'WV Expiry Parent']
        );

        $after = $deskBrowser->get('/checkin/' . $bookingId);

        Assert::contains('Expired', $after['body'], 'and an out-of-date waiver says so');
    } finally {
        wv_clear();
        DB::delete('bookings', 'id = :id', ['id' => $bookingId]);
    }
});

Harness::test('a role without checkin cannot reach the door list', function () use ($baseUrl): void {
    $password = 'waivers-marketing-passphrase-9';
    $userId   = test_make_user('marketing', [
        'email'         => 'waivers-marketing@example.test',
        'name'          => 'Marketing Mo',
        'password_hash' => Auth::hash($password),
    ]);

    try {
        $browser = new TestBrowser($baseUrl, 'waivers-marketing');
        $browser->login('waivers-marketing@example.test', $password);

        Assert::same(200, $browser->get('/account')['status'], 'precondition: signed in');
        Assert::same(403, $browser->get('/checkin')['status']);
    } finally {
        DB::delete('users', 'id = :id', ['id' => $userId]);
    }
});

// ----------------------------------------------------------------- cleanup

Harness::test('the suite tidies up after itself', function (): void {
    wv_clear();
    DB::delete('bookings', 'booking_number LIKE :p', ['p' => 'WV-%']);

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {waivers} WHERE title LIKE :p', ['p' => 'WV %'])
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {bookings} WHERE booking_number LIKE :p', ['p' => 'WV-%'])
    );
});
