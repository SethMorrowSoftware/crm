<?php

declare(strict_types=1);

/**
 * The installer's engine: everything that finishes an install once the
 * decisions have been made.
 *
 * Split out of `install/index.php`, which is the EIGHT-STEP WIZARD — the half
 * of the installer that asks a person questions in a browser. This half asks
 * nothing. It connects, verifies the privileges, seeds, creates the owner,
 * writes `config/config.php` and locks the installer.
 *
 * Two callers, and the second one is why this file exists:
 *
 *   - `install/index.php`, the web installer a cPanel operator walks through;
 *   - `tools/cli-install.php`, which `install.sh` runs on an Ubuntu server,
 *     where nobody is sitting in front of a browser at all.
 *
 * Written twice, these would be two implementations of "a finished install is
 * in Safe Mode, with every automation off and mail going nowhere" — and they
 * would drift, in the direction of the one nobody walks in CI. There is one,
 * and the suite walks both of its callers (`tests/install/`).
 *
 * NOTHING HERE READS `$_SERVER`. The two request-shaped values a finished
 * install records — the URL it was set up on, and the domain its mail appears
 * to come from — arrive in `$context`, because on a command line there is no
 * request to take them from.
 */

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\Encryption;
use App\Core\Settings;

/**
 * Point the application at a database and connect to it.
 *
 * @param array<string,mixed> $db
 */
function installer_connect(array $db): PDO
{
    Config::set('db.host', $db['host']);
    Config::set('db.port', $db['port']);
    Config::set('db.name', $db['name']);
    Config::set('db.user', $db['user']);
    Config::set('db.pass', $db['pass']);
    Config::set('db.prefix', $db['prefix']);
    Config::set('db.socket', $db['socket']);

    DB::disconnect();
    $pdo = DB::connect();
    DB::setPrefix((string) $db['prefix']);

    return $pdo;
}

/**
 * Test the supplied credentials, verify the privileges we need, and report
 * anything already in the database.
 *
 * @param array<string,mixed> $db
 * @return array{ok:bool,error:string,warnings:list<string>,version:string}
 */
function installer_testDatabase(array $db): array
{
    $warnings = [];

    try {
        $pdo = installer_connect($db);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            // The driver's own message is the most useful thing here, and it
            // does not contain the password.
            'error' => 'Could not connect: ' . installer_friendlyDbError($e->getMessage()),
            'warnings' => [],
            'version' => '',
        ];
    }

    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    // Prove we can actually create and drop, rather than discovering it
    // halfway through applying the schema.
    $probe = $db['prefix'] . 'install_probe';

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $probe . '` (`id` INT NOT NULL) ENGINE=InnoDB');
        $pdo->exec('ALTER TABLE `' . $probe . '` ADD COLUMN `probe` INT NULL');
        $pdo->exec('DROP TABLE `' . $probe . '`');
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => 'Connected, but this user cannot create tables. In cPanel → MySQL Databases, '
                . 'add the user to the database with ALL PRIVILEGES.',
            'warnings' => [],
            'version' => $version,
        ];
    }

    // Already-installed detection.
    $ours = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name LIKE ' . $pdo->quote($db['prefix'] . '%')
    )->fetchColumn();

    if ($ours > 0) {
        $warnings[] = sprintf(
            'This database already contains %d table%s with the prefix "%s". Setup will add anything '
            . 'missing and leave existing data alone.',
            $ours,
            $ours === 1 ? '' : 's',
            $db['prefix']
        );
    }

    $foreign = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name NOT LIKE ' . $pdo->quote($db['prefix'] . '%')
    )->fetchColumn();

    if ($foreign > 0) {
        $warnings[] = sprintf(
            'This database also holds %d table%s belonging to something else. That is safe — Castle CRM '
            . 'only touches tables starting with "%s" — but double-check you picked the right database.',
            $foreign,
            $foreign === 1 ? '' : 's',
            $db['prefix']
        );
    }

    if (version_compare($version, '5.7.8', '<') && !str_contains(strtolower($version), 'mariadb')) {
        $warnings[] = 'MySQL ' . $version . ' is older than the tested 5.7.8. Setup may fail on JSON columns.';
    }

    return ['ok' => true, 'error' => '', 'warnings' => $warnings, 'version' => $version];
}

/**
 * Translate a driver error into something actionable in cPanel.
 */
function installer_friendlyDbError(string $message): string
{
    if (str_contains($message, 'Access denied')) {
        return 'the username or password was rejected. In cPanel → MySQL Databases, confirm the user is '
            . 'assigned to this database with ALL PRIVILEGES. Remember cPanel prefixes both names with '
            . 'your account, like "cpaneluser_castlecrm".';
    }

    if (str_contains($message, 'Unknown database')) {
        return 'that database does not exist. Create it in cPanel → MySQL Databases first, and use the '
            . 'full prefixed name.';
    }

    if (str_contains($message, "Can't connect") || str_contains($message, 'Connection refused')) {
        return 'the server could not be reached. On cPanel the host is almost always "localhost".';
    }

    return $message;
}

/**
 * Write the configuration, create the owner, run the seeds.
 *
 * Ordered so the config file is written LAST: if seeding fails, the install is
 * still marked incomplete and the operator can retry rather than landing in a
 * half-configured application.
 *
 * @param array<string,mixed>                    $state
 * @param array{host:string,base_url:string}     $context  Where this install
 *        is being reached: the host its mail should appear to come from, and
 *        the base URL to record as `system.install_url`. Both may be empty —
 *        a CLI install with no domain yet — and both are then simply not used.
 * @return array<string,mixed>
 */
function installer_finalise(array &$state, array $context): array
{
    if (!isset($state['db'], $state['venue'], $state['owner'], $state['owner_password'])) {
        throw new RuntimeException('Some setup steps are incomplete. Start again from step 1.');
    }

    installer_connect($state['db']);

    $appKey    = Encryption::generateKey();
    $cronToken = bin2hex(random_bytes(24));

    // The key must be live before anything encrypted is written.
    Config::set('app_key', $appKey);
    Config::set('cron_token', $cronToken);

    $summary = ['seeds' => []];

    DB::transaction(static function () use ($state, $context, &$summary): void {
        // --- Permissions and roles (always; the app is unusable without them)
        $seedPermissions = require CC_ROOT . '/install/seeds/permissions.php';
        $summary['seeds']['permissions'] = $seedPermissions();

        // --- Venue settings
        $venue = $state['venue'];

        Settings::setMany([
            'venue.name'        => $venue['name'],
            'venue.email'       => $venue['email'] ?: null,
            'venue.phone'       => $venue['phone'] ?: null,
            'venue.address_1'   => $venue['address_1'] ?: null,
            'venue.city'        => $venue['city'] ?: null,
            'venue.state'       => $venue['state'] ?: null,
            'venue.postal_code' => $venue['postal_code'] ?: null,
            'venue.website'     => $venue['website'] ?: null,
            'venue.timezone'    => $venue['timezone'],
            'venue.tax_rate'    => $venue['tax_rate'],
            'venue.brand_color' => '#6b4ea8',
            // The operator still has to confirm these on the dashboard
            // checklist, because the seeded prices came from a public website.
            'venue.confirmed'   => false,
        ]);

        Settings::setMany([
            // Safe Mode ON. This is the single most important default in the
            // installer: a finished install cannot message a real guest.
            'system.safe_mode'            => true,
            'system.sending_paused'       => false,
            'system.quiet_hours_start'    => '09:00',
            'system.quiet_hours_end'      => '20:00',
            'system.message_cap_per_week' => 4,
            'system.lapse_days'           => 120,
            'system.attribution_days'     => 30,
            'system.installed_at'         => gmdate('Y-m-d H:i:s'),
            'system.version'              => trim((string) @file_get_contents(CC_ROOT . '/VERSION')),
            // Off by default: a fresh cPanel domain is plain HTTP until AutoSSL
            // issues a certificate, and forcing HTTPS before then would lock
            // the operator out of the application they just installed. They
            // turn it on from Settings → System once the padlock appears.
            'system.force_https'          => false,
            'system.audit_retention_days' => 730,
            // The URL this install was reached on while it was being set up.
            //
            // Recorded so a link in an email never has to be built from the
            // request's Host header, which a crafted request controls: that is
            // how a password-reset link gets pointed at somebody else's domain.
            // The operator is using the real address right now, by definition,
            // which makes this the one moment it can be captured for free.
            'system.install_url'          => $context['base_url'],
        ]);

        // --- Email defaults
        //
        // The LOG driver on purpose. Nothing leaves the server until somebody
        // configures a provider on Settings → Email, which means a fresh
        // install cannot email a guest even if Safe Mode were turned off on the
        // same day. A password reset still works: the log driver writes the
        // whole message, link included, to storage/logs/ where an
        // administrator can retrieve it.
        $mailDomain = preg_replace('/^www\./', '', explode(':', $context['host'])[0]);

        Settings::setMany([
            'mail.driver'               => 'log',
            'mail.from_email'           => $venue['email'] ?: ($mailDomain !== '' && $mailDomain !== null
                ? 'noreply@' . $mailDomain
                : 'noreply@localhost'),
            'mail.from_name'            => $venue['name'],
            'mail.reply_to'             => $venue['email'] ?: null,
            'mail.smtp_port'            => 587,
            'mail.smtp_encryption'      => 'tls',
            'mail.smtp_verify_peer'     => true,
            'mail.cost_per_email_cents' => 0,
        ]);

        // --- Owner account
        $ownerRoleId = (int) DB::value("SELECT id FROM {roles} WHERE slug = 'owner'");

        if ($ownerRoleId === 0) {
            throw new RuntimeException('The owner role was not created. Re-run step 4.');
        }

        $existing = DB::first(
            'SELECT id FROM {users} WHERE email = :email',
            ['email' => $state['owner']['email']]
        );

        if ($existing !== null) {
            DB::update('users', [
                'name'          => $state['owner']['name'],
                'password_hash' => Auth::hash($state['owner_password']),
                'role_id'       => $ownerRoleId,
                'is_active'     => 1,
                'timezone'      => $state['venue']['timezone'],
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $existing['id']]);

            $summary['owner_id'] = (int) $existing['id'];
        } else {
            $summary['owner_id'] = DB::insert('users', [
                'name'          => $state['owner']['name'],
                'email'         => $state['owner']['email'],
                'password_hash' => Auth::hash($state['owner_password']),
                'role_id'       => $ownerRoleId,
                'is_active'     => 1,
                'timezone'      => $state['venue']['timezone'],
                'created_at'    => gmdate('Y-m-d H:i:s'),
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        }

        // --- Email templates (always)
        //
        // Not optional, because Mailer::sendTemplate() resolves by slug and
        // refuses to send when the row is missing. Without this seed a
        // password reset would fail with "no_template" on a brand-new install.
        $seedTemplates = require CC_ROOT . '/install/seeds/templates.php';
        $summary['seeds']['templates'] = $seedTemplates();

        // --- Feedback surveys (always)
        //
        // Also not optional: `survey_responses.survey_id` is a non-null
        // foreign key, so with no survey row the reputation module has
        // nowhere to record an answer. Both ship SWITCHED OFF — see the
        // seed's own comment.
        $seedSurveys = require CC_ROOT . '/install/seeds/surveys.php';
        $summary['seeds']['surveys'] = $seedSurveys();

        // --- Optional seeds
        if (!empty($state['seeds']['venue'])) {
            $seedVenue = require CC_ROOT . '/install/seeds/venue.php';
            $summary['seeds']['venue'] = $seedVenue();
        }

        if (!empty($state['seeds']['playbooks'])) {
            $seedPlaybooks = require CC_ROOT . '/install/seeds/playbooks.php';
            $summary['seeds']['playbooks'] = $seedPlaybooks();
        }

        // Last, because the example deals and bookings reference the pipelines
        // and packages the venue seed creates.
        if (!empty($state['seeds']['demo'])) {
            $seedDemo = require CC_ROOT . '/install/seeds/demo.php';
            $summary['seeds']['demo'] = $seedDemo();
        }
    });

    // --- Write the config file, atomically.
    installer_writeConfig($state['db'], $appKey, $cronToken);

    // --- Lock the installer.
    //
    // The note says both things, because the right answer depends on how the
    // application got here and the file cannot ask. An unzipped release can
    // lose this directory safely — the next upgrade is another unzip, which
    // puts it back. A git checkout must keep it: install/schema is what
    // applies the next version's tables after a pull, and deleting tracked
    // files only makes that pull a conflict.
    @file_put_contents(
        CC_ROOT . '/install/.lock',
        'Castle CRM was installed on ' . gmdate('c') . " UTC.\n"
        . "The web installer is closed while this file exists.\n"
        . "Deployed by uploading a release? You can delete the whole install/ directory.\n"
        . "Deployed from a git checkout? Keep it — install/schema applies the next upgrade.\n"
    );

    $summary['cron_line'] = sprintf(
        '* * * * * %s %s/cron.php >/dev/null 2>&1',
        PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'fpm') ? PHP_BINARY : '/usr/local/bin/php',
        CC_ROOT
    );

    $summary['cron_token'] = $cronToken;
    $summary['dns'] = installer_dnsRecords($context['host']);

    return $summary;
}

/**
 * @param array<string,mixed> $db
 */
function installer_writeConfig(array $db, string $appKey, string $cronToken): void
{
    $export = static fn (mixed $v): string => var_export($v, true);

    $contents = <<<PHP
<?php
/**
 * Castle CRM configuration.
 *
 * Written by the installer on {$export(gmdate('c'))}.
 * Keep this file out of version control: it holds the database password and
 * the encryption key.
 */

return [
    'app_url'   => null,
    'env'       => 'production',
    'debug'     => false,

    // Encrypts provider credentials at rest. CHANGING THIS MAKES EXISTING
    // ENCRYPTED SETTINGS UNREADABLE — you would have to re-enter them.
    'app_key'   => {$export($appKey)},

    'db' => [
        'host'    => {$export($db['host'])},
        'port'    => {$export((int) $db['port'])},
        'name'    => {$export($db['name'])},
        'user'    => {$export($db['user'])},
        'pass'    => {$export($db['pass'])},
        'charset' => 'utf8mb4',
        'prefix'  => {$export($db['prefix'])},
        'socket'  => {$export($db['socket'])},
    ],

    'storage_path' => null,

    'session' => [
        'name'             => 'castle_crm_session',
        'idle_timeout'     => 28800,
        'absolute_timeout' => 2592000,
        'cookie_secure'    => null,
        'cookie_samesite'  => 'Lax',
    ],

    // Enable ONLY behind a proxy you control. With no proxy, this would let a
    // client spoof its own IP and defeat rate limiting.
    'trust_proxy' => false,

    // Shared secret for triggering cron over HTTP where CLI cron is unavailable.
    'cron_token' => {$export($cronToken)},

    'log_level' => 'info',
];

PHP;

    $target = CC_ROOT . '/config/config.php';
    $temp   = $target . '.' . getmypid() . '.tmp';

    if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
        throw new RuntimeException(
            'Could not write config/config.php. Set the config folder to 0775 in cPanel File Manager.'
        );
    }

    // Readable by the web server, not by the world.
    @chmod($temp, 0640);

    if (!@rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException('Could not finalise config/config.php. Check the config folder permissions.');
    }
}

/**
 * The DNS records to add in cPanel → Zone Editor, so mail from Castle CRM is
 * not treated as spam.
 *
 * @param string $host The domain this install is reached on, if it has one.
 * @return list<array{type:string,name:string,value:string,note:string}>
 */
function installer_dnsRecords(string $host): array
{
    $host = $host !== '' ? $host : 'your-domain.com';
    $domain = preg_replace('/^www\./', '', explode(':', $host)[0]) ?? $host;

    return [
        [
            'type'  => 'TXT',
            'name'  => $domain,
            'value' => 'v=spf1 +a +mx include:_spf.' . $domain . ' ~all',
            'note'  => 'Authorises this server to send mail for the domain. If an SPF record already '
                . 'exists, ADD to it rather than creating a second one — two SPF records break both.',
        ],
        [
            'type'  => 'TXT',
            'name'  => '_dmarc.' . $domain,
            'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@' . $domain,
            'note'  => 'Starts DMARC in report-only mode. Tighten to p=quarantine once reports look clean.',
        ],
        [
            'type'  => 'TXT',
            'name'  => 'default._domainkey.' . $domain,
            'value' => '(generated by cPanel)',
            'note'  => 'DKIM signs your mail. In cPanel open Email Deliverability and click Repair — it '
                . 'generates and installs this record for you.',
        ],
    ];
}
