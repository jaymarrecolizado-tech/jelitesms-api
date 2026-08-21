<?php

namespace Jelite;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(?string $dbName = null): PDO
    {
        $key = $dbName ?? Config::dbName();
        if (self::$pdo !== null && self::$currentDb === $key) {
            return self::$pdo;
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), $key);
        self::$pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$currentDb = $key;
        return self::$pdo;
    }

    public static function serverPdo(): PDO
    {
        $dsn = sprintf('mysql:host=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'));
        return new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static ?string $currentDb = null;
}
