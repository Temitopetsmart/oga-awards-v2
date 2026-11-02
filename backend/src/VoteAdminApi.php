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

    public static function ledger(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('votes.view', $slug);
        $ed = self::edition($slug);
        $money = Access::can($actor, 'finance.view', (int) $ed['id']);
        $st = self::pdo()->prepare('SELECT * FROM vote_orders WHERE edition_id = ? ORDER BY id DESC LIMIT 5000');
        $st->execute([$ed['id']]);
        Response::json(['ok' => true, 'orders' => array_map(fn ($o) => [
            'reference'  => $o['reference'],
            'nomineeId'  => (string) $o['nominee_entry_id'],
            'categoryId' => (string) $o['category_id'],
            'votes'      => (int) $o['votes'],
            'amount'     => $money ? (float) $o['amount'] : 0,
            'name'       => $o['voter_name'],
            'email'      => $o['voter_email'],
            'status'     => $o['status'],
            'source'     => $o['source'] === 'manual' ? 'manual' : 'public',
            'reason'     => $o['reason'] ?? $o['void_reason'],
            'createdAt'  => (new \DateTimeImmutable($o['created_at']))->format('c'),
        ], $st->fetchAll())]);
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
        self::pdo()->prepare("INSERT INTO vote_orders (edition_id, reference, nominee_entry_id, category_id, votes, amount, voter_name, voter_email, status, source, reason, created_by, paid_at)
                              VALUES (?, ?, ?, ?, ?, 0, ?, '', 'paid', 'manual', ?, ?, NOW())")
            ->execute([$n['edition_id'], $reference, $n['id'], $n['category_id'], (int) $votes, $actor['name'], $reason, $actor['id']]);
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
        self::pdo()->prepare("UPDATE vote_orders SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE id = ?")
            ->execute([$o['actor']['id'], $reason, $o['id']]);
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
        $tally = [];
        $st = $pdo->prepare("SELECT nominee_entry_id, SUM(votes) AS total FROM vote_orders WHERE edition_id = ? AND status = 'paid' GROUP BY nominee_entry_id");
        $st->execute([$ed['id']]);
        foreach ($st->fetchAll() as $r) {
            $tally[(string) $r['nominee_entry_id']] = (int) $r['total'];
        }
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