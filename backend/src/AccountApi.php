<?php
declare(strict_types=1);

namespace App;

/** Nominee accounts: register, sign in, my entries, profile and photo, passwords, email verification. */
final class AccountApi
{
    private const LEVELS = ['ND I', 'ND II', 'HND I', 'HND II'];
    private const TOKEN_MINUTES = 30;

    private static function fail(string $message, int $status = 422, array $extra = []): never
    {
        Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    // -------------------------------------------------------------- routing

    public static function handle(string $method, string $path): never
    {
        Session::useScope('nominee');
        header('Cache-Control: no-store');
        if ($method !== 'GET') {
            Session::requireCsrf();
        }

        if ($method === 'GET' && $path === '/nominee/csrf')      Response::json(['ok' => true, 'csrf' => Session::csrf()]);
        if ($method === 'GET' && $path === '/nominee/me')        self::me();
        if ($method === 'POST' && $path === '/nominee/register') self::register();
        if ($method === 'POST' && $path === '/nominee/login')    self::login();
        if ($method === 'POST' && $path === '/nominee/logout')   self::logout();
        if ($method === 'POST' && $path === '/nominee/entries')  self::apply();
        if ($method === 'PATCH' && preg_match('#^/nominee/entries/(\d+)$#', $path, $m)) self::updateEntry((int) $m[1]);
        if ($method === 'POST' && preg_match('#^/nominee/entries/(\d+)/photo$#', $path, $m)) self::uploadPhoto((int) $m[1]);
        if ($method === 'DELETE' && preg_match('#^/nominee/entries/(\d+)/photo$#', $path, $m)) self::removePhoto((int) $m[1]);
        if ($method === 'POST' && $path === '/nominee/password/change') self::changePassword();
        if ($method === 'POST' && $path === '/nominee/password/forgot') self::forgotPassword();
        if ($method === 'POST' && $path === '/nominee/password/reset')  self::resetPassword();
        if ($method === 'POST' && $path === '/nominee/email/send')      self::sendVerification();
        if ($method === 'POST' && $path === '/nominee/email/verify')    self::verifyEmail();

        Response::error('Not found', 404);
    }

    // ------------------------------------------------------------- helpers

    private static function account(): ?array
    {
        $id = Session::personId();
        if ($id === null) {
            return null;
        }
        $st = self::pdo()->prepare('SELECT id, email, email_verified_at FROM accounts WHERE id = ?');
        $st->execute([$id]);
        $a = $st->fetch();
        if (!$a) {
            Session::destroy();
        }
        return $a ?: null;
    }

    private static function requireAccount(): array
    {
        return self::account() ?? self::fail('Your session has expired. Please log in again.', 401);
    }

    /** More than $max rows for this kind of attempt within the window -> stop with 429. */
    private static function throttle(string $kind, string $who, int $max, int $minutes, bool $failuresOnly = true): void
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)'
            . ($failuresOnly ? ' AND success = 0' : ''));
        $st->execute([hash('sha256', "$kind:$who")]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many attempts. Please wait a while and try again.', 429);
        }
    }

    private static function record(string $kind, string $who, bool $success): void
    {
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)')
            ->execute([hash('sha256', "$kind:$who"), Request::ip(), $success ? 1 : 0]);
    }

    private static function password(string $pw, string $field = 'password'): string
    {
        if (mb_strlen($pw) < 8) {
            self::fail('Your password must contain at least 8 characters.', 422, ['fields' => [$field => 'Use at least 8 characters.']]);
        }
        if (strlen($pw) > 72) {
            self::fail('Use a password of at most 72 characters.', 422, ['fields' => [$field => 'At most 72 characters.']]);
        }
        return Auth::hash($pw);
    }

    private static function mediaShape(array $e, string $edSlug): array
    {
        return [
            'id'         => (string) $e['id'],
            'accountId'  => (string) $e['account_id'],
            'occasionId' => $edSlug,
            'categoryId' => (string) $e['category_id'],
            'name'       => $e['name'],
            'phone'      => $e['phone'] ?? '',
            'department' => $e['department'] ?? '',
            'level'      => $e['level'] ?? '',
            'bio'        => $e['bio'] ?? '',
            'photo'      => $e['photo_path'],
            'thumb'      => Photos::thumbOf($e['photo_path']),
            'status'     => $e['status'],
            'createdAt'  => (new \DateTimeImmutable($e['created_at']))->format('c'),
        ];
    }

    private static function entries(int $accountId): array
    {
        $st = self::pdo()->prepare('SELECT n.*, e.slug AS edition_slug FROM nominee_entries n JOIN editions e ON e.id = n.edition_id WHERE n.account_id = ? ORDER BY n.id');
        $st->execute([$accountId]);
        return array_map(fn ($e) => self::mediaShape($e, $e['edition_slug']), $st->fetchAll());
    }

    private static function sessionPayload(array $account): array
    {
        return [
            'authenticated' => true,
            'csrf'          => Session::csrf(),
            'account'       => ['id' => (string) $account['id'], 'email' => $account['email'], 'emailVerified' => $account['email_verified_at'] !== null],
            'entries'       => self::entries((int) $account['id']),
        ];
    }

    private static function queue(string $type, string $email, array $payload): void
    {
        self::pdo()->prepare('INSERT INTO notification_outbox (type, recipient, payload) VALUES (?, ?, ?)')
            ->execute([$type, $email, json_encode($payload)]);
    }

    /** In local development only, the one-time code is handed back so you can test without email. */
    private static function devToken(string $token): array
    {
        return Env::get('APP_ENV') === 'local' && Env::bool('APP_DEBUG') ? ['devToken' => $token] : [];
    }

    private static function newToken(int $accountId, string $type): string
    {
        $pdo = self::pdo();
        $pdo->prepare('UPDATE account_tokens SET used_at = NOW() WHERE account_id = ? AND type = ? AND used_at IS NULL')->execute([$accountId, $type]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO account_tokens (account_id, type, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ' . self::TOKEN_MINUTES . ' MINUTE)')
            ->execute([$accountId, $type, hash('sha256', $token)]);
        return $token;
    }

    // -------------------------------------------------------------- session

    private static function me(): never
    {
        $a = self::account();
        Response::json($a ? ['ok' => true] + self::sessionPayload($a) : ['ok' => true, 'authenticated' => false, 'csrf' => Session::csrf()]);
    }

    private static function logout(): never
    {
        Session::destroy();
        Response::json(['ok' => true]);
    }

    private static function login(): never
    {
        $in = Request::json();
        $email = mb_strtolower(Request::str($in, 'email', 190));
        self::throttle('nominee-login', $email, 5, 15);
        self::throttle('nominee-login-ip', Request::ip(), 200, 15);   // per address: generous, a whole campus can share one

        $st = self::pdo()->prepare('SELECT id, email, email_verified_at, password_hash FROM accounts WHERE email = ?');
        $st->execute([$email]);
        $a = $st->fetch();
        $ok = password_verify((string) ($in['password'] ?? ''), $a['password_hash'] ?? '$2y$12$4W4MtJ6F8ftxs8HaPgNGjOceZORSvdLwtGOzgUsvvq5xM1M750pkS') && $a !== false;
        self::record('nominee-login', $email, $ok);
        self::record('nominee-login-ip', Request::ip(), $ok);
        if (!$ok) {
            self::fail('The email or password is incorrect.', 401);
        }
        if (self::entries((int) $a['id']) === []) {
            self::fail('Your account has no nominee entry yet. Start a registration for an open edition.');
        }
        self::pdo()->prepare('DELETE FROM login_attempts WHERE identifier = ? AND success = 0')->execute([hash('sha256', "nominee-login:$email")]);
        Session::signIn((int) $a['id']);
        Response::json(['ok' => true] + self::sessionPayload($a));
    }

    // ------------------------------------------------------------ register

    /** Shared by "register" and "apply for another edition": the edition and the category must be valid. */
    private static function openEdition(array $in): array
    {
        $ed = EditionState::openForNominations()
            ?? self::fail('Nominations are currently closed. You cannot register at this time.');
        if (Request::str($in, 'occasionId', 60) !== $ed['slug']) {
            self::fail('This registration belongs to a different edition. Refresh the page and try again.');
        }
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND edition_id = ? AND active = 1');
        $st->execute([(int) ($in['categoryId'] ?? 0), $ed['id']]);
        if ((int) $st->fetchColumn() === 0) {
            self::fail('Choose a category.', 422, ['fields' => ['categoryId' => 'Choose a category.']]);
        }
        $fee = (float) ($ed['nominee_registration_fee'] ?? 0);
        if ($fee > 0) {
            // Fee payments are verified with Flutterwave (the payments phase). Until then a paid edition cannot take registrations.
            self::fail('Registration for this edition has a fee of ₦' . number_format($fee) . '. Online payment is not switched on yet.', 402, ['code' => 'payment_required']);
        }
        return $ed;
    }

    private static function profile(array $in): array
    {
        $name  = Request::str($in, 'name', 150);
        $phone = Request::str($in, 'phone', 30);
        $dept  = Request::str($in, 'department', 150);
        $level = Request::str($in, 'level', 30);
        $f = [];
        if (mb_strlen($name) < 2) $f['name'] = 'Enter your full name.';
        if (!preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) $f['phone'] = 'Enter a valid phone number.';
        if ($dept === '') $f['department'] = 'Enter your department.';
        if (!in_array($level, self::LEVELS, true)) $f['level'] = 'Choose your level.';
        if ($f) {
            self::fail(reset($f), 422, ['fields' => $f]);
        }
        return [$name, $phone, $dept, $level];
    }

    private static function register(): never
    {
        $in = Request::json();
        self::throttle('nominee-register', Request::ip(), 60, 60, false);     // 60 sign-up attempts per hour per address (campus WiFi shares one)
        self::record('nominee-register', Request::ip(), true);

        $email = mb_strtolower(Request::str($in, 'email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Enter a valid email address.', 422, ['fields' => ['email' => 'Enter a valid email address.']]);
        }
        $ed = self::openEdition($in);
        [$name, $phone, $dept, $level] = self::profile($in);
        $hash = self::password((string) ($in['password'] ?? ''));

        $st = self::pdo()->prepare('SELECT COUNT(*) FROM accounts WHERE email = ?');
        $st->execute([$email]);
        if ((int) $st->fetchColumn() > 0) {
            self::fail('An account with this email already exists. Log in to continue.', 422, ['fields' => ['email' => 'Already registered — try logging in.']]);
        }

        $accountId = Database::transaction(function (\PDO $db) use ($email, $hash, $ed, $in, $name, $phone, $dept, $level) {
            $db->prepare('INSERT INTO accounts (email, password_hash) VALUES (?, ?)')->execute([$email, $hash]);
            $aid = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO nominee_entries (edition_id, account_id, category_id, name, phone, department, level, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')")
                ->execute([$ed['id'], $aid, (int) $in['categoryId'], $name, $phone, $dept, $level]);
            return $aid;
        });

        $token = self::newToken($accountId, 'email_verify');
        self::queue('nominee_verify_email', $email, ['token' => $token, 'expiresMinutes' => self::TOKEN_MINUTES]);
        Cache::flush();
        Audit::log(null, 'Nominee registered', $email, (int) $ed['id']);
        Session::signIn($accountId);
        $a = ['id' => $accountId, 'email' => $email, 'email_verified_at' => null];
        Response::json(['ok' => true] + self::sessionPayload($a) + self::devToken($token));
    }

    private static function apply(): never
    {
        $a = self::requireAccount();
        $in = Request::json();
        $ed = self::openEdition($in);
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM nominee_entries WHERE account_id = ? AND edition_id = ?');
        $st->execute([$a['id'], $ed['id']]);
        if ((int) $st->fetchColumn() > 0) {
            self::fail('You already have an entry for this edition.');
        }
        $st = self::pdo()->prepare('SELECT * FROM nominee_entries WHERE account_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$a['id']]);
        $prev = $st->fetch() ?: self::fail('We could not find your existing nominee profile.');
        self::pdo()->prepare("INSERT INTO nominee_entries (edition_id, account_id, category_id, name, phone, department, level, bio, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')")
            ->execute([$ed['id'], $a['id'], (int) $in['categoryId'], $prev['name'], $prev['phone'], $prev['department'], $prev['level'], $prev['bio']]);
        $entryId = (string) self::pdo()->lastInsertId();
        Cache::flush();
        Audit::log(null, 'Nominee applied for a new edition', $a['email'], (int) $ed['id']);
        Response::json(['ok' => true, 'entryId' => $entryId] + self::sessionPayload($a));
    }

    // ------------------------------------------------------ my entry, photo

    /** My own entry, in an edition that is still open to changes. */
    private static function ownEntry(int $id, array $account): array
    {
        $st = self::pdo()->prepare('SELECT n.*, e.status AS edition_status FROM nominee_entries n JOIN editions e ON e.id = n.edition_id WHERE n.id = ? AND n.account_id = ?');
        $st->execute([$id, $account['id']]);
        $e = $st->fetch() ?: self::fail('We could not find your nominee entry.', 404);
        if (in_array($e['edition_status'], ['completed', 'archived'], true)) {
            self::fail('That edition is closed, so the entry can’t be changed.', 403);
        }
        return $e;
    }

    private static function updateEntry(int $id): never
    {
        $a = self::requireAccount();
        $e = self::ownEntry($id, $a);
        $in = Request::json();
        [$name, $phone, $dept, $level] = self::profile($in);
        $bio = Request::str($in, 'bio', 600);
        if (mb_strlen($bio) > 500) {
            self::fail('Your bio can be at most 500 characters.', 422, ['fields' => ['bio' => 'At most 500 characters.']]);
        }
        self::pdo()->prepare('UPDATE nominee_entries SET name = ?, phone = ?, department = ?, level = ?, bio = ? WHERE id = ?')
            ->execute([$name, $phone, $dept, $level, $bio ?: null, $id]);
        Cache::flush();
        Response::json(['ok' => true] + self::sessionPayload($a));
    }

    private static function uploadPhoto(int $id): never
    {
        $a = self::requireAccount();
        $e = self::ownEntry($id, $a);
        $path = Photos::save($_FILES['photo'] ?? [], 'nominees');
        self::pdo()->prepare('UPDATE nominee_entries SET photo_path = ? WHERE id = ?')->execute([$path, $id]);
        Photos::delete($e['photo_path']);
        Cache::flush();
        Response::json(['ok' => true] + self::sessionPayload($a));
    }

    private static function removePhoto(int $id): never
    {
        $a = self::requireAccount();
        $e = self::ownEntry($id, $a);
        self::pdo()->prepare('UPDATE nominee_entries SET photo_path = NULL WHERE id = ?')->execute([$id]);
        Photos::delete($e['photo_path']);
        Cache::flush();
        Response::json(['ok' => true] + self::sessionPayload($a));
    }

    // ------------------------------------------------------------ passwords

    private static function changePassword(): never
    {
        $a = self::requireAccount();
        $in = Request::json();
        self::throttle('nominee-pw', (string) $a['id'], 5, 15);
        $st = self::pdo()->prepare('SELECT password_hash FROM accounts WHERE id = ?');
        $st->execute([$a['id']]);
        if (!password_verify((string) ($in['currentPassword'] ?? ''), (string) $st->fetchColumn())) {
            self::record('nominee-pw', (string) $a['id'], false);
            self::fail('Your current password is incorrect.', 422, ['fields' => ['currentPassword' => 'Password does not match.']]);
        }
        $hash = self::password((string) ($in['newPassword'] ?? ''), 'newPassword');
        self::pdo()->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')->execute([$hash, $a['id']]);
        Session::signIn((int) $a['id']);               // fresh session id after a password change
        Response::json(['ok' => true, 'success' => true, 'csrf' => Session::csrf()]);
    }

    /** Always answers the same way, so nobody can use it to find out which emails have accounts. */
    private static function forgotPassword(): never
    {
        $in = Request::json();
        $email = mb_strtolower(Request::str($in, 'email', 190));
        self::throttle('nominee-forgot', Request::ip(), 60, 60, false);
        self::record('nominee-forgot', Request::ip(), true);

        $st = self::pdo()->prepare('SELECT id FROM accounts WHERE email = ?');
        $st->execute([$email]);
        $id = $st->fetchColumn();
        $extra = [];
        if ($id !== false) {
            $recent = self::pdo()->prepare("SELECT COUNT(*) FROM account_tokens WHERE account_id = ? AND type = 'password_reset' AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $recent->execute([$id]);
            if ((int) $recent->fetchColumn() < 3) {
                $token = self::newToken((int) $id, 'password_reset');
                self::queue('nominee_password_reset', $email, ['token' => $token, 'expiresMinutes' => self::TOKEN_MINUTES]);
                $extra = self::devToken($token);
            }
        }
        Response::json(['ok' => true, 'expiresInMinutes' => self::TOKEN_MINUTES] + $extra);
    }

    private static function resetPassword(): never
    {
        $in = Request::json();
        self::throttle('nominee-reset', Request::ip(), 60, 15);
        $email = mb_strtolower(Request::str($in, 'email', 190));
        $hash = self::password((string) ($in['password'] ?? ''));

        $st = self::pdo()->prepare(
            "SELECT t.id, t.account_id FROM account_tokens t JOIN accounts a ON a.id = t.account_id
              WHERE t.token_hash = ? AND t.type = 'password_reset' AND a.email = ? AND t.used_at IS NULL AND t.expires_at > NOW()"
        );
        $st->execute([hash('sha256', trim((string) ($in['token'] ?? ''))), $email]);
        $t = $st->fetch();
        if (!$t) {
            self::record('nominee-reset', Request::ip(), false);
            self::fail('The reset token is invalid or has expired.');
        }
        Database::transaction(function (\PDO $db) use ($t, $hash) {
            $db->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')->execute([$hash, $t['account_id']]);
            $db->prepare("UPDATE account_tokens SET used_at = NOW() WHERE account_id = ? AND type = 'password_reset' AND used_at IS NULL")->execute([$t['account_id']]);
        });
        Audit::log(null, 'Nominee password reset', $email);
        Response::json(['ok' => true, 'success' => true]);
    }

    // -------------------------------------------------------- email verify

    private static function sendVerification(): never
    {
        $a = self::requireAccount();
        if ($a['email_verified_at'] !== null) {
            Response::json(['ok' => true, 'alreadyVerified' => true]);
        }
        $recent = self::pdo()->prepare("SELECT COUNT(*) FROM account_tokens WHERE account_id = ? AND type = 'email_verify' AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $recent->execute([$a['id']]);
        if ((int) $recent->fetchColumn() >= 5) {
            self::fail('Too many requests. Please wait a while and try again.', 429);
        }
        $token = self::newToken((int) $a['id'], 'email_verify');
        self::queue('nominee_verify_email', $a['email'], ['token' => $token, 'expiresMinutes' => self::TOKEN_MINUTES]);
        Response::json(['ok' => true, 'expiresInMinutes' => self::TOKEN_MINUTES] + self::devToken($token));
    }

    private static function verifyEmail(): never
    {
        $a = self::requireAccount();
        $in = Request::json();
        self::throttle('nominee-verify', (string) $a['id'], 10, 15);
        $st = self::pdo()->prepare("SELECT id FROM account_tokens WHERE account_id = ? AND type = 'email_verify' AND token_hash = ? AND used_at IS NULL AND expires_at > NOW()");
        $st->execute([$a['id'], hash('sha256', trim((string) ($in['token'] ?? '')))]);
        $tid = $st->fetchColumn();
        if ($tid === false) {
            self::record('nominee-verify', (string) $a['id'], false);
            self::fail('The verification token is invalid or has expired.');
        }
        Database::transaction(function (\PDO $db) use ($tid, $a) {
            $db->prepare('UPDATE account_tokens SET used_at = NOW() WHERE id = ?')->execute([$tid]);
            $db->prepare('UPDATE accounts SET email_verified_at = NOW() WHERE id = ?')->execute([$a['id']]);
        });
        Response::json(['ok' => true, 'success' => true]);
    }
}