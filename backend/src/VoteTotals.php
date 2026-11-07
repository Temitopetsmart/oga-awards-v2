<?php
declare(strict_types=1);

namespace App;

/**
 * Precomputed vote totals (table vote_totals): paid votes per nominee and category.
 * Every place that turns an order into a paid one, adds manual votes or voids a paid order calls one of the
 * methods below INSIDE the same database transaction, so the totals can never disagree with the orders.
 * drift() compares them with the orders; backend/tools/rebuild-vote-totals.php --fix repairs them.
 */
final class VoteTotals
{
    /** After a payment's pending orders were set to paid. */
    public static function creditPayment(\PDO $db, int $paymentId): void
    {
        $db->prepare(
            "INSERT INTO vote_totals (nominee_entry_id, category_id, edition_id, votes, manual_votes, amount)
             SELECT nominee_entry_id, category_id, edition_id, SUM(votes), SUM(CASE WHEN source = 'manual' THEN votes ELSE 0 END), SUM(amount)
               FROM vote_orders WHERE payment_id = ? AND status = 'paid'
              GROUP BY nominee_entry_id, category_id, edition_id
             ON DUPLICATE KEY UPDATE votes = votes + VALUES(votes), manual_votes = manual_votes + VALUES(manual_votes), amount = amount + VALUES(amount)"
        )->execute([$paymentId]);
    }

    /** After a paid manual order was inserted. */
    public static function addOrder(\PDO $db, array $order): void
    {
        $manual = ($order['source'] ?? 'payment') === 'manual' ? (int) $order['votes'] : 0;
        $db->prepare(
            'INSERT INTO vote_totals (nominee_entry_id, category_id, edition_id, votes, manual_votes, amount) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE votes = votes + VALUES(votes), manual_votes = manual_votes + VALUES(manual_votes), amount = amount + VALUES(amount)'
        )->execute([$order['nominee_entry_id'], $order['category_id'], $order['edition_id'], (int) $order['votes'], $manual, (float) $order['amount']]);
    }

    /** When a PAID order is voided. */
    public static function removeOrder(\PDO $db, array $order): void
    {
        $manual = ($order['source'] ?? 'payment') === 'manual' ? (int) $order['votes'] : 0;
        $db->prepare('UPDATE vote_totals SET votes = votes - ?, manual_votes = manual_votes - ?, amount = amount - ? WHERE nominee_entry_id = ? AND category_id = ?')
            ->execute([(int) $order['votes'], $manual, (float) $order['amount'], $order['nominee_entry_id'], $order['category_id']]);
    }

    /** nominee id => total paid votes, for an edition. */
    public static function perNominee(int $editionId): array
    {
        $st = Database::pdo()->prepare('SELECT nominee_entry_id, SUM(votes) AS v FROM vote_totals WHERE edition_id = ? GROUP BY nominee_entry_id');
        $st->execute([$editionId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['nominee_entry_id']] = (int) $r['v'];
        }
        return $out;
    }

    /** @return array<int,array{category_id:string,votes:int,manual:int,amount:float}> */
    public static function perCategory(int $editionId): array
    {
        $st = Database::pdo()->prepare('SELECT category_id, SUM(votes) AS v, SUM(manual_votes) AS m, SUM(amount) AS a FROM vote_totals WHERE edition_id = ? GROUP BY category_id');
        $st->execute([$editionId]);
        return array_map(fn ($r) => ['category_id' => (string) $r['category_id'], 'votes' => (int) $r['v'], 'manual' => (int) $r['m'], 'amount' => (float) $r['a']], $st->fetchAll());
    }

    /** Rows where the stored totals differ from the orders (or are missing / extra). Empty = healthy. */
    public static function drift(?\PDO $db = null): array
    {
        $db ??= Database::pdo();
        return $db->query(
            "SELECT COALESCE(t.nominee_entry_id, o.nominee_entry_id) AS nominee_entry_id, COALESCE(t.category_id, o.category_id) AS category_id,
                    COALESCE(t.votes, 0) AS stored, COALESCE(o.v, 0) AS actual
               FROM vote_totals t
               LEFT JOIN (SELECT nominee_entry_id, category_id, SUM(votes) AS v, SUM(CASE WHEN source = 'manual' THEN votes ELSE 0 END) AS m, SUM(amount) AS a
                            FROM vote_orders WHERE status = 'paid' GROUP BY nominee_entry_id, category_id) o
                      ON o.nominee_entry_id = t.nominee_entry_id AND o.category_id = t.category_id
              WHERE COALESCE(o.v, 0) <> t.votes OR COALESCE(o.m, 0) <> t.manual_votes OR COALESCE(o.a, 0) <> t.amount
             UNION
             SELECT o.nominee_entry_id, o.category_id, 0, o.v
               FROM (SELECT nominee_entry_id, category_id, SUM(votes) AS v FROM vote_orders WHERE status = 'paid' GROUP BY nominee_entry_id, category_id) o
               LEFT JOIN vote_totals t ON t.nominee_entry_id = o.nominee_entry_id AND t.category_id = o.category_id
              WHERE t.nominee_entry_id IS NULL"
        )->fetchAll();
    }

    /** Recompute everything from the orders. */
    public static function rebuild(?\PDO $db = null): int
    {
        $db ??= Database::pdo();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM vote_totals');
            $db->exec(
                "INSERT INTO vote_totals (nominee_entry_id, category_id, edition_id, votes, manual_votes, amount)
                 SELECT nominee_entry_id, category_id, edition_id, SUM(votes), SUM(CASE WHEN source = 'manual' THEN votes ELSE 0 END), SUM(amount)
                   FROM vote_orders WHERE status = 'paid' GROUP BY nominee_entry_id, category_id, edition_id"
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return (int) $db->query('SELECT COUNT(*) FROM vote_totals')->fetchColumn();
    }
}