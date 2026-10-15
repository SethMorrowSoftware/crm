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

$root = dirname(__DIR__);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

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

require $root . '/index.php';
