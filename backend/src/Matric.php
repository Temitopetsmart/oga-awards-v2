<?php
declare(strict_types=1);

namespace App;

/**
 * Matric number format, configured in the admin (Settings → Matric number).
 * Pattern language:  #  = a digit     @  = a letter     anything else = itself.
 * A token may be followed by {n} or {n,m}.   Example: @{3,5}/#{2}/#{4}  matches HSWF/24/0010.
 * Phase 7 attaches the field to the forms; this class only holds the rules.
 */
final class Matric
{
    public const DEFAULTS = [
        'maxLength' => 13,
        'formats'   => [
            ['label' => 'Full-time', 'pattern' => '@{3,5}/#{2}/#{4}',  'example' => 'HSWF/24/0010'],
            ['label' => 'Part-time', 'pattern' => '@{3,5}/#{2}E/#{4}', 'example' => 'HSWF/24E/0010'],
        ],
    ];

    /** @return array{0:string,1:int,2:int} regex, shortest and longest string the pattern can match */
    public static function compile(string $pattern): array
    {
        $re = '';
        $minLen = 0;
        $maxLen = 0;
        $n = strlen($pattern);
        if ($n === 0 || $n > 80) {
            throw new \InvalidArgumentException('A pattern must be 1 to 80 characters.');
        }
        for ($i = 0; $i < $n; $i++) {
            $ch = $pattern[$i];
            if ($ch === '{' || $ch === '}') {
                throw new \InvalidArgumentException('A {count} must directly follow # or @.');
            }
            $class = $ch === '#' ? '[0-9]' : ($ch === '@' ? '[A-Z]' : null);
            $min = $max = 1;
            if ($class !== null && ($pattern[$i + 1] ?? '') === '{') {
                if (!preg_match('/\G\{(\d{1,2})(?:,(\d{1,2}))?\}/', $pattern, $m, 0, $i + 1)) {
                    throw new \InvalidArgumentException('Use {n} or {n,m} after # or @.');
                }
                $min = (int) $m[1];
                $max = isset($m[2]) ? (int) $m[2] : $min;
                if ($min < 1 || $max < $min || $max > 20) {
                    throw new \InvalidArgumentException('That {count} is not valid.');
                }
                $i += strlen($m[0]);
            }
            if ($class === null) {
                if (!preg_match('/^[A-Za-z0-9\/\-._]$/', $ch)) {
                    throw new \InvalidArgumentException('Pattern characters can be letters, digits, / - . _ and the tokens # @.');
                }
                $re .= preg_quote(strtoupper($ch), '/');
            } else {
                $re .= $class . ($min === $max ? ($min === 1 ? '' : "{{$min}}") : "{{$min},{$max}}");
            }
            $minLen += $min;
            $maxLen += $max;
        }
        return ['/^' . $re . '$/', $minLen, $maxLen];
    }

    public static function config(): array
    {
        $st = Database::pdo()->prepare("SELECT value FROM site_settings WHERE setting_key = 'matric'");
        $st->execute();
        $saved = json_decode((string) ($st->fetchColumn() ?: 'null'), true);
        return is_array($saved) && !empty($saved['formats']) ? $saved : self::DEFAULTS;
    }

    /** Validate and clean admin input. */
    public static function clean(array $in): array
    {
        $max = (int) ($in['maxLength'] ?? 0);
        if ($max < 6 || $max > 30) {
            throw new \InvalidArgumentException('Maximum length must be between 6 and 30.');
        }
        $formats = [];
        foreach (array_slice(array_values((array) ($in['formats'] ?? [])), 0, 10) as $f) {
            $f = (array) $f;
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 40);
            $pattern = trim((string) ($f['pattern'] ?? ''));
            $example = strtoupper(trim((string) ($f['example'] ?? '')));
            if ($label === '') {
                throw new \InvalidArgumentException('Give every format a name (for example Full-time).');
            }
            [$re, $shortest] = self::compile($pattern);
            if ($shortest > $max) {
                throw new \InvalidArgumentException("“$label” needs at least $shortest characters, but the maximum length is $max.");
            }
            if ($example !== '' && (!preg_match($re, $example) || strlen($example) > $max)) {
                throw new \InvalidArgumentException("The example for “$label” does not match its pattern and the maximum length.");
            }
            $formats[] = ['label' => $label, 'pattern' => $pattern, 'example' => $example];
        }
        if (!$formats) {
            throw new \InvalidArgumentException('Add at least one matric format.');
        }
        return ['maxLength' => $max, 'formats' => $formats];
    }

    /** The cleaned (upper-case, trimmed) matric number, or null if it matches no configured format. */
    public static function normalize(string $value): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        $cfg = self::config();
        if ($v === '' || strlen($v) > (int) $cfg['maxLength']) {
            return null;
        }
        foreach ($cfg['formats'] as $f) {
            if (preg_match(self::compile((string) $f['pattern'])[0], $v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Is this (already validated) matric number free for this email? Returns an error message, or null if it is
     * free or already belongs to this same email. Does not reserve anything.
     */
    public static function problem(\PDO $db, string $matric, string $email): ?string
    {
        $st = $db->prepare('SELECT email FROM matric_registry WHERE matric_no = ?');
        $st->execute([$matric]);
        $owner = $st->fetchColumn();
        if ($owner !== false && mb_strtolower((string) $owner) !== mb_strtolower($email)) {
            return 'That matric number is already registered with a different email address. Use the email you registered with, or contact the organisers.';
        }
        $st = $db->prepare('SELECT matric_no FROM matric_registry WHERE email = ?');
        $st->execute([mb_strtolower($email)]);
        $has = $st->fetchColumn();
        if ($has !== false && $has !== $matric) {
            return 'This email address is already linked to a different matric number. Check the number, or contact the organisers.';
        }
        return null;
    }

    /** Reserve the number for this email (no-op if it already belongs to it). Call inside the transaction that stores it. */
    public static function claim(\PDO $db, string $matric, string $email, string $source): ?string
    {
        $email = mb_strtolower($email);
        if (($error = self::problem($db, $matric, $email)) !== null) {
            return $error;
        }
        $db->prepare('INSERT IGNORE INTO matric_registry (matric_no, email, source) VALUES (?, ?, ?)')->execute([$matric, $email, $source]);
        return null;
    }

    /** Admin correction: move an email to a different matric number (or set one). Returns an error message or null. */
    public static function reassign(\PDO $db, string $newMatric, string $email): ?string
    {
        $email = mb_strtolower($email);
        $st = $db->prepare('SELECT email FROM matric_registry WHERE matric_no = ?');
        $st->execute([$newMatric]);
        $owner = $st->fetchColumn();
        if ($owner !== false && mb_strtolower((string) $owner) !== $email) {
            return 'That matric number already belongs to someone else.';
        }
        $db->prepare('DELETE FROM matric_registry WHERE email = ?')->execute([$email]);
        $db->prepare("INSERT INTO matric_registry (matric_no, email, source) VALUES (?, ?, 'admin')")->execute([$newMatric, $email]);
        return null;
    }
}