<?php

require __DIR__ . '/../src/autoload.php';

use Jelite\Config;
use Jelite\Database;

Config::load(dirname(__DIR__) . '/.env');
$db = Database::pdo();

foreach ($db->query('SELECT id, to_e164, status, attempts, error FROM sms_messages ORDER BY id DESC LIMIT 5') as $r) {
    echo "#{$r['id']} {$r['to_e164']} {$r['status']} attempts={$r['attempts']}"
        . ($r['error'] ? ' error=' . substr((string) $r['error'], 0, 80) : '') . PHP_EOL;
}
echo "--- end of recent rows ---" . PHP_EOL;
