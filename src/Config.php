<?php

namespace Jelite;

class Config
{
    private static ?array $values = null;

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
        return isset(self::$values[$name]) && self::$values[$name] !== '' ? self::$values[$name] : $default;
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

    public static function dbName(): string
    {
        return 'jelite_sms_api_' . self::env();
    }
}
