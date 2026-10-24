<?php
declare(strict_types=1);

namespace App;

/**
 * People, access (assignments) and roles. Every rule from the V2 admin is enforced HERE,
 * because the browser can be tampered with:
 *   - you can only give access you hold yourself (no privilege escalation)
 *   - you cannot change your own access
 *   - only a Super admin can create Super admins or touch them
 *   - there must always be at least one active Super admin
 */
final class TeamApi
{
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function isSuper(array $p): bool
    {
        return (int) $p['is_super_admin'] === 1;
    }

    private static function activeSupers(): int
    {
        return (int) self::pdo()->query('SELECT COUNT(*) FROM people WHERE is_super_admin = 1 AND active = 1')->fetchColumn();
    }

    private static function person(int $id): array
    {
        $st = self::pdo()->prepare('SELECT id, name, email, active, is_super_admin, (password_hash IS NOT NULL) AS has_password FROM people WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: self::fail('That person was not found.', 404);
    }

    private static function emailTaken(string $email, ?int $exceptId): bool
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM people WHERE email = ? AND id <> ?');
        $st->execute([$email, $exceptId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    private static function cleanEmail(string $email): string
    {
        $email = mb_strtolower($email);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Enter a valid email.');
        }
        return $email;
    }

    private static function slugOf(string $roleId): string
    {
        return preg_replace('/^role-/', '', $roleId);
    }

    private static function validKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        if ($keys === []) {
            return [];
        }
        $known = self::pdo()->query('SELECT perm_key FROM permissions')->fetchAll(\PDO::FETCH_COLUMN);
        if (array_diff($keys, $known) !== []) {
            self::fail('Unknown permission.');
        }
        return $keys;
    }

    // ---------------------------------------------------------------- people

    public static function addPerson(): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $name  = Request::str($in, 'name', 150);
        $email = self::cleanEmail(Request::str($in, 'email', 190));
        if (mb_strlen($name) < 2) {
            self::fail('Enter the person’s name.');
        }
        if ($email !== '' && self::emailTaken($email, null)) {
            self::fail('Someone with that email already exists.');
        }
        $active = array_key_exists('active', $in) ? (bool) $in['active'] : true;
        self::pdo()->prepare('INSERT INTO people (name, email, active) VALUES (?, ?, ?)')
            ->execute([$name, $email === '' ? null : $email, $active ? 1 : 0]);
        $id = (int) self::pdo()->lastInsertId();
        Audit::log((int) $actor['id'], 'Added person', $name, $actor['edition_id'], ['email' => $email ?: null]);
        Response::json(['ok' => true, 'personId' => (string) $id]);
    }

    public static function updatePerson(int $id): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $p = self::person($id);
        if ($p['is_super_admin'] && !self::isSuper($actor)) {
            self::fail('Only a Super admin can change a Super admin.', 403);
        }

        $set = [];
        $args = [];
        if (array_key_exists('name', $in)) {
            $name = Request::str($in, 'name', 150);
            if (mb_strlen($name) < 2) {
                self::fail('Enter the person’s name.');
            }
            $set[] = 'name = ?';
            $args[] = $name;
        }
        if (array_key_exists('email', $in)) {
            $email = self::cleanEmail(Request::str($in, 'email', 190));
            if ($email !== '' && self::emailTaken($email, $id)) {
                self::fail('Someone with that email already exists.');
            }
            if ($email !== (string) $p['email'] && $p['has_password'] && !self::isSuper($actor)) {
                // Changing the email of an account that can already sign in would let a team manager take it over.
                self::fail('Only a Super admin can change the sign-in email of someone who already has a password.', 403);
            }
            $set[] = 'email = ?';
            $args[] = $email === '' ? null : $email;
        }
        if (array_key_exists('active', $in)) {
            $active = (bool) $in['active'];
            if ($id === (int) $actor['id']) {
                $active = true;                         // you cannot deactivate yourself
            }
            if (!$active && $p['is_super_admin'] && $p['active'] && self::activeSupers() < 2) {
                self::fail('At least one active Super admin is required.');
            }
            $set[] = 'active = ?';
            $args[] = $active ? 1 : 0;
        }
        if ($set === []) {
            Response::json(['ok' => true]);
        }
        $args[] = $id;
        self::pdo()->prepare('UPDATE people SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Edited person', $p['name'], $actor['edition_id'], ['changed' => array_map(fn ($s) => explode(' ', $s)[0], $set)]);
        Response::json(['ok' => true]);
    }

    public static function setSuper(int $id): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        if (!self::isSuper($actor)) {
            self::fail('Only a Super admin can do that.', 403);
        }
        $p = self::person($id);
        $value = (bool) ($in['value'] ?? false);
        if (!$value && $p['is_super_admin'] && $p['active'] && self::activeSupers() < 2) {
            self::fail('At least one active Super admin is required.');
        }
        self::pdo()->prepare('UPDATE people SET is_super_admin = ? WHERE id = ?')->execute([$value ? 1 : 0, $id]);
        Audit::log((int) $actor['id'], $value ? 'Made Super admin' : 'Removed Super admin', $p['name'], $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function deletePerson(int $id): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $p = self::person($id);
        if ($id === (int) $actor['id']) {
            self::fail('You can’t delete yourself.');
        }
        if ($p['is_super_admin'] && !self::isSuper($actor)) {
            self::fail('Only a Super admin can delete a Super admin.', 403);
        }
        if ($p['is_super_admin'] && $p['active'] && self::activeSupers() < 2) {
            self::fail('At least one active Super admin is required.');
        }
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM edition_assignments WHERE person_id = ?');
        $st->execute([$id]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            self::fail("{$p['name']} appears in $n edition" . ($n === 1 ? '' : 's') . '. Deactivate them instead so history is kept.');
        }
        try {
            self::pdo()->prepare('DELETE FROM people WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('This person has records linked to them. Deactivate them instead so history is kept.');
            }
            throw $e;
        }
        Cache::flush();
        Audit::log((int) $actor['id'], 'Deleted person', $p['name'], $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------ assignments

    /** Permissions that a set of roles (+ extra grants) would give. A locked role means "everything". */
    private static function permsFrom(array $roleSlugs, array $grants): array
    {
        $pdo = self::pdo();
        $perms = array_fill_keys($grants, true);
        if ($roleSlugs !== []) {
            $in = implode(',', array_fill(0, count($roleSlugs), '?'));
            $st = $pdo->prepare("SELECT id, is_locked FROM roles WHERE slug IN ($in)");
            $st->execute($roleSlugs);
            foreach ($st->fetchAll() as $r) {
                if ($r['is_locked']) {
                    return array_fill_keys($pdo->query('SELECT perm_key FROM permissions')->fetchAll(\PDO::FETCH_COLUMN), true);
                }
                $q = $pdo->prepare('SELECT perm_key FROM role_permissions WHERE role_id = ?');
                $q->execute([$r['id']]);
                foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $k) {
                    $perms[$k] = true;
                }
            }
        }
        return $perms;
    }

    public static function saveAssignment(): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $pdo = self::pdo();

        $target = self::person((int) ($in['personId'] ?? 0));
        $st = $pdo->prepare('SELECT id, status FROM editions WHERE slug = ?');
        $st->execute([Request::str($in, 'editionId', 60)]);
        $ed = $st->fetch() ?: self::fail('That edition was not found.', 404);
        $patch = is_array($in['patch'] ?? null) ? $in['patch'] : [];

        $changesAccess = isset($patch['roleIds']) || isset($patch['grants']) || isset($patch['denies']);
        if ($changesAccess && (int) $target['id'] === (int) $actor['id'] && !self::isSuper($actor)) {
            self::fail('You can’t change your own access.', 403);
        }

        $st = $pdo->prepare('SELECT * FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
        $st->execute([$target['id'], $ed['id']]);
        $current = $st->fetch() ?: null;

        $curRoles = $curGrants = $curDenies = [];
        if ($current) {
            $q = $pdo->prepare('SELECT r.slug FROM assignment_roles ar JOIN roles r ON r.id = ar.role_id WHERE ar.assignment_id = ?');
            $q->execute([$current['id']]);
            $curRoles = $q->fetchAll(\PDO::FETCH_COLUMN);
            $q = $pdo->prepare("SELECT perm_key, effect FROM assignment_overrides WHERE assignment_id = ?");
            $q->execute([$current['id']]);
            foreach ($q->fetchAll() as $o) {
                if ($o['effect'] === 'grant') { $curGrants[] = $o['perm_key']; } else { $curDenies[] = $o['perm_key']; }
            }
        }

        $roles  = isset($patch['roleIds']) ? array_values(array_unique(array_map(fn ($r) => self::slugOf((string) $r), (array) $patch['roleIds']))) : $curRoles;
        $grants = isset($patch['grants']) ? self::validKeys((array) $patch['grants']) : $curGrants;
        $denies = isset($patch['denies']) ? self::validKeys((array) $patch['denies']) : $curDenies;
        $grants = array_values(array_diff($grants, $denies));       // a block always wins

        if ($roles !== []) {
            $in2 = implode(',', array_fill(0, count($roles), '?'));
            $q = $pdo->prepare("SELECT slug, is_locked FROM roles WHERE slug IN ($in2)");
            $q->execute($roles);
            $found = $q->fetchAll();
            if (count($found) !== count($roles)) {
                self::fail('One of those roles no longer exists.');
            }
            foreach ($found as $r) {
                if ($r['is_locked'] && !self::isSuper($actor)) {
                    self::fail('Only a Super admin can give the Super admin role.', 403);
                }
            }
        }

        // Privilege escalation check: whatever is NEWLY given must be something the actor holds in this edition.
        if (!self::isSuper($actor)) {
            $added  = array_diff_key(self::permsFrom($roles, $grants), self::permsFrom($curRoles, $curGrants));
            $mine   = Access::effective($actor, (int) $ed['id'])['perms'];
            if (array_diff_key($added, $mine) !== []) {
                self::fail('You can only give access that you hold yourself.', 403);
            }
        }
        if (($roles !== [] || $grants !== []) && empty($target['email'])) {
            self::fail('This person has no email yet — enter one so they can sign in.');
        }

        $unitId = $current['unit_id'] ?? null;
        if (array_key_exists('unitId', $patch)) {
            $u = (string) $patch['unitId'];
            if ($u === '') {
                $unitId = null;
            } else {
                $q = $pdo->prepare('SELECT COUNT(*) FROM committee_units WHERE id = ?');
                $q->execute([(int) $u]);
                if ((int) $q->fetchColumn() === 0) {
                    self::fail('That committee unit was not found.');
                }
                $unitId = (int) $u;
            }
        }
        $position = array_key_exists('position', $patch) ? mb_substr(trim((string) $patch['position']), 0, 100) : ($current['position'] ?? '');
        $visible  = array_key_exists('visible', $patch) ? (int) (bool) $patch['visible'] : (int) ($current['visible'] ?? 0);

        Database::transaction(function (\PDO $db) use ($current, $target, $ed, $position, $unitId, $visible, $roles, $grants, $denies, $patch) {
            if ($current) {
                $db->prepare('UPDATE edition_assignments SET position = ?, unit_id = ?, visible = ? WHERE id = ?')
                    ->execute([$position, $unitId, $visible, $current['id']]);
                $aid = (int) $current['id'];
            } else {
                $db->prepare('INSERT INTO edition_assignments (person_id, edition_id, position, unit_id, visible) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$target['id'], $ed['id'], $position, $unitId, $visible]);
                $aid = (int) $db->lastInsertId();
            }
            if (isset($patch['roleIds'])) {
                $db->prepare('DELETE FROM assignment_roles WHERE assignment_id = ?')->execute([$aid]);
                foreach ($roles as $slug) {
                    $db->prepare('INSERT INTO assignment_roles (assignment_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$aid, $slug]);
                }
            }
            if (isset($patch['grants']) || isset($patch['denies'])) {
                $db->prepare('DELETE FROM assignment_overrides WHERE assignment_id = ?')->execute([$aid]);
                foreach ($grants as $k) {
                    $db->prepare("INSERT INTO assignment_overrides (assignment_id, perm_key, effect) VALUES (?, ?, 'grant')")->execute([$aid, $k]);
                }
                foreach ($denies as $k) {
                    $db->prepare("INSERT INTO assignment_overrides (assignment_id, perm_key, effect) VALUES (?, ?, 'deny')")->execute([$aid, $k]);
                }
            }
        });

        Cache::flush();                                  // committee position/visibility is public
        Audit::log((int) $actor['id'], $changesAccess ? 'Changed access' : 'Changed committee position', $target['name'], (int) $ed['id'], [
            'roles' => $roles, 'extraAllowed' => count($grants), 'blocked' => count($denies), 'position' => $position,
        ]);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ roles

    private static function role(string $roleId): array
    {
        $st = self::pdo()->prepare('SELECT * FROM roles WHERE slug = ?');
        $st->execute([self::slugOf($roleId)]);
        return $st->fetch() ?: self::fail('That role was not found.', 404);
    }

    private static function rolePermKeys(int $roleId): array
    {
        $st = self::pdo()->prepare('SELECT perm_key FROM role_permissions WHERE role_id = ?');
        $st->execute([$roleId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function setRolePerms(int $roleId, array $keys): void
    {
        self::pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        foreach ($keys as $k) {
            self::pdo()->prepare('INSERT INTO role_permissions (role_id, perm_key) VALUES (?, ?)')->execute([$roleId, $k]);
        }
    }

    public static function createRole(): never
    {
        $in = Request::json();
        $actor = Access::require('roles.manage', Request::str($in, 'edition', 60));
        $name = Request::str($in, 'name', 100);
        if (mb_strlen($name) < 2) {
            self::fail('Give the role a name.');
        }
        $mine = Access::effective($actor, $actor['edition_id'])['perms'];
        $base = ['dashboard.view'];
        if (!empty($in['copyFrom'])) {
            $src = self::role((string) $in['copyFrom']);
            $base = $src['is_locked'] ? [] : self::rolePermKeys((int) $src['id']);
        }
        if (!self::isSuper($actor)) {
            $base = array_values(array_filter($base, fn ($k) => isset($mine[$k])));   // you can't hand out what you don't hold
        }
        if ($base === []) {
            self::fail('Choose a starting role that contains permissions you are allowed to grant.');
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
        $slug = mb_substr($slug === '' ? 'role' : $slug, 0, 50) . '-' . substr(bin2hex(random_bytes(2)), 0, 3);

        self::pdo()->prepare('INSERT INTO roles (slug, name, description, is_system, is_locked) VALUES (?, ?, ?, 0, 0)')
            ->execute([$slug, $name, Request::str($in, 'description', 255) ?: null]);
        $rid = (int) self::pdo()->lastInsertId();
        self::setRolePerms($rid, $base);
        Audit::log((int) $actor['id'], 'Created role', $name, $actor['edition_id'], ['permissions' => count($base)]);
        Response::json(['ok' => true, 'roleId' => 'role-' . $slug]);
    }

    public static function updateRole(string $roleId): never
    {
        $in = Request::json();
        $actor = Access::require('roles.manage', Request::str($in, 'edition', 60));
        $role = self::role($roleId);
        if ($role['is_locked']) {
            self::fail('The Super admin role can’t be changed.', 403);
        }
        $name = Request::str($in, 'name', 100);
        if (mb_strlen($name) < 2) {
            self::fail('Give the role a name.');
        }
        $perms = self::validKeys((array) ($in['perms'] ?? []));
        if ($perms === []) {
            self::fail('A role needs at least one permission.');
        }
        if (!self::isSuper($actor)) {
            $mine  = Access::effective($actor, $actor['edition_id'])['perms'];
            $added = array_diff($perms, self::rolePermKeys((int) $role['id']));
            if (array_diff($added, array_keys($mine)) !== []) {
                self::fail('You can only add permissions that you hold yourself.', 403);
            }
        }
        Database::transaction(function () use ($role, $name, $in, $perms) {
            self::pdo()->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ?')
                ->execute([$name, Request::str($in, 'description', 255) ?: null, $role['id']]);
            self::setRolePerms((int) $role['id'], $perms);
        });
        Audit::log((int) $actor['id'], 'Edited role', $name, $actor['edition_id'], ['permissions' => count($perms)]);
        Response::json(['ok' => true]);
    }

    public static function resetRole(string $roleId): never
    {
        $in = Request::json();
        $actor = Access::require('roles.manage', Request::str($in, 'edition', 60));
        $role = self::role($roleId);
        $defaults = RoleDefaults::PERMISSIONS[$role['slug']] ?? null;
        if ($role['is_locked'] || !$role['is_system'] || $defaults === null) {
            self::fail('Only the built-in roles can be reset.');
        }
        if (!self::isSuper($actor)) {
            $mine = Access::effective($actor, $actor['edition_id'])['perms'];
            $defaults = array_values(array_filter($defaults, fn ($k) => isset($mine[$k])));
            if ($defaults === []) {
                self::fail('You do not have permission to grant any of this role’s default permissions.', 403);
            }
        }
        self::setRolePerms((int) $role['id'], $defaults);
        Audit::log((int) $actor['id'], 'Reset role to default', $role['name'], $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function deleteRole(string $roleId): never
    {
        $in = Request::json();
        $actor = Access::require('roles.manage', Request::str($in, 'edition', 60));
        $role = self::role($roleId);
        if ($role['is_locked'] || $role['is_system']) {
            self::fail('Built-in roles can’t be deleted.', 403);
        }
        $st = self::pdo()->prepare('SELECT (SELECT COUNT(*) FROM assignment_roles WHERE role_id = ?) + (SELECT COUNT(*) FROM invitation_roles WHERE role_id = ?)');
        $st->execute([$role['id'], $role['id']]);
        $used = (int) $st->fetchColumn();
        if ($used > 0) {
            self::fail("“{$role['name']}” is assigned to $used person" . ($used === 1 ? '' : 's') . '. Remove it from them first.');
        }
        self::pdo()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$role['id']]);
        self::pdo()->prepare('DELETE FROM roles WHERE id = ?')->execute([$role['id']]);
        Audit::log((int) $actor['id'], 'Deleted role', $role['name'], $actor['edition_id']);
        Response::json(['ok' => true]);
    }
}