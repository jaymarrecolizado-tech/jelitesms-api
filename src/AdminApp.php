<?php

namespace Jelite;

/**
 * Admin UI controller. Session is an injected array (see AdminAuth) so the
 * class is testable without PHP session machinery.
 *
 * @return array{status:int,body:string,location?:string}
 */
class AdminApp
{
    /** Install base path (e.g. "/projects/jelite_sms_api"); set by index.php. */
    public static string $basePath = '';

    public static function url(string $path): string
    {
        return self::$basePath . $path;
    }

    /** @var array<string,array{label:string,type:string,help?:string,writeonly?:bool}> */
    private const SETTING_FIELDS = [
        'SMS_GATEWAY_URL' => ['label' => 'Gateway base URL', 'type' => 'text'],
        'SMS_GATEWAY_USERNAME' => ['label' => 'Gateway username', 'type' => 'text'],
        'SMS_GATEWAY_PASSWORD' => ['label' => 'Gateway password', 'type' => 'password', 'writeonly' => true],
        'SMS_API_PATH' => ['label' => 'Gateway API path', 'type' => 'text'],
        'SMS_TIMEOUT_SECONDS' => ['label' => 'Timeout (seconds)', 'type' => 'number'],
        'SMS_DEFAULT_COUNTRY_CODE' => ['label' => 'Default country code', 'type' => 'number'],
        'SMS_MAX_MESSAGE_LENGTH' => ['label' => 'Max message length', 'type' => 'number'],
        'SMS_MAX_ATTEMPTS' => ['label' => 'Max send attempts', 'type' => 'number'],
        'WORKER_BATCH_SIZE' => ['label' => 'Worker batch size', 'type' => 'number'],
        'APP_URL' => ['label' => 'App URL', 'type' => 'text'],
    ];

    public function __construct(
        private ApiKeyRepository $apiKeys,
        private SmsRepository $sms,
        private SettingsRepository $settings,
        private \PDO $db,
        private ?App $app = null,
        private ?\Closure $drain = null
    ) {
        $this->app ??= App::fromConfig();
    }

    public static function fromConfig(): self
    {
        $db = Database::pdo();
        return new self(new ApiKeyRepository($db), new SmsRepository($db), new SettingsRepository($db), $db);
    }

    public function handle(array &$session, string $method, string $path, array $post = [], array $query = []): array
    {
        try {
            return $this->route($session, $method, $path, $post, $query);
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'body' => AdminViews::layout('Error', '', '<h1>Error</h1><p class="error">Something went wrong.</p>', AdminAuth::check($session)),
            ];
        }
    }

    private function route(array &$session, string $method, string $path, array $post, array $query): array
    {
        $csrf = AdminAuth::csrfToken($session);

        if ($path === '/admin' || $path === '/admin/') {
            return AdminAuth::check($session)
                ? self::redirect(self::url('/admin/settings'))
                : self::html(200, AdminViews::loginPage($csrf, null));
        }

        if ($path === '/admin/login' && $method === 'POST') {
            return $this->login($session, $post, $csrf);
        }

        if ($path === '/admin/logout' && $method === 'POST') {
            if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
                return self::html(403, AdminViews::layout('Forbidden', '', '<h1>403</h1><p class="error">Invalid CSRF token.</p>', false));
            }
            AdminAuth::logout($session);
            return self::redirect(self::url('/admin'));
        }

        // Everything below requires a logged-in admin.
        if (!AdminAuth::check($session)) {
            return self::redirect(self::url('/admin'));
        }

        switch ("{$method} {$path}") {
            case 'GET /admin/settings':
                return self::html(200, AdminViews::settingsPage($csrf, $this->settingFields(), $this->takeFlash($session, 'settings_flash')));

            case 'POST /admin/settings':
                return $this->saveSettings($session, $post);

            case 'GET /admin/keys':
                $createdKey = $this->takeFlash($session, 'created_key');
                return self::html(200, AdminViews::keysPage($csrf, $this->apiKeys->list(), $createdKey));

            case 'POST /admin/keys/create':
                return $this->createKey($session, $post);

            case 'POST /admin/keys/revoke':
                if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
                    return self::forbidden($session);
                }
                $this->apiKeys->revoke((int) ($post['id'] ?? 0));
                return self::redirect(self::url('/admin/keys'));

            case 'GET /admin/messages':
                return self::html(200, AdminViews::messagesPage($this->sms->recentWithKeyNames(50)));

            case 'GET /admin/usage':
                [$from, $to] = self::parseDateRange($query);
                return self::html(200, AdminViews::usagePage($from, $to, $this->sms->usageByKey($from, $to)));

            case 'GET /admin/reports':
                return self::reportsPage($query);

            case 'GET /admin/reports/export':
                return $this->reportsCsv($query);

            case 'GET /admin/test':
                return self::html(200, AdminViews::testPage($csrf, $this->activeKeys(), null, null));

            case 'GET /admin/docs':
                return self::html(200, AdminViews::docsPage(self::resolveGuidePage($query['page'] ?? $query['doc'] ?? 'welcome')));

            case 'POST /admin/test/probe':
                if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
                    return self::forbidden($session);
                }
                return self::html(200, AdminViews::testPage($csrf, $this->activeKeys(), $this->app->probe(), null));

            case 'POST /admin/test/send':
                return $this->sendTestSms($session, $post);

            default:
                return self::html(404, AdminViews::layout('Not found', '', '<h1>404</h1><p>Unknown admin page.</p>', true));
        }
    }

    /**
     * Delivery reports page (Phase 5.8). Filters: from/to dates, API key, status.
     */
    private function reportsPage(array $query): array
    {
        [$from, $to] = self::parseDateRange($query);
        $keyId = isset($query['api_key_id']) && $query['api_key_id'] !== '' ? (int) $query['api_key_id'] : null;
        $status = is_string($query['status'] ?? null) && $query['status'] !== '' ? $query['status'] : null;

        return self::html(200, AdminViews::reportsPage(
            $from,
            $to,
            $this->apiKeys->list(),
            $keyId,
            $status,
            $this->sms->reportTotals($from, $to, $keyId, $status),
            $this->sms->reportByKey($from, $to, $keyId, $status),
            $this->sms->reportMessages($from, $to, $keyId, $status)
        ));
    }

    private function reportsCsv(array $query): array
    {
        [$from, $to] = self::parseDateRange($query);
        $keyId = isset($query['api_key_id']) && $query['api_key_id'] !== '' ? (int) $query['api_key_id'] : null;
        $status = is_string($query['status'] ?? null) && $query['status'] !== '' ? $query['status'] : null;

        return [
            'status' => 200,
            'body' => AdminViews::reportsCsv($from, $to, $this->sms->reportMessages($from, $to, $keyId, $status)),
            'content_type' => 'text/csv; charset=utf-8',
        ];
    }

    private function login(array &$session, array $post, string $csrf): array
    {
        if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
            return self::html(403, AdminViews::layout('Forbidden', '', '<h1>403</h1><p class="error">Invalid CSRF token.</p>', false));
        }

        // Simple throttle: repeated failures get progressively slower.
        $fails = (int) ($session['login_fails'] ?? 0);
        if ($fails >= 3) {
            sleep(min(5, $fails - 2));
        }

        if (!AdminAuth::login($session, trim((string) ($post['username'] ?? '')), (string) ($post['password'] ?? ''))) {
            $session['login_fails'] = $fails + 1;
            return self::html(401, AdminViews::loginPage($csrf, 'Invalid username or password.'));
        }

        return self::redirect(self::url('/admin/settings'));
    }

    /**
     * @return array<string,array{label:string,value:string,type:string,help?:string,writeonly?:bool}>
     */
    private function settingFields(): array
    {
        $fields = [];
        foreach (self::SETTING_FIELDS as $name => $meta) {
            $fields[$name] = [
                'label' => $meta['label'],
                'type' => $meta['type'],
                'value' => $meta['writeonly'] ?? false ? '' : Config::get($name),
                'writeonly' => $meta['writeonly'] ?? false,
            ];
            if ($meta['writeonly'] ?? false) {
                $fields[$name]['help'] = 'Leave blank to keep the current password.';
            }
        }
        return $fields;
    }

    private function saveSettings(array &$session, array $post): array
    {
        if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
            return self::forbidden($session);
        }

        $errors = [];
        foreach (self::SETTING_FIELDS as $name => $meta) {
            $value = trim((string) ($post[$name] ?? ''));
            if ($value === '') {
                continue; // blank = leave unchanged (also covers write-only password)
            }
            if ($meta['type'] === 'number' && !ctype_digit($value)) {
                $errors[] = "{$name} must be a non-negative whole number";
                continue;
            }
            $this->settings->set($name, $value);
        }

        if ($errors !== []) {
            $errorHtml = '<div class="error">' . implode('<br>', array_map(fn (string $e2) => htmlspecialchars($e2, ENT_QUOTES), $errors)) . '</div>';
            return self::html(422, $errorHtml . AdminViews::settingsPage(AdminAuth::csrfToken($session), $this->settingFields(), null));
        }

        Config::loadDbOverrides($this->db); // make new values effective immediately
        $session['settings_flash'] = 'Settings saved.';
        return self::redirect(self::url('/admin/settings'));
    }

    private function createKey(array &$session, array $post): array
    {
        if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
            return self::forbidden($session);
        }

        $name = trim((string) ($post['name'] ?? ''));
        $rate = (int) ($post['rate'] ?? 30);
        if ($name === '' || strlen($name) > 100 || $rate < 1) {
            return self::html(422, AdminViews::keysPage(AdminAuth::csrfToken($session), $this->apiKeys->list(), null));
        }

        $key = $this->apiKeys->create($name, $rate);
        $session['created_key'] = $key['key']; // shown once on the next page render
        return self::redirect(self::url('/admin/keys'));
    }

    /**
     * Send-as-consumer: identical validation/enqueue path as the public API,
     * attributed to the selected active key (rate limit + ownership included).
     */
    private function sendTestSms(array &$session, array $post): array
    {
        if (!AdminAuth::verifyCsrf($session, $post['csrf'] ?? null)) {
            return self::forbidden($session);
        }

        $keyRow = $this->apiKeys->find((int) ($post['api_key_id'] ?? 0));
        if ($keyRow === null) {
            return self::html(422, AdminViews::testPage(AdminAuth::csrfToken($session), $this->activeKeys(), null, [
                'error' => 'Selected API key does not exist or is revoked.',
            ]));
        }

        $payload = json_encode(array_filter([
            'to' => (string) ($post['to'] ?? ''),
            'message' => (string) ($post['message'] ?? ''),
            'client_ref' => trim((string) ($post['client_ref'] ?? '')) ?: null,
        ], static fn ($v) => $v !== null));

        $result = $this->app->sendRaw($keyRow, $payload);

        $workerNote = null;
        if (!empty($post['run_worker']) && $result['status'] === 202) {
            $processed = $this->drain !== null ? ($this->drain)() : Worker::drain();
            $workerNote = $processed === -1
                ? 'Worker skipped: gateway not configured.'
                : "Worker ran once: {$processed} message(s) processed.";
        }

        return self::html(200, AdminViews::testPage(
            AdminAuth::csrfToken($session),
            $this->activeKeys(),
            null,
            ['status' => $result['status'], 'body' => $result['body'], 'worker' => $workerNote]
        ));
    }

    /**
     * Tutorial guide chapters (docs/guide/*.md). Order defines the side nav.
     * Allowlisted ids only — no path traversal; ops runbooks (docs/ops/) are
     * never exposed here.
     */
    public const GUIDE = [
        'welcome' => 'Welcome & prerequisites',
        'api-key' => 'Create an API key',
        'first-sms' => 'Your first SMS',
        'plain-php' => 'Plain PHP from scratch',
        'laravel' => 'Laravel from scratch',
        'codeigniter' => 'CodeIgniter from scratch',
        'react-laravel-bff' => 'React + Laravel BFF',
        'react-node-bff' => 'React + Node BFF',
        'troubleshooting' => 'Troubleshooting',
        'next-steps' => 'Next steps',
    ];

    /** Legacy single-page docs (?doc=consumers) map into the guide. */
    private const LEGACY_DOCS = [
        'consumers' => 'welcome',
        'deploy' => 'welcome',
    ];

    /**
     * Resolve the requested guide page. Unknown/legacy/traversal ids fall
     * back to the welcome chapter.
     *
     * @return array{id:string,title:string,html:string,prev:?string,next:?string}
     */
    public static function resolveGuidePage(string $requested): array
    {
        $requested = self::LEGACY_DOCS[$requested] ?? $requested;
        $id = isset(self::GUIDE[$requested]) ? $requested : 'welcome';

        $path = dirname(__DIR__) . '/docs/guide/' . $id . '.md';
        $markdown = is_file($path) ? (string) file_get_contents($path) : null;

        if ($markdown === null || $markdown === '') {
            return ['id' => $id, 'title' => self::GUIDE[$id], 'html' => '<p class="error">Chapter not found on disk.</p>', 'prev' => null, 'next' => null];
        }

        $ids = array_keys(self::GUIDE);
        $pos = array_search($id, $ids, true);

        return [
            'id' => $id,
            'title' => self::GUIDE[$id],
            'html' => Markdown::toHtml($markdown),
            'prev' => $pos > 0 ? $ids[$pos - 1] : null,
            'next' => $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : null,
        ];
    }

    /**
     * @return list<array> active keys only (id, name, prefix)
     */
    private function activeKeys(): array
    {
        return array_values(array_filter(
            $this->apiKeys->list(),
            static fn (array $k): bool => (bool) $k['active']
        ));
    }

    private function takeFlash(array &$session, string $key): ?string
    {
        if (isset($session[$key])) {
            $value = (string) $session[$key];
            unset($session[$key]);
            return $value;
        }
        return null;
    }

    /**
     * Inclusive Y-m-d range for Usage. Defaults to the last 7 days (today inclusive).
     *
     * @return array{0:string,1:string}
     */
    private static function parseDateRange(array $query): array
    {
        $today = date('Y-m-d');
        $from = self::validDate($query['from'] ?? null) ?? date('Y-m-d', strtotime('-6 days'));
        $to = self::validDate($query['to'] ?? null) ?? $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // Cap range at 366 days so a bad filter cannot scan forever.
        $maxFrom = date('Y-m-d', strtotime($to . ' -365 days'));
        if ($from < $maxFrom) {
            $from = $maxFrom;
        }
        return [$from, $to];
    }

    private static function validDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt && $dt->format('Y-m-d') === $value ? $value : null;
    }

    private static function forbidden(array $session): array
    {
        return self::html(403, AdminViews::layout('Forbidden', '', '<h1>403</h1><p class="error">Invalid CSRF token.</p>', AdminAuth::check($session)));
    }

    private static function redirect(string $location): array
    {
        return ['status' => 302, 'body' => '', 'location' => $location];
    }

    private static function html(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
