<?php

declare(strict_types=1);

// Queue worker: drains queued SMS messages through the gateway.
// Run via cron, e.g. every minute:
//   * * * * * C:\xampp\php\php.exe C:\xampp\htdocs\Projects\jelite_sms_api\bin\worker.php

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\Config;
use Jelite\Database;
use Jelite\SmsGateway;
use Jelite\SmsRepository;

Config::load(dirname(__DIR__) . '/.env');

// Connect first: Database::pdo() loads Admin-UI settings (app_settings)
// as config overrides, so the gateway must be built after this line.
$db = Database::pdo();

$gateway = SmsGateway::fromConfig();
if ($gateway === null) {
    fwrite(STDERR, "Gateway not configured (SMS_GATEWAY_URL/USERNAME/PASSWORD via Admin Settings or .env).\n");
    exit(1);
}

$limit = Config::int('WORKER_BATCH_SIZE', 20);
$maxAttempts = Config::int('SMS_MAX_ATTEMPTS', 3);
$repo = new SmsRepository($db);

$messages = $repo->claimQueued($limit, $maxAttempts);
foreach ($messages as $message) {
    $result = $gateway->send($message['to_e164'], $message['body']);
    if ($result['ok']) {
        $repo->markSent((int) $message['id'], $result['message_id']);
        echo "[sent] #{$message['id']} to {$message['to_e164']}\n";
    } else {
        $repo->markFailed((int) $message['id'], (string) $result['error'], $maxAttempts);
        echo "[retry/fail] #{$message['id']}: {$result['error']}\n";
    }
}

echo count($messages) . " message(s) processed.\n";
