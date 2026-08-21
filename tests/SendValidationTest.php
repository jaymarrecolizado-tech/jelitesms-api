<?php

declare(strict_types=1);

section('Send validation');

$db->exec('TRUNCATE TABLE sms_messages');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, 'not json');
same(400, $r['status'], 'invalid JSON → 400');
same('invalid_json', $r['body']['error'], 'invalid_json error code');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode([]));
same(422, $r['status'], 'empty body → 422');
check(isset($r['body']['fields']['to']), 'missing to reported');
check(isset($r['body']['fields']['message']), 'missing message reported');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode(['to' => 'nope', 'message' => 'x']));
same(422, $r['status'], 'bad phone → 422');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode(['to' => '+639171234567', 'message' => str_repeat('x', 321)]));
same(422, $r['status'], 'message > 320 chars → 422');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode(['to' => '+639171234567', 'message' => str_repeat('x', 320)]));
same(202, $r['status'], 'exactly 320 chars accepted');

$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode(['to' => '+639171234567', 'message' => 'ok', 'client_ref' => str_repeat('c', 101)]));
same(422, $r['status'], 'client_ref > 100 chars → 422');

$r = $app->handle('GET', '/api/v1/nope', $auth);
same(404, $r['status'], 'unknown route → 404');
