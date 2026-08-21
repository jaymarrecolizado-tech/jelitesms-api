<?php

declare(strict_types=1);

// Usage: php send.php <to> <message> [client_ref]

require __DIR__ . '/sms.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php send.php <to> <message> [client_ref]\n");
    exit(1);
}

[, $to, $message] = $argv;
$clientRef = $argv[3] ?? null;

$result = smsSend($to, $message, $clientRef);

match (true) {
    $result['http'] === 202 => print("Queued. id={$result['body']['id']} status={$result['body']['status']}\n"),
    $result['http'] === 200 => print("Replay (already sent). id={$result['body']['id']}\n"),
    $result['http'] === 401 => print("ERROR: invalid API key (401)\n"),
    $result['http'] === 422 => print('ERROR: validation failed — ' . json_encode($result['body']['fields'] ?? []) . "\n"),
    $result['http'] === 429 => print("ERROR: rate limited (429) — retry later\n"),
    default                 => print("ERROR: SMS API unavailable (HTTP {$result['http']})\n"),
};

exit($result['http'] === 202 || $result['http'] === 200 ? 0 : 1);
