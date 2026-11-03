<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** Sends one email through SMTP. Credentials come only from backend/config.php. */
final class Mailer
{
    public static function configured(): bool
    {
        return (string) Env::get('SMTP_HOST', '') !== '' && (string) Env::get('MAIL_FROM', '') !== '';
    }

    /** @throws \RuntimeException when the message could not be sent */
    public static function send(string $to, string $subject, string $html, string $text, ?string $replyTo = null, ?string $fromName = null): void
    {
        if (!self::configured()) {
            throw new \RuntimeException('Email is not configured (SMTP_HOST and MAIL_FROM in backend/config.php).');
        }
        $lib = dirname(__DIR__) . '/lib/PHPMailer/src/';
        foreach (['Exception', 'PHPMailer', 'SMTP'] as $f) {
            require_once $lib . $f . '.php';
        }

        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host       = (string) Env::get('SMTP_HOST');
            $m->Port       = (int) Env::get('SMTP_PORT', '587');
            $user          = (string) Env::get('SMTP_USER', '');
            $m->SMTPAuth   = $user !== '';
            $m->Username   = $user;
            $m->Password   = (string) Env::get('SMTP_PASSWORD', '');
            $secure        = strtolower((string) Env::get('SMTP_SECURE', 'tls'));
            $m->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $m->SMTPAutoTLS = $secure === 'tls';
            $m->Timeout    = 10;
            $m->CharSet    = 'UTF-8';
            $m->setFrom((string) Env::get('MAIL_FROM'), $fromName ?: (string) Env::get('MAIL_FROM_NAME', 'OGA Awards'));
            if ($replyTo) {
                $m->addReplyTo($replyTo);
            }
            $m->addAddress($to);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;
            $m->AltBody = $text;
            $m->send();
        } catch (MailException $e) {
            throw new \RuntimeException($m->ErrorInfo !== '' ? $m->ErrorInfo : $e->getMessage());
        }
    }
}