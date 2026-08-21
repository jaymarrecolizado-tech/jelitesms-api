<?php

declare(strict_types=1);

// Queue worker: drains queued SMS messages through the gateway.
// Run via cron, e.g. every minute:
//   * * * * * C:\xampp\php\php.exe C:\xampp\htdocs\Projects\jelite_sms_api\bin\worker.php

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\Config;
use Jelite\Database;
use Jelite\Worker;

Config::load(dirname(__DIR__) . '/.env');

// Connect first: Database::pdo() loads Admin-UI settings (app_settings)
// as config overrides, so the gateway must be built after this line.
Database::pdo();

$processed = Worker::drain(null, null, static function (string $line): void {
    echo $line, PHP_EOL;
});

if ($processed === -1) {
    fwrite(STDERR, "Gateway not configured (SMS_GATEWAY_URL/USERNAME/PASSWORD via Admin Settings or .env).\n");
    exit(1);
}

echo "{$processed} message(s) processed.\n";
