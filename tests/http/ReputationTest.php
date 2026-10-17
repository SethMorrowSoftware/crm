<?php

declare(strict_types=1);

/**
 * Feedback and reviews, over HTTP.
 *
 * Two halves, and the guest's half is the one that matters most: a parent
 * with no account, on a phone, following a link out of an email. There is no
 * session to lean on, the token in the path IS the authorisation, and
 * everything about the page has to work on one tap.
 *
 * What is pinned here that nothing else can pin:
 *
 *   * A HAPPY GUEST IS SHOWN A REVIEW LINK AND AN UNHAPPY ONE IS NOT. Both
 *     answer the same form at the same URL, so the difference is entirely in
 *     what comes back — which means it has to be checked in the HTML a guest
 *     actually receives, not in a return value.
 *   * THE REVIEW REDIRECT GOES WHERE THE VENUE SAID. `/r/{token}` is the one
 *     endpoint in this application that sends a visitor to another site, and
 *     the destination comes from settings rather than from the request. A
 *     test that only checked it redirected would pass just as happily with an
 *     open redirect.
 *   * THE WALL IS FRAMEABLE AND THE REST IS NOT. The review wall exists to be
 *     embedded on the venue's website, so it carries its own
 *     `frame-ancestors` and no `X-Frame-Options` — and every other public
 *     page here must still be noindex and uncached.
 *   * READING IT NEEDS A PERMISSION. The page names guests and prints what
 *     they said about the staff.
 */

use App\Core\DB;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/reputation');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('reputation suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('reputation suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

function hrep_clear(): void
{
    DB::query(
        "DELETE FROM {review_requests} WHERE contact_id IN
            (SELECT id FROM {contacts} WHERE source = 'hreptest')"
    );
    DB::query(
        "DELETE FROM {survey_responses} WHERE contact_id IN
            (SELECT id FROM {contacts} WHERE source = 'hreptest')"
    );
    DB::query("DELETE FROM {reviews} WHERE author_name LIKE 'HRep%'");
    DB::query("DELETE FROM {tasks} WHERE entity_type = 'feedback'");
    DB::query("DELETE FROM {contacts} WHERE source = 'hreptest'");
}

function hrep_contact(string $name): int
{
    return (int) DB::insert('contacts', [
        'first_name'      => $name,
        'last_name'       => 'HRep',
        'email'           => 'hrep.' . strtolower($name) . '.' . bin2hex(random_bytes(3)) . '@example.test',
        'source'          => 'hreptest',
        'lifecycle_stage' => 'customer',
        'email_consent'   => 1,
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ]);
}

/** An outstanding invitation, and the token a guest would be sent. */
function hrep_invite(string $name): array
{
    $surveyId = (int) DB::value("SELECT id FROM {surveys} WHERE type = 'nps' ORDER BY id LIMIT 1");

    if ($surveyId === 0) {
        $surveyId = (int) DB::insert('surveys', [
            'name'       => 'After a party',
            'type'       => 'nps',
            'delay_hours' => 20,
            'is_active'  => 1,
            'created_at' => now_utc(),
        ]);
    }

    $token     = bin2hex(random_bytes(16));
    $contactId = hrep_contact($name);

    $id = (int) DB::insert('survey_responses', [
        'survey_id'    => $surveyId,
        'contact_id'   => $contactId,
        'public_token' => $token,
        'invited_at'   => now_utc(),
        'channel'      => 'email',
        'created_at'   => now_utc(),
    ]);

    return ['id' => $id, 'token' => $token, 'contact_id' => $contactId];
}

function hrep_setting(string $key, mixed $value): void
{
    Settings::set('reputation.' . $key, $value);
    Settings::load(true);
}

hrep_clear();

hrep_setting('link_google', 'https://google.example/castle/review');
hrep_setting('weight_google', 3);
hrep_setting('link_yelp', '');
hrep_setting('weight_yelp', 0);
hrep_setting('ask_everyone', false);
hrep_setting('wall_enabled', true);
hrep_setting('wall_min_rating', 4);

$owner = new TestBrowser($baseUrl, 'reputation-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds no view_reputation: floor staff do not need to read what
// guests said about each other's parties.
$desk = new TestBrowser($baseUrl, 'reputation-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

Harness::test('the fixtures signed in', function () use ($owner, $desk): void {
    Assert::same(200, $owner->get('/account')['status']);
    Assert::same(200, $desk->get('/account')['status']);
});

// ============================================================== the venue side

Harness::test('every reputation page renders', function () use ($owner): void {
    foreach ([
        '/reputation',
        '/reputation/feedback',
        '/reputation/reviews',
        '/reputation/requests',
        '/reputation/surveys',
    ] as $path) {
        $response = $owner->get($path);

        Assert::same(200, $response['status'], $path . ' should render');
        Assert::notContains('Fatal error', $response['body'], $path);
        Assert::notContains('Warning:', $response['body'], $path);
        Assert::notContains('Undefined ', $response['body'], $path);
    }
});

Harness::test('READING FEEDBACK NEEDS THE PERMISSION', function () use ($desk): void {
    foreach (['/reputation', '/reputation/feedback', '/reputation/reviews'] as $path) {
        Assert::same(403, $desk->get($path)['status'], $path . ' should be refused');
    }
});

Harness::test('the dashboard says so when no survey is running', function () use ($owner): void {
    DB::query('UPDATE {surveys} SET is_active = 0');

    $body = $owner->get('/reputation')['body'];

    // Everything on the page would otherwise be empty with no explanation,
    // which reads as a broken feature rather than an unswitched one.
    Assert::contains('No survey is running', $body);

    DB::query("UPDATE {surveys} SET is_active = 1 WHERE trigger_event IS NOT NULL");
});

Harness::test('the settings page shows the rotation in words', function () use ($owner): void {
    $body = $owner->get('/reputation/surveys')['body'];

    Assert::contains('Google', $body);
    Assert::contains('happy guests go to', $body, 'the venue can see what will happen');
});

Harness::test('a non-http review link is refused rather than stored', function () use ($owner): void {
    /*
     * Every field the real form carries, because this is a checkbox form and
     * an unticked box submits nothing: a partial POST here would read as the
     * venue switching the wall and the escalation OFF, which is correct
     * behaviour and a trap for a test that only sends the field it is
     * interested in. (It was: two later tests failed because this POST had
     * quietly turned the review wall off.)
     */
    $owner->postWithToken('/reputation/surveys', '/reputation/settings', [
        'link_google'          => 'javascript:alert(1)',
        'weight_google'        => '3',
        'link_yelp'            => '',
        'weight_yelp'          => '0',
        'link_facebook'        => '',
        'weight_facebook'      => '0',
        'link_tripadvisor'     => '',
        'weight_tripadvisor'   => '0',
        'auto_escalate'        => '1',
        'sla_hours'            => '4',
        'invite_channel'       => 'email',
        'invite_lookback_days' => '14',
        'wall_enabled'         => '1',
        'wall_min_rating'      => '4',
    ]);

    Settings::load(true);

    // This URL is put in front of a guest, so anything but http(s) is dropped
    // on the floor rather than saved and later rendered as a link.
    Assert::same('', (string) Settings::get('reputation.link_google'));

    hrep_setting('link_google', 'https://google.example/castle/review');
});

Harness::test('the embed snippet carries an origin check', function () use ($owner): void {
    $body = $owner->get('/reputation/surveys')['body'];

    Assert::contains('castleCrmEmbedHeight', $body);
    Assert::contains('e.origin !==', $body, 'a message handler that trusts any sender is a bad habit to hand out');
});

// ========================================================= the guest's survey

Harness::test('the question renders for a guest with no account', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Guest');

    $guest    = new TestBrowser($baseUrl, 'reputation-guest-question');
    $response = $guest->get('/s/' . $invite['token']);

    Assert::same(200, $response['status']);
    Assert::contains('recommend', $response['body']);
    Assert::contains('name="score" value="10"', $response['body'], 'eleven tappable answers');
    Assert::contains('name="score" value="0"', $response['body']);

    // The token in the path is a bearer credential: indexed, it becomes
    // somebody else's feedback form.
    Assert::contains('noindex', $response['headers']);
    Assert::contains('no-store', $response['headers']);
});

Harness::test('the page is branded with the VENUE, not with Castle CRM', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Branded');

    $guest = new TestBrowser($baseUrl, 'reputation-guest-brand');
    $body  = $guest->get('/s/' . $invite['token'])['body'];

    // A parent being asked to answer something has never heard of the CRM.
    Assert::notContains('<strong>Castle CRM</strong>', $body);
    Assert::contains((string) Settings::venueName(), $body);
});

Harness::test('A PROMOTER IS SHOWN A REVIEW LINK', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Happy');

    $guest    = new TestBrowser($baseUrl, 'reputation-guest-promoter');
    $response = $guest->postWithToken('/s/' . $invite['token'], '/s/' . $invite['token'], [
        'score' => '10',
    ]);

    Assert::same(200, $response['status']);
    Assert::contains('Leave a review on Google', $response['body']);
    Assert::matches('~/r/[a-f0-9]{32}~', $response['body'], 'through the tracked link, not straight out');

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => $invite['id']]);

    Assert::same(10, (int) $saved['score']);
    Assert::same('promoter', (string) $saved['sentiment']);
});

Harness::test('AN UNHAPPY GUEST IS SHOWN NO REVIEW LINK AT ALL', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Unhappy');

    $guest    = new TestBrowser($baseUrl, 'reputation-guest-detractor');
    $response = $guest->postWithToken('/s/' . $invite['token'], '/s/' . $invite['token'], [
        'score' => '2',
    ]);

    Assert::same(200, $response['status']);
    Assert::notContains('Leave a review', $response['body']);
    Assert::notContains('/r/', $response['body']);
    Assert::contains('sorry', strtolower($response['body']));
    Assert::contains('What went wrong', $response['body'], 'they are asked, not sent away');
});

Harness::test('a passive is asked what was missing, and not for a review', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Mild');

    $guest    = new TestBrowser($baseUrl, 'reputation-guest-passive');
    $response = $guest->postWithToken('/s/' . $invite['token'], '/s/' . $invite['token'], [
        'score' => '8',
    ]);

    Assert::notContains('Leave a review', $response['body']);
    Assert::contains('what would have made it a ten', strtolower($response['body']));
});

Harness::test('a comment left on the follow-up page is kept', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Chatty');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-comment');
    $guest->postWithToken($path, $path, ['score' => '9']);

    $response = $guest->postWithToken($path, $path, [
        'comment' => 'The host was wonderful with the children',
    ]);

    Assert::same(200, $response['status']);

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => $invite['id']]);

    Assert::same('The host was wonderful with the children', (string) $saved['comment']);
    Assert::same(9, (int) $saved['score'], 'and the score is untouched');
});

Harness::test('answering again shows the thank-you, not the question again', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Returning');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-again');
    $guest->postWithToken($path, $path, ['score' => '10']);

    $response = $guest->get($path);

    // A blank form on a second visit implies their answer was lost.
    Assert::notContains('name="score" value="10"', $response['body']);
    Assert::contains('Leave a review on Google', $response['body'], 'their ask is still there');
});

Harness::test('a bad score is sent back to the question', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Tampered');
    $path   = '/s/' . $invite['token'];

    $guest    = new TestBrowser($baseUrl, 'reputation-guest-bad');
    $response = $guest->postWithToken($path, $path, ['score' => '99']);

    Assert::same(302, $response['status']);

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => $invite['id']]);

    Assert::null($saved['score']);
    Assert::null($saved['responded_at'], 'so the invitation is still outstanding');
});

Harness::test('AN UNKNOWN TOKEN IS A DEAD END THAT GIVES NOTHING AWAY', function () use ($baseUrl): void {
    $guest = new TestBrowser($baseUrl, 'reputation-guest-unknown');

    // A token-shaped string that exists nowhere, and one that is not even the
    // right shape: the same answer, so poking at URLs says nothing about
    // whether a token exists.
    $real = $guest->get('/s/' . str_repeat('a', 32));

    Assert::same(404, $real['status']);
    Assert::contains('did not work', $real['body']);
    Assert::notContains('survey_responses', $real['body']);

    Assert::same(404, $guest->get('/s/not-a-token')['status']);
});

// ================================================== the review click-through

Harness::test('THE REVIEW LINK REDIRECTS WHERE THE VENUE SAID, AND RECORDS THE CLICK', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Clicker');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-click');
    $body  = $guest->postWithToken($path, $path, ['score' => '10'])['body'];

    Assert::same(1, preg_match('~/r/([a-f0-9]{32})~', $body, $match));

    $response = $guest->get('/r/' . $match[1]);

    Assert::same(302, $response['status']);
    Assert::same('https://google.example/castle/review', $response['location']);

    $ask = DB::first('SELECT * FROM {review_requests} WHERE token = :t', ['t' => $match[1]]);

    Assert::same('clicked', (string) $ask['status']);
    Assert::notNull($ask['clicked_at']);

    // A second click (or a mail client prefetching) must not move the stamp:
    // the figure is "how many guests clicked", not "how many times a link was
    // followed".
    $first = (string) $ask['clicked_at'];
    $guest->get('/r/' . $match[1]);

    $again = DB::first('SELECT clicked_at FROM {review_requests} WHERE token = :t', ['t' => $match[1]]);

    Assert::same($first, (string) $again['clicked_at']);
});

Harness::test('a review link whose destination was removed thanks them instead', function () use ($baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Orphan');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-orphan');
    $body  = $guest->postWithToken($path, $path, ['score' => '10'])['body'];

    Assert::same(1, preg_match('~/r/([a-f0-9]{32})~', $body, $match));

    hrep_setting('link_google', '');

    $response = $guest->get('/r/' . $match[1]);

    Assert::same(200, $response['status'], 'not a redirect to nowhere');
    Assert::contains('Thank you', $response['body']);

    hrep_setting('link_google', 'https://google.example/castle/review');
});

Harness::test('an unknown review token is a dead end too', function () use ($baseUrl): void {
    $guest = new TestBrowser($baseUrl, 'reputation-guest-badclick');

    Assert::same(404, $guest->get('/r/' . str_repeat('b', 32))['status']);
});

// ============================================================== the wall

Harness::test('THE REVIEW WALL IS INDEXABLE, CACHEABLE AND FRAMEABLE', function () use ($baseUrl): void {
    DB::insert('reviews', [
        'platform'    => 'google',
        'author_name' => 'HRep Featured',
        'rating'      => 5,
        'body'        => 'The best birthday party we have had',
        'is_featured' => 1,
        'posted_at'   => now_utc(),
        'imported_at' => now_utc(),
    ]);

    $visitor  = new TestBrowser($baseUrl, 'reputation-wall');
    $response = $visitor->get('/reviews');

    Assert::same(200, $response['status']);
    Assert::contains('The best birthday party we have had', $response['body']);

    // Unlike every other public page in this module, the wall is MEANT to be
    // found and framed — it is the venue's own marketing.
    Assert::notContains('X-Robots-Tag: noindex', $response['headers']);
    Assert::contains('frame-ancestors *', $response['headers']);
    Assert::notContains('X-Frame-Options', $response['headers'], 'there is no value that permits framing');
    Assert::contains('max-age=600', $response['headers']);
});

Harness::test('the embedded wall drops the chrome and posts its height', function () use ($baseUrl): void {
    $visitor  = new TestBrowser($baseUrl, 'reputation-wall-embed');
    $response = $visitor->get('/reviews?embed=1');

    Assert::same(200, $response['status']);
    Assert::contains('class="embed"', $response['body']);
    Assert::contains('js/embed.js', $response['body']);
    Assert::notContains('bare-card', $response['body'], 'no card around a widget');
});

Harness::test('an unfeatured review never reaches the wall', function () use ($baseUrl): void {
    DB::insert('reviews', [
        'platform'    => 'google',
        'author_name' => 'HRep Hidden',
        'rating'      => 5,
        'body'        => 'Nobody chose to show this one',
        'is_featured' => 0,
        'posted_at'   => now_utc(),
        'imported_at' => now_utc(),
    ]);

    $visitor = new TestBrowser($baseUrl, 'reputation-wall-hidden');

    Assert::notContains('Nobody chose to show this one', $visitor->get('/reviews')['body']);
});

Harness::test('a switched-off wall says nothing rather than erroring', function () use ($baseUrl): void {
    hrep_setting('wall_enabled', false);

    $visitor  = new TestBrowser($baseUrl, 'reputation-wall-off');
    $response = $visitor->get('/reviews');

    Assert::same(200, $response['status']);
    Assert::notContains('The best birthday party we have had', $response['body']);
    Assert::contains('No reviews are featured', $response['body']);

    hrep_setting('wall_enabled', true);
});

// ============================================================ reviews, inside

Harness::test('reviews import from a pasted CSV, and the bad rows are reported', function () use ($owner): void {
    DB::query("DELETE FROM {reviews} WHERE author_name LIKE 'HRep Import%'");

    $csv = "author,rating,body,date\n"
        . "HRep Import One,5,\"Loved it\",2026-09-01\n"
        . "HRep Import Two,2,\"Too crowded\",2026-09-02\n"
        . "HRep Import Bad,nine,\"Not a number\",2026-09-03\n";

    $response = $owner->postWithToken('/reputation/reviews', '/reputation/reviews/import', [
        'platform' => 'google',
        'csv'      => $csv,
    ], true);

    Assert::same(200, $response['status']);
    Assert::contains('2 reviews added', $response['body']);
    Assert::contains('could not be used', $response['body'], 'the bad row is named, not swallowed');

    $two = DB::first("SELECT * FROM {reviews} WHERE author_name = 'HRep Import Two'");

    Assert::notNull($two);
    Assert::same(2, (int) $two['rating']);
    Assert::same('negative', (string) $two['sentiment']);
});

Harness::test('re-importing the same export updates rather than duplicating', function () use ($owner): void {
    $csv = "author,rating,body,date\nHRep Import One,4,\"Changed my mind slightly\",2026-09-01\n";

    $owner->postWithToken('/reputation/reviews', '/reputation/reviews/import', [
        'platform' => 'google',
        'csv'      => $csv,
    ], true);

    Assert::same(
        1,
        (int) DB::value("SELECT COUNT(*) FROM {reviews} WHERE author_name = 'HRep Import One'"),
        'one review, not two'
    );

    Assert::same(
        4,
        (int) DB::value("SELECT rating FROM {reviews} WHERE author_name = 'HRep Import One'")
    );
});

Harness::test('a logged reply says plainly that it was not posted for them', function () use ($owner): void {
    $id = (int) DB::value("SELECT id FROM {reviews} WHERE author_name = 'HRep Import Two'");

    $response = $owner->postWithToken('/reputation/reviews', '/reputation/reviews/' . $id . '/reply', [
        'reply_body' => 'I am sorry about the wait. Please call and ask for the manager.',
    ], true);

    Assert::same(200, $response['status']);

    $review = DB::first('SELECT * FROM {reviews} WHERE id = :id', ['id' => $id]);

    Assert::notNull($review['replied_at']);
    Assert::contains('I am sorry about the wait', (string) $review['reply_body']);

    // The application cannot post to Google, and the page must not imply it
    // did. ("Posting it is still done on Google.")
    Assert::contains('Posting it is still done on', $response['body']);
});

Harness::test('a draft can be loaded into the reply box with no JavaScript', function () use ($owner): void {
    $id = (int) DB::value("SELECT id FROM {reviews} WHERE author_name = 'HRep Import One'");

    $response = $owner->get('/reputation/reviews?draft=review-reply-thanks&review=' . $id);

    Assert::same(200, $response['status']);
    Assert::contains('HRep Import One', $response['body'], 'the draft is addressed to them');
    Assert::contains('passed it on to the team', $response['body']);
});

Harness::test('featuring a review puts it on the wall and takes it off again', function () use ($owner, $baseUrl): void {
    $id = (int) DB::value("SELECT id FROM {reviews} WHERE author_name = 'HRep Import One'");

    $owner->postWithToken('/reputation/reviews', '/reputation/reviews/' . $id . '/feature', [], true);

    $visitor = new TestBrowser($baseUrl, 'reputation-wall-feature');

    Assert::contains('HRep Import One', $visitor->get('/reviews')['body']);

    $owner->postWithToken('/reputation/reviews', '/reputation/reviews/' . $id . '/feature', [], true);

    Assert::notContains('HRep Import One', $visitor->get('/reviews')['body']);
});

Harness::test('writing a review reply needs the permission', function () use ($desk): void {
    $id = (int) DB::value("SELECT id FROM {reviews} WHERE author_name = 'HRep Import One'");

    $response = $desk->post('/reputation/reviews/' . $id . '/reply', ['reply_body' => 'no']);

    // Refused before CSRF even matters, because the route carries the gate.
    Assert::true(in_array($response['status'], [403, 419], true), 'got ' . $response['status']);
});

// ============================================================= the complaint

Harness::test('A COMPLAINT IS RESOLVED WITH A RECORD OF WHAT WAS DONE', function () use ($owner, $baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Cross');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-cross');
    $guest->postWithToken($path, $path, ['score' => '1']);

    $page = $owner->get('/reputation/feedback/' . $invite['id']);

    Assert::same(200, $page['status']);
    Assert::contains('somebody here owes them a call', $page['body']);
    Assert::contains('Record what you did', $page['body']);

    // The note is required: "resolved" with no record of what was said is
    // the state this page exists to replace.
    $empty = $owner->postWithToken(
        '/reputation/feedback/' . $invite['id'],
        '/reputation/feedback/' . $invite['id'] . '/resolve',
        ['resolution_note' => '']
    );

    Assert::same(302, $empty['status']);
    Assert::null(
        DB::value('SELECT resolved_at FROM {survey_responses} WHERE id = :id', ['id' => $invite['id']]),
        'nothing was resolved'
    );

    $done = $owner->postWithToken(
        '/reputation/feedback/' . $invite['id'],
        '/reputation/feedback/' . $invite['id'] . '/resolve',
        ['resolution_note' => 'Rang them, comped two wristbands for their next visit'],
        true
    );

    Assert::same(200, $done['status']);
    Assert::contains('Rang them, comped two wristbands', $done['body']);

    $saved = DB::first('SELECT * FROM {survey_responses} WHERE id = :id', ['id' => $invite['id']]);

    Assert::notNull($saved['resolved_at']);
    Assert::notNull($saved['routed_to'], 'and who did it');

    // The task the module raised is closed with it, so the same complaint is
    // not still in somebody's list.
    $open = (int) DB::value(
        "SELECT COUNT(*) FROM {tasks}
          WHERE entity_type = 'feedback' AND entity_id = :id AND status IN ('open','in_progress')",
        ['id' => $invite['id']]
    );

    Assert::same(0, $open);
});

Harness::test('resolving twice is a double-click, not an error', function () use ($owner, $baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Twice');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-twice');
    $guest->postWithToken($path, $path, ['score' => '3']);

    for ($i = 0; $i < 2; $i++) {
        $owner->postWithToken(
            '/reputation/feedback/' . $invite['id'],
            '/reputation/feedback/' . $invite['id'] . '/resolve',
            ['resolution_note' => 'Spoke to them']
        );
    }

    $response = $owner->get('/reputation/feedback/' . $invite['id']);

    Assert::same(200, $response['status']);
    Assert::contains('Spoke to them', $response['body']);
});

Harness::test('resolving needs the permission', function () use ($desk): void {
    $id = (int) DB::value('SELECT id FROM {survey_responses} ORDER BY id DESC LIMIT 1');

    $response = $desk->post('/reputation/feedback/' . $id . '/resolve', [
        'resolution_note' => 'not mine to close',
    ]);

    Assert::true(in_array($response['status'], [403, 419], true), 'got ' . $response['status']);
});

Harness::test('the escalation task links back to the feedback it is about', function () use ($owner, $baseUrl): void {
    hrep_clear();
    $invite = hrep_invite('Linked');
    $path   = '/s/' . $invite['token'];

    $guest = new TestBrowser($baseUrl, 'reputation-guest-linked');
    $guest->postWithToken($path, $path, ['score' => '0']);

    $taskId = (int) DB::value(
        "SELECT id FROM {tasks} WHERE entity_type = 'feedback' AND entity_id = :id",
        ['id' => $invite['id']]
    );

    Assert::greaterThan(0, $taskId, 'a complaint became work');

    // A task about something with no way to open it is a dead end — which is
    // what every automation-raised task was before TaskController learned the
    // plural entity types.
    $body = $owner->get('/tasks/' . $taskId)['body'];

    Assert::contains('/reputation/feedback/' . $invite['id'], $body);
});

// ------------------------------------------------------------------- tidy up

hrep_clear();
DB::query("DELETE FROM {reviews} WHERE author_name LIKE 'HRep%'");

foreach (['link_google' => '', 'weight_google' => 3, 'ask_everyone' => false, 'wall_enabled' => true] as $k => $v) {
    hrep_setting($k, $v);
}
