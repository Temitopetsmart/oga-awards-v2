<?php
declare(strict_types=1);

namespace App;

/**
 * Site-wide settings kept in the database: contact details, social links, email preferences, gateway preferences.
 * Secrets (Flutterwave secret key, webhook hash, SMTP password) are NEVER stored here: they are encrypted
 * in their own tables (app_secrets, smtp_settings).
 */
final class SettingsApi
{
private const SECTIONS = ['contact', 'social', 'communications', 'gateway', 'brand', 'matric', 'progression'];
    /** Sections with their own storage (not a site_settings row). */
    private const SPECIAL = ['levels', 'departments', 'secrets'];
    
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function all(): array
    {
        $out = [];
        foreach (Database::pdo()->query('SELECT setting_key, value FROM site_settings')->fetchAll() as $r) {
            $out[$r['setting_key']] = json_decode($r['value'], true);
        }
        return $out;
    }

    /** What every visitor may see: how to reach the organisers. */
    public static function publicView(): array
    {
        $s = self::all();
        $matric = Matric::config();

        return [
            'contact'     => $s['contact'] ?? new \stdClass(),
            'social'      => $s['social'] ?? [],
            'brand'       => $s['brand'] ?? new \stdClass(),
            'levels'      => Options::names('level'),
            'levelRules'  => array_values(array_map(fn ($l) => ['name' => $l['name'], 'requiresMatric' => $l['requiresMatric']], array_filter(Options::all('level'), fn ($l) => $l['active']))),
            'departments' => Options::names('department'),
            'matric'      => ['maxLength' => $matric['maxLength'], 'formats' => $matric['formats']],
        ];
    }

    /** One stored section as an array (empty if never saved). For server-side use. */
    public static function value(string $key): array
    {
        $st = Database::pdo()->prepare('SELECT value FROM site_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = json_decode((string) ($st->fetchColumn() ?: '{}'), true);
        return is_array($v) ? $v : [];
    }

    /** The name used in email footers etc. Admin-controlled. */
    public static function brandName(): string
    {
        $c = self::value('communications');
        $b = self::value('brand');
        return (string) (($c['fromName'] ?? '') ?: ($b['name'] ?? '') ?: 'OGA Awards');
    }

    public static function read(): never {
        Access::require('settings.view', (string) ($_GET['edition'] ?? ''));

        $s = self::all();

        $settings = array_map(
            fn ($k) => $s[$k] ?? null,
            array_combine(self::SECTIONS, self::SECTIONS)
        );

        // Return SMTP connection details without ever returning the password.
        $settings['communications'] = array_merge(
            (array) ($settings['communications'] ?? []),
            SmtpSettings::publicSettings()
        );

        $settings['brand'] = $s['brand'] ?? null;
        $settings['matric'] = Matric::config();
        $settings['levels'] = Options::all('level');
        $settings['progression'] = ['map' => (object) LevelApi::mapping(), 'last' => LevelApi::last()];
        $settings['departments'] = Options::all('department');
        $settings['secrets'] = array_values(array_map(
            fn ($k, $v) => ['key' => $k] + $v,
            array_keys(Secrets::status()),
            Secrets::status()
        ));

        Response::json(['ok' => true, 'settings' => $settings]);
    }

    private static function email(array $in, string $key, bool $required = false): string
    {
        $v = mb_strtolower(Request::str($in, $key, 190));
        if (($v === '' && $required) || ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL))) {
            self::fail('Enter a valid email address.');
        }
        return $v;
    }

    private static function https(array $in, string $key, int $max = 255): string
    {
        $v = Request::str($in, $key, $max + 1);
        if ($v !== '' && (mb_strlen($v) > $max || !preg_match('#^https://\S+$#', $v))) {
            self::fail('Use a full https:// address.');
        }
        return $v;
    }

    private static function clean(string $section, array $v): array
    {
        switch ($section) {
            case 'contact':
                $phone = Request::str($v, 'phone', 30);
                if ($phone !== '' && !preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) {
                    self::fail('Enter a valid phone number.');
                }
                return ['email' => self::email($v, 'email'), 'phone' => $phone];

            case 'social':
                $out = [];
                foreach (array_slice(array_values($v), 0, 10) as $x) {
                    $x = (array) $x;
                    $label = Request::str($x, 'label', 40);
                    $icon = Request::str($x, 'icon', 60);
                    if ($label === '' || ($icon !== '' && !preg_match('/^fa-[a-z0-9 -]+$/', $icon))) {
                        self::fail('Each social link needs a name.');
                    }
                    $out[] = ['label' => $label, 'icon' => $icon, 'href' => self::https($x, 'href')];
                }
                return $out;

            case 'communications':
                // Sender details. The wording of every email is edited in Admin → Email → Templates.
                if ((string) ($v['emailMode'] ?? 'smtp') !== 'smtp') {
                    self::fail('Only SMTP delivery is supported.');
                }

                return [
                    'emailMode'  => 'smtp',
                    'fromName'   => Request::str($v, 'fromName', 100),
                    'fromEmail'  => self::email($v, 'fromEmail'),
                    'replyTo'    => self::email($v, 'replyTo'),
                    'footerText' => Request::str($v, 'footerText', 300),
                ];
            case 'brand':
                $name = Request::str($v, 'name', 60);
                if ($name === '') {
                    self::fail('Enter the site name.');
                }
                return ['name' => $name, 'org' => Request::str($v, 'org', 80), 'tagline' => Request::str($v, 'tagline', 120)];

            case 'matric':
                try {
                    return Matric::clean($v);
                } catch (\InvalidArgumentException $e) {
                    self::fail($e->getMessage());
                }

            case 'progression':
                try {
                    return ['map' => LevelApi::cleanMap($v['map'] ?? [])];
                } catch (\InvalidArgumentException $e) {
                    self::fail($e->getMessage());
                }

            case 'gateway':
                $provider = (string) ($v['provider'] ?? '');
                $env = (string) ($v['environment'] ?? '');
                $key = Request::str($v, 'publicKey', 200);
                if (!in_array($provider, ['flutterwave', 'paystack', 'none'], true)) {
                    self::fail('Choose a supported provider.');
                }
                if (!in_array($env, ['test', 'live'], true)) {
                    self::fail('Choose test or live mode.');
                }
                if ($key !== '' && (!preg_match('/^[A-Za-z0-9_-]+$/', $key) || preg_match('/SECK|(^|[_-])sk[_-]|secret/i', $key))) {
                    self::fail('That looks like a secret key. Only the PUBLIC key goes here. Secret keys belong in the server’s config.php.');
                }
                return ['provider' => $provider, 'environment' => $env, 'publicKey' => $key];
        }
        self::fail('Unknown settings section.', 404);
    }

    private static function saveSpecial(string $section, array $in, array $actor): never
    {
        $value = $in['value'] ?? [];
        if (!is_array($value)) {
            self::fail('Invalid request.', 400);
        }
        $actorId = (int) $actor['id'];

        if ($section === 'levels' || $section === 'departments') {
            $kind = $section === 'levels' ? 'level' : 'department';
            try {
                $list = Options::save($kind, $value);
            } catch (\InvalidArgumentException $e) {
                self::fail($e->getMessage());
            }
            Cache::flush();
            Audit::log($actorId, 'Updated ' . $section . ' list', null, $actor['edition_id']);
            Response::json(['ok' => true, 'items' => $list]);
        }

        // secrets: Super admin only; values are write-only.
        if ((int) $actor['is_super_admin'] !== 1) {
            self::fail('Only a Super admin can change payment secrets.', 403);
        }
        $map = ['flutterwaveSecretKey' => 'flutterwave.secret_key', 'flutterwaveWebhookHash' => 'flutterwave.webhook_hash'];
        $changed = [];
        foreach ($map as $field => $name) {
            if (is_array($value['clear'] ?? null) && in_array($field, $value['clear'], true)) {
                Secrets::clear($name);
                $changed[] = $name . ' removed';
                continue;
            }
            $plain = isset($value[$field]) && is_string($value[$field]) ? trim($value[$field]) : '';
            if ($plain === '') {
                continue;                                   // blank = keep what is saved
            }
            if (!preg_match('/^[A-Za-z0-9_.:\-]{8,200}$/', $plain)) {
                self::fail('That value has spaces or characters a key never contains. Paste it exactly as shown by your provider.');
            }
            if ($field === 'flutterwaveSecretKey' && preg_match('/PUBK/i', $plain)) {
                self::fail('That looks like a PUBLIC key. Paste the SECRET key here.');
            }
            try {
                Secrets::set($name, $plain, $actorId);
            } catch (\RuntimeException $e) {
                error_log($e->getMessage());
                self::fail('Secrets cannot be saved: the encryption key in backend/config.php is missing or invalid.', 500);
            }
            $changed[] = $name . ' saved';
        }
        Cache::flush();
        Audit::log($actorId, 'Updated payment secrets', null, $actor['edition_id'], ['changes' => $changed]);   // names only, never values
        Response::json(['ok' => true, 'secrets' => array_values(array_map(fn ($k, $v) => ['key' => $k] + $v, array_keys(Secrets::status()), Secrets::status()))]);
    }

    public static function save(string $section): never
    {
        $in = Request::json();
        if (in_array($section, self::SPECIAL, true)) {
            $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
            self::saveSpecial($section, $in, $actor);
        }
        if (!in_array($section, self::SECTIONS, true)) {
            self::fail('Unknown settings section.', 404);
        }
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        if ($section === 'gateway' && (int) $actor['is_super_admin'] !== 1) {
            self::fail('Only a Super admin can change payment gateway preferences.', 403);
        }
        $inputValue = (array) ($in['value'] ?? []);

            $value = self::clean($section, $inputValue);

            if ($section === 'communications') {
                try {
                    SmtpSettings::save($inputValue, (int) $actor['id']);
                } catch (\InvalidArgumentException $e) {
                    self::fail($e->getMessage());
                }
            }
        Database::pdo()->prepare(
            'INSERT INTO site_settings (setting_key, value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)'
        )->execute([$section, json_encode($value, JSON_UNESCAPED_UNICODE), $actor['id']]);
        Cache::flush();
        $names = ['contact' => 'contact details', 'social' => 'social links', 'communications' => 'email delivery settings', 'gateway' => 'payment gateway preferences', 'brand' => 'site branding', 'matric' => 'matric number format', 'progression' => 'level progression'];
        Audit::log((int) $actor['id'], 'Updated ' . $names[$section], null, $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    /** Asks Flutterwave about a reference that cannot exist. A reply (even "not found") proves the key works. */
    public static function testGateway(): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        if ((int) $actor['is_super_admin'] !== 1) {
            self::fail('Only a Super admin can test payment secrets.', 403);
        }
        try {
            Flutterwave::verifyByReference('oga-key-check-' . bin2hex(random_bytes(6)));
            Response::json(['ok' => true, 'message' => 'Flutterwave accepted the secret key.']);
        } catch (GatewayException $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            error_log($e->getMessage());
            Response::json(['ok' => false, 'message' => 'The saved key could not be read. Check the encryption key in backend/config.php.']);
        }
    }
}