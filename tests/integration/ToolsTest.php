<?php

declare(strict_types=1);

/**
 * The command-line tools, run as real subprocesses.
 *
 * Both exist for a moment when the web application cannot help: nobody can
 * sign in, or the database is too large to dump inside a web request. A tool
 * that only works in theory is no use at that moment, so these tests invoke
 * the actual scripts through PHP rather than calling into their internals.
 *
 * They run against the config file the test runner wrote for the HTTP server,
 * which is the same file a real install would have.
 */

use App\Core\Auth;
use App\Core\Backup;
use App\Core\DB;
use Tests\Assert;
use Tests\Harness;

Harness::suite('integration/tools');

if (!test_boot()) {
    Harness::skip('tools suite', 'no database available');

    return;
}

if (!is_file(CC_ROOT . '/config/config.php')) {
    Harness::skip('tools suite', 'no config/config.php; the tools need one, as a real install has');

    return;
}

/**
 * Run a tool and capture both streams and the exit code.
 *
 * @param list<string> $args
 * @return array{code:int,out:string,err:string}
 */
function run_tool(string $script, array $args = [], string $stdin = ''): array
{
    $command = array_merge([PHP_BINARY, CC_ROOT . '/tools/' . $script], $args);

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        CC_ROOT
    );

    if (!is_resource($process)) {
        Assert::fail('could not start ' . $script);
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
}

// ------------------------------------------------------- reset-password.php

Harness::test('with no arguments the reset tool lists the accounts', function (): void {
    $id = test_make_user('owner', ['email' => 'cli-listed@example.test']);

    $result = run_tool('reset-password.php');

    Assert::same(0, $result['code'], $result['err']);
    Assert::contains('cli-listed@example.test', $result['out']);
    Assert::notContains(
        '$argon2',
        $result['out'],
        'listing accounts must not print password hashes'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('--generate sets a new password and prints it once', function (): void {
    $id = test_make_user('owner', [
        'email'         => 'cli-generate@example.test',
        'password_hash' => Auth::hash('the-old-passphrase-here'),
    ]);

    $before = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    $result = run_tool('reset-password.php', ['cli-generate@example.test', '--generate']);

    Assert::same(0, $result['code'], $result['err']);

    $after = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::notSame($before, $after, 'the stored hash should have changed');

    // Pull the generated password out of the output and prove it works. It is
    // printed on a line of its own, indented, after "Password changed." — four
    // lowercase words and a number, joined by hyphens.
    preg_match('/^\s+((?:[a-z]+-){4}\d{3})\s*$/m', $result['out'], $match);

    Assert::notEmpty($match, 'the tool has to print the password it generated: ' . $result['out']);
    Assert::true(
        password_verify($match[1], $after),
        'the printed password must actually be the one that was set'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('a reset ends every live session for that account', function (): void {
    $id = test_make_user('owner', ['email' => 'cli-sessions@example.test']);

    DB::insert('sessions', [
        'id'            => bin2hex(random_bytes(16)),
        'user_id'       => $id,
        'payload'       => 'test',
        'last_activity' => gmdate('Y-m-d H:i:s'),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ]);

    run_tool('reset-password.php', ['cli-sessions@example.test', '--generate']);

    Assert::same(
        0,
        (int) DB::value('SELECT COUNT(*) FROM {sessions} WHERE user_id = :id', ['id' => $id]),
        'whoever locked the owner out may still be signed in somewhere'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('--unlock clears a lockout without changing the password', function (): void {
    $id = test_make_user('owner', [
        'email'           => 'cli-unlock@example.test',
        'password_hash'   => Auth::hash('keep-this-passphrase'),
        'failed_attempts' => 9,
        'locked_until'    => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);

    $before = (string) DB::value('SELECT password_hash FROM {users} WHERE id = :id', ['id' => $id]);

    $result = run_tool('reset-password.php', ['cli-unlock@example.test', '--unlock']);

    Assert::same(0, $result['code'], $result['err']);

    $user = DB::first('SELECT * FROM {users} WHERE id = :id', ['id' => $id]);

    Assert::null($user['locked_until']);
    Assert::same(0, (int) $user['failed_attempts']);
    Assert::same($before, (string) $user['password_hash'], 'unlock is not a password reset');

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('an unknown address is refused with a non-zero exit code', function (): void {
    $result = run_tool('reset-password.php', ['nobody@example.test', '--generate']);

    Assert::notSame(0, $result['code'], 'a script in a cron line has to signal failure');
    Assert::contains('nobody@example.test', $result['err'] . $result['out']);
});

Harness::test('the reset tool refuses a weak password', function (): void {
    $id = test_make_user('owner', ['email' => 'cli-weak@example.test']);

    // Typed twice through the prompt, as a person would.
    $result = run_tool('reset-password.php', ['cli-weak@example.test'], "abc\nabc\n");

    Assert::notSame(0, $result['code'], 'the CLI must apply the same policy as the web form');
    Assert::contains('password', strtolower($result['err'] . $result['out']));

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('the reset tool records what it did in the audit log', function (): void {
    $id = test_make_user('owner', ['email' => 'cli-audited@example.test']);

    DB::delete('audit_log', "action LIKE 'password%'");

    run_tool('reset-password.php', ['cli-audited@example.test', '--generate']);

    Assert::true(
        DB::exists("SELECT id FROM {audit_log} WHERE action LIKE 'password%'"),
        'a password reset from a shell is exactly the event an audit log is for'
    );

    DB::delete('users', 'id = :id', ['id' => $id]);
});

Harness::test('the reset tool is unreachable over HTTP', function (): void {
    // Not a behavioural test of the web server — a check that the guard is in
    // the file, since the consequence of losing it is an unauthenticated
    // password reset endpoint.
    $source = (string) file_get_contents(CC_ROOT . '/tools/reset-password.php');

    Assert::contains("PHP_SAPI !== 'cli'", $source);
    Assert::contains('http_response_code(404)', $source);
});

// -------------------------------------------------------------- backup.php

Harness::test('--help explains itself and exits cleanly', function (): void {
    $result = run_tool('backup.php', ['--help']);

    Assert::same(0, $result['code']);
    Assert::contains('--list', $result['out']);
    Assert::contains('--export', $result['out']);
});

Harness::test('the backup tool writes a real backup', function (): void {
    foreach (Backup::all() as $existing) {
        Backup::delete($existing['filename']);
    }

    $result = run_tool('backup.php', ['--no-compress']);

    Assert::same(0, $result['code'], $result['err']);
    Assert::contains('Wrote ', $result['out']);
    Assert::contains('config/config.php', $result['out'], 'it must warn what is not in the file');

    $backups = Backup::all();

    Assert::count(1, $backups);
    Assert::false($backups[0]['compressed'], '--no-compress was asked for');

    $sql = (string) file_get_contents((string) Backup::pathFor($backups[0]['filename']));

    Assert::contains('CREATE TABLE `cc_contacts`', $sql);
    Assert::contains('-- Complete.', $sql);
});

Harness::test('--list shows what is on disk', function (): void {
    $result = run_tool('backup.php', ['--list']);

    Assert::same(0, $result['code'], $result['err']);

    foreach (Backup::all() as $backup) {
        Assert::contains($backup['filename'], $result['out']);
    }
});

Harness::test('--keep prunes to the requested number', function (): void {
    $dir = Backup::directory();

    foreach (['20260301-010000', '20260302-010000'] as $i => $stamp) {
        file_put_contents($dir . '/castle-crm-' . $stamp . '.sql', '-- filler');
        touch($dir . '/castle-crm-' . $stamp . '.sql', 1_772_000_000 + ($i * 86400));
    }

    Assert::greaterThan(1, count(Backup::all()));

    $result = run_tool('backup.php', ['--no-compress', '--keep=1', '--quiet']);

    Assert::same(0, $result['code'], $result['err']);
    Assert::count(1, Backup::all());

    // --quiet prints the path and nothing else, so it can be used in a script.
    Assert::same(1, substr_count(trim($result['out']), "\n") + 1);
    Assert::contains('castle-crm-', trim($result['out']));
});

Harness::test('--export writes CSV to stdout and the count to stderr', function (): void {
    $id = test_make_contact(['first_name' => 'Piped', 'last_name' => 'Guest']);

    $result = run_tool('backup.php', ['--export=contacts']);

    Assert::same(0, $result['code'], $result['err']);
    Assert::contains('first_name', $result['out'], 'the header row belongs on stdout');
    Assert::contains('Piped', $result['out']);
    Assert::contains('rows exported', $result['err'], 'so that piping stdout to a file keeps it clean');
    Assert::notContains('rows exported', $result['out']);

    DB::delete('contacts', 'id = :id', ['id' => $id]);
});

Harness::test('--export refuses a table that is not exportable', function (): void {
    $result = run_tool('backup.php', ['--export=api_tokens']);

    Assert::notSame(0, $result['code']);
    Assert::contains('cannot be exported', $result['err']);
    Assert::empty(trim($result['out']), 'nothing at all should reach stdout');
});

Harness::test('an unrecognised argument is refused rather than ignored', function (): void {
    $result = run_tool('backup.php', ['--delete-everything']);

    Assert::notSame(0, $result['code'], 'silently ignoring a misspelled flag is how data gets lost');
});

Harness::test('the backup tool is unreachable over HTTP', function (): void {
    $source = (string) file_get_contents(CC_ROOT . '/tools/backup.php');

    Assert::contains("PHP_SAPI !== 'cli'", $source);
});

Harness::test('--keep with no value is refused rather than pruning to one', function (): void {
    // `--keep` on its own coerced to max(1, (int) true) = 1 and deleted every
    // backup but the one just written: on a host keeping 14 nightlies, 13
    // destroyed, reported as a success. `--keep 14` (a space) was already
    // refused, so the valueless form was the one shape that got through — and
    // the file's own comment claimed the allowlist prevented exactly this.
    $before = count(Backup::all());

    foreach (['--keep', '--keep=', '--keep=abc', '--keep=0', '--keep=-3'] as $arg) {
        $result = run_tool('backup.php', [$arg]);

        Assert::notSame(0, $result['code'], $arg . ' must be refused');
        Assert::contains('keep', strtolower($result['err']), $arg);
    }

    Assert::count(
        $before,
        Backup::all(),
        'a refused run must not have taken a backup or pruned anything'
    );
});

Harness::test('--export with no value is refused', function (): void {
    foreach (['--export', '--export='] as $arg) {
        $result = run_tool('backup.php', [$arg]);

        Assert::notSame(0, $result['code'], $arg . ' must be refused');
        Assert::empty(trim($result['out']), 'nothing should reach stdout');
    }
});

Harness::test('--keep with a real value still works', function (): void {
    // The other half: the validation must not break the documented usage.
    $result = run_tool('backup.php', ['--no-compress', '--keep=3', '--quiet']);

    Assert::same(0, $result['code'], $result['err']);
    Assert::contains('castle-crm-', trim($result['out']));
});
