<?php
declare(strict_types=1);

final class Api
{
    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(int $code, string $msg): never
    {
        self::json(['error' => $msg], $code);
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        return $data;
    }
}