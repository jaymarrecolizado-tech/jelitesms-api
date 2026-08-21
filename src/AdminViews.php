<?php

namespace Jelite;

use function htmlspecialchars as e;

/** HTML rendering for the admin UI. All dynamic output is escaped here. */
class AdminViews
{
    public static function layout(string $title, string $active, string $content, bool $loggedIn): string
    {
        $nav = '';
        if ($loggedIn) {
            $links = [
                '/admin/settings' => 'Settings',
                '/admin/keys' => 'API Keys',
                '/admin/messages' => 'Messages',
                '/admin/usage' => 'Usage',
                '/admin/reports' => 'Reports',
                '/admin/test' => 'Test',
                '/admin/docs' => 'Docs',
            ];
            foreach ($links as $href => $label) {
                $url = AdminApp::url($href);
                $class = $active === $href ? ' class="active"' : '';
                $nav .= "<a href=\"{$url}\"{$class}>{$label}</a>";
            }
            $nav .= '<form method="post" action="' . AdminApp::url('/admin/logout') . '" class="logout">'
                . '<button type="submit">Log out</button></form>';
        }

        return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">"
            . "\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . "\n<title>" . e($title) . " — JE Lite SMS API</title>\n" . self::css()
            . "</head>\n<body>\n<header><strong>JE Lite SMS Admin</strong><nav>{$nav}</nav></header>\n"
            . "<main>{$content}</main>\n</body>\n</html>\n";
    }

    public static function loginPage(string $csrf, ?string $error): string
    {
        $err = $error !== null ? "<p class=\"error\">" . e($error) . "</p>" : '';
        $content = "<h1>Admin log in</h1>{$err}"
            . '<form method="post" action="' . AdminApp::url('/admin/login') . '" class="card narrow">'
            . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
            . '<label>Username<input name="username" autofocus required></label>'
            . '<label>Password<input name="password" type="password" required></label>'
            . '<button type="submit">Log in</button></form>';
        return self::layout('Log in', '', $content, false);
    }

    /**
     * @param array<string,array{label:string,value:string,type:string,help?:string}> $fields
     */
    public static function settingsPage(string $csrf, array $fields, ?string $flash): string
    {
        $flashHtml = $flash !== null ? "<p class=\"ok\">" . e($flash) . "</p>" : '';
        $rows = '';
        foreach ($fields as $name => $f) {
            $help = isset($f['help']) ? "<small>" . e($f['help']) . "</small>" : '';
            $rows .= "<label>" . e($f['label'])
                . "<input name=\"" . e($name) . "\" type=\"{$f['type']}\" value=\"" . e($f['value']) . "\">"
                . "{$help}</label>";
        }
        $content = "<h1>Settings</h1>{$flashHtml}<p class=\"hint\">Values saved here override <code>.env</code>. "
            . "Leave the password blank to keep the current one.</p>"
            . '<form method="post" action="' . AdminApp::url('/admin/settings') . '" class="card">'
            . '<input type="hidden" name="csrf" value="' . e($csrf) . '">' . $rows
            . '<button type="submit">Save settings</button></form>';
        return self::layout('Settings', '/admin/settings', $content, true);
    }

    /**
     * @param list<array> $keys
     */
    public static function keysPage(string $csrf, array $keys, ?string $createdKey): string
    {
        $flash = $createdKey !== null
            ? "<p class=\"ok\">New key created — copy it now, it will not be shown again:<br><code>" . e($createdKey) . "</code></p>"
            : '';
        $rows = '';
        foreach ($keys as $k) {
            $status = $k['active'] ? 'active' : 'revoked';
            $revoke = $k['active']
                ? '<form method="post" action="' . AdminApp::url('/admin/keys/revoke') . '">'
                . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . (int) $k['id'] . '">'
                . '<button type="submit" class="danger">Revoke</button></form>'
                : ($k['revoked_at'] ? 'revoked ' . e((string) $k['revoked_at']) : '');
            $rows .= '<tr><td>' . (int) $k['id'] . '</td><td>' . e((string) $k['name']) . '</td><td><code>'
                . e((string) $k['key_prefix']) . '…</code></td><td>' . $status . '</td><td>'
                . (int) $k['rate_limit_per_minute'] . '/min</td><td>' . e((string) $k['created_at'])
                . '</td><td>' . $revoke . '</td></tr>';
        }
        $content = "<h1>API Keys</h1>{$flash}"
            . '<form method="post" action="' . AdminApp::url('/admin/keys/create') . '" class="card narrow">'
            . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
            . '<label>Consumer name<input name="name" required placeholder="e.g. HRMIS"></label>'
            . '<label>Rate limit / minute<input name="rate" type="number" value="30" min="1"></label>'
            . '<button type="submit">Create key</button></form>'
            . '<table><thead><tr><th>ID</th><th>Name</th><th>Prefix</th><th>Status</th><th>Rate</th>'
            . '<th>Created</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        return self::layout('API Keys', '/admin/keys', $content, true);
    }

    /**
     * @param list<array> $rows
     */
    public static function messagesPage(array $rows): string
    {
        $body = '';
        foreach ($rows as $m) {
            $err = $m['error'] !== null ? '<div class="error">' . e((string) $m['error']) . '</div>' : '';
            $body .= '<tr><td>' . (int) $m['id'] . '</td><td>' . e((string) $m['key_name']) . '</td><td>'
                . e((string) $m['to_e164']) . '</td><td>' . e(mb_substr((string) $m['body'], 0, 40))
                . '</td><td><span class="status s-' . e((string) $m['status']) . '">' . e((string) $m['status'])
                . '</span></td><td>' . (int) $m['attempts'] . '</td><td>' . e((string) $m['created_at'])
                . '</td><td>' . e((string) ($m['sent_at'] ?? '')) . $err . '</td></tr>';
        }
        $content = '<h1>Messages</h1><p class="hint">Most recent 50 queue rows (read-only).</p>'
            . '<table><thead><tr><th>ID</th><th>Key</th><th>To</th><th>Body</th><th>Status</th>'
            . '<th>Attempts</th><th>Created</th><th>Sent / Error</th></tr></thead><tbody>'
            . ($body !== '' ? $body : '<tr><td colspan="8">No messages yet.</td></tr>')
            . '</tbody></table>';
        return self::layout('Messages', '/admin/messages', $content, true);
    }

    /**
     * Per-consumer usage totals for a date range.
     *
     * @param list<array> $rows from SmsRepository::usageByKey
     */
    public static function usagePage(string $from, string $to, array $rows): string
    {
        $totals = ['total' => 0, 'queued' => 0, 'sending' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0];
        $body = '';
        foreach ($rows as $r) {
            foreach (['total', 'queued', 'sending', 'sent', 'delivered', 'failed'] as $col) {
                $totals[$col] += (int) $r[$col];
            }
            $status = ((int) $r['active']) === 1 ? 'active' : 'revoked';
            $body .= '<tr><td>' . e((string) $r['name']) . '</td><td><code>'
                . e((string) $r['key_prefix']) . '…</code></td><td>' . $status . '</td><td>'
                . (int) $r['total'] . '</td><td>' . (int) $r['sent'] . '</td><td>'
                . (int) $r['delivered'] . '</td><td>' . (int) $r['failed'] . '</td><td>' . (int) $r['queued'] . '</td><td>'
                . (int) $r['sending'] . '</td><td>'
                . e((string) ($r['last_used'] ?? '—')) . '</td></tr>';
        }

        $filter = '<form method="get" action="' . AdminApp::url('/admin/usage') . '" class="card narrow filter">'
            . '<label>From<input type="date" name="from" value="' . e($from) . '" required></label>'
            . '<label>To<input type="date" name="to" value="' . e($to) . '" required></label>'
            . '<button type="submit">Apply</button></form>';

        $summary = '<p class="hint">Showing <strong>' . e($from) . '</strong> → <strong>' . e($to)
            . '</strong>. Totals: <strong>' . $totals['total'] . '</strong> messages'
            . ' (' . $totals['sent'] . ' sent, ' . $totals['delivered'] . ' delivered, '
            . $totals['failed'] . ' failed, ' . $totals['queued'] . ' queued).</p>';

        $content = '<h1>Usage by app</h1>'
            . '<p class="hint">Each API key name is a consumer app. Counts are from queued SMS in the selected date range.</p>'
            . $filter . $summary
            . '<table><thead><tr><th>App / key</th><th>Prefix</th><th>Status</th><th>Total</th>'
            . '<th>Sent</th><th>Delivered</th><th>Failed</th><th>Queued</th><th>Sending</th><th>Last used</th></tr></thead><tbody>'
            . ($body !== '' ? $body : '<tr><td colspan="10">No API keys yet.</td></tr>')
            . '</tbody></table>';

        return self::layout('Usage', '/admin/usage', $content, true);
    }

    /**
     * Delivery reports: filters, summary totals, per-app breakdown and a
     * message drill-down (Phase 5.8).
     *
     * @param list<array> $keys all API keys (for the filter dropdown)
     * @param array{total:int,queued:int,sending:int,sent:int,delivered:int,failed:int} $totals
     * @param list<array> $byKey from SmsRepository::reportByKey
     * @param list<array> $messages from SmsRepository::reportMessages
     */
    public static function reportsPage(string $from, string $to, array $keys, ?int $keyId, ?string $status, array $totals, array $byKey, array $messages): string
    {
        $keyOptions = '<option value="">All apps</option>';
        foreach ($keys as $k) {
            $sel = $keyId === (int) $k['id'] ? ' selected' : '';
            $keyOptions .= '<option value="' . (int) $k['id'] . '"' . $sel . '>' . e((string) $k['name']) . '</option>';
        }
        $statusOptions = '<option value="">All statuses</option>';
        foreach (['queued', 'sending', 'sent', 'delivered', 'failed'] as $s) {
            $sel = $status === $s ? ' selected' : '';
            $statusOptions .= '<option value="' . $s . '"' . $sel . '>' . $s . '</option>';
        }

        $query = http_build_query(array_filter([
            'from' => $from,
            'to' => $to,
            'api_key_id' => $keyId,
            'status' => $status,
        ], static fn ($v) => $v !== null && $v !== ''));

        $filter = '<form method="get" action="' . AdminApp::url('/admin/reports') . '" class="card narrow filter">'
            . '<label>From<input type="date" name="from" value="' . e($from) . '" required></label>'
            . '<label>To<input type="date" name="to" value="' . e($to) . '" required></label>'
            . '<label>App<select name="api_key_id">' . $keyOptions . '</select></label>'
            . '<label>Status<select name="status">' . $statusOptions . '</select></label>'
            . '<button type="submit">Apply</button></form>';

        $summary = '<p class="hint">Showing <strong>' . e($from) . '</strong> → <strong>' . e($to)
            . '</strong>: <strong>' . $totals['total'] . '</strong> messages — '
            . $totals['delivered'] . ' delivered, ' . $totals['sent'] . ' sent, '
            . $totals['failed'] . ' failed, ' . $totals['queued'] . ' queued, '
            . $totals['sending'] . ' sending.</p>';

        $export = '<p><a class="button-link" href="' . AdminApp::url('/admin/reports/export') . '?' . e($query)
            . '">Download CSV</a></p>';

        $byKeyRows = '';
        foreach ($byKey as $r) {
            $byKeyRows .= '<tr><td>' . e((string) $r['name']) . '</td><td><code>'
                . e((string) $r['key_prefix']) . '…</code></td><td>' . (int) $r['total'] . '</td><td>'
                . (int) $r['delivered'] . '</td><td>' . (int) $r['sent'] . '</td><td>'
                . (int) $r['failed'] . '</td><td>' . (int) $r['queued'] . '</td><td>'
                . (int) $r['sending'] . '</td></tr>';
        }
        $byKeyTable = '<h2>By app</h2>'
            . '<table><thead><tr><th>App / key</th><th>Prefix</th><th>Total</th><th>Delivered</th>'
            . '<th>Sent</th><th>Failed</th><th>Queued</th><th>Sending</th></tr></thead><tbody>'
            . ($byKeyRows !== '' ? $byKeyRows : '<tr><td colspan="8">No messages in range.</td></tr>')
            . '</tbody></table>';

        $msgRows = '';
        foreach ($messages as $m) {
            $err = $m['error'] !== null ? '<div class="error">' . e((string) $m['error']) . '</div>' : '';
            $msgRows .= '<tr><td>' . (int) $m['id'] . '</td><td>' . e((string) $m['key_name']) . '</td><td>'
                . e((string) $m['to_e164']) . '</td><td><span class="status s-' . e((string) $m['status']) . '">'
                . e((string) $m['status']) . '</span></td><td>' . e((string) ($m['gateway_state'] ?? '')) . '</td><td>'
                . e((string) $m['created_at']) . '</td><td>' . e((string) ($m['delivered_at'] ?? ''))
                . $err . '</td></tr>';
        }
        $msgTable = '<h2>Messages</h2><p class="hint">Most recent 200 matching rows.</p>'
            . '<table><thead><tr><th>ID</th><th>Key</th><th>To</th><th>Status</th><th>Gateway state</th>'
            . '<th>Created</th><th>Delivered / Error</th></tr></thead><tbody>'
            . ($msgRows !== '' ? $msgRows : '<tr><td colspan="7">No messages in range.</td></tr>')
            . '</tbody></table>';

        $content = '<h1>Delivery reports</h1>'
            . '<p class="hint">Delivery state comes from the sync job (<code>bin/sync-delivery.php</code>, also run by the worker). '
            . '<code>sent</code> = accepted by gateway; <code>delivered</code> = confirmed by the handset.</p>'
            . $filter . $summary . $export . $byKeyTable . $msgTable;

        return self::layout('Reports', '/admin/reports', $content, true);
    }

    /**
     * CSV export for the Reports page (same filters).
     *
     * @param list<array> $messages from SmsRepository::reportMessages
     */
    public static function reportsCsv(string $from, string $to, array $messages): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['id', 'app', 'to', 'status', 'gateway_state', 'client_ref', 'attempts', 'error', 'created_at', 'sent_at', 'delivered_at']);
        foreach ($messages as $m) {
            fputcsv($out, [
                $m['id'],
                $m['key_name'],
                $m['to_e164'],
                $m['status'],
                $m['gateway_state'],
                $m['client_ref'],
                $m['attempts'],
                $m['error'],
                $m['created_at'],
                $m['sent_at'],
                $m['delivered_at'],
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * Test / Playground page: config probe + send-as-consumer form.
     *
     * @param list<array> $keys active API keys
     * @param array|null $probe health body from App::probe()
     * @param array|null $result ['status'=>int,'body'=>array,'worker'=>string|null,'error'=>string|null]
     */
    public static function testPage(string $csrf, array $keys, ?array $probe, ?array $result): string
    {
        $warning = '<p class="hint"><strong>Warning:</strong> sending here dispatches a '
            . '<strong>real SMS</strong> when the gateway and worker are configured.</p>';

        // Section A — config probe.
        $probeHtml = '';
        if ($probe !== null) {
            $ok = (bool) ($probe['ok'] ?? false);
            $class = $ok ? 'ok' : 'error';
            $probeHtml = '<div class="' . $class . '">'
                . 'Database: <strong>' . e((string) ($probe['database'] ?? '?')) . '</strong> &middot; '
                . 'Gateway configured: <strong>' . e(!empty($probe['gateway_configured']) ? 'yes' : 'no') . '</strong> &middot; '
                . 'Gateway: <strong>' . e((string) ($probe['gateway'] ?? '?')) . '</strong>'
                . '</div>';
        }

        // Section B — send form.
        $keyOptions = '';
        foreach ($keys as $k) {
            $keyOptions .= '<option value="' . (int) $k['id'] . '">'
                . e((string) $k['name']) . ' (#' . (int) $k['id'] . ', ' . e((string) $k['key_prefix']) . '…)</option>';
        }
        if ($keyOptions === '') {
            $keyOptions = '<option value="">— no active keys —</option>';
        }

        $resultHtml = '';
        if ($result !== null) {
            if (isset($result['error'])) {
                $resultHtml = '<div class="error">' . e((string) $result['error']) . '</div>';
            } else {
                $json = json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $resultHtml = '<div class="' . (($result['status'] >= 200 && $result['status'] < 300) ? 'ok' : 'error') . '">'
                    . '<strong>HTTP ' . (int) $result['status'] . '</strong>'
                    . '<pre>' . e((string) $json) . '</pre>'
                    . ($result['worker'] !== null ? '<p>' . e((string) $result['worker']) . '</p>' : '')
                    . '<p><a href="' . AdminApp::url('/admin/messages') . '">View in Messages →</a></p></div>';
            }
        }

        $content = '<h1>Test / Playground</h1>' . $warning
            . '<h2>1. Check configuration</h2>'
            . '<form method="post" action="' . AdminApp::url('/admin/test/probe') . '" class="card narrow">'
            . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
            . '<button type="submit">Check configuration</button></form>' . $probeHtml
            . '<h2>2. Send test SMS (as a consumer app)</h2>'
            . '<form method="post" action="' . AdminApp::url('/admin/test/send') . '" class="card">'
            . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
            . '<label>API key (send attributed to this consumer)<select name="api_key_id">' . $keyOptions . '</select></label>'
            . '<label>To (phone)<input name="to" required placeholder="09171234567 or +639171234567"></label>'
            . '<label>Message<textarea name="message" rows="3" required maxlength="1000"></textarea></label>'
            . '<label>client_ref (optional idempotency)<input name="client_ref" placeholder="e.g. test-001"></label>'
            . '<label class="check"><input type="checkbox" name="run_worker" value="1"> Run worker once after enqueue</label>'
            . '<button type="submit">Send test SMS</button></form>' . $resultHtml;

        return self::layout('Test', '/admin/test', $content, true);
    }

    /**
     * Admin Docs page: multi-page tutorial with a sticky left side nav.
     *
     * @param array{id:string,title:string,html:string,prev:?string,next:?string} $guide
     */
    public static function docsPage(array $guide): string
    {
        $nav = '';
        foreach (AdminApp::GUIDE as $id => $title) {
            $num = array_search($id, array_keys(AdminApp::GUIDE), true) + 1;
            $class = $guide['id'] === $id ? ' class="active"' : '';
            $nav .= '<a href="' . AdminApp::url('/admin/docs') . '?page=' . $id . '"' . $class . '>'
                . '<span class="num">' . $num . '</span>' . e($title) . '</a>';
        }

        $pager = '';
        if ($guide['prev'] !== null || $guide['next'] !== null) {
            $pager .= '<nav class="pager">';
            $pager .= $guide['prev'] !== null
                ? '<a class="prev" href="' . AdminApp::url('/admin/docs') . '?page=' . $guide['prev'] . '">← ' . e((string) AdminApp::GUIDE[$guide['prev']]) . '</a>'
                : '<span></span>';
            $pager .= $guide['next'] !== null
                ? '<a class="next" href="' . AdminApp::url('/admin/docs') . '?page=' . $guide['next'] . '">' . e((string) AdminApp::GUIDE[$guide['next']]) . ' →</a>'
                : '<span></span>';
            $pager .= '</nav>';
        }

        $content = '<div class="docs-layout">'
            . '<aside class="side-nav"><h3>Integration guide</h3>' . $nav . '</aside>'
            . '<main class="docs card">' . $guide['html'] . $pager . '</main>'
            . '</div>';

        return self::layout('Docs', '/admin/docs', $content, true);
    }

    private static function css(): string
    {
        return <<<'CSS'
<style>
:root { font-family: system-ui, sans-serif; }
body { margin: 0; background: #f4f6f8; color: #1c2733; }
header { display: flex; align-items: center; gap: 24px; padding: 10px 20px; background: #10314f; color: #fff; }
header nav { display: flex; gap: 12px; align-items: center; flex: 1; }
header a { color: #cfe0ef; text-decoration: none; padding: 4px 8px; border-radius: 4px; }
header a.active, header a:hover { background: #1d4a75; color: #fff; }
.logout button { background: transparent; border: 1px solid #5b7d9e; color: #cfe0ef; border-radius: 4px; padding: 4px 10px; cursor: pointer; }
main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
.card { background: #fff; border: 1px solid #dbe2e8; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; display: grid; gap: 12px; }
.card.narrow { max-width: 420px; }
label { display: grid; gap: 4px; font-size: 14px; font-weight: 600; }
input { padding: 7px 9px; border: 1px solid #b9c4cd; border-radius: 5px; font-size: 14px; width: 100%; box-sizing: border-box; }
button { background: #1660a8; color: #fff; border: 0; border-radius: 5px; padding: 8px 14px; font-size: 14px; cursor: pointer; justify-self: start; }
button.danger { background: #a83232; }
small { font-weight: 400; color: #5b6b7a; }
.ok { background: #e5f5e8; border: 1px solid #9fd4ab; padding: 10px 12px; border-radius: 6px; }
.error { background: #fbeaea; border: 1px solid #e0a3a3; padding: 10px 12px; border-radius: 6px; color: #7d2020; }
.hint { color: #5b6b7a; font-size: 13px; }
table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #dbe2e8; border-radius: 8px; font-size: 13px; }
th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e8edf1; vertical-align: top; }
th { background: #eef2f6; }
.status { padding: 2px 8px; border-radius: 10px; font-size: 12px; }
.s-sent { background: #e5f5e8; } .s-delivered { background: #c9ecd2; font-weight: 600; } .s-queued { background: #fdf3dc; } .s-sending { background: #e3eefb; } .s-failed { background: #fbeaea; }
code { background: #eef2f6; padding: 2px 5px; border-radius: 4px; word-break: break-all; }
form.logout { margin: 0; }
textarea { padding: 7px 9px; border: 1px solid #b9c4cd; border-radius: 5px; font-size: 14px; width: 100%; box-sizing: border-box; font-family: inherit; }
label.check { display: flex; gap: 8px; align-items: center; font-weight: 400; }
label.check input { width: auto; }
pre { background: #eef2f6; padding: 10px; border-radius: 6px; overflow-x: auto; font-size: 12px; margin: 8px 0; }
.tabs { display: flex; gap: 8px; margin-bottom: 12px; }
.tabs a { text-decoration: none; padding: 6px 14px; border-radius: 6px; background: #e3eaf1; color: #1c2733; font-size: 14px; }
.tabs a.active { background: #1660a8; color: #fff; }
.docs h1, .docs h2, .docs h3, .docs h4 { margin: 18px 0 8px; line-height: 1.3; }
.docs h1 { font-size: 22px; border-bottom: 2px solid #dbe2e8; padding-bottom: 6px; }
.docs h2 { font-size: 18px; }
.docs p, .docs li { font-size: 14px; line-height: 1.55; }
.docs pre { background: #10263c; color: #dce8f4; }
.docs pre code { background: transparent; color: inherit; padding: 0; }
.card.filter { grid-template-columns: 1fr 1fr auto; align-items: end; max-width: 560px; }
.button-link { display: inline-block; padding: 7px 14px; background: #1660a8; color: #fff; border-radius: 6px; text-decoration: none; font-size: 14px; }
.docs-layout { display: grid; grid-template-columns: 240px 1fr; gap: 16px; align-items: start; }
.side-nav { position: sticky; top: 12px; background: #fff; border: 1px solid #dbe2e8; border-radius: 8px; padding: 10px 0; }
.side-nav h3 { margin: 4px 14px 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #5b6b7a; }
.side-nav a { display: flex; gap: 8px; align-items: baseline; padding: 6px 14px; text-decoration: none; color: #1c2733; font-size: 13.5px; }
.side-nav a:hover { background: #eef2f6; }
.side-nav a.active { background: #1660a8; color: #fff; }
.side-nav a .num { display: inline-block; min-width: 18px; font-size: 11px; color: #5b6b7a; }
.side-nav a.active .num { color: #cfe0f2; }
.pager { display: flex; justify-content: space-between; margin-top: 22px; padding-top: 12px; border-top: 1px solid #dbe2e8; }
.pager a { color: #1660a8; text-decoration: none; font-size: 14px; }
@media (max-width: 800px) { .docs-layout { grid-template-columns: 1fr; } .side-nav { position: static; } }
@media (max-width: 640px) { .card.filter { grid-template-columns: 1fr; } }
</style>
CSS;
    }
}
