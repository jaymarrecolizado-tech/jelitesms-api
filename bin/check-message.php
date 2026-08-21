<?php

// Diagnostic: fetch live message state from the gateway (cloud or local).
// Usage: php bin/check-message.php <gateway_message_id>

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\Config;
use Jelite\Database;
use Jelite\SmsGateway;

Config::load(dirname(__DIR__) . '/.env');
Database::pdo(); // loads Admin-UI settings as config overrides

$id = $argv[1] ?? '';
if ($id === '') {
    fwrite(STDERR, "Usage: php bin/check-message.php <gateway_message_id>\n");
    exit(1);
}

$gateway = SmsGateway::fromConfig();
if ($gateway === null) {
    fwrite(STDERR, "Gateway not configured.\n");
    exit(1);
}

$endpoint = $gateway->endpoint() . '/' . urlencode($id);
echo "GET {$endpoint}\n";

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => Config::get('SMS_GATEWAY_USERNAME') . ':' . Config::get('SMS_GATEWAY_PASSWORD'),
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP {$code}\n";
$decoded = json_decode((string) $body, true);
if (is_array($decoded)) {
    echo 'state:        ' . ($decoded['state'] ?? '?') . "\n";
    echo 'isSmsStatus:  ' . var_export($decoded['isSmsStatus'] ?? null, true) . "\n";
    echo 'recipients:   ' . json_encode($decoded['recipients'] ?? null) . "\n";
    echo 'deviceId:     ' . ($decoded['device_id'] ?? $decoded['deviceId'] ?? '?') . "\n";
    echo 'createdAt:    ' . ($decoded['created_at'] ?? $decoded['createdAt'] ?? '?') . "\n";
    echo 'sentAt:       ' . ($decoded['sent_at'] ?? $decoded['sentAt'] ?? '?') . "\n";
} else {
    echo substr((string) $body, 0, 500) . "\n";
}
