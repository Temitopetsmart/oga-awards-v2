<?php
declare(strict_types=1);

namespace App;

final class SmtpSettings
{
    private static function row(): array
    {
        $row = Database::pdo()->query(
            'SELECT * FROM smtp_settings WHERE id = 1'
        )->fetch();

        return $row ?: [
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'encryption' => 'tls',
            'password_ciphertext' => null,
        ];
    }

    public static function publicSettings(): array
    {
        $row = self::row();

        return [
            'smtpHost' => $row['smtp_host'],
            'smtpPort' => (string) $row['smtp_port'],
            'smtpUsername' => $row['smtp_username'],
            'smtpEncryption' => $row['encryption'],
            'smtpPasswordConfigured' => !empty($row['password_ciphertext']),
        ];
    }

    public static function configured(): bool
    {
        $smtp = self::row();

        $st = Database::pdo()->prepare(
            "SELECT value FROM site_settings WHERE setting_key = 'communications'"
        );
        $st->execute();

        $comms = json_decode((string) ($st->fetchColumn() ?: '{}'), true);
        $comms = is_array($comms) ? $comms : [];

        $username = trim((string) $smtp['smtp_username']);

        return ($comms['emailMode'] ?? 'smtp') === 'smtp'
            && trim((string) $smtp['smtp_host']) !== ''
            && filter_var(
                (string) ($comms['fromEmail'] ?? ''),
                FILTER_VALIDATE_EMAIL
            ) !== false
            && ($username === '' || !empty($smtp['password_ciphertext']));
    }

    public static function connection(): array
    {
        $row = self::row();

        return [
            'host' => (string) $row['smtp_host'],
            'port' => (int) $row['smtp_port'],
            'username' => (string) $row['smtp_username'],
            'password' => !empty($row['password_ciphertext'])
                ? self::decrypt((string) $row['password_ciphertext'])
                : '',
            'encryption' => (string) $row['encryption'],
        ];
    }

    public static function save(array $input, int $actorId): void
    {
        $host = trim((string) ($input['smtpHost'] ?? ''));
        $port = trim((string) ($input['smtpPort'] ?? '587'));
        $username = trim((string) ($input['smtpUsername'] ?? ''));
        $encryption = (string) ($input['smtpEncryption'] ?? 'tls');
        $password = (string) ($input['smtpPassword'] ?? '');

        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new \InvalidArgumentException('Enter a valid SMTP host.');
        }

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException('Enter a valid SMTP port.');
        }

        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new \InvalidArgumentException('Choose a valid SMTP encryption method.');
        }

        if (strlen($username) > 190 || strlen($password) > 500) {
            throw new \InvalidArgumentException('SMTP credentials are too long.');
        }

        $encrypted = $password !== '' ? self::encrypt($password) : null;

        Database::pdo()->prepare(
            'INSERT INTO smtp_settings
                (id, smtp_host, smtp_port, smtp_username, encryption,
                 password_ciphertext, updated_by)
             VALUES (1, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                smtp_host = VALUES(smtp_host),
                smtp_port = VALUES(smtp_port),
                smtp_username = VALUES(smtp_username),
                encryption = VALUES(encryption),
                password_ciphertext =
                    COALESCE(VALUES(password_ciphertext), password_ciphertext),
                updated_by = VALUES(updated_by)'
        )->execute([
            $host,
            (int) $port,
            $username,
            $encryption,
            $encrypted,
            $actorId,
        ]);
    }

    private static function key(): string
    {
        $configured = trim((string) Env::get('SMTP_ENCRYPTION_KEY', ''));

        if (!preg_match('/^[a-f0-9]{64}$/i', $configured)) {
            throw new \RuntimeException(
                'SMTP encryption key is missing or invalid in backend/config.php.'
            );
        }

        $key = hex2bin($configured);

        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Invalid SMTP encryption key.');
        }

        return $key;
    }

    private static function encrypt(string $password): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('PHP OpenSSL extension is required.');
        }

        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $password,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'oga-awards-smtp-v1',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt SMTP password.');
        }

        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decrypt(string $stored): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('PHP OpenSSL extension is required.');
        }

        if (!str_starts_with($stored, 'v1:')) {
            throw new \RuntimeException('Unsupported SMTP password format.');
        }

        $raw = base64_decode(substr($stored, 3), true);

        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid encrypted SMTP password.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $password = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'oga-awards-smtp-v1'
        );

        if ($password === false) {
            throw new \RuntimeException(
                'Could not decrypt SMTP password. Check SMTP_ENCRYPTION_KEY.'
            );
        }

        return $password;
    }
}