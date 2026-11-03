<?php
declare(strict_types=1);

namespace App;

/** The email queue: add a message, then send what is waiting. Secrets are wiped after sending. */
final class Outbox
{
    private const MAX_ATTEMPTS = 5;

    public static function queue(string $type, string $recipient, array $payload, ?int $editionId = null): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO notification_outbox (edition_id, type, recipient, payload) VALUES (?, ?, ?, ?)')
            ->execute([$editionId, $type, $recipient, json_encode($payload)]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Queue, then send as soon as the response has gone out (and any open transaction has finished).
     * Never throws: a failure just leaves the message queued for the next send-outbox.php run.
     */
    public static function queueAndSend(string $type, string $recipient, array $payload, ?int $editionId = null): void
    {
        $id = self::queue($type, $recipient, $payload, $editionId);
        if (!Mailer::configured()) {
            return;
        }
        register_shutdown_function(static function () use ($id): void {
            try {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                self::sendOne($id);     // 'skipped' if the transaction that queued it was rolled back
            } catch (\Throwable $e) {
                error_log('Outbox send failed: ' . $e->getMessage());
            }
        });
    }

    /** @return array{sent:int,failed:int,expired:int,skipped:int} */
    public static function sendPending(int $limit = 50): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0];
        $ids = Database::pdo()->query("SELECT id FROM notification_outbox WHERE status = 'queued' ORDER BY id LIMIT " . max(1, $limit))->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $out[self::sendOne((int) $id)]++;
        }
        return $out;
    }

    /** @return 'sent'|'failed'|'expired'|'skipped' */
    public static function sendOne(int $id): string
    {
        $pdo = Database::pdo();
        $st  = $pdo->prepare("SELECT * FROM notification_outbox WHERE id = ? AND status = 'queued'");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return 'skipped';
        }
        $payload = json_decode((string) $row['payload'], true) ?: [];

        // A code that has already expired is useless: do not send it, and wipe it.
        $mins = (int) ($payload['expiresMinutes'] ?? 0);
        if ($mins > 0 && strtotime((string) $row['created_at']) + $mins * 60 < time()) {
            self::finish($id, 'failed', 'Expired before it could be sent.', $payload);
            return 'expired';
        }

        $msg = MailTemplates::render((string) $row['type'], (string) $row['recipient'], $payload);
        if ($msg === null) {
            self::finish($id, 'failed', 'No email template for this message type.', $payload);
            return 'failed';
        }

        // Claim it (another sender may be working on the same row): only one UPDATE can win.
        $claim = $pdo->prepare("UPDATE notification_outbox SET attempts = attempts + 1 WHERE id = ? AND status = 'queued' AND attempts = ?");
        $claim->execute([$id, $row['attempts']]);
        if ($claim->rowCount() !== 1) {
            return 'skipped';
        }

        $comms = self::communications();
        try {
            Mailer::send((string) $row['recipient'], $msg['subject'], $msg['html'], $msg['text'], $comms['replyTo'] ?? null, $comms['fromName'] ?? null);
        } catch (\Throwable $e) {
            $attempts = (int) $row['attempts'] + 1;
            $reason   = mb_substr($e->getMessage(), 0, 480);
            if ($attempts >= self::MAX_ATTEMPTS) {
                self::finish($id, 'failed', $reason, $payload);
                return 'failed';
            }
            $pdo->prepare('UPDATE notification_outbox SET last_error = ? WHERE id = ?')->execute([$reason, $id]);
            return 'failed';
        }
        self::finish($id, 'sent', null, $payload);
        return 'sent';
    }

    /** Close a message and wipe any secret from the stored payload. */
    private static function finish(int $id, string $status, ?string $error, array $payload): void
    {
        foreach (MailTemplates::SECRET_KEYS as $k) {
            unset($payload[$k]);
        }
        Database::pdo()->prepare('UPDATE notification_outbox SET status = ?, last_error = ?, payload = ?, sent_at = IF(? = \'sent\', NOW(), sent_at) WHERE id = ?')
            ->execute([$status, $error, json_encode($payload), $status, $id]);
    }

    private static function communications(): array
    {
        $st = Database::pdo()->prepare("SELECT value FROM site_settings WHERE setting_key = 'communications'");
        $st->execute();
        $v = json_decode((string) ($st->fetchColumn() ?: '{}'), true);
        return is_array($v) ? $v : [];
    }
}