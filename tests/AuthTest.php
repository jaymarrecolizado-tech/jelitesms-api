<?php

declare(strict_types=1);

section('Auth');

$r = $app->handle('POST', '/api/v1/sms/send', [], json_encode(['to' => '+639171234567', 'message' => 'hi']));
same(401, $r['status'], 'missing bearer → 401');

$r = $app->handle('POST', '/api/v1/sms/send', ['authorization' => 'Basic abc'], '{}');
same(401, $r['status'], 'non-bearer scheme → 401');

$r = $app->handle('POST', '/api/v1/sms/send', ['authorization' => 'Bearer jl_bogus'], '{}');
same(401, $r['status'], 'unknown key → 401');

$r = $app->handle('GET', '/api/v1/sms/1', ['authorization' => 'Bearer jl_bogus']);
same(401, $r['status'], 'unknown key on status → 401');

$r = $app->handle('GET', '/api/v1/health');
check($r['status'] === 200 || $r['status'] === 503, 'health needs no auth');
check(!isset($r['body']['password']) && !isset($r['body']['username']), 'health leaks no secrets');
