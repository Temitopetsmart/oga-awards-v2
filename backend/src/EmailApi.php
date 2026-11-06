<?php
declare(strict_types=1);

namespace App;

/**
 * Admin → Email: delivery status, editable templates (with preview and test send) and the outbox viewer.
 * Sender and SMTP details are saved through SettingsApi ("communications"); this class never handles the SMTP password.
 * Needs settings.view to read and settings.edit to change anything.
 */
final class EmailApi
{
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    /** At most $max test emails per admin address in $minutes: stops the page being used to spam someone. */
    private static function throttle(string $kind, int $max, int $minutes): void
    {
        $id = hash('sha256', "$kind:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many test emails. Please wait a few minutes.', 429);
        }
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, 1)')->execute([$id, Request::ip()]);
    }

    private static function templateKey(string $key): string
    {
        if (!isset(MailTemplates::DEFAULTS[$key])) {
            self::fail('That email template does not exist.', 404);
        }
        return $key;
    }

    public static function overview(): never
    {
        Access::require('settings.view', (string) ($_GET['edition'] ?? ''));

        $comms = SettingsApi::value('communications');
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0];
        foreach (self::pdo()->query('SELECT status, COUNT(*) AS n FROM notification_outbox GROUP BY status')->fetchAll() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }

        Response::json([
            'ok'         => true,
            'configured' => Mailer::configured(),
            'delivery'   => [
                'fromName'   => (string) ($comms['fromName'] ?? ''),
                'fromEmail'  => (string) ($comms['fromEmail'] ?? ''),
                'replyTo'    => (string) ($comms['replyTo'] ?? ''),
                'footerText' => (string) ($comms['footerText'] ?? ''),
            ] + SmtpSettings::publicSettings(),
            'templates'  => MailTemplates::catalogue(),
            'counts'     => $counts,
        ]);
    }

    /** PUT /admin/email/templates/{key}   { edition, subject, body } */
    public static function saveTemplate(string $key): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        self::templateKey($key);
        $subject = trim((string) ($in['subject'] ?? ''));
        $body = str_replace(["\r\n", "\r"], "\n", (string) ($in['body'] ?? ''));
        if ($error = MailTemplates::validate($key, $subject, $body)) {
            self::fail($error);
        }
        MailTemplates::save($key, $subject, $body, (int) $actor['id']);
        Audit::log((int) $actor['id'], 'Edited email template', $key, $actor['edition_id']);
        Response::json(['ok' => true, 'templates' => MailTemplates::catalogue()]);
    }

    /** DELETE /admin/email/templates/{key}   { edition }  : back to the default wording */
    public static function resetTemplate(string $key): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        self::templateKey($key);
        MailTemplates::reset($key);
        Audit::log((int) $actor['id'], 'Reset email template to default', $key, $actor['edition_id']);
        Response::json(['ok' => true, 'templates' => MailTemplates::catalogue()]);
    }

    /** POST /admin/email/templates/{key}/preview   { edition, subject, body } : renders unsaved wording with sample values */
    public static function preview(string $key): never
    {
        $in = Request::json();
        Access::require('settings.view', Request::str($in, 'edition', 60));
        self::templateKey($key);
        $subject = trim((string) ($in['subject'] ?? ''));
        $body = str_replace(["\r\n", "\r"], "\n", (string) ($in['body'] ?? ''));
        if ($error = MailTemplates::validate($key, $subject, $body)) {
            self::fail($error);
        }
        $m = MailTemplates::preview($key, $subject, $body);
        Response::json(['ok' => true, 'subject' => $m['subject'], 'html' => $m['html'], 'text' => $m['text']]);
    }

    /** POST /admin/email/test   { edition, to, template?, subject?, body? } : sends one sample email right now */
    public static function sendTest(): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        $to = mb_strtolower(Request::str($in, 'to', 190));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::fail('Enter a valid email address to send the test to.');
        }
        if (!Mailer::configured()) {
            Response::json(['ok' => false, 'message' => 'Email is not set up yet. Fill in and save the Delivery tab first.']);
        }
        self::throttle('email-test', 10, 10);

        $key = Request::str($in, 'template', 60);
        if ($key !== '') {
            self::templateKey($key);
            $subject = trim((string) ($in['subject'] ?? '')) ?: MailTemplates::wording($key)['subject'];
            $body = str_replace(["\r\n", "\r"], "\n", (string) ($in['body'] ?? '')) ?: MailTemplates::wording($key)['body'];
            if ($error = MailTemplates::validate($key, $subject, $body)) {
                self::fail($error);
            }
            $m = MailTemplates::preview($key, $subject, $body);
            $m['subject'] = '[TEST] ' . $m['subject'];
        } else {
            $name = SettingsApi::brandName();
            $m = [
                'subject' => '[TEST] Email from ' . $name,
                'html'    => '<p>This is a test email from <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>. If you can read it, sending works.</p>',
                'text'    => "This is a test email from $name. If you can read it, sending works.\n",
            ];
        }

        $comms = SettingsApi::value('communications');
        try {
            Mailer::send($to, $m['subject'], $m['html'], $m['text'], $comms['replyTo'] ?? null, $comms['fromName'] ?? null, $comms['fromEmail'] ?? null);
        } catch (\Throwable $e) {
            Audit::log((int) $actor['id'], 'Test email failed', $key ?: 'plain', $actor['edition_id']);
            Response::json(['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 400)]);
        }
        Audit::log((int) $actor['id'], 'Sent a test email', $key ?: 'plain', $actor['edition_id']);
        Response::json(['ok' => true, 'message' => "Sent to $to. Check the inbox (and the spam folder)."]);
    }

    /** GET /admin/email/outbox?edition=&status=&type=&q=&page= */
    public static function outbox(): never
    {
        Access::require('settings.view', (string) ($_GET['edition'] ?? ''));
        $page = Outbox::listing(
            (string) ($_GET['status'] ?? ''),
            (string) ($_GET['type'] ?? ''),
            trim((string) ($_GET['q'] ?? '')),
            (int) ($_GET['page'] ?? 1)
        );
        Response::json(['ok' => true] + $page);
    }

    /** POST /admin/email/outbox/send   { edition } : send what is waiting, now (for hosts without a cron job) */
    public static function sendQueued(): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        if (!Mailer::configured()) {
            Response::json(['ok' => false, 'message' => 'Email is not set up yet. Fill in and save the Delivery tab first.']);
        }
        $r = Outbox::sendPending(50);
        Audit::log((int) $actor['id'], 'Sent the email queue', null, $actor['edition_id'], $r);
        Response::json(['ok' => true, 'result' => $r]);
    }

    /** POST /admin/email/outbox/{id}/retry   { edition } */
    public static function retry(int $id): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        $r = Outbox::retry($id);
        Audit::log((int) $actor['id'], 'Retried an email', (string) $id, $actor['edition_id'], ['ok' => $r['ok']]);
        Response::json(['ok' => $r['ok'], 'message' => $r['message']]);
    }
}