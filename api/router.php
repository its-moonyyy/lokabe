<?php
/* Router for `php -S` development server.
 * Usage: php -S 127.0.0.1:8000 -t api api/router.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(400);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/* serve static files that exist */
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';