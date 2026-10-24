<?php
declare(strict_types=1);

namespace App;

/** Secure PHP session for the admin area, plus CSRF protection. */
final class Session
{
    private const IDLE_SECONDS     = 7200;     // signed out after 2 hours of no activity
    private const ABSOLUTE_SECONDS = 43200;    // and always after 12 hours

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('oga_admin');
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
        $expired = isset($_SESSION['created'])
            && (($now - ($_SESSION['last'] ?? $now)) > self::IDLE_SECONDS
                || ($now - $_SESSION['created']) > self::ABSOLUTE_SECONDS);
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