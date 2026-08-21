<?php

declare(strict_types=1);

use Jelite\AdminApp;
use Jelite\AdminAuth;
use Jelite\Config;

section('Admin UI');

// Admin bootstrap credentials for the test run (env wins over .env in Config).
putenv('ADMIN_USER=admin');
putenv('ADMIN_PASSWORD=secret-test-pass');
Config::load(dirname(__DIR__) . '/.env');

$admin = AdminApp::fromConfig();
$sess = [];

// Login gate.
$r = $admin->handle($sess, 'GET', '/admin');
same(200, $r['status'], 'GET /admin shows login page when logged out');
check(str_contains($r['body'], 'name="password"'), 'login form rendered');

$r = $admin->handle($sess, 'GET', '/admin/settings');
same(302, $r['status'], 'unauthenticated /admin/settings redirects');
same('/admin', $r['location'], 'redirect target is /admin');

// CSRF enforcement.
$r = $admin->handle($sess, 'POST', '/admin/login', ['username' => 'admin', 'password' => 'secret-test-pass']);
same(403, $r['status'], 'login without CSRF token → 403');

$csrf = AdminAuth::csrfToken($sess);
$r = $admin->handle($sess, 'POST', '/admin/login', ['csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong']);
same(401, $r['status'], 'wrong password → 401');
check(!AdminAuth::check($sess), 'session not authenticated after failed login');

$r = $admin->handle($sess, 'POST', '/admin/login', ['csrf' => $csrf, 'username' => 'admin', 'password' => 'secret-test-pass']);
same(302, $r['status'], 'correct login → redirect');
same('/admin/settings', $r['location'], 'login lands on settings');
check(AdminAuth::check($sess), 'session authenticated');

// Settings page + save.
$r = $admin->handle($sess, 'GET', '/admin/settings');
same(200, $r['status'], 'settings page renders');
check(str_contains($r['body'], 'SMS_GATEWAY_URL'), 'settings lists gateway URL field');
check(!str_contains($r['body'], 'value="secret"'), 'no secret values pre-filled unexpectedly');

$db->exec('DELETE FROM app_settings');
Config::loadDbOverrides($db);

$r = $admin->handle($sess, 'POST', '/admin/settings', [
    'csrf' => $csrf,
    'SMS_MAX_MESSAGE_LENGTH' => '10',
    'SMS_GATEWAY_URL' => 'https://api.sms-gate.app',
    'SMS_TIMEOUT_SECONDS' => 'abc',
]);
same(422, $r['status'], 'non-numeric number field → 422');

$r = $admin->handle($sess, 'POST', '/admin/settings', [
    'csrf' => $csrf,
    'SMS_MAX_MESSAGE_LENGTH' => '10',
    'SMS_GATEWAY_PASSWORD' => '',
]);
same(302, $r['status'], 'valid settings save → redirect');

same('10', Config::get('SMS_MAX_MESSAGE_LENGTH'), 'DB override visible via Config::get');

// Override actually changes API validation behavior.
$r = $app->handle('POST', '/api/v1/sms/send', $auth, json_encode(['to' => '+639171234567', 'message' => str_repeat('x', 11)]));
same(422, $r['status'], 'override tightens max message length on the API');

// Restore.
$db->exec("DELETE FROM app_settings WHERE setting_key = 'SMS_MAX_MESSAGE_LENGTH'");
Config::loadDbOverrides($db);
same('320', Config::get('SMS_MAX_MESSAGE_LENGTH'), 'override removal restores env/default value');

// Keys management through the admin UI.
$r = $admin->handle($sess, 'POST', '/admin/keys/create', ['csrf' => $csrf, 'name' => 'Admin Created', 'rate' => '5']);
same(302, $r['status'], 'key create → redirect');

$createdKey = (string) $sess['created_key'];
check(str_starts_with($createdKey, 'jl_'), 'plaintext key flashed once');
check($keyRepo->authenticate($createdKey) !== null, 'flashed key authenticates against hash');

$r = $admin->handle($sess, 'GET', '/admin/keys');
same(200, $r['status'], 'keys page renders');
check(str_contains($r['body'], 'Admin Created'), 'new key listed');
check(str_contains($r['body'], $createdKey), 'plaintext key shown exactly once (first render)');

$r = $admin->handle($sess, 'GET', '/admin/keys');
check(!str_contains($r['body'], $createdKey), 'plaintext key not shown again');

$keyId = (int) $keyRepo->authenticate($createdKey)['id'];
$r = $admin->handle($sess, 'POST', '/admin/keys/revoke', ['csrf' => $csrf, 'id' => (string) $keyId]);
same(302, $r['status'], 'revoke → redirect');
check($keyRepo->authenticate($createdKey) === null, 'revoked key no longer authenticates');

// Messages page.
$r = $admin->handle($sess, 'GET', '/admin/messages');
same(200, $r['status'], 'messages page renders');

// Logout.
$r = $admin->handle($sess, 'POST', '/admin/logout', ['csrf' => $csrf]);
same(302, $r['status'], 'logout → redirect');
check(!AdminAuth::check($sess), 'session deauthenticated');

// Cleanup test key rows created here.
$db->exec("DELETE FROM sms_messages WHERE api_key_id IN (SELECT id FROM api_keys WHERE name = 'Admin Created')");
$db->exec("DELETE FROM api_keys WHERE name = 'Admin Created'");
