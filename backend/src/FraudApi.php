<?php
declare(strict_types=1);

namespace App;

/**
 * Phase 17: anti-fraud. Signals are worked out from the paid orders (nothing is hidden or changed by looking),
 * an admin reviews them, and can void the orders and block an address. Thresholds are the admin's.
 * Careful with IP rules: a whole campus can share one public address, so the IP limit is OFF until the admin sets it.
 */
final class FraudApi
{
    /** Domains where lots of different people legitimately have an address. */
    private const COMMON_DOMAINS = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'live.com', 'proton.me', 'protonmail.com', 'yahoo.co.uk', 'googlemail.com', 'me.com', 'msn.com', 'aol.com'];

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function edition(string $slug): array
    {
        $st = self::pdo()->prepare('SELECT id, slug FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('Unknown edition.', 404);
    }

    // ------------------------------------------------------------ rules

    public static function defaults(): array
    {
        return ['ipMaxOrdersPerHour' => 0, 'burstVotes' => 300, 'burstMinutes' => 10, 'ipClusterOrders' => 15, 'domainClusterEmails' => 20];
    }

    public static function rules(int $editionId): array
    {
        $st = self::pdo()->prepare('SELECT fraud_rules FROM edition_settings WHERE edition_id = ?');
        $st->execute([$editionId]);
        $saved = json_decode((string) $st->fetchColumn(), true);
        return array_replace(self::defaults(), is_array($saved) ? array_intersect_key($saved, self::defaults()) : []);
    }

    // ------------------------------------------------------------ at the door (used when a vote order is made)

    /** Returns a message if this voter must be turned away, otherwise null. */
    public static function turnAway(int $editionId, string $ip, string $email): ?string
    {
        $domain = substr((string) strrchr($email, '@'), 1);
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM vote_blocks WHERE edition_id = ? AND ((kind = 'ip' AND value = ?) OR (kind = 'email' AND value = ?) OR (kind = 'domain' AND value = ?))");
        $st->execute([$editionId, $ip, mb_strtolower($email), mb_strtolower($domain)]);
        if ((int) $st->fetchColumn() > 0) {
            return 'We could not accept this vote right now. If you think this is a mistake, please contact the organisers.';
        }
        $max = (int) self::rules($editionId)['ipMaxOrdersPerHour'];
        if ($max > 0) {
            $c = self::pdo()->prepare('SELECT COUNT(*) FROM vote_orders WHERE edition_id = ? AND ip_address = ? AND created_at > (NOW() - INTERVAL 60 MINUTE)');
            $c->execute([$editionId, $ip]);
            if ((int) $c->fetchColumn() >= $max) {
                return 'Too many votes have been started from this connection in the last hour. Please try again later.';
            }
        }
        return null;
    }

    // ------------------------------------------------------------ signals

    /** The paid payment orders a signal key points at (key = what the signal was built from). */
    private static function ordersOf(int $editionId, string $key): array
    {
        $parts = explode(':', $key);
        $base = "SELECT id, reference, nominee_entry_id, votes, voter_email, ip_address, paid_at FROM vote_orders WHERE edition_id = ? AND status = 'paid' AND source = 'payment'";
        $args = [$editionId];
        switch ($parts[0] ?? '') {
            case 'burst':       // burst:{nominee}:{window}:{minutes}
                if (count($parts) !== 4) { return []; }
                $base .= ' AND nominee_entry_id = ? AND FLOOR(UNIX_TIMESTAMP(paid_at) / (60 * ?)) = ?';
                array_push($args, (int) $parts[1], (int) $parts[3], (int) $parts[2]);
                break;
            case 'ip':          // ip:{address}:{nominee}   (an IPv6 address contains colons, so the nominee is the last part)
                $nominee = (int) array_pop($parts);
                $ip = implode(':', array_slice($parts, 1));
                $base .= ' AND nominee_entry_id = ? AND ip_address = ?';
                array_push($args, $nominee, $ip);
                break;
            case 'domain':      // domain:{domain}:{nominee}
                if (count($parts) !== 3) { return []; }
                $base .= " AND nominee_entry_id = ? AND SUBSTRING_INDEX(voter_email, '@', -1) = ?";
                array_push($args, (int) $parts[2], $parts[1]);
                break;
            default:
                return [];
        }
        $st = self::pdo()->prepare($base);
        $st->execute($args);
        return $st->fetchAll();
    }

    private static function signals(int $editionId, array $r): array
    {
        $pdo = self::pdo();
        $names = $pdo->prepare('SELECT n.id, n.name, c.name AS category FROM nominee_entries n JOIN categories c ON c.id = n.category_id WHERE n.edition_id = ?');
        $names->execute([$editionId]);
        $nom = array_column($names->fetchAll(), null, 'id');
        $reviews = $pdo->prepare('SELECT signal_key, status, note FROM fraud_reviews WHERE edition_id = ?');
        $reviews->execute([$editionId]);
        $done = array_column($reviews->fetchAll(), null, 'signal_key');
        $out = [];
        $add = function (string $kind, string $key, int $nomineeId, int $votes, int $orders, string $detail) use (&$out, $nom, $done) {
            $out[] = [
                'key' => $key, 'kind' => $kind, 'nomineeId' => (string) $nomineeId, 'nominee' => $nom[$nomineeId]['name'] ?? '(removed)',
                'category' => $nom[$nomineeId]['category'] ?? '', 'votes' => $votes, 'orders' => $orders, 'detail' => $detail,
                'status' => $done[$key]['status'] ?? 'open', 'note' => $done[$key]['note'] ?? '',
            ];
        };
        $paid = "FROM vote_orders WHERE edition_id = ? AND status = 'paid' AND source = 'payment'";
        if ($r['burstVotes'] > 0) {
            $m = max(1, (int) $r['burstMinutes']);
            $st = $pdo->prepare("SELECT nominee_entry_id AS n, FLOOR(UNIX_TIMESTAMP(paid_at) / (60 * $m)) AS w, SUM(votes) AS v, COUNT(*) AS o, MIN(paid_at) AS s $paid AND paid_at IS NOT NULL GROUP BY nominee_entry_id, w HAVING v >= ?");
            $st->execute([$editionId, (int) $r['burstVotes']]);
            foreach ($st->fetchAll() as $x) {
                $add('burst', "burst:{$x['n']}:{$x['w']}:$m", (int) $x['n'], (int) $x['v'], (int) $x['o'], "{$x['v']} votes in $m minutes, from " . $x['s']);
            }
        }
        if ($r['ipClusterOrders'] > 0) {
            $st = $pdo->prepare("SELECT ip_address AS ip, nominee_entry_id AS n, COUNT(*) AS o, SUM(votes) AS v $paid AND ip_address IS NOT NULL GROUP BY ip_address, nominee_entry_id HAVING o >= ?");
            $st->execute([$editionId, (int) $r['ipClusterOrders']]);
            foreach ($st->fetchAll() as $x) {
                $add('ip_cluster', "ip:{$x['ip']}:{$x['n']}", (int) $x['n'], (int) $x['v'], (int) $x['o'], "{$x['o']} paid orders from the address {$x['ip']}");
            }
        }
        if ($r['domainClusterEmails'] > 0) {
            $marks = implode(',', array_fill(0, count(self::COMMON_DOMAINS), '?'));
            $st = $pdo->prepare("SELECT SUBSTRING_INDEX(voter_email, '@', -1) AS d, nominee_entry_id AS n, COUNT(DISTINCT voter_email) AS e, COUNT(*) AS o, SUM(votes) AS v $paid
                                  AND SUBSTRING_INDEX(voter_email, '@', -1) NOT IN ($marks) GROUP BY d, nominee_entry_id HAVING e >= ?");
            $st->execute(array_merge([$editionId], self::COMMON_DOMAINS, [(int) $r['domainClusterEmails']]));
            foreach ($st->fetchAll() as $x) {
                $add('domain_cluster', "domain:{$x['d']}:{$x['n']}", (int) $x['n'], (int) $x['v'], (int) $x['o'], "{$x['e']} different email addresses at {$x['d']}");
            }
        }
        usort($out, fn ($a, $b) => [$a['status'] === 'open' ? 0 : 1, -$a['votes']] <=> [$b['status'] === 'open' ? 0 : 1, -$b['votes']]);
        return $out;
    }

    private static function blocks(int $editionId): array
    {
        $st = self::pdo()->prepare('SELECT b.id, b.kind, b.value, b.reason, b.created_at, p.name AS by_name FROM vote_blocks b LEFT JOIN people p ON p.id = b.created_by WHERE b.edition_id = ? ORDER BY b.id DESC');
        $st->execute([$editionId]);
        return array_map(fn ($b) => ['id' => (string) $b['id'], 'kind' => $b['kind'], 'value' => $b['value'], 'reason' => (string) $b['reason'], 'by' => $b['by_name'], 'at' => (new \DateTimeImmutable($b['created_at']))->format('c')], $st->fetchAll());
    }

    // ------------------------------------------------------------ admin routes

    public static function overview(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('fraud.view', $slug);
        $ed = self::edition($slug);
        $rules = self::rules((int) $ed['id']);
        $signals = self::signals((int) $ed['id'], $rules);
        Response::json(['ok' => true, 'rules' => $rules, 'signals' => $signals, 'open' => count(array_filter($signals, fn ($s) => $s['status'] === 'open')), 'blocks' => self::blocks((int) $ed['id'])]);
    }

    public static function saveRules(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('fraud.manage', $slug);
        $ed = self::edition($slug);
        $limits = ['ipMaxOrdersPerHour' => [0, 1000], 'burstVotes' => [0, 1000000], 'burstMinutes' => [1, 120], 'ipClusterOrders' => [0, 1000], 'domainClusterEmails' => [0, 1000]];
        $labels = ['ipMaxOrdersPerHour' => 'Orders per address per hour', 'burstVotes' => 'Burst: votes', 'burstMinutes' => 'Burst: minutes', 'ipClusterOrders' => 'Address cluster: orders', 'domainClusterEmails' => 'Domain cluster: emails'];
        $rules = [];
        foreach ($limits as $k => [$lo, $hi]) {
            $v = $in[$k] ?? null;
            if (!is_numeric($v) || (float) $v != (int) $v || (int) $v < $lo || (int) $v > $hi) {
                self::fail("{$labels[$k]}: enter a whole number from $lo to $hi.");
            }
            $rules[$k] = (int) $v;
        }
        self::pdo()->prepare('INSERT INTO edition_settings (edition_id, fraud_rules, updated_by) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE fraud_rules = VALUES(fraud_rules), updated_by = VALUES(updated_by)')
            ->execute([$ed['id'], json_encode($rules), $actor['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Changed the fraud rules', json_encode($rules), (int) $ed['id']);
        Response::json(['ok' => true]);
    }

    /** Dismiss a signal (it is fine), or void every paid order behind it, optionally blocking the address or domain. */
    public static function review(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('fraud.manage', $slug);
        $ed = self::edition($slug);
        $key = Request::str($in, 'key', 190);
        $action = (string) ($in['action'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!in_array($action, ['dismiss', 'void'], true) || $key === '') {
            self::fail('Choose what to do with this signal.');
        }
        $rev = self::pdo()->prepare('SELECT status FROM fraud_reviews WHERE edition_id = ? AND signal_key = ?');
        $rev->execute([$ed['id'], $key]);
        if ($rev->fetchColumn() !== false) {
            self::fail('This signal has already been reviewed.');
        }
        if ($action === 'dismiss') {
            self::pdo()->prepare("INSERT INTO fraud_reviews (edition_id, signal_key, status, note, reviewed_by) VALUES (?, ?, 'dismissed', ?, ?)")
                ->execute([$ed['id'], $key, mb_substr($note, 0, 255) ?: null, $actor['id']]);
            Audit::log((int) $actor['id'], 'Dismissed a fraud signal', $key, (int) $ed['id']);
            Response::json(['ok' => true]);
        }
        $actor = Access::require('votes.void', $slug);                 // voiding votes needs the void permission too
        if (mb_strlen($note) < 5) {
            self::fail('Say why these votes are being cancelled (at least 5 characters).');
        }
        $orders = self::ordersOf((int) $ed['id'], $key);
        if ($orders === []) {
            self::fail('There are no paid orders left behind this signal.');
        }
        $voided = Database::transaction(function (\PDO $db) use ($orders, $actor, $note, $ed, $key) {
            $count = 0;
            $votes = 0;
            foreach ($orders as $o) {
                $lock = $db->prepare('SELECT * FROM vote_orders WHERE id = ? FOR UPDATE');
                $lock->execute([$o['id']]);
                $fresh = $lock->fetch();
                if (!$fresh || $fresh['status'] !== 'paid') {
                    continue;
                }
                $db->prepare("UPDATE vote_orders SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")
                    ->execute([$actor['id'], 'Fraud review: ' . mb_substr($note, 0, 450), $o['id']]);
                VoteTotals::removeOrder($db, $fresh);
                $count++;
                $votes += (int) $fresh['votes'];
            }
            $db->prepare("INSERT INTO fraud_reviews (edition_id, signal_key, status, note, reviewed_by) VALUES (?, ?, 'actioned', ?, ?)")
                ->execute([$ed['id'], $key, mb_substr($note, 0, 255), $actor['id']]);
            return [$count, $votes];
        });
        $blocked = null;
        if (!empty($in['block'])) {
            $parts = explode(':', $key);
            if ($parts[0] === 'ip') {
                array_pop($parts);
                $blocked = ['ip', implode(':', array_slice($parts, 1))];
            } elseif ($parts[0] === 'domain') {
                $blocked = ['domain', $parts[1]];
            }
            if ($blocked !== null) {
                self::pdo()->prepare('INSERT IGNORE INTO vote_blocks (edition_id, kind, value, reason, created_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$ed['id'], $blocked[0], $blocked[1], 'Fraud review: ' . mb_substr($note, 0, 200), $actor['id']]);
            }
        }
        Cache::flush();
        Audit::log((int) $actor['id'], 'Voided votes after a fraud review', $key, (int) $ed['id'], ['orders' => $voided[0], 'votes' => $voided[1], 'reason' => $note, 'blocked' => $blocked !== null ? implode(' ', $blocked) : null]);
        Response::json(['ok' => true, 'orders' => $voided[0], 'votes' => $voided[1], 'blocked' => $blocked !== null]);
    }

    public static function addBlock(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('fraud.manage', $slug);
        $ed = self::edition($slug);
        $kind = (string) ($in['kind'] ?? '');
        $value = mb_strtolower(trim((string) ($in['value'] ?? '')));
        $ok = match ($kind) {
            'ip'     => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'email'  => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'domain' => (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})+$/', $value),
            default  => false,
        };
        if (!$ok) {
            self::fail('Enter a valid ' . ($kind === 'ip' ? 'IP address' : ($kind === 'email' ? 'email address' : 'domain such as example.com')) . '.');
        }
        if ($kind === 'domain' && in_array($value, self::COMMON_DOMAINS, true)) {
            self::fail('That domain is used by far too many real people to block.');
        }
        $reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 255);
        try {
            self::pdo()->prepare('INSERT INTO vote_blocks (edition_id, kind, value, reason, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$ed['id'], $kind, $value, $reason !== '' ? $reason : null, $actor['id']]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('That is already blocked.');
            }
            throw $e;
        }
        Audit::log((int) $actor['id'], 'Blocked a voter', "$kind $value", (int) $ed['id']);
        Response::json(['ok' => true]);
    }

    public static function removeBlock(int $id): never
    {
        $st = self::pdo()->prepare('SELECT b.*, e.slug FROM vote_blocks b JOIN editions e ON e.id = b.edition_id WHERE b.id = ?');
        $st->execute([$id]);
        $b = $st->fetch() ?: self::fail('That block was not found.', 404);
        $actor = Access::require('fraud.manage', $b['slug']);
        self::pdo()->prepare('DELETE FROM vote_blocks WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'Removed a voter block', $b['kind'] . ' ' . $b['value'], (int) $b['edition_id']);
        Response::json(['ok' => true]);
    }
}