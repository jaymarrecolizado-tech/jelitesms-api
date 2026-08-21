<?php

declare(strict_types=1);

section('Enqueue + status');

$db->exec('TRUNCATE TABLE sms_messages');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode([
    'to' => '09171234567',
    'message' => 'Hello from JE Lite SMS API',
    'client_ref' => 'hrmis-leave-42',
]));
same(202, $r['status'], 'valid send → 202');
same('queued', $r['body']['status'], 'initial status queued');
$id = $r['body']['id'];
check(is_int($id) && $id > 0, 'numeric id returned');

$row = $smsRepo->find($id);
same('+639171234567', $row['to_e164'], 'phone normalized to E.164 on enqueue');
same('hrmis-leave-42', $row['client_ref'], 'client_ref stored');

// Idempotency: same client_ref replays the existing message.
$r2 = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode([
    'to' => '+639171234567',
    'message' => 'Hello from JE Lite SMS API',
    'client_ref' => 'hrmis-leave-42',
]));
same(200, $r2['status'], 'duplicate client_ref → 200 replay');
same($id, $r2['body']['id'], 'duplicate returns original id');
same(1, (int) $db->query('SELECT COUNT(*) FROM sms_messages')->fetchColumn(), 'no duplicate row inserted');

// Status endpoint.
$r = $app->handle('GET', "/api/v1/sms/{$id}", $auth);
same(200, $r['status'], 'status → 200');
same('queued', $r['body']['status'], 'status queued');
same('hrmis-leave-42', $r['body']['client_ref'], 'status includes client_ref');

$r = $app->handle('GET', '/api/v1/sms/999999', $auth);
same(404, $r['status'], 'unknown id → 404');

// Key isolation: another consumer cannot read this message.
$otherKey = $keyRepo->create('Other Consumer')['key'];
$r = $app->handle('GET', "/api/v1/sms/{$id}", ['authorization' => 'Bearer ' . $otherKey]);
same(404, $r['status'], 'other key cannot read foreign message');

// Rate limiting: create a key with limit 2 and fire 3 sends.
$tightKey = $keyRepo->create('Tight Consumer', 2)['key'];
$tauth = ['authorization' => 'Bearer ' . $tightKey];
$s1 = $app->handle('POST', '/api/v1/sms/send', $tauth, json_encode(['to' => '+6391700000001', 'message' => 'a']));
$s2 = $app->handle('POST', '/api/v1/sms/send', $tauth, json_encode(['to' => '+6391700000002', 'message' => 'b']));
$s3 = $app->handle('POST', '/api/v1/sms/send', $tauth, json_encode(['to' => '+6391700000003', 'message' => 'c']));
same(202, $s1['status'], 'rate: 1st allowed');
same(202, $s2['status'], 'rate: 2nd allowed');
same(429, $s3['status'], 'rate: 3rd blocked → 429');
