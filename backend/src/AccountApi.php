<?php
declare(strict_types=1);

namespace App;

/** Nominee accounts: register, sign in, my entries, profile and photo, passwords, email verification. */
final class AccountApi
{
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
        if ($method === 'POST' && $path === '/nominee/matric')   self::setMatric();
        if ($method === 'POST' && $path === '/nominee/register/confirm') self::confirmRegistration();
        if ($method === 'PATCH' && preg_match('#^/nominee/entries/(\d+)$#', $path, $m)) self::updateEntry((int) $m[1]);
        if ($method === 'POST' && preg_match('#^/nominee/entries/(\\d+)/resubmit$#', $path, $m)) self::resubmit((int) $m[1]);
        if ($method === 'GET' && $path === '/nominee/access-pass') self::accessPass(false);
        if ($method === 'POST' && $path === '/nominee/access-pass') self::accessPass(true);
        if ($method === 'GET' && $path === '/nominee/announcements') { self::requireAccount(); Response::json(['ok' => true, 'announcements' => AnnouncementApi::visible(['public', 'nominees'])]); }
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
        $st = self::pdo()->prepare('SELECT id, email, email_verified_at, matric_no, level FROM accounts WHERE id = ?');
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
    /** The level on the newest entry (for accounts made before levels were kept on the account). */
    private static function latestLevel(int $accountId): ?string
    {
        $st = self::pdo()->prepare('SELECT level FROM nominee_entries WHERE account_id = ? AND level IS NOT NULL ORDER BY id DESC LIMIT 1');
        $st->execute([$accountId]);
        $l = $st->fetchColumn();
        return $l === false ? null : (string) $l;
    }

    private static function accountLevel(array $a): ?string
    {
        return ($a['level'] ?? null) !== null && $a['level'] !== '' ? (string) $a['level'] : self::latestLevel((int) $a['id']);
    }

    /** True when this nominee still has to give a matric number (their level asks for one and none is saved). */
    private static function needsMatric(array $a): bool
    {
        if (($a['matric_no'] ?? null) !== null) {
            return false;
        }
        $level = self::accountLevel($a);
        return $level === null || Options::requiresMatric($level);
    }

    private static function requireMatric(array $a): void
    {
        if (self::needsMatric($a)) {
            self::fail('Please add your matric number first. Your dashboard will ask for it.', 403, ['code' => 'matric_required']);
        }
    }

    /** Validates a matric number against the admin's formats; returns it cleaned, or fails with a field error. */
    private static function matricField(mixed $raw, bool $required): ?string
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            if ($required) {
                self::fail('Enter your matric number.', 422, ['fields' => ['matricNo' => 'Enter your matric number.']]);
            }
            return null;
        }
        $clean = Matric::normalize($raw);
        if ($clean === null) {
            $cfg = Matric::config();
            $examples = implode(' or ', array_filter(array_column($cfg['formats'], 'example')));
            $msg = 'That is not a valid matric number' . ($examples ? " (for example $examples)" : '') . '.';
            self::fail($msg, 422, ['fields' => ['matricNo' => $msg]]);
        }
        return $clean;
    }

    private static function assertEligible(int $categoryId, string $level): void
    {
        if (!Eligibility::allows($categoryId, $level)) {
            $msg = Eligibility::message($categoryId);
            self::fail($msg, 422, ['fields' => ['categoryId' => $msg]]);
        }
    }
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
            'reviewNote' => $e['review_note'] ?? '',
            'nickname'   => $e['nickname'] ?? '',
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
            'account'       => [
                'id' => (string) $account['id'], 'email' => $account['email'], 'emailVerified' => $account['email_verified_at'] !== null,
                'matricNo' => $account['matric_no'] ?? null, 'level' => self::accountLevel($account), 'needsMatric' => self::needsMatric($account),
            ],
            'entries'       => self::entries((int) $account['id']),
        ];
    }

    public static function queue(string $type, string $email, array $payload): void
    {
        Outbox::queueAndSend($type, $email, $payload);
    }
    
    /** In local development only, the one-time code is handed back so you can test without email. */
    private static function devToken(string $token): array
    {
        return Env::get('APP_ENV') === 'local' && Env::bool('APP_DEBUG') ? ['devToken' => $token] : [];
    }

    public static function newToken(int $accountId, string $type): string
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

        $st = self::pdo()->prepare('SELECT id, email, email_verified_at, password_hash, matric_no, level
        FROM accounts WHERE email = ?');
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

    /** Shared by "register" and "apply": nominations must be open, and for the edition the page was built for. */
    private static function openEdition(array $in): array
    {
        $ed = EditionState::openForNominations()
            ?? self::fail('Nominations are currently closed. You cannot register at this time.');
        if (Request::str($in, 'occasionId', 60) !== $ed['slug']) {
            self::fail('This registration belongs to a different edition. Refresh the page and try again.');
        }
        return $ed;
    }

    /**
     * The categories asked for ("categoryIds", or the older "categoryId"): each active in this edition, open to the
     * nominee's level, not already entered, and within the admin's limit. Returns the ids.
     */
    private static function chooseCategories(array $in, array $ed, string $level, array $already = []): array
    {
        $ids = Entries::requested($in);
        if ($ids === []) {
            self::fail('Choose a category.', 422, ['fields' => ['categoryId' => 'Choose a category.']]);
        }
        $ok = self::pdo()->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND edition_id = ? AND active = 1');
        foreach ($ids as $id) {
            $ok->execute([$id, $ed['id']]);
            if ((int) $ok->fetchColumn() === 0) {
                self::fail('Choose a category.', 422, ['fields' => ['categoryId' => 'Choose a category.']]);
            }
            if (in_array($id, $already, true)) {
                self::fail('You already entered that category.', 422, ['fields' => ['categoryId' => 'You already entered that category.']]);
            }
            self::assertEligible($id, $level);
        }
        $max = Entries::maxPerNominee((int) $ed['id']);
        if (count($already) + count($ids) > $max) {
            $msg = $already === []
                ? "You can enter up to $max " . ($max === 1 ? 'category' : 'categories') . ' in this edition.'
                : 'You have entered ' . count($already) . " of the $max " . ($max === 1 ? 'category' : 'categories') . ' allowed in this edition.';
            self::fail($msg, 422, ['fields' => ['categoryId' => $msg]]);
        }
        return $ids;
    }

    private static function profile(array $in, ?string $keepLevel = null): array
    {
        if ($keepLevel !== null) {
            $in['level'] = $keepLevel; // a nominee cannot change their own level: the admin moves students up
        }

        $name  = Request::str($in, 'name', 150);
        $phone = Request::str($in, 'phone', 30);
        $dept  = Request::str($in, 'department', 150);
        $level = Request::str($in, 'level', 30);
        $f = [];
        if (mb_strlen($name) < 2) $f['name'] = 'Enter your full name.';
        if (!preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) $f['phone'] = 'Enter a valid phone number.';
        if ($dept === '') $f['department'] = 'Enter your department.';
        if ($keepLevel === null && !Options::isActive('level', $level)) {
            $f['level'] = 'Choose your level.';
        }
        if ($f) {
            self::fail(reset($f), 422, ['fields' => $f]);
        }
        return [$name, $phone, $dept, $level];
    }

    private static function register(): never
    {
        $in = Request::json();
        self::throttle('nominee-register', Request::ip(), 60, 60, false);
        self::record('nominee-register', Request::ip(), true);

        $email = mb_strtolower(Request::str($in, 'email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Enter a valid email address.', 422, ['fields' => ['email' => 'Enter a valid email address.']]);
        }

        $ed = self::openEdition($in);
        [$name, $phone, $dept, $level] = self::profile($in);

        $matric = self::matricField($in['matricNo'] ?? '', Options::requiresMatric($level));
        $catIds = self::chooseCategories($in, $ed, $level);
        $hash = self::password((string) ($in['password'] ?? ''));

        if ($matric !== null && ($why = Matric::problem(self::pdo(), $matric, $email)) !== null) {
            self::fail($why, 422, ['fields' => ['matricNo' => $why]]);
        }

        $st = self::pdo()->prepare('SELECT COUNT(*) FROM accounts WHERE email = ?');
        $st->execute([$email]);
        if ((int) $st->fetchColumn() > 0) {
            self::fail('An account with this email already exists. Log in to continue.', 422, ['fields' => ['email' => 'Already registered — try logging in.']]);
        }

        $profile = ['name' => $name, 'phone' => $phone, 'department' => $dept, 'level' => $level, 'matricNo' => $matric, 'categoryIds' => $catIds];

        if ((float) ($ed['nominee_registration_fee'] ?? 0) > 0) {
            self::startPaidRegistration($ed, null, $email, $hash, $profile, 'register');   // one fee, however many categories
        }

        try {
            $accountId = Database::transaction(function (\PDO $db) use ($email, $hash, $ed, $catIds, $name, $phone, $dept, $level, $matric) {
                if ($matric !== null) {
                    $problem = Matric::claim($db, $matric, $email, 'nominee');
                    if ($problem !== null) {
                        throw new \DomainException($problem);
                    }
                }
                $db->prepare('INSERT INTO accounts (email, password_hash, matric_no, level) VALUES (?, ?, ?, ?)')
                    ->execute([$email, $hash, $matric, $level]);
                $aid = (int) $db->lastInsertId();
                $ins = $db->prepare("INSERT INTO nominee_entries (edition_id, account_id, category_id, name, phone, department, level, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                foreach ($catIds as $cid) {
                    $ins->execute([$ed['id'], $aid, $cid, $name, $phone, $dept, $level]);
                }
                return $aid;
            });
        } catch (\DomainException $e) {
            self::fail($e->getMessage(), 422, ['fields' => ['matricNo' => $e->getMessage()]]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('That matric number or email is already registered.', 422);
            }
            throw $e;
        }

        $token = self::newToken($accountId, 'email_verify');
        self::queue('nominee_verify_email', $email, ['token' => $token, 'expiresMinutes' => self::TOKEN_MINUTES]);

        Cache::flush();
        Audit::log(null, 'Nominee registered', $email, (int) $ed['id']);
        Session::signIn($accountId);

        $a = ['id' => $accountId, 'email' => $email, 'email_verified_at' => null, 'matric_no' => $matric, 'level' => $level];
        Response::json(['ok' => true] + self::sessionPayload($a) + self::devToken($token));
    }

    private static function setMatric(): never
    {
        $a = self::requireAccount();

        if (!empty($a['matric_no'])) {
            self::fail('Your matric number has already been saved and cannot be changed.', 409);
        }

        $in = Request::json();
        $matric = self::matricField($in['matricNo'] ?? '', true);

        try {
            Database::transaction(function (\PDO $db) use ($a, $matric) {
                $st = $db->prepare('SELECT matric_no FROM accounts WHERE id = ? FOR UPDATE');
                $st->execute([$a['id']]);
                $current = $st->fetchColumn();

                if ($current !== null && $current !== false && $current !== '') {
                    throw new \DomainException('Your matric number has already been saved and cannot be changed.');
                }

                $problem = Matric::claim($db, $matric, $a['email'], 'nominee');

                if ($problem !== null) {
                    throw new \DomainException($problem);
                }

                $db->prepare('UPDATE accounts SET matric_no = ? WHERE id = ?')
                    ->execute([$matric, $a['id']]);
            });
        } catch (\DomainException $e) {
            $status = str_contains($e->getMessage(), 'already been saved') ? 409 : 422;

            self::fail($e->getMessage(), $status, [
                'fields' => ['matricNo' => $e->getMessage()]
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('That matric number is already registered with another email address.', 422);
            }
            throw $e;
        }

        $fresh = self::account();
        Response::json(['ok' => true] + self::sessionPayload($fresh));
    }
    /** Enter one or more (more) categories: a returning nominee, or one adding to this edition. */
    private static function apply(): never
    {
        $a = self::requireAccount();
        self::requireMatric($a);

        $level = self::accountLevel($a);
        if ($level === null) {
            self::fail('Your student level is missing. Please contact the organisers.');
        }
        $in = Request::json();
        $ed = self::openEdition($in);
        $pdo = self::pdo();
        $accountId = (int) $a['id'];

        $taken = Entries::takenCategories($pdo, $accountId, (int) $ed['id']);
        $catIds = self::chooseCategories($in, $ed, $level, $taken);

        // Details to reuse: this edition's newest entry (with its photo), else the newest from an earlier edition.
        $st = $pdo->prepare('SELECT * FROM nominee_entries WHERE account_id = ? AND edition_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$accountId, $ed['id']]);
        $prev = $st->fetch() ?: null;
        $photo = $prev['photo_path'] ?? null;
        if ($prev === null) {
            $st = $pdo->prepare('SELECT * FROM nominee_entries WHERE account_id = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$accountId]);
            $prev = $st->fetch() ?: self::fail('We could not find your existing nominee profile.');
        }

        // The registration fee is paid once per edition: more categories in the same edition are free.
        if ((float) ($ed['nominee_registration_fee'] ?? 0) > 0 && !Entries::feeSettled($pdo, $accountId, (int) $ed['id'])) {
            self::startPaidRegistration($ed, $accountId, $a['email'], null, [
                'name' => $prev['name'], 'phone' => $prev['phone'], 'department' => $prev['department'], 'level' => $level,
                'bio' => $prev['bio'], 'categoryIds' => $catIds,
            ], 'dashboard');
        }

        $max = Entries::maxPerNominee((int) $ed['id']);
        try {
            $newIds = Database::transaction(function (\PDO $db) use ($accountId, $ed, $catIds, $prev, $level, $photo, $max) {
                $db->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE')->execute([$accountId]);   // one request at a time per nominee
                $have = Entries::takenCategories($db, $accountId, (int) $ed['id']);
                if (count($have) + count($catIds) > $max) {
                    throw new \DomainException("You can enter up to $max " . ($max === 1 ? 'category' : 'categories') . ' in this edition.');
                }
                $ins = $db->prepare("INSERT INTO nominee_entries (edition_id, account_id, category_id, name, phone, department, level, bio, photo_path, nickname, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $ids = [];
                foreach ($catIds as $cid) {
                    if (in_array($cid, $have, true)) {
                        throw new \DomainException('You already entered that category.');
                    }
                    $ins->execute([$ed['id'], $accountId, $cid, $prev['name'], $prev['phone'], $prev['department'], $level, $prev['bio'], $photo, $prev['nickname'] ?? null]);
                    $ids[] = (string) $db->lastInsertId();
                }
                return $ids;
            });
        } catch (\DomainException $e) {
            self::fail($e->getMessage(), 422, ['fields' => ['categoryId' => $e->getMessage()]]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('You already entered that category.', 422, ['fields' => ['categoryId' => 'You already entered that category.']]);
            }
            throw $e;
        }
        Cache::flush();
        Audit::log(null, $taken === [] ? 'Nominee applied for a new edition' : 'Nominee entered more categories', $a['email'], (int) $ed['id']);
        Response::json(['ok' => true, 'entryId' => $newIds[0], 'entryIds' => $newIds] + self::sessionPayload($a));
    }
   /**
     * The edition charges a registration fee: keep the details safe, send the person to pay, and create nothing yet.
     * The account and entry are created by Registrations::complete() once Flutterwave has confirmed the money.
     */
    private static function startPaidRegistration(array $ed, ?int $accountId, string $email, ?string $hash, array $profile, string $returnPage): never
    {
        $fee = round((float) $ed['nominee_registration_fee'], 2);
        $currency = $ed['currency'] ?: 'NGN';
        $reference = 'REG-' . strtoupper(bin2hex(random_bytes(6)));
        Database::transaction(function (\PDO $db) use ($ed, $accountId, $email, $hash, $profile, $fee, $currency, $reference) {
            $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (?, 'nominee_fee', ?, ?, ?, ?, 'pending')")
                ->execute([$ed['id'], $reference, $fee, $currency, $email]);
            $paymentId = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO pending_registrations (reference, edition_id, account_id, email, password_hash, profile, payment_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')")
                ->execute([$reference, $ed['id'], $accountId, $email, $hash, json_encode($profile, JSON_UNESCAPED_UNICODE), $paymentId]);
        });
        try {
            $link = Flutterwave::createPayment([
                'reference' => $reference, 'amount' => $fee, 'currency' => $currency, 'email' => $email, 'name' => $profile['name'],
                'redirectUrl' => rtrim((string) Env::get('APP_URL', ''), '/') . '/' . $returnPage . '?pay=return', 'purpose' => 'nominee_fee',
                'title' => $ed['name'] . ' ' . $ed['edition_label'] . ' — nominee registration', 'description' => 'Registration fee',
            ]);
        } catch (GatewayException $e) {
            error_log((string) $e);
            self::pdo()->prepare("UPDATE payments SET status = 'failed' WHERE reference = ?")->execute([$reference]);
            self::pdo()->prepare("UPDATE pending_registrations SET status = 'failed', password_hash = NULL WHERE reference = ?")->execute([$reference]);
            self::fail('The payment service is unavailable right now. You have not been charged. Please try again shortly.', 502);
        }
        Session::put('pending_reg', $reference);        // only THIS browser may be signed in when the payment is confirmed
        Response::json(['ok' => true, 'paymentRequired' => true, 'reference' => $reference, 'amount' => $fee, 'paymentLink' => $link]);
    }

    /** The person came back from Flutterwave. We ask Flutterwave ourselves; only the browser that started it is signed in. */
    private static function confirmRegistration(): never
    {
        $in = Request::json();
        self::throttle('nominee-reg-confirm', Request::ip(), 240, 15, false);
        self::record('nominee-reg-confirm', Request::ip(), true);
        $ref = Request::str($in, 'reference', 80);
        $st = self::pdo()->prepare('SELECT * FROM pending_registrations WHERE reference = ?');
        $st->execute([$ref]);
        if (!$st->fetch()) {
            self::fail('We could not find that payment.', 404);
        }
        $result = PaymentService::settle($ref);
        $st->execute([$ref]);
        $row = $st->fetch();
        if ($result['status'] !== 'paid') {
            Response::json(['ok' => true, 'status' => $result['status'], 'note' => $result['note'] ?? null]);
        }
        if ($row['status'] !== 'completed') {
            Response::json(['ok' => true, 'status' => 'paid', 'problem' => true,
                'note' => "Your payment was received, but we could not finish your registration automatically. Please contact the organisers and quote this reference: $ref"]);
        }
        $account = self::pdo()->prepare('SELECT id, email, email_verified_at, matric_no, level
        FROM accounts WHERE id = ?');
        $account->execute([$row['account_id']]);
        $a = $account->fetch();
        if (Session::peek('pending_reg') === $ref) {
            Session::pull('pending_reg');
            Session::signIn((int) $a['id']);
            Response::json(['ok' => true, 'status' => 'paid'] + self::sessionPayload($a));
        }
        Response::json(['ok' => true, 'status' => 'paid', 'authenticated' => false,
            'note' => 'Your payment is confirmed and your account is ready. Log in with your email and password.']);
    }

    // ------------------------------------------------------ my entry, photo

    /** Phase 12: the nominee's free event pass. GET shows it; POST claims it (once per edition). */
    private static function accessPass(bool $claim): never
    {
        $a = self::requireAccount();
        self::requireMatric($a);
        $pdo = self::pdo();
        $ed = AccessPass::activeEdition();
        $cfg = $ed ? AccessPass::settings((int) $ed['id']) : ['enabled' => false, 'guests' => 0];
        $out = ['ok' => true, 'enabled' => $cfg['enabled'], 'guests' => $cfg['guests'], 'eligible' => false, 'claimed' => false, 'tickets' => [], 'edition' => $ed['slug'] ?? null];
        $tickets = $ed ? AccessPass::tickets($pdo, (int) $a['id'], (int) $ed['id']) : [];   // a claimed pass stays visible even if the admin later switches the feature off
        if ($ed && $cfg['enabled']) {
            $out['eligible'] = AccessPass::eligible($pdo, (int) $a['id'], (int) $ed['id']);
            if ($claim && $tickets === []) {
                if (!$out['eligible']) {
                    self::fail('Your Access Pass opens once one of your entries is approved.', 403);
                }
                $tickets = AccessPass::claim($a, $ed);
                self::queue('nominee_access_pass', $a['email'], ['editionName' => trim($ed['name'] . ' ' . ($ed['edition_label'] ?? ''))]);
                Audit::log(null, 'Nominee claimed an Access Pass', $a['email'], (int) $ed['id']);
            }
            $out['tickets'] = $tickets;
            $out['claimed'] = $tickets !== [];
        } elseif ($claim && $tickets === []) {
            self::fail('The Access Pass is not available right now.', 403);
        } else {
            $out['tickets'] = $tickets;
            $out['claimed'] = $tickets !== [];
        }
        Response::json($out);
    }
    
        /** A rejected entry goes back to review after the nominee has fixed what the reviewer asked for. */
    private static function resubmit(int $id): never
    {
        $a = self::requireAccount();
        self::requireMatric($a);
        $e = self::ownEntry($id, $a);
        if ($e['status'] !== 'rejected') {
            self::fail('Only an entry that needs changes can be sent for review again.');
        }
        $open = EditionState::openForNominations();
        if ($open === null || (int) $open['id'] !== (int) $e['edition_id']) {
            self::fail('Nominations are closed, so this entry can no longer be sent for review.', 403);
        }
        self::pdo()->prepare("UPDATE nominee_entries SET status = 'pending' WHERE id = ?")->execute([$id]);
        Cache::flush();
        Audit::log(null, 'Nominee sent an entry for review again', $a['email'], (int) $e['edition_id']);
        Response::json(['ok' => true] + self::sessionPayload($a));
    }
    
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
    self::requireMatric($a);

    $e = self::ownEntry($id, $a);
    $in = Request::json();

    [$name, $phone, $dept, $level] = self::profile($in, self::accountLevel($a));
    self::assertEligible((int) $e['category_id'], $level);

    $bio = Request::str($in, 'bio', 600);

    $nick = array_key_exists('nickname', $in) ? trim(Request::str($in, 'nickname', 60)) : (string) ($e['nickname'] ?? '');
    if (mb_strlen($nick) > 40 || preg_match('/[\x00-\x1F\x7F<>]/', $nick)) {
        self::fail('Your nickname can be at most 40 characters, with no special symbols.', 422, ['fields' => ['nickname' => 'At most 40 characters.']]);
    }

    if (mb_strlen($bio) > 500) {
        self::fail('Your bio can be at most 500 characters.', 422, [
            'fields' => ['bio' => 'At most 500 characters.']
        ]);
    }

    // The same person in every category of this edition: one set of details.
    Entries::syncProfile(self::pdo(), (int) $a['id'], (int) $e['edition_id'], [
        'name' => $name, 'nickname' => $nick !== '' ? $nick : null, 'phone' => $phone, 'department' => $dept, 'level' => $level, 'bio' => $bio ?: null,
    ]);

    Cache::flush();

    Response::json(['ok' => true] + self::sessionPayload($a));
}
    private static function uploadPhoto(int $id): never
    {
        $a = self::requireAccount();
        self::requireMatric($a);
        $e = self::ownEntry($id, $a);
        $path = Photos::save($_FILES['photo'] ?? [], 'nominees');
        $old = Entries::photosOf(self::pdo(), (int) $a['id'], (int) $e['edition_id']);
        Entries::setPhoto(self::pdo(), (int) $a['id'], (int) $e['edition_id'], $path);    // one photo for all of this edition's entries
        foreach ($old as $p) {
            Photos::delete($p);
        }
        Cache::flush();
        Response::json(['ok' => true] + self::sessionPayload($a));
    }


private static function removePhoto(int $id): never
{
    $a = self::requireAccount();
    self::requireMatric($a);

    $e = self::ownEntry($id, $a);

    $photos = Entries::photosOf(
        self::pdo(),
        (int) $a['id'],
        (int) $e['edition_id']
    );

    Entries::setPhoto(
        self::pdo(),
        (int) $a['id'],
        (int) $e['edition_id'],
        null
    );

    foreach ($photos as $photo) {
        Photos::delete($photo);
    }

    Cache::flush();
    Response::json(['ok' => true] + self::sessionPayload($a));
}
}