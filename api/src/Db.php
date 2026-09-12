<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
            try {
                self::$pdo = self::connect($dsn);
            } catch (PDOException $e) {
                self::installDb();
                self::$pdo = self::connect($dsn);
            }
            self::$pdo->exec('USE `' . DB_NAME . '`');
        }
        return self::$pdo;
    }

    private static function connect(string $dsn): PDO
    {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }

    public static function installDb(): void
    {
        $sql = file_get_contents(__DIR__ . '/../schema.sql');
        if ($sql === false) {
            throw new RuntimeException('schema.sql not found');
        }
        $pdo = self::connect('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4');
        $pdo->exec($sql);
        $pdo = null;
    }

    public static function isInstalled(): bool
    {
        try {
            $pdo = self::connect('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4');
            $pdo->query('SELECT 1 FROM game_sessions LIMIT 1')->fetchAll();
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}