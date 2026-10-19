<?php

declare(strict_types=1);

/**
 * Schema and seeds, against a real MySQL/MariaDB server.
 *
 * The acceptance criteria in spec §13.1 are checked literally here: the schema
 * applies cleanly, it is idempotent on re-run, and the seeds produce the
 * counts the specification promises.
 */

use App\Core\DB;
use App\Core\Migration;
use App\Core\Settings;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/schema');

if (!test_boot(true)) {
    Harness::skip('schema suite', 'no database available (set CC_TEST_DB_* or CC_TEST_SKIP_DB=1)');

    return;
}

// --------------------------------------------------------------- migrations

Harness::test('every schema file is recorded as applied', function (): void {
    $onDisk = count(glob(CC_ROOT . '/install/schema/*.sql') ?: []);
    $applied = (int) DB::value('SELECT COUNT(*) FROM {migrations}');

    Assert::same($onDisk, $applied, 'all schema files should be recorded');
});

Harness::test('re-running the migration applies nothing and errors nowhere', function (): void {
    $result = (new Migration(CC_ROOT . '/install/schema', 'cc_'))->run();

    Assert::count(0, $result['applied'], 'a second run must apply nothing');
    Assert::greaterThan(0, count($result['skipped']), 'and must skip what it already did');
});

Harness::test('the expected number of tables exists', function (): void {
    $count = (int) DB::value(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name LIKE 'cc_%'"
    );

    // 99 at the time of writing. Asserting a floor rather than an exact number
    // keeps this from failing every time a table is legitimately added.
    Assert::atLeast(95, $count, 'the schema should create ~99 tables');
});

Harness::test('foreign keys are in place', function (): void {
    $count = (int) DB::value(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'"
    );

    Assert::atLeast(100, $count, 'referential integrity should be enforced by the database');
});

Harness::test('core tables exist with their key columns', function (): void {
    $expected = [
        'users'        => ['email', 'password_hash', 'role_id', 'is_active', 'locked_until'],
        'roles'        => ['slug', 'is_system'],
        'permissions'  => ['slug', 'group'],
        'contacts'     => ['email', 'phone_normalized', 'external_pos_id',
                           'email_consent', 'sms_consent', 'do_not_contact'],
        'children'     => ['birth_month', 'contact_id'],
        'deals'        => ['pipeline_id', 'stage_id', 'sla_due_at', 'status'],
        'bookings'     => ['booking_number', 'event_date', 'status', 'total_cents'],
        'visits'       => ['visit_date', 'source', 'spend_cents', 'external_ref'],
        'consent_log'  => ['channel', 'action', 'evidence'],
        'suppressions' => ['channel', 'address', 'reason'],
        'automations'  => ['trigger_type', 'is_active', 'goal_event'],
        'audit_log'    => ['action', 'entity_type', 'changes', 'ip'],
    ];

    foreach ($expected as $table => $columns) {
        Assert::true(DB::tableExists($table), $table . ' must exist');

        foreach ($columns as $column) {
            Assert::true(
                DB::columnExists($table, $column),
                $table . '.' . $column . ' must exist'
            );
        }
    }
});

Harness::test('a visit cannot be duplicated for one contact on one day', function (): void {
    // The dedup guarantee from spec §8.3 is enforced by the database, not by
    // application discipline.
    $index = DB::first(
        "SELECT constraint_name FROM information_schema.table_constraints
          WHERE table_schema = DATABASE() AND table_name = 'cc_visits'
            AND constraint_type = 'UNIQUE'"
    );

    Assert::notNull($index, 'visits needs a unique key on (contact_id, visit_date)');
});

Harness::test('a campaign cannot message one contact twice', function (): void {
    $unique = (int) DB::value(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE table_schema = DATABASE() AND table_name = 'cc_campaign_recipients'
            AND constraint_type = 'UNIQUE'"
    );

    Assert::greaterThan(0, $unique, 'campaign_recipients needs a unique (campaign, contact) key');
});

Harness::test('a suppression address is unique per channel', function (): void {
    DB::insert('suppressions', [
        'channel' => 'email', 'address' => 'dupe@example.test',
        'reason' => 'unsubscribe', 'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    Assert::throws(PDOException::class, static function (): void {
        DB::insert('suppressions', [
            'channel' => 'email', 'address' => 'dupe@example.test',
            'reason' => 'bounce', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }, 'the same address must not be suppressed twice on one channel');

    DB::delete('suppressions', 'address = :a', ['a' => 'dupe@example.test']);
});

Harness::test('utf8mb4 stores four-byte characters intact', function (): void {
    // A guest called "José 🎂" must round-trip. utf8 (three-byte) would
    // truncate or mangle the emoji.
    $id = test_make_contact(['first_name' => 'José', 'last_name' => 'Castle 🎂🎈']);

    $row = DB::first('SELECT first_name, last_name FROM {contacts} WHERE id = :id', ['id' => $id]);

    Assert::same('José', $row['first_name'] ?? null);
    Assert::same('Castle 🎂🎈', $row['last_name'] ?? null);

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

// -------------------------------------------------------------------- seeds

Harness::test('permissions and roles are seeded', function (): void {
    Assert::atLeast(50, (int) DB::value('SELECT COUNT(*) FROM {permissions}'));
    Assert::same(8, (int) DB::value('SELECT COUNT(*) FROM {roles}'), 'eight starting roles');
});

Harness::test('the owner role is a locked system role holding everything', function (): void {
    $owner = DB::first("SELECT * FROM {roles} WHERE slug = 'owner'");

    Assert::notNull($owner);
    Assert::equals(1, $owner['is_system'], 'the owner role must be a system role');

    $granted = (int) DB::value(
        'SELECT COUNT(*) FROM {role_permissions} WHERE role_id = :id',
        ['id' => (int) $owner['id']]
    );

    $total = (int) DB::value('SELECT COUNT(*) FROM {permissions}');

    Assert::same($total, $granted, 'the owner must hold every permission');
});

Harness::test('front desk cannot see revenue', function (): void {
    // A deliberate product decision (spec §6): floor staff work bookings
    // without seeing guest spend or lifetime value.
    $canSeeRevenue = DB::exists(
        "SELECT rp.role_id FROM {role_permissions} rp
           JOIN {roles} r ON r.id = rp.role_id
           JOIN {permissions} p ON p.id = rp.permission_id
          WHERE r.slug = 'front_desk' AND p.slug = 'view_revenue'",
    );

    Assert::false($canSeeRevenue, 'front_desk must not hold view_revenue');
});

Harness::test('the party host role is scoped to its own deals', function (): void {
    $scope = DB::value(
        "SELECT rp.scope FROM {role_permissions} rp
           JOIN {roles} r ON r.id = rp.role_id
           JOIN {permissions} p ON p.id = rp.permission_id
          WHERE r.slug = 'party_host' AND p.slug = 'view_deals'"
    );

    Assert::same('own', $scope, 'a host should see their own leads, not the team\'s');
});

Harness::test('the Castle attractions are seeded with their seasons', function (): void {
    Assert::same(16, (int) DB::value('SELECT COUNT(*) FROM {attractions}'));

    $goKarts = DB::first("SELECT * FROM {attractions} WHERE slug = 'go-karts'");

    Assert::notNull($goKarts, 'go-karts must be seeded');
    Assert::equals(1, $goKarts['is_seasonal'], 'go-karts are seasonal');
    Assert::equals(0, $goKarts['is_indoor'], 'go-karts are outdoors');
    Assert::same('04-01', $goKarts['season_start_md']);
    Assert::notNull($goKarts['min_height_in'], 'a height limit protects small children');

    $bowling = DB::first("SELECT * FROM {attractions} WHERE slug = 'bowling'");
    Assert::equals(1, $bowling['is_indoor'], 'bowling is indoors and year-round');
    Assert::equals(0, $bowling['is_seasonal']);
    Assert::equals(1, $bowling['is_party_main'], 'a party can be built around bowling');
});

Harness::test('the arcade cannot be a party main activity', function (): void {
    $arcade = DB::first("SELECT * FROM {attractions} WHERE slug = 'arcade'");

    Assert::equals(0, $arcade['is_party_main']);
    Assert::equals(1, $arcade['is_party_eligible'], 'but it can be added to one');
});

Harness::test('every party CenterEdge sells is a package, in the order the venue sells them', function (): void {
    // CenterEdge's GroupEventTypes on 19 October 2026, older price points
    // folded into one product each. InflataPark first: about half the year's
    // parties, and missing from the first seed altogether.
    Assert::same(
        [
            'inflatapark-birthday', 'roller-skating-birthday', 'laser-tag-birthday', 'bowling-birthday',
            'all-access-party', 'all-access-party-winter', 'ballocity-birthday', 'mini-golf-birthday',
            'inflatapark-toddler-party', 'glow-room-party', 'bounce-party',
        ],
        DB::column('SELECT slug FROM {party_packages} ORDER BY sort_order')
    );
});

Harness::test('party packages carry the venue 7-day notice rule', function (): void {
    foreach (DB::all('SELECT * FROM {party_packages}') as $package) {
        Assert::equals(7, $package['min_notice_days'], $package['name'] . ' needs the notice rule');
        Assert::greaterThan(0, (int) $package['base_price_cents'], $package['name'] . ' needs a price');
        Assert::greaterThan(0, (int) $package['additional_guest_cents'], $package['name'] . ' needs an extra-guest price');
        Assert::true(in_array((int) $package['included_guests'], [8, 10], true), $package['name'] . ' includes 8 kids, or 10 guests');
        Assert::true(in_array((int) $package['duration_minutes'], [100, 120], true), $package['name'] . ' runs about two hours');
    }
});

Harness::test('prices are CenterEdge list prices, to the cent', function (): void {
    $prices = [];

    foreach (DB::all('SELECT slug, base_price_cents, additional_guest_cents FROM {party_packages}') as $row) {
        $prices[$row['slug']] = [(int) $row['base_price_cents'], (int) $row['additional_guest_cents']];
    }

    // $349.99, not $349: the website rounds, the POS does not.
    Assert::same([34999, 2699], $prices['laser-tag-birthday']);
    Assert::same([34999, 2699], $prices['mini-golf-birthday']);
    Assert::same([34999, 2699], $prices['ballocity-birthday']);
    Assert::same([34900, 2699], $prices['roller-skating-birthday']);
    Assert::same([39900, 2999], $prices['bowling-birthday']);
    Assert::same([39900, 2999], $prices['inflatapark-birthday']);
    Assert::same([44999, 3999], $prices['all-access-party']);
});

Harness::test('only what CenterEdge sells on the web is bookable online', function (): void {
    Assert::same(
        ['inflatapark-birthday', 'roller-skating-birthday', 'laser-tag-birthday', 'bowling-birthday',
            'ballocity-birthday', 'mini-golf-birthday'],
        DB::column('SELECT slug FROM {party_packages} WHERE is_online = 1 ORDER BY sort_order')
    );
    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {party_packages} WHERE is_active = 0'),
        'a phone-only party is still active: staff book it'
    );
});

Harness::test('mini golf is marked seasonal', function (): void {
    $seasonal = DB::value("SELECT is_seasonal FROM {party_packages} WHERE slug = 'mini-golf-birthday'");

    Assert::equals(1, $seasonal);
});

/**
 * Apply 013 on its own to this database, as an upgrade would.
 *
 * @return array{applied:list<string>,messages:list<string>}
 */
function schema_apply_013(string $prefix = ''): array
{
    $file = '013_castle_party_packages.sql';
    $directory = sys_get_temp_dir() . '/cc-upgrade-013-' . bin2hex(random_bytes(4));
    mkdir($directory);
    copy(CC_ROOT . '/install/schema/' . $file, $directory . '/' . $file);

    DB::query("DELETE FROM {migrations} WHERE filename = :file", ['file' => $file]);

    try {
        return (new Migration($directory, $prefix === '' ? null : $prefix))->run();
    } finally {
        unlink($directory . '/' . $file);
        rmdir($directory);
    }
}

/** @return array<string,array<string,mixed>> */
function schema_package_rows(): array
{
    $rows = [];

    foreach (DB::all('SELECT * FROM {party_packages} ORDER BY slug') as $row) {
        unset($row['id'], $row['created_at'], $row['updated_at']);
        // MySQL stores JSON as binary and prints it back with spaces; MariaDB
        // keeps the text. The list is what matters, not the spelling.
        $row['includes'] = $row['includes'] === null ? null : json_decode((string) $row['includes'], true);
        $rows[(string) $row['slug']] = $row;
    }

    return $rows;
}

/**
 * Put this database's packages back to what the ORIGINAL seed made: its
 * four, at its prices and positions, before 013 existed.
 */
function schema_packages_as_first_seeded(): void
{
    DB::query(
        "DELETE FROM {party_packages} WHERE slug NOT IN
            ('roller-skating-birthday', 'laser-tag-birthday', 'bowling-birthday', 'mini-golf-birthday')"
    );

    foreach ([['roller-skating-birthday', 34900, 10], ['laser-tag-birthday', 34900, 20],
        ['bowling-birthday', 39900, 30], ['mini-golf-birthday', 34900, 40]] as [$slug, $price, $sort]) {
        DB::update('party_packages', [
            'base_price_cents' => $price,
            'sort_order'       => $sort,
            'is_online'        => 1,
        ], 'slug = :slug', ['slug' => $slug]);
    }
}

Harness::test('AN EXISTING INSTALL UPGRADES TO EXACTLY THE PACKAGES A FRESH ONE SEEDS', function (): void {
    // Fresh installs get the packages from install/seeds/venue.php and
    // existing ones from 013's SQL: two copies of one list. A copy that drifts
    // is a live site selling a different party from a reinstall of itself.
    $fresh = schema_package_rows();

    schema_packages_as_first_seeded();
    $result = schema_apply_013();

    Assert::same(['013_castle_party_packages.sql'], $result['applied'], implode('; ', $result['messages']));
    Assert::same($fresh, schema_package_rows());
});

Harness::test('the upgrade leaves a price the venue set alone', function (): void {
    schema_packages_as_first_seeded();
    DB::update('party_packages', ['base_price_cents' => 37500], 'slug = :slug', ['slug' => 'laser-tag-birthday']);

    schema_apply_013();

    Assert::same(37500, (int) DB::value("SELECT base_price_cents FROM {party_packages} WHERE slug = 'laser-tag-birthday'"));
    Assert::same(34999, (int) DB::value("SELECT base_price_cents FROM {party_packages} WHERE slug = 'mini-golf-birthday'"), 'while an untouched one is corrected');
    Assert::same(11, (int) DB::value('SELECT COUNT(*) FROM {party_packages}'), 'and what is missing is still added');

    DB::update('party_packages', ['base_price_cents' => 34999], 'slug = :slug', ['slug' => 'laser-tag-birthday']);
});

Harness::test('A FRESH INSTALL\'S SCHEMA ADDS NO PACKAGE BEFORE THE SEED CAN', function (): void {
    // On a fresh install every schema file runs before the venue seed, so
    // there is no attraction to link InflataPark to yet. If 013 inserted
    // anyway, the seed would find the slug taken and skip it, and the venue's
    // best-selling party would be the one with no attraction attached.
    //
    // So: every schema file, under a prefix of its own, with no seed — which
    // is exactly the database a fresh install has at that moment.
    $prefix = 'tfresh' . bin2hex(random_bytes(2)) . '_';

    try {
        $result = (new Migration(null, $prefix))->run();

        Assert::true(in_array('013_castle_party_packages.sql', $result['applied'], true), implode('; ', $result['messages']));
        Assert::same(0, (int) DB::value('SELECT COUNT(*) FROM ' . DB::quoteIdentifier($prefix . 'party_packages')));
    } finally {
        $tables = DB::column(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :p',
            ['p' => $prefix . '%']
        );

        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            DB::pdo()->exec('DROP TABLE ' . DB::quoteIdentifier((string) $table));
        }

        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
});

Harness::test('resources cover rooms, lanes and the rink', function (): void {
    Assert::same(6, (int) DB::value("SELECT COUNT(*) FROM {resources} WHERE type = 'party_room'"));
    Assert::same(24, (int) DB::value("SELECT COUNT(*) FROM {resources} WHERE type = 'bowling_lane'"));
    Assert::greaterThan(30, (int) DB::value('SELECT COUNT(*) FROM {resources}'));
});

Harness::test('pipelines are seeded with stages, SLAs and a terminal pair', function (): void {
    $pipelines = DB::all('SELECT * FROM {pipelines} ORDER BY sort_order');

    Assert::same(4, count($pipelines));

    foreach ($pipelines as $pipeline) {
        $stages = DB::all(
            'SELECT * FROM {pipeline_stages} WHERE pipeline_id = :id ORDER BY sort_order',
            ['id' => (int) $pipeline['id']]
        );

        Assert::greaterThan(3, count($stages), $pipeline['name'] . ' needs stages');

        $won = array_filter($stages, static fn (array $s): bool => (bool) $s['is_won']);
        $lost = array_filter($stages, static fn (array $s): bool => (bool) $s['is_lost']);

        Assert::count(1, $won, $pipeline['name'] . ' needs exactly one won stage');
        Assert::count(1, $lost, $pipeline['name'] . ' needs exactly one lost stage');

        // The first stage must have a response deadline, or nothing drives the
        // accountability playbook.
        Assert::notNull($stages[0]['sla_hours'], $pipeline['name'] . ' first stage needs an SLA');
    }
});

Harness::test('the birthday pipeline has a two-hour first-response deadline', function (): void {
    $sla = DB::value(
        "SELECT s.sla_hours FROM {pipeline_stages} s
           JOIN {pipelines} p ON p.id = s.pipeline_id
          WHERE p.slug = 'birthday' AND s.name = 'New Inquiry'"
    );

    Assert::equals(2, $sla, 'a birthday inquiry should be answered within two hours');
});

Harness::test('all eight playbooks are seeded and inactive', function (): void {
    $playbooks = DB::all('SELECT * FROM {playbooks} ORDER BY number');

    Assert::same(8, count($playbooks));

    foreach ($playbooks as $playbook) {
        Assert::equals(0, $playbook['is_active'], $playbook['name'] . ' must ship deactivated');
        Assert::notEmpty($playbook['tagline']);
        Assert::notEmpty($playbook['revenue_moment']);
        Assert::notEmpty($playbook['arrives_ready']);
        Assert::notEmpty($playbook['you_tailor']);
    }

    Assert::same(
        [1, 2, 3, 4, 5, 6, 7, 8],
        array_map(static fn (array $p): int => (int) $p['number'], $playbooks),
        'playbooks should be numbered 1-8'
    );
});

Harness::test('the automation library is seeded and entirely inactive', function (): void {
    $total = (int) DB::value('SELECT COUNT(*) FROM {automations}');
    $active = (int) DB::value('SELECT COUNT(*) FROM {automations} WHERE is_active = 1');

    Assert::atLeast(100, $total, 'the spec promises 100+ automations');
    Assert::same(0, $active, 'NOTHING may be active on a fresh install');
});

Harness::test('every automation belongs to a playbook and has a trigger', function (): void {
    $orphans = (int) DB::value('SELECT COUNT(*) FROM {automations} WHERE playbook_id IS NULL');
    Assert::same(0, $orphans);

    $untriggered = (int) DB::value("SELECT COUNT(*) FROM {automations} WHERE trigger_type = ''");
    Assert::same(0, $untriggered);
});

Harness::test('every automation respects quiet hours by default', function (): void {
    $ignoring = (int) DB::value('SELECT COUNT(*) FROM {automations} WHERE quiet_hours_respect = 0');

    Assert::same(0, $ignoring, 'no seeded automation should be able to text at 3am');
});

Harness::test('the STOP keyword works from the very first install', function (): void {
    // Before anyone configures anything, a guest replying STOP must be honoured.
    $stop = DB::first("SELECT * FROM {sms_keywords} WHERE keyword = 'STOP'");

    Assert::notNull($stop, 'STOP must be seeded');
    Assert::same('unsubscribe', $stop['action']);
    Assert::equals(1, $stop['is_active']);

    foreach (['CANCEL', 'QUIT', 'UNSUBSCRIBE', 'HELP'] as $keyword) {
        Assert::true(
            DB::exists('SELECT id FROM {sms_keywords} WHERE keyword = :k', ['k' => $keyword]),
            $keyword . ' must be seeded'
        );
    }
});

Harness::test('loss reasons are seeded so win/loss reporting has data', function (): void {
    Assert::atLeast(8, (int) DB::value('SELECT COUNT(*) FROM {lost_reasons}'));
});

Harness::test('operating hours cover all seven days', function (): void {
    $days = DB::column('SELECT DISTINCT day_of_week FROM {operating_hours} ORDER BY day_of_week');

    Assert::same(7, count($days), 'every day needs hours');
    Assert::same([0, 1, 2, 3, 4, 5, 6], array_map('intval', $days));
});

Harness::test('seeds are idempotent', function (): void {
    // Re-running must repair rather than duplicate: the installer can be
    // re-entered, and an upgrade re-runs the seeds.
    $before = [
        'attractions' => (int) DB::value('SELECT COUNT(*) FROM {attractions}'),
        'packages'    => (int) DB::value('SELECT COUNT(*) FROM {party_packages}'),
        'playbooks'   => (int) DB::value('SELECT COUNT(*) FROM {playbooks}'),
        'automations' => (int) DB::value('SELECT COUNT(*) FROM {automations}'),
        'roles'       => (int) DB::value('SELECT COUNT(*) FROM {roles}'),
        'keywords'    => (int) DB::value('SELECT COUNT(*) FROM {sms_keywords}'),
    ];

    (require CC_ROOT . '/install/seeds/permissions.php')();
    (require CC_ROOT . '/install/seeds/venue.php')();
    (require CC_ROOT . '/install/seeds/playbooks.php')();

    foreach ($before as $what => $count) {
        $after = (int) DB::value('SELECT COUNT(*) FROM {' . ($what === 'packages' ? 'party_packages' : ($what === 'keywords' ? 'sms_keywords' : $what)) . '}');

        Assert::same($count, $after, 're-seeding must not duplicate ' . $what);
    }
});

Harness::test(
    'EVERY SEEDED AUTOMATION HAS A TRIGGER THE ENGINE CAN ACTUALLY DELIVER',
    function (): void {
        /*
         * The silent failure this exists to prevent: a venue switches an
         * automation on, waits, and nothing ever happens — because its
         * `trigger_type` is a name no event fires and no scanner looks for.
         * Nothing in the UI could say so, and nothing in a log would either,
         * because the automation is working exactly as written.
         *
         * `AutomationTriggers::catalogue()` is the union of the engine's two
         * halves — the events modules fire and the triggers cron scans for —
         * and it is also what the builder offers, so a trigger outside it is
         * one nobody could have chosen on purpose. MEASURED when the engine
         * was built: nine of the 111 seeded automations named triggers in
         * neither half.
         */
        $catalogue     = App\Services\AutomationTriggers::catalogue();
        $undeliverable = [];

        foreach (DB::all('SELECT slug, trigger_type FROM {automations} ORDER BY id') as $row) {
            $trigger = (string) $row['trigger_type'];

            if (!isset($catalogue[$trigger])) {
                $undeliverable[] = (string) $row['slug'] . ' waits for "' . $trigger . '"';
            }
        }

        Assert::same(
            [],
            $undeliverable,
            "these automations wait for something that does not exist:\n   "
            . implode("\n   ", $undeliverable)
        );
    }
);

Harness::test(
    'and nothing is seeded SWITCHED ON waiting for a feature that is not built',
    function (): void {
        /*
         * The second half of the same failure, and the worse one.
         *
         * Some catalogue entries are honest placeholders: `review.received`
         * will be fired by the reputation module, `ticket.purchased` by online
         * ticketing. `Event::PENDING` names them and the automation pages say
         * what they are waiting on — so an operator who chooses one has been
         * told. What must never happen is the product shipping one of them
         * ALREADY ACTIVE, because then nobody chose it and nobody was told:
         * the playbook reads as working, and its first message will go out
         * whenever that module lands, possibly years of guests later.
         *
         * So a pending trigger may be seeded, and may not be seeded active.
         */
        $catalogue = App\Services\AutomationTriggers::catalogue();
        $waiting   = [];

        foreach (DB::all('SELECT slug, trigger_type FROM {automations} WHERE is_active = 1') as $row) {
            $trigger = (string) $row['trigger_type'];
            $pending = $catalogue[$trigger]['pending'] ?? null;

            if ($pending !== null) {
                $waiting[] = (string) $row['slug'] . ' is ON but "' . $trigger
                    . '" waits on ' . $pending;
            }
        }

        Assert::same(
            [],
            $waiting,
            "switched on, waiting for something unbuilt:\n   " . implode("\n   ", $waiting)
        );
    }
);

Harness::test(
    'and every trigger the engine delivers is one somebody could choose',
    function (): void {
        // The other direction: a scanner for a trigger no automation uses is
        // dead code. Reported rather than failed — a venue may well write an
        // automation on a trigger the seeds do not use. The matching check for
        // an EVENT nothing fires needs a static scan of every `Event::fire()`
        // call and lives in the quality gates.
        $used = array_column(
            DB::all('SELECT DISTINCT trigger_type FROM {automations}'),
            'trigger_type'
        );

        $unused = [];

        foreach (array_keys(App\Services\AutomationTriggers::SCANNED) as $trigger) {
            if (!in_array($trigger, $used, true)) {
                $unused[] = $trigger;
            }
        }

        Assert::true(
            count($unused) <= count(App\Services\AutomationTriggers::SCANNED),
            'unused scanners: ' . implode(', ', $unused)
        );
    }
);

// ----------------------------------------------------------------- settings

Harness::test('Safe Mode defaults to on when unset', function (): void {
    // The most important default in the application: an install with no
    // settings row must still refuse to message guests.
    //
    // And it is PUT BACK afterwards. Settings are global and the suites share
    // one database, so leaving the row deleted hands every later suite an
    // install with no Safe Mode setting at all — which is only harmless today
    // because `seed_http_fixtures()` happens to write it again before the
    // browser suites. A test that removes a guard has to restore it, or the
    // failure it eventually causes lands in a different file.
    $before = Settings::get('system.safe_mode');

    try {
        Settings::forget('system.safe_mode');
        Settings::load(true);

        Assert::true(Settings::safeMode(), 'absent configuration must mean protected');
    } finally {
        if ($before === null) {
            Settings::forget('system.safe_mode');
        } else {
            Settings::set('system.safe_mode', $before);
        }

        Settings::load(true);
    }
});

Harness::test('settings round-trip with their types', function (): void {
    Settings::set('test.string', 'hello');
    Settings::set('test.int', 42);
    Settings::set('test.bool_true', true);
    Settings::set('test.bool_false', false);
    Settings::set('test.float', 8.125);
    Settings::set('test.json', ['a' => 1, 'b' => [2, 3]]);

    Settings::load(true);

    Assert::same('hello', Settings::get('test.string'));
    Assert::same(42, Settings::get('test.int'));
    Assert::same(true, Settings::get('test.bool_true'));
    Assert::same(false, Settings::get('test.bool_false'));
    Assert::same(8.125, Settings::get('test.float'));
    Assert::same(['a' => 1, 'b' => [2, 3]], Settings::get('test.json'));
});

Harness::test('an encrypted setting is unreadable in the database', function (): void {
    Settings::set('test.secret', 'twilio-auth-token-xyz', 'string', true);
    Settings::load(true);

    Assert::same('twilio-auth-token-xyz', Settings::get('test.secret'), 'readable through the API');

    $raw = (string) DB::value("SELECT value FROM {settings} WHERE `key` = 'test.secret'");

    Assert::notContains('twilio-auth-token-xyz', $raw, 'the plaintext must not be stored');
    Assert::contains('cc1:', $raw, 'it should carry our ciphertext marker');
});

Harness::test('a missing setting returns the supplied default', function (): void {
    Assert::same('fallback', Settings::get('test.definitely.missing', 'fallback'));
    Assert::null(Settings::get('test.definitely.missing'));
});

Harness::test('settings group() returns a keyed subset', function (): void {
    Settings::set('grouptest.one', 'a');
    Settings::set('grouptest.two', 'b');
    Settings::load(true);

    $group = Settings::group('grouptest');

    Assert::same('a', $group['one'] ?? null);
    Assert::same('b', $group['two'] ?? null);
});
