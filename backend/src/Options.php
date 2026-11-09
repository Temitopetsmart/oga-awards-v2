<?php
declare(strict_types=1);

namespace App;

/** Admin-managed lists: student levels and departments. Replaces the hardcoded copies. */
final class Options
{
    public const KINDS = ['level' => 30, 'department' => 120];

    /** Active names in the admin's order. */
    public static function names(string $kind): array
    {
        $st = Database::pdo()->prepare('SELECT name FROM lookup_options WHERE kind = ? AND active = 1 ORDER BY sort_order, id');
        $st->execute([$kind]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function levels(): array
    {
        return self::names('level');
    }

    public static function isActive(string $kind, string $name): bool
    {
        return in_array($name, self::names($kind), true);
    }

    /** Must a student at this level give a matric number? (Staff do not.) Unknown levels: yes. */
    public static function requiresMatric(string $level): bool
    {
        $st = Database::pdo()->prepare("SELECT requires_matric FROM lookup_options WHERE kind = 'level' AND name = ?");
        $st->execute([$level]);
        $v = $st->fetchColumn();
        return $v === false ? true : (int) $v === 1;
    }

    /** Every level (active or not) by name, for validating a list of levels. */
    public static function knownLevels(): array
    {
        return array_map('strval', Database::pdo()->query("SELECT name FROM lookup_options WHERE kind = 'level' ORDER BY sort_order, id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Everything, for the admin editor. */
    public static function all(string $kind): array
    {
        $st = Database::pdo()->prepare('SELECT name, active, requires_matric FROM lookup_options WHERE kind = ? ORDER BY sort_order, id');
        $st->execute([$kind]);
        return array_map(fn ($r) => ['name' => $r['name'], 'active' => (int) $r['active'] === 1, 'requiresMatric' => (int) $r['requires_matric'] === 1], $st->fetchAll());
    }

    /**
     * Replace the list. The submitted order becomes the display order. Names are never renamed
     * (records already use them) and never deleted: a name left out is switched off.
     * @return array<int,array{name:string,active:bool}>
     */
    public static function save(string $kind, array $items): array
    {
        $max = self::KINDS[$kind] ?? throw new \InvalidArgumentException('Unknown list.');
        $clean = [];
        $seen = [];
        foreach (array_slice(array_values($items), 0, 100) as $x) {
            $x = (array) $x;
            $name = preg_replace('/\s+/', ' ', trim((string) ($x['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > $max || !preg_match('/^[\p{L}\p{N} .,&\/()\'-]+$/u', $name)) {
                throw new \InvalidArgumentException("Each name must be 1 to $max characters (letters, numbers and . , & / ( ) - only).");
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("“$name” is listed twice.");
            }
            $seen[$key] = true;
            $clean[] = ['name' => $name, 'active' => !empty($x['active']), 'requiresMatric' => !array_key_exists('requiresMatric', $x) || !empty($x['requiresMatric'])];
        }
        if ($kind === 'level' && !array_filter($clean, fn ($c) => $c['active'])) {
            throw new \InvalidArgumentException('Keep at least one level active.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE lookup_options SET active = 0 WHERE kind = ?')->execute([$kind]);
            $up = $pdo->prepare(
                'INSERT INTO lookup_options (kind, name, sort_order, active, requires_matric) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = VALUES(active), requires_matric = VALUES(requires_matric)'
            );
            foreach ($clean as $i => $c) {
                $up->execute([$kind, $c['name'], $i + 1, $c['active'] ? 1 : 0, $c['requiresMatric'] ? 1 : 0]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::all($kind);
    }
}