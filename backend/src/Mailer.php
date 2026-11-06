<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function configured(): bool
    {
        return SmtpSettings::configured();
    }

    public static function send(
        string $to,
        string $subject,
        string $html,
        string $text,
        ?string $replyTo = null,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): void {
        if (!self::configured()) {
            throw new \RuntimeException(
                'Email is not configured. Check the SMTP settings in the admin dashboard.'
            );
        }

        $smtp = SmtpSettings::connection();

        $lib = dirname(__DIR__) . '/lib/PHPMailer/src/';

        foreach (['Exception', 'PHPMailer', 'SMTP'] as $file) {
            require_once $lib . $file . '.php';
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = $smtp['port'];

            $mail->SMTPAuth = $smtp['username'] !== '';
            $mail->Username = $smtp['username'];
            $mail->Password = $smtp['password'];

            $secure = strtolower($smtp['encryption']);

            $mail->SMTPSecure = match ($secure) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };

            $mail->SMTPAutoTLS = $secure === 'tls';
            $mail->Timeout = 10;
            $mail->CharSet = 'UTF-8';

            if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('A valid sender email is required.');
            }

            $mail->setFrom(
                $fromEmail,
                $fromName ?: SettingsApi::brandName()
            );

            if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }

            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;

            $mail->send();

        } catch (MailException $e) {
            throw new \RuntimeException(
                $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()
            );
        }
    }
}