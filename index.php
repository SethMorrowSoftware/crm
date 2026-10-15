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
//
// The path is cut out of REQUEST_URI by hand rather than with parse_url():
// a URI starting with two slashes is a protocol-relative URL, so
// parse_url('//config/config.php') reports `config` as the host and
// '/config.php' as the path — and this guard would be inspecting a path from
// which the segment it is looking for had already been removed. The same
// routine lives in Request::rawPath(), which cannot be used here because the
// autoloader is not registered until bootstrap.php.
$uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = substr($uri, 0, strcspn($uri, '?#'));
$path = (string) preg_replace('#/{2,}#', '/', $path);
$path = $path === '' ? '/' : $path;

if (preg_match('#(^|/)(app|config|storage)(/|$)#i', $path) === 1) {
    http_response_code(404);
    exit('Not found.');
}

require CC_ROOT . '/app/bootstrap.php';
