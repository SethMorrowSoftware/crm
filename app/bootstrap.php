<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Called by index.php for web requests and by cron.php for scheduled work.
 * Everything here must be safe to run before the database exists, because the
 * installer boots through this file too.
 */

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Upgrade;
use App\Core\View;

if (!defined('CC_ROOT')) {
    throw new RuntimeException('CC_ROOT must be defined before loading bootstrap.php.');
}

// A minimum version check up front, because an older PHP would fail later with
// a confusing parse error instead of a clear message.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Castle CRM needs PHP 8.1 or newer. This server is running PHP ' . PHP_VERSION
        . '. In cPanel, open MultiPHP Manager and select 8.1 or later for this domain.');
}

// -----------------------------------------------------------------------
// Autoloader
// -----------------------------------------------------------------------

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, 4));
    $file = CC_ROOT . '/app/' . $relative . '.php';

    // Guard against a crafted class name escaping the app directory.
    $real = realpath($file);
    $base = realpath(CC_ROOT . '/app');

    if ($real !== false && $base !== false && str_starts_with($real, $base) && is_file($real)) {
        require $real;
    }
});

require CC_ROOT . '/app/helpers.php';

/**
 * Bring the database up to date when the code has been replaced.
 *
 * An upgrade on shared hosting is an unzip over the top of the old files;
 * nothing runs afterwards. Comparing the shipped VERSION against the marker
 * the last upgrade stored costs one string comparison per request, and skips
 * everything else until there is actually a new release in place.
 */
function cc_apply_pending_upgrade(): void
{
    $shipped = trim((string) @file_get_contents(CC_ROOT . '/VERSION'));

    if ($shipped === '' || $shipped === (string) Settings::get('system.version', '')) {
        return;
    }

    Upgrade::run($shipped);
}

// -----------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------

$configFile = CC_ROOT . '/config/config.php';
$isInstalled = is_file($configFile);

if ($isInstalled) {
    /** @var array<string,mixed> $config */
    $config = require $configFile;

    if (!is_array($config)) {
        http_response_code(500);
        exit('config/config.php must return an array. Restore it from config.sample.php and re-run the installer.');
    }

    Config::load($config);
} else {
    // Enough configuration to render the installer.
    Config::load([
        'env'     => 'production',
        'debug'   => false,
        'db'      => ['prefix' => 'cc_'],
    ]);
}

Config::set('storage_path', Config::get('storage_path', CC_ROOT . '/storage'));

// -----------------------------------------------------------------------
// Error handling
// -----------------------------------------------------------------------

Log::init((string) Config::get('storage_path'), (string) Config::get('log_level', 'info'));

// Report everything; whether it is *displayed* is a separate decision below.
error_reporting(E_ALL);
ini_set('display_errors', Config::isDebug() ? '1' : '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    // Promote notices and warnings to exceptions in development so they get
    // fixed, but only log them in production so a deprecation cannot take the
    // venue's CRM offline mid-shift.
    if (Config::isDebug()) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    Log::warning('PHP ' . $message, ['file' => $file . ':' . $line, 'severity' => $severity]);

    return true;
});

set_exception_handler(static function (Throwable $e): void {
    Log::critical('Unhandled exception', [
        'type'    => $e::class,
        'message' => $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
        'trace'   => Config::isDebug() ? $e->getTraceAsString() : '[suppressed]',
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $request = $GLOBALS['cc_request'] ?? null;

    if ($request instanceof Request && $request->wantsJson()) {
        Response::error(
            Config::isDebug() ? $e->getMessage() : 'Something went wrong on our side.',
            500,
            Config::isDebug() ? ['file' => $e->getFile() . ':' . $e->getLine()] : []
        )->send();

        return;
    }

    Response::html(View::renderError(500, 'Something went wrong', [
        'message' => Config::isDebug()
            ? $e->getMessage()
            : 'The error has been logged. Try again, and tell an administrator if it keeps happening.',
        'detail'  => Config::isDebug() ? $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : null,
    ]), 500)->send();
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    Log::critical('Fatal error', [
        'message' => $error['message'],
        'file'    => $error['file'] . ':' . $error['line'],
    ]);

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        echo View::renderError(500, 'Something went wrong', [
            'message' => 'The error has been logged.',
        ]);
    }
});

// All internal time is UTC; display conversion happens in local_time().
date_default_timezone_set('UTC');

mb_internal_encoding('UTF-8');

View::init(CC_ROOT . '/app/Views');

// -----------------------------------------------------------------------
// Non-routed entry points stop here and drive themselves: the CLI, and
// cron.php when a host has no CLI cron and triggers it over HTTP. Falling
// through to the router would make cron.php dispatch /cron.php as a route,
// 404, send that response, and only then run the actual scheduled work.
// -----------------------------------------------------------------------

/*
 * WHAT LISTENS TO WHAT, registered BEFORE the CLI early return below.
 *
 * It has to be both entry points, and cron is the more important of the two:
 * most automations fire from the time-based triggers and the nightly jobs, so
 * listeners registered only on the web path would mean an engine that works
 * when somebody is clicking and does nothing overnight. Registering one is
 * only storing a closure, so this costs nothing on a request that fires no
 * events.
 */
if ($isInstalled) {
    require CC_ROOT . '/app/listeners.php';
}

if (PHP_SAPI === 'cli' || defined('CC_WEB_CRON')) {
    if ($isInstalled) {
        DB::connect();
        Settings::load();
        cc_apply_pending_upgrade();
    }

    return;
}

// -----------------------------------------------------------------------
// Web request
// -----------------------------------------------------------------------

$request = Request::capture();
$GLOBALS['cc_request'] = $request;

// The installer is the entry point for this request and drives itself from
// here. It must be checked before anything else: when install/index.php is the
// running script, the base path derived from SCRIPT_NAME is /install, so the
// request path resolves to "/" and the redirect below would otherwise send the
// installer to /install/install/.
if (defined('CC_INSTALLER')) {
    return;
}

// Not installed yet, and this is not the installer: send the visitor there.
if (!$isInstalled) {
    if (!str_starts_with($request->path(), '/install')) {
        Response::redirect(url('install/'))->send();
    }

    return;
}

DB::connect();
Settings::load();
cc_apply_pending_upgrade();

// Force HTTPS, when the operator has turned it on.
//
// Deliberately off by default and settings-driven rather than hard-coded: a
// fresh cPanel install is reachable over plain HTTP until AutoSSL finishes
// issuing, and a redirect loop on first visit would lock the operator out of
// their own installer. Once they have a certificate they turn this on, and
// from then on a password or a guest's details never travel in the clear.
if (!$request->isSecure() && Settings::get('system.force_https', false)) {
    // Only ever redirect a safe, idempotent request. Bouncing a POST would
    // silently drop the form body and look like data loss.
    if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
        Response::redirect(
            'https://' . $request->host() . ($_SERVER['REQUEST_URI'] ?? '/'),
            301
        )->send();

        return;
    }

    Log::warning('Insecure request refused while force_https is on', [
        'method' => $request->method(),
        'path'   => $request->path(),
        'ip'     => $request->ip(),
    ]);

    Response::html(View::renderError(400, 'This connection is not secure', [
        'message' => 'Castle CRM is set to require HTTPS. Reload the page over https:// and try again.',
    ]), 400)->send();

    return;
}

Session::start($request);
Audit::init($request);

// Security headers that depend on request state.
$securityHeaders = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options'        => 'SAMEORIGIN',
    'Referrer-Policy'        => 'strict-origin-when-cross-origin',
];

if ($request->isSecure()) {
    // Only once HTTPS is actually working, so a pre-SSL install is not locked
    // out of its own domain by a browser remembering HSTS.
    $securityHeaders['Strict-Transport-Security'] = 'max-age=31536000';
}

// A strict CSP, with 'unsafe-inline' for styles only: the app ships a handful
// of inline style attributes for chart geometry, but no inline scripts.
$securityHeaders['Content-Security-Policy'] = implode('; ', [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data:",
    "font-src 'self'",
    "connect-src 'self'",
    "frame-ancestors 'self'",
    "base-uri 'self'",
    "form-action 'self'",
    "object-src 'none'",
]);

// Share request-scoped data with every view.
View::share('currentUser', Auth::user());
View::share('venueName', Settings::venueName());
View::share('safeMode', Settings::safeMode());
View::share('sendingPaused', Settings::sendingPaused());
View::share('flashMessages', Session::pull('_flash', []));

// Validation state survives exactly one redirect.
View::share('errors', Session::pull('_errors', []));
Session::put('_old_input', Session::pull('_old_input_next', []));

$router = new Router();
require CC_ROOT . '/app/routes.php';

$response = $router->dispatch($request);

$response->headers($securityHeaders);

// Persist the session and attach its cookie.
$cookie = Session::save();

if ($cookie !== null) {
    $response->cookie(Session::cookieName(), $cookie[0], $cookie[1]);
}

$response->send();
