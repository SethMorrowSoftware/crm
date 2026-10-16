<?php

declare(strict_types=1);

/**
 * The calendar and its iCal feed.
 *
 * THE FEED IS THE INTERESTING HALF. It is the only endpoint in the
 * application, other than one-click unsubscribe, that answers without a
 * session — a calendar application cannot sign in, so a bearer token in a URL
 * is the whole of the authentication. Everything that follows from that is
 * tested here:
 *
 *   - a wrong, absent, malformed or revoked token gets the SAME 404, so the
 *     URL reveals nothing about which tokens exist;
 *   - the token is re-checked against the live user and role on EVERY fetch,
 *     so deactivating an account or taking `view_bookings` away from a role
 *     stops the feed without anybody remembering to revoke anything;
 *   - the feed carries the time, the room and the name, and NOT the allergy
 *     note, the money or the family's contact details. That is a deliberate
 *     privacy decision about a secret that gets pasted into shared calendars,
 *     and it is the kind of decision that quietly erodes without a test.
 *
 * The month grid is checked on its boundaries — a month that starts on a
 * Sunday, one that ends on a Saturday, and the "plus one month" step from a
 * 31st, which has no obvious answer and gives 1 May if you take the obvious
 * one.
 */

use App\Controllers\CalendarController as C;
use App\Core\Auth;
use App\Core\DB;
use App\Services\AvailabilityEngine as A;
use App\Services\BookingRepository as R;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/calendar');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('calendar suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('calendar suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/** A booking written directly: this suite is about reading, not taking them. */
function cal_booking(string $date, string $start, array $overrides = []): int
{
    static $counter = 0;
    $counter++;

    return DB::insert('bookings', array_merge([
        'booking_number'      => 'CAL-' . $counter . '-' . bin2hex(random_bytes(3)),
        'type'                => 'birthday',
        'status'              => 'confirmed',
        'event_date'          => $date,
        'start_time'          => $start,
        'end_time'            => A::timeValue((int) A::minutes($start) + 120),
        'guest_count'         => 14,
        'guest_of_honor_name' => 'Calendar Guest ' . $counter,
        'allergies'           => 'Peanuts',
        'total_cents'         => 51094,
        'created_at'          => now_utc(),
        'updated_at'          => now_utc(),
    ], $overrides));
}

function cal_cleanup(): void
{
    DB::delete('bookings', 'booking_number LIKE :p', ['p' => 'CAL-%']);
}

/** The token a fresh mint flashed, pulled out of the page that shows it. */
function cal_token_from(string $body): ?string
{
    return preg_match('/token=([a-f0-9]{64})/', $body, $match) === 1 ? $match[1] : null;
}

$owner = new TestBrowser($baseUrl, 'calendar-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

$deskBrowser = new TestBrowser($baseUrl, 'calendar-desk');
$deskBrowser->login('desk@test.invalid', 'desk-test-passphrase-9');

cal_cleanup();

$saturday = (static function (): string {
    $date = A::addDays(R::today(), 14);

    for ($i = 0; $i < 7 && A::dayOfWeek($date) !== 6; $i++) {
        $date = A::addDays($date, 1);
    }

    return $date;
})();

Harness::test('the fixtures signed in', function () use ($owner, $deskBrowser): void {
    foreach (['owner' => $owner, 'desk' => $deskBrowser] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }
});

// -------------------------------------------------------------- the views

Harness::test('all three views render, and a booking appears in each', function () use ($owner, $saturday): void {
    $id = cal_booking($saturday, '14:00:00');

    try {
        foreach (['month', 'week', 'day'] as $view) {
            $response = $owner->get('/calendar?view=' . $view . '&date=' . $saturday);

            Assert::same(200, $response['status'], $view . ' should render');
            Assert::contains(
                '/bookings/' . $id . '"',
                $response['body'],
                'the booking is on the ' . $view . ' view'
            );
        }
    } finally {
        cal_cleanup();
    }
});

Harness::test('an unreadable view or date falls back rather than failing', function () use ($owner): void {
    // A hand-edited URL is the normal way these arrive.
    $junkView = $owner->get('/calendar?view=decade&date=' . R::today());

    Assert::same(200, $junkView['status']);

    $junkDate = $owner->get('/calendar?view=month&date=soon');

    Assert::same(200, $junkDate['status']);
    Assert::contains(
        substr(R::today(), 0, 4),
        $junkDate['body'],
        'and lands on the venue\'s own today'
    );
});

Harness::test('the month grid covers whole weeks, Sunday to Saturday', function () use ($owner): void {
    // The boundary cases: a month that begins on a Sunday needs no leading
    // days, one that ends on a Saturday needs no trailing ones, and the grid
    // must still be whole weeks either way. Tested through the rendered page
    // because that is where an off-by-one shows up as a missing column.
    foreach (['2026-02-01', '2026-03-01', '2026-11-01', '2027-01-01'] as $first) {
        $response = $owner->get('/calendar?view=month&date=' . $first);

        Assert::same(200, $response['status'], $first);

        // Seven column headers, however the month falls.
        Assert::same(
            7,
            preg_match_all('/<abbr title="(?:Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday)"/', $response['body']),
            $first . ' should have seven day columns'
        );

        // Every row is a whole week, so the cell count is a multiple of seven.
        $cells = preg_match_all('/class="cal-cell/', $response['body']);

        Assert::same(0, $cells % 7, $first . ' should render whole weeks, got ' . $cells . ' cells');
        Assert::true($cells >= 28 && $cells <= 42, $first . ' cells: ' . $cells);
    }
});

Harness::test('stepping a month from a 31st does not skip a month', function () use ($owner): void {
    // "31 March plus one month" has no answer, and taking the obvious one
    // gives 1 May. The step lands on the 1st for exactly that reason.
    $response = $owner->get('/calendar?view=month&date=2026-03-31');

    Assert::same(200, $response['status']);
    Assert::contains('March 2026', $response['body']);
    Assert::contains('date=2026-04-01', $response['body'], 'next is April, not May');
    Assert::contains('date=2026-02-01', $response['body'], 'and previous is February');
});

Harness::test('the day view shows every room as a lane, free or not', function () use ($owner, $saturday): void {
    // A room with nothing in it has to be visible: the point of the lane view
    // is seeing the gaps.
    $room = DB::insert('resources', [
        'name'      => 'Calendar Test Room ' . bin2hex(random_bytes(3)),
        'type'      => 'table',
        'capacity'  => 20,
        'is_active' => 1,
        'created_at' => now_utc(),
        'updated_at' => now_utc(),
    ]);

    $id = cal_booking($saturday, '15:00:00');

    DB::insert('booking_resources', [
        'booking_id'  => $id,
        'resource_id' => $room,
        'start_time'  => (string) R::localToUtc($saturday, 15 * 60),
        'end_time'    => (string) R::localToUtc($saturday, 17 * 60),
    ]);

    try {
        $response = $owner->get('/calendar?view=day&date=' . $saturday);

        Assert::same(200, $response['status']);
        Assert::contains('Calendar Test Room', $response['body'], 'the room is a lane');
        Assert::contains('/bookings/' . $id . '"', $response['body'], 'with the booking in it');
        Assert::contains('Free all day', $response['body'], 'and the empty rooms say so');
    } finally {
        cal_cleanup();
        DB::delete('resources', 'id = :id', ['id' => $room]);
    }
});

Harness::test('a cancelled booking is shown struck through, not hidden', function () use ($owner, $saturday): void {
    // The diary is a record of what was going to happen, and a cancelled
    // party still explains why a room sat empty. Struck through AND in the
    // failure colour: colour alone is not a signal.
    $id = cal_booking($saturday, '14:00:00', [
        'status'              => 'cancelled',
        'cancelled_at'        => now_utc(),
        'cancellation_reason' => 'The family cancelled',
    ]);

    try {
        $response = $owner->get('/calendar?view=month&date=' . $saturday);

        Assert::contains('/bookings/' . $id . '"', $response['body'], 'still on the calendar');
        Assert::contains('cal-off', $response['body'], 'and marked as off');
        Assert::contains('<del>', $response['body'], 'struck through, not only coloured');
    } finally {
        cal_cleanup();
    }
});

Harness::test('a role without view_bookings cannot reach the calendar', function () use ($baseUrl): void {
    $password = 'calendar-marketing-passphrase-9';
    $userId   = test_make_user('marketing', [
        'email'         => 'calendar-marketing@example.test',
        'name'          => 'Marketing Mo',
        'password_hash' => Auth::hash($password),
    ]);

    try {
        $browser = new TestBrowser($baseUrl, 'calendar-marketing');
        $browser->login('calendar-marketing@example.test', $password);

        Assert::same(200, $browser->get('/account')['status'], 'precondition: signed in');
        Assert::same(403, $browser->get('/calendar')['status'], 'the Marketing role holds no view_bookings');

        $mint = $browser->postWithToken('/account', '/calendar/feed', []);

        Assert::same(403, $mint['status'], 'nor may it mint a feed address');
    } finally {
        DB::delete('api_tokens', 'user_id = :id', ['id' => $userId]);
        DB::delete('users', 'id = :id', ['id' => $userId]);
    }
});

// ------------------------------------------------------------- the ical feed

Harness::test('minting a feed shows the token ONCE and stores only its hash', function () use ($owner): void {
    $response = $owner->postWithToken('/calendar', '/calendar/feed', [], true);

    Assert::same(200, $response['status']);

    $token = cal_token_from($response['body']);

    Assert::notNull($token, 'the address is shown once, at mint time');
    Assert::same(64, strlen((string) $token));

    $row = DB::first(
        'SELECT token_hash, abilities, name FROM {api_tokens} WHERE name = :name ORDER BY id DESC LIMIT 1',
        ['name' => C::TOKEN_NAME]
    );

    Assert::notNull($row);
    Assert::same(hash('sha256', (string) $token), (string) $row['token_hash'], 'stored hashed');
    Assert::notContains(
        (string) $token,
        (string) $row['token_hash'],
        'the token itself is not in the database'
    );
    Assert::same('["' . C::TOKEN_ABILITY . '"]', (string) $row['abilities'], 'and it may do one thing');

    // And the page afterwards cannot show it again.
    $later = $owner->get('/calendar');

    Assert::null(cal_token_from($later['body']), 'it is not recoverable from the page');
    Assert::contains('cannot be shown again', $later['body']);
});

Harness::test('THE FEED IS VALID ICAL AND CARRIES THE BOOKINGS', function () use ($owner, $saturday): void {
    $id = cal_booking($saturday, '14:00:00', ['guest_of_honor_name' => 'Zoë Calendar']);

    $mint  = $owner->postWithToken('/calendar', '/calendar/feed', [], true);
    $token = cal_token_from($mint['body']);

    Assert::notNull($token);

    try {
        $feed = $owner->get('/calendar/feed.ics?token=' . $token);

        Assert::same(200, $feed['status']);

        $body = $feed['body'];

        Assert::true(str_starts_with($body, "BEGIN:VCALENDAR\r\n"), 'CRLF line endings, as the RFC requires');
        Assert::contains('VERSION:2.0', $body);
        Assert::contains('PRODID:-//Castle CRM//Bookings//EN', $body);
        Assert::contains('BEGIN:VEVENT', $body);
        Assert::contains('UID:booking-' . $id . '@castle-crm', $body);
        Assert::contains('STATUS:CONFIRMED', $body);
        Assert::true(str_ends_with($body, "END:VCALENDAR\r\n"));

        // The start time as an absolute UTC instant, which needs no VTIMEZONE
        // block to be unambiguous. 2pm local, whatever the offset that week.
        $expected = (string) R::localToUtc($saturday, 14 * 60);
        $stamp    = str_replace([' ', '-', ':'], '', substr($expected, 0, 10))
            . 'T' . str_replace(':', '', substr($expected, 11)) . 'Z';
        $stamp    = preg_replace('/[^0-9TZ]/', '', $stamp);

        Assert::contains('DTSTART:' . $stamp, $body, 'the event is at the right instant');

        // Every content line is folded to 75 octets. A long guest name is how
        // this gets hit in practice.
        foreach (explode("\r\n", $body) as $line) {
            Assert::true(strlen($line) <= 75, 'line over 75 octets: ' . substr($line, 0, 40));
        }
    } finally {
        cal_cleanup();
    }
});

Harness::test('THE FEED DOES NOT CARRY ALLERGIES, MONEY OR CONTACT DETAILS', function () use (
    $owner,
    $saturday
): void {
    // The deliberate privacy decision, and the one most likely to erode: the
    // feed address is a bearer token that ends up in shared calendars and
    // phone backups, so what it carries has to be what a host needs to show
    // up — and no more.
    $contactId = test_make_contact([
        'first_name' => 'Parenty',
        'last_name'  => 'McCalendar',
        'email'      => 'parent-calendar@example.test',
        'phone'      => '(845) 555-0133',
    ]);

    $id = cal_booking($saturday, '14:00:00', [
        'contact_id'    => $contactId,
        'allergies'     => 'SECRETPEANUTALLERGY',
        'dietary_notes' => 'SECRETDIETARY',
        'total_cents'   => 123456,
        'internal_notes' => 'SECRETINTERNAL',
    ]);

    $mint  = $owner->postWithToken('/calendar', '/calendar/feed', [], true);
    $token = cal_token_from($mint['body']);

    try {
        $body = $owner->get('/calendar/feed.ics?token=' . $token)['body'];

        Assert::contains('BEGIN:VEVENT', $body, 'precondition: the booking IS in the feed');

        foreach ([
            'SECRETPEANUTALLERGY' => 'the allergy note',
            'SECRETDIETARY'       => 'the dietary note',
            'SECRETINTERNAL'      => 'the internal notes',
            'McCalendar'          => 'the family\'s surname',
            'parent-calendar@example.test' => 'their email address',
            '555-0133'            => 'their phone number',
            '1234.56'             => 'the figure',
        ] as $needle => $what) {
            Assert::notContains($needle, $body, $what . ' must not be in an unauthenticated feed');
        }
    } finally {
        cal_cleanup();
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('EVERY BAD TOKEN GETS THE SAME ANSWER', function () use ($owner): void {
    // The same 404 whether the token is absent, malformed, the wrong length or
    // simply unknown, so the URL cannot be used to learn which tokens exist.
    $answers = [];

    foreach ([
        '',
        'short',
        str_repeat('z', 64),
        str_repeat('a', 63),
        str_repeat('a', 65),
        bin2hex(random_bytes(32)),
        '../../etc/passwd',
    ] as $attempt) {
        $response = $owner->get('/calendar/feed.ics?token=' . urlencode($attempt));

        $answers[] = $response['status'] . ' ' . trim($response['body']);
    }

    Assert::count(1, array_unique($answers), 'one answer for every refusal: ' . implode(' | ', array_unique($answers)));
    Assert::contains('404', $answers[0]);
});

Harness::test('REVOKING A FEED STOPS IT AT ONCE', function () use ($owner): void {
    $mint  = $owner->postWithToken('/calendar', '/calendar/feed', [], true);
    $token = cal_token_from($mint['body']);

    Assert::notNull($token);
    Assert::same(200, $owner->get('/calendar/feed.ics?token=' . $token)['status'], 'precondition');

    $owner->postWithToken('/calendar', '/calendar/feed/revoke', []);

    Assert::same(
        404,
        $owner->get('/calendar/feed.ics?token=' . $token)['status'],
        'and the old address is dead'
    );
});

Harness::test('re-minting replaces the old address rather than adding a second', function () use ($owner): void {
    $first  = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);
    $second = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    Assert::notNull($first);
    Assert::notNull($second);
    Assert::notSame($first, $second);

    Assert::same(404, $owner->get('/calendar/feed.ics?token=' . $first)['status'], 'the old one is dead');
    Assert::same(200, $owner->get('/calendar/feed.ics?token=' . $second)['status'], 'the new one works');

    $ownerId = (int) DB::value("SELECT id FROM {users} WHERE email = 'owner@test.invalid'");

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {api_tokens} WHERE user_id = :uid AND name = :name',
            ['uid' => $ownerId, 'name' => C::TOKEN_NAME]
        ),
        'one feed address per person'
    );
});

Harness::test('THE FEED IS RE-CHECKED AGAINST THE LIVE ACCOUNT ON EVERY FETCH', function () use (
    $baseUrl
): void {
    // So deactivating somebody, or taking view_bookings off their role, stops
    // their feed without anybody remembering to revoke a token. A permission
    // stamped into the token at mint time would not do this.
    $password = 'calendar-host-passphrase-9';
    $userId   = test_make_user('party_host', [
        'email'         => 'calendar-host@example.test',
        'name'          => 'Host Harriet',
        'password_hash' => Auth::hash($password),
    ]);

    $browser = new TestBrowser($baseUrl, 'calendar-host');
    $browser->login('calendar-host@example.test', $password);

    $token = cal_token_from($browser->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    Assert::notNull($token, 'a party host may have a feed');

    try {
        Assert::same(200, $browser->get('/calendar/feed.ics?token=' . $token)['status'], 'precondition');

        // Deactivated.
        DB::update('users', ['is_active' => 0], 'id = :id', ['id' => $userId]);

        Assert::same(
            404,
            $browser->get('/calendar/feed.ics?token=' . $token)['status'],
            'a deactivated account\'s feed stops'
        );

        DB::update('users', ['is_active' => 1], 'id = :id', ['id' => $userId]);

        Assert::same(200, $browser->get('/calendar/feed.ics?token=' . $token)['status'], 'and comes back');

        // Role stripped of view_bookings.
        $roleId = (int) DB::value('SELECT role_id FROM {users} WHERE id = :id', ['id' => $userId]);
        $permId = (int) DB::value("SELECT id FROM {permissions} WHERE slug = 'view_bookings'");
        $scope  = (string) DB::value(
            'SELECT scope FROM {role_permissions} WHERE role_id = :r AND permission_id = :p',
            ['r' => $roleId, 'p' => $permId]
        );

        DB::delete(
            'role_permissions',
            'role_id = :r AND permission_id = :p',
            ['r' => $roleId, 'p' => $permId]
        );

        try {
            Assert::same(
                404,
                $browser->get('/calendar/feed.ics?token=' . $token)['status'],
                'a role that can no longer see bookings has no feed either'
            );
        } finally {
            DB::insert('role_permissions', [
                'role_id'       => $roleId,
                'permission_id' => $permId,
                'scope'         => $scope === '' ? 'all' : $scope,
            ]);
        }

        Assert::same(
            200,
            $browser->get('/calendar/feed.ics?token=' . $token)['status'],
            'and it comes back when the permission does'
        );
    } finally {
        DB::update('users', ['is_active' => 1], 'id = :id', ['id' => $userId]);
        DB::delete('api_tokens', 'user_id = :id', ['id' => $userId]);
        DB::delete('users', 'id = :id', ['id' => $userId]);
    }
});

Harness::test('an expired token does not work', function () use ($owner): void {
    $token = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    Assert::notNull($token);

    DB::update(
        'api_tokens',
        ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)],
        'token_hash = :h',
        ['h' => hash('sha256', (string) $token)]
    );

    Assert::same(404, $owner->get('/calendar/feed.ics?token=' . $token)['status']);
});

Harness::test('a booking with no start time is left out rather than placed at midnight', function () use (
    $owner,
    $saturday
): void {
    // A phantom party at the top of somebody's day is worse than a missing
    // one: they would go looking for it.
    $id = cal_booking($saturday, '14:00:00', ['start_time' => null, 'end_time' => null]);

    $token = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    try {
        $body = $owner->get('/calendar/feed.ics?token=' . $token)['body'];

        Assert::notContains('UID:booking-' . $id . '@', $body);
        Assert::notContains('T000000Z', $body, 'and nothing is placed at midnight');
    } finally {
        cal_cleanup();
    }
});

Harness::test('a cancelled booking is published as cancelled, not dropped', function () use (
    $owner,
    $saturday
): void {
    // So a subscriber's calendar removes it rather than keeping a stale copy
    // for ever.
    $id = cal_booking($saturday, '14:00:00', ['status' => 'cancelled', 'cancelled_at' => now_utc()]);

    $token = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    try {
        $body = $owner->get('/calendar/feed.ics?token=' . $token)['body'];

        Assert::contains('UID:booking-' . $id . '@castle-crm', $body);
        Assert::contains('STATUS:CANCELLED', $body);
    } finally {
        cal_cleanup();
    }
});

Harness::test('A LONG LINE IS FOLDED, AND A MULTIBYTE CHARACTER IS NOT SPLIT', function () use (
    $owner,
    $saturday
): void {
    // Folding at 75 OCTETS is required by RFC 5545, and a client that rejects
    // the file gives no reason — so it is not optional. The earlier "every
    // line is under 75" assertion was vacuous: no fixture produced a line
    // long enough to fold. MEASURED — removing the folding left the suite
    // green until this test existed.
    //
    // The multibyte half matters because the limit is on BYTES: splitting a
    // character across a fold produces a line no client can read, and a guest
    // of honour called Zoë is enough to hit it. The name is built so the
    // two-byte 'ë' straddles the 75-octet boundary.
    $padding = str_repeat('e', 60);
    $name    = $padding . 'Zoë' . str_repeat('x', 40);

    $id = cal_booking($saturday, '14:00:00', ['guest_of_honor_name' => $name]);

    $token = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    try {
        $body  = $owner->get('/calendar/feed.ics?token=' . $token)['body'];
        $lines = explode("\r\n", $body);

        foreach ($lines as $line) {
            Assert::true(strlen($line) <= 75, 'line over 75 octets: ' . substr($line, 0, 30));
        }

        $folded = array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && $line[0] === ' '
        ));

        Assert::notEmpty($folded, 'a name this long must actually have been folded');

        // Unfolding — drop the CRLF and the single leading space — has to give
        // the name back exactly, accented character included.
        $unfolded = str_replace("\r\n ", '', $body);

        Assert::contains($padding . 'Zoë', $unfolded, 'the character survived the fold');
        Assert::notContains("\xc3\r", $body, 'no lead byte is left stranded at a fold');
        Assert::notContains("\r\n\xab", $body, 'and no continuation byte starts a line');
    } finally {
        cal_cleanup();
    }
});

Harness::test('text that would break the format is escaped', function () use ($owner, $saturday): void {
    // A semicolon, a comma and a newline each end a property value in RFC
    // 5545. A guest of honour called "Ava, Mia; and co" is enough to produce
    // a file no calendar can read.
    $id = cal_booking($saturday, '14:00:00', [
        'guest_of_honor_name' => 'Ava, Mia; co',
    ]);

    $token = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    try {
        $body = $owner->get('/calendar/feed.ics?token=' . $token)['body'];

        Assert::contains('Ava\\, Mia\\; co', $body, 'escaped, not raw');
        Assert::notContains('SUMMARY:Ava, Mia; co', $body);
    } finally {
        cal_cleanup();
    }
});

Harness::test('the feed is not indexed and not cached', function () use ($owner): void {
    $token = cal_token_from($owner->postWithToken('/calendar', '/calendar/feed', [], true)['body']);

    $response = $owner->get('/calendar/feed.ics?token=' . $token);

    // TestBrowser hands back the raw header block, not an array — asserting
    // against `$response['headers']['content-type']` silently compares an
    // empty string, which is how this test first passed for nothing.
    $headers = strtolower((string) $response['headers']);

    Assert::same(200, $response['status']);
    Assert::contains('content-type: text/calendar', $headers);
    Assert::contains('x-robots-tag: noindex', $headers);
    Assert::contains('cache-control: no-store', $headers);
    Assert::contains('content-disposition: inline', $headers, 'shown, not downloaded');
});

// ----------------------------------------------------------------- cleanup

Harness::test('the suite tidies up after itself', function (): void {
    cal_cleanup();

    DB::delete('api_tokens', 'name = :name', ['name' => C::TOKEN_NAME]);

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {bookings} WHERE booking_number LIKE :p', ['p' => 'CAL-%'])
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {api_tokens} WHERE name = :name', ['name' => C::TOKEN_NAME]),
        'and no feed address is left live'
    );
});
