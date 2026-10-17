<?php

declare(strict_types=1);

/**
 * The unified inbox, end to end over HTTP.
 *
 * Five things here are worth more than the rest.
 *
 *   1. **AN AGENT PHYSICALLY CANNOT SEND WHAT THE SEND PATH WOULD REFUSE.**
 *      §8.6 puts it that way, and it is tested twice: the composer for a guest
 *      who texted STOP does not offer their channel AT ALL, and a hand-posted
 *      reply to one is refused in the send path's own words. A page that let
 *      somebody type a reply and only refused it on submit would satisfy half
 *      of that and lose the work.
 *   2. **REPLYING AND MANAGING THE QUEUE ARE DIFFERENT PERMISSIONS.** Front
 *      Desk holds `inbox_reply` and not `inbox_assign` in the seeds, so the
 *      person on the phone can answer a guest and cannot decide the queue is
 *      clear. Every refusal has its positive control beside it.
 *   3. **FIRST RESPONSE IS STAMPED ONCE, BY A PERSON.** It is reported per rep,
 *      so an internal note must not stop the clock and a second reply must not
 *      move it — otherwise a slow conversation that got a lot of attention
 *      reads as a fast one.
 *   4. **AN UNKNOWN SENDER BECOMES A CONTACT AND AN OPEN CONVERSATION.** A
 *      guest texting a number the venue has never seen is a lead; dropping
 *      their message is how a venue loses a booking it never knew it had.
 *   5. **A REPLY TO A CLOSED CONVERSATION REOPENS IT.** A closed thread with
 *      an unanswered question in it is the exact failure a queue exists to
 *      prevent.
 */

use App\Controllers\InboxController;
use App\Core\DB;
use App\Core\Settings;
use App\Services\Inbox;
use Tests\Assert;
use Tests\Harness;

Harness::suite('http/inbox');

$baseUrl = getenv('CC_TEST_BASE_URL') ?: '';

if ($baseUrl === '') {
    Harness::skip('inbox suite', 'no server; run via tests/run.php');

    return;
}

if (!test_boot()) {
    Harness::skip('inbox suite', 'no database available');

    return;
}

require_once CC_ROOT . '/tests/TestBrowser.php';

// ------------------------------------------------------------------ fixtures

/** A guest of this suite's own, named so cleanup can find them. */
function ib_contact(array $overrides = []): int
{
    static $n = 0;
    $n++;

    $phone = '+1845777' . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT);

    return DB::insert('contacts', array_merge([
        'first_name'       => 'InboxTest',
        'last_name'        => 'Guest ' . $n,
        'email'            => 'inbox.' . bin2hex(random_bytes(4)) . '@example.test',
        'phone'            => $phone,
        'phone_normalized' => $phone,
        'sms_consent'      => 1,
        'sms_consent_at'   => now_utc(),
        'lifecycle_stage'  => 'lead',
        'created_at'       => now_utc(),
        'updated_at'       => now_utc(),
    ], $overrides));
}

/** A conversation with one inbound message on it. */
function ib_conversation(int $contactId, string $channel = 'sms', array $overrides = []): int
{
    $id = DB::insert('conversations', array_merge([
        'contact_id'      => $contactId,
        'channel'         => $channel,
        'subject'         => 'InboxTest thread',
        'status'          => 'open',
        'is_unread'       => 1,
        'last_message_at' => now_utc(),
        'created_at'      => now_utc(),
        'updated_at'      => now_utc(),
    ], $overrides));

    DB::insert('messages', [
        'conversation_id' => $id,
        'contact_id'      => $contactId,
        'direction'       => 'inbound',
        'channel'         => $channel,
        'body'            => 'Do you have a room free on the 12th?',
        'body_text'       => 'Do you have a room free on the 12th?',
        'status'          => 'delivered',
        'created_at'      => now_utc(),
    ]);

    return $id;
}

/** @return array<string,mixed> */
function ib_row(int $id): array
{
    $row = DB::first('SELECT * FROM {conversations} WHERE id = :id', ['id' => $id]);

    Assert::notNull($row, 'conversation ' . $id . ' should exist');

    /** @var array<string,mixed> $row */
    return $row;
}

function ib_messages(int $id): array
{
    return DB::all(
        'SELECT * FROM {messages} WHERE conversation_id = :id ORDER BY id',
        ['id' => $id]
    );
}

function ib_clear(): void
{
    DB::query(
        "DELETE FROM {messages} WHERE conversation_id IN (
            SELECT id FROM {conversations} WHERE subject = 'InboxTest thread'
         )"
    );
    DB::query("DELETE FROM {conversations} WHERE subject = 'InboxTest thread'");
    DB::query(
        "DELETE FROM {messages} WHERE contact_id IN (
            SELECT id FROM {contacts} WHERE first_name = 'InboxTest'
         )"
    );
    DB::query(
        "DELETE FROM {conversations} WHERE contact_id IN (
            SELECT id FROM {contacts} WHERE first_name = 'InboxTest'
         )"
    );
    DB::query("DELETE FROM {contacts} WHERE first_name = 'InboxTest'");
    DB::query("DELETE FROM {contacts} WHERE email LIKE 'inbox.%@example.test'");
    DB::delete('suppressions', "channel = 'sms' AND address LIKE :p", ['p' => '+1845777%']);
    DB::query("DELETE FROM {canned_replies} WHERE title LIKE 'InboxTest%'");
}

ib_clear();

// ------------------------------------------------------------------- actors

$owner = new TestBrowser($baseUrl, 'inbox-owner');
$owner->login('owner@test.invalid', 'owner-test-passphrase-9');

// Front Desk holds `inbox_reply` and NOT `inbox_assign` — the whole split.
$desk = new TestBrowser($baseUrl, 'inbox-desk');
$desk->login('desk@test.invalid', 'desk-test-passphrase-9');

// Read Only: no inbox permission at all in the seeds, which makes it the
// control for both gates below.
$watcherPassword = 'inbox-watcher-passphrase-9';
$watcherId       = test_make_user('read_only', [
    'email'         => 'inbox-watcher@example.test',
    'name'          => 'Inbox Watcher',
    'password_hash' => App\Core\Auth::hash($watcherPassword),
]);

$watcher = new TestBrowser($baseUrl, 'inbox-watcher');
$watcher->login('inbox-watcher@example.test', $watcherPassword);

Harness::test('the fixtures signed in, and hold the permissions these tests turn on', function () use ($owner, $desk, $watcher): void {
    foreach (['owner' => $owner, 'desk' => $desk, 'watcher' => $watcher] as $who => $browser) {
        Assert::same(200, $browser->get('/account')['status'], $who . ' should be signed in');
    }

    // The split this suite exists to test. If the seeds ever change so that
    // Front Desk holds inbox_assign, every refusal test below would pass
    // vacuously — so it is asserted rather than assumed.
    Assert::true(
        DB::exists(
            "SELECT rp.role_id FROM {role_permissions} rp
               JOIN {permissions} p ON p.id = rp.permission_id
               JOIN {roles} r ON r.id = rp.role_id
              WHERE r.slug = 'front_desk' AND p.slug = 'inbox_reply'"
        ),
        'front desk may reply'
    );
    Assert::false(
        DB::exists(
            "SELECT rp.role_id FROM {role_permissions} rp
               JOIN {permissions} p ON p.id = rp.permission_id
               JOIN {roles} r ON r.id = rp.role_id
              WHERE r.slug = 'front_desk' AND p.slug = 'inbox_assign'"
        ),
        'and may NOT manage the queue'
    );
});

// ------------------------------------------------------------------ the queue

Harness::test('the queue renders, and every tab answers', function () use ($owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $page = $owner->get('/inbox?filter=all');

        Assert::same(200, $page['status']);
        Assert::contains('InboxTest', $page['body'], 'the conversation is listed');

        foreach (array_keys(Inbox::FILTERS) as $filter) {
            Assert::same(
                200,
                $owner->get('/inbox?filter=' . $filter)['status'],
                'the ' . $filter . ' tab answers'
            );
        }

        // An unrecognised tab falls back rather than erroring.
        Assert::same(200, $owner->get('/inbox?filter=nonsense')['status']);
    } finally {
        ib_clear();
    }
});

Harness::test('THE SEARCH BOX ACTUALLY FILTERS', function () use ($owner): void {
    // The Phase 2 lesson: a list test that never sets a filter does not test
    // the filter. Two conversations, searched down to one, asserted on the
    // row LINK rather than on the term — the filter bar echoes the term back
    // into its own input, so a text assertion passes against an empty list.
    $wanted = ib_contact(['last_name' => 'Findable']);
    $other  = ib_contact(['last_name' => 'Invisible']);

    $wantedId = ib_conversation($wanted);
    $otherId  = ib_conversation($other);

    try {
        $all = $owner->get('/inbox?filter=all');

        Assert::contains('/inbox/' . $wantedId, $all['body'], 'both are there unfiltered');
        Assert::contains('/inbox/' . $otherId, $all['body']);

        $found = $owner->get('/inbox?filter=all&q=Findable');

        Assert::contains('/inbox/' . $wantedId, $found['body'], 'the match is listed');
        Assert::notContains('/inbox/' . $otherId, $found['body'], 'and the other one is not');

        $none = $owner->get('/inbox?filter=all&q=zzz-nobody-' . bin2hex(random_bytes(3)));

        Assert::notContains('/inbox/' . $wantedId, $none['body'], 'a search with no matches finds none');
        Assert::contains('Nothing matches', $none['body'], 'and says so');
    } finally {
        ib_clear();
    }
});

Harness::test('the channel filter filters too', function () use ($owner): void {
    $textContact  = ib_contact();
    $emailContact = ib_contact();

    $textId  = ib_conversation($textContact, 'sms');
    $emailId = ib_conversation($emailContact, 'email');

    try {
        $sms = $owner->get('/inbox?filter=all&channel=sms');

        Assert::contains('/inbox/' . $textId, $sms['body']);
        Assert::notContains('/inbox/' . $emailId, $sms['body']);

        $email = $owner->get('/inbox?filter=all&channel=email');

        Assert::contains('/inbox/' . $emailId, $email['body']);
        Assert::notContains('/inbox/' . $textId, $email['body']);
    } finally {
        ib_clear();
    }
});

Harness::test('opening a conversation marks it read', function () use ($owner): void {
    // Not a side effect to apologise for: the flag means "nobody has looked
    // at this", and somebody just did.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms', ['is_unread' => 1]);

    try {
        Assert::same(1, (int) ib_row($id)['is_unread'], 'precondition: unread');

        Assert::same(200, $owner->get('/inbox/' . $id)['status']);

        Assert::same(0, (int) ib_row($id)['is_unread'], 'and reading it clears the flag');
    } finally {
        ib_clear();
    }
});

Harness::test('a conversation that does not exist is a 404, not an empty page', function () use ($owner): void {
    Assert::same(404, $owner->get('/inbox/99999999')['status']);
});

// --------------------------------------------------------------- the gates

Harness::test('a role with no view_inbox cannot reach the inbox at all', function () use ($watcher): void {
    // Read Only holds no inbox permission in the seeds.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        Assert::same(403, $watcher->get('/inbox')['status']);
        Assert::same(403, $watcher->get('/inbox/' . $id)['status']);
    } finally {
        ib_clear();
    }
});

Harness::test('FRONT DESK MAY REPLY AND MAY NOT MANAGE THE QUEUE', function () use ($desk, $owner): void {
    // The split, both halves, with the owner as the positive control for each
    // refusal — scoping a write to nothing would satisfy the refusals
    // perfectly and this is what tells the two apart.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms');

    try {
        Assert::same(200, $desk->get('/inbox/' . $id)['status'], 'they can read it');

        // Replying: allowed.
        $reply = $desk->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'internal',
            'body'    => 'Desk note: checking the diary.',
        ]);

        Assert::same(302, $reply['status']);
        Assert::same(
            1,
            count(array_filter(
                ib_messages($id),
                static fn (array $m): bool => (string) $m['channel'] === 'internal'
            )),
            'and the note is on the thread'
        );

        // Managing the queue: refused, four ways.
        //
        // EACH GATE EXISTS TWICE -- once as route middleware, once as an
        // authorize() call in the action -- so removing either ALONE leaves
        // this test green; removing both is caught. MEASURED by mutation, and
        // it is the right thing to pin: the claim worth testing is "front
        // desk cannot close a conversation", not "this particular line stops
        // them".
        foreach ([
            'assign' => ['assigned_to' => '0'],
            'snooze' => ['option' => 'tomorrow'],
            'close'  => [],
            'reopen' => [],
        ] as $action => $fields) {
            $response = $desk->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/' . $action, $fields);

            Assert::same(403, $response['status'], 'front desk cannot ' . $action);
        }

        Assert::same('open', (string) ib_row($id)['status'], 'so the conversation is untouched');
        Assert::null(ib_row($id)['assigned_to']);

        // THE POSITIVE CONTROL: the owner can do all four.
        Assert::same(
            302,
            $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/close', [])['status']
        );
        Assert::same('closed', (string) ib_row($id)['status'], 'so the refusals above were the permission');
    } finally {
        ib_clear();
    }
});

Harness::test('and a role with no inbox_reply cannot reply', function () use ($watcher, $owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $response = $watcher->postWithToken('/inbox', '/inbox/' . $id . '/reply', [
            'channel' => 'internal',
            'body'    => 'Should not land.',
        ]);

        Assert::same(403, $response['status']);
        Assert::same(
            1,
            count(ib_messages($id)),
            'only the guest\'s own message is on the thread'
        );
    } finally {
        ib_clear();
    }
});

// ------------------------------------------------------------- the composer

Harness::test('AN AGENT CANNOT TEXT A GUEST WHO REPLIED STOP — the channel is not even offered', function () use ($owner): void {
    // §8.6, and the whole reason the composer asks the send path before it is
    // drawn. A box offered and then refused on submit loses the work and
    // tells them too late.
    $contactId = ib_contact(['sms_consent' => 1]);
    $phone     = (string) DB::value(
        'SELECT phone_normalized FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );
    $id = ib_conversation($contactId, 'sms');

    try {
        // Before the STOP: the channel is offered.
        $before = $owner->get('/inbox/' . $id);

        Assert::contains('value="sms"', $before['body'], 'the text channel is offered to begin with');
        Assert::contains('name="body"', $before['body'], 'with a box to type in');

        // The guest texts STOP, through the real inbound path.
        InboxController::receive(['channel' => 'sms', 'from' => $phone, 'body' => 'STOP']);

        Assert::true(
            DB::exists(
                "SELECT id FROM {suppressions} WHERE channel = 'sms' AND address = :a",
                ['a' => $phone]
            ),
            'precondition: the STOP was honoured'
        );

        $after = $owner->get('/inbox/' . $id);

        Assert::same(200, $after['status'], 'the conversation still opens');
        Assert::notContains(
            'value="sms"',
            $after['body'],
            'but the text channel is GONE from the composer'
        );
        Assert::contains(
            'opted out',
            $after['body'],
            'and the page says why, in the send path\'s own words'
        );

        // AND the POST is refused too, for anybody who posts it by hand.
        $posted = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'sms',
            'body'    => 'Trying anyway.',
        ]);

        Assert::same(302, $posted['status'], 'it is sent back, not accepted');
        Assert::same(
            0,
            count(array_filter(
                ib_messages($id),
                static fn (array $m): bool => (string) $m['direction'] === 'outbound'
                    && (string) $m['channel'] === 'sms'
            )),
            'and no text is recorded as going to them'
        );

        // AND THE CONVERSATION IS NOT MARKED AS ANSWERED. This is the
        // assertion that makes the one above worth anything: a controller
        // that ignored the send path's refusal and carried on would record no
        // message either (a refusal writes no row), so counting rows alone
        // cannot tell a refusal from a success. Stamping first_response_at is
        // what such a bug actually does, and that figure is reported per rep
        // -- so it would read as the venue having answered a guest it never
        // reached.
        Assert::null(
            ib_row($id)['first_response_at'],
            'a refused send is not recorded as a response'
        );

        // An internal note still works: it sends nothing.
        $note = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'internal',
            'body'    => 'They asked to stop. Noted.',
        ]);

        Assert::same(302, $note['status']);
        Assert::same(
            1,
            count(array_filter(
                ib_messages($id),
                static fn (array $m): bool => (string) $m['channel'] === 'internal'
            )),
            'because a note is staff talking to each other'
        );
    } finally {
        ib_clear();
    }
});

Harness::test('A GUEST MARKED DO-NOT-CONTACT GETS NO COMPOSER ON EITHER CHANNEL', function () use ($owner): void {
    // The EMAIL half is the one that needs its own test. For a text, the
    // composer asks Core/Sms and gets the same refusal whether or not the
    // do-not-contact branch runs first -- the two layers agree, so removing
    // the branch is unobservable there. The email path has no equivalent
    // preview: without that branch it checks only whether an address exists,
    // and would cheerfully offer to write to somebody who asked not to be
    // contacted at all.
    $contactId = ib_contact(['do_not_contact' => 1]);
    $textId    = ib_conversation($contactId, 'sms');
    $emailId   = ib_conversation($contactId, 'email');

    try {
        $text = $owner->get('/inbox/' . $textId);

        Assert::contains('not to be contacted', $text['body']);
        Assert::notContains('value="sms"', $text['body']);

        $email = $owner->get('/inbox/' . $emailId);

        Assert::contains('not to be contacted', $email['body'], 'the email thread says so too');
        Assert::notContains('value="email"', $email['body'], 'and offers no email channel');

        // The positive control: the same guest WITHOUT the block is offered
        // both, so the refusals above are the flag and not a broken composer.
        DB::update('contacts', ['do_not_contact' => 0], 'id = :id', ['id' => $contactId]);

        Assert::contains('value="sms"', $owner->get('/inbox/' . $textId)['body']);
        Assert::contains('value="email"', $owner->get('/inbox/' . $emailId)['body']);
    } finally {
        ib_clear();
    }
});

Harness::test('a guest with no mobile cannot be texted, and the page says so first', function () use ($owner): void {
    $contactId = ib_contact(['phone' => null, 'phone_normalized' => null]);
    $id        = ib_conversation($contactId, 'sms');

    try {
        $page = $owner->get('/inbox/' . $id);

        Assert::contains('no mobile number', $page['body']);
        Assert::notContains('value="sms"', $page['body']);

        $posted = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'sms',
            'body'    => 'Hello?',
        ]);

        Assert::same(302, $posted['status'], 'and the POST is refused as well');
        Assert::same(1, count(ib_messages($id)), 'with nothing recorded');
    } finally {
        ib_clear();
    }
});

Harness::test('an empty reply is refused', function () use ($owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms');

    try {
        $response = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'internal',
            'body'    => '   ',
        ]);

        Assert::same(302, $response['status']);
        Assert::same(1, count(ib_messages($id)), 'nothing is added');
    } finally {
        ib_clear();
    }
});

// -------------------------------------------------------- the first response

Harness::test('A REPLY STAMPS THE FIRST RESPONSE, and a later one does not move it', function () use ($owner): void {
    // The figure is how long the GUEST waited. Overwriting it would make a
    // slow conversation that got a lot of attention look fast.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms');

    try {
        Assert::null(ib_row($id)['first_response_at'], 'precondition: unanswered');

        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'sms',
            'body'    => 'Yes, the 12th at 2pm is free.',
        ]);

        $stamped = ib_row($id)['first_response_at'];

        Assert::notNull($stamped, 'the first reply stamps it');

        // Move the stamp into the PAST before the second reply, because
        // now_utc() has one-second resolution and two replies inside the same
        // second would compare equal whether or not the code overwrote it —
        // which is a test that cannot fail.
        DB::update('conversations', [
            'first_response_at' => '2026-01-01 09:00:00',
        ], 'id = :id', ['id' => $id]);

        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'sms',
            'body'    => 'Shall I pencil it in?',
        ]);

        Assert::same(
            '2026-01-01 09:00:00',
            (string) ib_row($id)['first_response_at'],
            'and a second reply leaves it exactly where it was'
        );
    } finally {
        ib_clear();
    }
});

Harness::test('AN INTERNAL NOTE DOES NOT STOP THE CLOCK', function () use ($owner): void {
    // Staff talking to each other. The guest has still heard nothing, so the
    // conversation must go on looking unanswered — otherwise the metric reads
    // best for whoever writes the most notes.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms');

    try {
        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'internal',
            'body'    => 'Checking the diary for the 12th.',
        ]);

        Assert::null(
            ib_row($id)['first_response_at'],
            'the note is on the thread and the clock is still running'
        );

        // The note DOES move the conversation up the queue, because somebody
        // is working on it.
        Assert::notNull(ib_row($id)['last_message_at']);

        // The positive control: a real reply stamps it.
        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'sms',
            'body'    => 'Yes, 2pm is free.',
        ]);

        Assert::notNull(ib_row($id)['first_response_at'], 'so the note was the difference');
    } finally {
        ib_clear();
    }
});

// -------------------------------------------------------------- the queue ops

Harness::test('a conversation can only be assigned to somebody who can reply', function () use ($owner, $watcherId): void {
    // Handing a guest's question to somebody with no `inbox_reply` is handing
    // it to a dead end: the queue shows it as their problem while they can do
    // nothing about it.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $deskUserId = (int) DB::value(
            'SELECT id FROM {users} WHERE email = :e',
            ['e' => 'desk@test.invalid']
        );

        $ok = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/assign', [
            'assigned_to' => (string) $deskUserId,
        ]);

        Assert::same(302, $ok['status']);
        Assert::same($deskUserId, (int) ib_row($id)['assigned_to'], 'front desk can take it');

        // The watcher is read_only: no inbox_reply, so no.
        $refused = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/assign', [
            'assigned_to' => (string) $watcherId,
        ]);

        Assert::same(302, $refused['status']);
        Assert::same(
            $deskUserId,
            (int) ib_row($id)['assigned_to'],
            'and a read-only account cannot be handed it'
        );

        // Clearing the assignment is allowed.
        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/assign', ['assigned_to' => '0']);

        Assert::null(ib_row($id)['assigned_to']);
    } finally {
        ib_clear();
    }
});

Harness::test('SNOOZING STORES A UTC INSTANT FROM THE VENUE\'S OWN CLOCK', function () use ($owner): void {
    // The database stores UTC and the venue thinks in its own wall clock, so
    // the stored value is checked against what Inbox::snoozeUntil() computes
    // for the same moment — the pure function the unit suite pins across both
    // DST transitions.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $zone  = Settings::timezone();
        $now   = new DateTimeImmutable('now', new DateTimeZone($zone));

        $response = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/snooze', [
            'option' => 'tomorrow',
        ]);

        Assert::same(302, $response['status']);

        $row = ib_row($id);

        Assert::same('snoozed', (string) $row['status']);
        Assert::notNull($row['snoozed_until']);
        Assert::same(
            Inbox::snoozeUntil('tomorrow', $now, $zone),
            (string) $row['snoozed_until'],
            'the stored instant is the venue\'s nine o\'clock tomorrow, in UTC'
        );

        // And it reads back as nine in the morning LOCAL, which is the claim
        // somebody actually cares about.
        $local = (new DateTimeImmutable((string) $row['snoozed_until'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($zone));

        Assert::same('09:00', $local->format('H:i'));
    } finally {
        ib_clear();
    }
});

Harness::test('a snooze to the past is refused rather than surfacing instantly', function () use ($owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $response = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/snooze', [
            'option' => 'custom',
            'until'  => '2020-01-01 09:00',
        ]);

        Assert::same(302, $response['status']);
        Assert::same('open', (string) ib_row($id)['status'], 'it is not snoozed');
        Assert::null(ib_row($id)['snoozed_until']);

        // The control: a future time works.
        $future = (new DateTimeImmutable('+3 days', new DateTimeZone(Settings::timezone())))
            ->format('Y-m-d\TH:i');

        $ok = $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/snooze', [
            'option' => 'custom',
            'until'  => $future,
        ]);

        Assert::same(302, $ok['status']);
        Assert::same('snoozed', (string) ib_row($id)['status']);
    } finally {
        ib_clear();
    }
});

Harness::test('a nonsense snooze option is refused', function () use ($owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/snooze', ['option' => 'whenever']);

        Assert::same('open', (string) ib_row($id)['status']);
    } finally {
        ib_clear();
    }
});

Harness::test('A SNOOZE THAT HAS COME DUE GOES BACK IN THE QUEUE, UNREAD', function (): void {
    // A snooze that never wakes is a guest's message lost. The cron step is
    // deliberately dumb and idempotent.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms', [
        'status'        => 'snoozed',
        'snoozed_until' => '2020-01-01 09:00:00',
        'is_unread'     => 0,
    ]);

    try {
        $woken = InboxController::wakeSnoozed();

        Assert::greaterThan(0, $woken, 'something was due');

        $row = ib_row($id);

        Assert::same('open', (string) $row['status']);
        Assert::null($row['snoozed_until']);
        Assert::same(1, (int) $row['is_unread'], 'and it is unread again, so it is noticed');

        // Idempotent: a second run finds nothing to do with this one.
        InboxController::wakeSnoozed();

        Assert::same('open', (string) ib_row($id)['status']);
    } finally {
        ib_clear();
    }
});

Harness::test('a snooze still in the future is left alone', function (): void {
    // The control. A waker that woke everything would pass the test above
    // and make the whole feature pointless.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms', [
        'status'        => 'snoozed',
        // UTC-CORRECT: an instant, not a day. wakeSnoozed() compares the
        // whole column against UTC now; no calendar is involved.
        'snoozed_until' => gmdate('Y-m-d H:i:s', time() + 86400),
        'is_unread'     => 0,
    ]);

    try {
        InboxController::wakeSnoozed();

        Assert::same('snoozed', (string) ib_row($id)['status'], 'tomorrow is not now');
    } finally {
        ib_clear();
    }
});

Harness::test('replying to a snoozed conversation reopens it', function () use ($owner): void {
    // Leaving it snoozed would hide the guest's next message behind a timer
    // somebody set before they replied.
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms', [
        'status'        => 'snoozed',
        // UTC-CORRECT: an instant, compared against UTC now.
        'snoozed_until' => gmdate('Y-m-d H:i:s', time() + 86400),
    ]);

    try {
        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reply', [
            'channel' => 'sms',
            'body'    => 'Sorry for the delay — yes, 2pm works.',
        ]);

        $row = ib_row($id);

        Assert::same('open', (string) $row['status']);
        Assert::null($row['snoozed_until']);
    } finally {
        ib_clear();
    }
});

Harness::test('closing and reopening both work, and are audited', function () use ($owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/close', []);

        $closed = ib_row($id);

        Assert::same('closed', (string) $closed['status']);
        Assert::notNull($closed['closed_at'], 'with the time it was closed');
        Assert::same(0, (int) $closed['is_unread']);

        $owner->postWithToken('/inbox/' . $id, '/inbox/' . $id . '/reopen', []);

        $reopened = ib_row($id);

        Assert::same('open', (string) $reopened['status']);
        Assert::null($reopened['closed_at'], 'and the closed stamp is cleared');

        foreach (['conversation.closed', 'conversation.reopened'] as $action) {
            Assert::true(
                DB::exists(
                    "SELECT id FROM {audit_log}
                      WHERE action = :a AND entity_type = 'conversations' AND entity_id = :id",
                    ['a' => $action, 'id' => $id]
                ),
                $action . ' is audited'
            );
        }
    } finally {
        ib_clear();
    }
});

// ------------------------------------------------------------------ inbound

Harness::test('an inbound message from a KNOWN guest joins their thread', function (): void {
    $contactId = ib_contact();
    $phone     = (string) DB::value(
        'SELECT phone_normalized FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );
    $existing = ib_conversation($contactId, 'sms');

    try {
        $before = (int) DB::value('SELECT COUNT(*) FROM {conversations}');

        $result = InboxController::receive([
            'channel' => 'sms',
            'from'    => $phone,
            'body'    => 'Actually, could we make it 3pm?',
        ]);

        Assert::same($existing, $result['conversation_id'], 'the same thread, not a new one');
        Assert::same(
            $before,
            (int) DB::value('SELECT COUNT(*) FROM {conversations}'),
            'so the queue does not fill with one guest'
        );
        Assert::same(2, count(ib_messages($existing)));
    } finally {
        ib_clear();
    }
});

Harness::test('AN UNKNOWN SENDER BECOMES A CONTACT AND AN OPEN CONVERSATION', function (): void {
    // A guest texting a number the venue has never seen is a lead, not an
    // error. Dropping their message is how a venue loses a booking it never
    // knew it had.
    $stranger = '+18457779999';

    try {
        $result = InboxController::receive([
            'channel' => 'sms',
            'from'    => $stranger,
            'body'    => 'Hi, do you do laser tag parties?',
        ]);

        Assert::notNull($result['conversation_id']);
        Assert::notNull($result['message_id']);

        $contact = DB::first(
            'SELECT * FROM {contacts} WHERE phone_normalized = :p',
            ['p' => $stranger]
        );

        Assert::notNull($contact, 'a contact is created for them');
        /** @var array<string,mixed> $contact */
        Assert::same('lead', (string) $contact['lifecycle_stage']);
        Assert::same('inbound_sms', (string) $contact['source'], 'and says where they came from');
        Assert::same(
            0,
            (int) $contact['sms_consent'],
            'but writing in is NOT marketing consent'
        );

        $row = ib_row((int) $result['conversation_id']);

        Assert::same('open', (string) $row['status']);
        Assert::same(1, (int) $row['is_unread'], 'and it is unread, so somebody sees it');
        Assert::same((int) $contact['id'], (int) $row['contact_id']);
    } finally {
        DB::query(
            "DELETE FROM {messages} WHERE contact_id IN (SELECT id FROM {contacts} WHERE phone_normalized = :p)",
            ['p' => $stranger]
        );
        DB::query(
            "DELETE FROM {conversations} WHERE contact_id IN (SELECT id FROM {contacts} WHERE phone_normalized = :p)",
            ['p' => $stranger]
        );
        DB::delete('contacts', 'phone_normalized = :p', ['p' => $stranger]);
        ib_clear();
    }
});

Harness::test('an unknown EMAIL sender is matched on the address, lowercased', function (): void {
    $address = 'Inbox.Stranger.' . bin2hex(random_bytes(3)) . '@Example.Test';

    try {
        $first = InboxController::receive([
            'channel' => 'email',
            'from'    => $address,
            'subject' => 'School group enquiry',
            'body'    => '<p>Thirty students for <b>laser tag</b>.</p>',
        ]);

        // The SAME address in a different case must not make a second contact.
        $second = InboxController::receive([
            'channel' => 'email',
            'from'    => strtolower($address),
            'body'    => 'Following up on the below.',
        ]);

        Assert::same($first['conversation_id'], $second['conversation_id'], 'one thread');
        Assert::same(
            1,
            (int) DB::value(
                'SELECT COUNT(*) FROM {contacts} WHERE email = :e',
                ['e' => strtolower($address)]
            ),
            'and one contact'
        );

        // STORED lowercase, not merely matched. MySQL's default collation is
        // case-insensitive, so a comparison test passes whether or not the
        // address was normalised on the way in -- and the normalisation is
        // the thing that has to hold, because a case-sensitive collation (or
        // a later export, or a join against another table's lowercased
        // column) would split one guest into two.
        Assert::same(
            strtolower($address),
            (string) DB::value(
                'SELECT email FROM {contacts} WHERE email = :e',
                ['e' => strtolower($address)]
            ),
            'the address is stored lowercased'
        );

        // The body is stored as plain text too, so the queue preview is not
        // a wall of markup.
        $messages = ib_messages((int) $first['conversation_id']);

        Assert::notContains('<b>', (string) $messages[0]['body_text'], 'the text copy has no markup');
        Assert::contains('laser tag', (string) $messages[0]['body_text'], 'but keeps the words');
    } finally {
        DB::query(
            "DELETE FROM {messages} WHERE contact_id IN (SELECT id FROM {contacts} WHERE email = :e)",
            ['e' => strtolower($address)]
        );
        DB::query(
            "DELETE FROM {conversations} WHERE contact_id IN (SELECT id FROM {contacts} WHERE email = :e)",
            ['e' => strtolower($address)]
        );
        DB::delete('contacts', 'email = :e', ['e' => strtolower($address)]);
    }
});

Harness::test('A REPLY TO A CLOSED CONVERSATION REOPENS IT, UNREAD', function (): void {
    // A closed thread with an unanswered question in it is the exact failure
    // a queue exists to prevent.
    $contactId = ib_contact();
    $phone     = (string) DB::value(
        'SELECT phone_normalized FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );
    $id = ib_conversation($contactId, 'sms', [
        'status'    => 'closed',
        'closed_at' => now_utc(),
        'is_unread' => 0,
    ]);

    try {
        $result = InboxController::receive([
            'channel' => 'sms',
            'from'    => $phone,
            'body'    => 'One more thing — can we add a cake?',
        ]);

        Assert::same($id, $result['conversation_id'], 'it lands on the same thread');

        $row = ib_row($id);

        Assert::same('open', (string) $row['status'], 'which is open again');
        Assert::same(1, (int) $row['is_unread']);
        Assert::null($row['closed_at']);
    } finally {
        ib_clear();
    }
});

Harness::test('A STOP IS HONOURED AND STILL RECORDED ON THE THREAD', function (): void {
    // Both halves matter. The suppression is what the send path checks; the
    // message on the thread is what lets the venue see that it happened, and
    // when, rather than inferring it from a table nobody opens.
    $contactId = ib_contact(['sms_consent' => 1]);
    $phone     = (string) DB::value(
        'SELECT phone_normalized FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );
    $id = ib_conversation($contactId, 'sms');

    try {
        $result = InboxController::receive([
            'channel' => 'sms',
            'from'    => $phone,
            'body'    => 'STOP',
        ]);

        Assert::same('stop', $result['keyword'], 'the keyword is recognised');
        Assert::notNull($result['reply'], 'and the guest is told it worked');

        Assert::true(
            DB::exists(
                "SELECT id FROM {suppressions} WHERE channel = 'sms' AND address = :a",
                ['a' => $phone]
            ),
            'the number is suppressed'
        );
        Assert::same(
            0,
            (int) DB::value('SELECT sms_consent FROM {contacts} WHERE id = :id', ['id' => $contactId]),
            'the consent is cleared'
        );

        $bodies = array_map(
            static fn (array $m): string => (string) $m['body'],
            ib_messages($id)
        );

        Assert::inArray('STOP', $bodies, 'and the message itself is on the thread');
    } finally {
        ib_clear();
    }
});

Harness::test('an ordinary message containing the word stop is NOT a keyword', function (): void {
    // Treating it as one closes the conversation and loses a complaint a
    // person needed to read.
    $contactId = ib_contact(['sms_consent' => 1]);
    $phone     = (string) DB::value(
        'SELECT phone_normalized FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );

    try {
        $result = InboxController::receive([
            'channel' => 'sms',
            'from'    => $phone,
            'body'    => 'Can we stop by early to set up?',
        ]);

        Assert::null($result['keyword']);
        Assert::false(
            DB::exists(
                "SELECT id FROM {suppressions} WHERE channel = 'sms' AND address = :a",
                ['a' => $phone]
            ),
            'nothing is suppressed behind the venue\'s back'
        );
        Assert::same(
            1,
            (int) DB::value('SELECT sms_consent FROM {contacts} WHERE id = :id', ['id' => $contactId]),
            'and their consent is untouched'
        );
    } finally {
        ib_clear();
    }
});

Harness::test('an inbound message with no sender is dropped rather than half-stored', function (): void {
    $before = (int) DB::value('SELECT COUNT(*) FROM {conversations}');

    $result = InboxController::receive(['channel' => 'sms', 'from' => '   ', 'body' => 'anything']);

    Assert::null($result['conversation_id']);
    Assert::null($result['message_id']);
    Assert::same($before, (int) DB::value('SELECT COUNT(*) FROM {conversations}'));
});

// ------------------------------------------------------------------- canned

Harness::test('canned replies are offered for the conversation\'s channel', function () use ($owner): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId, 'sms');

    $anyId = DB::insert('canned_replies', [
        'title'      => 'InboxTest any channel',
        'body'       => 'Hi {{first_name}}, leave it with me.',
        'channel'    => 'any',
        'created_at' => now_utc(),
    ]);
    $emailOnlyId = DB::insert('canned_replies', [
        'title'      => 'InboxTest email only',
        'body'       => 'Please find the quote attached.',
        'channel'    => 'email',
        'created_at' => now_utc(),
    ]);

    try {
        $page = $owner->get('/inbox/' . $id);

        Assert::contains('InboxTest any channel', $page['body'], 'an any-channel reply is offered');
        Assert::notContains(
            'InboxTest email only',
            $page['body'],
            'and an email-only one is not offered on a text thread'
        );
    } finally {
        DB::delete('canned_replies', 'id = :id', ['id' => $anyId]);
        DB::delete('canned_replies', 'id = :id', ['id' => $emailOnlyId]);
        ib_clear();
    }
});

// -------------------------------------------------------------------- CSRF

Harness::test('every write needs a CSRF token', function () use ($owner, $baseUrl): void {
    $contactId = ib_contact();
    $id        = ib_conversation($contactId);

    try {
        foreach ([
            'reply'  => ['channel' => 'internal', 'body' => 'x'],
            'assign' => ['assigned_to' => '0'],
            'snooze' => ['option' => 'tomorrow'],
            'close'  => [],
            'reopen' => [],
        ] as $action => $fields) {
            $response = $owner->post('/inbox/' . $id . '/' . $action, $fields);

            Assert::same(419, $response['status'], $action . ' refuses a tokenless post');
        }

        Assert::same('open', (string) ib_row($id)['status'], 'and nothing changed');
        Assert::same(1, count(ib_messages($id)));
    } finally {
        ib_clear();
    }
});

Harness::test('the suite leaves nothing behind', function (): void {
    ib_clear();

    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE first_name = 'InboxTest'")
    );
    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {conversations} WHERE subject = 'InboxTest thread'")
    );
});
