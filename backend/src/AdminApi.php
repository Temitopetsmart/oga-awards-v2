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

        if ($method === 'GET' && $path === '/admin/csrf') {
            Response::json(['ok' => true, 'csrf' => Session::csrf()]);
        }
        if ($method === 'POST' && $path === '/admin/login') {
            self::login();
        }
        if ($method === 'POST' && $path === '/admin/logout') {
            self::logout();
        }
        if ($method === 'GET' && $path === '/admin/me') {
            self::me();
        }

        // Everything below needs a signed-in admin. Checked once, here, before any route looks at the request.
        if (Auth::person() === null) {
            Response::error('Please sign in.', 401);
        }

        // ---- team, access and roles ----
        if ($method === 'POST' && $path === '/admin/people') {
            TeamApi::addPerson();
        }
        if (preg_match('#^/admin/people/(\d+)$#', $path, $m)) {
            $method === 'PATCH'  && TeamApi::updatePerson((int) $m[1]);
            $method === 'DELETE' && TeamApi::deletePerson((int) $m[1]);
        }
        if ($method === 'POST' && preg_match('#^/admin/people/(\d+)/super$#', $path, $m)) {
            TeamApi::setSuper((int) $m[1]);
        }
        if ($method === 'PUT' && $path === '/admin/assignments') {
            TeamApi::saveAssignment();
        }
        if ($method === 'POST' && $path === '/admin/roles') {
            TeamApi::createRole();
        }
        if (preg_match('#^/admin/roles/([a-z0-9-]+)$#', $path, $m)) {
            $method === 'PATCH'  && TeamApi::updateRole($m[1]);
            $method === 'DELETE' && TeamApi::deleteRole($m[1]);
        }
        if ($method === 'POST' && preg_match('#^/admin/roles/([a-z0-9-]+)/reset$#', $path, $m)) {
            TeamApi::resetRole($m[1]);
        }


                // ---- invitations, audit log, "View as…" previews ----
        if ($method === 'POST' && $path === '/admin/invitations') {
            InviteApi::create();
        }
        if ($method === 'GET' && $path === '/admin/invitations') {
            InviteApi::list();
        }
        if ($method === 'POST' && preg_match('#^/admin/invitations/(\d+)/revoke$#', $path, $m)) {
            InviteApi::revoke((int) $m[1]);
        }
        if ($method === 'GET' && $path === '/admin/audit') {
            AuditApi::list();
        }
        if ($method === 'POST' && $path === '/admin/audit/export') {
            AuditApi::exported();
        }
        if ($method === 'POST' && $path === '/admin/preview') {
            AuditApi::preview();
        }

                // ---- editions ----
        if ($method === 'POST' && $path === '/admin/editions') {
            EditionApi::create();
        }
        if (preg_match('#^/admin/editions/([a-z0-9-]+)$#', $path, $m)) {
            $method === 'PATCH'  && EditionApi::updateDetails($m[1]);
            $method === 'DELETE' && EditionApi::delete($m[1]);
        }
        if ($method === 'PUT' && preg_match('#^/admin/editions/([a-z0-9-]+)/state$#', $path, $m)) {
            EditionApi::saveState($m[1]);
        }
        if ($method === 'PUT' && preg_match('#^/admin/editions/([a-z0-9-]+)/voting$#', $path, $m)) {
            EditionApi::saveVoting($m[1]);
        }
        if ($method === 'POST' && preg_match('#^/admin/editions/([a-z0-9-]+)/transition$#', $path, $m)) {
            EditionApi::transition($m[1]);
        }

        // ---- categories and occasions ----
        if ($method === 'GET' && $path === '/admin/categories') {
            ContentApi::listCategories();
        }
        if ($method === 'POST' && $path === '/admin/categories') {
            ContentApi::createCategory();
        }
        if (preg_match('#^/admin/categories/(\d+)$#', $path, $m)) {
            $method === 'PATCH'  && ContentApi::updateCategory((int) $m[1]);
            $method === 'DELETE' && ContentApi::deleteCategory((int) $m[1]);
        }
        if ($method === 'POST' && $path === '/admin/occasions') {
            ContentApi::createOccasion();
        }
        if (preg_match('#^/admin/occasions/([a-z0-9-]+)$#', $path, $m)) {
            $method === 'PATCH'  && ContentApi::updateOccasion($m[1]);
            $method === 'DELETE' && ContentApi::deleteOccasion($m[1]);
        }
        if ($method === 'POST' && preg_match('#^/admin/occasions/([a-z0-9-]+)/toggle$#', $path, $m)) {
            ContentApi::toggleOccasion($m[1]);
        }
        
                // ---- nominees ----
        if ($method === 'GET' && $path === '/admin/nominees') {
            NomineeApi::list();
        }
        if ($method === 'POST' && $path === '/admin/nominees') {
            NomineeApi::create();
        }
        if ($method === 'POST' && $path === '/admin/nominees/status') {
            NomineeApi::setStatus();
        }
        if ($method === 'GET' && preg_match('#^/admin/nominees/(\d+)$#', $path, $m)) {
            NomineeApi::preview((int) $m[1]);
        }
        if (preg_match('#^/admin/nominees/(\d+)$#', $path, $m)) {
            $method === 'PATCH'  && NomineeApi::update((int) $m[1]);
            $method === 'DELETE' && NomineeApi::delete((int) $m[1]);
        }
        if (preg_match('#^/admin/nominees/(\d+)/photo$#', $path, $m)) {
            $method === 'POST'   && NomineeApi::uploadPhoto((int) $m[1]);
            $method === 'DELETE' && NomineeApi::removePhoto((int) $m[1]);
        }

        // ---- site settings ----
        if ($method === 'GET' && $path === '/admin/settings') {
            SettingsApi::read();
        }
        if ($method === 'PUT' && preg_match('#^/admin/settings/([a-z]+)$#', $path, $m)) {
            SettingsApi::save($m[1]);
        }

        // ---- votes and results ----
        if ($method === 'GET' && $path === '/admin/votes') {
            VoteAdminApi::ledger();
        }
        if ($method === 'POST' && $path === '/admin/votes/manual') {
            VoteAdminApi::addManual();
        }
        if ($method === 'POST' && preg_match('#^/admin/votes/([A-Z0-9-]+)/void$#', $path, $m)) {
            VoteAdminApi::void($m[1]);
        }
        if ($method === 'POST' && preg_match('#^/admin/votes/([A-Z0-9-]+)/recheck$#', $path, $m)) {
            VoteAdminApi::recheck($m[1]);
        }
        if ($method === 'GET' && $path === '/admin/results') {
            VoteAdminApi::results();
        }
        if ($method === 'PUT' && $path === '/admin/results/winners') {
            VoteAdminApi::saveWinners();
        }

        // ---- tickets and the gate scanner ----
        if ($method === 'GET' && $path === '/admin/tickets') {
            TicketAdminApi::list();
        }
        if ($method === 'POST' && preg_match('#^/admin/tickets/([A-Z0-9-]+)/override$#', $path, $m)) {
            TicketAdminApi::override($m[1]);
        }
        if ($method === 'PATCH' && preg_match('#^/admin/ticket-groups/([A-Z0-9-]+)$#', $path, $m)) {
            TicketAdminApi::setTable($m[1]);
        }
        if ($method === 'GET' && $path === '/admin/ticket-types') {
            TicketAdminApi::types();
        }
        if ($method === 'POST' && $path === '/admin/ticket-types') {
            TicketAdminApi::createType();
        }
        if (preg_match('#^/admin/ticket-types/(\d+)$#', $path, $m)) {
            $method === 'PATCH'  && TicketAdminApi::updateType((int) $m[1]);
            $method === 'DELETE' && TicketAdminApi::deleteType((int) $m[1]);
        }
        if ($method === 'POST' && $path === '/admin/scan') {
            TicketAdminApi::scan();
        }
        if ($method === 'GET' && $path === '/admin/scan') {
            TicketAdminApi::scanSummary();
        }

        // ---- Dashboard, messages, activity log ----
        if ($method === 'GET' && $path === '/admin/dashboard') {
            DashboardApi::stats();
        }
        if ($method === 'POST' && $path === '/admin/activity') {
            DashboardApi::record();
        }
        if ($method === 'GET' && $path === '/admin/messages') {
            MessagesApi::list();
        }
        if (preg_match('#^/admin/messages/(\d+)$#', $path, $m)) {
            $method === 'PATCH' && MessagesApi::update((int) $m[1]);
            $method === 'DELETE' && MessagesApi::delete((int) $m[1]);
        }

        // ---- Committee recruitment ----
        if ($method === 'GET' && $path === '/admin/committee') {
            CommitteeApi::overview();
        }
        if ($method === 'PUT' && $path === '/admin/committee/config') {
            CommitteeApi::saveConfig();
        }
        if ($method === 'POST' && preg_match('#^/admin/committee/applications/(\d+)$#', $path, $m)) {
            CommitteeApi::act((int) $m[1]);
        }
        if ($method === 'POST' && $path === '/admin/committee/members') {
            CommitteeApi::addMember();
        }
        if (preg_match('#^/admin/committee/members/(\d+)$#', $path, $m)) {
            $method === 'PATCH' && CommitteeApi::editMember((int) $m[1]);
            $method === 'DELETE' && CommitteeApi::removeMember((int) $m[1]);
        }

        Response::error('Not found', 404);
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

        $pending = [];
        foreach ($allowedIds as $id) {
            if (Access::can($person, 'nominees.view', $id)) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM nominee_entries WHERE edition_id = ? AND status = 'pending'");
                $q->execute([$id]);
                $pending[$slugById[$id]] = (int) $q->fetchColumn();
            }
        }

        $badges = [];
        foreach ($allowedIds as $id) {
            $badges[$slugById[$id]] = DashboardApi::badges($person, $id);
        }

        return [
            'pendingNominees' => (object) $pending,
            'badges' => (object) array_map(fn ($b) => (object) $b, $badges),
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