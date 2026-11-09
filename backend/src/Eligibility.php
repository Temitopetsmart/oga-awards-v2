<?php
declare(strict_types=1);

namespace App;

/** Which levels may be nominated in a category (table category_levels). No rows = open to everyone. */
final class Eligibility
{
    /** @return string[] level names, in the admin's order; empty = everyone */
    public static function levelsOf(int $categoryId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT cl.level FROM category_levels cl LEFT JOIN lookup_options o ON o.kind = 'level' AND o.name = cl.level
              WHERE cl.category_id = ? ORDER BY COALESCE(o.sort_order, 999), cl.level"
        );
        $st->execute([$categoryId]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** category id => levels, for a set of categories (public list and admin list in one query). */
    public static function forCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        $rows = Database::pdo()->query(
            "SELECT cl.category_id, cl.level FROM category_levels cl LEFT JOIN lookup_options o ON o.kind = 'level' AND o.name = cl.level
              WHERE cl.category_id IN ($in) ORDER BY COALESCE(o.sort_order, 999), cl.level"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int) $r['category_id']][] = $r['level'];
        }
        return $out;
    }

    public static function allows(int $categoryId, string $level): bool
    {
        $levels = self::levelsOf($categoryId);
        return $levels === [] || in_array($level, $levels, true);
    }

    /** Replace the list for a category (inside the caller's transaction). */
    public static function save(\PDO $db, int $categoryId, array $levels): void
    {
        $db->prepare('DELETE FROM category_levels WHERE category_id = ?')->execute([$categoryId]);
        $ins = $db->prepare('INSERT INTO category_levels (category_id, level) VALUES (?, ?)');
        foreach ($levels as $l) {
            $ins->execute([$categoryId, $l]);
        }
    }

    /** Validates a submitted list of level names. Returns the clean list, or throws InvalidArgumentException. */
    public static function clean(mixed $in): array
    {
        if ($in === null || $in === '' || $in === []) {
            return [];
        }
        if (!is_array($in)) {
            throw new \InvalidArgumentException('Choose the eligible levels from the list.');
        }
        $known = Options::knownLevels();
        $out = [];
        foreach ($in as $l) {
            $l = (string) $l;
            if (!in_array($l, $known, true)) {
                throw new \InvalidArgumentException('“' . mb_substr($l, 0, 30) . '” is not one of the levels set in Settings.');
            }
            $out[$l] = true;
        }
        return array_keys($out);
    }

    /** "This category is open to HND I and HND II only." */
    public static function message(int $categoryId): string
    {
        $levels = self::levelsOf($categoryId);
        return 'This category is open to ' . implode(', ', $levels) . ' only.';
    }
}