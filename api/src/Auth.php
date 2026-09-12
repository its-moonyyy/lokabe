<?php
declare(strict_types=1);

final class Auth
{
    public static function tokenFromRequest(): ?string
    {
        $h = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        if ($h === '') {
            $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }
        $h = trim($h);
        if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
            $h = trim($m[1]);
        }
        return $h !== '' ? $h : null;
    }

    public static function user(): ?array
    {
        $tok = self::tokenFromRequest();
        if (!$tok) {
            return null;
        }
        $st = Db::get()->prepare('SELECT * FROM users WHERE api_token=?');
        $st->execute([$tok]);
        $u = $st->fetch();
        return $u ?: null;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            Api::error(401, 'Authentication required');
        }
        return $u;
    }

    public static function makeToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function publicUser(array $u): array
    {
        return [
            'id'        => (int)$u['id'],
            'username'  => $u['username'],
            'avatar'    => $u['avatar'] ?: '♠',
            'chips'     => (int)$u['chips'],
            'wins'      => (int)$u['wins'],
            'hands'     => (int)$u['hands_played'],
            'is_bot'    => (int)$u['is_bot'],
        ];
    }
}