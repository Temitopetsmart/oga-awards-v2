<?php
declare(strict_types=1);

namespace App;

/**
 * Nominee vote feed: a nominee's OWN vote total per category and their latest paid votes
 * (voter name as the admin allows, number of votes, time). Off by default, switched on per edition by the admin.
 * Never returns emails, rankings or anybody else's numbers.
 */
final class VoteFeed
{
    public const NAME_MODES = ['full', 'masked', 'anonymous'];

    public static function settings(int $editionId): array
    {
        $st = Database::pdo()->prepare('SELECT vote_feed_enabled, vote_feed_names, vote_feed_recent FROM edition_settings WHERE edition_id = ?');
        $st->execute([$editionId]);
        $r = $st->fetch();
        $mode = (string) ($r['vote_feed_names'] ?? 'masked');
        return [
            'enabled' => $r ? (bool) $r['vote_feed_enabled'] : false,
            'names'   => in_array($mode, self::NAME_MODES, true) ? $mode : 'masked',
            'recent'  => $r ? max(1, min(20, (int) $r['vote_feed_recent'])) : 5,
        ];
    }

    /** How a voter's name is shown: full, "Ade O." / "A***" (masked) or "A supporter". An email address is never shown. */
    public static function displayName(string $name, string $mode): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($mode === 'anonymous' || $name === '' || str_contains($name, '@')) {
            return 'A supporter';
        }
        if ($mode === 'full') {
            return mb_substr($name, 0, 60);
        }
        $parts = explode(' ', $name);
        if (count($parts) > 1) {
            return mb_substr($parts[0], 0, 20) . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
        }
        return mb_substr($name, 0, 1) . '***';
    }

    /** The signed-in nominee's own numbers for the edition. */
    public static function forAccount(int $accountId, array $edition): array
    {
        $cfg = self::settings((int) $edition['id']);
        $out = ['enabled' => $cfg['enabled'], 'names' => $cfg['names'], 'recent' => $cfg['recent'], 'entries' => [], 'total' => 0];
        if (!$cfg['enabled']) {
            return $out;
        }
        $db = Database::pdo();
        $st = $db->prepare(
            'SELECT n.id, n.status, c.name AS category FROM nominee_entries n JOIN categories c ON c.id = n.category_id
              WHERE n.account_id = ? AND n.edition_id = ? ORDER BY n.id'
        );
        $st->execute([$accountId, $edition['id']]);
        $sum = $db->prepare('SELECT COALESCE(SUM(votes), 0) FROM vote_totals WHERE nominee_entry_id = ?');
        $last = $db->prepare(
            "SELECT voter_name, votes, COALESCE(paid_at, created_at) AS at FROM vote_orders
              WHERE nominee_entry_id = ? AND status = 'paid'
              ORDER BY COALESCE(paid_at, created_at) DESC, id DESC LIMIT " . (int) $cfg['recent']
        );
        foreach ($st->fetchAll() as $e) {
            $sum->execute([$e['id']]);
            $votes = (int) $sum->fetchColumn();
            $last->execute([$e['id']]);
            $recent = [];
            foreach ($last->fetchAll() as $o) {
                $recent[] = [
                    'name'  => self::displayName((string) $o['voter_name'], $cfg['names']),
                    'votes' => (int) $o['votes'],
                    'at'    => (new \DateTimeImmutable((string) $o['at']))->format('c'),
                ];
            }
            $out['entries'][] = ['id' => (string) $e['id'], 'category' => $e['category'], 'status' => $e['status'], 'votes' => $votes, 'recent' => $recent];
            $out['total'] += $votes;
        }
        return $out;
    }
}