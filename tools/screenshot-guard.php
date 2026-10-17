<?php

declare(strict_types=1);

/**
 * Refuse to screenshot a database that holds real guest data.
 *
 * `tools/screenshots.js` commits what it captures into the repository, so the
 * one mistake worth engineering against is somebody running it against a live
 * install and pushing a venue's guest list as PNGs — names, addresses, party
 * details, spend. Once that is in git history it is in every clone, for ever,
 * and no later commit removes it.
 *
 * So this is checked before the browser is launched, and it is checked by
 * looking at the DATA rather than at a flag somebody set: every contact's
 * email address must be on a domain the standards reserve for exactly this
 * purpose (RFC 2606's example.com/.net/.org and .test/.invalid, and RFC 6761's
 * .localhost), or be empty. The demo seed is built that way deliberately, and
 * nothing a venue imports will be.
 *
 * Phone numbers are checked the same way against the NANP's reserved 555-01xx
 * fiction block, but only as a WARNING: a venue's own demo rows might
 * legitimately carry a blank or an odd number, and the email rule is the one
 * that actually distinguishes real people.
 *
 * Exits 0 and prints SAFE when the data is publishable, 1 and prints why when
 * it is not. `--explain` prints the reasoning either way.
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

$explain = in_array('--explain', $argv, true);

/** Domains the standards reserve so they can never belong to a real person. */
const RESERVED = ['example.com', 'example.net', 'example.org', 'example.edu',
                  '.test', '.invalid', '.localhost', '.example'];

try {
    $total = (int) DB::value('SELECT COUNT(*) FROM {contacts}');
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot read the database: " . $e->getMessage() . "\n");
    exit(1);
}

if ($total === 0) {
    echo "SAFE — no contacts at all.\n";
    exit(0);
}

/*
 * Built as a single counting query rather than a row walk: this runs against
 * whatever database is configured, which on a venue install is the real one,
 * and the whole point is to establish a fact about it without reading a single
 * guest's details into memory or a log.
 */
$conditions = [];
$params     = [];

foreach (RESERVED as $i => $domain) {
    $conditions[]       = 'email LIKE :d' . $i;
    $params['d' . $i]   = str_starts_with($domain, '.') ? '%' . $domain : '%@' . $domain;
}

$safeClause = '(email IS NULL OR email = \'\' OR ' . implode(' OR ', $conditions) . ')';

$real = (int) DB::value(
    'SELECT COUNT(*) FROM {contacts} WHERE NOT ' . $safeClause,
    $params
);

if ($explain) {
    fwrite(STDERR, sprintf(
        "contacts: %d, with an address that is not on a reserved domain: %d\n",
        $total,
        $real
    ));
}

if ($real > 0) {
    echo "REFUSED — " . number_format($real) . " of " . number_format($total)
        . " contacts have an email address on a real domain.\n\n"
        . "This looks like live guest data. Screenshots taken here get committed to\n"
        . "the repository, and a guest list in git history is in every clone for ever.\n\n"
        . "Run this against a demo install instead — the installer's \"Example guests\n"
        . "and bookings\" seed makes data built for exactly this.\n";

    exit(1);
}

// A warning, not a refusal: the email rule above is what actually decides.
try {
    $oddPhones = (int) DB::value(
        "SELECT COUNT(*) FROM {contacts}
          WHERE phone IS NOT NULL AND phone <> '' AND phone NOT LIKE '%55501%' AND phone NOT LIKE '%555-01%'"
    );

    if ($oddPhones > 0) {
        fwrite(STDERR, sprintf(
            "note: %d contact(s) carry a phone number outside the reserved 555-01xx block.\n"
            . "      Addresses all check out, so this is allowed — but look at a capture\n"
            . "      before pushing if you are not sure where this data came from.\n",
            $oddPhones
        ));
    }
} catch (Throwable) {
    // A missing column is not a reason to block a safe capture.
}

echo "SAFE — all " . number_format($total) . " contacts are on reserved domains.\n";
exit(0);
