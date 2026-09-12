<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'poker');
define('DB_USER', 'root');
define('DB_PASS', '');

/*
 * URL prefix under which the API folder is mounted when deployed under a web
 * server in a sub-directory (e.g. http://localhost/fullstack/poker/api).
 * Leave empty ("") when running through the bundled router / Vite dev proxy.
 */
define('API_BASE', '');

define('CHIPS_START', 10000);   // bankroll given to new accounts
define('SITEOUT', 40);          // seconds before a silent human is folded
define('SHOWDOWN_PAUSE', 4);    // seconds the showdown result stays visible