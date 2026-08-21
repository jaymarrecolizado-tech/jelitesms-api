<?php

declare(strict_types=1);

section('Worker drain');

$db->exec('TRUNCATE TABLE sms_messages');
$gwRequests = [];

$workerKey = $keyRepo->create('Worker Consumer')['key'];
$wauth = ['authorization' => 'Bearer ' . $workerKey];

$a = $app->handle('POST', '/api/v1/sms/send', $wauth, json_encode(['to' => '+6391710000001', 'message' => 'one']))['body']['id'];
$b = $app->handle('POST', '/api/v1/sms/send', $wauth, json_encode(['to' => '+6391710000002', 'message' => 'two']))['body']['id'];

// Drain the queue through the same logic as bin/worker.php.
$maxAttempts = 3;
foreach ($smsRepo->claimQueued(20, $maxAttempts) as $m) {
    $res = $gateway->send($m['to_e164'], $m['body']);
    if ($res['ok']) {
        $smsRepo->markSent((int) $m['id'], $res['message_id']);
    } else {
        $smsRepo->markFailed((int) $m['id'], (string) $res['error'], $maxAttempts);
    }
}

same('sent', $smsRepo->find($a)['status'], 'message A sent');
same('sent', $smsRepo->find($b)['status'], 'message B sent');
same('gw-123', $smsRepo->find($a)['gateway_message_id'], 'gateway id stored');
same(2, count($gwRequests), 'two gateway calls made');
$texts = array_map(
    fn (string $p): mixed => json_decode($p, true)['textMessage']['text'] ?? null,
    array_column(array_map(fn (array $r): array => ['payload' => (string) $r['payload']], $gwRequests), 'payload')
);
check(in_array('one', $texts, true), 'upstream payload uses textMessage.text');

// Failure path: gateway down → soft-fail back to queued until attempts exhausted.
global $gwResponse;
$gwResponse = fn (): array => ['body' => false, 'errno' => 28, 'error' => 'timeout', 'code' => 0, 'final_url' => 'mock'];

$c = $app->handle('POST', '/api/v1/sms/send', $wauth, json_encode(['to' => '+6391710000003', 'message' => 'three']))['body']['id'];
for ($i = 0; $i < $maxAttempts; $i++) {
    foreach ($smsRepo->claimQueued(20, $maxAttempts) as $m) {
        $res = $gateway->send($m['to_e164'], $m['body']);
        if ($res['ok']) {
            $smsRepo->markSent((int) $m['id'], $res['message_id']);
        } else {
            $smsRepo->markFailed((int) $m['id'], (string) $res['error'], $maxAttempts);
        }
    }
}
same('failed', $smsRepo->find($c)['status'], 'exhausted retries → failed');
check($smsRepo->find($c)['attempts'] >= $maxAttempts, 'attempts recorded');
