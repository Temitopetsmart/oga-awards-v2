<?php
declare(strict_types=1);

namespace App;

/** Dashboard numbers, sidebar badge counts, and the log for things the browser does by itself (exports, previews). */
final class DashboardApi
{
    /** kind => [permission needed, audit action] */
    private const ACTIVITY = [
        'export:nominees'  => ['nominees.export', 'Exported nominees'],
        'export:votes'     => ['votes.export', 'Exported votes'],
        'export:tickets'   => ['tickets.export', 'Exported ticket orders'],
        'export:results'   => ['results.view', 'Exported results'],
        'preview:nominee'  => ['nominees.view', 'Previewed nominee dashboard'],
    ];

    private static function one(string $sql, array $args): int
    {
        $st = Database::pdo()->prepare($sql);
        $st->execute($args);
        return (int) $st->fetchColumn();
    }

    /** Only what this person may see; everything else is left out. */
    public static function stats(): never
    {
        $slug  = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('dashboard.view', $slug);
        $ed    = (int) $actor['edition_id'];
        $pdo   = Database::pdo();
        $out   = ['ok' => true];

        if (Access::can($actor, 'votes.view', $ed)) {
            $rows = VoteTotals::perCategory($ed);
            $out['votes'] = (int) array_sum(array_column($rows, 'votes'));
            $out['byCategory'] = array_map(fn ($r) => [
                'categoryId' => $r['category_id'],
                'votes' => $r['votes']
            ], $rows);

            if (Access::can($actor, 'finance.view', $ed)) {
                $out['voteAmount'] = (float) array_sum(array_column($rows, 'amount'));
            }
        }
        if (Access::can($actor, 'tickets.view', $ed)) {
            $out['ticketsSold'] = self::one("SELECT COUNT(*) FROM tickets WHERE edition_id = ? AND status = 'issued'", [$ed]);
            if (Access::can($actor, 'finance.view', $ed)) {
                $out['ticketRevenue'] = (float) self::one("SELECT COALESCE(SUM(amount), 0) FROM ticket_orders WHERE edition_id = ? AND status = 'paid'", [$ed]);
            }
        }
        if (Access::can($actor, 'committee.view', $ed)) {
            $out['applications'] = self::one("SELECT COUNT(*) FROM committee_applications WHERE edition_id = ? AND status <> 'withdrawn'", [$ed]);
        }
        if (Access::can($actor, 'messages.view', $ed)) {
            $out['messages'] = self::one('SELECT COUNT(*) FROM contact_messages WHERE edition_id = ?', [$ed]);
        }
        if (Access::can($actor, 'audit.view', $ed)) {
            $st = $pdo->prepare('SELECT a.action, a.target, a.created_at, p.name FROM audit_log a LEFT JOIN people p ON p.id = a.person_id
                                  WHERE a.edition_id = ? OR a.edition_id IS NULL ORDER BY a.id DESC LIMIT 6');
            $st->execute([$ed]);
            $out['activity'] = array_map(fn ($a) => [
                'admin'  => $a['name'] ?? 'System', 'action' => $a['action'], 'target' => $a['target'],
                'at'     => (new \DateTimeImmutable($a['created_at']))->format('c'),
            ], $st->fetchAll());
        }
        header('Cache-Control: no-store');
        Response::json($out);
    }

    /** Sidebar counts for one edition: what needs attention. Used when the admin shell loads. */
    public static function badges(array $person, int $editionId): array
    {
        $b = [];
        if (Access::can($person, 'messages.view', $editionId)) {
            $b['unreadMessages'] = self::one('SELECT COUNT(*) FROM contact_messages WHERE edition_id = ? AND is_read = 0', [$editionId]);
        }
        if (Access::can($person, 'committee.view', $editionId)) {
            $b['newApplications'] = self::one("SELECT COUNT(*) FROM committee_applications WHERE edition_id = ? AND status = 'submitted'", [$editionId]);
        }
        return $b;
    }

    /** POST /admin/activity: records an export or preview the browser did by itself. Checked against the same permission. */
    public static function record(): never
    {
        $in   = Request::json();
        $kind = Request::str($in, 'kind', 40);
        if (!isset(self::ACTIVITY[$kind])) {
            Response::error('That activity is not recognised.', 422);
        }
        [$perm, $action] = self::ACTIVITY[$kind];
        $actor = Access::require($perm, Request::str($in, 'edition', 60));
        Audit::log((int) $actor['id'], $action, Request::str($in, 'target', 255) ?: null, (int) $actor['edition_id']);
        Response::json(['ok' => true]);
    }
}