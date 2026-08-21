<?php

namespace Jelite;

/**
 * Session-array based admin auth (session array is injected so tests need no
 * PHP session machinery). Credentials bootstrap from ADMIN_USER/ADMIN_PASSWORD.
 */
class AdminAuth
{
    public static function check(array $session): bool
    {
        return !empty($session['admin_user']);
    }

    public static function login(array &$session, string $user, string $password): bool
    {
        $expectedUser = Config::get('ADMIN_USER');
        $expectedPass = Config::get('ADMIN_PASSWORD');

        // Admin disabled until both are configured.
        if ($expectedUser === '' || $expectedPass === '') {
            return false;
        }

        if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $password)) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $session = ['admin_user' => $expectedUser] + $session;
        unset($session['login_fails']);
        return true;
    }

    public static function logout(array &$session): void
    {
        unset($session['admin_user']);
    }

    public static function csrfToken(array &$session): string
    {
        if (!isset($session['csrf']) || !is_string($session['csrf']) || $session['csrf'] === '') {
            $session['csrf'] = bin2hex(random_bytes(16));
        }
        return $session['csrf'];
    }

    public static function verifyCsrf(array $session, ?string $token): bool
    {
        return is_string($token) && $token !== ''
            && isset($session['csrf'])
            && hash_equals($session['csrf'], $token);
    }
}
