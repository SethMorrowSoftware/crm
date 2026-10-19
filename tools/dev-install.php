<?php

declare(strict_types=1);

/**
 * Build a demo install for local development and screenshots, in one step.
 *
 *   php tools/dev-install.php                       # database castle_crm_dev
 *   php tools/dev-install.php --db=castle_crm_dev --fresh
 *
 * The same order the web installer uses — schema, permissions, settings, the
 * owner, templates, surveys, the venue, the playbooks, the example guests —
 * without a browser. It writes `config/config.php` pointing at the database,
 * so `php -S 127.0.0.1:8777 -t . tools/dev-server.php` then serves it, and
 * `node tools/screenshots.js` can photograph it.
 *
 * Signs in as demo@castle.test / demo-passphrase-99, which is what
 * tools/screenshots.js expects. Both are fictional by construction: the
 * screenshot guard refuses an account on a real domain.
 *
 * THREE REFUSALS, because this writes a config and drops tables:
 *   - it will not overwrite an existing config/config.php;
 *   - it will only touch a database whose name ends in `_dev`;
 *   - --fresh is needed to empty one that already has tables in it.
 *
 * Connection: CC_DEV_DB_HOST / _PORT / _USER / _PASS, defaulting to root with
 * no password on 127.0.0.1:3306 — the same server the test suite uses.
 */

if (PHP_SAPI !== 'cli') {
    // Never reachable over HTTP: it writes a config and drops tables.
    http_response_code(404);
    exit;
}

define('CC_ROOT', dirname(__DIR__));

$options = getopt('', ['db::', 'fresh']);
$name    = (string) ($options['db'] ?? 'castle_crm_dev');
$fresh   = array_key_exists('fresh', $options);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if (!str_ends_with($name, '_dev') || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    $fail("Refusing database '{$name}': a demo install only goes into a database named *_dev.");
}

$configPath = CC_ROOT . '/config/config.php';

if (is_file($configPath)) {
    $fail("config/config.php already exists. This tool does not overwrite an install; move it aside first.");
}

$db = [
    'host'    => getenv('CC_DEV_DB_HOST') ?: '127.0.0.1',
    'port'    => getenv('CC_DEV_DB_PORT') ?: '3306',
    'name'    => $name,
    'user'    => getenv('CC_DEV_DB_USER') ?: 'root',
    'pass'    => getenv('CC_DEV_DB_PASS') ?: '',
    'charset' => 'utf8mb4',
    'prefix'  => 'cc_',
    'socket'  => null,
];

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$tables = $server->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = ' . $server->quote($name)
)->fetchAll(PDO::FETCH_COLUMN);

if ($tables !== [] && !$fresh) {
    $fail("{$name} already has " . count($tables) . " tables. Pass --fresh to empty it.");
}

if ($tables !== []) {
    $server->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $table) {
        $server->exec('DROP TABLE `' . $name . '`.`' . str_replace('`', '', (string) $table) . '`');
    }

    $server->exec('SET FOREIGN_KEY_CHECKS = 1');
}

foreach (['logs', 'cache', 'uploads', 'exports', 'backups', 'sessions'] as $directory) {
    @mkdir(CC_ROOT . '/storage/' . $directory, 0775, true);
}

$export = static fn (mixed $value): string => var_export($value, true);

$config = "<?php\n\n// Written by tools/dev-install.php: a DEMO install. Safe to delete.\n\nreturn [\n"
    . "    'app_url'      => null,\n"
    . "    'env'          => 'development',\n"
    . "    'debug'        => true,\n"
    . "    'app_key'      => " . $export(base64_encode(random_bytes(32))) . ",\n"
    . "    'db'           => " . $export($db) . ",\n"
    . "    'storage_path' => " . $export(CC_ROOT . '/storage') . ",\n"
    . "];\n";

file_put_contents($configPath, $config);

require CC_ROOT . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\DB;
use App\Core\Migration;
use App\Core\Settings;
use App\Core\Upgrade;

try {
    // Loading the bootstrap has usually applied the schema already, through
    // the same upgrade check every request makes; this catches anything left.
    (new Migration())->run();
    echo 'Schema: ' . (int) DB::value('SELECT COUNT(*) FROM {migrations}') . " files applied.\n";

    (require CC_ROOT . '/install/seeds/permissions.php')();

    Settings::setMany([
        'venue.name'        => 'The Castle Fun Center',
        'venue.phone'       => '(845) 555-0100',
        'venue.address_1'   => '109 Brookside Ave',
        'venue.city'        => 'Chester',
        'venue.state'       => 'NY',
        'venue.postal_code' => '10918',
        'venue.website'     => 'https://example.test',
        'venue.timezone'    => 'America/New_York',
        'venue.tax_rate'    => '8.125',
        'venue.brand_color' => '#6b4ea8',
        'venue.confirmed'   => false,
        'system.safe_mode'            => true,
        'system.sending_paused'       => false,
        'system.quiet_hours_start'    => '09:00',
        'system.quiet_hours_end'      => '20:00',
        'system.message_cap_per_week' => 4,
        'system.lapse_days'           => 120,
        'system.attribution_days'     => 30,
        'system.installed_at'         => gmdate('Y-m-d H:i:s'),
        'system.version'              => trim((string) @file_get_contents(CC_ROOT . '/VERSION')),
        'system.schema_files'         => Upgrade::schemaMarker(),
        'system.force_https'          => false,
        'system.audit_retention_days' => 730,
        'system.install_url'          => 'http://127.0.0.1:8777',
        'mail.driver'      => 'log',
        'mail.from_email'  => 'noreply@example.test',
        'mail.from_name'   => 'The Castle Fun Center',
    ]);

    DB::insert('users', [
        'name'          => 'Demo Owner',
        'email'         => 'demo@castle.test',
        'password_hash' => Auth::hash('demo-passphrase-99'),
        'role_id'       => (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'"),
        'is_active'     => 1,
        'timezone'      => 'America/New_York',
        'created_at'    => gmdate('Y-m-d H:i:s'),
        'updated_at'    => gmdate('Y-m-d H:i:s'),
    ]);

    foreach (['templates', 'surveys', 'venue', 'playbooks', 'demo'] as $seed) {
        (require CC_ROOT . '/install/seeds/' . $seed . '.php')();
        echo "Seeded {$seed}.\n";
    }

    @touch(CC_ROOT . '/install/.lock');
} catch (Throwable $e) {
    @unlink($configPath);
    $fail('Failed, and removed the config again: ' . $e->getMessage());
}

echo "\nDemo install ready in {$name}.\n"
    . "  php -S 127.0.0.1:8777 -t . tools/dev-server.php\n"
    . "  sign in as demo@castle.test / demo-passphrase-99\n";
