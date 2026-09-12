<?php
/* One-shot installer: creates the database, tables and seeds rooms/bots.
 *   php install.php
 *   (or open in a browser under the API path)
 */
declare(strict_types=1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Db.php';

$html = PHP_SAPI === 'cli' ? '' : '<pre>';

try {
    if (Db::isInstalled()) {
        echo $html . "Database already installed — nothing to do.\n";
    } else {
        Db::installDb();
        echo $html . "Installation complete.\n";
    }
    echo $html . "Tables: users, rooms, game_sessions, players, chat_messages\n";
    echo $html . "Rooms seeded: Aurum Table, Emerald Lounge, Onyx Room, High Rollers\n";
    echo $html . "Bot accounts seeded (8).\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo $html . "Installation failed: " . $e->getMessage() . "\n";
}
if (!$html) {
    echo "\n";
} else {
    echo '</pre>';
}