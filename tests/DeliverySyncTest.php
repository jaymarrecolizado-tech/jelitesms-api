<?php

declare(strict_types=1);

use Jelite\Worker;

section('Delivery-state sync');

$db->exec('TRUNCATE TABLE sms_messages');
global $gwResponse;

$syncKey = $keyRepo->create('Sync Consumer')['key'];
$sauth = ['authorization' => 'Bearer ' . $syncKey];
$syncKeyId = (int) $keyRepo->authenticate($syncKey)['id'];

/**
 * Enqueue + drain one message.
 * @return array{local:int,gw:string}
 */
$sendAndDrain = function (string $to, string $message) use ($app, $sauth, $smsRepo, $gateway): array {
    global $gwResponse;
    $gwResponse = fn (): array => ['body' => json_encode(['id' => 'gws-' . uniqid()]), 'errno' => 0, 'error' => '', 'code' => 202, 'final_url' => 'mock'];
    $localId = (int) $app->handle('POST', '/api/v1/sms/send', $sauth, json_encode(['to' => $to, 'message' => $message]))['body']['id'];
    foreach ($smsRepo->claimQueued(20, 3) as $m) {
        $res = $gateway->send($m['to_e164'], $m['body']);
        if ($res['ok']) {
            $smsRepo->markSent((int) $m['id'], $res['message_id']);
        } else {
            $smsRepo->markFailed((int) $m['id'], (string) $res['error'], 3);
        }
    }
    return ['local' => $localId, 'gw' => (string) $smsRepo->find($localId)['gateway_message_id']];
};

/**
 * Respond to GET {apiPath}/{gwId} with upstream state JSON. Map of gateway id
 * (or id prefix) => ['state'=>..., 'reason'=>?, 'code'=>?]; any other gateway
 * id fails loudly so tests cannot pass for the wrong reason.
 */
$stateResponse = function (array $map) use (&$gwResponse): void {
    $gwResponse = function (string $url) use ($map): array {
        foreach ($map as $gwId => $spec) {
            if (str_contains($url, '/messages/' . $gwId)) {
                return [
                    'body' => json_encode($spec),
                    'errno' => 0,
                    'error' => '',
                    'code' => (int) ($spec['code'] ?? 200),
                    'final_url' => 'mock',
                ];
            }
        }
        throw new RuntimeException('Unexpected gateway call in sync phase: ' . $url);
    };
};

// --- Delivered ---------------------------------------------------------------

$a = $sendAndDrain('+6391710010001', 'deliver me');
same('sent', $smsRepo->find($a['local'])['status'], 'pre-sync status sent');
check($smsRepo->find($a['local'])['delivered_at'] === null, 'pre-sync delivered_at null');

$stateResponse([$a['gw'] => ['id' => $a['gw'], 'state' => 'Delivered']]);
$examined = Worker::syncDeliveries($gateway);
same(1, $examined, 'one message examined');
same('delivered', $smsRepo->find($a['local'])['status'], 'sent → delivered');
check($smsRepo->find($a['local'])['delivered_at'] !== null, 'delivered_at recorded');

$status = $app->handle('GET', '/api/v1/sms/' . $a['local'], $sauth);
same('delivered', $status['body']['status'], 'public API reports delivered');
check(isset($status['body']['delivered_at']) && $status['body']['delivered_at'] !== null, 'public API exposes delivered_at');
same('Delivered', $status['body']['gateway_state'], 'public API exposes gateway_state');

// Idempotent: already-delivered rows are not re-examined.
same(0, Worker::syncDeliveries($gateway), 'delivered row no longer examined');

// --- Failed (terminal, with reason) ------------------------------------------

$b = $sendAndDrain('+6391710010002', 'will fail');
$stateResponse([$b['gw'] => ['id' => $b['gw'], 'state' => 'Failed', 'reason' => 'Invalid number']]);
Worker::syncDeliveries($gateway);
same('failed', $smsRepo->find($b['local'])['status'], 'upstream Failed → failed');
same('Invalid number', $smsRepo->find($b['local'])['error'], 'upstream reason stored as error');

// --- Cancelled ----------------------------------------------------------------

$c = $sendAndDrain('+6391710010003', 'cancelled');
$stateResponse([$c['gw'] => ['id' => $c['gw'], 'state' => 'Cancelled']]);
Worker::syncDeliveries($gateway);
same('failed', $smsRepo->find($c['local'])['status'], 'upstream Cancelled → failed');

// --- Still in flight -----------------------------------------------------------

$d = $sendAndDrain('+6391710010004', 'still pending');
$stateResponse([$d['gw'] => ['id' => $d['gw'], 'state' => 'Pending']]);
Worker::syncDeliveries($gateway);
same('sent', $smsRepo->find($d['local'])['status'], 'upstream Pending leaves status sent');
same('Pending', $smsRepo->find($d['local'])['gateway_state'], 'raw gateway state recorded');

$stateResponse([$d['gw'] => ['id' => $d['gw'], 'state' => 'Sent']]);
Worker::syncDeliveries($gateway);
same('sent', $smsRepo->find($d['local'])['status'], 'upstream Sent leaves status sent');

// --- Gateway errors are skipped silently ---------------------------------------

$e = $sendAndDrain('+6391710010005', 'gateway error');
$stateResponse([
    $d['gw'] => ['id' => $d['gw'], 'state' => 'Sent'],
    $e['gw'] => ['error' => 'boom', 'code' => 500],
]);
Worker::syncDeliveries($gateway);
same('sent', $smsRepo->find($e['local'])['status'], 'gateway HTTP error skipped, stays sent');

// Resolve $d and $e so later phases start from a clean slate.
$stateResponse([
    $d['gw'] => ['id' => $d['gw'], 'state' => 'Delivered'],
    $e['gw'] => ['id' => $e['gw'], 'state' => 'Delivered'],
]);
Worker::syncDeliveries($gateway);

// --- Age window -----------------------------------------------------------------

$f = $sendAndDrain('+6391710010007', 'too old');
$db->exec('UPDATE sms_messages SET created_at = NOW() - INTERVAL 30 DAY WHERE id = ' . $f['local']);
$stateResponse([$f['gw'] => ['id' => $f['gw'], 'state' => 'Delivered']]);
$examined = Worker::syncDeliveries($gateway);
same(0, $examined, 'old messages outside sync window excluded');
same('sent', $smsRepo->find($f['local'])['status'], 'old message still sent');

// --- Rows without a gateway id are never selected -------------------------------

$db->exec("INSERT INTO sms_messages (api_key_id, to_e164, body, status) VALUES ($syncKeyId, '+6391710010006', 'no gw id', 'sent')");
$noGwId = (int) $db->lastInsertId();
$examined = Worker::syncDeliveries($gateway);
same(0, $examined, 'row without gateway id not examined');
same('sent', $smsRepo->find($noGwId)['status'], 'row without gateway id untouched');

// --- Limit -----------------------------------------------------------------------

$db->exec('TRUNCATE TABLE sms_messages');
for ($i = 1; $i <= 5; $i++) {
    $db->exec(
        "INSERT INTO sms_messages (api_key_id, to_e164, body, status, gateway_message_id)
         VALUES ($syncKeyId, '+639171002000{$i}', 'batch {$i}', 'sent', 'gwb-{$i}')"
    );
}
$stateResponse(['gwb-' => ['id' => 'x', 'state' => 'Delivered']]);
$examined = Worker::syncDeliveries($gateway, 3);
same(3, $examined, 'limit caps examined messages');
