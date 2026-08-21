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

    public function handle(array &$session, string $method, string $path, array $post = []): array
    {
        try {
            return $this->route($session, $method, $path, $post);
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'body' => AdminViews::layout('Error', '', '<h1>Error</h1><p class="error">Something went wrong.</p>', AdminAuth::check($session)),
            ];
        }
    }

    private function route(array &$session, string $method, string $path, array $post): array
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

            case 'GET /admin/test':
                return self::html(200, AdminViews::testPage($csrf, $this->activeKeys(), null, null));

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
