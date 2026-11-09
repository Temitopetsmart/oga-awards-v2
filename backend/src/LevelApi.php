<?php
declare(strict_types=1);

namespace App;

/**
 * Moving students up a level. The admin sets the mapping once (Settings → Level progression), previews it, and
 * applies it when the new session starts. It changes the level kept on each nominee account (the level used to
 * decide which categories a student may enter). Entries from past editions keep the level they were made with.
 * Super admin only; it refuses to run twice within 90 days unless the admin says it is meant.
 */
final class LevelApi
{
    private const COOLDOWN_DAYS = 90;

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    /** from => to, as saved. */
    public static function mapping(): array
    {
        $m = SettingsApi::value('progression')['map'] ?? [];
        return is_array($m) ? array_map('strval', $m) : [];
    }

    /** Validates an admin's mapping against the known levels. */
    public static function cleanMap(mixed $in): array
    {
        if (!is_array($in)) {
            throw new \InvalidArgumentException('Invalid progression.');
        }
        $known = Options::knownLevels();
        $out = [];
        foreach ($in as $from => $to) {
            $from = (string) $from;
            $to = trim((string) $to);
            if ($to === '') {
                continue;                                           // "stays at this level"
            }
            if (!in_array($from, $known, true) || !in_array($to, $known, true)) {
                throw new \InvalidArgumentException('Both levels must be levels you have set up.');
            }
            if ($from === $to) {
                throw new \InvalidArgumentException("“$from” cannot move to itself.");
            }
            $out[$from] = $to;
        }
        return $out;
    }

    /** @return array{counts:array<string,int>,total:int} students that would move, per level */
    private static function preview(array $map): array
    {
        $counts = [];
        $total = 0;
        if ($map) {
            $in = implode(',', array_fill(0, count($map), '?'));
            $st = self::pdo()->prepare("SELECT level, COUNT(*) FROM accounts WHERE level IN ($in) GROUP BY level");
            $st->execute(array_keys($map));
            foreach ($st->fetchAll(\PDO::FETCH_KEY_PAIR) as $level => $n) {
                $counts[$level] = (int) $n;
                $total += (int) $n;
            }
        }
        return ['counts' => $counts, 'total' => $total];
    }

    public static function last(): ?array
    {
        $r = self::pdo()->query('SELECT p.applied_at, p.moved, pe.name AS by_name FROM level_promotions p LEFT JOIN people pe ON pe.id = p.applied_by ORDER BY p.id DESC LIMIT 1')->fetch();
        return $r ? ['at' => (new \DateTimeImmutable($r['applied_at']))->format('c'), 'moved' => (int) $r['moved'], 'by' => $r['by_name']] : null;
    }

    /** PUT /admin/settings/progression handled by SettingsApi; POST /admin/levels/promote { edition, mode: preview|apply, confirm?, again? } */
    public static function promote(): never
    {
        $in = Request::json();
        $actor = Access::require('settings.edit', Request::str($in, 'edition', 60));
        if ((int) $actor['is_super_admin'] !== 1) {
            self::fail('Only a Super admin can move students up a level.', 403);
        }
        $map = self::mapping();
        if (!$map) {
            self::fail('Set the progression first: choose which level each level moves to, and save it.');
        }
        $preview = self::preview($map);
        if ((string) ($in['mode'] ?? 'preview') !== 'apply') {
            Response::json(['ok' => true, 'map' => $map, 'last' => self::last()] + $preview);
        }
        if (empty($in['confirm'])) {
            self::fail('Confirm that you want to move the students.');
        }
        $last = self::last();
        if ($last && empty($in['again']) && strtotime($last['at']) > time() - self::COOLDOWN_DAYS * 86400) {
            self::fail('Students were already moved up on ' . date('j M Y', strtotime($last['at'])) . '. Applying again so soon would move them up twice. If you really mean it, tick “Apply again”.', 409);
        }

        // One statement, so a student moves exactly one step even if the chain is ND I → ND II → HND I.
        $case = 'CASE level';
        $args = [];
        foreach ($map as $from => $to) {
            $case .= ' WHEN ? THEN ?';
            array_push($args, $from, $to);
        }
        $case .= ' ELSE level END';
        $in2 = implode(',', array_fill(0, count($map), '?'));
        $moved = Database::transaction(function (\PDO $db) use ($case, $args, $in2, $map, $actor) {
            $st = $db->prepare("UPDATE accounts SET level = $case WHERE level IN ($in2)");
            $st->execute(array_merge($args, array_keys($map)));
            $n = $st->rowCount();
            $db->prepare('INSERT INTO level_promotions (applied_by, mapping, moved) VALUES (?, ?, ?)')->execute([$actor['id'], json_encode($map, JSON_UNESCAPED_UNICODE), $n]);
            return $n;
        });
        Cache::flush();
        Audit::log((int) $actor['id'], 'Moved students up a level', "$moved students", $actor['edition_id'], ['map' => $map]);
        Response::json(['ok' => true, 'moved' => $moved, 'last' => self::last()]);
    }
}