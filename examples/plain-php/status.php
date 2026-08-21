<?php

declare(strict_types=1);

// Usage: php status.php <id>

require __DIR__ . '/sms.php';

if ($argc < 2 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Usage: php status.php <id>\n");
    exit(1);
}

$result = smsStatus((int) $argv[1]);

if ($result['http'] !== 200) {
    print("ERROR: HTTP {$result['http']}\n");
    exit(1);
}

$b = $result['body'];
printf(
    "id=%d to=%s status=%s gateway_state=%s attempts=%d created=%s sent=%s delivered=%s\n",
    $b['id'],
    $b['to'],
    $b['status'],
    $b['gateway_state'] ?? '-',
    $b['attempts'],
    $b['created_at'],
    $b['sent_at'] ?? '-',
    $b['delivered_at'] ?? '-',
);
