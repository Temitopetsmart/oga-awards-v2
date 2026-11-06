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

        if ($row['edition_id'] !== null) {                       // lets templates use {{edition}}
            $ed = $pdo->prepare('SELECT name, edition_label FROM editions WHERE id = ?');
            $ed->execute([$row['edition_id']]);
            if ($e = $ed->fetch()) {
                $payload['_editionName'] = trim($e['name'] . ' ' . $e['edition_label']);
            }
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
            Mailer::send(
                (string) $row['recipient'],
                $msg['subject'],
                $msg['html'],
                $msg['text'],
                $comms['replyTo'] ?? null,
                $comms['fromName'] ?? null,
                $comms['fromEmail'] ?? null
            );
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

    /**
     * One page of the outbox for the admin. Never returns the payload (it can hold names and codes).
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public static function listing(string $status, string $type, string $search, int $page, int $perPage = 25): array
    {
        $where = ['1=1'];
        $args = [];
        if (in_array($status, ['queued', 'sent', 'failed'], true)) {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        if ($type !== '' && isset(MailTemplates::DEFAULTS[$type])) {
            $where[] = 'type = ?';
            $args[] = $type;
        }
        if ($search !== '') {
            $where[] = 'recipient LIKE ?';
            $args[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $pdo = Database::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM notification_outbox WHERE ' . implode(' AND ', $where));
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $st = $pdo->prepare('SELECT id, type, recipient, status, attempts, last_error, created_at, sent_at, payload FROM notification_outbox WHERE '
            . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $payload = json_decode((string) $r['payload'], true) ?: [];
            $rows[] = [
                'id'        => (string) $r['id'],
                'type'      => $r['type'],
                'label'     => MailTemplates::DEFAULTS[$r['type']]['label'] ?? $r['type'],
                'recipient' => $r['recipient'],
                'status'    => $r['status'],
                'attempts'  => (int) $r['attempts'],
                'error'     => $r['last_error'],
                'createdAt' => $r['created_at'],
                'sentAt'    => $r['sent_at'],
                'retryable' => $r['status'] === 'failed' && self::retryProblem($r['type'], $r['recipient'], $payload, (string) $r['created_at']) === null,
            ];
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Why a message cannot be sent again, or null if it can. */
    private static function retryProblem(string $type, string $recipient, array $payload, string $createdAt): ?string
    {
        if (MailTemplates::render($type, $recipient, $payload) === null) {
            return 'This message held a one-time code that was deleted for security once it failed. Ask the person to request a new one.';
        }
        $mins = (int) ($payload['expiresMinutes'] ?? 0);
        if ($mins > 0 && strtotime($createdAt) + $mins * 60 < time()) {
            return 'The code in this message has expired. Ask the person to request a new one.';
        }
        return null;
    }

    /** Put a failed message back in the queue and try it now. @return array{ok:bool,message:string} */
    public static function retry(int $id): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT * FROM notification_outbox WHERE id = ? AND status = 'failed'");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'Only failed messages can be retried.'];
        }
        $problem = self::retryProblem((string) $row['type'], (string) $row['recipient'], json_decode((string) $row['payload'], true) ?: [], (string) $row['created_at']);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }
        $pdo->prepare("UPDATE notification_outbox SET status = 'queued', attempts = 0, last_error = NULL WHERE id = ? AND status = 'failed'")->execute([$id]);
        $result = self::sendOne($id);
        if ($result === 'sent') {
            return ['ok' => true, 'message' => 'Sent.'];
        }
        $err = $pdo->prepare('SELECT last_error FROM notification_outbox WHERE id = ?');
        $err->execute([$id]);
        return ['ok' => false, 'message' => (string) ($err->fetchColumn() ?: 'It could not be sent. It stays in the queue and will be tried again.')];
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