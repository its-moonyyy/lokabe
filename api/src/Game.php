<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';

final class Game
{
    private const MAX_STEPS = 100;
    private const BOT_NAMES = [];

    /* ------------------------------------------------------------------ */
    /* Loaders                                                             */
    /* ------------------------------------------------------------------ */

    public static function session(PDO $db, int $id, bool $lock = false): ?array
    {
        $row = $db->query('SELECT * FROM game_sessions WHERE id=' . (int)$id . ($lock ? ' FOR UPDATE' : ''))->fetch();
        return $row ?: null;
    }

    public static function sessionForRoom(PDO $db, int $roomId, bool $lock = false): ?array
    {
        $row = $db->query('SELECT * FROM game_sessions WHERE room_id=' . (int)$roomId . ($lock ? ' FOR UPDATE' : ''))->fetch();
        return $row ?: null;
    }

    public static function room(PDO $db, int $id): ?array
    {
        return $db->query('SELECT * FROM rooms WHERE id=' . (int)$id)->fetch() ?: null;
    }

    public static function players(PDO $db, int $sessionId): array
    {
        $rows = $db->query(
            'SELECT p.*, COALESCE(u.username, "") AS username, COALESCE(u.avatar, "♠") AS avatar
             FROM players p LEFT JOIN users u ON u.id = p.user_id
             WHERE p.session_id=' . (int)$sessionId . ' ORDER BY p.seat'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['seat']] = $r;
        }
        return $out;
    }

    public static function saveSession(PDO $db, array $s): void
    {
        $st = $db->prepare(
            'UPDATE game_sessions SET phase=?, hand_id=?, dealer_seat=?, turn_seat=?, current_bet=?, min_raise=?,
             to_act=?, pot=?, community_cards=?, deck=?, turn_started_at=?, showdown_at=? WHERE id=?'
        );
        $st->execute([
            $s['phase'],
            (int)$s['hand_id'],
            (int)$s['dealer_seat'],
            $s['turn_seat'] !== null ? (int)$s['turn_seat'] : null,
            (int)$s['current_bet'],
            (int)$s['min_raise'],
            (int)$s['to_act'],
            (int)$s['pot'],
            $s['community_cards'],
            $s['deck'],
            $s['turn_started_at'],
            $s['showdown_at'],
            (int)$s['id'],
        ]);
    }

    public static function savePlayers(PDO $db, array $players): void
    {
        $st = $db->prepare(
            'UPDATE players SET stack=?, street_bet=?, total_bet=?, cards=?, folded=?, all_in=?,
             has_acted=?, result=?, connected=?, last_seen=? WHERE id=?'
        );
        foreach ($players as $p) {
            $st->execute([
                (int)$p['stack'],
                (int)$p['street_bet'],
                (int)$p['total_bet'],
                $p['cards'],
                (int)$p['folded'],
                (int)$p['all_in'],
                (int)$p['has_acted'],
                $p['result'],
                (int)$p['connected'],
                $p['last_seen'],
                (int)$p['id'],
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Tick loop — advances the server-authoritative state machine         */
    /* ------------------------------------------------------------------ */

    public static function tick(PDO $db, int $sessionId): array
    {
        $db->beginTransaction();
        $s = self::session($db, $sessionId, true);
        if (!$s) {
            $db->rollBack();
            Api::error(404, 'Session not found');
        }
        $room = self::room($db, (int)$s['room_id']);
        if (!$room) {
            $db->rollBack();
            Api::error(500, 'Room not found');
        }
        $players = self::players($db, $sessionId);

        self::fillBots($db, $s, $room, $players);

        $steps = 0;
        while ($steps++ < self::MAX_STEPS) {
            if (!self::step($db, $s, $room, $players)) {
                break;
            }
        }

        self::saveSession($db, $s);
        self::savePlayers($db, $players);
        $db->commit();

        return $s;
    }

    /**
     * Execute exactly one atomic transition. Returns true if something
     * happened (so the loop should keep going), false if the game is now
     * waiting on a human player.
     */
    private static function step(PDO $db, array &$s, array &$room, array &$players): bool
    {
        $phase = $s['phase'];

        if ($phase === 'idle') {
            self::fillBots($db, $s, $room, $players);
            $humans = 0;
            foreach ($players as $p) {
                if ($p['user_id']) {
                    $humans++;
                }
            }
            if ($humans >= 1 && count($players) >= 2) {
                self::startHand($db, $s, $room, $players);
                return true;
            }
            return false;
        }

        if ($phase === 'showdown') {
            $at = $s['showdown_at'] ? strtotime($s['showdown_at']) : 0;
            if ($at && (time() - $at) >= SHOWDOWN_PAUSE) {
                foreach ($players as $i => $p) {
                    $players[$i]['result'] = null;
                    $players[$i]['cards']  = null;
                }
                $s['phase'] = 'idle';
                $s['turn_seat'] = null;
                return true;
            }
            return false;
        }

        /* Betting streets: preflop, flop, turn, river */
        $unfolded = [];
        foreach ($players as $p) {
            if (!(int)$p['folded']) {
                $unfolded[] = $p;
            }
        }
        if (count($unfolded) <= 1) {
            self::payout($db, $s, $players, count($unfolded) === 1 ? $unfolded[0]['seat'] : null);
            return true;
        }

        if ((int)$s['to_act'] <= 0) {
            if ($phase === 'river') {
                self::payout($db, $s, $players, null);
                return true;
            }
            self::nextStreet($db, $s, $room, $players);
            return true;
        }

        $ts = (int)$s['turn_seat'];
        $p = $players[$ts] ?? null;
        if (!$p || (int)$p['folded'] || (int)$p['all_in']) {
            $s['turn_seat'] = self::nextActive($players, $ts, (int)$room['max_players']);
            $s['turn_started_at'] = date('Y-m-d H:i:s');
            return true;
        }

        if ((int)$p['is_bot']) {
            $dec = self::botDecide($s, $p, $players);
            self::applyAction($db, $s, $room, $players, $players[$ts], $dec['action'], $dec['target'], false);
            return true;
        }

        /* Human turn — auto-fold after SITEOUT seconds of inactivity */
        $last = $s['turn_started_at'];
        if ($last && (time() - strtotime($last)) > SITEOUT) {
            self::applyAction($db, $s, $room, $players, $players[$ts], 'fold', null, false);
            return true;
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Hand lifecycle                                                      */
    /* ------------------------------------------------------------------ */

    private static function startHand(PDO $db, array &$s, array &$room, array &$players): void
    {
        $max = (int)$room['max_players'];
        $seats = self::occupiedSeats($players);

        $s['hand_id'] = (int)$s['hand_id'] + 1;
        $s['pot'] = 0;
        $s['community_cards'] = '[]';
        $s['turn_seat'] = null;
        $s['current_bet'] = 0;
        $s['min_raise'] = (int)$room['big_blind'];
        $s['to_act'] = 0;

        $dealer = $s['hand_id'] === 1
            ? $seats[0]
            : self::nextSeat($players, (int)$s['dealer_seat'], $max);

        $sbAmt = (int)$room['small_blind'];
        $bbAmt = (int)$room['big_blind'];
        if (count($seats) === 2) {
            $sbSeat = $dealer;                                    /* heads-up: dealer posts SB   */
            $bbSeat = self::nextSeat($players, $sbSeat, $max);
            $sbAmt = $bbAmt;
        } else {
            $sbSeat = self::nextSeat($players, $dealer, $max);
            $bbSeat = self::nextSeat($players, $sbSeat, $max);
        }

        $deck = Cards::deck();
        foreach ($players as $i => $p) {
            $players[$i]['folded']     = 0;
            $players[$i]['all_in']     = 0;
            $players[$i]['street_bet'] = 0;
            $players[$i]['total_bet']  = 0;
            $players[$i]['has_acted']  = 0;
            $players[$i]['result']     = null;
            $players[$i]['cards']      = json_encode([array_pop($deck), array_pop($deck)]);

            if ((int)$players[$i]['stack'] < (int)$room['min_buyin'] && $players[$i]['is_bot']) {
                $players[$i]['stack'] = mt_rand((int)$room['min_buyin'], (int)$room['max_buyin']);
            }
        }

        $s['dealer_seat'] = $dealer;
        self::postBlind($s, $players[$sbSeat], $sbAmt);
        self::postBlind($s, $players[$bbSeat], $bbAmt);

        $s['current_bet'] = (int)$players[$bbSeat]['street_bet'];
        $s['min_raise']   = $bbAmt;

        $active = [];
        foreach ($players as $p) {
            if (!(int)$p['folded'] && !(int)$p['all_in']) {
                $active[] = $p['seat'];
            }
        }
        $s['to_act'] = count($active);
        $s['phase'] = 'preflop';
        $s['deck'] = json_encode($deck);
        $s['turn_seat'] = self::nextActive($players, $bbSeat, $max);
        $s['turn_started_at'] = date('Y-m-d H:i:s');
        $s['showdown_at'] = null;
    }

    private static function postBlind(array &$s, array &$p, int $amt): void
    {
        $paid = min($amt, (int)$p['stack']);
        $p['stack']      = (int)$p['stack'] - $paid;
        $p['street_bet'] = (int)$p['street_bet'] + $paid;
        $p['total_bet']  = (int)$p['total_bet'] + $paid;
        $p['has_acted']  = 1;
        $s['pot']        = (int)$s['pot'] + $paid;
        if ((int)$p['stack'] === 0) {
            $p['all_in'] = 1;
        }
    }

    private static function nextStreet(PDO $db, array &$s, array &$room, array &$players): void
    {
        $phase = $s['phase'];
        $community = json_decode($s['community_cards'], true) ?: [];
        $deck = json_decode($s['deck'], true) ?: [];

        if ($phase === 'preflop') {
            $community = [array_pop($deck), array_pop($deck), array_pop($deck)];
        } elseif ($phase === 'flop') {
            $community[] = array_pop($deck);
        } elseif ($phase === 'turn') {
            $community[] = array_pop($deck);
        }
        $s['deck'] = json_encode($deck);
        $s['community_cards'] = json_encode($community);

        $s['phase'] = $phase === 'preflop' ? 'flop' : ($phase === 'flop' ? 'turn' : 'river');

        foreach ($players as $i => $p) {
            if (!(int)$p['folded']) {
                $players[$i]['street_bet'] = 0;
            }
            $players[$i]['has_acted'] = 0;
        }

        $s['current_bet'] = 0;
        $s['min_raise'] = (int)$room['big_blind'];

        $active = [];
        foreach ($players as $p) {
            if (!(int)$p['folded'] && !(int)$p['all_in']) {
                $active[] = $p['seat'];
            }
        }
        $s['to_act'] = count($active);
        $s['turn_seat'] = self::nextActive($players, (int)$s['dealer_seat'], (int)$room['max_players']);
        $s['turn_started_at'] = date('Y-m-d H:i:s');
    }

    private static function nextActive(array $players, int $fromSeat, int $max): int
    {
        $guard = count($players) + 1;
        $seat = $fromSeat;
        while ($guard-- > 0) {
            $seat = self::nextSeat($players, $seat, $max);
            $p = $players[$seat] ?? null;
            if ($p && !(int)$p['folded'] && !(int)$p['all_in']) {
                return $seat;
            }
        }
        return $fromSeat;
    }

    private static function nextSeat(array $players, int $fromSeat, int $max): int
    {
        $seats = self::occupiedSeats($players);
        if (!$seats) {
            return 0;
        }
        foreach ($seats as $s) {
            if ($s > $fromSeat) {
                return $s;
            }
        }
        return $seats[0];
    }

    private static function occupiedSeats(array $players): array
    {
        $s = array_keys($players);
        sort($s, SORT_NUMERIC);
        return $s;
    }

    /* ------------------------------------------------------------------ */
    /* Actions                                                             */
    /* ------------------------------------------------------------------ */

    public static function playerAction(PDO $db, int $userId, int $roomId, string $action, ?int $target): void
    {
        $db->beginTransaction();
        $s = self::sessionForRoom($db, $roomId, true);
        if (!$s) {
            $db->rollBack();
            Api::error(404, 'No game running in this room');
        }
        $players = self::players($db, (int)$s['id']);
        $room = self::room($db, (int)$s['room_id']);

        $seat = -1;
        foreach ($players as $p) {
            if ((int)$p['user_id'] === $userId) {
                $seat = (int)$p['seat'];
                break;
            }
        }
        if ($seat < 0) {
            $db->rollBack();
            Api::error(403, 'You are not seated at this table');
        }
        $p = &$players[$seat];

        if (!in_array($s['phase'], ['preflop','flop','turn','river'], true)) {
            $db->rollBack();
            Api::error(409, 'No action available right now');
        }
        if ((int)$s['turn_seat'] !== $seat) {
            $db->rollBack();
            Api::error(409, 'Not your turn');
        }
        if ((int)$p['folded'] || (int)$p['all_in']) {
            $db->rollBack();
            Api::error(409, 'You cannot act');
        }

        $toCall = (int)$s['current_bet'] - (int)$p['street_bet'];
        $stack  = (int)$p['stack'];
        $bb     = (int)$room['big_blind'];
        $minRaise = (int)$s['min_raise'];

        switch ($action) {
            case 'fold':
                $target = null;
                break;
            case 'check':
                if ($toCall > 0) {
                    $db->rollBack();
                    Api::error(422, 'You must call ' . $toCall . ' or fold');
                }
                break;
            case 'call':
                break;
            case 'allin':
                break;
            case 'bet':
                if ($toCall > 0) {
                    $db->rollBack();
                    Api::error(422, 'Use raise — a bet is already on the table');
                }
                if ($target === null || $target < $bb) {
                    $db->rollBack();
                    Api::error(422, 'Minimum bet is the big blind (' . $bb . ')');
                }
                break;
            case 'raise':
                if ($toCall <= 0) {
                    $db->rollBack();
                    Api::error(422, 'Use bet — no bet to raise against');
                }
                if ($target === null || ($target - (int)$p['street_bet']) < 0) {
                    $db->rollBack();
                    Api::error(422, 'Invalid raise amount');
                }
                $allInTarget = (int)$p['street_bet'] + $stack;
                if ($target < (int)$s['current_bet'] + $minRaise && $target < $allInTarget) {
                    $db->rollBack();
                    Api::error(422, 'Minimum raise to ' . ((int)$s['current_bet'] + $minRaise));
                }
                break;
            default:
                $db->rollBack();
                Api::error(422, 'Unknown action');
        }

        self::applyAction($db, $s, $room, $players, $p, $action, $target, true);
        self::savePlayers($db, $players);
        self::saveSession($db, $s);
        self::syncChips($db, $players);
        $db->commit();

        self::tick($db, (int)$s['id']);
    }

    private static function applyAction(PDO $db, array &$s, array &$room, array &$players, array &$p, string $action, ?int $target, bool $fromUser): void
    {
        $toCall = (int)$s['current_bet'] - (int)$p['street_bet'];
        $stack  = (int)$p['stack'];

        switch ($action) {
            case 'fold':
                $p['folded'] = 1;
                $p['has_acted'] = 1;
                $s['to_act'] = max(0, (int)$s['to_act'] - 1);
                break;

            case 'check':
                $p['has_acted'] = 1;
                $s['to_act'] = max(0, (int)$s['to_act'] - 1);
                break;

            case 'call':
                $amt = min(max($toCall, 0), $stack);
                if ($amt > 0) {
                    $p['stack'] = $stack - $amt;
                    $p['street_bet'] += $amt;
                    $p['total_bet'] += $amt;
                    $s['pot'] += $amt;
                }
                if ((int)$p['stack'] === 0) {
                    $p['all_in'] = 1;
                }
                $p['has_acted'] = 1;
                $s['to_act'] = max(0, (int)$s['to_act'] - 1);
                break;

            case 'bet':
            case 'raise':
            case 'allin':
                if ($action === 'allin') {
                    $target = (int)$p['street_bet'] + $stack;
                } else {
                    $target = min((int)$target, (int)$p['street_bet'] + $stack);
                }
                $add = max(0, $target - (int)$p['street_bet']);
                if ($add > $stack) {
                    $add = $stack;
                }
                $p['stack'] = $stack - $add;
                $p['street_bet'] += $add;
                $p['total_bet'] += $add;
                $s['pot'] += $add;
                if ((int)$p['stack'] === 0) {
                    $p['all_in'] = 1;
                }
                $p['has_acted'] = 1;

                $raisedTo = (int)$p['street_bet'];
                if ($raisedTo > (int)$s['current_bet']) {
                    $s['current_bet'] = $raisedTo;
                    $active = 0;
                    foreach ($players as $q) {
                        if (!(int)$q['folded'] && !(int)$q['all_in']) {
                            $active++;
                        }
                    }
                    $s['to_act'] = max(0, $active - 1);
                } else {
                    $s['to_act'] = max(0, (int)$s['to_act'] - 1);
                }
                break;
        }

        if ((int)$s['to_act'] > 0) {
            $s['turn_seat'] = self::nextActive($players, (int)$p['seat'], (int)$room['max_players']);
            $s['turn_started_at'] = date('Y-m-d H:i:s');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Payout / showdown                                                   */
    /* ------------------------------------------------------------------ */

    private static function payout(PDO $db, array &$s, array &$players, ?int $soleWinner): void
    {
        $community = json_decode($s['community_cards'], true) ?: [];

        if ($soleWinner !== null) {
            $amt = (int)$s['pot'];
            $players[$soleWinner]['stack'] = (int)$players[$soleWinner]['stack'] + $amt;
            $players[$soleWinner]['result'] = json_encode(['won' => $amt, 'label' => 'won the pot unopposed']);
        } else {
            $works = [];
            foreach ($players as $p) {
                if (!(int)$p['folded']) {
                    $works[(int)$p['seat']] = (int)$p['total_bet'];
                } else {
                    $works[(int)$p['seat']] = 0;
                }
            }

            $pots = [];
            while (true) {
                $pos = [];
                foreach ($works as $seat => $v) {
                    if ($v > 0) {
                        $pos[$seat] = $v;
                    }
                }
                if (!$pos) {
                    break;
                }
                $low = min($pos);
                $amt = 0;
                foreach ($pos as $v) {
                    $amt += $low;
                }
                $pots[] = ['amt' => $amt, 'seats' => array_keys($pos)];
                foreach ($pos as $seat => $v) {
                    $works[$seat] -= $low;
                }
            }

            $scores = [];
            foreach ($players as $p) {
                if ((int)$p['folded']) {
                    continue;
                }
                $hand = array_merge($community, json_decode($p['cards'], true) ?: []);
                $score = Cards::evaluate($hand);
                $scores[(int)$p['seat']] = [$score, Cards::handLabel($score)];
            }

            foreach ($pots as $pot) {
                if (!$pot['seats']) {
                    continue;
                }
                $best = null;
                $winners = [];
                foreach ($pot['seats'] as $seat) {
                    if (!isset($scores[$seat])) {
                        continue;
                    }
                    if ($best === null) {
                        $best = $scores[$seat][0];
                        $winners = [$seat];
                    } else {
                        $cmp = Cards::compare($scores[$seat][0], $best);
                        if ($cmp > 0) {
                            $best = $scores[$seat][0];
                            $winners = [$seat];
                        } elseif ($cmp === 0) {
                            $winners[] = $seat;
                        }
                    }
                }
                if (!$winners) {
                    continue;
                }
$share = intdiv($pot['amt'], count($winners));
                $rem = $pot['amt'] % count($winners);
                foreach ($winners as $k => $seat) {
                    $add = $share + ($rem > 0 && $k === 0 ? $rem : 0);
                    $players[$seat]['stack'] = (int)$players[$seat]['stack'] + $add;
                    $cur = $players[$seat]['result'] ? json_decode($players[$seat]['result'], true) : ['won' => 0, 'label' => ''];
                    $cur['won'] = (int)$cur['won'] + $add;
                    $cur['label'] = $scores[$seat][1];
                    $cur['pots'] = ($cur['pots'] ?? 0) + 1;
                    $players[$seat]['result'] = json_encode($cur);
                }
        }
        }

        $s['phase'] = 'showdown';
        $s['showdown_at'] = date('Y-m-d H:i:s');
        $s['turn_seat'] = null;

        self::syncChips($db, $players);
    }

    private static function syncChips(PDO $db, array $players): void
    {
        $st = $db->prepare('UPDATE users SET chips=? WHERE id=?');
        foreach ($players as $p) {
            if ($p['user_id']) {
                $st->execute([(int)$p['stack'], (int)$p['user_id']]);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Bots                                                                */
    /* ------------------------------------------------------------------ */

    private static function fillBots(PDO $db, array $s, array $room, array &$players): void
    {
        if ($s['phase'] !== 'idle' && $s['phase'] !== 'showdown') {
            return;
        }
        $humans = 0;
        $botCount = 0;
        foreach ($players as $p) {
            if ($p['user_id']) {
                $humans++;
            } else {
                $botCount++;
            }
        }
        if ($humans === 0) {
            return;
        }

        $max = (int)$room['max_players'];
        $desired = min($max, max(3, $humans + 1));
        $need = $desired - count($players);
        if ($need <= 0) {
            return;
        }

        $used = [];
        foreach ($players as $p) {
            $used[(int)$p['seat']] = true;
        }
        $free = [];
        for ($seat = 0; $seat < $max; $seat++) {
            if (!isset($used[$seat])) {
                $free[] = $seat;
            }
        }
        if (!$free) {
            return;
        }

        $exclude = [];
        foreach ($players as $p) {
            if ($p['user_id']) {
                $exclude[] = (int)$p['user_id'];
            }
        }
        $q = 'SELECT id FROM users WHERE is_bot=1';
        if ($exclude) {
            $q .= ' AND id NOT IN (' . implode(',', $exclude) . ')';
        }
        $q .= ' ORDER BY RAND() LIMIT ' . $need;
        $bots = $db->query($q)->fetchAll();
        $levels = ['easy','medium','medium','hard'];
        $st = $db->prepare(
            'INSERT INTO players (session_id, user_id, is_bot, bot_level, seat, stack, connected, last_seen)
             VALUES (?,?,?,?,?,?,1,NOW())'
        );
        $minBuy = (int)$room['min_buyin'];
        $maxBuy = (int)$room['max_buyin'];
        foreach ($bots as $i => $bot) {
            if (!isset($free[$i])) {
                break;
            }
            $stack = mt_rand($minBuy, min($maxBuy, (int)max($minBuy, ($maxBuy + $minBuy) / 2 + mt_rand(-2, 2))));
            $st->execute([(int)$s['id'], (int)$bot['id'], 1, $levels[array_rand($levels)], $free[$i], $stack]);
        }
        $players = self::players($db, (int)$s['id']);

        foreach ($players as $i => $p) {
            if (!$p['user_id'] && $s['phase'] === 'showdown') {
                /* freshly added mid-showdown: wait for the next hand */
                $players[$i]['folded'] = 1;
            }
        }
    }

    private static function botDecide(array $s, array $p, array $players): array
    {
        $level = $p['bot_level'] ?: 'medium';
        $hole  = json_decode($p['cards'], true) ?: [];
        $board = json_decode($s['community_cards'], true) ?: [];

        $strength = self::strength($hole, $board, $s['phase']);
        $toCall = (int)$s['current_bet'] - (int)$p['street_bet'];
        $stack  = (int)$p['stack'];
        $pot    = (int)$s['pot'];
        $bb     = $s['min_raise'] ?: 10;
        $r = mt_rand(0, 1000) / 1000;

        $aggro = ['easy' => 0.06, 'medium' => 0.16, 'hard' => 0.34][$level];
        $loose = ['easy' => 0.8,  'medium' => 0.6,  'hard' => 0.45][$level];
        $bluff = ['easy' => 0.02, 'medium' => 0.06, 'hard' => 0.12][$level];

        if ($toCall <= 0) {
            /* nothing to call — check or open with a probe bet */
            $openStrength = 0.60 - $aggro * 0.9;
            if ($strength > $openStrength || $r < $bluff) {
                $bet = max($bb, (int)(($pot ?: $bb) * (0.4 + $r * 0.9)));
                return self::raiseTarget($p, $bet);
            }
            return ['action' => 'check', 'target' => null];
        }

        $cost = min($toCall, $stack);
        $potOdds = $cost / max(1, $pot + $cost);

        if ($strength > 0.92 - $aggro || ($r < 0.02 && $stack > $toCall)) {
            /* monster — shove or big raise */
            return ['action' => 'allin', 'target' => null];
        }
        if ($strength > 0.78 - $aggro * 0.5) {
            /* strong — apply pressure */
            $raiseTo = (int)$s['current_bet'] + max($bb, (int)($s['current_bet'] * 1.6));
            return self::raiseTarget($p, $raiseTo);
        }
        if ($strength > $potOdds + 0.08 - $loose * 0.3) {
            /* profitable call */
            return ['action' => 'call', 'target' => null];
        }
        if ($r < $bluff && $stack > $toCall * 2) {
            return self::raiseTarget($p, (int)$s['current_bet'] + $bb * 2);
        }
        return ['action' => 'fold', 'target' => null];
    }

    private static function raiseTarget(array $p, int $raiseTo): array
    {
        $maxTarget = (int)$p['street_bet'] + (int)$p['stack'];
        $target = min($raiseTo, $maxTarget);
        if ($target >= $maxTarget) {
            return ['action' => 'allin', 'target' => null];
        }
        return ['action' => 'bet', 'target' => $target];
    }

    private static function strength(array $hole, array $board, string $phase): float
    {
        if (empty($board)) {
            return self::preflopStrength($hole);
        }
        $cards = array_merge($board, $hole);
        $score = Cards::evaluate($cards);
        $cat = $score[0];
        $rough = 0.36 + $cat * 0.07 + ($score[1] ?? 2) * 0.002;
        $noise = mt_rand(-5, 5) / 200;
        return min(1.0, max(0.02, $rough + $noise));
    }

    private static function preflopStrength(array $hole): float
    {
        if (count($hole) < 2) {
            return 0.2;
        }
        $a = Cards::RANK_VALUE[$hole[0][0]] ?? 2;
        $b = Cards::RANK_VALUE[$hole[1][0]] ?? 2;
        $suited = $hole[0][1] === $hole[1][1];
        $hi = max($a, $b);
        $lo = min($a, $b);

        if ($a === $b) {
            $base = 0.55 + ($a / 30);
            return min(0.98, $base + mt_rand(-2, 2) / 100);
        }
        $base = 0.08 + (($hi - 2) / 13) * 0.5;
        $base += $suited ? 0.09 : 0;
        $gap = $hi - $lo;
        if ($gap === 1) {
            $base += 0.08;
        } elseif ($gap === 2) {
            $base += 0.03;
        }
        if ($suited && $gap <= 1) {
            $base += 0.05;
        }
        return min(0.94, max(0.05, $base));
    }

    /* ------------------------------------------------------------------ */
    /* State serialisation                                                 */
    /* ------------------------------------------------------------------ */

    public static function state(PDO $db, int $roomId, ?int $userId): array
    {
        $s = self::sessionForRoom($db, $roomId);
        $room = self::room($db, $roomId);
        if (!$room) {
            Api::error(404, 'Room not found');
        }

        $mePlayer = null;
        $playersOut = [];
        $meSeat = null;
        $acting = false;

        if ($s) {
            $players = self::players($db, (int)$s['id']);
            $reveal = $s['phase'] === 'showdown';

            foreach ($players as $p) {
                $mine = $p['user_id'] && $userId && (int)$p['user_id'] === (int)$userId;
                if ($mine) {
                    $mePlayer = $p;
                    $meSeat = (int)$p['seat'];
                }
                $cards = json_decode((string)$p['cards'], true);
                $show = $mine || ($reveal && !(int)$p['folded']);
                $isActing = ($mine && in_array($s['phase'], ['preflop','flop','turn','river'], true)
                    && (int)$s['turn_seat'] === (int)$p['seat'] && !(int)$p['folded'] && !(int)$p['all_in']);

                $playersOut[] = [
                    'id'         => (int)$p['id'],
                    'seat'       => (int)$p['seat'],
                    'user_id'    => $p['user_id'] ? (int)$p['user_id'] : null,
                    'username'   => (string)$p['username'],
                    'avatar'     => (string)($p['avatar'] ?: '♠'),
                    'is_bot'     => (int)$p['is_bot'],
                    'bot_level'  => $p['bot_level'],
                    'stack'      => (int)$p['stack'],
                    'street_bet' => (int)$p['street_bet'],
                    'total_bet'  => (int)$p['total_bet'],
                    'folded'     => (int)$p['folded'],
                    'all_in'     => (int)$p['all_in'],
                    'cards'      => $show ? ($cards ?: []) : [],
                    'result'     => $p['result'] ? json_decode($p['result'], true) : null,
                    'is_me'      => $mine,
                    'acting'     => $isActing,
                ];
            }

            if ($acting) {
                $acting = true;
            } else {
                foreach ($playersOut as $po) {
                    if ($po['acting']) {
                        $acting = true;
                    }
                }
            }
        }

        $countdown = null;
        if ($acting && $s && $s['turn_started_at']) {
            $countdown = max(0, SITEOUT - (time() - strtotime($s['turn_started_at'])));
        }

        return [
            'room'    => [
                'id'          => (int)$room['id'],
                'name'        => $room['name'],
                'small_blind' => (int)$room['small_blind'],
                'big_blind'   => (int)$room['big_blind'],
                'min_buyin'   => (int)$room['min_buyin'],
                'max_buyin'   => (int)$room['max_buyin'],
                'max_players' => (int)$room['max_players'],
            ],
            'game'    => $s ? [
                'id'             => (int)$s['id'],
                'hand_id'        => (int)$s['hand_id'],
                'phase'          => $s['phase'],
                'dealer_seat'    => (int)$s['dealer_seat'],
                'turn_seat'      => $s['turn_seat'] !== null ? (int)$s['turn_seat'] : null,
                'current_bet'    => (int)$s['current_bet'],
                'min_raise'      => (int)$s['min_raise'],
                'pot'            => (int)$s['pot'],
                'community'      => json_decode((string)$s['community_cards'], true) ?: [],
                'showdown_at'    => $s['showdown_at'],
                'countdown'      => $countdown,
            ] : null,
            'players' => $playersOut,
            'me_seat' => $meSeat,
        ];
    }
}