<?php

declare(strict_types=1);

/**
 * Castle CRM — front controller.
 *
 * Every request that is not a real file on disk arrives here (see .htaccess).
 * This file stays deliberately tiny: it establishes the root path, refuses to
 * serve anything under the protected directories even if the web server's
 * rewrite rules were bypassed, and hands off to the bootstrap.
 */

define('CC_ROOT', __DIR__);
define('CC_START', microtime(true));

// Defence in depth. .htaccess already denies these, but a host that ignores
// .htaccess (or an nginx deployment set up from the wrong recipe) would
// otherwise serve config/config.php as plain text.
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';

if (preg_match('#(^|/)(app|config|storage)(/|$)#i', (string) $path) === 1) {
    http_response_code(404);
    exit('Not found.');
}

require CC_ROOT . '/app/bootstrap.php';
