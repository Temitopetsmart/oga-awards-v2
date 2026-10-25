<?php
declare(strict_types=1);

namespace App;

/** Secure PHP session plus CSRF protection, for both the admin and nominee sign-ins (separate cookies). */
final class Session
{
    /** 'admin' (default) or 'nominee': separate cookie names and time limits, so the two never mix. */
    private static string $scope = 'admin';
    private const LIMITS = [
        'admin'   => ['name' => 'oga_admin',   'idle' => 7200,  'absolute' => 43200],    // 2 h idle, 12 h max
        'nominee' => ['name' => 'oga_nominee', 'idle' => 43200, 'absolute' => 604800],   // 12 h idle, 7 days max
    ];

    public static function useScope(string $scope): void
    {
        self::$scope = isset(self::LIMITS[$scope]) ? $scope : 'admin';
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name(self::LIMITS[self::$scope]['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,            // JavaScript can never read the session cookie
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        $now = time();
        $limits = self::LIMITS[self::$scope];
        $expired = isset($_SESSION['created'])
            && (($now - ($_SESSION['last'] ?? $now)) > $limits['idle']
                || ($now - $_SESSION['created']) > $limits['absolute']);
        if ($expired) {
            self::destroy();
            session_start();
        }
        $_SESSION['created'] ??= $now;
        $_SESSION['last'] = $now;
    }

    public static function csrf(): string
    {
        self::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /** Every request that changes data must send the token in the X-CSRF-Token header. */
    public static function requireCsrf(): void
    {
        self::start();
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $real = $_SESSION['csrf'] ?? '';
        if ($real === '' || !hash_equals($real, $sent)) {
            Response::error('Your session expired. Please refresh the page and try again.', 419);
        }
    }

    public static function signIn(int $personId): void
    {
        self::start();
        session_regenerate_id(true);       // a new session id at login stops session fixation
        $_SESSION['pid']  = $personId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], true);
        }
        session_destroy();
    }

    public static function personId(): ?int
    {
        self::start();
        return isset($_SESSION['pid']) ? (int) $_SESSION['pid'] : null;
    }
}