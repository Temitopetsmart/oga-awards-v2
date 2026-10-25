<?php
declare(strict_types=1);

namespace App;

/**
 * Site-wide settings kept in the database: contact details, social links, email preferences, gateway preferences.
 * Secrets (Flutterwave secret key, webhook hash, SMTP password) are NEVER stored here: they live in config.php.
 */
final class SettingsApi
{
    private const SECTIONS = ['contact', 'social', 'communications', 'gateway'];
    private const TEMPLATES = ['templateReceived', 'templateShortlisted', 'templateApproved', 'templateUnsuccessful', 'templateReconsideration'];

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
        return ['contact' => $s['contact'] ?? new \stdClass(), 'social' => $s['social'] ?? []];
    }

    public static function read(): never
    {
        Access::require('settings.view', (string) ($_GET['edition'] ?? ''));
        $s = self::all();
        Response::json(['ok' => true, 'settings' => array_map(fn ($k) => $s[$k] ?? null, array_combine(self::SECTIONS, self::SECTIONS))]);
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
                $mode = (string) ($v['emailMode'] ?? '');
                if (!in_array($mode, ['smtp', 'domain', 'api'], true)) {
                    self::fail('Choose an email delivery method.');
                }
                $port = (string) ($v['smtpPort'] ?? '');
                if ($mode === 'smtp' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
                    self::fail('Enter a valid SMTP port.');
                }
                $host = Request::str($v, 'smtpHost', 190);
                if ($host !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
                    self::fail('Enter a valid SMTP host name.');
                }
                $out = [
                    'emailMode'        => $mode,
                    'smtpHost'         => $host,
                    'smtpPort'         => $port,
                    'smtpUsername'     => Request::str($v, 'smtpUsername', 190),
                    'fromName'         => Request::str($v, 'fromName', 100),
                    'fromEmail'        => self::email($v, 'fromEmail'),
                    'replyTo'          => self::email($v, 'replyTo'),
                    'whatsappGroupUrl' => self::https($v, 'whatsappGroupUrl'),
                ];
                foreach (self::TEMPLATES as $t) {
                    $text = (string) ($v[$t] ?? '');
                    if (mb_strlen($text) > 4000) {
                        self::fail('A message template can be at most 4000 characters.');
                    }
                    $out[$t] = $text;
                }
                return $out;

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

    public static function save(string $section): never
    {
        if (!in_array($section, self::SECTIONS, true)) {
            self::fail('Unknown settings section.', 404);
        }
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        if ($section === 'gateway' && (int) $actor['is_super_admin'] !== 1) {
            self::fail('Only a Super admin can change payment gateway preferences.', 403);
        }
        $value = self::clean($section, (array) ($in['value'] ?? []));
        Database::pdo()->prepare(
            'INSERT INTO site_settings (setting_key, value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)'
        )->execute([$section, json_encode($value, JSON_UNESCAPED_UNICODE), $actor['id']]);
        Cache::flush();
        $names = ['contact' => 'contact details', 'social' => 'social links', 'communications' => 'recruitment communications settings', 'gateway' => 'payment gateway preferences'];
        Audit::log((int) $actor['id'], 'Updated ' . $names[$section], null, $actor['edition_id']);
        Response::json(['ok' => true]);
    }
}