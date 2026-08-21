<?php

declare(strict_types=1);

use Jelite\AdminApp;
use Jelite\AdminAuth;
use Jelite\Config;
use Jelite\Markdown;
use Jelite\SettingsRepository;
use Jelite\Worker;

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

section('Admin Test page');

// Auth gate: logged-out session cannot open the Test page.
$fresh = [];
$r = $admin->handle($fresh, 'GET', '/admin/test');
same(302, $r['status'], 'logged-out /admin/test redirects to login');

// Log back in.
$csrf = AdminAuth::csrfToken($sess);
$r = $admin->handle($sess, 'POST', '/admin/login', ['csrf' => $csrf, 'username' => 'admin', 'password' => 'secret-test-pass']);
same(302, $r['status'], 're-login for test-page section');

// Reset mock gateway to success and build an admin whose "run worker once"
// drains through the mocked gateway (real gateway is unconfigured in tests).
global $gwResponse;
$gwResponse = fn (): array => ['body' => json_encode(['id' => 'gw-123']), 'errno' => 0, 'error' => '', 'code' => 202, 'final_url' => 'mock'];
$admin = new AdminApp(
    $keyRepo,
    $smsRepo,
    new SettingsRepository($db),
    $db,
    $app,
    static fn (): int => Worker::drain($gateway)
);

$r = $admin->handle($sess, 'GET', '/admin/test');
same(200, $r['status'], 'test page renders');
check(str_contains($r['body'], 'Send test SMS'), 'send form rendered');
check(str_contains($r['body'], 'Check configuration'), 'probe button rendered');
check(str_contains($r['body'], 'real SMS'), 'real-SMS warning shown');

$r = $admin->handle($sess, 'POST', '/admin/test/send', ['api_key_id' => '1', 'to' => '+639171234567', 'message' => 'x']);
same(403, $r['status'], 'test send without CSRF → 403');

// Send-as-consumer: attributed to the selected key, same validation path.
$playground = $keyRepo->create('Playground Key', 5);
$keyId = (string) $playground['id'];

$r = $admin->handle($sess, 'POST', '/admin/test/send', [
    'csrf' => $csrf,
    'api_key_id' => $keyId,
    'to' => '09181112222',
    'message' => 'admin playground test',
    'client_ref' => 'pg-1',
    'run_worker' => '1',
]);
same(200, $r['status'], 'test send renders result panel');
check(str_contains($r['body'], 'HTTP 202'), 'result shows HTTP 202');
check(str_contains($r['body'], '&quot;queued&quot;'), 'result shows queued status');
check(str_contains($r['body'], 'Worker ran once: 1 message(s) processed.'), 'injected drain ran once');

$row = $smsRepo->findByClientRef((int) $keyId, 'pg-1');
check($row !== null && $row['to_e164'] === '+639181112222', 'message enqueued under selected key');
same('sent', $row['status'], 'run_worker moved status past queued');

// Invalid key selection.
$r = $admin->handle($sess, 'POST', '/admin/test/send', [
    'csrf' => $csrf,
    'api_key_id' => '999999',
    'to' => '+639171234567',
    'message' => 'x',
]);
same(422, $r['status'], 'unknown api_key_id → 422 error panel');

// Config probe.
$r = $admin->handle($sess, 'POST', '/admin/test/probe', ['csrf' => $csrf]);
same(200, $r['status'], 'probe renders');
check(str_contains($r['body'], 'Database:'), 'probe shows database state');

// Rate limit counts against the selected consumer key (limit 5 → 5 more sends allowed, 6th blocked).
for ($i = 0; $i < 4; $i++) {
    $admin->handle($sess, 'POST', '/admin/test/send', [
        'csrf' => $csrf,
        'api_key_id' => $keyId,
        'to' => '+639171234567',
        'message' => "rate {$i}",
        'client_ref' => "rl-{$i}",
    ]);
}
$r = $admin->handle($sess, 'POST', '/admin/test/send', [
    'csrf' => $csrf,
    'api_key_id' => $keyId,
    'to' => '+639171234567',
    'message' => 'over limit',
    'client_ref' => 'rl-over',
]);
check(str_contains($r['body'], 'HTTP 429'), 'test sends hit the selected key rate limit → 429');

// Cleanup.
$db->exec("DELETE FROM sms_messages WHERE api_key_id = " . (int) $keyId);
$db->exec("DELETE FROM api_keys WHERE id = " . (int) $keyId);

section('Admin Docs page (tutorial guide)');

// Auth gate.
$freshDocs = [];
$r = $admin->handle($freshDocs, 'GET', '/admin/docs');
same(302, $r['status'], 'logged-out /admin/docs redirects');

$r = $admin->handle($sess, 'GET', '/admin/docs');
same(200, $r['status'], 'docs page renders for admin');
check(str_contains($r['body'], 'side-nav'), 'left side nav rendered');
check(str_contains($r['body'], 'Welcome &amp; prerequisites'), 'welcome chapter in nav');
check(str_contains($r['body'], '?page=laravel'), 'laravel chapter linked');
check(str_contains($r['body'], '?page=codeigniter'), 'codeigniter chapter linked');
check(str_contains($r['body'], '?page=react-laravel-bff'), 'react-laravel chapter linked');
check(str_contains($r['body'], '?page=react-node-bff'), 'react-node chapter linked');
check(str_contains($r['body'], 'What this API is'), 'welcome content shown by default');

// Page switching + allowlist.
$r = $admin->handle($sess, 'GET', '/admin/docs', [], ['page' => 'troubleshooting']);
check(str_contains($r['body'], 'HTTP responses'), 'troubleshooting page renders');
check(str_contains($r['body'], 'class="active"'), 'active nav item marked');

$r = $admin->handle($sess, 'GET', '/admin/docs', [], ['page' => 'next-steps']);
check(str_contains($r['body'], 'Production checklist'), 'next-steps page renders');

// Unknown / traversal / legacy ids fall back to welcome and leak nothing.
foreach ([['page' => 'not-a-chapter'], ['page' => '../../.env'], ['doc' => 'deploy']] as $q) {
    $r = $admin->handle($sess, 'GET', '/admin/docs', [], $q);
    check(str_contains($r['body'], 'What this API is'), "fallback to welcome for " . ($q['page'] ?? $q['doc']));
    check(!str_contains($r['body'], 'ADMIN_PASSWORD'), '.env contents not leaked');
}

// Ops deploy runbook must never appear in Admin Docs.
$opsMd = (string) file_get_contents(dirname(__DIR__) . '/docs/ops/DEPLOY.md');
$marker = 'jelitesmsapi.dictr2.cloud';
check(str_contains($opsMd, $marker), 'ops runbook still on disk (sanity)');
$r = $admin->handle($sess, 'GET', '/admin/docs', [], ['page' => 'welcome']);
check(!str_contains($r['body'], $marker), 'ops host details not in admin docs');
check(!str_contains($r['body'], 'docs/ops/DEPLOY.md'), 'no link to ops runbook in admin docs');

// .htaccess must block sensitive paths when the app root is the web docroot.
$htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
foreach (['^\.env', '^\.git', '(dist|tests|docs/ops)'] as $deny) {
    check(str_contains($htaccess, $deny), ".htaccess denies {$deny}");
}

section('Markdown renderer');

$html = Markdown::toHtml("# Title\n\nSome **bold** and `code` text.");
check(str_contains($html, '<h1>Title</h1>'), 'heading rendered');
check(str_contains($html, '<strong>bold</strong>'), 'bold rendered');
check(str_contains($html, '<code>code</code>'), 'inline code rendered');

$html = Markdown::toHtml("```\n<b>&raw</b>\n```");
check(str_contains($html, '&lt;b&gt;&amp;raw&lt;/b&gt;'), 'fenced code escaped');

$html = Markdown::toHtml("| A | B |\n|---|---|\n| 1 | 2 |");
check(str_contains($html, '<table>') && str_contains($html, '<td>1</td>'), 'table rendered');

$html = Markdown::toHtml("- one\n- two\n\n1. first\n2. second");
check(str_contains($html, '<ul><li>one</li><li>two</li></ul>'), 'unordered list rendered');
check(str_contains($html, '<ol><li>first</li><li>second</li></ol>'), 'ordered list rendered');

$html = Markdown::toHtml('[docs](https://example.com/x) and [bad](javascript:alert(1))');
check(str_contains($html, '<a href="https://example.com/x"'), 'safe link kept');
check(!str_contains($html, 'href="javascript:'), 'javascript: URL stripped');

$html = Markdown::toHtml('<script>alert(1)</script>');
check(!str_contains($html, '<script>'), 'raw HTML escaped');

section('Admin Usage page');

$fresh = [];
$r = $admin->handle($fresh, 'GET', '/admin/usage');
same(302, $r['status'], 'logged-out /admin/usage redirects');

$usageA = $keyRepo->create('Usage App A', 30);
$usageB = $keyRepo->create('Usage App B', 30);
$smsRepo->enqueue((int) $usageA['id'], '+639171111111', 'ua1', 'usage-a-1');
$smsRepo->enqueue((int) $usageA['id'], '+639171111112', 'ua2', 'usage-a-2');
$smsRepo->enqueue((int) $usageB['id'], '+639172222222', 'ub1', 'usage-b-1');
$smsRepo->markSent((int) $smsRepo->findByClientRef((int) $usageA['id'], 'usage-a-1')['id'], 'gw-u1');

$today = date('Y-m-d');
$r = $admin->handle($sess, 'GET', '/admin/usage', [], ['from' => $today, 'to' => $today]);
same(200, $r['status'], 'usage page renders');
check(str_contains($r['body'], 'Usage by app'), 'usage heading shown');
check(str_contains($r['body'], 'Usage App A'), 'app A listed');
check(str_contains($r['body'], 'Usage App B'), 'app B listed');
check(str_contains($r['body'], 'name="from"'), 'from date filter present');
check(str_contains($r['body'], 'name="to"'), 'to date filter present');

// App A should show total 2; sent at least 1 in the HTML table row context.
check(preg_match('/Usage App A.*?<\/tr>/s', $r['body'], $mA) === 1, 'app A row captured');
check(str_contains($mA[0], '>2</td>'), 'app A total is 2');

$stats = $smsRepo->usageByKey($today, $today);
$byName = [];
foreach ($stats as $row) {
    $byName[$row['name']] = $row;
}
same(2, (int) $byName['Usage App A']['total'], 'repo: App A total 2');
same(1, (int) $byName['Usage App A']['sent'], 'repo: App A sent 1');
same(1, (int) $byName['Usage App B']['total'], 'repo: App B total 1');
check($byName['Usage App A']['last_used'] !== null, 'repo: App A last_used set');

// Invalid dates fall back to a valid default range (still 200).
$r = $admin->handle($sess, 'GET', '/admin/usage', [], ['from' => 'not-a-date', 'to' => 'also-bad']);
same(200, $r['status'], 'invalid dates still render with defaults');

// Outside range → zeros for these keys' totals in the filtered window.
$past = date('Y-m-d', strtotime('-30 days'));
$statsPast = $smsRepo->usageByKey($past, $past);
$pastA = null;
foreach ($statsPast as $row) {
    if ($row['name'] === 'Usage App A') {
        $pastA = $row;
        break;
    }
}
same(0, (int) ($pastA['total'] ?? -1), 'date filter excludes today messages from past day');

$db->exec('DELETE FROM sms_messages WHERE api_key_id IN (' . (int) $usageA['id'] . ',' . (int) $usageB['id'] . ')');
$db->exec('DELETE FROM api_keys WHERE id IN (' . (int) $usageA['id'] . ',' . (int) $usageB['id'] . ')');

