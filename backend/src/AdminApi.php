<?php
declare(strict_types=1);

namespace App;

/** /admin/* endpoints. Responses are never cached. */
final class AdminApi
{
    public static function handle(string $method, string $path): never
    {
        header('Cache-Control: no-store');

        if ($method !== 'GET') {
            Session::requireCsrf();                 // every change needs the CSRF token
        }

        match (true) {
            $method === 'GET'  && $path === '/admin/csrf'   => Response::json(['ok' => true, 'csrf' => Session::csrf()]),
            $method === 'POST' && $path === '/admin/login'  => self::login(),
            $method === 'POST' && $path === '/admin/logout' => self::logout(),
            $method === 'GET'  && $path === '/admin/me'     => self::me(),
            default => Response::error('Not found', 404),
        };
    }

    private static function login(): never
    {
        $in = Request::json();
        $person = Auth::attempt(Request::str($in, 'email', 190), (string) ($in['password'] ?? ''));
        if ($person === null) {
            Response::error('We could not sign you in with those details.', 401);
        }
        Response::json(['ok' => true] + self::bootstrap($person));
    }

    private static function logout(): never
    {
        Auth::logout();
        Response::json(['ok' => true]);
    }

    private static function me(): never
    {
        $person = Auth::person();
        if ($person === null) {
            Response::json(['ok' => true, 'authenticated' => false, 'csrf' => Session::csrf()]);
        }
        Response::json(['ok' => true] + self::bootstrap($person));
    }

    /**
     * Everything the admin shell needs after sign-in, in the same shapes the V2 admin already uses.
     * Other people's details are included only for those allowed to see the team.
     */
    private static function bootstrap(array $person): array
    {
        $pdo = Database::pdo();
        $allowedIds = array_map('intval', Access::editionIds($person));

        $doc = Catalog::editions(true);
        $slugById = $pdo->query('SELECT id, slug FROM editions')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $allowedSlugs = array_map(fn ($id) => $slugById[$id], $allowedIds);
        $doc['editions'] = array_values(array_filter($doc['editions'], fn ($e) => in_array($e['id'], $allowedSlugs, true)));

        $seeTeam = (int) $person['is_super_admin'] === 1;
        foreach ($allowedIds as $id) {
            $seeTeam = $seeTeam || isset(Access::effective($person, $id)['perms']['team.view']);
        }

        // roles (with their permissions); the locked Super role is "*"
        $perms = [];
        foreach ($pdo->query('SELECT role_id, perm_key FROM role_permissions')->fetchAll() as $rp) {
            $perms[$rp['role_id']][] = $rp['perm_key'];
        }
        $roles = array_map(fn ($r) => [
            'id'          => 'role-' . $r['slug'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'perms'       => (int) $r['is_locked'] === 1 ? ['*'] : ($perms[$r['id']] ?? []),
            'system'      => (bool) $r['is_system'],
            'locked'      => (bool) $r['is_locked'],
        ], $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll());

        $roleSlug = $pdo->query('SELECT id, slug FROM roles')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $asgRoles = [];
        foreach ($pdo->query('SELECT assignment_id, role_id FROM assignment_roles')->fetchAll() as $r) {
            $asgRoles[$r['assignment_id']][] = 'role-' . $roleSlug[$r['role_id']];
        }
        $asgOver = [];
        foreach ($pdo->query('SELECT assignment_id, perm_key, effect FROM assignment_overrides')->fetchAll() as $o) {
            $asgOver[$o['assignment_id']][$o['effect']][] = $o['perm_key'];
        }
        $assignments = [];
        foreach ($pdo->query('SELECT * FROM edition_assignments ORDER BY id')->fetchAll() as $a) {
            if (!$seeTeam && (int) $a['person_id'] !== (int) $person['id']) {
                continue;
            }
            $assignments[] = [
                'id'        => (string) $a['id'],
                'personId'  => (string) $a['person_id'],
                'editionId' => $slugById[$a['edition_id']],
                'position'  => $a['position'],
                'unitId'    => $a['unit_id'] === null ? '' : (string) $a['unit_id'],
                'visible'   => (bool) $a['visible'],
                'roleIds'   => $asgRoles[$a['id']] ?? [],
                'grants'    => $asgOver[$a['id']]['grant'] ?? [],
                'denies'    => $asgOver[$a['id']]['deny'] ?? [],
            ];
        }

        $people = [];
        foreach ($pdo->query('SELECT id, name, email, active, is_super_admin, photo_path FROM people ORDER BY id')->fetchAll() as $p) {
            if (!$seeTeam && (int) $p['id'] !== (int) $person['id']) {
                continue;
            }
            $people[] = [
                'id'         => (string) $p['id'],
                'name'       => $p['name'],
                'email'      => $p['email'] ?? '',
                'active'     => (bool) $p['active'],
                'superAdmin' => (bool) $p['is_super_admin'],
                'photo'      => $p['photo_path'],
            ];
        }

        return [
            'authenticated' => true,
            'csrf'          => Session::csrf(),
            'person'        => [
                'id'         => (string) $person['id'],
                'name'       => $person['name'],
                'email'      => $person['email'] ?? '',
                'active'     => true,
                'superAdmin' => (bool) $person['is_super_admin'],
                'photo'      => $person['photo_path'],
            ],
            'editions'      => $doc,
            'roles'         => $roles,
            'assignments'   => $assignments,
            'people'        => $people,
        ];
    }
}