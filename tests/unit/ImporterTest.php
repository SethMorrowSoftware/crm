<?php

declare(strict_types=1);

/**
 * CSV import — the rules.
 *
 * An import runs ONCE, against a file nobody will read again, and produces
 * records indistinguishable from ones a person typed. So the interesting tests
 * here are not "does it parse a CSV" but:
 *
 *   - **does an ambiguous date get guessed?** It must not: `03/04/2024` is two
 *     different birthdays and a wrong one sends the offer to the wrong week
 *     for ever.
 *   - **can consent get in through a spreadsheet?** It must not, unless the
 *     operator affirms they hold the records — and even then only an
 *     affirmative value counts, and never over a suppression.
 *   - **does a bad row get repaired?** It must not: a shifted column makes a
 *     guest whose every field is wrong, and a refusal with a line number is
 *     the only thing that lets somebody see it.
 *
 * No database, no clock, no files.
 */

use App\Services\Importer;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/importer');

// --------------------------------------------------------------- the mapping

Harness::test('a header is reduced so every spelling of it matches', function (): void {
    Assert::same('emailaddress', Importer::normalizeHeader('E-Mail Address'));
    Assert::same('emailaddress', Importer::normalizeHeader('email_address'));
    Assert::same('emailaddress', Importer::normalizeHeader('EMAIL ADDRESS'));
    Assert::same('phone2', Importer::normalizeHeader('Phone #2'));

    /*
     * THE BOM. Excel writes one on the first header of every file it exports,
     * so without stripping it the first column never matches anything — and
     * the first column is usually the name or the email, i.e. the one that
     * matters most.
     */
    Assert::same('firstname', Importer::normalizeHeader("\xEF\xBB\xBFFirst Name"));
});

Harness::test('a contact file maps itself from its headers', function (): void {
    $mapping = Importer::guessMapping(
        ['First Name', 'Last Name', 'E-mail', 'Mobile', 'ZIP', 'Nonsense Column'],
        'contacts'
    );

    Assert::same('first_name', $mapping[0] ?? null);
    Assert::same('last_name', $mapping[1] ?? null);
    Assert::same('email', $mapping[2] ?? null);
    Assert::same('phone', $mapping[3] ?? null);
    Assert::same('postal_code', $mapping[4] ?? null);
    Assert::false(isset($mapping[5]), 'a column it does not recognise is left unmapped');
});

Harness::test('a field is never mapped twice, and the first column wins', function (): void {
    $mapping = Importer::guessMapping(['Email', 'Secondary Email', 'Mail'], 'contacts');

    Assert::same('email', $mapping[0] ?? null);
    Assert::false(isset($mapping[1]), 'the second is left for the operator to decide');
    Assert::false(isset($mapping[2]));
});

Harness::test('a full-name column stands down when there are real name columns', function (): void {
    // Both present: the specific columns win, because otherwise which one
    // decided the name would depend on the order they happened to be read.
    $both = Importer::guessMapping(['Name', 'First Name', 'Last Name', 'Email'], 'contacts');

    Assert::false(in_array('full_name', $both, true), 'full_name drops out');
    Assert::true(in_array('first_name', $both, true));

    // Alone: it is the only thing naming the person, so it is used.
    $alone = Importer::guessMapping(['Customer Name', 'Email'], 'contacts');

    Assert::true(in_array('full_name', $alone, true));
});

Harness::test('a mapping with no way to identify a person is refused up front', function (): void {
    /*
     * Checked on the mapping screen rather than discovered 50,000 rows in. A
     * file mapped with no identity column refuses every row, and the
     * difference between saying so once and saying it fifty thousand times is
     * the difference between a typo and a wasted afternoon.
     */
    $problems = Importer::mappingProblems([0 => 'first_name', 1 => 'last_name'], 'contacts');
    $blockers = Importer::blockers($problems);

    Assert::same(1, count($blockers), 'it blocks');
    Assert::true(
        str_contains($blockers[0], 'two different families called Smith'),
        'and says why a name is not identity'
    );
});

Harness::test('ADVICE DOES NOT BLOCK AN IMPORT, AND THIS WAS A REAL BUG', function (): void {
    /*
     * MEASURED by this very test on its first run.
     *
     * `mappingProblems()` returned one undifferentiated list, so a mapping of
     * nothing but an email column — which imports perfectly well — came back
     * with a "problem": that nobody is named. A caller gating the dry run on
     * `$problems === []` would have refused it.
     *
     * The two readings are genuinely different. "Nothing identifies a person"
     * means every row will be refused. "No name column" means the import
     * works and everybody is nameless on screen, which some venues genuinely
     * want (a list of card numbers and visit history). Each problem now says
     * which it is.
     */
    $problems = Importer::mappingProblems([0 => 'email'], 'contacts');

    Assert::same([], Importer::blockers($problems), 'nothing stops it');
    Assert::same(1, count($problems), 'but the advice is still offered');
    Assert::same('advice', $problems[0]['level']);
    Assert::true(str_contains($problems[0]['message'], 'nameless'));
});

Harness::test('two columns mapped to one field is a problem, not a race', function (): void {
    $blockers = Importer::blockers(Importer::mappingProblems([0 => 'email', 1 => 'email'], 'contacts'));

    Assert::same(1, count($blockers), 'and it blocks, rather than letting the last one win');
    Assert::true(str_contains($blockers[0], 'Pick one'));
});

Harness::test('a visit file needs a date and a way to find the guest', function (): void {
    $blockers = static fn (array $mapping): array
        => Importer::blockers(Importer::mappingProblems($mapping, 'visits'));

    Assert::same(1, count($blockers([0 => 'email'])), 'no date');
    Assert::same(1, count($blockers([0 => 'visit_date'])), 'no guest');
    Assert::same([], $blockers([0 => 'visit_date', 1 => 'email']));

    // A card number is identity enough for a visit: a POS export often has
    // nothing else.
    Assert::same([], $blockers([0 => 'visit_date', 1 => 'external_pos_id']));

    // No spend column is advice, not a blocker: the visits are still worth
    // having, they just move nobody's lifetime value.
    $noSpend = Importer::mappingProblems([0 => 'visit_date', 1 => 'email'], 'visits');

    Assert::same(1, count($noSpend));
    Assert::same('advice', $noSpend[0]['level']);
    Assert::true(str_contains($noSpend[0]['message'], 'lifetime value'));
});

Harness::test('A FILE THAT IS NOT A CSV IS REFUSED, NOT MAPPED', function (): void {
    /*
     * MEASURED on a live walk: a six-byte binary file was accepted as an
     * import and sat on the mapping screen with one column whose name was a
     * control character. `fgetcsv()` has no concept of "not a CSV" — it will
     * read a PDF and hand back a row — so the heading row has to be examined.
     */
    Assert::notNull(Importer::headerProblem(["\x00\x01\x02\xFF"]), 'a NUL is the clearest tell');
    Assert::notNull(Importer::headerProblem(["\x01\x02"]), 'so is a control character');
    Assert::notNull(Importer::headerProblem(["\xFF\xFE not utf8"]), 'and so is invalid UTF-8');

    /*
     * A row of values rather than headings. The operator exported without
     * headers, and without this every row of the file is misread — including
     * the first one, which quietly becomes a guest.
     */
    $noHeadings = Importer::headerProblem(['12', '13', '14']);

    Assert::notNull($noHeadings);
    Assert::true(str_contains((string) $noHeadings, 'no column names'));
    Assert::true(str_contains((string) $noHeadings, 'headers included'), 'and says what to do');

    // A real heading row passes, including one with blank or numeric columns
    // beside the named ones — which is what a spreadsheet's trailing columns
    // look like.
    Assert::null(Importer::headerProblem(['First Name', 'Email']));
    Assert::null(Importer::headerProblem(['', 'Email', '3']));
    Assert::null(Importer::headerProblem(["\xEF\xBB\xBFFirst Name", 'Email']), 'a BOM is fine');
    Assert::null(Importer::headerProblem(['Prénom', 'Téléphone']), 'and so are accents');
    Assert::null(Importer::headerProblem(["Name\tExtra"]), 'a tab is whitespace, not a control code');
});

// ------------------------------------------------------------------- dates

Harness::test('AN AMBIGUOUS DATE IS REFUSED, NOT GUESSED', function (): void {
    /*
     * THE MOST IMPORTANT TEST IN THIS FILE.
     *
     * `03/04/2024` is the 3rd of April to most of the world and the 4th of
     * March in the United States. `strtotime()` answers confidently (US
     * order) and is wrong half the time — and a date of birth that is wrong by
     * a month sends the birthday offer to the wrong week every year, silently,
     * for as long as the venue uses the record.
     */
    Assert::null(Importer::readDate('03/04/2024'), 'both parts could be the month');
    Assert::null(Importer::readDate('1/2/2020'));
    Assert::null(Importer::readDate('12/12/2020'), 'even when both readings agree on nothing');

    // Told which order, it reads it.
    Assert::same('2024-03-04', Importer::readDate('03/04/2024', 'mdy'));
    Assert::same('2024-04-03', Importer::readDate('03/04/2024', 'dmy'));
});

Harness::test('an unambiguous date is read whichever order it is in', function (): void {
    // ISO, either separator.
    Assert::same('2024-03-04', Importer::readDate('2024-03-04'));
    Assert::same('2024-03-04', Importer::readDate('2024/03/04'));
    Assert::same('2024-03-04', Importer::readDate('2024-03-04 14:30:00'), 'a time is ignored');
    Assert::same('2024-03-04', Importer::readDate('2024-03-04T14:30:00Z'));

    // One part over 12 settles it, in either position, with no order given.
    Assert::same('2024-03-25', Importer::readDate('25/03/2024'), 'day first');
    Assert::same('2024-03-25', Importer::readDate('03/25/2024'), 'month first');

    // A written month is never ambiguous.
    Assert::same('2024-03-04', Importer::readDate('4 Mar 2024'));
    Assert::same('2024-03-04', Importer::readDate('4 March 2024'));
    Assert::same('2024-03-04', Importer::readDate('Mar 4, 2024'));
    Assert::same('2024-03-04', Importer::readDate('March 4 2024'));
});

Harness::test('a date that is not a date comes back null', function (): void {
    Assert::null(Importer::readDate(''));
    Assert::null(Importer::readDate('n/a'));
    Assert::null(Importer::readDate('unknown'));
    Assert::null(Importer::readDate('2024-02-30'), 'February has no 30th');
    Assert::null(Importer::readDate('2024-13-01'), 'and there is no 13th month');
    Assert::null(Importer::readDate('13/25/2024'), 'neither part can be a month');
    Assert::null(Importer::readDate('1824-01-01'), 'too long ago to be a guest');
    Assert::null(Importer::readDate('2150-01-01'));
});

Harness::test('a two-digit year pivots at 70, and says so', function (): void {
    // A guest list's two-digit years are birthdays, so the pivot has to put
    // 1965 in the past rather than 2065 in the future.
    Assert::same('1985-06-15', Importer::readDate('15/06/85'));
    Assert::same('2015-06-15', Importer::readDate('15/06/15'));
    Assert::same('1970-06-15', Importer::readDate('15/06/70'), '70 is the oldest in this century');
    Assert::same('2069-06-15', Importer::readDate('15/06/69'));
});

// ------------------------------------------------------------------- money

Harness::test('money is read from whatever the old system wrote', function (): void {
    Assert::same(123450, Importer::readCents('$1,234.50'));
    Assert::same(123450, Importer::readCents('1234.50'));
    Assert::same(123450, Importer::readCents('1,234.5'));
    Assert::same(1200, Importer::readCents('12'));
    Assert::same(1200, Importer::readCents(' 12.00 '));
    Assert::same(1250, Importer::readCents('USD 12.50'));

    // Rounding, not truncation: 0.145 is 15 cents, not 14.
    Assert::same(15, Importer::readCents('0.145'));

    // A refund, written both ways accountants write one.
    Assert::same(-1200, Importer::readCents('(12.00)'));
    Assert::same(-1200, Importer::readCents('-12.00'));
    Assert::same(-1200, Importer::readCents('-$12.00'));

    Assert::null(Importer::readCents(''));
    Assert::null(Importer::readCents('free'));
    Assert::null(Importer::readCents('n/a'));
});

// -------------------------------------------------------------------- names

Harness::test('a full name splits on the LAST space', function (): void {
    Assert::same(['Mary', 'Smith'], Importer::splitName('Mary Smith'));

    // Not the first: "Mary Anne Fitzgerald" is Mary Anne Fitzgerald, and
    // splitting on the first space makes her surname "Anne Fitzgerald".
    Assert::same(['Mary Anne', 'Fitzgerald'], Importer::splitName('Mary Anne Fitzgerald'));
    // A particle-laden surname splits the same way, which is wrong for "van
    // der Berg" and right for every "Mary Anne". The rule is stated rather
    // than special-cased, because no rule gets both and the operator can fix
    // a surname in the file.
    Assert::same(['Jan van der', 'Berg'], Importer::splitName('Jan van der Berg'));

    // One word is a FIRST name. Somebody called "Mum" in a phone export has a
    // first name and no surname, which is true; the reverse would be strange.
    Assert::same(['Mum', ''], Importer::splitName('Mum'));

    // "Surname, Forename" is the other common export shape.
    Assert::same(['Mary Anne', 'Fitzgerald'], Importer::splitName('Fitzgerald, Mary Anne'));

    Assert::same(['', ''], Importer::splitName('   '));
    Assert::same(['Mary', 'Smith'], Importer::splitName("Mary\t Smith"), 'odd whitespace collapses');
});

// ------------------------------------------------------------- yes and no

Harness::test('only a known yes is a yes', function (): void {
    foreach (['1', 'Y', 'yes', 'TRUE', 't', 'on', 'Subscribed', 'opted in', 'consented'] as $yes) {
        Assert::true(Importer::readBool($yes), $yes . ' is yes');
    }

    foreach (['0', 'n', 'No', 'FALSE', 'off', '', 'unsubscribed', 'opt out', 'none'] as $no) {
        Assert::false(Importer::readBool($no), $no . ' is no');
    }

    /*
     * EVERYTHING ELSE IS NULL — "I could not read this" — and the caller
     * treats that differently from "no". This is read for consent, so an
     * allow-list is the only safe direction: a value the exporting system
     * invented must never become a yes.
     */
    Assert::null(Importer::readBool('maybe'));
    Assert::null(Importer::readBool('unknown'));
    Assert::null(Importer::readBool('2024-01-01'));
    Assert::null(Importer::readBool('pending'));
});

// ------------------------------------------------------------ contact rows

/** @param array<string,string> $overrides */
function imp_contact(array $overrides = [], array $options = []): array
{
    // `$overrides + $defaults`, NOT the other way round: PHP's `+` keeps the
    // LEFT operand's value for a duplicate key, so defaults on the left
    // silently ignore every override. (Learned the hard way elsewhere in
    // this suite.)
    return Importer::contactRow($overrides + [
        'first_name' => 'Nadia',
        'last_name'  => 'Okonjo',
        'email'      => 'nadia@example.test',
    ], $options);
}

Harness::test('a good contact row becomes columns', function (): void {
    $result = imp_contact(['phone' => '(845) 555-0199', 'postal_code' => '10918']);

    Assert::true($result['ok']);
    Assert::same('Nadia', $result['columns']['first_name']);
    Assert::same('Okonjo', $result['columns']['last_name']);
    Assert::same('nadia@example.test', $result['columns']['email'], 'lower-cased');
    Assert::same('+18455550199', $result['columns']['phone_normalized'], 'E.164 for matching');
    Assert::same('(845) 555-0199', $result['columns']['phone'], 'and as typed, for reading');
    Assert::same('10918', $result['columns']['postal_code']);
    Assert::same([], $result['warnings']);
});

Harness::test('an email that is not an email refuses the WHOLE row', function (): void {
    /*
     * Not "import it without the email". An address that does not parse is
     * usually a shifted column — every field in the row is one cell to the
     * left — and importing the rest stores a guest whose name is their phone
     * number and whose town is their postcode. The line number in the report
     * is what lets somebody see that at a glance.
     */
    $result = imp_contact(['email' => 'nadia at example dot test']);

    Assert::false($result['ok']);
    Assert::same('bad_email', $result['reason']);
    Assert::true(str_contains($result['message'], 'not an email address'));
    Assert::true(str_contains($result['message'], 'nadia at example'), 'and quotes the value back');
});

Harness::test('a phone number with the wrong number of digits refuses the row', function (): void {
    $result = imp_contact(['email' => '', 'phone' => '555-01']);

    Assert::false($result['ok']);
    Assert::same('bad_phone', $result['reason']);
});

Harness::test('a row with no email and no usable phone is refused', function (): void {
    $result = imp_contact(['email' => '', 'phone' => '']);

    Assert::false($result['ok']);
    Assert::same('no_identity', $result['reason']);
    Assert::true(
        str_contains($result['message'], 'never be matched again'),
        'and says what the consequence would have been'
    );

    // A phone alone is identity enough.
    Assert::true(imp_contact(['email' => '', 'phone' => '8455550199'])['ok']);
});

Harness::test('a full name is split only when there is no first or last', function (): void {
    $split = Importer::contactRow([
        'full_name' => 'Mary Anne Fitzgerald',
        'email'     => 'mary@example.test',
    ]);

    Assert::same('Mary Anne', $split['columns']['first_name']);
    Assert::same('Fitzgerald', $split['columns']['last_name']);

    // With a real first name present, the full name is ignored rather than
    // overwriting it.
    $both = imp_contact(['full_name' => 'Someone Else Entirely']);

    Assert::same('Nadia', $both['columns']['first_name']);
});

Harness::test('an unknown stage is refused with the list of real ones', function (): void {
    $result = imp_contact(['lifecycle_stage' => 'VIP Platinum']);

    Assert::false($result['ok']);
    Assert::same('bad_stage', $result['reason']);
    Assert::true(str_contains($result['message'], 'prospect'), 'and names what is allowed');

    Assert::same('member', imp_contact(['lifecycle_stage' => 'Member'])['columns']['lifecycle_stage']);
});

Harness::test('an unreadable date of birth is refused, and points at the fix', function (): void {
    $ambiguous = imp_contact(['birth_date' => '03/04/1990']);

    Assert::false($ambiguous['ok']);
    Assert::same('bad_date', $ambiguous['reason']);
    Assert::true(
        str_contains($ambiguous['message'], 'day-first or month-first'),
        'it tells the operator the setting that would read it'
    );

    // With the order given, the same row is fine — and the message stops
    // offering advice that no longer applies.
    $told = imp_contact(['birth_date' => '03/04/1990'], ['date_order' => 'dmy']);

    Assert::true($told['ok']);
    Assert::same('1990-04-03', $told['columns']['birth_date']);

    $nonsense = imp_contact(['birth_date' => 'sometime in the 80s'], ['date_order' => 'dmy']);

    Assert::false($nonsense['ok']);
    Assert::false(
        str_contains((string) $nonsense['message'], 'day-first'),
        'the order hint is not offered when the order was already given'
    );
});

Harness::test('a date of birth in the future is refused', function (): void {
    $result = imp_contact(['birth_date' => gmdate('Y-m-d', time() + 86400 * 400)]);

    Assert::false($result['ok']);
    Assert::same('future_birth_date', $result['reason']);
});

// ----------------------------------------------------------------- consent

Harness::test('CONSENT IN THE FILE IS IGNORED UNLESS THE OPERATOR AFFIRMS IT', function (): void {
    /*
     * The other most important test in this file.
     *
     * A file exported from a previous system says nothing about whether those
     * people agreed to be emailed; the column that looks like it does is
     * usually a default somebody set once, years ago, for everybody. Reading
     * it as consent makes the venue's first campaign five thousand messages
     * to people who never asked — a CAN-SPAM problem, a carrier complaint,
     * and a sending reputation nobody gets back.
     *
     * So the contact still imports (losing the guest would be worse), and
     * arrives NOT consented, with the reason recorded per row so the count is
     * visible rather than surprising.
     */
    $unclaimed = imp_contact(['email_consent' => 'yes', 'sms_consent' => 'yes']);

    Assert::true($unclaimed['ok'], 'the guest is still imported');
    Assert::same(0, $unclaimed['columns']['email_consent'], 'and not consented');
    Assert::same(0, $unclaimed['columns']['sms_consent']);
    Assert::true(
        str_contains(implode(' ', $unclaimed['warnings']), 'was not applied'),
        'and the row says why'
    );

    // Affirmed, it is honoured.
    $claimed = imp_contact(
        ['phone' => '8455550199', 'email_consent' => 'yes', 'sms_consent' => 'yes'],
        ['consent_claimed' => true]
    );

    Assert::same(1, $claimed['columns']['email_consent']);
    Assert::same(1, $claimed['columns']['sms_consent']);
    Assert::same('import', $claimed['columns']['sms_consent_source']);
});

Harness::test('a consent value that cannot be read is not a yes', function (): void {
    $result = imp_contact(['email_consent' => 'pending'], ['consent_claimed' => true]);

    Assert::same(0, $result['columns']['email_consent']);
    Assert::true(str_contains(implode(' ', $result['warnings']), 'as a yes or no'));
});

Harness::test('a consent date is recorded, and never invented', function (): void {
    $dated = imp_contact(
        ['email_consent' => 'yes', 'email_consent_at' => '2023-06-15'],
        ['consent_claimed' => true]
    );

    Assert::same('2023-06-15 00:00:00', $dated['columns']['email_consent_at']);

    /*
     * No date: consented, but with NOTHING in the date column. Substituting
     * today would be a false record of when somebody agreed — and that date
     * is exactly what a complaint is answered with.
     */
    $undated = imp_contact(['email_consent' => 'yes'], ['consent_claimed' => true]);

    Assert::same(1, $undated['columns']['email_consent']);
    Assert::false(isset($undated['columns']['email_consent_at']), 'no date is invented');
    Assert::true(str_contains(implode(' ', $undated['warnings']), 'no record of when'));
});

Harness::test('a suppression is honoured whatever else the row says', function (): void {
    // Always applied, with or without the affirmation: a do-not-contact is
    // the one flag where caution and the operator's wishes always agree.
    $dnc = imp_contact(['do_not_contact' => 'yes']);

    Assert::same(1, $dnc['columns']['do_not_contact']);
    Assert::true(isset($dnc['columns']['marketing_opt_out_at']));

    // And it beats a consent in the same row.
    $both = imp_contact(
        ['do_not_contact' => '1', 'email_consent' => 'yes'],
        ['consent_claimed' => true]
    );

    Assert::same(1, $both['columns']['do_not_contact']);
    Assert::same(0, $both['columns']['email_consent'], 'the suppression wins');
    Assert::true(str_contains(implode(' ', $both['warnings']), 'suppression wins'));
});

Harness::test('an unreadable suppression value is treated AS a suppression', function (): void {
    /*
     * The one place an unreadable value is read as the stronger reading rather
     * than the weaker one, and it is the right way round: the cost of
     * suppressing somebody who did not ask is one guest who gets no offers;
     * the cost of the other mistake is messaging somebody who did ask.
     */
    $result = imp_contact(['do_not_contact' => 'see notes']);

    Assert::same(1, $result['columns']['do_not_contact']);
    Assert::true(str_contains(implode(' ', $result['warnings']), 'treated as a suppression'));
});

Harness::test('consent with nothing to send to is dropped', function (): void {
    $noPhone = imp_contact(['sms_consent' => 'yes'], ['consent_claimed' => true]);

    Assert::same(0, $noPhone['columns']['sms_consent']);
    Assert::true(str_contains(implode(' ', $noPhone['warnings']), 'no phone number'));

    $noEmail = imp_contact(
        ['email' => '', 'phone' => '8455550199', 'email_consent' => 'yes'],
        ['consent_claimed' => true]
    );

    Assert::same(0, $noEmail['columns']['email_consent']);
    Assert::true(str_contains(implode(' ', $noEmail['warnings']), 'no email address'));
});

// -------------------------------------------------------------- duplicates

Harness::test('skip mode writes nothing at all', function (): void {
    Assert::same([], Importer::mergeContact(['first_name' => 'New'], ['first_name' => 'Old'], 'skip'));
});

Harness::test('fill mode never overwrites something already set', function (): void {
    $changes = Importer::mergeContact(
        ['first_name' => 'Nadia', 'city' => 'Chester', 'postal_code' => '10918'],
        ['first_name' => 'Nadia Okonjo-Smith', 'city' => null, 'postal_code' => ''],
        'fill'
    );

    Assert::false(isset($changes['first_name']), 'a name corrected by hand is not undone');
    Assert::same('Chester', $changes['city'], 'but a blank is filled');
    Assert::same('10918', $changes['postal_code'], 'and so is an empty string');
});

Harness::test('update mode overwrites, but still skips blanks in the file', function (): void {
    $changes = Importer::mergeContact(
        ['first_name' => 'Nadia', 'city' => null, 'state' => ''],
        ['first_name' => 'Nadia Okonjo-Smith', 'city' => 'Chester', 'state' => 'NY'],
        'update'
    );

    Assert::same('Nadia', $changes['first_name']);
    Assert::false(isset($changes['city']), 'an empty cell does not erase a real value');
    Assert::false(isset($changes['state']));
});

Harness::test('A MERGE NEVER WIDENS CONSENT, IN ANY MODE', function (): void {
    /*
     * The existing consent was made with evidence — a form submission, a
     * double opt-in text — that a spreadsheet does not have. So an import can
     * neither grant one nor take one away, even when the operator chose
     * "overwrite with the file's values": that choice is about addresses and
     * names, and nobody ticking it means "replace my consent records with a
     * CSV".
     */
    foreach (['fill', 'update'] as $mode) {
        $changes = Importer::mergeContact(
            [
                'email_consent'    => 1,
                'email_consent_at' => '2020-01-01 00:00:00',
                'sms_consent'      => 1,
                'sms_consent_source' => 'import',
            ],
            ['email_consent' => 0, 'sms_consent' => 1],
            $mode
        );

        Assert::same([], $changes, $mode . ' mode leaves consent entirely alone');
    }
});

Harness::test('a suppression in the file is applied even in fill mode', function (): void {
    $changes = Importer::mergeContact(
        ['do_not_contact' => 1, 'marketing_opt_out_at' => '2024-01-01 00:00:00'],
        ['do_not_contact' => 0],
        'fill'
    );

    Assert::same(1, $changes['do_not_contact'], 'asking not to be contacted always lands');

    // And it is never removed: a file with no suppression column cannot
    // un-suppress somebody.
    $already = Importer::mergeContact(
        ['do_not_contact' => 1],
        ['do_not_contact' => 1],
        'update'
    );

    Assert::same([], $already, 'nothing to change');
});

Harness::test('a merge that changes nothing is empty, not a no-op write', function (): void {
    Assert::same(
        [],
        Importer::mergeContact(
            ['first_name' => 'Nadia', 'email' => 'nadia@example.test'],
            ['first_name' => 'Nadia', 'email' => 'nadia@example.test'],
            'update'
        ),
        'so the report says "already here" rather than "updated"'
    );
});

// ------------------------------------------------------------- visit rows

Harness::test('a visit row becomes something the recorder can take', function (): void {
    $result = Importer::visitRow([
        'email'       => 'Nadia@Example.Test',
        'visit_date'  => '2024-07-04',
        'spend_cents' => '$42.50',
        'party_size'  => '6',
    ]);

    Assert::true($result['ok']);
    Assert::same('2024-07-04', $result['visit']['visit_date']);
    Assert::same(4250, $result['visit']['spend_cents']);
    Assert::same(6, $result['visit']['party_size']);
    Assert::same('pos_import', $result['visit']['source'], 'the highest-priority source by default');
    Assert::same('nadia@example.test', $result['identity']['email'], 'lower-cased for matching');
});

Harness::test('a visit with no date is refused, in both shapes', function (): void {
    $missing = Importer::visitRow(['email' => 'a@example.test']);

    Assert::false($missing['ok']);
    Assert::same('bad_date', $missing['reason']);
    Assert::true(str_contains($missing['message'], 'without a date cannot be recorded'));

    $unreadable = Importer::visitRow(['email' => 'a@example.test', 'visit_date' => '03/04/2024']);

    Assert::false($unreadable['ok']);
    Assert::same('bad_date', $unreadable['reason']);
});

Harness::test('a visit in the future is refused as a booking', function (): void {
    /*
     * Reading it as a visit would put people who have not been yet into the
     * venue's "guests this month" count, and into a guest's visit history as
     * though they had arrived.
     */
    $result = Importer::visitRow([
        'email'      => 'a@example.test',
        'visit_date' => gmdate('Y-m-d', time() + 86400 * 7),
    ]);

    Assert::false($result['ok']);
    Assert::same('future_visit', $result['reason']);
    Assert::true(str_contains($result['message'], 'booking, not a visit'));
});

Harness::test('a refund line is refused rather than recorded as a visit', function (): void {
    $result = Importer::visitRow([
        'email'       => 'a@example.test',
        'visit_date'  => '2024-07-04',
        'spend_cents' => '(25.00)',
    ]);

    Assert::false($result['ok']);
    Assert::same('negative_amount', $result['reason']);
    Assert::true(str_contains($result['message'], 'refund'));
});

Harness::test('a visit row with nobody in it is refused', function (): void {
    $result = Importer::visitRow(['visit_date' => '2024-07-04']);

    Assert::false($result['ok']);
    Assert::same('no_identity', $result['reason']);
    Assert::true(str_contains($result['message'], 'nobody to belong to'));
});

Harness::test('a bad email on a visit row is dropped, not refused', function (): void {
    /*
     * Different from a contact row, deliberately: the visit has two other
     * ways to find its guest, so losing the unusable one costs nothing. A
     * contact row has no fallback, which is why the same value refuses it.
     */
    $result = Importer::visitRow([
        'email'      => 'not an address',
        'phone'      => '8455550199',
        'visit_date' => '2024-07-04',
    ]);

    Assert::true($result['ok']);
    Assert::null($result['identity']['email']);
    Assert::same('+18455550199', $result['identity']['phone']);
    Assert::true(str_contains(implode(' ', $result['warnings']), 'not used to match'));
});

// ----------------------------------------------------------- the row reader

Harness::test('a cell is trimmed, including the spaces that are not spaces', function (): void {
    $values = Importer::readRow(
        ['  Nadia  ', "nadia@example.test\n", "\xC2\xA0", 'ignored'],
        [0 => 'first_name', 1 => 'email', 2 => 'city']
    );

    Assert::same('Nadia', $values['first_name']);
    Assert::same('nadia@example.test', $values['email']);

    /*
     * A non-breaking space is invisible AND is not whitespace to `trim()`, so
     * without this a cell containing one reads as "set" for ever — which in
     * `fill` mode means that field can never be filled in again.
     */
    Assert::same('', $values['city'], 'a non-breaking space is empty');

    Assert::false(isset($values['last_name']), 'an unmapped column is not invented');
});

Harness::test('a short row does not reach past its own end', function (): void {
    // A CSV with a ragged last row is common; reading column 5 of a 2-column
    // row must be empty rather than a warning.
    $values = Importer::readRow(['Nadia'], [0 => 'first_name', 4 => 'email']);

    Assert::same('Nadia', $values['first_name']);
    Assert::same('', $values['email']);
});

// ------------------------------------------------------------- the summary

Harness::test('the summary counts what happened and says it in words', function (): void {
    $summary = Importer::summary(
        ['created' => 120, 'updated' => 5, 'skipped' => 30, 'refused' => 2, 'total_rows' => 157],
        'done',
        false
    );

    Assert::same(157, $summary['seen']);
    Assert::same(100, $summary['percent']);
    Assert::true($summary['finished']);
    Assert::same('120 added, 5 updated, 30 already here, 2 refused.', $summary['headline']);
});

Harness::test('a dry run says WOULD be, not was', function (): void {
    $summary = Importer::summary(['created' => 120, 'total_rows' => 120], 'dry_run_done', true);

    Assert::true(str_contains($summary['headline'], 'would be added'));
    Assert::true($summary['dry_run']);
});

Harness::test('progress is null, not zero, while the total is unknown', function (): void {
    /*
     * A bar reading 0% because nobody has counted the rows yet looks like a
     * job that is stuck. "Working" is the honest reading.
     */
    $summary = Importer::summary(['created' => 50], 'importing', false);

    Assert::null($summary['percent']);
    Assert::false($summary['finished']);
});

Harness::test('a file that imports nothing says so rather than reading as success', function (): void {
    $summary = Importer::summary(['refused' => 0, 'total_rows' => 0], 'dry_run_done', true);

    Assert::true(str_contains($summary['headline'], 'Nothing in this file can be imported'));
});

Harness::test('a cancelled or failed job is not reported as a count', function (): void {
    Assert::true(str_contains(
        Importer::summary(['created' => 10], 'cancelled', false)['headline'],
        'Cancelled'
    ));
    Assert::true(str_contains(
        Importer::summary(['created' => 10], 'failed', false)['headline'],
        'stopped on an error'
    ));
});

Harness::test('a row is labelled so a refusal can be recognised', function (): void {
    Assert::same(
        'Nadia Okonjo · nadia@example.test',
        Importer::labelFor(['first_name' => 'Nadia', 'last_name' => 'Okonjo', 'email' => 'nadia@example.test'])
    );

    // With nothing else, whatever identifies them.
    Assert::same('8455550199', Importer::labelFor(['phone' => '8455550199']));
    Assert::same('', Importer::labelFor([]));
});

Harness::test('the caps are set where a mistake cannot run for a day', function (): void {
    Assert::true(Importer::MAX_ROWS >= 50000, 'a venue migrating two decades fits');
    Assert::true(Importer::MAX_BYTES >= 8 * 1024 * 1024);
    Assert::true(Importer::BATCH >= 100 && Importer::BATCH <= 2000);
    Assert::true(
        Importer::MAX_REPORTED < Importer::MAX_ROWS,
        'the report stops before the file does, because a wrong mapping refuses every row'
    );
});
