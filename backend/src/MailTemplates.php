<?php
declare(strict_types=1);

namespace App;

/**
 * Turns an outbox row (type + payload) into a subject and message.
 *
 * The default wording of every email is in DEFAULTS. The admin can replace the subject and body of any of them
 * (Admin → Email → Templates); that copy lives in the email_templates table and wins over the default.
 *
 * Body format (plain text, so nothing can break the layout):
 *   - a blank line starts a new paragraph; a single line break stays a line break
 *   - {{placeholder}} is replaced with the value; every value is HTML-escaped
 *   - a line whose placeholders are ALL empty is dropped (so "Date: {{date}}" vanishes when there is no date)
 *   - a paragraph that is only [[Button label | {{link}}]] becomes a button
 *   - a paragraph that is only a code placeholder ({{code}}) is shown as a code block
 */
final class MailTemplates
{
    /** Payload keys that are secrets: removed from the outbox as soon as the email is sent. */
    public const SECRET_KEYS = ['token'];

    /** Available in every template. */
    public const GLOBAL_PLACEHOLDERS = [
        'site_name'     => 'The site name from Settings → Branding',
        'site_url'      => 'The web address of the site',
        'contact_email' => 'The contact email from Settings',
        'edition'       => 'The edition name, for example OGA 10.0 (when the message belongs to one)',
    ];

    /** Placeholders whose value is shown as a code block when alone in a paragraph. */
    private const CODE_PLACEHOLDERS = ['code'];

    public const DEFAULTS = [
        'nominee_verify_email' => [
            'label' => 'Verify email address', 'group' => 'Nominee account',
            'description' => 'Sent after someone registers as a nominee, with the code that verifies their email.',
            'placeholders' => ['code' => 'The one-time code', 'expires_minutes' => 'Minutes the code stays valid'],
            'required' => ['code'], 'needs' => ['token'],
            'subject' => 'Verify your email address',
            'body' => "Use this code on your dashboard to verify your email address. It expires in {{expires_minutes}} minutes.\n\n{{code}}",
        ],
        'nominee_password_reset' => [
            'label' => 'Password reset', 'group' => 'Nominee account',
            'description' => 'Sent when a nominee asks to reset their password.',
            'placeholders' => ['code' => 'The reset code', 'link' => 'Link that opens the reset form with the code filled in', 'expires_minutes' => 'Minutes the code stays valid'],
            'required' => ['code'], 'needs' => ['token'],
            'subject' => 'Reset your password',
            'body' => "We received a request to reset your password. The link below fills in your reset code. It expires in {{expires_minutes}} minutes.\n\n[[Choose a new password | {{link}}]]\n\nReset code:\n\n{{code}}\n\nIf you did not ask for this, you can ignore this email.",
        ],
        'nominee_entry_approved' => [
            'label' => 'Nominee entry approved', 'group' => 'Nominee account',
            'description' => 'Sent when an admin approves a nominee’s entry for a category.',
            'placeholders' => ['category' => 'The category name', 'link' => 'Link to the nominee dashboard'],
            'required' => [], 'needs' => [],
            'subject' => 'Your {{edition}} entry is approved',
            'body' => "Good news: your entry in {{category}} has been approved for {{edition}}.\n\nShare your profile and ask your friends to vote.\n\n[[Open my dashboard | {{link}}]]",
        ],
        'nominee_entry_rejected' => [
            'label' => 'Nominee entry needs changes', 'group' => 'Nominee account',
            'description' => 'Sent when an admin rejects a nominee’s entry. The reviewer’s note is included.',
            'placeholders' => ['category' => 'The category name', 'note' => 'The reviewer’s note (may be empty)', 'link' => 'Link to the nominee dashboard'],
            'required' => [], 'needs' => [],
            'subject' => 'Your {{edition}} entry needs changes',
            'body' => "Your entry in {{category}} was not approved yet.\n\nReviewer’s note: {{note}}\n\nYou can update your details and send it for review again from your dashboard.\n\n[[Open my dashboard | {{link}}]]",
        ],
        'admin_invitation' => [
            'label' => 'Team invitation', 'group' => 'Team',
            'description' => 'Sent when an admin invites someone to help run an edition.',
            'placeholders' => ['link' => 'The invitation link', 'expires_at' => 'When the invitation expires'],
            'required' => ['link'], 'needs' => ['token'],
            'subject' => 'You have been invited to the {{edition}} team',
            'body' => "You have been invited to help run {{edition}}.\n\n[[Accept invitation | {{link}}]]\n\nThis link expires {{expires_at}}.",
        ],
        'committee_application_received' => [
            'label' => 'Committee application received', 'group' => 'Committee recruitment',
            'description' => 'Sent straight after someone applies to join the committee.',
            'placeholders' => ['name' => 'The applicant\'s name'], 'required' => [], 'needs' => [],
            'subject' => 'We received your committee application',
            'body' => "Hello {{name}},\n\nThank you for applying to join the organising committee. We have received your application and will be in touch about the next steps.",
        ],
        'committee_shortlisted' => [
            'label' => 'Shortlisted for screening', 'group' => 'Committee recruitment',
            'description' => 'Sent when an applicant is shortlisted. Date, time, venue and instructions come from the screening details.',
            'placeholders' => ['name' => 'The applicant\'s name', 'message' => 'The screening message set for the edition', 'date' => 'Screening date', 'time' => 'Screening time', 'venue' => 'Screening venue', 'instructions' => 'Screening instructions'],
            'required' => [], 'needs' => [],
            'subject' => 'You have been shortlisted for screening',
            'body' => "Hello {{name}},\n\n{{message}}\n\nDate: {{date}}\nTime: {{time}}\nVenue: {{venue}}\n\n{{instructions}}",
        ],
        'committee_approved' => [
            'label' => 'Application approved', 'group' => 'Committee recruitment',
            'description' => 'Sent when an application is approved.',
            'placeholders' => ['name' => 'The applicant\'s name', 'message' => 'The approval message', 'onboarding' => 'The onboarding message', 'whatsapp_link' => 'The committee WhatsApp group link (empty if none is set)'],
            'required' => [], 'needs' => [],
            'subject' => 'Your committee application has been approved',
            'body' => "Hello {{name}},\n\n{{message}}\n\n{{onboarding}}\n\n[[Join the committee WhatsApp group | {{whatsapp_link}}]]",
        ],
        'committee_appointed' => [
            'label' => 'Appointed to the committee', 'group' => 'Committee recruitment',
            'description' => 'Sent when an approved applicant is appointed to a position.',
            'placeholders' => ['name' => 'The applicant\'s name', 'position' => 'The position', 'unit' => 'The unit (empty if none)', 'message' => 'An optional note'],
            'required' => [], 'needs' => [],
            'subject' => 'You have been appointed to the committee',
            'body' => "Hello {{name}},\n\nYou have been appointed as {{position}}.\n\nUnit: {{unit}}\n\n{{message}}",
        ],
        'committee_reserve' => [
            'label' => 'Reserve list', 'group' => 'Committee recruitment',
            'description' => 'Sent when an applicant is placed on the reserve list.',
            'placeholders' => ['name' => 'The applicant\'s name', 'message' => 'The reserve-list message'], 'required' => [], 'needs' => [],
            'subject' => 'Your committee application: reserve list',
            'body' => "Hello {{name}},\n\n{{message}}",
        ],
        'committee_unsuccessful' => [
            'label' => 'Application unsuccessful', 'group' => 'Committee recruitment',
            'description' => 'Sent when an application is not successful.',
            'placeholders' => ['name' => 'The applicant\'s name', 'message' => 'The message set for unsuccessful applicants'], 'required' => [], 'needs' => [],
            'subject' => 'Your committee application',
            'body' => "Hello {{name}},\n\n{{message}}",
        ],
    ];

    /** Sample values used for previews and test emails. */
    private const SAMPLES = [
        'code' => 'K7Q2-M9XD-4TRA', 'expires_minutes' => '30', 'link' => 'https://example.com/open-this-link', 'expires_at' => '30 Dec 2027, 5:00 PM',
        'name' => 'Ada Okafor', 'message' => 'This is the message the committee wrote for this stage.', 'date' => 'Saturday, 12 December', 'time' => '10:00 AM',
        'venue' => 'Main Hall, OGITECH', 'instructions' => 'Please arrive 15 minutes early and bring your student ID.', 'onboarding' => 'Welcome aboard. Your first meeting is on Monday.',
        'whatsapp_link' => 'https://chat.whatsapp.com/sample', 'position' => 'Media Lead', 'unit' => 'Media & Publicity',
    ];

    private static ?array $stored = null;

    // ------------------------------------------------------------------ helpers

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function url(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/' . ltrim($path, '/');
    }

    /** The admin's saved wording, keyed by template. */
    private static function overrides(): array
    {
        if (self::$stored === null) {
            self::$stored = [];
            foreach (Database::pdo()->query('SELECT template_key, subject, body FROM email_templates')->fetchAll() as $r) {
                self::$stored[$r['template_key']] = ['subject' => $r['subject'], 'body' => $r['body']];
            }
        }
        return self::$stored;
    }

    /** Forget cached wording (after an admin saves or resets a template, and in tests). */
    public static function forget(): void
    {
        self::$stored = null;
    }

    /** @return array{subject:string,body:string,custom:bool} */
    public static function wording(string $type): array
    {
        $d = self::DEFAULTS[$type];
        $o = self::overrides()[$type] ?? null;
        return $o ? ['subject' => $o['subject'], 'body' => $o['body'], 'custom' => true]
                  : ['subject' => $d['subject'], 'body' => $d['body'], 'custom' => false];
    }

    /** What the admin page lists. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $d) {
            $w = self::wording($key);
            $out[] = [
                'key' => $key, 'label' => $d['label'], 'group' => $d['group'], 'description' => $d['description'],
                'placeholders' => $d['placeholders'] + self::GLOBAL_PLACEHOLDERS,
                'subject' => $w['subject'], 'body' => $w['body'], 'custom' => $w['custom'],
                'defaultSubject' => $d['subject'], 'defaultBody' => $d['body'],
            ];
        }
        return $out;
    }

    /** Returns an error message, or null if the wording is acceptable for this template. */
    public static function validate(string $type, string $subject, string $body): ?string
    {
        $d = self::DEFAULTS[$type] ?? null;
        if ($d === null) {
            return 'Unknown email template.';
        }
        if ($subject === '' || mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
            return 'The subject must be one line of 1 to 200 characters.';
        }
        if (trim($body) === '' || mb_strlen($body) > 5000) {
            return 'The message must be 1 to 5000 characters.';
        }
        $allowed = array_keys($d['placeholders'] + self::GLOBAL_PLACEHOLDERS);
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $subject . "\n" . $body, $m);
        $unknown = array_values(array_unique(array_diff($m[1], $allowed)));
        if ($unknown) {
            return 'Unknown placeholder: {{' . implode('}}, {{', $unknown) . '}}. Use only the ones listed beside the editor.';
        }
        foreach ($d['required'] as $need) {
            if (!str_contains($body, '{{' . $need . '}}')) {
                return 'The message must contain {{' . $need . '}}, otherwise the email would be useless.';
            }
        }
        if (substr_count($body, '{{') !== substr_count($body, '}}')) {
            return 'A placeholder is missing its closing }}.';
        }
        return null;
    }

    public static function save(string $type, string $subject, string $body, int $actorId): void
    {
        Database::pdo()->prepare(
            'INSERT INTO email_templates (template_key, subject, body, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), updated_by = VALUES(updated_by)'
        )->execute([$type, $subject, $body, $actorId]);
        self::forget();
    }

    public static function reset(string $type): void
    {
        Database::pdo()->prepare('DELETE FROM email_templates WHERE template_key = ?')->execute([$type]);
        self::forget();
    }

    // ------------------------------------------------------------------ values

    /** @return array<string,string> placeholder => raw value */
    private static function values(string $type, string $recipient, array $p): array
    {
        $mins = (string) (int) ($p['expiresMinutes'] ?? 30);
        $name = (string) ($p['applicantName'] ?? '') !== '' ? (string) $p['applicantName'] : 'there';
        $contact = SettingsApi::value('contact');

        $v = [
            'site_name'     => SettingsApi::brandName(),
            'site_url'      => rtrim(self::url(''), '/'),
            'contact_email' => (string) ($contact['email'] ?? ''),
            'edition'       => trim((string) ($p['_editionName'] ?? ($p['editionName'] ?? ''))),
            'name'          => $name,
            'message'       => trim((string) ($p['message'] ?? '')),
        ];
        switch ($type) {
            case 'nominee_verify_email':
                $v += ['code' => (string) ($p['token'] ?? ''), 'expires_minutes' => $mins];
                break;
            case 'nominee_password_reset':
                $token = (string) ($p['token'] ?? '');
                $v += ['code' => $token, 'expires_minutes' => $mins, 'link' => self::url('forgot-password') . '?' . http_build_query(['email' => $recipient, 'token' => $token])];
                break;
            case 'nominee_entry_approved':
            case 'nominee_entry_rejected':
                $v += ['category' => (string) ($p['categoryName'] ?? ''), 'note' => trim((string) ($p['note'] ?? '')), 'link' => self::url('dashboard')];
                break;
            case 'admin_invitation':
                $v += ['link' => self::url('accept-invite') . '?' . http_build_query(['token' => (string) ($p['token'] ?? '')]), 'expires_at' => (string) ($p['expiresAt'] ?? '')];
                if ($v['edition'] === '') {
                    $v['edition'] = 'the awards team';
                }
                break;
            case 'committee_shortlisted':
                $v += ['date' => (string) ($p['date'] ?? ''), 'time' => (string) ($p['time'] ?? ''), 'venue' => (string) ($p['venue'] ?? ''), 'instructions' => trim((string) ($p['instructions'] ?? ''))];
                break;
            case 'committee_approved':
                $link = (string) ($p['whatsappGroupUrl'] ?? '');
                $v += ['onboarding' => trim((string) ($p['onboarding'] ?? '')), 'whatsapp_link' => preg_match('#^https?://#i', $link) ? $link : ''];
                break;
            case 'committee_appointed':
                $v += ['position' => (string) (($p['position'] ?? '') !== '' ? $p['position'] : 'a committee member'), 'unit' => (string) ($p['unitName'] ?? '')];
                break;
        }
        return $v;
    }

    // ------------------------------------------------------------------ composing

    private static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $text) ?? $text;
    }

    /** True when the line has placeholders and every one of them is empty. */
    private static function emptyLine(string $line, array $vars): bool
    {
        if (!preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $line, $m)) {
            return false;
        }
        foreach ($m[1] as $k) {
            if (trim((string) ($vars[$k] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function layout(string $title, string $bodyHtml, string $footer): string
    {
        return '<div style="background:#f4f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:28px;color:#222;line-height:1.55">'
            . '<h2 style="margin:0 0 16px;font-size:20px;color:#111">' . self::e($title) . '</h2>' . $bodyHtml
            . '<hr style="border:none;border-top:1px solid #e5e5ea;margin:24px 0 12px">'
            . '<p style="font-size:12px;color:#777;margin:0">' . self::e($footer) . '</p>'
            . '</div></div>';
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function compose(string $subject, string $body, array $vars): array
    {
        $subjectOut = trim(preg_replace('/\s+/', ' ', self::fill($subject, $vars)) ?? '');
        $html = '';
        $text = [];

        foreach (preg_split('/\n{2,}/', trim(str_replace(["\r\n", "\r"], "\n", $body))) ?: [] as $para) {
            $original = trim($para);
            $lines = [];
            foreach (explode("\n", $para) as $line) {
                if (!self::emptyLine($line, $vars)) {
                    $lines[] = self::fill($line, $vars);
                }
            }
            $para = trim(implode("\n", $lines));
            if ($para === '') {
                continue;
            }
            if (preg_match('/^\[\[(.+?)\|(.+?)\]\]$/s', $para, $b)) {
                $label = trim($b[1]);
                $href = trim($b[2]);
                if (preg_match('#^https?://#i', $href) && $label !== '') {
                    $html .= '<p><a href="' . self::e($href) . '" style="display:inline-block;background:#b8892b;color:#fff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:bold">' . self::e($label) . '</a></p>';
                    $text[] = $label . ': ' . $href;
                }
                continue;
            }
            $isCode = (bool) preg_match('/^\{\{\s*(' . implode('|', self::CODE_PLACEHOLDERS) . ')\s*\}\}$/', $original);
            if ($isCode) {
                $html .= '<p style="font-family:monospace;font-size:13px;word-break:break-all;background:#f4f4f6;padding:12px;border-radius:6px">' . self::e($para) . '</p>';
            } else {
                $html .= '<p>' . nl2br(self::e($para), false) . '</p>';
            }
            $text[] = $para;
        }

        $footer = trim((string) (SettingsApi::value('communications')['footerText'] ?? ''));
        $footer = SettingsApi::brandName() . '. ' . ($footer !== '' ? $footer : 'If you did not expect this email, you can ignore it.');

        return ['subject' => $subjectOut, 'html' => self::layout($subjectOut, $html, $footer), 'text' => implode("\n\n", $text) . "\n"];
    }

    // ------------------------------------------------------------------ public entry points

    /** @return array{subject:string,html:string,text:string}|null */
    public static function render(string $type, string $recipient, array $p): ?array
    {
        $d = self::DEFAULTS[$type] ?? null;
        if ($d === null) {
            return null;
        }
        foreach ($d['needs'] as $key) {                       // a code or token that has been wiped cannot be re-sent
            if ((string) ($p[$key] ?? '') === '') {
                return null;
            }
        }
        $w = self::wording($type);
        return self::compose($w['subject'], $w['body'], self::values($type, $recipient, $p));
    }

    /** A preview or test email: sample values, never real codes. Uses the given wording (even if not saved yet). */
    public static function preview(string $type, string $subject, string $body): array
    {
        $d = self::DEFAULTS[$type];
        $vars = self::values($type, 'person@example.com', ['_editionName' => 'OGA 10.0', 'expiresMinutes' => 30]);
        foreach (array_keys($d['placeholders']) as $k) {
            $vars[$k] = self::SAMPLES[$k] ?? '';
        }
        if ($vars['edition'] === '') {
            $vars['edition'] = 'OGA 10.0';
        }
        return self::compose($subject, $body, $vars);
    }
}