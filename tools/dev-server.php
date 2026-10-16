<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in web server.
 *
 * Production uses Apache + .htaccess; this reproduces the same two rules so
 * local development and CI smoke tests exercise the real front controller:
 *
 *   1. serve a request that maps to a real file (assets), except under the
 *      protected directories
 *   2. send everything else to index.php
 *
 * Usage:
 *   php -S 127.0.0.1:8899 -t . tools/dev-server.php
 *
 * Never use this to serve the application publicly — the built-in server is
 * single-threaded and not hardened.
 */

/*
 * NOT UNDER A REAL WEB SERVER.
 *
 * `cli-server` AS WELL AS `cli`, and getting that wrong broke everything for
 * a minute: this file is the ROUTER SCRIPT for `php -S`, so it is re-executed
 * on every request the development server handles — and under the built-in
 * server `PHP_SAPI` is `cli-server`, not `cli`. A plain `!== 'cli'` guard
 * therefore 404s the entire application the moment it is added, which is
 * exactly what it did.
 *
 * What the guard is for is Apache or FPM: the shipped `.htaccess` denies
 * `tools/`, and a host that ignores it (nginx in front, `AllowOverride None`)
 * would otherwise execute this file on request and bind a port on the
 * venue's server.
 */
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    http_response_code(404);
    exit;
}


$root = dirname(__DIR__);

// Cut the path out of REQUEST_URI by hand, for the same reason index.php does:
// parse_url() reads a leading "//" as a protocol-relative authority and would
// hand back a path with its first segment removed, so a request for
// //config/config.php would slip past the deny rules below.
$uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = substr($uri, 0, strcspn($uri, '?#'));
$path = (string) preg_replace('#/{2,}#', '/', $path);
$path = $path === '' ? '/' : $path;

// Mirror the .htaccess deny rules. The front controller checks these too, but
// matching here keeps the dev server honest about what production serves.
$isProtected = preg_match('#^/(app|config|storage)(/|$)#i', $path) === 1
    || preg_match('#^/install/(schema|seeds|steps)(/|$)#i', $path) === 1
    || preg_match('#\.(sql|md|lock|log)$#i', $path) === 1;

if ($isProtected) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found.\n";

    return true;
}

$candidate = $root . $path;

// Let the built-in server stream real files itself (returning false does that).
if ($path !== '/' && is_file($candidate)) {
    return false;
}

// Apache's DirectoryIndex: a request for a directory containing index.php runs
// that file. The installer lives at /install/, so without this the dev server
// would not reach it the way production does.
if ($path !== '/' && is_dir($candidate) && is_file(rtrim($candidate, '/') . '/index.php')) {
    require rtrim($candidate, '/') . '/index.php';

    return true;
}

require $root . '/index.php';
