<?php
declare(strict_types=1);

namespace App;

/** Turns an outbox row (type + payload) into a subject and message. Returns null for unknown types. */
final class MailTemplates
{
    /** Payload keys that are secrets: removed from the outbox as soon as the email is sent. */
    public const SECRET_KEYS = ['token'];

    private static function url(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/' . ltrim($path, '/');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function layout(string $title, string $bodyHtml): string
    {
        $name = self::e((string) Env::get('MAIL_FROM_NAME', 'OGA Awards'));
        return '<div style="background:#f4f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;color:#222;line-height:1.55">'
            . '<h2 style="margin:0 0 16px;font-size:20px;color:#111">' . self::e($title) . '</h2>' . $bodyHtml
            . '<hr style="border:none;border-top:1px solid #e5e5ea;margin:24px 0 12px">'
            . '<p style="font-size:12px;color:#777;margin:0">' . $name . '. If you did not expect this email, you can ignore it.</p>'
            . '</div></div>';
    }

    private static function code(string $token): string
    {
        return '<p style="font-family:monospace;font-size:13px;word-break:break-all;background:#f4f4f6;padding:12px;border-radius:6px">' . self::e($token) . '</p>';
    }

    private static function button(string $href, string $label): string
    {
        return '<p><a href="' . self::e($href) . '" style="display:inline-block;background:#b8892b;color:#fff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:bold">' . self::e($label) . '</a></p>';
    }

    private static function lines(string $s): string
    {
        return nl2br(self::e($s), false);
    }

    /** @return array{subject:string,html:string,text:string} */
    private static function committeeStage(string $type, array $p): array
    {
        $name = (string) ($p['applicantName'] ?? 'there');
        $msg  = trim((string) ($p['message'] ?? ''));
        $html = '<p>Hello ' . self::e($name) . ',</p>';
        $text = "Hello $name,\n\n";
        $add  = function (string $h, string $t) use (&$html, &$text): void { $html .= $h; $text .= $t . "\n\n"; };

        switch ($type) {
            case 'committee_shortlisted':
                $subject = 'You have been shortlisted for screening';
                if ($msg !== '') { $add('<p>' . self::lines($msg) . '</p>', $msg); }
                $rows = ['Date' => $p['date'] ?? '', 'Time' => $p['time'] ?? '', 'Venue' => $p['venue'] ?? ''];
                $h = '<p>';
                $t = '';
                foreach ($rows as $k => $v) {
                    if ($v !== '') { $h .= '<strong>' . $k . ':</strong> ' . self::e((string) $v) . '<br>'; $t .= "$k: $v\n"; }
                }
                $add($h . '</p>', rtrim($t));
                if (trim((string) ($p['instructions'] ?? '')) !== '') { $add('<p>' . self::lines((string) $p['instructions']) . '</p>', (string) $p['instructions']); }
                break;
            case 'committee_approved':
                $subject = 'Your committee application has been approved';
                if ($msg !== '') { $add('<p>' . self::lines($msg) . '</p>', $msg); }
                if (trim((string) ($p['onboarding'] ?? '')) !== '') { $add('<p>' . self::lines((string) $p['onboarding']) . '</p>', (string) $p['onboarding']); }
                if (preg_match('#^https?://#i', (string) ($p['whatsappGroupUrl'] ?? ''))) {
                    $add(self::button((string) $p['whatsappGroupUrl'], 'Join the committee WhatsApp group'), 'Join the committee WhatsApp group: ' . $p['whatsappGroupUrl']);
                }
                break;
            case 'committee_appointed':
                $subject = 'You have been appointed to the committee';
                $line = 'You have been appointed as ' . ($p['position'] ?? 'a committee member') . (($p['unitName'] ?? '') !== '' ? ' in the ' . $p['unitName'] . ' unit' : '') . '.';
                $add('<p>' . self::e($line) . '</p>', $line);
                if ($msg !== '') { $add('<p>' . self::lines($msg) . '</p>', $msg); }
                break;
            case 'committee_reserve':
                $subject = 'Your committee application: reserve list';
                if ($msg !== '') { $add('<p>' . self::lines($msg) . '</p>', $msg); }
                break;
            default: // committee_unsuccessful
                $subject = 'Your committee application';
                if ($msg !== '') { $add('<p>' . self::lines($msg) . '</p>', $msg); }
        }
        return ['subject' => $subject, 'html' => self::layout($subject, $html), 'text' => rtrim($text) . "\n"];
    }

    /** @return array{subject:string,html:string,text:string}|null */
    public static function render(string $type, string $recipient, array $p): ?array
    {
        $mins = (int) ($p['expiresMinutes'] ?? 30);

        switch ($type) {
            case 'nominee_verify_email':
                $token = (string) ($p['token'] ?? '');
                if ($token === '') {
                    return null;
                }
                return [
                    'subject' => 'Verify your email address',
                    'html'    => self::layout('Verify your email', '<p>Use this code on your dashboard to verify your email address. It expires in ' . $mins . ' minutes.</p>' . self::code($token)),
                    'text'    => "Use this code on your dashboard to verify your email address (expires in $mins minutes):\n\n$token\n",
                ];

            case 'nominee_password_reset':
                $token = (string) ($p['token'] ?? '');
                if ($token === '') {
                    return null;
                }
                $link = self::url('forgot-password.html') . '?' . http_build_query(['email' => $recipient, 'token' => $token]);
                return [
                    'subject' => 'Reset your password',
                    'html'    => self::layout('Reset your password', '<p>We received a request to reset your password. The link below fills in your reset code. It expires in ' . $mins . ' minutes.</p>' . self::button($link, 'Choose a new password') . '<p>Reset code:</p>' . self::code($token)),
                    'text'    => "Reset your password (link expires in $mins minutes):\n$link\n\nReset code: $token\n",
                ];

            case 'admin_invitation':
                $token = (string) ($p['token'] ?? '');
                if ($token === '') {
                    return null;
                }
                $link    = self::url('accept-invite.html') . '?' . http_build_query(['token' => $token]);
                $edition = (string) ($p['editionName'] ?? 'the awards team');
                $expires = (string) ($p['expiresAt'] ?? '');
                return [
                    'subject' => 'You have been invited to the ' . $edition . ' team',
                    'html'    => self::layout('You are invited', '<p>You have been invited to help run <strong>' . self::e($edition) . '</strong>.</p>' . self::button($link, 'Accept invitation') . ($expires !== '' ? '<p style="color:#777;font-size:13px">This link expires ' . self::e($expires) . '.</p>' : '')),
                    'text'    => "You have been invited to help run $edition.\nAccept: $link\n" . ($expires !== '' ? "Expires: $expires\n" : ''),
                ];

            case 'committee_shortlisted':
            case 'committee_unsuccessful':
            case 'committee_approved':
            case 'committee_appointed':
            case 'committee_reserve':
                return self::committeeStage($type, $p);

            case 'committee_application_received':
                $name = (string) ($p['applicantName'] ?? 'there');
                return [
                    'subject' => 'We received your committee application',
                    'html'    => self::layout('Application received', '<p>Hello ' . self::e($name) . ',</p><p>Thank you for applying to join the organising committee. We have received your application and will be in touch about the next steps.</p>'),
                    'text'    => "Hello $name,\n\nThank you for applying to join the organising committee. We have received your application and will be in touch about the next steps.\n",
                ];
        }
        return null;
    }
}