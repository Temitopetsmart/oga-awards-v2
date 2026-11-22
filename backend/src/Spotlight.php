<?php
declare(strict_types=1);

namespace App;

/**
 * Face of the moment: one random nominee or committee member is in the spotlight for a time slot the admin sets
 * (every N seconds, minutes, hours, days, weeks or months). Everybody sees the same person until the slot ends,
 * nobody comes round twice until everyone else has had a turn, and nobody can tell who is next.
 * The picks are written to spotlight_slots the first time anyone asks, so no cron job is needed.
 */
final class Spotlight
{
    /** Longest and shortest "every": seconds start at 5 so a busy site is never asked for a new face every second. */
    public const LIMITS = ['second' => [5, 3600], 'minute' => [1, 1440], 'hour' => [1, 720], 'day' => [1, 365], 'week' => [1, 52], 'month' => [1, 12]];
    public const WHO = ['both', 'nominees', 'committee'];
    private const KEEP_SLOTS = 400;

    public static function settings(int $editionId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM edition_settings WHERE edition_id = ?');
        $st->execute([$editionId]);
        $r = $st->fetch() ?: [];
        $unit = (string) ($r['spotlight_unit'] ?? 'day');
        $unit = isset(self::LIMITS[$unit]) ? $unit : 'day';
        [$lo, $hi] = self::LIMITS[$unit];
        $who = (string) ($r['spotlight_who'] ?? 'both');
        return [
            'enabled'      => (bool) ($r['spotlight_auto'] ?? false),
            'every'        => max($lo, min($hi, (int) ($r['spotlight_every'] ?? 1))),
            'unit'         => $unit,
            'who'          => in_array($who, self::WHO, true) ? $who : 'both',
            'showBio'      => (bool) ($r['spotlight_show_bio'] ?? true),
            'requirePhoto' => (bool) ($r['spotlight_require_photo'] ?? true),
            'heading'      => (string) ($r['spotlight_heading'] ?? 'Meet the face of the {period}'),
            'tagline'      => (string) ($r['spotlight_tagline'] ?? 'Could it be you next? Stay tuned.'),
        ];
    }

    /** What the admin sent. Returns [settings, error or null]. */
    public static function validate(array $in): array
    {
        $unit = (string) ($in['unit'] ?? '');
        if (!isset(self::LIMITS[$unit])) {
            return [[], 'Choose how often the face changes: seconds, minutes, hours, days, weeks or months.'];
        }
        [$lo, $hi] = self::LIMITS[$unit];
        $every = $in['every'] ?? null;
        if (!is_numeric($every) || (float) $every != (int) $every || (int) $every < $lo || (int) $every > $hi) {
            return [[], "Change every: enter a whole number from $lo to $hi for {$unit}s."];
        }
        $who = (string) ($in['who'] ?? '');
        if (!in_array($who, self::WHO, true)) {
            return [[], 'Choose who can be shown: nominees, committee, or both.'];
        }
        $heading = trim((string) ($in['heading'] ?? ''));
        $tagline = trim((string) ($in['tagline'] ?? ''));
        if ($heading === '' || mb_strlen($heading) > 80) {
            return [[], 'Heading: 1 to 80 characters.'];
        }
        if (mb_strlen($tagline) > 160) {
            return [[], 'Tagline: up to 160 characters.'];
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $heading . $tagline)) {
            return [[], 'The text has characters that are not allowed.'];
        }
        return [[
            'enabled'      => !empty($in['enabled']),
            'every'        => (int) $every,
            'unit'         => $unit,
            'who'          => $who,
            'showBio'      => !empty($in['showBio']),
            'requirePhoto' => !empty($in['requirePhoto']),
            'heading'      => $heading,
            'tagline'      => $tagline,
        ], null];
    }

    /** "hour", or "3 hours". */
    public static function periodWord(array $cfg): string
    {
        return $cfg['every'] === 1 ? $cfg['unit'] : $cfg['every'] . ' ' . $cfg['unit'] . 's';
    }

    /** The time slot that contains $now: ['key', 'start', 'end'] (unix times). Days, weeks and months follow the site's own midnight. */
    public static function slot(string $unit, int $every, ?int $now = null): array
    {
        $now ??= time();
        $tz = new \DateTimeZone(date_default_timezone_get());
        $offset = $tz->getOffset(new \DateTimeImmutable('@' . $now));
        if ($unit === 'second' || $unit === 'minute' || $unit === 'hour') {
            $step = $every * ['second' => 1, 'minute' => 60, 'hour' => 3600][$unit];
            $idx = intdiv($now, $step);
            return ['key' => "$every$unit:$idx", 'start' => $idx * $step, 'end' => ($idx + 1) * $step];
        }
        $days = intdiv($now + $offset, 86400);
        if ($unit === 'day') {
            $idx = intdiv($days, $every);
            return ['key' => "{$every}day:$idx", 'start' => $idx * $every * 86400 - $offset, 'end' => ($idx + 1) * $every * 86400 - $offset];
        }
        if ($unit === 'week') {                                   // weeks start on Monday (1 Jan 1970 was a Thursday)
            $idx = intdiv(intdiv($days + 3, 7), $every);
            $start = ($idx * $every * 7 - 3) * 86400 - $offset;
            return ['key' => "{$every}week:$idx", 'start' => $start, 'end' => $start + $every * 7 * 86400];
        }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $monthIdx = (int) $local->format('Y') * 12 + (int) $local->format('n') - 1;
        $idx = intdiv($monthIdx, $every);
        $first = $idx * $every;
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', intdiv($first, 12), $first % 12 + 1), $tz);
        return ['key' => "{$every}month:$idx", 'start' => $start->getTimestamp(), 'end' => $start->modify("+$every months")->getTimestamp()];
    }

    private static function trimBio(?string $bio): string
    {
        $bio = trim((string) preg_replace('/\s+/u', ' ', (string) $bio));
        if (mb_strlen($bio) <= 360) {
            return $bio;
        }
        $cut = mb_substr($bio, 0, 360);
        $space = mb_strrpos($cut, ' ');
        return rtrim(mb_substr($cut, 0, $space !== false && $space > 240 ? $space : 360), ' ,.;:') . '…';
    }

    /** Everyone who can be picked right now, keyed "nominee:12" / "committee:5". One entry per nominee, however many categories they are in. */
    public static function pool(int $editionId, array $cfg): array
    {
        $db = Database::pdo();
        $pool = [];
        if ($cfg['who'] !== 'committee') {
            $st = $db->prepare(
                "SELECT n.id, n.account_id, n.name, n.nickname, n.bio, n.photo_path, n.department, c.name AS category,
                        COALESCE(a.public_id, n.public_id) AS person_public_id
                   FROM nominee_entries n JOIN categories c ON c.id = n.category_id LEFT JOIN accounts a ON a.id = n.account_id
                  WHERE n.edition_id = ? AND n.status = 'approved' ORDER BY n.id"
            );
            $st->execute([$editionId]);
            $seen = [];
            foreach ($st->fetchAll() as $n) {
                $person = $n['account_id'] !== null ? 'a' . $n['account_id'] : 'e' . $n['id'];
                if (isset($seen[$person])) {
                    continue;                                   // already counted through their main entry
                }
                $seen[$person] = true;
                if ($cfg['requirePhoto'] && trim((string) $n['photo_path']) === '') {
                    continue;
                }
                $pool['nominee:' . $n['id']] = [
                    'type' => 'nominee', 'id' => (int) $n['id'], 'name' => $n['name'], 'nickname' => (string) ($n['nickname'] ?? ''),
                    'photo' => $n['photo_path'], 'thumb' => Photos::thumbOf($n['photo_path']),
                    'bio' => $cfg['showBio'] ? self::trimBio($n['bio']) : '', 'line' => (string) $n['category'], 'extra' => (string) ($n['department'] ?? ''),
                    'nomineeId' => (string) $n['id'], 'publicId' => $n['person_public_id'],
                ];
            }
        }
        if ($cfg['who'] !== 'nominees') {
            $st = $db->prepare(
                "SELECT a.id, p.name, p.photo_path, a.position, u.name AS unit_name
                   FROM edition_assignments a JOIN people p ON p.id = a.person_id AND p.active = 1
                   LEFT JOIN committee_units u ON u.id = a.unit_id
                  WHERE a.edition_id = ? AND a.position <> '' AND a.visible = 1 ORDER BY a.id"
            );
            $st->execute([$editionId]);
            foreach ($st->fetchAll() as $m) {
                if ($cfg['requirePhoto'] && trim((string) $m['photo_path']) === '') {
                    continue;
                }
                $pool['committee:' . $m['id']] = [
                    'type' => 'committee', 'id' => (int) $m['id'], 'name' => $m['name'], 'nickname' => '',
                    'photo' => $m['photo_path'], 'thumb' => Photos::thumbOf($m['photo_path']),
                    'bio' => '', 'line' => (string) $m['position'], 'extra' => (string) ($m['unit_name'] ?? ''),
                    'nomineeId' => null, 'publicId' => null,
                ];
            }
        }
        return $pool;
    }

    /** The person for this slot. Picked at random from those who have not had a turn in this round, and remembered. */
    private static function pick(int $editionId, string $slotKey, array $pool): ?array
    {
        if ($pool === []) {
            return null;
        }
        $db = Database::pdo();
        $find = $db->prepare('SELECT id, subject_type, subject_id FROM spotlight_slots WHERE edition_id = ? AND slot_key = ?');
        $find->execute([$editionId, $slotKey]);
        $row = $find->fetch() ?: null;
        if ($row && isset($pool[$row['subject_type'] . ':' . $row['subject_id']])) {
            return $pool[$row['subject_type'] . ':' . $row['subject_id']];
        }
        // A "round" is everybody in the draw once, in a fresh random order. Nobody comes round again until the round is complete.
        $latest = $db->prepare('SELECT subject_type, subject_id, round_no FROM spotlight_slots WHERE edition_id = ? AND slot_key <> ? ORDER BY id DESC LIMIT 1');
        $latest->execute([$editionId, $slotKey]);
        $last = $latest->fetch() ?: null;
        $round = $last ? (int) $last['round_no'] : 1;
        $shownSt = $db->prepare('SELECT subject_type, subject_id FROM spotlight_slots WHERE edition_id = ? AND round_no = ? AND slot_key <> ?');
        $shownSt->execute([$editionId, $round, $slotKey]);
        $shown = [];
        foreach ($shownSt->fetchAll() as $r) {
            $shown[$r['subject_type'] . ':' . $r['subject_id']] = true;
        }
        $candidates = array_diff_key($pool, $shown);
        if ($candidates === []) {                                // everyone has had a turn: start a new round
            $round++;
            $candidates = $pool;
            if (count($pool) > 1 && $last) {
                unset($candidates[$last['subject_type'] . ':' . $last['subject_id']]);   // never the same face twice running
            }
        }
        $keys = array_keys($candidates);
        $chosen = $candidates[$keys[random_int(0, count($keys) - 1)]];
        if ($row) {                                              // the person in this slot is no longer eligible: replace them
            $db->prepare('UPDATE spotlight_slots SET subject_type = ?, subject_id = ? WHERE id = ?')->execute([$chosen['type'], $chosen['id'], $row['id']]);
            return $chosen;
        }
        $db->prepare('INSERT IGNORE INTO spotlight_slots (edition_id, slot_key, round_no, subject_type, subject_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$editionId, $slotKey, $round, $chosen['type'], $chosen['id']]);
        $find->execute([$editionId, $slotKey]);                  // two visitors at the same moment: the first pick wins for everybody
        $won = $find->fetch();
        if (random_int(1, 25) === 1) {
            $db->prepare('DELETE FROM spotlight_slots WHERE edition_id = ? AND id <= (SELECT id FROM (SELECT id FROM spotlight_slots WHERE edition_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP_SLOTS . ') t)')
                ->execute([$editionId, $editionId]);
        }
        return $won ? ($pool[$won['subject_type'] . ':' . $won['subject_id']] ?? $chosen) : $chosen;
    }

    /** Public pieces of a person. Never the internal ids of staff, never contact details. */
    private static function publicFace(array $s): array
    {
        return ['type' => $s['type'], 'name' => $s['name'], 'nickname' => $s['nickname'], 'photo' => $s['photo'], 'thumb' => $s['thumb'],
                'bio' => $s['bio'], 'line' => $s['line'], 'extra' => $s['extra'], 'nomineeId' => $s['nomineeId'], 'publicId' => $s['publicId']];
    }

    private static function build(array $ed, array $cfg, array $slot): array
    {
        $pool = self::pool((int) $ed['id'], $cfg);
        $face = self::pick((int) $ed['id'], $slot['key'], $pool);
        $recent = [];
        if ($face) {
            $st = Database::pdo()->prepare('SELECT subject_type, subject_id FROM spotlight_slots WHERE edition_id = ? AND slot_key <> ? ORDER BY id DESC LIMIT 12');
            $st->execute([$ed['id'], $slot['key']]);
            foreach ($st->fetchAll() as $r) {
                $s = $pool[$r['subject_type'] . ':' . $r['subject_id']] ?? null;
                if ($s && !($s['type'] === $face['type'] && $s['id'] === $face['id']) && count($recent) < 8) {
                    $recent[] = ['type' => $s['type'], 'name' => $s['name'], 'thumb' => $s['thumb'] ?: $s['photo'], 'line' => $s['line']];
                }
            }
        }
        return [
            'enabled'  => true,
            'heading'  => str_replace('{period}', self::periodWord($cfg), $cfg['heading']),
            'tagline'  => $cfg['tagline'],
            'edition'  => trim($ed['name'] . ' ' . ($ed['edition_label'] ?? '')),
            'face'     => $face ? self::publicFace($face) : null,
            'startsAt' => (new \DateTimeImmutable('@' . $slot['start']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('c'),
            'endsAt'   => (new \DateTimeImmutable('@' . $slot['end']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('c'),
            'every'    => $cfg['every'],
            'unit'     => $cfg['unit'],
            'recent'   => $recent,
        ];
    }

    /** GET /spotlight/now: the face for this moment (the same for everybody), and when it changes. */
    public static function now(): array
    {
        $ed = AccessPass::activeEdition();
        if (!$ed) {
            return ['ok' => true, 'enabled' => false];
        }
        $cfg = self::settings((int) $ed['id']);
        if (!$cfg['enabled']) {
            return ['ok' => true, 'enabled' => false];
        }
        $now = time();
        $slot = self::slot($cfg['unit'], $cfg['every'], $now);
        $ttl = max(1, min(60, $slot['end'] - $now));
        $json = Cache::remember('spotlight-now-' . $ed['id'] . '-' . $slot['key'], $ttl, fn () => (string) json_encode(self::build($ed, $cfg, $slot)));
        $out = json_decode($json, true);
        return ['ok' => true, 'serverNow' => (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('c')] + (is_array($out) ? $out : ['enabled' => false]);
    }

    /** Is the live face switched on for the active edition? (The cached public list tells the pages which version to draw.) */
    public static function enabledNow(): bool
    {
        $ed = AccessPass::activeEdition();
        return $ed ? self::settings((int) $ed['id'])['enabled'] : false;
    }
}