<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Api.php';
require_once __DIR__ . '/src/Cards.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Game.php';

/* ---- CORS ----------------------------------------------------------- */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

/* ---- Routing -------------------------------------------------------- */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = rtrim($path, '/');

/* strip configured prefix (Apache sub-directory deployment) */
if (API_BASE !== '' && str_starts_with($path, API_BASE)) {
    $path = substr($path, strlen(API_BASE));
}

/* strip filename if PHP built-in server routed here */
$path = preg_replace('#/index\.php$#', '', $path);

$seg = array_values(array_filter(explode('/', $path), fn ($x) => $x !== ''));

try {
    route($seg, $method);
} catch (Throwable $e) {
    Api::error(500, $e->getMessage());
}

/* ====================================================================== */
/* Route dispatcher                                                       */
/* ====================================================================== */

function route(array $seg, string $method): void
{
    $r0 = $seg[0] ?? '';
    $r1 = $seg[1] ?? '';
    $r2 = $seg[2] ?? '';

    /* ---- auth -------------------------------------------------------- */
    if ($r0 === 'auth' && $method === 'POST' && $r1 === 'register') {
        authRegister();
        return;
    }
    if ($r0 === 'auth' && $method === 'POST' && $r1 === 'login') {
        authLogin();
        return;
    }

    /* ---- me ---------------------------------------------------------- */
    if ($r0 === 'me' && $method === 'GET') {
        meProfile();
        return;
    }
    if ($r0 === 'me' && $method === 'POST' && $r1 === 'buyin') {
        meBuyin();
        return;
    }

    /* ---- rooms ------------------------------------------------------- */
    if ($r0 === 'rooms' && count($seg) === 1 && $method === 'GET') {
        roomsList();
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'state') {
        roomsState((int)$r1);
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'join' && $method === 'POST') {
        roomsJoin((int)$r1);
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'leave' && $method === 'POST') {
        roomsLeave((int)$r1);
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'action' && $method === 'POST') {
        roomsAction((int)$r1);
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'buyin' && $method === 'POST') {
        roomsBuyin((int)$r1);
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'chat' && $method === 'GET') {
        roomsChatGet((int)$r1);
        return;
    }
    if ($r0 === 'rooms' && $r1 !== '' && $r2 === 'chat' && $method === 'POST') {
        roomsChatPost((int)$r1);
        return;
    }

    Api::error(404, 'Not found');
}

/* ====================================================================== */
/* Handlers                                                               */
/* ====================================================================== */

/* ---- Auth ------------------------------------------------------------ */

function authRegister(): void
{
    $b = Api::body();
    $uname = trim($b['username'] ?? '');
    $pw    = trim($b['password'] ?? '');

    if (strlen($uname) < 2 || strlen($uname) > 24 || !preg_match('/^[a-zA-Z0-9_]+$/', $uname)) {
        Api::error(422, 'Username must be 2-24 alphanumeric or underscore characters');
    }
    if (strlen($pw) < 4) {
        Api::error(422, 'Password must be at least 4 characters');
    }

    $db = Db::get();
    $st = $db->prepare('SELECT id FROM users WHERE username=?');
    $st->execute([$uname]);
    if ($st->fetch()) {
        Api::error(409, 'Username already taken');
    }

    $tok = Auth::makeToken();
    $hash = Auth::hashPassword($pw);
    $avatar = ['♠','♥','♦','♣','★','❀'][array_rand(['♠','♥','♦','♣','★','❀'])];

    $ins = $db->prepare('INSERT INTO users (username, password_hash, api_token, avatar, chips) VALUES (?,?,?,?,?)');
    $ins->execute([$uname, $hash, $tok, $avatar, CHIPS_START]);
    $userId = (int)$db->lastInsertId();

    $u = $db->query('SELECT * FROM users WHERE id=' . $userId)->fetch();
    Api::json(['token' => $tok, 'user' => Auth::publicUser($u)]);
}

function authLogin(): void
{
    $b = Api::body();
    $uname = trim($b['username'] ?? '');
    $pw    = $b['password'] ?? '';

    $db = Db::get();
    $st = $db->prepare('SELECT * FROM users WHERE username=?');
    $st->execute([$uname]);
    $u = $st->fetch();
    if (!$u || !password_verify($pw, $u['password_hash'])) {
        Api::error(401, 'Invalid username or password');
    }

    $tok = Auth::makeToken();
    $db->prepare('UPDATE users SET api_token=? WHERE id=?')->execute([$tok, (int)$u['id']]);

    Api::json(['token' => $tok, 'user' => Auth::publicUser(array_merge($u, ['api_token' => $tok]))]);
}

/* ---- Me -------------------------------------------------------------- */

function meProfile(): void
{
    $user = Auth::requireUser();
    Api::json(Auth::publicUser($user));
}

function meBuyin(): void
{
    $user = Auth::requireUser();
    $b = Api::body();
    $amount = (int)($b['amount'] ?? 0);
    if ($amount < 1) {
        Api::error(422, 'Amount must be at least 1');
    }
    $db = Db::get();
    $db->prepare('UPDATE users SET chips = chips + ? WHERE id=?')->execute([$amount, (int)$user['id']]);
    $u = $db->query('SELECT * FROM users WHERE id=' . (int)$user['id'])->fetch();
    Api::json(Auth::publicUser($u));
}

/* ---- Rooms ----------------------------------------------------------- */

function roomsList(): void
{
    $db = Db::get();
    $rows = $db->query(
        'SELECT r.*,
                (SELECT COUNT(*) FROM players p
                 JOIN game_sessions g ON g.id=p.session_id AND g.room_id=r.id
                 WHERE p.user_id IS NOT NULL AND p.is_bot=0) AS humans,
                (SELECT COUNT(*) FROM players p
                 JOIN game_sessions g ON g.id=p.session_id AND g.room_id=r.id) AS seated,
                (SELECT g.phase FROM game_sessions g WHERE g.room_id=r.id) AS phase
         FROM rooms r ORDER BY r.sort'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'small_blind' => (int)$r['small_blind'],
            'big_blind'   => (int)$r['big_blind'],
            'min_buyin'   => (int)$r['min_buyin'],
            'max_buyin'   => (int)$r['max_buyin'],
            'max_players' => (int)$r['max_players'],
            'humans'      => (int)($r['humans'] ?? 0),
            'seated'      => (int)($r['seated'] ?? 0),
            'phase'       => $r['phase'] ?? 'idle',
        ];
    }
    Api::json(['rooms' => $out]);
}

function roomsState(int $roomId): void
{
    $db = Db::get();
    $user = Auth::user();
    $userId = $user ? (int)$user['id'] : null;

    /* tick runs the server engine (bot play, timeouts, street deals) */
    $s = Game::sessionForRoom($db, $roomId);
    if ($s) {
        Game::tick($db, (int)$s['id']);
    }

    $state = Game::state($db, $roomId, $userId);

    /* append recent chat */
    if ($s) {
        $chat = $db->query(
            'SELECT username, message, created_at FROM chat_messages
             WHERE session_id=' . (int)$s['id'] . ' ORDER BY id DESC LIMIT 30'
        )->fetchAll();
        $state['chat'] = array_reverse($chat);
    } else {
        $state['chat'] = [];
    }

    Api::json($state);
}

function roomsJoin(int $roomId): void
{
    $user = Auth::requireUser();
    $b    = Api::body();
    $prefSeat = isset($b['seat']) ? (int)$b['seat'] : null;

    $db = Db::get();
    $db->beginTransaction();
    try {
        $s = Game::sessionForRoom($db, $roomId, true);
        if (!$s) {
            $db->exec('INSERT INTO game_sessions (room_id) VALUES (' . (int)$roomId . ')');
            $sid = (int)$db->lastInsertId();
            $s = Game::session($db, $sid, true);
        } else {
            $sid = (int)$s['id'];
        }
        $room  = Game::room($db, $roomId);
        if (!$room) {
            throw new Exception('Room not found');
        }
        $players = Game::players($db, $sid);

        /* check if already seated */
        foreach ($players as $p) {
            if ((int)$p['user_id'] === (int)$user['id']) {
                $db->rollBack();
                Game::tick($db, $sid);
                Api::json(Game::state($db, $roomId, (int)$user['id']));
            }
        }

        $max = (int)$room['max_players'];

        /* find seat */
        $seat = null;
        if ($prefSeat !== null) {
            if (isset($players[$prefSeat])) {
                if ((int)$players[$prefSeat]['user_id'] !== 0 && $players[$prefSeat]['user_id'] !== null) {
                    $db->rollBack();
                    Api::error(422, 'Seat is taken');
                }
                /* kick bot */
                $db->exec('DELETE FROM players WHERE session_id=' . $sid . ' AND seat=' . (int)$prefSeat);
                unset($players[$prefSeat]);
            }
            $seat = $prefSeat;
        } else {
            for ($i = 0; $i < $max; $i++) {
                if (!isset($players[$i])) {
                    $seat = $i;
                    break;
                }
            }
            if ($seat === null) {
                foreach ($players as $i => $p) {
                    if ($p['is_bot']) {
                        $db->exec('DELETE FROM players WHERE session_id=' . $sid . ' AND seat=' . (int)$i);
                        unset($players[$i]);
                        $seat = $i;
                        break;
                    }
                }
            }
        }
        if ($seat === null) {
            $db->rollBack();
            Api::error(422, 'Table is full');
        }

        $stack = min((int)$user['chips'], (int)$room['max_buyin']);
        if ($stack < 1) {
            $db->rollBack();
            Api::error(422, 'You need chips to play. Visit the lobby to add chips.');
        }

        $midHand = $s['phase'] !== 'idle';
        $ins = $db->prepare(
            'INSERT INTO players (session_id, user_id, is_bot, seat, stack, folded, connected, last_seen)
             VALUES (?, ?, 0, ?, ?, ?, 1, NOW())'
        );
        $ins->execute([$sid, (int)$user['id'], $seat, $stack, $midHand ? 1 : 0]);

        $db->prepare('UPDATE users SET chips = chips - ? WHERE id=?')->execute([$stack, (int)$user['id']]);

        $db->commit();
        Game::tick($db, $sid);
        Api::json(Game::state($db, $roomId, (int)$user['id']));
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function roomsLeave(int $roomId): void
{
    $user = Auth::requireUser();
    $db   = Db::get();
    $db->beginTransaction();
    try {
        $s = Game::sessionForRoom($db, $roomId, true);
        if (!$s) {
            $db->rollBack();
            Api::json(['ok' => true]);
        }
        $sid = (int)$s['id'];
        $st = $db->prepare('SELECT * FROM players WHERE session_id=? AND user_id=?');
        $st->execute([$sid, (int)$user['id']]);
        $p = $st->fetch();
        if (!$p) {
            $db->rollBack();
            Api::json(['ok' => true]);
        }
        $db->prepare('UPDATE users SET chips = chips + ? WHERE id=?')->execute([(int)$p['stack'], (int)$user['id']]);
        $db->exec('DELETE FROM players WHERE id=' . (int)$p['id']);
        $db->commit();
        Game::tick($db, $sid);
        Api::json(['ok' => true]);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function roomsAction(int $roomId): void
{
    $user = Auth::requireUser();
    $b    = Api::body();
    $action = strtolower(trim($b['action'] ?? ''));
    $amount = isset($b['amount']) ? (int)$b['amount'] : null;

    if (!in_array($action, ['fold','check','call','bet','raise','allin'], true)) {
        Api::error(422, 'Unknown action');
    }

    Game::playerAction(Db::get(), (int)$user['id'], $roomId, $action, $amount);
    /* playerAction already ticks; then returns state */
    Api::json(Game::state(Db::get(), $roomId, (int)$user['id']));
}

function roomsBuyin(int $roomId): void
{
    $user = Auth::requireUser();
    $b    = Api::body();
    $amount = (int)($b['amount'] ?? 0);
    if ($amount < 1) {
        Api::error(422, 'Amount must be at least 1');
    }
    $db   = Db::get();
    $db->beginTransaction();
    $s = Game::sessionForRoom($db, $roomId, true);
    if (!$s) {
        $db->rollBack();
        Api::error(404, 'Session not found');
    }
    $sid = (int)$s['id'];
    $st = $db->prepare('SELECT * FROM players WHERE session_id=? AND user_id=?');
    $st->execute([$sid, (int)$user['id']]);
    $p = $st->fetch();
    if (!$p) {
        $db->rollBack();
        Api::error(403, 'You are not seated');
    }
    $db->prepare('UPDATE players SET stack = stack + ? WHERE id=?')->execute([$amount, (int)$p['id']]);
    $db->commit();
    Game::tick($db, $sid);
    Api::json(Game::state($db, $roomId, (int)$user['id']));
}

function roomsChatPost(int $roomId): void
{
    $user = Auth::requireUser();
    $b    = Api::body();
    $msg  = trim($b['message'] ?? '');
    if ($msg === '' || mb_strlen($msg) > 200) {
        Api::error(422, 'Message must be 1-200 characters');
    }
    $db = Db::get();
    $s = Game::sessionForRoom($db, $roomId);
    if (!$s) {
        Api::json(['ok' => true]);
    }
    $ins = $db->prepare('INSERT INTO chat_messages (session_id, user_id, username, message) VALUES (?,?,?,?)');
    $ins->execute([(int)$s['id'], (int)$user['id'], $user['username'], $msg]);
    Api::json(['ok' => true]);
}

function roomsChatGet(int $roomId): void
{
    $db = Db::get();
    $s = Game::sessionForRoom($db, $roomId);
    if (!$s) {
        Api::json(['chat' => []]);
    }
    $chat = $db->query(
        'SELECT username, message, created_at FROM chat_messages
         WHERE session_id=' . (int)$s['id'] . ' ORDER BY id DESC LIMIT 30'
    )->fetchAll();
    Api::json(['chat' => array_reverse($chat)]);
}