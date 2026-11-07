<?php
declare(strict_types=1);

namespace App;

/**
 * Server-side paging, filtering, searching and sorting for the admin lists.
 * A list endpoint is "paged" when the request carries ?page=. Query parameters:
 *   page, perPage (5-500, default 25), q (search text), sort + dir (asc|desc), and one parameter per filter.
 * Sort names are looked up in a whitelist; nothing from the browser is ever put into SQL.
 */
final class Paging
{
    public static function wanted(): bool
    {
        return isset($_GET['page']);
    }

    public static function perPage(int $default = 25): int
    {
        return max(5, min(500, (int) ($_GET['perPage'] ?? $default)));
    }

    public static function q(): string
    {
        return mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    }

    public static function filter(string $key): string
    {
        return mb_substr(trim((string) ($_GET[$key] ?? '')), 0, 80);
    }

    /** A LIKE pattern that finds $q anywhere, with % and _ treated as plain characters. */
    public static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    /** "(a LIKE ? OR b LIKE ?)" for the given columns; adds the arguments. */
    public static function search(array $columns, string $q, array &$args): string
    {
        $like = self::like($q);
        foreach ($columns as $_) {
            $args[] = $like;
        }
        return '(' . implode(' OR ', array_map(fn ($c) => "$c LIKE ?", $columns)) . ')';
    }

    /** ORDER BY text from ?sort= and ?dir=. $map: browser name => SQL column. The tiebreak keeps pages stable. */
    public static function order(array $map, string $default, string $tiebreak): string
    {
        $key = (string) ($_GET['sort'] ?? '');
        if (!isset($map[$key])) {
            return $default;
        }
        $dir = strtolower((string) ($_GET['dir'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
        return $map[$key] . ' ' . $dir . ', ' . $tiebreak;
    }

    /**
     * Counts, then fetches one page.
     * @param string[] $where  AND-ed conditions (with ? placeholders)
     * @return array{rows:array,total:int,page:int,pages:int,perPage:int}
     */
    public static function run(\PDO $pdo, string $select, string $from, array $where, array $args, string $orderBy, ?string $countFrom = null): array
    {
        $cond = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . ($countFrom ?? $from) . $cond);
        $count->execute($args);
        $total = (int) $count->fetchColumn();

        $perPage = self::perPage();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        $st = $pdo->prepare("SELECT $select FROM $from$cond ORDER BY $orderBy LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /** The paging fields every paged response carries. */
    public static function meta(array $r): array
    {
        return ['total' => $r['total'], 'page' => $r['page'], 'pages' => $r['pages'], 'perPage' => $r['perPage']];
    }
}