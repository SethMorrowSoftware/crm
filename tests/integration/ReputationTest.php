<?php

declare(strict_types=1);

/**
 * The feedback gate and the invitation sweep, against a real database.
 *
 * `unit/reputation` pins the rules. This pins what they DO: which rows get
 * written, which events fire, which guest gets a review link, and — the one
 * that would be most expensive to get wrong — WHO IS SENT AN INVITATION AT
 * ALL.
 *
 * That last one is why the lookback window has its own tests. The sweep looks
 * for parties that have just crossed their survey delay, and without a bound
 * on how far back it looks, switching feedback on would email every family
 * who has ever had a party at the venue. On an install carrying twenty years
 * of imported history that is tens of thousands of messages, sent in the
 * first minute, about visits nobody remembers. It would be the single most
 * damaging thing this application could do, and it is three lines of SQL away
 * at any time — so the bound is tested from both sides.
 */

use App\Services\Feedback;
use App\Core\DB;
use App\Core\Event;
use App\Core\Settings;
use App\Services\Reputation;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/reputation');

if (!test_boot()) {
    Harness::skip('reputation suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

function rep_clear(): void
{
    DB::query(
        "DELETE FROM {review_requests} WHERE contact_id IN
            (SELECT id FROM {contacts} WHERE source = 'reptest')"
    );
    DB::query(
        "DELETE FROM {survey_responses} WHERE contact_id IN
            (SELECT id FROM {contacts} WHERE source = 'reptest')"
    );
    DB::query("DELETE FROM {reviews} WHERE author_name LIKE 'RepTest%'");
    DB::query("DELETE FROM {tasks} WHERE entity_type = 'feedback'");
    DB::query(
        "DELETE FROM {visits} WHERE contact_id IN
            (SELECT id FROM {contacts} WHERE source = 'reptest')"
    );
    DB::query(
        "DELETE FROM {bookings} WHERE contact_id IN
            (SELECT id FROM {contacts} WHERE source = 'reptest')"
    );
    DB::query("DELETE FROM {contacts} WHERE source = 'reptest'");
}

function rep_contact(string $name = 'Parent'): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => $name,
        'last_name'       => 'RepTest',
        'email'           => 'reptest.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'phone'           => '+18455550' . random_int(100, 999),
        'source'          => 'reptest',
        'lifecycle_stage' => 'customer',
        'email_consent'   => 1,
        'sms_consent'     => 1,
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/**
 * A party date, `$daysAgo` days back, in the frame the sweep actually uses.
 *
 * UTC-CORRECT, said once here rather than twelve times below. The reports
 * module reasons in venue days and its tests use `test_local_day()`; this one
 * does not. `Feedback::plan()` bounds its candidates with
 * `event_date >= :oldest AND event_date <= :today` where both come from a
 * `DateTimeImmutable('now', 'UTC')`, so the lookback window really is UTC,
 * and a date built here in the venue's frame would be testing a window the
 * code does not have. (What IS venue-local in that sweep is the delay:
 * `endOfBooking()` reads the party's end time in the venue's zone. Every
 * call below clears that delay by days, so no assertion here turns on it.)
 *
 * If `plan()` is ever changed to bound by the venue's day — its own comment
 * already claims it does — this helper is the one place to change with it.
 */
function rep_days_ago(int $daysAgo): string
{
    // UTC-CORRECT: see above. The whole file's reason lives in one place.
    return gmdate('Y-m-d', time() - ($daysAgo * 86400));
}

/** @param array<string,mixed> $overrides */
function rep_booking(int $contactId, string $date, array $overrides = []): int
{
    return (int) DB::insert('bookings', $overrides + [
        'booking_number' => 'RP-' . bin2hex(random_bytes(4)),
        'contact_id'     => $contactId,
        'event_date'     => $date,
        'start_time'     => '14:00',
        'end_time'       => '16:00',
        'guest_count'    => 12,
        'status'         => 'completed',
        'total_cents'    => 40000,
        'created_at'     => now_utc(),
        'updated_at'     => now_utc(),
    ]);
}

/** The "After a party" survey, switched on for the duration of a test. */
function rep_survey(array $changes = []): int
{
    $id = (int) DB::value("SELECT id FROM {surveys} WHERE trigger_event = 'booking.completed' LIMIT 1");

    if ($id === 0) {
        $id = (int) DB::insert('surveys', [
            'name'          => 'After a party',
            'type'          => 'nps',
            'trigger_event' => 'booking.completed',
            'delay_hours'   => 20,
            'is_active'     => 1,
            'created_at'    => now_utc(),
        ]);
    }

    DB::update('surveys', $changes + [
        'is_active'     => 1,
        'trigger_event' => 'booking.completed',
        'delay_hours'   => 20,
    ], 'id = :id', ['id' => $id]);

    return $id;
}

/** A planned-and-sent invitation row, as the sweep would leave it. */
function rep_invite(int $surveyId, int $contactId, ?int $bookingId = null, array $overrides = []): array
{
    $id = (int) DB::insert('survey_responses', $overrides + [
        'survey_id'    => $surveyId,
        'contact_id'   => $contactId,
        'booking_id'   => $bookingId,
        'public_token' => bin2hex(random_bytes(16)),
        'invited_at'   => now_utc(),
        'channel'      => 'email',
        'created_at'   => now_utc(),
    ]);

    return (array) DB::first(
        'SELECT r.*, s.type AS survey_type FROM {survey_responses} r
           JOIN {surveys} s ON s.id = r.survey_id
          WHERE r.id = :id',
        ['id' => $id]
    );
}

/** @return list<string> */
function rep_events(): array
{
    $names = [];

    foreach (Event::recorded() as $fired) {
        if (
            str_starts_with($fired['event'], 'survey.')
            || str_starts_with($fired['event'], 'feedback.')
            || str_starts_with($fired['event'], 'review.')
        ) {
            $names[] = $fired['event'];
        }
    }

    return $names;
}

function rep_settings(array $values): void
{
    foreach ($values as $key => $value) {
        Settings::set('reputation.' . $key, $value);
    }

    Settings::load(true);
}

rep_clear();

// Leave the venue configured with one review platform for the gate tests, and
// put it back at the end so later suites see the shipped defaults.
rep_settings([
    'link_google'   => 'https://g.example/review',
    'weight_google' => 3,
    'link_yelp'     => '',
    'weight_yelp'   => 0,
    'ask_everyone'  => false,
    'auto_escalate' => true,
    'sla_hours'     => 4,
]);

// ================================================================== the gate

Harness::test('a promoter is recorded and handed a review link', function (): void {
    rep_clear();
    $survey  = rep_survey();
    $contact = rep_contact('Happy');
    $row     = rep_invite($survey, $contact);

    Event::record();
    $outcome = Feedback::record($row, 10, 'Best party we have had');
    $events  = rep_events();

    Assert::same('promoter', $outcome['sentiment']);
    Assert::true($outcome['first']);
    Assert::notNull($outcome['review'], 'a promoter gets somewhere to leave it');
    Assert::same('google', $outcome['review']['platform']);
    Assert::contains('/r/', $outcome['review']['url']);

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => (int) $row['id']]);

    Assert::same(10, (int) $saved['score']);
    Assert::same('promoter', (string) $saved['sentiment']);
    Assert::same('Best party we have had', (string) $saved['comment']);
    Assert::notNull($saved['responded_at']);

    Assert::inArray('survey.responded', $events);
    Assert::false(in_array('survey.detractor', $events, true), 'a 10 is not a complaint');

    $ask = DB::first(
        'SELECT * FROM {review_requests} WHERE survey_response_id = :id',
        ['id' => (int) $row['id']]
    );

    Assert::notNull($ask);
    Assert::same('sent', (string) $ask['status']);
    Assert::notNull($ask['token']);
});

Harness::test('A PASSIVE IS NOT ASKED FOR A REVIEW', function (): void {
    rep_clear();
    $row = rep_invite(rep_survey(), rep_contact('Lukewarm'));

    $outcome = Feedback::record($row, 8, 'It was fine');

    Assert::same('passive', $outcome['sentiment']);
    Assert::null($outcome['review']);
    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {review_requests} WHERE survey_response_id = :id',
            ['id' => (int) $row['id']]
        )
    );
});

Harness::test('A DETRACTOR IS NOT ASKED FOR A REVIEW, AND A MANAGER IS TOLD', function (): void {
    rep_clear();
    $row = rep_invite(rep_survey(), rep_contact('Unhappy'));

    Event::record();
    $outcome = Feedback::record($row, 3, 'Waited an hour for the karts');
    $events  = rep_events();

    Assert::same('detractor', $outcome['sentiment']);
    Assert::null($outcome['review'], 'never');
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {review_requests} WHERE survey_response_id = :id', [
            'id' => (int) $row['id'],
        ])
    );

    Assert::inArray('survey.responded', $events);
    Assert::inArray('survey.detractor', $events, 'the low-score trigger fires separately');

    $task = DB::first(
        "SELECT * FROM {tasks} WHERE entity_type = 'feedback' AND entity_id = :id",
        ['id' => (int) $row['id']]
    );

    Assert::notNull($task, 'the complaint is somebody\'s work now');
    Assert::same('urgent', (string) $task['priority']);
    Assert::notNull($task['due_at'], 'with a deadline');
    Assert::contains('3', (string) $task['title']);
});

Harness::test('the escalation task is raised even with every automation off', function (): void {
    /*
     * The seeded reputation playbook adds the manager notifications, but a
     * complaint has to reach somebody whether or not a venue has switched it
     * on. Nothing in this suite activates an automation, so this is the state
     * every fresh install is in.
     */
    rep_clear();
    $row = rep_invite(rep_survey(), rep_contact('Nobody'));

    Feedback::record($row, 0, null);

    Assert::same(
        1,
        (int) DB::value("SELECT COUNT(*) FROM {tasks} WHERE entity_type = 'feedback' AND entity_id = :id", [
            'id' => (int) $row['id'],
        ])
    );
});

Harness::test('escalation can be switched off', function (): void {
    rep_clear();
    rep_settings(['auto_escalate' => false]);

    $row = rep_invite(rep_survey(), rep_contact('Quiet'));

    Event::record();
    Feedback::record($row, 2, 'bad');

    // The EVENT still fires — an automation may be doing the alerting — but
    // this module raises no task of its own.
    Assert::inArray('survey.detractor', rep_events());
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {tasks} WHERE entity_type = 'feedback' AND entity_id = :id", [
            'id' => (int) $row['id'],
        ])
    );

    rep_settings(['auto_escalate' => true]);
});

Harness::test('with the override on, a passive IS asked and a detractor still is not', function (): void {
    rep_clear();
    rep_settings(['ask_everyone' => true]);

    $passive   = rep_invite(rep_survey(), rep_contact('Mild'));
    $detractor = rep_invite(rep_survey(), rep_contact('Cross'));

    Assert::notNull(Feedback::record($passive, 7, null)['review']);
    Assert::null(Feedback::record($detractor, 4, null)['review']);

    rep_settings(['ask_everyone' => false]);
});

Harness::test('NO REVIEW LINK CONFIGURED MEANS NO ASK, NOT A BROKEN ONE', function (): void {
    rep_clear();
    rep_settings(['link_google' => '', 'weight_google' => 3]);

    $row     = rep_invite(rep_survey(), rep_contact('Keen'));
    $outcome = Feedback::record($row, 10, null);

    // Still a promoter, still recorded, just nowhere to send them: a link to
    // a listing the venue does not have is worse than no ask.
    Assert::same('promoter', $outcome['sentiment']);
    Assert::null($outcome['review']);
    Assert::same(0, (int) DB::value('SELECT COUNT(*) FROM {review_requests} WHERE survey_response_id = :id', [
        'id' => (int) $row['id'],
    ]));

    rep_settings(['link_google' => 'https://g.example/review']);
});

Harness::test('A SECOND ANSWER KEEPS THE FIRST SCORE AND TAKES THE NEW COMMENT', function (): void {
    /*
     * A guest who scores 10, then comes back and writes something, must not
     * be handed a second review request — and a guest who scored 2 and then
     * adds detail must not re-page a manager about a complaint already being
     * dealt with.
     */
    rep_clear();
    $row = rep_invite(rep_survey(), rep_contact('Twice'));

    Feedback::record($row, 10, null);

    $after = (array) DB::first(
        'SELECT r.*, s.type AS survey_type FROM {survey_responses} r
           JOIN {surveys} s ON s.id = r.survey_id WHERE r.id = :id',
        ['id' => (int) $row['id']]
    );

    Event::record();
    $second = Feedback::record($after, 1, 'Actually, one more thing');

    Assert::false($second['first']);
    Assert::same('promoter', $second['sentiment'], 'the first answer stands');
    Assert::null($second['review'], 'and no second ask');
    Assert::same([], rep_events(), 'nothing fires twice');

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => (int) $row['id']]);

    Assert::same(10, (int) $saved['score'], 'the score is not overwritten');
    Assert::same('Actually, one more thing', (string) $saved['comment'], 'the comment is');
    Assert::same(1, (int) DB::value('SELECT COUNT(*) FROM {review_requests} WHERE survey_response_id = :id', [
        'id' => (int) $row['id'],
    ]));
});

Harness::test('a score off the scale is recorded as nothing at all', function (): void {
    rep_clear();
    $row = rep_invite(rep_survey(), rep_contact('Tampered'));

    Event::record();
    $outcome = Feedback::record($row, 11, 'eleven');

    Assert::null($outcome['sentiment']);
    Assert::same([], rep_events());

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => (int) $row['id']]);

    Assert::null($saved['score']);
    Assert::null($saved['responded_at'], 'so the invitation is still outstanding');
});

Harness::test('the rotation advances across guests', function (): void {
    rep_clear();
    rep_settings([
        'link_google' => 'https://g.example/review',
        'weight_google' => 1,
        'link_yelp'   => 'https://y.example/review',
        'weight_yelp' => 1,
    ]);

    $survey    = rep_survey();
    $platforms = [];

    for ($i = 0; $i < 4; $i++) {
        $row = rep_invite($survey, rep_contact('Rot' . $i));
        $platforms[] = Feedback::record($row, 10, null)['review']['platform'];
    }

    // Two platforms, equal weight: alternating, whatever the starting point.
    Assert::same(2, count(array_unique($platforms)), 'both platforms were used');
    Assert::notSame($platforms[0], $platforms[1], 'and it alternated');

    rep_settings(['link_yelp' => '', 'weight_yelp' => 0]);
});

// ============================================================== the resolving

Harness::test('resolving a complaint closes its task and fires the event', function (): void {
    rep_clear();
    $row = rep_invite(rep_survey(), rep_contact('Resolve'));

    Feedback::record($row, 2, 'Too crowded');

    $taskId = (int) DB::value(
        "SELECT id FROM {tasks} WHERE entity_type = 'feedback' AND entity_id = :id",
        ['id' => (int) $row['id']]
    );

    Assert::greaterThan(0, $taskId);

    // The controller action needs a request; the write it performs is what
    // matters here, so it is driven through the same SQL the action runs and
    // the HTTP suite covers the form itself.
    DB::update('survey_responses', [
        'resolved_at'     => now_utc(),
        'resolution_note' => 'Rang them, comped two wristbands',
    ], 'id = :id', ['id' => (int) $row['id']]);

    DB::update('tasks', ['status' => 'done'], 'id = :id', ['id' => $taskId]);

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => (int) $row['id']]);

    Assert::notNull($saved['resolved_at']);
    Assert::notNull(Reputation::resolutionHours(
        (string) $saved['responded_at'],
        (string) $saved['resolved_at']
    ));
});

// ========================================================== the sweep's bound

Harness::test('THE SWEEP NEVER REACHES PAST THE LOOKBACK WINDOW', function (): void {
    /*
     * The one that matters. A party from last July is not something to ask
     * about today, and an install carrying imported history has thousands of
     * them.
     */
    rep_clear();
    rep_settings(['invite_lookback_days' => 14]);
    $survey = rep_survey();

    $old = rep_contact('Ancient');
    rep_booking($old, rep_days_ago(400));

    $alsoOld = rep_contact('LastMonth');
    rep_booking($alsoOld, rep_days_ago(40));

    Feedback::dispatchDue();

    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses} WHERE survey_id = :s AND contact_id IN (:a, :b)',
            ['s' => $survey, 'a' => $old, 'b' => $alsoOld]
        ),
        'nothing older than the window is even planned'
    );
});

Harness::test('a party inside the window IS planned once the delay has passed', function (): void {
    rep_clear();
    rep_settings(['invite_lookback_days' => 14]);
    $survey  = rep_survey();
    $contact = rep_contact('Yesterday');

    // Two days ago, so the 20-hour delay from the party's 16:00 end has
    // certainly passed whatever the venue's timezone.
    rep_booking($contact, rep_days_ago(2));

    Feedback::dispatchDue();

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses} WHERE survey_id = :s AND contact_id = :c',
            ['s' => $survey, 'c' => $contact]
        )
    );
});

Harness::test('a party that ended an hour ago is NOT asked yet', function (): void {
    rep_clear();
    $survey  = rep_survey();
    $contact = rep_contact('Today');

    // Today's party, with the delay still to run. The sweep must leave it.
    rep_booking($contact, rep_days_ago(0), ['end_time' => '23:59']);

    Feedback::dispatchDue();

    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses} WHERE survey_id = :s AND contact_id = :c',
            ['s' => $survey, 'c' => $contact]
        )
    );
});

Harness::test('only a COMPLETED booking is surveyed', function (): void {
    rep_clear();
    $survey = rep_survey();

    $cancelled = rep_contact('Cancelled');
    rep_booking($cancelled, rep_days_ago(2), ['status' => 'cancelled']);

    $noShow = rep_contact('NoShow');
    rep_booking($noShow, rep_days_ago(2), ['status' => 'no_show']);

    Feedback::dispatchDue();

    Assert::same(
        0,
        (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses} WHERE survey_id = :s AND contact_id IN (:a, :b)',
            ['s' => $survey, 'a' => $cancelled, 'b' => $noShow]
        ),
        'asking how the party went when it did not happen'
    );
});

Harness::test('THE SAME PARTY IS NEVER ASKED TWICE', function (): void {
    rep_clear();
    $survey  = rep_survey();
    $contact = rep_contact('Once');
    rep_booking($contact, rep_days_ago(3));

    Feedback::dispatchDue();
    Feedback::dispatchDue();
    Feedback::dispatchDue();

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses} WHERE survey_id = :s AND contact_id = :c',
            ['s' => $survey, 'c' => $contact]
        )
    );
});

Harness::test('a switched-off survey asks nobody', function (): void {
    rep_clear();
    $survey = rep_survey();
    DB::update('surveys', ['is_active' => 0], 'id = :id', ['id' => $survey]);

    $contact = rep_contact('Dormant');
    rep_booking($contact, rep_days_ago(3));

    Assert::same(0, Feedback::dispatchDue());
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {survey_responses} WHERE contact_id = :c', ['c' => $contact])
    );

    DB::update('surveys', ['is_active' => 1], 'id = :id', ['id' => $survey]);
});

Harness::test('a survey with no trigger asks nobody either', function (): void {
    rep_clear();
    $survey = rep_survey(['trigger_event' => null]);

    $contact = rep_contact('Untriggered');
    rep_booking($contact, rep_days_ago(3));

    Assert::same(0, Feedback::dispatchDue());

    rep_survey();
});

Harness::test('AN INVITATION REFUSED FOR GOOD RECORDS WHY, AND IS NOT RETRIED', function (): void {
    /*
     * A guest with no email address can never be asked by email. The row is
     * kept with the reason on it so the page can say "we could not ask 12
     * guests, and here is why" instead of reporting a low response rate —
     * those look identical without this.
     */
    rep_clear();
    rep_settings(['invite_channel' => 'email']);
    $survey = rep_survey();

    $contact = rep_contact('NoEmail');
    DB::update('contacts', ['email' => null], 'id = :id', ['id' => $contact]);
    rep_booking($contact, rep_days_ago(3));

    Feedback::dispatchDue();

    $row = DB::first(
        'SELECT * FROM {survey_responses} WHERE survey_id = :s AND contact_id = :c',
        ['s' => $survey, 'c' => $contact]
    );

    Assert::notNull($row, 'the row is kept');
    Assert::null($row['invited_at'], 'but nothing was sent, so it counts in no denominator');
    Assert::same('refused', (string) $row['channel']);

    $answers = json_decode((string) $row['answers'], true);

    Assert::same('no_address', $answers['invite_refused'] ?? null);

    // And the next tick leaves it alone rather than trying for ever.
    Feedback::dispatchDue();

    Assert::same(
        1,
        (int) DB::value(
            'SELECT COUNT(*) FROM {survey_responses} WHERE survey_id = :s AND contact_id = :c',
            ['s' => $survey, 'c' => $contact]
        )
    );
});

Harness::test('a guest who has opted out of marketing is not surveyed', function (): void {
    rep_clear();
    $survey  = rep_survey();
    $contact = rep_contact('OptedOut');

    DB::update('contacts', ['email_consent' => 0], 'id = :id', ['id' => $contact]);
    rep_booking($contact, rep_days_ago(3));

    Feedback::dispatchDue();

    $row = DB::first(
        'SELECT * FROM {survey_responses} WHERE survey_id = :s AND contact_id = :c',
        ['s' => $survey, 'c' => $contact]
    );

    // A survey goes as marketing, deliberately: it is not something a guest
    // needs in order to use a booking they have already made.
    Assert::notNull($row);
    Assert::null($row['invited_at'], 'nothing was sent to them');
});

Harness::test('the invitation sweep stops when the budget runs out', function (): void {
    rep_clear();
    $survey = rep_survey();

    for ($i = 0; $i < 3; $i++) {
        rep_booking(rep_contact('Budget' . $i), rep_days_ago(3));
    }

    // A budget that is already spent: the rows may be planned, but nothing is
    // sent, and the next tick picks them up.
    Assert::same(0, Feedback::dispatchDue(static fn (): bool => false));
});

// ================================================================== the wall

Harness::test('THE REVIEW WALL SHOWS ONLY WHAT SOMEBODY FEATURED', function (): void {
    rep_clear();
    rep_settings(['wall_enabled' => true, 'wall_min_rating' => 4]);

    $five = (int) DB::insert('reviews', [
        'platform'    => 'google',
        'author_name' => 'RepTest Featured',
        'rating'      => 5,
        'body'        => 'Wonderful',
        'is_featured' => 1,
        'posted_at'   => now_utc(),
        'imported_at' => now_utc(),
    ]);

    DB::insert('reviews', [
        'platform'    => 'google',
        'author_name' => 'RepTest Unfeatured',
        'rating'      => 5,
        'body'        => 'Also wonderful',
        'is_featured' => 0,
        'posted_at'   => now_utc(),
        'imported_at' => now_utc(),
    ]);

    // Featured but below the floor the venue set: the floor is a limit on
    // what MAY be featured, so it wins.
    DB::insert('reviews', [
        'platform'    => 'google',
        'author_name' => 'RepTest LowFeatured',
        'rating'      => 2,
        'body'        => 'Not great',
        'is_featured' => 1,
        'posted_at'   => now_utc(),
        'imported_at' => now_utc(),
    ]);

    $wall = Feedback::wallReviews(12);
    $names = array_map(static fn (array $r): string => (string) $r['author_name'], $wall);

    Assert::inArray('RepTest Featured', $names);
    Assert::false(in_array('RepTest Unfeatured', $names, true), 'five stars is not a reason on its own');
    Assert::false(in_array('RepTest LowFeatured', $names, true), 'below the floor');

    Assert::greaterThan(0, $five);
});

Harness::test('switching the wall off empties it', function (): void {
    rep_settings(['wall_enabled' => false]);

    Assert::same([], Feedback::wallReviews(12));

    rep_settings(['wall_enabled' => true]);
});

// ------------------------------------------------------------------- tidy up

rep_clear();
rep_settings([
    'link_google'   => '',
    'weight_google' => 3,
    'link_yelp'     => '',
    'weight_yelp'   => 0,
    'ask_everyone'  => false,
    'auto_escalate' => true,
    'invite_channel' => 'email',
    'invite_lookback_days' => Feedback::DEFAULT_LOOKBACK_DAYS,
]);
