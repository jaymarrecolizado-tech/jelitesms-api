<?php

namespace Jelite;

class Config
{
    private static ?array $values = null;
    private static ?array $overrides = null;

    /** Keys the Admin UI may override from the app_settings table. */
    private const OVERRIDABLE = [
        'APP_URL',
        'SMS_GATEWAY_URL',
        'SMS_GATEWAY_USERNAME',
        'SMS_GATEWAY_PASSWORD',
        'SMS_API_PATH',
        'SMS_TIMEOUT_SECONDS',
        'SMS_DEFAULT_COUNTRY_CODE',
        'SMS_MAX_MESSAGE_LENGTH',
        'SMS_MAX_ATTEMPTS',
        'WORKER_BATCH_SIZE',
    ];

    public static function load(string $envFile): void
    {
        $values = [];
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                    $value = substr($value, 1, -1);
                }
                $values[$name] = $value;
            }
        }

        // Real environment variables win over .env file values.
        self::$values = array_merge($values, getenv());
    }

    public static function get(string $name, string $default = ''): string
    {
        if (self::$values === null) {
            self::load(dirname(__DIR__) . '/.env');
        }

        // DB override (Admin UI) wins for allowlisted keys; never for DB_*/ADMIN_*.
        if (self::$overrides !== null && isset(self::$overrides[$name]) && self::$overrides[$name] !== '') {
            return self::$overrides[$name];
        }

        return isset(self::$values[$name]) && self::$values[$name] !== '' ? self::$values[$name] : $default;
    }

    /**
     * Load allowlisted overrides from app_settings. Safe to call repeatedly;
     * uses the given PDO directly so it cannot recurse into Database::pdo().
     */
    public static function loadDbOverrides(\PDO $pdo): void
    {
        try {
            $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
            $overrides = [];
            foreach ($rows ?: [] as $key => $value) {
                if (in_array($key, self::OVERRIDABLE, true) && $value !== null && $value !== '') {
                    $overrides[$key] = (string) $value;
                }
            }
            self::$overrides = $overrides;
        } catch (\Throwable) {
            // Table missing (pre-migration) or DB hiccup → fall back to env only.
            self::$overrides = [];
        }
    }

    public static function int(string $name, int $default): int
    {
        $v = self::get($name);
        return $v !== '' && is_numeric($v) ? (int) $v : $default;
    }

    public static function env(): string
    {
        return self::get('APP_ENV', 'dev');
    }

    /**
     * Database name for the current environment. Hostinger (and similar
     * hosts) force a username prefix on DB names, so an explicit `DB_NAME`
     * always wins; otherwise the name is derived from APP_ENV.
     */
    public static function dbName(): string
    {
        $explicit = self::get('DB_NAME');
        if ($explicit !== '') {
            return $explicit;
        }
        return 'jelite_sms_api_' . self::env();
    }
}
