<?php
declare(strict_types=1);

namespace App;

/**
 * How a ticket type looks: the tier word printed on it, two colours, the text colour, a pattern and up to three
 * detail lines. The admin sets these per ticket type, so a VIP, Platinum or Regular ticket is recognisable at a glance.
 */
final class TicketLook
{
    public const PATTERNS = ['none', 'stripes', 'dots', 'diagonal', 'rings'];

    public static function defaults(string $typeName): array
    {
        return ['label' => mb_strtoupper(mb_substr(trim($typeName), 0, 16)), 'primary' => '#0f1b3d', 'secondary' => '#c9a227', 'text' => '#ffffff', 'pattern' => 'none', 'details' => []];
    }

    /** What is stored, completed with defaults, always safe to send to the browser. */
    public static function normalise(?string $json, string $typeName): array
    {
        $d = self::defaults($typeName);
        $v = json_decode((string) $json, true);
        if (!is_array($v)) {
            return $d;
        }
        foreach (['primary', 'secondary', 'text'] as $k) {
            if (isset($v[$k]) && is_string($v[$k]) && preg_match('/^#[0-9a-f]{6}$/i', $v[$k])) {
                $d[$k] = strtolower($v[$k]);
            }
        }
        if (isset($v['label']) && is_string($v['label']) && trim($v['label']) !== '') {
            $d['label'] = mb_substr(trim($v['label']), 0, 16);
        }
        if (isset($v['pattern']) && in_array($v['pattern'], self::PATTERNS, true)) {
            $d['pattern'] = $v['pattern'];
        }
        if (isset($v['details']) && is_array($v['details'])) {
            $d['details'] = array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 60), $v['details']), fn ($x) => $x !== ''), 0, 3));
        }
        return $d;
    }

    /** Checks what the admin typed. Throws InvalidArgumentException with a plain message. */
    public static function clean(array $in, string $typeName): string
    {
        $d = self::defaults($typeName);
        $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 16);
        $out = ['label' => $label !== '' ? $label : $d['label']];
        foreach (['primary' => 'Main colour', 'secondary' => 'Accent colour', 'text' => 'Text colour'] as $k => $name) {
            $c = strtolower(trim((string) ($in[$k] ?? $d[$k])));
            if (!preg_match('/^#[0-9a-f]{6}$/', $c)) {
                throw new \InvalidArgumentException("$name: choose a colour.");
            }
            $out[$k] = $c;
        }
        $pattern = (string) ($in['pattern'] ?? 'none');
        if (!in_array($pattern, self::PATTERNS, true)) {
            throw new \InvalidArgumentException('Choose one of the listed patterns.');
        }
        $out['pattern'] = $pattern;
        if (self::contrast($out['text'], $out['primary']) < 3.0) {
            throw new \InvalidArgumentException('The text colour is hard to read on the main colour. Pick colours that contrast more.');
        }
        $details = $in['details'] ?? [];
        if (is_string($details)) {
            $details = preg_split('/\R/', $details);
        }
        $out['details'] = array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 60), (array) $details), fn ($x) => $x !== ''), 0, 3));
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(function ($i) use ($hex) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast(string $a, string $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}