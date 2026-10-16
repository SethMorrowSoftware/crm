<?php

declare(strict_types=1);

namespace App\Services;

/**
 * CSV import — the rules, with no database, no clock and no filesystem.
 *
 * `ImportRunner` streams the file and writes; this decides what every row
 * means. The split is the usual one and it earns its keep here because the
 * interesting cases are all decisions about a single row, and there are a lot
 * of them: thirty refusal reasons are far easier to pin against a literal row
 * than against a fixture file.
 *
 * Three decisions shape the whole module.
 *
 * **CONSENT DOES NOT COME IN THROUGH A SPREADSHEET.** A file exported from a
 * previous system says nothing about whether those people agreed to be
 * emailed; the column that looks like it does is usually a default somebody
 * set once. So an imported contact is NOT consented, full stop, unless the
 * operator both maps a consent column AND affirms on the screen that they hold
 * the records — and even then only an affirmative value in that column
 * counts, with the date taken from the file where there is one. Get this wrong
 * and the venue's first campaign is five thousand messages to people who never
 * asked, which is a CAN-SPAM problem, a carrier complaint and a reputation
 * nobody gets back.
 *
 * **A ROW IS REFUSED, NOT REPAIRED.** It is tempting to coerce a bad date or
 * invent a name from an email address. But an import runs once, nobody reads
 * 50,000 rows afterwards, and a guessed value is indistinguishable from a real
 * one the moment it is stored. So anything this class cannot read with
 * confidence comes back as a refusal with a reason in words, and the operator
 * fixes the file — which they can, because the report says which line.
 *
 * **IDENTITY IS AN EMAIL OR A PHONE NUMBER, NEVER A NAME.** Two different
 * families called Smith are not one guest, and merging them is not something
 * an import can undo. A row with no email and no usable phone cannot be
 * deduplicated, so it is refused rather than stored as a contact nobody can
 * ever match again.
 */
final class Importer
{
    /** Hard cap on a row count, so a mis-chosen file cannot run for a day. */
    public const MAX_ROWS = 200000;

    /** Hard cap on the upload, in bytes. 200k rows of guests is well under. */
    public const MAX_BYTES = 33554432;

    /** Columns per row. A file wider than this is not a guest list. */
    public const MAX_COLUMNS = 120;

    /** How many rows one cron tick walks. */
    public const BATCH = 500;

    /**
     * How many rows the report keeps per outcome.
     *
     * A file of 50,000 rows that is wrong in a mapping will refuse all 50,000,
     * and storing them all is a report nobody reads and a table nobody wanted.
     * The COUNTS are exact; the listing stops. The page says which it is
     * showing.
     */
    public const MAX_REPORTED = 2000;

    /**
     * What a contact row can carry.
     *
     * `label` is what the mapping screen shows; `aliases` are the header
     * spellings seen in the wild, lower-cased with non-letters stripped, which
     * is how `E-Mail Address`, `email_address` and `EMAIL` all land on one
     * field without the operator mapping anything.
     *
     * @var array<string,array{label:string,aliases:list<string>,note?:string}>
     */
    public const CONTACT_FIELDS = [
        'first_name' => [
            'label'   => 'First name',
            'aliases' => ['firstname', 'first', 'givenname', 'fname', 'forename'],
        ],
        'last_name' => [
            'label'   => 'Last name',
            'aliases' => ['lastname', 'last', 'surname', 'familyname', 'lname'],
        ],
        'full_name' => [
            'label'   => 'Full name (split into first and last)',
            'aliases' => ['name', 'fullname', 'contactname', 'guestname', 'customername'],
            'note'    => 'Split on the last space. Mapped only when there is no first-name column.',
        ],
        'email' => [
            'label'   => 'Email',
            'aliases' => ['email', 'emailaddress', 'mail', 'e', 'primaryemail'],
        ],
        'phone' => [
            'label'   => 'Phone',
            'aliases' => ['phone', 'phonenumber', 'mobile', 'cell', 'cellphone', 'telephone', 'tel', 'mobilephone'],
        ],
        'alt_phone' => [
            'label'   => 'Other phone',
            'aliases' => ['altphone', 'otherphone', 'homephone', 'workphone', 'secondaryphone', 'phone2'],
        ],
        'address_1' => [
            'label'   => 'Address',
            'aliases' => ['address', 'address1', 'addressline1', 'street', 'streetaddress'],
        ],
        'address_2' => [
            'label'   => 'Address line 2',
            'aliases' => ['address2', 'addressline2', 'apt', 'apartment', 'unit', 'suite'],
        ],
        'city'  => ['label' => 'Town or city', 'aliases' => ['city', 'town', 'locality']],
        'state' => ['label' => 'State', 'aliases' => ['state', 'province', 'region', 'county']],
        'postal_code' => [
            'label'   => 'ZIP or postcode',
            'aliases' => ['zip', 'zipcode', 'postalcode', 'postcode', 'postal'],
        ],
        'birth_date' => [
            'label'   => 'Date of birth',
            'aliases' => ['birthdate', 'dateofbirth', 'dob', 'birthday', 'born'],
            'note'    => 'Used for birthday offers. A year in the future is refused.',
        ],
        'lifecycle_stage' => [
            'label'   => 'Stage',
            'aliases' => ['stage', 'lifecycle', 'lifecyclestage', 'status', 'type'],
            'note'    => 'lead, prospect, customer, member, lapsed or advocate. Anything else is refused.',
        ],
        'source_detail' => [
            'label'   => 'Where they came from',
            'aliases' => ['source', 'origin', 'leadsource', 'howtheyheard', 'sourcedetail'],
        ],
        'notes_summary' => [
            'label'   => 'Notes',
            'aliases' => ['notes', 'note', 'comments', 'comment', 'remarks'],
        ],
        'external_pos_id' => [
            'label'   => 'Card or account number in your old system',
            'aliases' => ['cardnumber', 'card', 'accountnumber', 'customerid', 'memberid', 'externalid', 'posid'],
            'note'    => 'Kept so a later POS import can match the same person.',
        ],
        'email_consent' => [
            'label'   => 'Email consent',
            'aliases' => ['emailconsent', 'emailoptin', 'optin', 'subscribed', 'newsletter', 'marketingconsent'],
            'note'    => 'Honoured ONLY if you confirm you hold the records. See the note on the page.',
        ],
        'email_consent_at' => [
            'label'   => 'Date they gave email consent',
            'aliases' => ['emailconsentat', 'optindate', 'consentdate', 'subscribedat', 'signupdate'],
        ],
        'sms_consent' => [
            'label'   => 'Text consent',
            'aliases' => ['smsconsent', 'textconsent', 'smsoptin', 'textoptin', 'mobileoptin'],
            'note'    => 'Honoured ONLY if you confirm you hold the records.',
        ],
        'sms_consent_at' => [
            'label'   => 'Date they gave text consent',
            'aliases' => ['smsconsentat', 'textconsentat', 'smsoptindate'],
        ],
        'do_not_contact' => [
            'label'   => 'Do not contact',
            'aliases' => ['donotcontact', 'dnc', 'unsubscribed', 'optout', 'suppressed', 'blocked'],
            'note'    => 'Always honoured. A row marked this way is imported and never messaged.',
        ],
    ];

    /**
     * What a visit row can carry.
     *
     * A visit needs somebody to belong to, so its identity columns are the
     * contact's — matched against contacts already in the database, never
     * creating one. An import of visits for guests who are not here yet would
     * silently create thousands of nameless contacts.
     *
     * @var array<string,array{label:string,aliases:list<string>,note?:string}>
     */
    public const VISIT_FIELDS = [
        'email' => [
            'label'   => 'Guest email (to match an existing contact)',
            'aliases' => ['email', 'emailaddress', 'mail', 'guestemail', 'customeremail'],
        ],
        'phone' => [
            'label'   => 'Guest phone (to match an existing contact)',
            'aliases' => ['phone', 'phonenumber', 'mobile', 'cell', 'telephone', 'guestphone'],
        ],
        'external_pos_id' => [
            'label'   => 'Card or account number (to match an existing contact)',
            'aliases' => ['cardnumber', 'card', 'accountnumber', 'customerid', 'memberid', 'externalid', 'posid'],
        ],
        'visit_date' => [
            'label'   => 'Date of the visit',
            'aliases' => ['visitdate', 'date', 'transactiondate', 'shiftdate', 'when', 'visited'],
        ],
        'spend_cents' => [
            'label'   => 'Amount spent',
            'aliases' => ['spend', 'amount', 'total', 'sales', 'revenue', 'spent', 'amountspent'],
            'note'    => 'Dollars, with or without a $. Stored as cents.',
        ],
        'party_size' => [
            'label'   => 'Party size',
            'aliases' => ['partysize', 'guests', 'guestcount', 'people', 'headcount', 'size'],
        ],
        'external_ref' => [
            'label'   => 'Reference in your old system',
            'aliases' => ['ref', 'reference', 'receipt', 'receiptnumber', 'transactionid', 'ticketnumber'],
        ],
    ];

    /** @var list<string> */
    public const LIFECYCLE_STAGES = ['lead', 'prospect', 'customer', 'member', 'lapsed', 'advocate'];

    /** What a duplicate does. */
    public const ON_DUPLICATE = [
        'skip'   => 'Leave the one already here alone',
        'fill'   => 'Fill in blanks only — never overwrite anything already set',
        'update' => 'Overwrite with the file’s values',
    ];

    /**
     * Values that mean yes.
     *
     * Deliberately an ALLOW-LIST. Every other value — including an empty cell,
     * `maybe`, `unknown`, a date, anything the exporting system invented — is
     * NO, because this is read for consent and the cost of reading a stray
     * value as yes is a message to somebody who never agreed.
     *
     * @var list<string>
     */
    public const TRUTHY = ['1', 'y', 'yes', 'true', 't', 'on', 'subscribed', 'opted in', 'opt in', 'opt-in', 'consented', 'active'];

    /** @var list<string> */
    public const FALSY = ['', '0', 'n', 'no', 'false', 'f', 'off', 'unsubscribed', 'opted out', 'opt out', 'opt-out', 'none', 'null'];

    /**
     * The fields for an entity.
     *
     * @return array<string,array{label:string,aliases:list<string>,note?:string}>
     */
    public static function fields(string $entity): array
    {
        return $entity === 'visits' ? self::VISIT_FIELDS : self::CONTACT_FIELDS;
    }

    /** Is this an entity this importer knows? */
    public static function isEntity(string $entity): bool
    {
        return in_array($entity, ['contacts', 'visits'], true);
    }

    /**
     * Reduce a header to something comparable.
     *
     * Lower-cased with every non-letter and non-digit removed, so `E-Mail
     * Address`, `email_address`, `EMAIL ADDRESS` and `Email` are one string.
     * A BOM arrives on the first header of every file Excel has ever written,
     * so it is stripped first or the first column never matches anything.
     */
    public static function normalizeHeader(string $header): string
    {
        $header = str_replace("\xEF\xBB\xBF", '', $header);

        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $header));
    }

    /**
     * Guess the mapping from the header row.
     *
     * A GUESS THE OPERATOR CONFIRMS, never one that is acted on silently: the
     * mapping screen shows every column with its guess selected, because a
     * header called `status` could be a lifecycle stage or a consent flag and
     * only the person who exported the file knows which.
     *
     * First match wins per field, so a file with both `email` and
     * `secondary_email` maps the first to `email` and leaves the second
     * unmapped rather than overwriting.
     *
     * @param list<string> $headers
     * @return array<int,string> column index => field
     */
    public static function guessMapping(array $headers, string $entity): array
    {
        $fields = self::fields($entity);
        $byAlias = [];

        foreach ($fields as $field => $spec) {
            // The field's own name counts as an alias, so a file exported from
            // this application re-imports without mapping.
            foreach (array_merge([$field], $spec['aliases']) as $alias) {
                $normalized = self::normalizeHeader($alias);

                if ($normalized !== '' && !isset($byAlias[$normalized])) {
                    $byAlias[$normalized] = $field;
                }
            }
        }

        $mapping = [];
        $taken   = [];

        foreach ($headers as $index => $header) {
            $normalized = self::normalizeHeader((string) $header);

            if ($normalized === '' || !isset($byAlias[$normalized])) {
                continue;
            }

            $field = $byAlias[$normalized];

            if (isset($taken[$field])) {
                continue;
            }

            $mapping[(int) $index] = $field;
            $taken[$field]         = true;
        }

        /*
         * `full_name` is only useful when nothing else names the person: a
         * file with First, Last AND Name would otherwise map all three and
         * the last writer would decide the name.
         */
        if (isset($taken['first_name']) || isset($taken['last_name'])) {
            foreach ($mapping as $index => $field) {
                if ($field === 'full_name') {
                    unset($mapping[$index]);
                }
            }
        }

        return $mapping;
    }

    /**
     * What is wrong with this mapping, and whether it stops the import.
     *
     * Checked before a dry run rather than discovered 50,000 rows in: a file
     * mapped with no identity column refuses every single row, and saying so
     * once on the mapping screen is the difference between a typo and a
     * wasted afternoon.
     *
     * **A BLOCKER AND A PIECE OF ADVICE ARE NOT THE SAME THING**, and keeping
     * them in one undifferentiated list was a real bug here, caught by this
     * module's own unit test: "nothing identifies a person" means every row
     * will be refused, while "no name column" means the import works and
     * everybody is nameless on screen. A caller that treats the second as the
     * first refuses a perfectly good import; one that treats the first as the
     * second lets somebody wait out 50,000 refusals. So each problem says
     * which it is.
     *
     * @param array<int,string> $mapping
     * @return list<array{level:string,message:string}> `blocker` or `advice`.
     */
    public static function mappingProblems(array $mapping, string $entity): array
    {
        $fields   = array_values($mapping);
        $problems = [];

        $blocker = static function (string $message) use (&$problems): void {
            $problems[] = ['level' => 'blocker', 'message' => $message];
        };

        $advice = static function (string $message) use (&$problems): void {
            $problems[] = ['level' => 'advice', 'message' => $message];
        };

        foreach (array_count_values($fields) as $field => $count) {
            if ($count > 1) {
                $label = self::fields($entity)[$field]['label'] ?? $field;
                $blocker('Two columns are both mapped to “' . $label . '”. Pick one.');
            }
        }

        if ($entity === 'visits') {
            if (!in_array('visit_date', $fields, true)) {
                $blocker('No column is mapped to the date of the visit, '
                    . 'and a visit without a date cannot be recorded.');
            }

            if (!array_intersect(['email', 'phone', 'external_pos_id'], $fields)) {
                $blocker('Nothing identifies the guest. Map an email, a phone number '
                    . 'or the card number you use in your old system, so each visit can be '
                    . 'matched to a contact who is already here.');
            }

            if (!in_array('spend_cents', $fields, true)) {
                $advice('No column is mapped to the amount spent. The visits will import, '
                    . 'but nobody’s lifetime value will change.');
            }

            return $problems;
        }

        if (!array_intersect(['email', 'phone'], $fields)) {
            $blocker('Nothing identifies a person. Map an email or a phone number — '
                . 'a name is not enough, because two different families called Smith are '
                . 'not one guest and an import cannot undo merging them.');
        }

        if (!array_intersect(['first_name', 'last_name', 'full_name'], $fields)) {
            $advice('No column is mapped to a name. The import will work, but '
                . 'every guest will be nameless on screen.');
        }

        return $problems;
    }

    /**
     * Just the blockers, for a caller deciding whether to start.
     *
     * @param list<array{level:string,message:string}> $problems
     * @return list<string>
     */
    public static function blockers(array $problems): array
    {
        return array_values(array_map(
            static fn (array $problem): string => $problem['message'],
            array_filter($problems, static fn (array $problem): bool => $problem['level'] === 'blocker')
        ));
    }

    /**
     * Is this really a heading row, or did somebody upload the wrong file?
     *
     * MEASURED: a six-byte binary file was accepted as an import. `fgetcsv()`
     * reads anything — it has no concept of "not a CSV" — and a check that
     * only asked whether the row had a non-blank cell passed, because binary
     * bytes are not whitespace. The result was an import job whose single
     * column was named with a control character, sitting on the page waiting
     * to be mapped to nothing.
     *
     * Three things a heading row has, each of which a binary blob, an image or
     * a PDF does not:
     *
     *   - **no NUL bytes.** A text file has none; nearly every binary format
     *     is full of them. This is the single most reliable signal.
     *   - **valid UTF-8.** Checked because every later message quotes the
     *     header back, and invalid bytes in an HTML page are a different
     *     problem again.
     *   - **a letter somewhere.** A row of `###` or `0` or `12,13,14` is a
     *     data row, not a heading — which means the operator exported without
     *     headers and every row of the file will be misread.
     *
     * Null means it looks like a heading row.
     *
     * @param list<string> $header
     */
    public static function headerProblem(array $header): ?string
    {
        $joined = implode('', $header);

        if (str_contains($joined, "\0")) {
            return 'That does not look like a text file at all — it has bytes in it that a '
                . 'CSV never contains. Export your data again, choosing CSV.';
        }

        if (!mb_check_encoding($joined, 'UTF-8')) {
            return 'The first row of that file is not readable as text. If your old system '
                . 'offers a choice, export as CSV with UTF-8 encoding.';
        }

        // A control character other than tab is the other binary tell.
        if (preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $joined) === 1) {
            return 'The first row of that file contains characters that cannot be a column '
                . 'heading, so it is probably not a CSV.';
        }

        $named = 0;

        foreach ($header as $cell) {
            if (preg_match('/\p{L}/u', (string) $cell) === 1) {
                $named++;
            }
        }

        if ($named === 0) {
            return 'The first row of that file has no column names in it — just values. '
                . 'Castle CRM needs the headings row to know what each column means, so '
                . 'export it again with headers included.';
        }

        return null;
    }

    /**
     * Read a yes/no cell.
     *
     * Null when the value is neither — which the caller treats as a refusal
     * for a consent column, because "I could not read this" and "no" are
     * different answers and only one of them is worth telling somebody about.
     */
    public static function readBool(string $value): ?bool
    {
        $value = strtolower(trim($value));

        if (in_array($value, self::TRUTHY, true)) {
            return true;
        }

        if (in_array($value, self::FALSY, true)) {
            return false;
        }

        return null;
    }

    /**
     * Read a date into `Y-m-d`, or null if it cannot be read with confidence.
     *
     * **AMBIGUOUS DATES ARE REFUSED, NOT GUESSED**, and this is the one place
     * in the module where that rule costs the operator something. `03/04/2024`
     * is the 3rd of April to half the world and the 4th of March to the other
     * half, and a birthday import that is wrong by a month sends the offer to
     * the wrong week for ever. `strtotime()` would answer confidently (US
     * order), which is exactly the behaviour not wanted.
     *
     * So: an unambiguous slash date (one part over 12) is read, in whichever
     * order makes it unambiguous. `Y-m-d`, `Y/m/d` and a written month are
     * read. A genuinely ambiguous `n/n/Y` is read only when the caller passes
     * an explicit order, which the mapping screen asks for.
     *
     * @param string $order 'mdy', 'dmy', or '' for "refuse the ambiguous ones"
     */
    public static function readDate(string $value, string $order = ''): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Already unambiguous: ISO, with either separator.
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m) === 1) {
            return self::validDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // A written month is never ambiguous: "4 Mar 2024", "March 4, 2024".
        if (preg_match('/[A-Za-z]{3}/', $value) === 1) {
            $parsed = date_create_immutable_from_format('!j M Y', $value)
                ?: date_create_immutable_from_format('!j F Y', $value)
                ?: date_create_immutable_from_format('!M j Y', str_replace(',', '', $value))
                ?: date_create_immutable_from_format('!F j Y', str_replace(',', '', $value));

            return $parsed === false ? null : $parsed->format('Y-m-d');
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})(?:[ T].*)?$/', $value, $m) !== 1) {
            return null;
        }

        [$a, $b, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year < 100) {
            // A two-digit year in a guest list is this century or the last.
            // 70 is the usual pivot and it is a guess, so it is recorded as
            // one here rather than hidden in a format string.
            $year += $year < 70 ? 2000 : 1900;
        }

        if ($a > 12 && $b <= 12) {
            return self::validDate($year, $b, $a);   // day first, unambiguous
        }

        if ($b > 12 && $a <= 12) {
            return self::validDate($year, $a, $b);   // month first, unambiguous
        }

        if ($a > 12 && $b > 12) {
            return null;                              // neither can be a month
        }

        // Both 12 or under: genuinely ambiguous.
        return match ($order) {
            'mdy'   => self::validDate($year, $a, $b),
            'dmy'   => self::validDate($year, $b, $a),
            default => null,
        };
    }

    /** `Y-m-d` only if those three numbers really are a date. */
    private static function validDate(int $year, int $month, int $day): ?string
    {
        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Read money into integer cents.
     *
     * Null when it cannot be read. `$1,234.50`, `1234.5`, `(12.00)` for a
     * refund and a bare `-12` all appear in POS exports.
     *
     * **BY INTEGER ARITHMETIC ON THE DIGITS, NOT THROUGH A FLOAT.** This read
     * `(int) round(((float) $clean) * 100)`, and review flagged it as
     * understating a half-cent — `0.145` reaching cents as 14. Measured, it
     * does not: PHP's `round()` compensates for binary representation, and
     * over every two-decimal value to $20,000 and every three-decimal value
     * to $1,000 — 3,000,001 of them — the float path agreed with exact
     * arithmetic on every single one. But the reviewer was right about the
     * SHAPE of it even with the arithmetic coming out: the correctness of a
     * venue's imported money rested on a rounding helper's fuzz
     * compensation, which is a thing that can change under us and a thing no
     * reader can verify by looking. It does not rest on anything now, and two
     * real defects fell out of writing it exactly:
     *
     *   - a float loses the last cent above about $90 trillion
     *     (`99999999999999.99` came back a cent short). No venue has that in
     *     a spend column, but a column of the wrong sort might, and a
     *     silently adjusted figure is the worst way to find out — so an
     *     amount too large to be money here is REFUSED and reported.
     *   - `2.5E+3` had its letters stripped like a currency code and became
     *     `2.53` — $2.53 where the cell said $2,500. Spreadsheets really do
     *     export a number column that way. Also refused: a reported row a
     *     venue can look at beats a wrong number they cannot see.
     */
    public static function readCents(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Accountants' parentheses mean negative.
        $negative = preg_match('/^\((.*)\)$/', $value, $m) === 1;

        if ($negative) {
            $value = trim($m[1]);
        }

        if (str_starts_with($value, '-')) {
            $negative = true;
        }

        /*
         * Scientific notation is refused rather than guessed at. Stripping
         * the non-digits out of `2.5E+3` leaves `2.53`, which is a plausible
         * amount and the wrong one by a factor of a thousand.
         */
        if (preg_match('/\d\s*[eE]\s*[-+]?\d/', $value) === 1) {
            return null;
        }

        $clean = (string) preg_replace('/[^0-9.]/', '', $value);

        // `is_numeric` FIRST, so that a second decimal point ("1.2.3") is a
        // refusal rather than something the split below quietly truncates.
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');

        $whole = ltrim($whole, '0');

        // Twelve digits is a trillion dollars. A figure that size in a spend
        // column is the wrong column, not a large party, and `(int)` on a
        // longer digit string saturates — so it is refused here while it can
        // still be reported against the row it came from.
        if (strlen($whole) > 12) {
            return null;
        }

        /*
         * Thousandths, then half-up to cents. Three fractional digits is
         * exactly enough: rounding to two places turns on whether the tail is
         * at least half a cent, and that is decided by the third digit alone
         * (0.004999… is below it however long the tail runs).
         */
        $thousandths = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
        $cents       = intdiv($thousandths + 5, 10);

        return $negative ? -$cents : $cents;
    }

    /**
     * Split a full name into first and last.
     *
     * ON THE LAST SPACE, so "Mary Anne Fitzgerald" is Mary Anne Fitzgerald and
     * not Mary "Anne Fitzgerald". A single word is a first name: somebody
     * called "Mum" in a phone export has a first name and no surname, which is
     * true, where the reverse would be strange.
     *
     * "Fitzgerald, Mary Anne" — the other common export shape — is read on the
     * comma instead.
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            return ['', ''];
        }

        if (str_contains($name, ',')) {
            [$last, $first] = array_pad(array_map('trim', explode(',', $name, 2)), 2, '');

            if ($last !== '' && $first !== '') {
                return [$first, $last];
            }
        }

        $position = strrpos($name, ' ');

        if ($position === false) {
            return [$name, ''];
        }

        return [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * Pull one CSV row into named values, trimmed.
     *
     * @param list<string> $row
     * @param array<int,string> $mapping
     * @return array<string,string>
     */
    public static function readRow(array $row, array $mapping): array
    {
        $out = [];

        foreach ($mapping as $index => $field) {
            $value = $row[$index] ?? '';

            // A non-breaking space is invisible and is not whitespace to
            // `trim()`, so a cell containing one would read as "set" for ever.
            $value = str_replace(["\xC2\xA0", "\u{200B}"], ' ', (string) $value);

            $out[$field] = trim($value);
        }

        return $out;
    }

    /**
     * Turn a contact row into columns to write, or a refusal.
     *
     * @param array<string,string> $values From `readRow()`.
     * @param array{consent_claimed?:bool,date_order?:string,source?:string} $options
     * @return array{ok:bool,reason?:string,message?:string,label:string,columns?:array<string,mixed>,identity:array{email:?string,phone:?string},warnings:list<string>}
     */
    public static function contactRow(array $values, array $options = []): array
    {
        $warnings = [];
        $label    = self::labelFor($values);

        $email = strtolower(trim($values['email'] ?? ''));
        $phone = trim($values['phone'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            /*
             * REFUSED, not dropped-and-imported. An address that does not
             * parse is usually a shifted column — the whole row is one cell to
             * the left — and importing the rest of it stores a guest whose
             * every field is wrong. The row number in the report is what lets
             * somebody see that at a glance.
             */
            return self::refusal('bad_email', 'That is not an email address: “' . self::clip($email) . '”.', $label);
        }

        $normalizedPhone = normalize_phone($phone === '' ? null : $phone);

        if ($phone !== '' && $normalizedPhone === null) {
            return self::refusal(
                'bad_phone',
                'That phone number has too few or too many digits to be real: “'
                    . self::clip($phone) . '”.',
                $label
            );
        }

        if ($email === '' && $normalizedPhone === null) {
            return self::refusal(
                'no_identity',
                'No email and no usable phone number, so this person could never be '
                    . 'matched again or messaged.',
                $label
            );
        }

        $first = trim($values['first_name'] ?? '');
        $last  = trim($values['last_name'] ?? '');

        if ($first === '' && $last === '' && ($values['full_name'] ?? '') !== '') {
            [$first, $last] = self::splitName($values['full_name']);
        }

        $columns = [
            'first_name' => $first === '' ? null : self::cap($first, 80),
            'last_name'  => $last === '' ? null : self::cap($last, 80),
            'email'      => $email === '' ? null : self::cap($email, 191),
            'phone'      => $phone === '' ? null : self::cap($phone, 32),
            'phone_normalized' => $normalizedPhone,
        ];

        foreach (['alt_phone' => 32, 'address_1' => 160, 'address_2' => 160, 'city' => 80,
                  'state' => 40, 'postal_code' => 20, 'source_detail' => 191,
                  'notes_summary' => 500, 'external_pos_id' => 64] as $field => $limit) {
            $value = trim($values[$field] ?? '');

            if ($value !== '') {
                $columns[$field] = self::cap($value, $limit);
            }
        }

        // Stage
        if (($values['lifecycle_stage'] ?? '') !== '') {
            $stage = strtolower(trim($values['lifecycle_stage']));

            if (!in_array($stage, self::LIFECYCLE_STAGES, true)) {
                return self::refusal(
                    'bad_stage',
                    'Unknown stage “' . self::clip($stage) . '”. It has to be one of: '
                        . implode(', ', self::LIFECYCLE_STAGES) . '.',
                    $label
                );
            }

            $columns['lifecycle_stage'] = $stage;
        }

        // Date of birth
        if (($values['birth_date'] ?? '') !== '') {
            $order = (string) ($options['date_order'] ?? '');
            $birth = self::readDate($values['birth_date'], $order);

            if ($birth === null) {
                return self::refusal(
                    'bad_date',
                    'Could not read “' . self::clip($values['birth_date']) . '” as a date of birth'
                        . ($order === '' ? '. If your dates are day-first or month-first, say which on the mapping screen.' : '.'),
                    $label
                );
            }

            if ($birth > gmdate('Y-m-d')) {
                return self::refusal(
                    'future_birth_date',
                    'That date of birth is in the future: ' . $birth . '.',
                    $label
                );
            }

            $columns['birth_date'] = $birth;
        }

        /*
         * CONSENT. Read last, and read strictly.
         *
         * `do_not_contact` is honoured whatever else the row says and whatever
         * the operator ticked: a suppression is the one flag where the
         * cautious reading and the operator's wishes always agree, and
         * ignoring it would re-message somebody who has already asked not to
         * be.
         */
        $columns['email_consent'] = 0;
        $columns['sms_consent']   = 0;

        if (($values['do_not_contact'] ?? '') !== '') {
            $dnc = self::readBool($values['do_not_contact']);

            if ($dnc === null) {
                $warnings[] = 'Could not read “' . self::clip($values['do_not_contact'])
                    . '” in the do-not-contact column, so it was treated as a suppression.';
                $dnc = true;
            }

            if ($dnc) {
                $columns['do_not_contact']     = 1;
                $columns['marketing_opt_out_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        $claimed = (bool) ($options['consent_claimed'] ?? false);

        foreach ([['email_consent', 'email_consent_at'], ['sms_consent', 'sms_consent_at']] as [$flagField, $dateField]) {
            $raw = trim($values[$flagField] ?? '');

            if ($raw === '') {
                continue;
            }

            if (!$claimed) {
                /*
                 * A column was mapped and the operator did not affirm they
                 * hold the records. The contact still imports — losing the
                 * guest would be worse — but NOT consented, and the report
                 * says so per row so the count is visible rather than
                 * surprising.
                 */
                $warnings[] = 'Consent in the file was not applied, because the box confirming '
                    . 'you hold records of these people agreeing was not ticked.';

                continue;
            }

            $granted = self::readBool($raw);

            if ($granted === null) {
                $warnings[] = 'Could not read “' . self::clip($raw) . '” as a yes or no, '
                    . 'so consent was not granted for this person.';

                continue;
            }

            if (!$granted) {
                continue;
            }

            if (($columns['do_not_contact'] ?? 0) === 1) {
                $warnings[] = 'The file says they consented AND asked not to be contacted. '
                    . 'The suppression wins.';

                continue;
            }

            $columns[$flagField] = 1;

            $when = self::readDate(trim($values[$dateField] ?? ''), (string) ($options['date_order'] ?? ''));

            // A consent with no date is still a consent, but the date is what
            // a complaint is answered with — so its absence is noted, and
            // today is NOT substituted for it, which would be a false record.
            if ($when !== null) {
                $columns[$dateField] = $when . ' 00:00:00';
            } else {
                $warnings[] = 'No date for ' . ($flagField === 'sms_consent' ? 'text' : 'email')
                    . ' consent, so there is no record of when they agreed.';
            }
        }

        if (($columns['email_consent'] ?? 0) === 1 && $columns['email'] === null) {
            $columns['email_consent'] = 0;
            $warnings[] = 'Email consent with no email address to send to.';
        }

        if (($columns['sms_consent'] ?? 0) === 1 && $normalizedPhone === null) {
            $columns['sms_consent'] = 0;
            $warnings[] = 'Text consent with no phone number to send to.';
        }

        if (($columns['sms_consent'] ?? 0) === 1) {
            $columns['sms_consent_source'] = 'import';
        }

        return [
            'ok'       => true,
            'label'    => $label,
            'columns'  => $columns,
            'identity' => ['email' => $columns['email'], 'phone' => $normalizedPhone],
            'warnings' => $warnings,
        ];
    }

    /**
     * Turn a visit row into something `VisitRecorder::record()` can take,
     * minus the contact id, which only the runner can resolve.
     *
     * @param array<string,string> $values
     * @param array{date_order?:string,source?:string} $options
     * @return array{ok:bool,reason?:string,message?:string,label:string,visit?:array<string,mixed>,identity:array{email:?string,phone:?string,external_pos_id:?string},warnings:list<string>}
     */
    public static function visitRow(array $values, array $options = []): array
    {
        $warnings = [];
        $label    = self::labelFor($values);

        $date = self::readDate(trim($values['visit_date'] ?? ''), (string) ($options['date_order'] ?? ''));

        if ($date === null) {
            $raw = trim($values['visit_date'] ?? '');

            return self::visitRefusal(
                'bad_date',
                $raw === ''
                    ? 'No date, and a visit without a date cannot be recorded.'
                    : 'Could not read “' . self::clip($raw) . '” as a date.',
                $label
            );
        }

        if ($date > gmdate('Y-m-d')) {
            // A visit in the future is a booking, and this is not the importer
            // for those. Reading it as a visit would make the venue's "guests
            // this month" count include people who have not been yet.
            return self::visitRefusal(
                'future_visit',
                'That visit is in the future (' . $date . '). A future date is a booking, not a visit.',
                $label
            );
        }

        $email = strtolower(trim($values['email'] ?? ''));
        $phone = normalize_phone(($values['phone'] ?? '') === '' ? null : $values['phone']);
        $card  = trim($values['external_pos_id'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
            $warnings[] = 'The email in this row is not an address, so it was not used to match.';
        }

        if ($email === '' && $phone === null && $card === '') {
            return self::visitRefusal(
                'no_identity',
                'Nothing in this row identifies the guest, so the visit has nobody to belong to.',
                $label
            );
        }

        $visit = [
            'visit_date' => $date,
            'source'     => in_array((string) ($options['source'] ?? ''), array_keys(VisitRecorder::SOURCES), true)
                ? (string) $options['source']
                : 'pos_import',
        ];

        if (($values['spend_cents'] ?? '') !== '') {
            $cents = self::readCents($values['spend_cents']);

            if ($cents === null) {
                return self::visitRefusal(
                    'bad_amount',
                    'Could not read “' . self::clip($values['spend_cents']) . '” as an amount.',
                    $label
                );
            }

            if ($cents < 0) {
                // A refund line. Recording it as a visit's spend would make
                // the guest's lifetime value negative for the day.
                return self::visitRefusal(
                    'negative_amount',
                    'That amount is negative (' . money($cents) . '), which is a refund rather than a visit.',
                    $label
                );
            }

            $visit['spend_cents'] = $cents;
        }

        if (($values['party_size'] ?? '') !== '') {
            $size = (string) preg_replace('/\D+/', '', $values['party_size']);

            if ($size !== '' && (int) $size > 0) {
                $visit['party_size'] = min((int) $size, 32767);
            }
        }

        if ($card !== '') {
            $visit['external_ref'] = self::cap(
                trim($values['external_ref'] ?? '') !== '' ? $values['external_ref'] : $card,
                64
            );
        } elseif (trim($values['external_ref'] ?? '') !== '') {
            $visit['external_ref'] = self::cap($values['external_ref'], 64);
        }

        return [
            'ok'       => true,
            'label'    => $label,
            'visit'    => $visit,
            'identity' => [
                'email' => $email === '' ? null : $email,
                'phone' => $phone,
                'external_pos_id' => $card === '' ? null : self::cap($card, 64),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Which of the file's values may be written over an existing contact.
     *
     * `fill` is the default on the page for a reason: a venue importing an old
     * list onto contacts who have since been corrected by hand does not want
     * the file to win. `update` is offered because sometimes the file IS the
     * better record, and `skip` because sometimes the point is only to add the
     * people who are missing.
     *
     * Consent is NEVER widened by an update, whichever mode is chosen: a
     * spreadsheet cannot revoke a consent somebody gave through a form here,
     * and it must not grant one either — the existing record was made with
     * evidence this one does not have.
     *
     * @param array<string,mixed> $new
     * @param array<string,mixed> $existing
     * @return array<string,mixed> Columns to write; empty means nothing to do.
     */
    public static function mergeContact(array $new, array $existing, string $mode): array
    {
        if ($mode === 'skip') {
            return [];
        }

        $changes = [];

        foreach ($new as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Consent and suppression: only ever MORE cautious.
            if (in_array($column, ['email_consent', 'sms_consent', 'email_consent_at',
                                   'sms_consent_at', 'sms_consent_source'], true)) {
                continue;
            }

            if ($column === 'do_not_contact' || $column === 'marketing_opt_out_at') {
                // A suppression in the file is applied even in `fill` mode,
                // and never removed.
                if ((int) ($existing['do_not_contact'] ?? 0) !== 1) {
                    $changes[$column] = $value;
                }

                continue;
            }

            $current = $existing[$column] ?? null;
            $isBlank = $current === null || $current === '' || $current === 0;

            if ($mode === 'fill' && !$isBlank) {
                continue;
            }

            if ((string) $current === (string) $value) {
                continue;
            }

            $changes[$column] = $value;
        }

        return $changes;
    }

    /**
     * One line for the report, so a refusal can be recognised.
     *
     * @param array<string,string> $values
     */
    public static function labelFor(array $values): string
    {
        $parts = array_filter([
            trim(($values['first_name'] ?? '') . ' ' . ($values['last_name'] ?? '')),
            trim($values['full_name'] ?? ''),
            trim($values['email'] ?? ''),
            trim($values['phone'] ?? ''),
            trim($values['external_pos_id'] ?? ''),
        ], static fn (string $part): bool => $part !== '');

        return self::cap(implode(' · ', array_slice(array_unique($parts), 0, 3)), 191);
    }

    /**
     * The summary a page or a CSV reports.
     *
     * @param array{created?:int,updated?:int,skipped?:int,refused?:int,total_rows?:?int} $counts
     * @return array<string,mixed>
     */
    public static function summary(array $counts, string $status, bool $dryRun): array
    {
        $created = (int) ($counts['created'] ?? 0);
        $updated = (int) ($counts['updated'] ?? 0);
        $skipped = (int) ($counts['skipped'] ?? 0);
        $refused = (int) ($counts['refused'] ?? 0);
        $seen    = $created + $updated + $skipped + $refused;

        $total = $counts['total_rows'] ?? null;

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'refused' => $refused,
            'seen'    => $seen,
            'total'   => $total,
            // Null, not 0, while the total is unknown: a progress bar that
            // reads 0% because nobody counted the rows yet is worse than one
            // that says it does not know.
            'percent' => $total === null || (int) $total <= 0
                ? null
                : (int) min(100, (int) floor(($seen / (int) $total) * 100)),
            'dry_run'  => $dryRun,
            'status'   => $status,
            'finished' => in_array($status, ['dry_run_done', 'done', 'failed', 'cancelled'], true),
            'headline' => self::headline($created, $updated, $skipped, $refused, $status, $dryRun),
        ];
    }

    /** The sentence at the top of the page. */
    private static function headline(
        int $created,
        int $updated,
        int $skipped,
        int $refused,
        string $status,
        bool $dryRun
    ): string {
        if ($status === 'cancelled') {
            return 'Cancelled. Nothing more will be read from this file.';
        }

        if ($status === 'failed') {
            return 'This import stopped on an error before it finished.';
        }

        $would = $dryRun ? 'would be ' : '';
        $parts = [];

        if ($created > 0) {
            $parts[] = number_format($created) . ' ' . $would . 'added';
        }

        if ($updated > 0) {
            $parts[] = number_format($updated) . ' ' . $would . 'updated';
        }

        if ($skipped > 0) {
            $parts[] = number_format($skipped) . ' already here';
        }

        if ($refused > 0) {
            $parts[] = number_format($refused) . ' refused';
        }

        if ($parts === []) {
            return $dryRun
                ? 'Nothing in this file can be imported. The refusals below say why.'
                : 'Nothing was imported.';
        }

        return ucfirst(implode(', ', $parts)) . '.';
    }

    /**
     * @return array{ok:false,reason:string,message:string,label:string,identity:array{email:null,phone:null},warnings:list<string>}
     */
    private static function refusal(string $reason, string $message, string $label): array
    {
        return [
            'ok'       => false,
            'reason'   => $reason,
            'message'  => self::cap($message, 300),
            'label'    => $label,
            'identity' => ['email' => null, 'phone' => null],
            'warnings' => [],
        ];
    }

    /**
     * @return array{ok:false,reason:string,message:string,label:string,identity:array{email:null,phone:null,external_pos_id:null},warnings:list<string>}
     */
    private static function visitRefusal(string $reason, string $message, string $label): array
    {
        return [
            'ok'       => false,
            'reason'   => $reason,
            'message'  => self::cap($message, 300),
            'label'    => $label,
            'identity' => ['email' => null, 'phone' => null, 'external_pos_id' => null],
            'warnings' => [],
        ];
    }

    /** Cut to a column's length, on a character boundary. */
    public static function cap(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    /** A value quoted back in a message, short enough not to run off the page. */
    private static function clip(string $value): string
    {
        $value = (string) preg_replace('/\s+/', ' ', trim($value));

        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : $value;
    }
}
