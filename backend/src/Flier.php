<?php
declare(strict_types=1);

namespace App;

/** Phase 13: the look and wording of the promo flier a nominee downloads. Set per edition by the admin; drawn in the browser. */
final class Flier
{
    public static function defaults(): array
    {
        return [
            'enabled'      => true,
            'headline'     => 'Vote for me',
            'tagline'      => '',
            'footer'       => 'Scan or visit to vote',
            'bg'           => '#14110a',
            'accent'       => '#d4a84b',
            'text'         => '#ffffff',
            'showQr'       => true,
            'showCategory' => true,
        ];
    }

    /** What is stored, filled in with the defaults. */
    public static function merged(?string $json): array
    {
        $saved = json_decode((string) $json, true);
        return array_replace(self::defaults(), is_array($saved) ? array_intersect_key($saved, self::defaults()) : []);
    }

    /** Checks what the admin sent. Returns [config, error message or null]. */
    public static function validate(array $in): array
    {
        $out = self::defaults();
        foreach (['headline' => 40, 'tagline' => 80, 'footer' => 80] as $k => $max) {
            $v = trim((string) ($in[$k] ?? ''));
            if (mb_strlen($v) > $max) {
                return [$out, ucfirst($k) . " can be at most $max characters."];
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
                return [$out, ucfirst($k) . ' has characters that are not allowed.'];
            }
            $out[$k] = $v;
        }
        if ($out['headline'] === '') {
            return [$out, 'Give the flier a headline.'];
        }
        foreach (['bg' => 'Background', 'accent' => 'Accent', 'text' => 'Text'] as $k => $label) {
            $v = strtolower(trim((string) ($in[$k] ?? '')));
            if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
                return [$out, "$label colour must look like #14110a."];
            }
            $out[$k] = $v;
        }
        foreach (['enabled', 'showQr', 'showCategory'] as $k) {
            $out[$k] = !empty($in[$k]);
        }
        return [$out, null];
    }
}