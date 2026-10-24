<?php
declare(strict_types=1);

namespace App;

final class AuditApi
{
    /** Newest first. You see entries of editions where you hold audit.view, plus entries not tied to an edition. */
    public static function list(): never
    {
        $person = Auth::person() ?? Response::error('Please sign in.', 401);
        $pdo = Database::pdo();
        $ids = array_values(array_filter(
            $pdo->query('SELECT id FROM editions')->fetchAll(\PDO::FETCH_COLUMN),
            fn ($id) => Access::can($person, 'audit.view', (int) $id)
        ));
        if ($ids === []) {
            Response::error('You do not have permission to do that.', 403);
        }
        $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 1000)));
        $in = implode(',', array_map('intval', $ids));
        $rows = $pdo->query(
            "SELECT a.id, a.created_at, a.action, a.target, p.name AS admin_name, e.slug AS edition_slug
               FROM audit_log a
               LEFT JOIN people p ON p.id = a.person_id
               LEFT JOIN editions e ON e.id = a.edition_id
              WHERE a.edition_id IS NULL OR a.edition_id IN ($in)
              ORDER BY a.id DESC LIMIT $limit"
        )->fetchAll();
        Response::json(['ok' => true, 'entries' => array_map(fn ($r) => [
            'id'        => (string) $r['id'],
            'at'        => (new \DateTimeImmutable($r['created_at']))->format('c'),
            'admin'     => $r['admin_name'] ?? 'System',
            'action'    => $r['action'],
            'target'    => $r['target'] ?? '',
            'editionId' => $r['edition_slug'],
        ], $rows)]);
    }

    /** Recorded when someone downloads the audit CSV. */
    public static function exported(): never
    {
        $in = Request::json();
        $actor = Access::require('audit.view', Request::str($in, 'edition', 60));
        Audit::log((int) $actor['id'], 'Exported audit log', null, $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    /** Recorded when someone starts/stops previewing another role's or person's access ("View as…"). */
    public static function preview(): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $start = ($in['action'] ?? '') === 'start';
        $label = '';
        if (preg_match('/^(role|person):(.+)$/', (string) ($in['target'] ?? ''), $m)) {
            $pdo = Database::pdo();
            $st = $m[1] === 'role'
                ? $pdo->prepare('SELECT name FROM roles WHERE slug = ?')
                : $pdo->prepare('SELECT name FROM people WHERE id = ?');
            $st->execute([$m[1] === 'role' ? preg_replace('/^role-/', '', $m[2]) : (int) $m[2]]);
            $label = (string) ($st->fetchColumn() ?: '');
        }
        Audit::log((int) $actor['id'], $start ? 'Started previewing access' : 'Stopped previewing access', $label ?: null, $actor['edition_id']);
        Response::json(['ok' => true]);
    }
}