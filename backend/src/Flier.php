<?php
declare(strict_types=1);

namespace App;

/** Phase 13: the look and wording of the promo flier a nominee downloads. Set per edition by the admin; drawn in the browser. */
final class Flier
{
    /** The stages a flier can be drawn for: the edition's public states, plus "winner" once results are out. */
    public const STAGES = ['landing', 'nominations', 'voting', 'voting-closed', 'results', 'winner'];
    public const STAGE_LABELS = [
        'landing'       => 'Coming soon',
        'nominations'   => 'Nominations open',
        'voting'        => 'Voting open',
        'voting-closed' => 'Voting closed',
        'results'       => 'Results out',
        'winner'        => 'Winner',
    ];
    /** Words the admin may put in any text; the browser fills them in: {name} {category} {edition} {opens} {closes} {results}. */
    public const STAGE_TEXT = ['headline' => 40, 'tagline' => 80, 'footer' => 80];

    /** Each stage has its own look and wording until the admin changes it. */
    public static function stageDefaults(): array
    {
        return [
            'landing'       => ['on' => true, 'headline' => 'Coming soon', 'tagline' => '{edition} is almost here', 'footer' => 'Follow for updates', 'bg' => '#0c1424', 'accent' => '#6ec6ff', 'text' => '#ffffff'],
            'nominations'   => ['on' => true, 'headline' => 'I’m nominated', 'tagline' => '{category} · {edition}', 'footer' => 'Voting opens {opens}', 'bg' => '#0b241c', 'accent' => '#e8c25a', 'text' => '#ffffff'],
            'voting'        => ['on' => true, 'headline' => 'Vote for me', 'tagline' => '{category} · {edition}', 'footer' => 'Voting closes {closes}', 'bg' => '#0a0a0e', 'accent' => '#ffc633', 'text' => '#ffffff'],
            'voting-closed' => ['on' => true, 'headline' => 'Voting closed', 'tagline' => 'Thank you for every vote', 'footer' => 'Results: {results}', 'bg' => '#16181d', 'accent' => '#c8d0dc', 'text' => '#ffffff'],
            'results'       => ['on' => true, 'headline' => 'Thank you', 'tagline' => '{category} nominee · {edition}', 'footer' => '', 'bg' => '#1a1410', 'accent' => '#e0b36a', 'text' => '#ffffff'],
            'winner'        => ['on' => true, 'headline' => 'Winner', 'tagline' => '{category} · {edition}', 'footer' => 'Thank you for believing in me', 'bg' => '#1a1200', 'accent' => '#ffd54a', 'text' => '#ffffff'],
        ];
    }

    public static function defaults(): array
    {
        return [
            'states'       => self::stageDefaults(),
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
        $saved = is_array($saved) ? $saved : [];
        $out = array_replace(self::defaults(), array_intersect_key($saved, self::defaults()));
        $states = self::stageDefaults();
        if (!isset($saved['states']) || !is_array($saved['states'])) {
            // saved before stages existed: the one look they had becomes the voting flier
            foreach (['headline', 'tagline', 'footer', 'bg', 'accent', 'text'] as $k) {
                if (isset($saved[$k]) && is_string($saved[$k]) && ($k === 'tagline' || $k === 'footer' || $saved[$k] !== '')) {
                    $states['voting'][$k] = $saved[$k];
                }
            }
        } else {
            foreach (self::STAGES as $st) {
                if (isset($saved['states'][$st]) && is_array($saved['states'][$st])) {
                    $states[$st] = array_replace($states[$st], array_intersect_key($saved['states'][$st], $states[$st]));
                    $states[$st]['on'] = (bool) $states[$st]['on'];
                }
            }
        }
        $out['states'] = $states;
        return $out;
    }

    /** Checks what the admin sent. Returns [config, error message or null]. */
    public static function validate(array $in): array
    {
        $out = self::defaults();
        if (isset($in['states']) && is_array($in['states'])) {
            return self::validateStages($in, $out);
        }
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
        // the old one-look form describes the voting flier
        foreach (['headline', 'tagline', 'footer', 'bg', 'accent', 'text'] as $k) {
            $out['states']['voting'][$k] = $out[$k];
        }
        return [$out, null];
    }

    /** The per-stage form: wording and colours for each stage. The old flat fields follow the voting stage. */
    private static function validateStages(array $in, array $out): array
    {
        $states = self::stageDefaults();
        foreach (self::STAGES as $st) {
            $label = self::STAGE_LABELS[$st];
            $row = is_array($in['states'][$st] ?? null) ? $in['states'][$st] : [];
            foreach (self::STAGE_TEXT as $k => $max) {
                $v = trim((string) ($row[$k] ?? $states[$st][$k]));
                if (mb_strlen($v) > $max) {
                    return [$out, "$label: " . ucfirst($k) . " can be at most $max characters."];
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
                    return [$out, "$label: " . ucfirst($k) . ' has characters that are not allowed.'];
                }
                $states[$st][$k] = $v;
            }
            if ($states[$st]['headline'] === '') {
                return [$out, "$label: give the flier a headline."];
            }
            foreach (['bg' => 'Background', 'accent' => 'Accent', 'text' => 'Text'] as $k => $name) {
                $v = strtolower(trim((string) ($row[$k] ?? $states[$st][$k])));
                if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
                    return [$out, "$label: " . strtolower($name) . ' colour must look like #14110a.'];
                }
                $states[$st][$k] = $v;
            }
            $states[$st]['on'] = array_key_exists('on', $row) ? !empty($row['on']) : true;
        }
        $out['states'] = $states;
        foreach (['headline', 'tagline', 'footer', 'bg', 'accent', 'text'] as $k) {
            $out[$k] = $states['voting'][$k];
        }
        foreach (['enabled', 'showQr', 'showCategory'] as $k) {
            $out[$k] = !empty($in[$k]);
        }
        return [$out, null];
    }
}