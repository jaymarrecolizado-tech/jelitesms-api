<?php

declare(strict_types=1);

use Jelite\AdminApp;
use Jelite\AdminAuth;

section('Admin Reports');

$reportsAdmin = AdminApp::fromConfig();
$sessR = [];

// Auth gate.
$r = $reportsAdmin->handle($sessR, 'GET', '/admin/reports');
same(302, $r['status'], 'logged-out /admin/reports redirects');

$csrfR = AdminAuth::csrfToken($sessR);
$r = $reportsAdmin->handle($sessR, 'POST', '/admin/login', ['csrf' => $csrfR, 'username' => 'admin', 'password' => 'secret-test-pass']);
same(302, $r['status'], 're-login for reports section');

// Seed: two apps, mixed statuses today.
$db->exec('TRUNCATE TABLE sms_messages');
$keyA = $keyRepo->create('Reports App A')['key'];
$keyB = $keyRepo->create('Reports App B')['key'];
$idA = (int) $keyRepo->authenticate($keyA)['id'];
$idB = (int) $keyRepo->authenticate($keyB)['id'];

$db->exec("INSERT INTO sms_messages (api_key_id, to_e164, body, status, gateway_message_id, gateway_state, delivered_at)
           VALUES ($idA, '+6391730010001', 'm1', 'delivered', 'gwr-1', 'Delivered', NOW())");
$db->exec("INSERT INTO sms_messages (api_key_id, to_e164, body, status, gateway_message_id, gateway_state)
           VALUES ($idA, '+6391730010002', 'm2', 'sent', 'gwr-2', 'Sent')");
$db->exec("INSERT INTO sms_messages (api_key_id, to_e164, body, status) VALUES ($idA, '+6391730010003', 'm3', 'queued')");
$db->exec("INSERT INTO sms_messages (api_key_id, to_e164, body, status, error) VALUES ($idB, '+6391730010004', 'm4', 'failed', 'Invalid number')");

// Page renders with aggregates.
$r = $reportsAdmin->handle($sessR, 'GET', '/admin/reports');
same(200, $r['status'], 'reports page renders');
check(str_contains($r['body'], 'Delivery reports'), 'reports heading shown');
check(str_contains($r['body'], '<strong>4</strong>'), 'summary total is 4');
check(str_contains($r['body'], 'Reports App A'), 'app A listed');
check(str_contains($r['body'], 'Reports App B'), 'app B listed');
check(str_contains($r['body'], 's-delivered'), 'delivered badge rendered');
check(str_contains($r['body'], 'Download CSV'), 'CSV export link present');
check(str_contains($r['body'], 'name="api_key_id"'), 'app filter present');
check(str_contains($r['body'], 'name="status"'), 'status filter present');

// Status filter narrows results.
$r = $reportsAdmin->handle($sessR, 'GET', '/admin/reports', [], ['status' => 'delivered']);
check(str_contains($r['body'], '<strong>1</strong>'), 'status filter narrows totals');

$r = $reportsAdmin->handle($sessR, 'GET', '/admin/reports', [], ['api_key_id' => (string) $idB]);
check(str_contains($r['body'], '<strong>1</strong>'), 'app filter narrows totals');

// Repo-level aggregates.
$today = date('Y-m-d');
$totals = $smsRepo->reportTotals($today, $today);
same(4, $totals['total'], 'repo: total 4');
same(1, $totals['delivered'], 'repo: delivered 1');
same(1, $totals['failed'], 'repo: failed 1');
same(1, $totals['queued'], 'repo: queued 1');

$byKey = $smsRepo->reportByKey($today, $today);
same(2, count($byKey), 'repo: two apps in breakdown');
same('3', (string) $byKey[0]['total'], 'repo: app A total 3');

$messages = $smsRepo->reportMessages($today, $today);
same(4, count($messages), 'repo: drill-down rows');
same('Reports App B', $messages[0]['key_name'], 'drill-down newest first');

// CSV export.
$r = $reportsAdmin->handle($sessR, 'GET', '/admin/reports/export');
same(200, $r['status'], 'CSV export renders');
same('text/csv; charset=utf-8', $r['content_type'] ?? null, 'CSV content type set');
check(str_starts_with($r['body'], 'id,app,to,status,gateway_state'), 'CSV header row');
check(substr_count($r['body'], "\n") >= 5, 'CSV contains all data rows');
check(str_contains($r['body'], 'Delivered'), 'CSV includes gateway state');

$r = $reportsAdmin->handle($sessR, 'GET', '/admin/reports/export', [], ['status' => 'failed']);
same(2, substr_count($r['body'], "\n"), 'CSV respects status filter (header + 1 row)');
