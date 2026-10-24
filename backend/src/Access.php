<?php
declare(strict_types=1);

namespace App;

/**
 * THE permission rule. Every protected API call goes through here; the browser only hides buttons.
 *
 *   allowed(permission, edition) =
 *       person is active
 *   AND (person is a Super admin OR a role/grant gives them it in THAT edition)
 *   AND it is not blocked for them (a block always wins)
 *   AND NOT (it changes data AND the edition is completed/archived — unless the permission is global)
 */
final class Access
{
    /** @return array{isSuper:bool, perms:array<string,true>} */
    public static function effective(array $person, int $editionId): array
    {
        $pdo = Database::pdo();
        $all = fn () => array_fill_keys($pdo->query('SELECT perm_key FROM permissions')->fetchAll(\PDO::FETCH_COLUMN), true);

        if ((int) $person['is_super_admin'] === 1) {
            return ['isSuper' => true, 'perms' => $all()];
        }

        $st = $pdo->prepare('SELECT id FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
        $st->execute([$person['id'], $editionId]);
        $assignmentId = $st->fetchColumn();
        if ($assignmentId === false) {
            return ['isSuper' => false, 'perms' => []];
        }

        // A "locked" role (the Super role) grants everything.
        $st = $pdo->prepare('SELECT COUNT(*) FROM assignment_roles ar JOIN roles r ON r.id = ar.role_id
                              WHERE ar.assignment_id = ? AND r.is_locked = 1');
        $st->execute([$assignmentId]);
        if ((int) $st->fetchColumn() > 0) {
            return ['isSuper' => false, 'perms' => $all()];
        }

        $perms = [];
        $st = $pdo->prepare('SELECT rp.perm_key FROM assignment_roles ar
                               JOIN role_permissions rp ON rp.role_id = ar.role_id
                              WHERE ar.assignment_id = ?');
        $st->execute([$assignmentId]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $k) {
            $perms[$k] = true;
        }
        $st = $pdo->prepare('SELECT perm_key, effect FROM assignment_overrides WHERE assignment_id = ?');
        $st->execute([$assignmentId]);
        foreach ($st->fetchAll() as $o) {
            if ($o['effect'] === 'grant') {
                $perms[$o['perm_key']] = true;
            }
        }
        $st = $pdo->prepare("SELECT perm_key FROM assignment_overrides WHERE assignment_id = ? AND effect = 'deny'");
        $st->execute([$assignmentId]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $k) {
            unset($perms[$k]);              // a block always wins
        }
        return ['isSuper' => false, 'perms' => $perms];
    }

    public static function can(array $person, string $permission, int $editionId): bool
    {
        if ((int) $person['active'] !== 1) {
            return false;
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT kind, scope FROM permissions WHERE perm_key = ?');
        $st->execute([$permission]);
        $meta = $st->fetch();
        if (!$meta) {
            return false;                   // unknown permission names are never allowed
        }
        if (!isset(self::effective($person, $editionId)['perms'][$permission])) {
            return false;
        }
        if ($meta['kind'] === 'write' && $meta['scope'] === 'edition') {
            $st = $pdo->prepare('SELECT status FROM editions WHERE id = ?');
            $st->execute([$editionId]);
            if (in_array($st->fetchColumn(), ['completed', 'archived'], true)) {
                return false;               // finished editions are read-only
            }
        }
        return true;
    }

    /** Ids of editions this person can open (Super admins: all; others: where they hold any permission). */
    public static function editionIds(array $person): array
    {
        $ids = Database::pdo()->query('SELECT id FROM editions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter($ids, fn ($id) => self::effective($person, (int) $id)['perms'] !== []));
    }

    /** Use at the top of a protected endpoint: returns the person, or stops with 401/403. */
    public static function require(string $permission, string $editionSlug): array
    {
        $person = Auth::person();
        if ($person === null) {
            Response::error('Please sign in.', 401);
        }
        $st = Database::pdo()->prepare('SELECT id FROM editions WHERE slug = ?');
        $st->execute([$editionSlug]);
        $editionId = $st->fetchColumn();
        if ($editionId === false || !self::can($person, $permission, (int) $editionId)) {
            Response::error('You do not have permission to do that.', 403);
        }
        return $person + ['edition_id' => (int) $editionId];
    }
}