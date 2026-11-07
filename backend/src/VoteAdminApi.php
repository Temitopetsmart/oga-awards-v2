<?php
declare(strict_types=1);

namespace App;

/** Admin side of voting: the ledger, manual votes, voiding, re-checking a payment, results and winners. */
final class VoteAdminApi
{
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
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    /** Loads an order AND checks the permission in the order's own edition. */
    private static function order(string $reference, string $perm): array
    {
        $st = self::pdo()->prepare('SELECT o.*, e.slug AS edition_slug FROM vote_orders o JOIN editions e ON e.id = o.edition_id WHERE o.reference = ?');
        $st->execute([$reference]);
        $o = $st->fetch() ?: self::fail('That order was not found.', 404);
        return $o + ['actor' => Access::require($perm, $o['edition_slug'])];
    }

    private static function orderShape(array $o, bool $money): array
    {
        return [
            'reference'    => $o['reference'],
            'nomineeId'    => (string) $o['nominee_entry_id'],
            'nomineeName'  => $o['nominee_name'] ?? null,
            'categoryId'   => (string) $o['category_id'],
            'categoryName' => $o['category_name'] ?? null,
            'votes'        => (int) $o['votes'],
            'amount'       => $money ? (float) $o['amount'] : 0,
            'name'         => $o['voter_name'],
            'email'        => $o['voter_email'],
            'status'       => $o['status'],
            'source'       => $o['source'] === 'manual' ? 'manual' : 'public',
            'reason'       => $o['reason'] ?? $o['void_reason'],
            'createdAt'    => (new \DateTimeImmutable($o['created_at']))->format('c'),
        ];
    }

    /** Paid public votes, paid manual votes, money collected (from the precomputed totals), pending and void order counts. */
    private static function ledgerStats(int $editionId, bool $money): array
    {
        $paid = VoteTotals::perCategory($editionId);
        $votes = array_sum(array_column($paid, 'votes'));
        $manual = array_sum(array_column($paid, 'manual'));
        $st = self::pdo()->prepare("SELECT status, COUNT(*) FROM vote_orders WHERE edition_id = ? AND status IN ('pending', 'void') GROUP BY status");
        $st->execute([$editionId]);
        $n = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        return [
            'paidVotes'   => (int) ($votes - $manual),
            'manualVotes' => (int) $manual,
            'amount'      => $money ? (float) array_sum(array_column($paid, 'amount')) : 0.0,
            'pending'     => (int) ($n['pending'] ?? 0),
            'void'        => (int) ($n['void'] ?? 0),
        ];
    }

    /**
     * The vote ledger. With ?page= it is paged on the server (filters: status, source, categoryId; search: q;
     * sort: reference, votes, amount, source, status, at) and ?stats=1 adds the totals.
     * Without ?page= it returns the newest 5000 orders, as before.
     */
    public static function ledger(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('votes.view', $slug);
        $ed = self::edition($slug);
        $money = Access::can($actor, 'finance.view', (int) $ed['id']);

        if (!Paging::wanted()) {
            $st = self::pdo()->prepare('SELECT * FROM vote_orders WHERE edition_id = ? ORDER BY id DESC LIMIT 5000');
            $st->execute([$ed['id']]);
            Response::json(['ok' => true, 'orders' => array_map(fn ($o) => self::orderShape($o, $money), $st->fetchAll())]);
        }

        $where = ['o.edition_id = ?'];
        $args = [$ed['id']];
        if (in_array($v = Paging::filter('status'), ['paid', 'pending', 'void'], true)) {
            $where[] = 'o.status = ?';
            $args[] = $v;
        }
        $source = Paging::filter('source');
        if ($source === 'manual') {
            $where[] = "o.source = 'manual'";
        } elseif ($source === 'public') {
            $where[] = "o.source <> 'manual'";
        }
        if (ctype_digit($c = Paging::filter('categoryId')) && $c !== '') {
            $where[] = 'o.category_id = ?';
            $args[] = (int) $c;
        }
        if (($q = Paging::q()) !== '') {
            // Names are matched through short id lists, so a search never has to join every order to its nominee.
            $like = Paging::like($q);
            $where[] = '(o.reference LIKE ? OR o.voter_name LIKE ? OR o.voter_email LIKE ?
                         OR o.nominee_entry_id IN (SELECT id FROM nominee_entries WHERE edition_id = ? AND name LIKE ?)
                         OR o.category_id IN (SELECT id FROM categories WHERE edition_id = ? AND name LIKE ?))';
            array_push($args, $like, $like, $like, $ed['id'], $like, $ed['id'], $like);
        }
        $order = Paging::order([
            'reference' => 'o.reference', 'votes' => 'o.votes', 'amount' => 'o.amount', 'source' => 'o.source', 'status' => 'o.status', 'at' => 'o.id',
        ], 'o.id DESC', 'o.id DESC');

        $r = Paging::run(
            self::pdo(),
            'STRAIGHT_JOIN o.*, n.name AS nominee_name, c.name AS category_name',   // read the orders in index order, then look up the two names
            'vote_orders o JOIN nominee_entries n ON n.id = o.nominee_entry_id JOIN categories c ON c.id = o.category_id',
            $where, $args, $order, 'vote_orders o'
        );
        $out = ['ok' => true, 'orders' => array_map(fn ($o) => self::orderShape($o, $money), $r['rows'])] + Paging::meta($r);
        if (!empty($_GET['stats'])) {
            $out['stats'] = self::ledgerStats((int) $ed['id'], $money);
        }
        Response::json($out);
    }

    public static function addManual(): never
    {
        $in = Request::json();
        $st = self::pdo()->prepare("SELECT n.*, e.slug AS edition_slug FROM nominee_entries n JOIN editions e ON e.id = n.edition_id WHERE n.id = ? AND n.status = 'approved'");
        $st->execute([(int) ($in['nomineeId'] ?? 0)]);
        $n = $st->fetch() ?: self::fail('Choose an approved nominee.');
        $actor = Access::require('votes.addManual', $n['edition_slug']);      // permission in the NOMINEE'S edition

        $votes = $in['votes'] ?? null;
        if (!is_numeric($votes) || (float) $votes != (int) $votes || (int) $votes < 1 || (int) $votes > 10000) {
            self::fail('Enter a whole number of votes from 1 to 10,000.');
        }
        $reason = Request::str($in, 'reason', 500);
        if (mb_strlen($reason) < 8) {
            self::fail('Give a reason (at least 8 characters). It is kept in the audit log.');
        }
        $reference = 'MAN-' . strtoupper(bin2hex(random_bytes(6)));
        Database::transaction(function (\PDO $db) use ($n, $reference, $votes, $actor, $reason) {
            $db->prepare("INSERT INTO vote_orders (edition_id, reference, nominee_entry_id, category_id, votes, amount, voter_name, voter_email, status, source, reason, created_by, paid_at)
                          VALUES (?, ?, ?, ?, ?, 0, ?, '', 'paid', 'manual', ?, ?, NOW())")
                ->execute([$n['edition_id'], $reference, $n['id'], $n['category_id'], (int) $votes, $actor['name'], $reason, $actor['id']]);
            VoteTotals::addOrder($db, ['nominee_entry_id' => $n['id'], 'category_id' => $n['category_id'], 'edition_id' => $n['edition_id'], 'votes' => (int) $votes, 'amount' => 0, 'source' => 'manual']);
        });
        Cache::flush();
        Audit::log((int) $actor['id'], "Added $votes manual votes", $n['name'] . ' — ' . $reason, (int) $n['edition_id']);
        Response::json(['ok' => true, 'reference' => $reference]);
    }

    public static function void(string $reference): never
    {
        $in = Request::json();
        $o = self::order($reference, 'votes.void');
        if ($o['status'] === 'void') {
            self::fail('That order is already void.');
        }
        $reason = Request::str($in, 'reason', 500) ?: 'Voided by an admin';
        $voided = Database::transaction(function (\PDO $db) use ($o, $reason) {
            $lock = $db->prepare('SELECT * FROM vote_orders WHERE id = ? FOR UPDATE');   // read the status again under a lock: two admins cannot both void it
            $lock->execute([$o['id']]);
            $fresh = $lock->fetch();
            if (!$fresh || $fresh['status'] === 'void') {
                return false;
            }
            $db->prepare("UPDATE vote_orders SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")
                ->execute([$o['actor']['id'], $reason, $o['id']]);
            if ($fresh['status'] === 'paid') {
                VoteTotals::removeOrder($db, $fresh);
            }
            return true;
        });
        if (!$voided) {
            self::fail('That order is already void.');
        }
        Cache::flush();
        Audit::log((int) $o['actor']['id'], 'Voided vote order', $reference, (int) $o['edition_id'], ['reason' => $reason, 'wasStatus' => $o['status'], 'votes' => (int) $o['votes']]);
        Response::json(['ok' => true]);
    }

    /** "Mark as paid" is NOT a switch: it re-asks Flutterwave. Only a payment Flutterwave confirms can be credited. */
    public static function recheck(string $reference): never
    {
        $o = self::order($reference, 'finance.reconcile');
        if ($o['source'] !== 'payment' || $o['status'] !== 'pending' || $o['payment_id'] === null) {
            self::fail('Only a pending online payment can be re-checked.');
        }
        $payment = self::pdo()->prepare('SELECT reference FROM payments WHERE id = ?');
        $payment->execute([$o['payment_id']]);
        $result = PaymentService::settle((string) $payment->fetchColumn());
        if ($result['status'] !== 'paid') {
            self::fail('Flutterwave does not show a successful payment for this order yet. ' . ($result['note'] ?? ''));
        }
        Cache::flush();
        Audit::log((int) $o['actor']['id'], 'Marked vote order paid (confirmed with Flutterwave)', $reference, (int) $o['edition_id']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------ results

    public static function results(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('results.view', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $tally = VoteTotals::perNominee((int) $ed['id']);
        $w = $pdo->prepare('SELECT category_id, nominee_entry_id FROM winners WHERE edition_id = ?');
        $w->execute([$ed['id']]);
        Response::json(['ok' => true, 'tally' => (object) $tally, 'winners' => array_map(fn ($r) => [
            'categoryId' => (string) $r['category_id'], 'nomineeId' => (string) $r['nominee_entry_id'],
        ], $w->fetchAll())]);
    }

    public static function saveWinners(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('results.save', $slug);
        $ed = self::edition($slug);
        $rows = array_values((array) ($in['rows'] ?? []));
        $seen = [];
        $check = self::pdo()->prepare("SELECT COUNT(*) FROM nominee_entries n JOIN categories c ON c.id = n.category_id
                                        WHERE n.id = ? AND n.category_id = ? AND n.edition_id = ? AND c.edition_id = ? AND n.status = 'approved'");
        foreach ($rows as $r) {
            $cat = (int) ($r['categoryId'] ?? 0);
            $nom = (int) ($r['nomineeId'] ?? 0);
            if (isset($seen[$cat])) {
                self::fail('Only one winner can be chosen per category.');
            }
            $seen[$cat] = true;
            $check->execute([$nom, $cat, $ed['id'], $ed['id']]);
            if ((int) $check->fetchColumn() === 0) {
                self::fail('Each winner must be an approved nominee of that category.');
            }
        }
        Database::transaction(function (\PDO $db) use ($ed, $rows, $actor) {
            $db->prepare('DELETE FROM winners WHERE edition_id = ?')->execute([$ed['id']]);       // other editions are never touched
            $ins = $db->prepare('INSERT INTO winners (edition_id, category_id, nominee_entry_id, set_by) VALUES (?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$ed['id'], (int) $r['categoryId'], (int) $r['nomineeId'], $actor['id']]);
            }
        });
        Cache::flush();
        Audit::log((int) $actor['id'], 'Saved winners', count($rows) . ' categories', (int) $ed['id']);
        Response::json(['ok' => true]);
    }
}