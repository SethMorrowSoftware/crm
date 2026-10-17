<?php

declare(strict_types=1);

/**
 * Refuse to screenshot an install that holds anything real.
 *
 * `tools/screenshots.js` commits what it captures into the repository, so the
 * one mistake worth engineering against is somebody running it against a live
 * install and pushing a venue's guest list as PNGs — names, addresses, party
 * details, spend. Once that is in git history it is in every clone, for ever,
 * and no later commit removes it.
 *
 * THE RULE IS POSITIVE EVIDENCE OF FICTION, NOT ABSENCE OF EVIDENCE OF LIFE.
 * That distinction is the whole correctness of this file, and the first
 * version got it backwards: it asked whether each contact's email was on a
 * reserved domain OR EMPTY, and treated empty as safe. `contacts.email` is
 * nullable everywhere in this application — `TextClubController` and
 * `InboxController` create guests from an inbound text with a real name, a
 * real phone and no address at all, and `Importer` stores null for a blank
 * email column while accepting `external_pos_id` as identity. So a venue
 * whose list came from the Text Club or from a POS export had **100% of its
 * real guests pass**, and the tool printed "SAFE — all 4,000 contacts are on
 * reserved domains" over a folder of their names. A missing email is the
 * normal shape of a real guest here; it can never be what clears one.
 *
 * So every row must carry a mark that a real person's record could not: an
 * email on a domain the standards reserve for fiction (RFC 2606's
 * example.com/.net/.org/.edu, RFC 2606's .test/.invalid/.example, RFC 6761's
 * .localhost), a phone in the NANP's reserved 555-01xx block, or the
 * installer seed's own `source = 'demo'` marker. Anything else is treated as
 * a real person, including a row carrying nothing but a name.
 *
 * AND IT IS NOT ONLY THE GUESTS. The capture photographs the whole
 * application, so it also checks:
 *
 *   - **every user account**, because `settings-users.png` is a picture of
 *     the user list — real staff names and work addresses, on an install
 *     where the only account was created by whoever ran the installer;
 *   - **the mail settings**, because `settings-mail.png` renders the SMTP
 *     host, the username and the from-address of whatever mailbox is
 *     configured;
 *   - **the venue's own contact details**, which sit in the header and in
 *     the settings pages.
 *
 * Exits 0 and prints SAFE when the install is publishable, 1 and prints every
 * reason when it is not. `--explain` prints the counts either way.
 *
 * Usage, from the install root:
 *   php tools/screenshot-guard.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CC_ROOT', __DIR__ . '/..');

require CC_ROOT . '/app/bootstrap.php';

use App\Core\DB;
use App\Core\Settings;

$explain = in_array('--explain', $argv, true);

/**
 * Domains the standards reserve so they can never belong to a real person.
 *
 * A bare name is matched as `%@example.com` so a lookalike domain cannot slip
 * through on a substring; a dotted suffix is matched as `%.test`, which is
 * what a reserved TLD means.
 */
const RESERVED = ['example.com', 'example.net', 'example.org', 'example.edu',
                  '.test', '.invalid', '.localhost', '.example'];

/** The NANP block set aside for fiction. */
const FAKE_PHONE = ['%55501%', '%555-01%', '%555 01%'];

/** @var list<string> every reason this install must not be photographed */
$refusals = [];
/** @var list<string> things worth looking at that are not refusals */
$notes    = [];

/**
 * `email` is on a reserved domain. Written once and used for contacts and for
 * users, because the two carry the same risk through different pages.
 *
 * EVERY COLUMN IS WRAPPED IN `COALESCE(…, '')`, and that is load-bearing
 * rather than tidiness. `email LIKE '%@example.com'` on a NULL email is NULL,
 * not false; `NULL OR NULL` is NULL; and `WHERE NOT NULL` matches nothing. So
 * the first attempt at this rule — which otherwise reads exactly right — let
 * a contact with no email through as though it had proved itself fictional,
 * which is the very hole the rewrite existed to close. MEASURED: a row with
 * a real name, a real phone and a NULL email was still reported SAFE.
 * Three-valued logic turns a guard that looks strict into one that fails open.
 *
 * @return array{0:string,1:array<string,string>}
 */
function reserved_email_clause(string $column, string $prefix): array
{
    $conditions = [];
    $params     = [];
    $value      = "COALESCE(" . $column . ", '')";

    foreach (RESERVED as $i => $domain) {
        $key          = $prefix . $i;
        $conditions[] = $value . ' LIKE :' . $key;
        $params[$key] = str_starts_with($domain, '.') ? '%' . $domain : '%@' . $domain;
    }

    return ['(' . implode(' OR ', $conditions) . ')', $params];
}

// ---------------------------------------------------------------- the guests

try {
    $total = (int) DB::value('SELECT COUNT(*) FROM {contacts}');
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot read the database: ' . $e->getMessage() . "\n");
    exit(1);
}

/*
 * Counted in the database rather than walked row by row: this runs against
 * whatever install is configured, which on a venue is the real one, and the
 * whole point is to establish a fact about it without reading a single
 * guest's details into memory, into a log, or onto a terminal somebody is
 * screen-sharing.
 */
if ($total > 0) {
    [$emailClause, $params] = reserved_email_clause('email', 'd');

    $phoneConditions = [];

    foreach (FAKE_PHONE as $i => $pattern) {
        $phoneConditions[] = "COALESCE(phone, '') LIKE :p" . $i;
        $params['p' . $i]  = $pattern;
    }

    // Positive evidence, in the three shapes this application can carry it.
    // COALESCE on every column, for the reason on `reserved_email_clause()`.
    // The seed's own mark is the list of ids it recorded, not `source`:
    // `source` is a column any contact form or API call can write.
    $seedIds = Settings::get('demo.seed_contact_ids', []);
    $seedIds = is_array($seedIds) ? array_values(array_filter(array_map('intval', $seedIds))) : [];

    $fictional = $emailClause
        . ' OR (' . implode(' OR ', $phoneConditions) . ')'
        . ($seedIds === [] ? '' : ' OR id IN (' . implode(',', $seedIds) . ')');

    $real = (int) DB::value(
        'SELECT COUNT(*) FROM {contacts} WHERE NOT (' . $fictional . ')',
        $params
    );

    if ($real > 0) {
        $refusals[] = number_format($real) . ' of ' . number_format($total)
            . ' contacts carry no mark of being fictional — no reserved email'
            . ' domain, no 555-01xx phone, and not the installer seed\'s own'
            . ' marker. They are treated as real people.';
    }

    if ($explain) {
        $notes[] = sprintf('contacts: %d, without a mark of fiction: %d', $total, $real);
    }
}

// ----------------------------------------------------------------- the staff
//
// `settings-users.png` is a photograph of the user list. On a venue install
// the accounts in it are real people with real work addresses, and the
// sidebar carries the signed-in one's name on every other image too.

try {
    [$userClause, $userParams] = reserved_email_clause('email', 'u');

    $realUsers = (int) DB::value(
        'SELECT COUNT(*) FROM {users} WHERE NOT ' . $userClause,
        $userParams
    );

    $totalUsers = (int) DB::value('SELECT COUNT(*) FROM {users}');

    if ($realUsers > 0) {
        $refusals[] = number_format($realUsers) . ' of ' . number_format($totalUsers)
            . " user accounts are on a real email domain. Their names and addresses\n"
            . '  appear on the users page, and the signed-in one appears in the sidebar'
            . ' of every image.';
    }

    if ($explain) {
        $notes[] = sprintf('users: %d, on a real domain: %d', $totalUsers, $realUsers);
    }
} catch (Throwable $e) {
    // A schema without the table is not an install worth photographing, but
    // it is also not a leak. Say so rather than passing in silence.
    $notes[] = 'could not check the user accounts: ' . $e->getMessage();
}

// --------------------------------------------------------- the mail settings
//
// `settings-mail.png` renders these verbatim. A configured mailbox means a
// real host, a real username, and often the venue's own address.

try {
    $mail = [
        'SMTP host'     => (string) Settings::get('mail.smtp_host', ''),
        'SMTP username' => (string) Settings::get('mail.smtp_username', ''),
        // `mail.from_email` is the key MailSettingsController writes; an
        // earlier version read `mail.from_address`, which nothing sets.
        'from address'  => (string) Settings::get('mail.from_email', ''),
        'Safe Mode address' => (string) Settings::get('mail.safe_mode_address', ''),
        'reply-to'      => (string) Settings::get('mail.reply_to', ''),
    ];

    $configured = [];

    foreach ($mail as $label => $value) {
        if ($value === '') {
            continue;
        }

        // An address on a reserved domain is as safe here as anywhere else.
        $safe = false;

        foreach (RESERVED as $domain) {
            $needle = str_starts_with($domain, '.') ? $domain : '@' . $domain;

            if (str_ends_with(strtolower($value), $needle)) {
                $safe = true;
                break;
            }
        }

        if (!$safe) {
            $configured[] = $label;
        }
    }

    if ($configured !== []) {
        $refusals[] = 'the email settings page would show a real ' . implode(', ', $configured)
            . '. Those render in full on `settings-mail.png`.';
    }
} catch (Throwable $e) {
    $notes[] = 'could not check the mail settings: ' . $e->getMessage();
}

// ------------------------------------------------------------------- verdict

foreach ($notes as $note) {
    fwrite(STDERR, 'note: ' . $note . "\n");
}

if ($refusals !== []) {
    echo "REFUSED — not photographing this install.\n\n";

    foreach ($refusals as $reason) {
        echo '  - ' . $reason . "\n";
    }

    echo "\nScreenshots taken here get committed to the repository, and anything in\n"
        . "git history is in every clone for ever.\n\n"
        . "Run this against a demo install instead — the installer's \"Example guests\n"
        . "and bookings\" seed makes data built for exactly this.\n";

    exit(1);
}

echo 'SAFE — ' . number_format($total) . " contacts, every one marked as fictional;\n"
    . "       no account and no mail setting on a real domain.\n";

exit(0);
