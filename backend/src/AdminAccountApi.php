<?php
declare(strict_types=1);

namespace App;

/** Phase 18: an admin's own account: change password, forgot password, sign out everywhere, display name. */
final class AdminAccountApi
{
    private const RESET_MINUTES = 60;

    private static function fail(string $message, int $status = 422, array $extra = []): never
    {
        Response::error($message, $status, $extra['fields'] ?? $extra);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function record(string $kind, string $who, bool $success = false): void
    {
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)')
            ->execute([hash('sha256', "$kind:$who"), Request::ip(), $success ? 1 : 0]);
    }

    private static function count(string $kind, string $who, int $minutes, bool $failuresOnly = false): int
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)' . ($failuresOnly ? ' AND success = 0' : ''));
        $st->execute([hash('sha256', "$kind:$who")]);
        return (int) $st->fetchColumn();
    }

    /** The same rule as accepting an invitation. */
    private static function checkPassword(string $pw): void
    {
        if (mb_strlen($pw) < 10) {
            self::fail('Choose a password of at least 10 characters.', 422, ['fields' => ['password' => 'Use at least 10 characters.']]);
        }
        if (strlen($pw) > 72) {
            self::fail('Use a password of at most 72 characters.', 422, ['fields' => ['password' => 'At most 72 characters.']]);
        }
    }

    // ------------------------------------------------------------ public: forgot and reset

    /** Always answers the same, so nobody can use it to find out which emails are admins. */
    public static function forgot(): never
    {
        $in = Request::json();
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (self::count('admin-forgot-ip', Request::ip(), 60) >= 20) {
            self::fail('Too many attempts. Please wait a while and try again.', 429);
        }
        self::record('admin-forgot-ip', Request::ip(), true);          // not a sign-in failure: must not count towards locking out sign-ins
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $st = self::pdo()->prepare('SELECT id, email FROM people WHERE email = ? AND active = 1 AND password_hash IS NOT NULL');
            $st->execute([$email]);
            if ($p = $st->fetch()) {
                self::sendReset((int) $p['id'], $p['email'], false);
            }
        }
        Response::json(['ok' => true, 'message' => 'If that email belongs to an admin, a reset link is on its way. It works for ' . self::RESET_MINUTES . ' minutes.']);
    }

    /** Makes a link and queues the email. Quietly does nothing if 3 links were already made for this person in the last hour. */
    public static function sendReset(int $personId, string $email, bool $force): bool
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM admin_tokens WHERE person_id = ? AND created_at > (NOW() - INTERVAL 60 MINUTE)');
        $st->execute([$personId]);
        if (!$force && (int) $st->fetchColumn() >= 3) {
            return false;
        }
        $token = bin2hex(random_bytes(32));
        self::pdo()->prepare('INSERT INTO admin_tokens (person_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . self::RESET_MINUTES . ' MINUTE)')
            ->execute([$personId, hash('sha256', $token)]);
        Outbox::queueAndSend('admin_password_reset', $email, ['token' => $token, 'expiresMinutes' => self::RESET_MINUTES]);
        return true;
    }

    public static function reset(): never
    {
        $in = Request::json();
        $token = (string) ($in['token'] ?? '');
        $password = (string) ($in['password'] ?? '');
        if (self::count('admin-reset-ip', Request::ip(), 60) >= 30) {
            self::fail('Too many attempts. Please wait a while and try again.', 429);
        }
        self::record('admin-reset-ip', Request::ip(), true);
        $st = self::pdo()->prepare('SELECT t.id, t.person_id, p.email FROM admin_tokens t JOIN people p ON p.id = t.person_id
                                     WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW() AND p.active = 1');
        $st->execute([hash('sha256', $token)]);
        $row = $st->fetch();
        if (!$row) {
            self::fail('This link is not valid any more. Ask for a new one.');
        }
        self::checkPassword($password);
        Database::transaction(function (\PDO $db) use ($row, $password) {
            $db->prepare('UPDATE people SET password_hash = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?')->execute([Auth::hash($password), $row['person_id']]);
            $db->prepare('UPDATE admin_tokens SET used_at = NOW() WHERE person_id = ? AND used_at IS NULL')->execute([$row['person_id']]);   // this link and any others
        });
        Audit::log((int) $row['person_id'], 'Reset their admin password');
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------ signed in

    /** Every other sign-in has just been ended by raising the version: this one adopts the new version and carries on. */
    private static function keepThisSignIn(int $personId): void
    {
        $st = self::pdo()->prepare('SELECT session_version FROM people WHERE id = ?');
        $st->execute([$personId]);
        Session::setVersion((int) $st->fetchColumn());
    }

    /** Changes the password. Every other sign-in of this person stops working; this one carries on. */
    public static function changePassword(array $me): never
    {
        $in = Request::json();
        $current = (string) ($in['current'] ?? '');
        $new = (string) ($in['password'] ?? '');
        if (self::count('admin-change', (string) $me['id'], 15, true) >= 5) {
            self::fail('Too many wrong attempts. Please wait a while and try again.', 429);
        }
        $st = self::pdo()->prepare('SELECT password_hash FROM people WHERE id = ?');
        $st->execute([$me['id']]);
        if (!password_verify($current, (string) $st->fetchColumn())) {
            self::record('admin-change', (string) $me['id']);
            self::fail('Your current password is not right.', 422, ['fields' => ['current' => 'Not right.']]);
        }
        self::checkPassword($new);
        if (hash_equals($current, $new)) {
            self::fail('Choose a different password from the current one.', 422, ['fields' => ['password' => 'Choose a new one.']]);
        }
        self::pdo()->prepare('UPDATE people SET password_hash = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?')->execute([Auth::hash($new), $me['id']]);
        self::keepThisSignIn((int) $me['id']);
        Audit::log((int) $me['id'], 'Changed their admin password');
        Response::json(['ok' => true]);
    }

    public static function signOutEverywhere(array $me): never
    {
        self::pdo()->prepare('UPDATE people SET password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?')->execute([$me['id']]);
        self::keepThisSignIn((int) $me['id']);
        Audit::log((int) $me['id'], 'Signed out of every device');
        Response::json(['ok' => true]);
    }

    public static function updateProfile(array $me): never
    {
        $in = Request::json();
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || preg_match('/[\x00-\x1F\x7F<>]/', $name)) {
            self::fail('Enter your full name (2 to 150 characters, no special symbols).', 422, ['fields' => ['name' => 'Enter your full name.']]);
        }
        self::pdo()->prepare('UPDATE people SET name = ? WHERE id = ?')->execute([$name, $me['id']]);
        Audit::log((int) $me['id'], 'Changed their display name', $name);
        Response::json(['ok' => true]);
    }

    /** A Super admin sends a reset link to someone who can already sign in (and has forgotten how). */
    public static function adminSendsReset(array $actor, int $personId): never
    {
        if ((int) $actor['is_super_admin'] !== 1) {
            self::fail('Only a Super admin can do this.', 403);
        }
        $st = self::pdo()->prepare('SELECT id, name, email, active, password_hash FROM people WHERE id = ?');
        $st->execute([$personId]);
        $p = $st->fetch() ?: self::fail('That person was not found.', 404);
        if ($p['email'] === null || !$p['active'] || $p['password_hash'] === null) {
            self::fail('This person has no active sign-in. Send them an invitation instead.');
        }
        self::sendReset((int) $p['id'], $p['email'], true);
        Audit::log((int) $actor['id'], 'Sent a password reset link', $p['name']);
        Response::json(['ok' => true]);
    }
}