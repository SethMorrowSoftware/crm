<?php

declare(strict_types=1);

/**
 * mod_rewrite self-test target.
 *
 * .htaccess rewrites /__rewrite_probe to this file. The installer fetches that
 * URL: if this response comes back, rewriting works. Testing it live beats
 * checking apache_get_modules(), which is unavailable under PHP-FPM and lies
 * under some cPanel configurations.
 */

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

echo 'rewrite-ok';
