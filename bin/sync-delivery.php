<?php

declare(strict_types=1);

// Delivery-state sync: polls the gateway for messages already handed off
// (`sent`) and moves them to `delivered` or terminal `failed`.
// Run via cron/Task Scheduler alongside bin/worker.php, e.g. every minute:
//   * * * * * C:\xampp\php\php.exe C:\xampp\htdocs\Projects\jelite_sms_api\bin\sync-delivery.php

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\Config;
use Jelite\Database;
use Jelite\Worker;

Config::load(dirname(__DIR__) . '/.env');

// Connect first: Database::pdo() loads Admin-UI settings (app_settings)
// as config overrides, so the gateway must be built after this line.
Database::pdo();

$checked = Worker::syncDeliveries(null, null, static function (string $line): void {
    echo $line, PHP_EOL;
});

if ($checked === -1) {
    fwrite(STDERR, "Gateway not configured (SMS_GATEWAY_URL/USERNAME/PASSWORD via Admin Settings or .env).\n");
    exit(1);
}

echo "{$checked} message(s) checked for delivery state.\n";
