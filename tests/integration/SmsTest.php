<?php

declare(strict_types=1);

/**
 * The text-message send path, against real rows.
 *
 * THE RULES ARE ENFORCED IN THE SEND PATH, NOT IN THE USER INTERFACE, and
 * this file is what makes that claim checkable. Every test here bypasses the
 * UI entirely and calls `Sms::send()` the way an automation, a cron job or a
 * future REST endpoint would — because those are the callers a disabled
 * button does not protect anybody from.
 *
 * What is being promised:
 *
 *   * A guest who texted STOP is never texted again, transactional or not.
 *   * Marketing without `sms_consent` does not go out.
 *   * A bulk text always carries a way to stop.
 *   * Quiet hours DEFER rather than drop — a message nobody sends is worse
 *     than a message sent an hour late.
 *   * The venue is told what a send will cost BEFORE it happens, in segments,
 *     and the preview cannot disagree with the send.
 *
 * A regression in any of these is not a bug report. It is a complaint to a
 * regulator, or a Saturday's bookings that never went out.
 */

use App\Core\DB;
use App\Core\Settings;
use App\Core\Sms;
use App\Services\SmsPolicy;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/sms');

if (!test_boot()) {
    Harness::skip('sms suite', 'no database available');

    return;
}

// ------------------------------------------------------------------ fixtures

/**
 * Run a closure with known settings, restoring them afterwards.
 *
 * @param array<string,mixed> $settings
 */
function with_sms_settings(array $settings, callable $callback): mixed
{
    $previous = [];

    foreach ($settings as $key => $value) {
        $previous[$key] = Settings::get($key);
        Settings::set($key, $value);
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            Settings::set($key, $value);
        }
    }
}

/**
 * The baseline: nothing paused, no cap, no quiet hours, no Safe Mode.
 *
 * Quiet hours are OFF by default here because the suite runs at whatever time
 * CI happens to run, and a test that passes at two in the afternoon and fails
 * at nine in the evening is worse than no test. The quiet-hour rules get
 * their own tests, which set the window relative to the current minute so
 * they hold at any hour.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function sms_live(array $overrides = []): array
{
    return array_merge([
        'system.safe_mode'            => false,
        'system.sending_paused'       => false,
        'system.message_cap_per_week' => 0,
        'sms.from_number'             => '+18454692116',
        'sms.safe_mode_number'        => null,
        'sms.quiet_start_minutes'     => 0,
        'sms.quiet_end_minutes'       => 0,
        'sms.cost_per_segment_millicents' => 750,
    ], $overrides);
}

/**
 * Capture what would have gone instead of sending it.
 *
 * @param array<string,mixed> $message
 * @return array{result:array<string,mixed>,sent:list<array<string,mixed>>}
 */
function sms_capture(array $message): array
{
    Sms::useDriver('log');
    Sms::startCapturing();

    try {
        $result = Sms::send($message);
        $sent   = Sms::captured();
    } finally {
        Sms::stopCapturing();
        Sms::useDriver(null);
    }

    return ['result' => $result, 'sent' => $sent];
}

/** A guest, with whatever consent state the test needs. */
function sms_contact(array $overrides = []): int
{
    static $n = 0;
    $n++;

    $phone = '+1845555' . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT);

    return DB::insert('contacts', array_merge([
        'first_name'       => 'SmsTest',
        'last_name'        => 'Guest ' . $n,
        'email'            => 'sms.' . bin2hex(random_bytes(4)) . '@example.test',
        'phone'            => $phone,
        'phone_normalized' => $phone,
        'sms_consent'      => 1,
        'sms_consent_at'   => now_utc(),
        'lifecycle_stage'  => 'lead',
        'created_at'       => now_utc(),
        'updated_at'       => now_utc(),
    ], $overrides));
}

function sms_phone(int $contactId): string
{
    return (string) DB::value(
        'SELECT phone_normalized FROM {contacts} WHERE id = :id',
        ['id' => $contactId]
    );
}

function sms_clear(): void
{
    DB::query("DELETE FROM {messages} WHERE channel IN ('sms','mms')");
    DB::delete('suppressions', "channel = 'sms' AND address LIKE :p", ['p' => '+1845555%']);
    DB::query("DELETE FROM {contacts} WHERE first_name = 'SmsTest'");
}

/** A body that satisfies the bulk opt-out rule, so other rules can be tested alone. */
const SMS_BULK = 'Half price go-karts this Saturday at the Castle. Reply STOP to opt out.';

sms_clear();

// --------------------------------------------------------------- the shape

Harness::test('an undeliverable number is refused before anything is recorded', function (): void {
    $before = (int) DB::value("SELECT COUNT(*) FROM {messages} WHERE channel = 'sms'");

    foreach (['', '   ', 'not a number', '12345', '1'] as $bad) {
        $result = Sms::send(['to' => $bad, 'body' => SMS_BULK]);

        Assert::false($result['sent'], '"' . $bad . '" is not a number');
        Assert::same('invalid_number', $result['reason']);
    }

    Assert::same(
        $before,
        (int) DB::value("SELECT COUNT(*) FROM {messages} WHERE channel = 'sms'"),
        'and nothing is written for a message that could never be sent'
    );
});

Harness::test('an empty message is refused', function (): void {
    $result = Sms::send(['to' => '+18455551234', 'body' => '   ']);

    Assert::same('empty_body', $result['reason']);
});

Harness::test('a message too long to arrive in one piece is REFUSED, not truncated', function (): void {
    // Truncating loses the end, which is where the opt-out line and the offer
    // code live — so a truncated bulk text is both useless and non-compliant.
    $long   = str_repeat('a', 153 * (SmsPolicy::MAX_SEGMENTS + 1)) . ' Reply STOP to opt out.';
    $result = Sms::send(['to' => '+18455551234', 'body' => $long]);

    Assert::same('too_long', $result['reason']);
    Assert::greaterThan(SmsPolicy::MAX_SEGMENTS, SmsPolicy::segments($long), 'precondition');
});

// ------------------------------------------------------------ the kill switch

Harness::test('the global pause stops even a transactional text', function (): void {
    $result = with_sms_settings(sms_live(['system.sending_paused' => true]), static fn (): array =>
        Sms::send([
            'to'   => '+18455551234',
            'body' => 'Your party is confirmed for Saturday at 2pm.',
            'kind' => Sms::TRANSACTIONAL,
        ]));

    Assert::false($result['sent']);
    Assert::same('sending_paused', $result['reason'], 'the stop button must stop everything');
});

// ------------------------------------------------------------- the opt-out line

Harness::test('A BULK TEXT WITHOUT A WAY TO STOP IS REFUSED', function (): void {
    // The template validator blocks the save, and this blocks the send,
    // because an automation has no template to validate.
    $result = with_sms_settings(sms_live(), static fn (): array =>
        Sms::send([
            'to'   => '+18455551234',
            'body' => 'Half price go-karts this Saturday!',
        ]));

    Assert::false($result['sent']);
    Assert::same('no_opt_out', $result['reason']);

    // The positive control: the same message WITH the line goes.
    $good = with_sms_settings(sms_live(), static fn (): array =>
        sms_capture(['to' => '+18455551234', 'body' => SMS_BULK]));

    Assert::true($good['result']['sent'], 'and the same offer with an opt-out line sends');
});

Harness::test('a TRANSACTIONAL text needs no opt-out line', function (): void {
    // "Reply STOP to opt out" on a booking confirmation is noise, and the
    // rule does not apply to a message the guest asked for.
    $result = with_sms_settings(sms_live(), static fn (): array =>
        sms_capture([
            'to'   => '+18455551234',
            'body' => 'Your party is confirmed for Saturday at 2pm.',
            'kind' => Sms::TRANSACTIONAL,
        ]));

    Assert::true($result['result']['sent']);
});

// ------------------------------------------------------------------ suppression

Harness::test('A SUPPRESSED NUMBER IS NEVER TEXTED AGAIN, transactional included', function (): void {
    // A guest who texted STOP gave an instruction, not a preference. This is
    // the one rule with no transactional exemption anywhere in the app.
    $contactId = sms_contact();
    $phone     = sms_phone($contactId);

    try {
        DB::insert('suppressions', [
            'channel'    => 'sms',
            'address'    => $phone,
            'reason'     => 'unsubscribe',
            'source'     => 'test',
            'created_at' => now_utc(),
        ]);

        foreach ([Sms::MARKETING, Sms::TRANSACTIONAL] as $kind) {
            $result = with_sms_settings(sms_live(), static fn (): array =>
                Sms::send([
                    'to'         => $phone,
                    'body'       => SMS_BULK,
                    'kind'       => $kind,
                    'contact_id' => $contactId,
                ]));

            Assert::false($result['sent'], $kind . ' is refused');
            Assert::same('suppressed', $result['reason']);
        }

        Assert::same(
            0,
            (int) DB::value(
                "SELECT COUNT(*) FROM {messages} WHERE channel = 'sms' AND to_address = :p",
                ['p' => $phone]
            ),
            'and nothing is recorded as queued to them'
        );
    } finally {
        DB::delete('suppressions', 'channel = :c AND address = :a', ['c' => 'sms', 'a' => $phone]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

// --------------------------------------------------------------------- consent

Harness::test('MARKETING WITHOUT SMS CONSENT DOES NOT GO OUT', function (): void {
    $contactId = sms_contact(['sms_consent' => 0, 'sms_consent_at' => null]);

    try {
        $phone = sms_phone($contactId);

        $result = with_sms_settings(sms_live(), static fn (): array =>
            Sms::send([
                'to'         => $phone,
                'body'       => SMS_BULK,
                'contact_id' => $contactId,
            ]));

        Assert::false($result['sent']);
        Assert::same('no_consent', $result['reason']);

        // But a transactional text does: a guest with a party on Saturday
        // needs to be told the room has changed whether or not they wanted
        // offers.
        $transactional = with_sms_settings(sms_live(), static fn (): array =>
            sms_capture([
                'to'         => $phone,
                'body'       => 'Your party has moved to Room 3.',
                'kind'       => Sms::TRANSACTIONAL,
                'contact_id' => $contactId,
            ]));

        Assert::true($transactional['result']['sent'], 'transactional still reaches them');
    } finally {
        DB::query("DELETE FROM {messages} WHERE contact_id = :id", ['id' => $contactId]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('do_not_contact stops EVERYTHING, consent or not', function (): void {
    // A broader instruction than "no offers", given by a different route.
    $contactId = sms_contact(['sms_consent' => 1, 'do_not_contact' => 1]);

    try {
        $phone = sms_phone($contactId);

        foreach ([Sms::MARKETING, Sms::TRANSACTIONAL] as $kind) {
            $result = with_sms_settings(sms_live(), static fn (): array =>
                Sms::send([
                    'to'         => $phone,
                    'body'       => SMS_BULK,
                    'kind'       => $kind,
                    'contact_id' => $contactId,
                ]));

            Assert::same('do_not_contact', $result['reason'], $kind . ' is refused');
        }
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('CONSENT BELONGS TO THE NUMBER, not to the person in the abstract', function (): void {
    // Somebody typing a different number into the composer against a consented
    // contact would be texting a number that never opted in — quite possibly
    // a stranger who now has the venue's marketing and no idea why.
    $contactId = sms_contact(['sms_consent' => 1]);

    try {
        $theirs    = sms_phone($contactId);
        $somebody  = '+18455559999';

        $result = with_sms_settings(sms_live(), static fn (): array =>
            Sms::send([
                'to'         => $somebody,
                'body'       => SMS_BULK,
                'contact_id' => $contactId,
            ]));

        Assert::false($result['sent']);
        Assert::same('number_mismatch', $result['reason']);

        // The control: their own number works.
        $own = with_sms_settings(sms_live(), static fn (): array =>
            sms_capture([
                'to'         => $theirs,
                'body'       => SMS_BULK,
                'contact_id' => $contactId,
            ]));

        Assert::true($own['result']['sent'], 'the number they opted in on sends');

        // A TRANSACTIONAL text to another number is allowed: a parent ringing
        // from a different phone to ask about today's party is a real thing,
        // and that reply is not marketing.
        $reply = with_sms_settings(sms_live(), static fn (): array =>
            sms_capture([
                'to'         => $somebody,
                'body'       => 'Yes, 2pm on Saturday is still free.',
                'kind'       => Sms::TRANSACTIONAL,
                'contact_id' => $contactId,
            ]));

        Assert::true($reply['result']['sent'], 'but a reply may go to the number that asked');
    } finally {
        DB::query("DELETE FROM {messages} WHERE contact_id = :id", ['id' => $contactId]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('a contact that does not exist is refused rather than texted anyway', function (): void {
    $result = with_sms_settings(sms_live(), static fn (): array =>
        Sms::send([
            'to'         => '+18455551234',
            'body'       => SMS_BULK,
            'contact_id' => 99999999,
        ]));

    Assert::same('no_contact', $result['reason']);
});

// ---------------------------------------------------------------- quiet hours

Harness::test('QUIET HOURS DEFER A MARKETING TEXT, they do not drop it', function (): void {
    // A message nobody sends is worse than a message sent an hour late: the
    // venue pressed the button, saw nothing fail, and the offer never went.
    // So send() reports `quiet_hours` WITH the minutes to wait, and the caller
    // queues it.
    //
    // The window is set relative to the current minute, so this holds at
    // whatever hour CI runs.
    $local = new DateTimeImmutable('now', new DateTimeZone(Settings::timezone()));
    $now   = ((int) $local->format('G')) * 60 + (int) $local->format('i');

    // A window that opens in two hours and closes three hours later.
    $start = ($now + 120) % 1440;
    $end   = ($now + 300) % 1440;

    $result = with_sms_settings(
        sms_live(['sms.quiet_start_minutes' => $start, 'sms.quiet_end_minutes' => $end]),
        static fn (): array => Sms::send(['to' => '+18455551234', 'body' => SMS_BULK])
    );

    Assert::false($result['sent']);
    Assert::same('quiet_hours', $result['reason']);
    Assert::same(120, $result['deferred_minutes'], 'and it says how long to wait');
    Assert::greaterThan(0, $result['segments'], 'while still reporting what it would cost to send');
});

Harness::test('and a TRANSACTIONAL text ignores them entirely', function (): void {
    // A guest whose party starts in an hour needs to know the room has
    // changed, at whatever time that happens.
    $local = new DateTimeImmutable('now', new DateTimeZone(Settings::timezone()));
    $now   = ((int) $local->format('G')) * 60 + (int) $local->format('i');

    $result = with_sms_settings(
        sms_live([
            'sms.quiet_start_minutes' => ($now + 120) % 1440,
            'sms.quiet_end_minutes'   => ($now + 300) % 1440,
        ]),
        static fn (): array => sms_capture([
            'to'   => '+18455551234',
            'body' => 'Your party has moved to Room 3.',
            'kind' => Sms::TRANSACTIONAL,
        ])
    );

    Assert::true($result['result']['sent']);
});

Harness::test('an explicit override sends a marketing text inside quiet hours', function (): void {
    // The escape hatch a weather closure needs. It exists, it is explicit, and
    // nothing reaches it by accident.
    $local = new DateTimeImmutable('now', new DateTimeZone(Settings::timezone()));
    $now   = ((int) $local->format('G')) * 60 + (int) $local->format('i');

    $result = with_sms_settings(
        sms_live([
            'sms.quiet_start_minutes' => ($now + 120) % 1440,
            'sms.quiet_end_minutes'   => ($now + 300) % 1440,
        ]),
        static fn (): array => sms_capture([
            'to'                 => '+18455551234',
            'body'               => SMS_BULK,
            'ignore_quiet_hours' => true,
        ])
    );

    Assert::true($result['result']['sent']);
});

// ------------------------------------------------------------- frequency cap

Harness::test('the weekly cap counts only campaign texts, and stops them', function (): void {
    $contactId = sms_contact();

    try {
        $phone = sms_phone($contactId);

        // Two campaign texts already this week.
        for ($i = 0; $i < 2; $i++) {
            DB::insert('messages', [
                'contact_id'  => $contactId,
                'direction'   => 'outbound',
                'channel'     => 'sms',
                'to_address'  => $phone,
                'body'        => 'earlier offer',
                'campaign_id' => null,
                'status'      => 'sent',
                'created_at'  => now_utc(),
            ]);
        }

        // With campaign_id NULL they are one-to-one messages from the inbox,
        // which the cap deliberately ignores: a rep answering a guest three
        // times in a day is a conversation, not a blast.
        $conversational = with_sms_settings(
            sms_live(['system.message_cap_per_week' => 2]),
            static fn (): array => sms_capture([
                'to' => $phone, 'body' => SMS_BULK, 'contact_id' => $contactId,
            ])
        );

        Assert::true($conversational['result']['sent'], 'one-to-one messages do not fill the cap');

        // Now make them campaign sends.
        $campaignId = DB::insert('campaigns', [
            'name'       => 'SmsTest cap campaign',
            'channel'    => 'sms',
            'status'     => 'draft',
            'created_at' => now_utc(),
        ]);

        DB::query(
            'UPDATE {messages} SET campaign_id = :c WHERE contact_id = :id',
            ['c' => $campaignId, 'id' => $contactId]
        );

        $capped = with_sms_settings(
            sms_live(['system.message_cap_per_week' => 2]),
            static fn (): array => Sms::send([
                'to' => $phone, 'body' => SMS_BULK, 'contact_id' => $contactId,
            ])
        );

        Assert::false($capped['sent']);
        Assert::same('frequency_cap', $capped['reason']);

        DB::delete('campaigns', 'id = :id', ['id' => $campaignId]);
    } finally {
        DB::query("DELETE FROM {messages} WHERE contact_id = :id", ['id' => $contactId]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

// ---------------------------------------------------------------- 10DLC

Harness::test('a number recorded as REJECTED for 10DLC refuses marketing, not confirmations', function (): void {
    // Carriers filter unregistered bulk traffic SILENTLY, so a send against
    // such a number reports every message as "sent" while nothing arrives —
    // and a campaign report full of confident successes is worse than a
    // refusal somebody can read. Refusing a booking confirmation for the same
    // reason would do the venue more harm than the filtering does.
    $from = '+18455558888';

    $numberId = DB::insert('sms_numbers', [
        'phone'         => $from,
        'provider'      => 'twilio',
        'tendlc_status' => 'rejected',
        'is_active'     => 1,
        'created_at'    => now_utc(),
    ]);

    try {
        $marketing = with_sms_settings(sms_live(['sms.from_number' => $from]), static fn (): array =>
            Sms::send(['to' => '+18455551234', 'body' => SMS_BULK]));

        Assert::false($marketing['sent']);
        Assert::same('unregistered_sender', $marketing['reason']);

        $transactional = with_sms_settings(sms_live(['sms.from_number' => $from]), static fn (): array =>
            sms_capture([
                'to'   => '+18455551234',
                'body' => 'Your party is confirmed.',
                'kind' => Sms::TRANSACTIONAL,
            ]));

        Assert::true($transactional['result']['sent'], 'a confirmation still goes');

        // PENDING sends: a venue mid-registration must not be stopped.
        DB::update('sms_numbers', ['tendlc_status' => 'pending'], 'id = :id', ['id' => $numberId]);

        $pending = with_sms_settings(sms_live(['sms.from_number' => $from]), static fn (): array =>
            sms_capture(['to' => '+18455551234', 'body' => SMS_BULK]));

        Assert::true($pending['result']['sent'], 'and a registration in progress is not a refusal');
    } finally {
        DB::query("DELETE FROM {messages} WHERE from_address = :p", ['p' => $from]);
        DB::delete('sms_numbers', 'id = :id', ['id' => $numberId]);
    }
});

Harness::test('A NUMBER WITH NO 10DLC ROW RECORDED IS NOT TREATED AS UNREGISTERED', function (): void {
    // The failure mode this guard must not have. An install that has never
    // seen the settings page, or a venue outside the United States where
    // 10DLC does not apply, must not silently stop texting because a field
    // nobody has heard of is empty.
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {sms_numbers} WHERE phone = :p', ['p' => '+18455557777']),
        'precondition: nothing recorded for this number'
    );

    $result = with_sms_settings(sms_live(['sms.from_number' => '+18455557777']), static fn (): array =>
        sms_capture(['to' => '+18455551234', 'body' => SMS_BULK]));

    Assert::true($result['result']['sent']);
});

// ------------------------------------------------------------------ Safe Mode

Harness::test('SAFE MODE REDIRECTS EVERY TEXT TO ONE NUMBER, and says where it was going', function (): void {
    $owner = '+18455550000';

    $result = with_sms_settings(
        sms_live(['system.safe_mode' => true, 'sms.safe_mode_number' => $owner]),
        static fn (): array => sms_capture(['to' => '+18455551234', 'body' => SMS_BULK])
    );

    Assert::true($result['result']['sent']);
    Assert::true($result['result']['redirected']);
    Assert::count(1, $result['sent']);
    Assert::same($owner, $result['sent'][0]['to'], 'it went to the owner');
    Assert::same('+18455551234', $result['sent'][0]['original_to'], 'and says who it was for');
    Assert::contains('+18455551234', $result['sent'][0]['body'], 'in the message itself');
});

Harness::test('Safe Mode with no number to redirect to REFUSES rather than sending', function (): void {
    // The one way Safe Mode could do the opposite of its job.
    $result = with_sms_settings(
        sms_live(['system.safe_mode' => true, 'sms.safe_mode_number' => null]),
        static fn (): array => Sms::send(['to' => '+18455551234', 'body' => SMS_BULK])
    );

    Assert::false($result['sent']);
    Assert::same('safe_mode_no_number', $result['reason']);
});

Harness::test('and a Safe Mode prefix that ADDS a segment is re-measured', function (): void {
    // The prefix is real characters a real carrier charges for, and this
    // fixture sits ON the boundary on purpose: exactly one segment as typed,
    // two once the prefix is on. A test written with a body comfortably
    // inside its segment proves nothing here — the un-prefixed and prefixed
    // counts are the same number, so reporting either one passes. (Measured:
    // the first version of this test did exactly that and survived the
    // mutation that drops the re-measure.)
    $tail = ' Reply STOP to opt out.';
    $body = str_repeat('a', 160 - strlen($tail)) . $tail;

    Assert::same(160, SmsPolicy::length($body), 'precondition: exactly one full segment');
    Assert::same(1, SmsPolicy::segments($body));

    $result = with_sms_settings(
        sms_live(['system.safe_mode' => true, 'sms.safe_mode_number' => '+18455550000']),
        static fn (): array => sms_capture(['to' => '+18455551234', 'body' => $body])
    );

    Assert::count(1, $result['sent']);

    $went = (string) $result['sent'][0]['body'];

    Assert::greaterThan(
        1,
        SmsPolicy::segments($went),
        'precondition: the prefix really did push it over'
    );
    Assert::same(
        SmsPolicy::segments($went),
        $result['result']['segments'],
        'and the reported count is the count of what actually went, not of what was typed'
    );
});

// --------------------------------------------------------- the message trail

Harness::test('a sent text is recorded with its segments, cost and provider', function (): void {
    $contactId = sms_contact();

    try {
        $phone = sms_phone($contactId);
        $body  = str_repeat('Half price karts. ', 12) . 'Reply STOP to opt out.';

        Assert::greaterThan(1, SmsPolicy::segments($body), 'precondition: more than one segment');

        $result = with_sms_settings(sms_live(), static fn (): array =>
            sms_capture(['to' => $phone, 'body' => $body, 'contact_id' => $contactId]));

        Assert::true($result['result']['sent']);

        $row = DB::first(
            'SELECT * FROM {messages} WHERE id = :id',
            ['id' => (int) $result['result']['message_id']]
        );

        Assert::notNull($row);
        /** @var array<string,mixed> $row */
        Assert::same('outbound', (string) $row['direction']);
        Assert::same('sms', (string) $row['channel']);
        Assert::same('sent', (string) $row['status']);
        Assert::same($phone, (string) $row['to_address']);
        Assert::same(SmsPolicy::segments($body), (int) $row['segments'], 'the segment count is stored');

        // COST IS IN CENTS. Dividing by a hundred here would record a
        // three-cent text as three hundredths of a cent, and the usage report
        // would read as though texting were free.
        Assert::same(
            SmsPolicy::costCents(SmsPolicy::segments($body), 750),
            (int) round((float) $row['cost_cents']),
            'and the cost in cents, not dollars'
        );
    } finally {
        DB::query("DELETE FROM {messages} WHERE contact_id = :id", ['id' => $contactId]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('THE USAGE LEDGER COUNTS SEGMENTS, not messages', function (): void {
    // A three-segment text costs three times as much. A ledger counting it as
    // one send under-reports the venue's bill by whatever fraction of its
    // traffic is long — which is most of it, because an opt-out line appended
    // to a message makes it longer than the one somebody typed.
    $body = str_repeat('a', 400) . ' Reply STOP to opt out.';

    Assert::same(3, SmsPolicy::segments($body), 'precondition: three segments');

    $before = (int) DB::value("SELECT COUNT(*) FROM {usage_ledger} WHERE channel = 'sms'");

    // The log driver is a real driver, so this goes all the way through
    // meter() rather than stopping at the capture branch.
    $result = with_sms_settings(sms_live(), static function () use ($body): array {
        Sms::useDriver('log');

        try {
            return Sms::send(['to' => '+18455551234', 'body' => $body]);
        } finally {
            Sms::useDriver(null);
        }
    });

    Assert::true($result['sent']);

    $ledger = DB::first(
        "SELECT * FROM {usage_ledger}
          WHERE channel = 'sms' AND entity_type = 'messages' AND entity_id = :id",
        ['id' => (int) $result['message_id']]
    );

    Assert::notNull($ledger, 'a send writes the ledger');
    /** @var array<string,mixed> $ledger */
    Assert::same(3, (int) $ledger['units'], 'three units for three segments');
    Assert::same('log', (string) $ledger['provider']);
    Assert::greaterThan(
        $before,
        (int) DB::value("SELECT COUNT(*) FROM {usage_ledger} WHERE channel = 'sms'")
    );

    DB::delete('usage_ledger', 'id = :id', ['id' => (int) $ledger['id']]);
    DB::delete('messages', 'id = :id', ['id' => (int) $result['message_id']]);
});

// ------------------------------------------------------------------- preview

Harness::test('THE PREVIEW CANNOT DISAGREE WITH THE SEND', function (): void {
    // The composer's cost estimate and the campaign approval screen both need
    // to know what will happen. A preview that reimplemented the rules is how
    // it comes to say "ready to send" about a message the send path refuses.
    $consented = sms_contact(['sms_consent' => 1]);
    $refused   = sms_contact(['sms_consent' => 0]);

    try {
        foreach ([$consented => true, $refused => false] as $contactId => $expected) {
            $phone   = sms_phone((int) $contactId);
            $message = ['to' => $phone, 'body' => SMS_BULK, 'contact_id' => (int) $contactId];

            $preview = with_sms_settings(sms_live(), static fn (): array => Sms::preview($message));
            $actual  = with_sms_settings(sms_live(), static fn (): array => sms_capture($message));

            Assert::same(
                $expected,
                $preview['ok'],
                'the preview agrees about contact ' . $contactId
            );
            Assert::same(
                $preview['ok'],
                $actual['result']['sent'],
                'and the send does the same thing the preview said'
            );

            if (!$expected) {
                Assert::same('no_consent', $preview['reason'], 'with the same reason');
                Assert::same($preview['reason'], $actual['result']['reason']);
            }
        }
    } finally {
        DB::query("DELETE FROM {messages} WHERE contact_id IN (:a, :b)", ['a' => $consented, 'b' => $refused]);
        DB::delete('contacts', 'id = :id', ['id' => $consented]);
        DB::delete('contacts', 'id = :id', ['id' => $refused]);
    }
});

Harness::test('and A PREVIEW SENDS NOTHING AND LEAVES NOTHING BEHIND', function (): void {
    // It runs the real send path against a capture buffer, which means it
    // writes a message row on the way through. Leaving that row would put a
    // text in the guest's history that was never sent — and on a campaign
    // estimate, forty thousand of them.
    $before = (int) DB::value("SELECT COUNT(*) FROM {messages} WHERE channel = 'sms'");
    $ledger = (int) DB::value("SELECT COUNT(*) FROM {usage_ledger} WHERE channel = 'sms'");

    $preview = with_sms_settings(sms_live(), static fn (): array =>
        Sms::preview(['to' => '+18455551234', 'body' => SMS_BULK]));

    Assert::true($preview['ok']);
    Assert::greaterThan(0, $preview['cost_cents'], 'it still reports the cost');
    Assert::same(
        $before,
        (int) DB::value("SELECT COUNT(*) FROM {messages} WHERE channel = 'sms'"),
        'and no message row survives it'
    );
    Assert::same(
        $ledger,
        (int) DB::value("SELECT COUNT(*) FROM {usage_ledger} WHERE channel = 'sms'"),
        'and nothing is metered for a message nobody sent'
    );
    Assert::count(0, Sms::captured(), 'and the capture buffer is left as it was');
});

Harness::test('a preview asked about a transactional text is answered about one', function (): void {
    // Marketing is only the DEFAULT, because it is the stricter of the two. A
    // preview that silently relaxed the rules would be the wrong way round;
    // one that silently tightened them would tell a venue it cannot send a
    // confirmation it can.
    $contactId = sms_contact(['sms_consent' => 0]);

    try {
        $phone = sms_phone($contactId);

        $marketing = with_sms_settings(sms_live(), static fn (): array => Sms::preview([
            'to' => $phone, 'body' => SMS_BULK, 'contact_id' => $contactId,
        ]));

        Assert::false($marketing['ok'], 'no consent, so no offer');

        $transactional = with_sms_settings(sms_live(), static fn (): array => Sms::preview([
            'to'         => $phone,
            'body'       => 'Your party is confirmed.',
            'kind'       => Sms::TRANSACTIONAL,
            'contact_id' => $contactId,
        ]));

        Assert::true($transactional['ok'], 'but the confirmation is fine');
    } finally {
        DB::query("DELETE FROM {messages} WHERE contact_id = :id", ['id' => $contactId]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

// ------------------------------------------------------------ STOP and START

Harness::test('STOP SUPPRESSES THE NUMBER, clears the consent and logs it', function (): void {
    $contactId = sms_contact(['sms_consent' => 1]);

    try {
        $phone = sms_phone($contactId);

        $outcome = Sms::handleKeyword($phone, 'STOP');

        Assert::same('stop', $outcome['action']);
        Assert::notNull($outcome['reply'], 'and the guest is told it worked');

        Assert::true(
            DB::exists('SELECT id FROM {suppressions} WHERE channel = :c AND address = :a', [
                'c' => 'sms', 'a' => $phone,
            ]),
            'the number is suppressed'
        );

        Assert::same(
            0,
            (int) DB::value('SELECT sms_consent FROM {contacts} WHERE id = :id', ['id' => $contactId]),
            'the consent is cleared on the contact too, so a person can see it'
        );

        Assert::true(
            DB::exists(
                "SELECT id FROM {consent_log} WHERE contact_id = :id AND channel = 'sms' AND action = 'revoked'",
                ['id' => $contactId]
            ),
            'and it is logged as revoked'
        );

        // The point of all that: the send path now refuses.
        $result = with_sms_settings(sms_live(), static fn (): array =>
            Sms::send(['to' => $phone, 'body' => SMS_BULK, 'contact_id' => $contactId]));

        Assert::same('suppressed', $result['reason']);
    } finally {
        DB::delete('suppressions', 'channel = :c AND address = :a', ['c' => 'sms', 'a' => sms_phone($contactId)]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('a STOP from a number the venue has never seen still works', function (): void {
    // A suppression that depended on finding a contact would fail for exactly
    // the people most likely to complain about it.
    $stranger = '+18455556543';

    try {
        $outcome = Sms::handleKeyword($stranger, 'stop');

        Assert::same('stop', $outcome['action']);
        Assert::true(
            DB::exists('SELECT id FROM {suppressions} WHERE channel = :c AND address = :a', [
                'c' => 'sms', 'a' => $stranger,
            ]),
            'the number is suppressed with no contact behind it'
        );

        $result = with_sms_settings(sms_live(), static fn (): array =>
            Sms::send(['to' => $stranger, 'body' => SMS_BULK]));

        Assert::same('suppressed', $result['reason']);
    } finally {
        DB::delete('suppressions', 'channel = :c AND address = :a', ['c' => 'sms', 'a' => $stranger]);
    }
});

Harness::test('START lifts the suppression but DOES NOT clear do_not_contact', function (): void {
    // do_not_contact is a broader instruction, given by a different route. A
    // text saying START is permission to text again; it is not permission to
    // undo a request never to be contacted at all.
    $contactId = sms_contact(['sms_consent' => 0, 'do_not_contact' => 1]);

    try {
        $phone = sms_phone($contactId);

        Sms::handleKeyword($phone, 'STOP');
        $outcome = Sms::handleKeyword($phone, 'START');

        Assert::same('start', $outcome['action']);
        Assert::false(
            DB::exists('SELECT id FROM {suppressions} WHERE channel = :c AND address = :a', [
                'c' => 'sms', 'a' => $phone,
            ]),
            'the suppression is lifted'
        );
        Assert::same(
            1,
            (int) DB::value('SELECT sms_consent FROM {contacts} WHERE id = :id', ['id' => $contactId]),
            'and consent is recorded afresh'
        );
        Assert::same(
            1,
            (int) DB::value('SELECT do_not_contact FROM {contacts} WHERE id = :id', ['id' => $contactId]),
            'but the do-not-contact instruction stands'
        );

        // So the send path still refuses, for the right reason.
        $result = with_sms_settings(sms_live(), static fn (): array =>
            Sms::send(['to' => $phone, 'body' => SMS_BULK, 'contact_id' => $contactId]));

        Assert::same('do_not_contact', $result['reason']);
    } finally {
        DB::delete('suppressions', 'channel = :c AND address = :a', ['c' => 'sms', 'a' => sms_phone($contactId)]);
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

Harness::test('an ordinary message is not a keyword and changes nothing', function (): void {
    $contactId = sms_contact(['sms_consent' => 1]);

    try {
        $phone = sms_phone($contactId);

        foreach (['please stop sending me these', 'is the party still on?', 'yes please, 2pm works'] as $body) {
            $outcome = Sms::handleKeyword($phone, $body);

            Assert::null($outcome['action'], '"' . $body . '" is a message for a person to read');
            Assert::null($outcome['reply']);
        }

        Assert::false(
            DB::exists('SELECT id FROM {suppressions} WHERE channel = :c AND address = :a', [
                'c' => 'sms', 'a' => $phone,
            ]),
            'and nothing is suppressed behind the venue\'s back'
        );
        Assert::same(
            1,
            (int) DB::value('SELECT sms_consent FROM {contacts} WHERE id = :id', ['id' => $contactId])
        );
    } finally {
        DB::delete('contacts', 'id = :id', ['id' => $contactId]);
    }
});

// -------------------------------------------------------------------- drivers

Harness::test('the default driver sends NOTHING, on purpose', function (): void {
    // An install whose SMS settings have never been touched must not be able
    // to text a guest by accident.
    $stored = Settings::get('sms.driver');

    try {
        Settings::forget('sms.driver');
        Sms::useDriver(null);

        Assert::same('log', Sms::driver(), 'a fresh install is a dry run');

        Settings::set('sms.driver', 'nonsense-provider');

        Assert::same('log', Sms::driver(), 'and an unknown driver falls back to the dry run');
    } finally {
        if ($stored === null) {
            Settings::forget('sms.driver');
        } else {
            Settings::set('sms.driver', $stored);
        }
    }
});

Harness::test('a driver with no credentials reports that, rather than claiming success', function (): void {
    $result = with_sms_settings(
        sms_live([
            'sms.driver'              => 'twilio',
            'sms.twilio_account_sid'  => '',
            'sms.twilio_auth_token'   => '',
        ]),
        static function (): array {
            Sms::useDriver('twilio');

            try {
                return Sms::send(['to' => '+18455551234', 'body' => SMS_BULK]);
            } finally {
                Sms::useDriver(null);
            }
        }
    );

    Assert::false($result['sent']);
    Assert::same('twilio_not_configured', $result['reason']);

    // The message is recorded as FAILED rather than silently dropped: a venue
    // asking "did that go?" needs an answer either way.
    $row = DB::first('SELECT status FROM {messages} WHERE id = :id', ['id' => (int) $result['message_id']]);

    Assert::notNull($row);
    /** @var array<string,mixed> $row */
    Assert::same('failed', (string) $row['status']);

    DB::delete('messages', 'id = :id', ['id' => (int) $result['message_id']]);
});

Harness::test('every refusal reason has plain English behind it', function (): void {
    // These strings end up in front of a member of staff at the front desk,
    // so a bare `no_consent` is not good enough.
    foreach ([
        'invalid_number', 'empty_body', 'too_long', 'sending_paused', 'no_opt_out',
        'suppressed', 'no_contact', 'do_not_contact', 'no_consent', 'number_mismatch',
        'frequency_cap', 'unregistered_sender', 'quiet_hours', 'safe_mode_no_number',
        'driver_error',
    ] as $reason) {
        $text = Sms::explain($reason);

        Assert::notSame($reason, $text, $reason . ' is explained, not echoed');
        Assert::greaterThan(20, strlen($text), 'and in a whole sentence');
    }
});

Harness::test('the suite leaves no texts or contacts behind', function (): void {
    sms_clear();

    Assert::same(
        0,
        (int) DB::value("SELECT COUNT(*) FROM {contacts} WHERE first_name = 'SmsTest'")
    );
});
