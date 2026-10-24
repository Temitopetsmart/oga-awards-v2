<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private const MAX_FAILS_PER_EMAIL = 5;     // within the window below
    private const MAX_FAILS_PER_IP    = 30;
    private const WINDOW_MINUTES      = 15;

    /** A real hash of a random string: checked when the email is unknown so timing doesn't reveal which emails exist. */
    private const DUMMY_HASH = '$2y$12$4W4MtJ6F8ftxs8HaPgNGjOceZORSvdLwtGOzgUsvvq5xM1M750pkS';

    private static ?array $cached = null;
    private static bool $loaded = false;

    /** The signed-in person (re-checked in the database on every request), or null. */
    public static function person(): ?array
    {
        if (self::$loaded) {
            return self::$cached;
        }
        self::$loaded = true;
        $id = Session::personId();
        if ($id === null) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, email, active, is_super_admin, photo_path FROM people WHERE id = ? AND active = 1'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            Session::destroy();             // deactivated or removed: sign them out right away
            return null;
        }
        return self::$cached = $row;
    }

    /** Check email + password. Returns the person row, or null. Stops with 429 on too many attempts. */
    public static function attempt(string $email, string $password): ?array
    {
        $pdo   = Database::pdo();
        $email = mb_strtolower(trim($email));
        $ident = hash('sha256', $email);
        $ip    = Request::ip();

        $count = function (string $col, string $val) use ($pdo): int {
            $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts
                                  WHERE $col = ? AND success = 0
                                    AND attempted_at > (NOW() - INTERVAL " . self::WINDOW_MINUTES . ' MINUTE)');
            $st->execute([$val]);
            return (int) $st->fetchColumn();
        };
        if ($count('identifier', $ident) >= self::MAX_FAILS_PER_EMAIL || $count('ip_address', $ip) >= self::MAX_FAILS_PER_IP) {
            Response::error('Too many attempts. Please wait 15 minutes and try again.', 429);
        }

        $st = $pdo->prepare(
            'SELECT id, name, email, active, is_super_admin, photo_path, password_hash
               FROM people WHERE email = ? AND active = 1 AND password_hash IS NOT NULL'
        );
        $st->execute([$email]);
        $row = $st->fetch();

        $valid = password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH) && $row !== false;
        $allowed = $valid && (
            (int) $row['is_super_admin'] === 1 || Access::editionIds($row) !== []
        );

        $pdo->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)')
            ->execute([$ident, $ip, $allowed ? 1 : 0]);

        if (!$allowed) {
            Audit::log($valid ? (int) $row['id'] : null, 'Sign-in refused', $valid ? 'no access to any edition' : null);
            return null;
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $pdo->prepare('UPDATE people SET password_hash = ? WHERE id = ?')
                ->execute([self::hash($password), $row['id']]);
        }
        $pdo->prepare('UPDATE people SET last_login_at = NOW() WHERE id = ?')->execute([$row['id']]);
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? AND success = 0')->execute([$ident]);

        unset($row['password_hash']);
        Session::signIn((int) $row['id']);
        self::$cached = $row;
        self::$loaded = true;
        Audit::log((int) $row['id'], 'Signed in');
        return $row;
    }

    public static function logout(): void
    {
        $p = self::person();
        if ($p) {
            Audit::log((int) $p['id'], 'Signed out');
        }
        Session::destroy();
        self::$cached = null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
