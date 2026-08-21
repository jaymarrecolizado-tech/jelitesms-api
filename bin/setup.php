<?php

declare(strict_types=1);

// Creates the database for the current APP_ENV and applies database/schema.sql.

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\Config;
use Jelite\Database;

requireConfig();

$dbName = Config::dbName();

if (Config::get('DB_NAME') !== '') {
    // Managed hosts (e.g. Hostinger) create databases in their panel with a
    // forced prefix — the database must already exist.
    echo "Using existing database from DB_NAME: {$dbName}\n";
} else {
    try {
        Database::serverPdo()->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $dbName)
        ));
        echo "Database ready: {$dbName}\n";
    } catch (\Throwable $e) {
        // CREATE denied (managed hosting) → continue against the existing DB.
        echo "Notice: could not create database ({$e->getMessage()}) — continuing with existing `{$dbName}`.\n";
    }
}

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Cannot read database/schema.sql\n");
    exit(1);
}
Database::pdo()->exec($schema);
echo "Schema applied.\n";

Database::migrate(Database::pdo());
echo "Migrations checked.\n";


function requireConfig(): void
{
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) {
        fwrite(STDERR, "Missing .env — copy .env.example to .env first.\n");
        exit(1);
    }
    Jelite\Config::load($envFile);
}
