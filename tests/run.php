<?php

declare(strict_types=1);

// Minimal dependency-free test runner.
// Usage: C:\xampp\php\php.exe tests\run.php

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\ApiKeyRepository;
use Jelite\App;
use Jelite\Config;
use Jelite\Database;
use Jelite\SmsGateway;
use Jelite\SmsRepository;

// Force the test database: real env vars win over .env values in Config.
putenv('APP_ENV=test');
Config::load(dirname(__DIR__) . '/.env');

$failures = 0;
$passes = 0;
$current = '';

function check(bool $cond, string $label): void
{
    global $failures, $passes, $current;
    if ($cond) {
        $passes++;
        echo "  ok  {$current} :: {$label}\n";
    } else {
        $failures++;
        echo "FAIL  {$current} :: {$label}\n";
    }
}

function same(mixed $expected, mixed $actual, string $label): void
{
    check($expected === $actual, $label . ($expected === $actual ? '' : sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true))));
}

function section(string $name): void
{
    global $current;
    $current = $name;
}

// --- Bootstrap test DB ------------------------------------------------------

$server = Database::serverPdo();
$server->exec('CREATE DATABASE IF NOT EXISTS `jelite_sms_api_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Database::pdo('jelite_sms_api_test')->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
Database::pdo('jelite_sms_api_test')->exec('TRUNCATE TABLE sms_messages');
Database::pdo('jelite_sms_api_test')->exec('DELETE FROM api_keys');

$db = Database::pdo('jelite_sms_api_test');
$keyRepo = new ApiKeyRepository($db);
$smsRepo = new SmsRepository($db);

// Mock gateway transport: records requests, returns canned responses.
/** @var list<array> $gwRequests */
$gwRequests = [];
$gwResponse = fn (): array => ['body' => json_encode(['id' => 'gw-123']), 'errno' => 0, 'error' => '', 'code' => 202, 'final_url' => 'mock'];
$transport = function (string $url, ?string $payload, bool $headOnly) use (&$gwRequests, &$gwResponse): array {
    $gwRequests[] = ['url' => $url, 'payload' => $payload];
    return $gwResponse();
};
$gateway = new SmsGateway('https://api.sms-gate.app', 'user', 'pass', '/3rdparty/v1/messages', 15, $transport);

$app = new App($keyRepo, $smsRepo, $gateway);
$plaintextKey = $keyRepo->create('Test Consumer', 30)['key'];
$auth = ['authorization' => 'Bearer ' . $plaintextKey];

require __DIR__ . '/PhoneTest.php';
require __DIR__ . '/AuthTest.php';
require __DIR__ . '/SendValidationTest.php';
require __DIR__ . '/EnqueueStatusTest.php';
require __DIR__ . '/GatewayClientTest.php';
require __DIR__ . '/WorkerDrainTest.php';

echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
