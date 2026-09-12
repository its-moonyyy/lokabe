<?php
declare(strict_types=1);

final class Cards
{
    public const RANKS      = ['2','3','4','5','6','7','8','9','T','J','Q','K','A'];
    public const SUITS      = ['s','h','d','c'];
    public const SUIT_CHARS = ['s' => '♠','h' => '♥','d' => '♦','c' => '♣'];
    public const SUIT_RED   = ['h' => true,'d' => true];

    public const RANK_VALUE = [
        '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8,
        '9' => 9, 'T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14,
    ];

    public const HAND_NAMES = [
        9 => 'Straight Flush', 8 => 'Four of a Kind', 7 => 'Full House',
        6 => 'Flush', 5 => 'Straight', 4 => 'Three of a Kind',
        3 => 'Two Pair', 2 => 'One Pair', 1 => 'High Card',
    ];

    public static function deck(): array
    {
        $deck = [];
        foreach (self::SUITS as $s) {
            foreach (self::RANKS as $r) {
                $deck[] = $r . $s;
            }
        }
        shuffle($deck);
        return $deck;
    }

    public static function parse(string $card): array
    {
        $rank = $card[0];
        $suit = $card[1];
        return ['r' => self::RANK_VALUE[$rank] ?? 0, 's' => $suit];
    }

    public static function rankLabel(string $card): string
    {
        $r = $card[0];
        return $r === 'T' ? '10' : $r;
    }

    public static function face(string $card): string
    {
        return self::rankLabel($card) . self::SUIT_CHARS[$card[1]];
    }

    /*
     * Evaluate the best 5-card hand from a list of card strings.
     * Returns a numeric score array [category, kicker, kicker, ...]
     * that can be compared lexicographically with compare().
     */
    public static function evaluate(array $cards): array
    {
        $rankCount = [];
        $suitRanks = [];
        foreach ($cards as $c) {
            $p = self::parse($c);
            $rankCount[$p['r']] = ($rankCount[$p['r']] ?? 0) + 1;
            $suitRanks[$p['s']][] = $p['r'];
        }

        $flushRanks = null;
        foreach ($suitRanks as $rs) {
            if (count($rs) >= 5) {
                $flushRanks = $rs;
                break;
            }
        }

        $straightTop = self::findStraight(array_keys($rankCount));

        if ($flushRanks !== null) {
            $sf = self::findStraight(array_keys(array_count_values($flushRanks)));
            if ($sf !== null) {
                return [$sf === 14 ? 9 : 9, $sf];
            }
        }

        $quads = self::byCount($rankCount, 4);
        if ($quads) {
            $k = self::byCount($rankCount, 1);
            return [8, $quads[0], $k[0]];
        }

        $trips = self::byCount($rankCount, 3);
        $pairs = self::byCount($rankCount, 2);
        if (count($trips) >= 2) {
            return [7, $trips[0], $trips[1]];
        }
        if ($trips && $pairs) {
            return [7, $trips[0], $pairs[0]];
        }

        if ($flushRanks !== null) {
            rsort($flushRanks);
            return array_merge([6], array_slice($flushRanks, 0, 5));
        }

        if ($straightTop !== null) {
            return [5, $straightTop];
        }

        if ($trips) {
            $k = self::byCount($rankCount, 1);
            return array_merge([4], [$trips[0]], array_slice($k, 0, 2));
        }

        if (count($pairs) >= 2) {
            $k = self::byCount($rankCount, 1);
            return array_merge([3], array_slice($pairs, 0, 2), [$k[0]]);
        }

        if ($pairs) {
            $k = self::byCount($rankCount, 1);
            return array_merge([2], [$pairs[0]], array_slice($k, 0, 3));
        }

        $single = array_keys($rankCount);
        rsort($single);
        return array_merge([1], array_slice($single, 0, 5));
    }

    private static function byCount(array $rankCount, int $n): array
    {
        $out = [];
        foreach ($rankCount as $rank => $count) {
            if ($count === $n) {
                $out[] = $rank;
            }
        }
        rsort($out);
        return $out;
    }

    private static function findStraight(array $ranks): ?int
    {
        $has = [];
        foreach ($ranks as $r) {
            $has[$r] = true;
        }
        if (isset($has[14], $has[5], $has[4], $has[3], $has[2])) {
            return 5; // wheel: A-2-3-4-5
        }
        for ($top = 14; $top >= 5; $top--) {
            $ok = true;
            for ($i = 0; $i < 5; $i++) {
                if (!isset($has[$top - $i])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $top;
            }
        }
        return null;
    }

    public static function compare(array $a, array $b): int
    {
        foreach ($a as $i => $v) {
            if ($v !== $b[$i] ?? null) {
                return $v - ($b[$i] ?? 0);
            }
        }
        return 0;
    }

    public static function handLabel(array $score): string
    {
        $cat = $score[0];
        $name = self::HAND_NAMES[$cat] ?? 'Hand';
        if ($cat === 9)    { return $score[1] === 14 ? 'Royal Flush' : $name . ' · ' . self::rankName($score[1]) . ' high'; }
        if ($cat === 8)    { return $name . ' · ' . self::rankName($score[1]) . 's'; }
        if ($cat === 7)    { return $name . ' · ' . self::rankName($score[1]) . 's over ' . self::rankName($score[2]) . 's'; }
        if ($cat === 6)    { return $name . ' · ' . self::rankNameL($score, 5); }
        if ($cat === 5)    { return $name . ' · ' . self::rankName($score[1]) . ' high'; }
        if ($cat === 4)    { return $name . ' · ' . self::rankName($score[1]) . 's'; }
        if ($cat === 3)    { return $name . ' · ' . self::rankName($score[1]) . 's and ' . self::rankName($score[2]) . 's'; }
        if ($cat === 2)    { return $name . ' · ' . self::rankName($score[1]) . 's'; }
        return $name . ' · ' . self::rankName($score[1]);
    }

    private static function rankNameL(array $score, int $n): string
    {
        $parts = [];
        for ($i = 1; $i <= $n; $i++) {
            $parts[] = self::rankName($score[$i]);
        }
        return implode(' ', $parts);
    }

    public static function rankName(int $rank): string
    {
        $map = [2=>'2',3=>'3',4=>'4',5=>'5',6=>'6',7=>'7',8=>'8',9=>'9',10=>'10',11=>'Jack',12=>'Queen',13=>'King',14=>'Ace'];
        return $map[$rank] ?? (string)$rank;
    }
}