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

        // Admin UI overrides ride on top of env config once a connection exists.
        Config::loadDbOverrides(self::$pdo);

        return self::$pdo;
    }

    public static function serverPdo(): PDO
    {
        $dsn = sprintf('mysql:host=%s;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'));
        return new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Idempotent migrations for databases created before newer schema changes
     * (CREATE TABLE IF NOT EXISTS does not alter existing tables).
     */
    public static function migrate(PDO $db): void
    {
        self::migrateDeliveryStatus($db);
    }

    private static function migrateDeliveryStatus(PDO $db): void
    {
        $col = $db->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_messages' AND COLUMN_NAME = 'status'"
        );
        $col->execute();
        $type = (string) $col->fetchColumn();
        if ($type !== '' && !str_contains($type, 'delivered')) {
            $db->exec("ALTER TABLE sms_messages MODIFY status ENUM('queued','sending','sent','delivered','failed') NOT NULL DEFAULT 'queued'");
        }

        foreach ([
            'delivered_at' => 'ALTER TABLE sms_messages ADD COLUMN delivered_at DATETIME NULL DEFAULT NULL AFTER sent_at',
            'gateway_state' => 'ALTER TABLE sms_messages ADD COLUMN gateway_state VARCHAR(50) NULL DEFAULT NULL AFTER gateway_message_id',
        ] as $column => $ddl) {
            $exists = $db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_messages' AND COLUMN_NAME = " . $db->quote($column)
            )->fetchColumn();
            if ((int) $exists === 0) {
                $db->exec($ddl);
            }
        }
    }

    private static ?string $currentDb = null;
}
