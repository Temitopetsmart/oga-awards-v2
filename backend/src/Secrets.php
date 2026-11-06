<?php
declare(strict_types=1);

namespace App;

/**
 * Secrets managed from the admin (Flutterwave keys). Stored AES-256-GCM encrypted in app_secrets.
 * The master key stays in backend/config.php: SMTP_ENCRYPTION_KEY if already set (so existing SMTP passwords keep working), otherwise APP_ENCRYPTION_KEY.
 * A secret is never returned to the browser, never logged and never written to the audit log.
 */
final class Secrets
{
    /** The only secrets that may be stored: name => label. */
    public const ALLOWED = [
        'flutterwave.secret_key'   => 'Flutterwave secret key',
        'flutterwave.webhook_hash' => 'Flutterwave webhook secret hash',
    ];

    private static function masterKey(): string
    {
        $hex = trim((string) (Env::get('SMTP_ENCRYPTION_KEY') ?: Env::get('APP_ENCRYPTION_KEY', '')));
        if (!preg_match('/^[a-f0-9]{64}$/i', $hex)) {
            throw new \RuntimeException('Encryption key is missing or invalid in backend/config.php.');
        }
        return (string) hex2bin($hex);
    }

    private static function aad(string $name): string
    {
        return 'oga-awards-secret-v1:' . $name;
    }

    private static function assertAllowed(string $name): void
    {
        if (!isset(self::ALLOWED[$name])) {
            throw new \InvalidArgumentException('Unknown secret.');
        }
    }

    public static function encrypt(string $name, string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, $iv, $tag, self::aad($name), 16);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the secret.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $name, string $stored): string
    {
        $raw = str_starts_with($stored, 'v1:') ? base64_decode(substr($stored, 3), true) : false;
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Stored secret is unreadable.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::masterKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::aad($name));
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt a stored secret. Has the encryption key in config.php changed?');
        }
        return $plain;
    }

    /** The decrypted value, or null when it was never saved in the admin. Throws if it is saved but unreadable. */
    public static function get(string $name): ?string
    {
        self::assertAllowed($name);
        $st = Database::pdo()->prepare('SELECT ciphertext FROM app_secrets WHERE secret_key = ?');
        $st->execute([$name]);
        $stored = $st->fetchColumn();
        return $stored === false ? null : self::decrypt($name, (string) $stored);
    }

    public static function set(string $name, string $plain, int $actorId): void
    {
        self::assertAllowed($name);
        Database::pdo()->prepare(
            'INSERT INTO app_secrets (secret_key, ciphertext, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), updated_by = VALUES(updated_by)'
        )->execute([$name, self::encrypt($name, $plain), $actorId]);
    }

    public static function clear(string $name): void
    {
        self::assertAllowed($name);
        Database::pdo()->prepare('DELETE FROM app_secrets WHERE secret_key = ?')->execute([$name]);
    }

    /** name => ['saved' => bool, 'updatedAt' => ?string]. Never the value. */
    public static function status(): array
    {
        $out = [];
        foreach (self::ALLOWED as $name => $label) {
            $out[$name] = ['label' => $label, 'saved' => false, 'updatedAt' => null];
        }
        foreach (Database::pdo()->query('SELECT secret_key, updated_at FROM app_secrets')->fetchAll() as $r) {
            if (isset($out[$r['secret_key']])) {
                $out[$r['secret_key']]['saved'] = true;
                $out[$r['secret_key']]['updatedAt'] = $r['updated_at'];
            }
        }
        return $out;
    }
}