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
        $st = self::pdo()->prepare('SELECT * FROM editions WHERE slug = ?');
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

        /** Per category: how many approved nominees, who leads, and whether the top spot is tied. */
    private static function standings(int $editionId): array
    {
        $tally = VoteTotals::perNominee($editionId);
        $st = self::pdo()->prepare("SELECT id, category_id, name FROM nominee_entries WHERE edition_id = ? AND status = 'approved' ORDER BY id");
        $st->execute([$editionId]);
        $cats = [];
        foreach ($st->fetchAll() as $n) {
            $cats[(int) $n['category_id']][] = ['id' => (int) $n['id'], 'name' => $n['name'], 'votes' => $tally[(string) $n['id']] ?? 0];
        }
        $out = [];
        foreach ($cats as $cid => $list) {
            $max = max(array_column($list, 'votes'));
            $top = array_values(array_filter($list, fn ($x) => $x['votes'] === $max));
            $out[$cid] = ['max' => $max, 'leaders' => array_column($top, 'id'), 'tied' => count($top) > 1 && $max > 0, 'votes' => array_column($list, 'votes', 'id'), 'names' => array_column($list, 'name', 'id')];
        }
        return $out;
    }

    private static function publishedShape(array $ed): array
    {
        $at = $ed['results_published_at'] ?? null;
        $by = null;
        if (!empty($ed['results_published_by'])) {
            $st = self::pdo()->prepare('SELECT name FROM people WHERE id = ?');
            $st->execute([$ed['results_published_by']]);
            $by = $st->fetchColumn() ?: null;
        }
        return [
            'at'        => $at ? (new \DateTimeImmutable($at))->format('c') : null,
            'by'        => $by,
            'live'      => $at !== null && strtotime($at) <= time(),
            'scheduled' => $at !== null && strtotime($at) > time(),
        ];
    }

    public static function results(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('results.view', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $tally = VoteTotals::perNominee((int) $ed['id']);
        $w = $pdo->prepare('SELECT category_id, nominee_entry_id, override_reason FROM winners WHERE edition_id = ?');
        $w->execute([$ed['id']]);
        $stand = self::standings((int) $ed['id']);
        Response::json(['ok' => true, 'tally' => (object) $tally, 'winners' => array_map(fn ($r) => [
            'categoryId' => (string) $r['category_id'], 'nomineeId' => (string) $r['nominee_entry_id'], 'reason' => (string) ($r['override_reason'] ?? ''),
        ], $w->fetchAll()),
            'ties'      => array_map('strval', array_keys(array_filter($stand, fn ($x) => $x['tied']))),
            'published' => self::publishedShape($ed),
            'state'     => EditionState::of($ed),
        ]);
    }

    public static function saveWinners(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('results.save', $slug);
        $ed = self::edition($slug);
        if (($ed['results_published_at'] ?? null) !== null) {
            self::fail('The results are published. Retract them first if a winner has to change.');
        }
        $rows = array_values((array) ($in['rows'] ?? []));
        $stand = self::standings((int) $ed['id']);
        $seen = [];
        $check = self::pdo()->prepare("SELECT COUNT(*) FROM nominee_entries n JOIN categories c ON c.id = n.category_id
                                        WHERE n.id = ? AND n.category_id = ? AND n.edition_id = ? AND c.edition_id = ? AND n.status = 'approved'");
        foreach ($rows as $i => $r) {
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
            // A winner who is not simply the vote leader (or who wins a tie) needs a written reason on record.
            $s = $stand[$cat] ?? null;
            $reason = trim((string) ($r['reason'] ?? ''));
            $isLeader = $s !== null && in_array($nom, $s['leaders'], true);
            $needs = $s !== null && $s['max'] > 0 && (!$isLeader || $s['tied']);
            if ($needs && mb_strlen($reason) < 5) {
                $why = $isLeader ? 'is tied for the lead' : 'is not the vote leader';
                self::fail(($s['names'][$nom] ?? 'This nominee') . " $why. Write the reason for choosing them (at least 5 characters).");
            }
            $rows[$i]['reason'] = $needs ? mb_substr($reason, 0, 255) : null;
        }
        Database::transaction(function (\PDO $db) use ($ed, $rows, $actor) {
            $db->prepare('DELETE FROM winners WHERE edition_id = ?')->execute([$ed['id']]);       // other editions are never touched
            $ins = $db->prepare('INSERT INTO winners (edition_id, category_id, nominee_entry_id, set_by, override_reason) VALUES (?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$ed['id'], (int) $r['categoryId'], (int) $r['nomineeId'], $actor['id'], $r['reason']]);
            }
        });
        Cache::flush();
        $overrides = count(array_filter($rows, fn ($r) => $r['reason'] !== null));
        Audit::log((int) $actor['id'], 'Saved winners', count($rows) . ' categories' . ($overrides ? ", $overrides with a written reason" : ''), (int) $ed['id']);
        Response::json(['ok' => true]);
    }

    /**
     * Makes the winners public: now, or at a time the admin picks (for a ceremony reveal). Needs voting to be over
     * and a winner in every category that has approved nominees (unless the admin says some are intentionally left out).
     */
    public static function publishResults(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('results.publish', $slug);
        $ed = self::edition($slug);
        if (($ed['results_published_at'] ?? null) !== null) {
            self::fail('The results are already published' . (self::publishedShape($ed)['scheduled'] ? ' (scheduled).' : '.') . ' Retract them first to change anything.');
        }
        if (in_array(EditionState::of($ed), ['landing', 'nominations', 'voting'], true)) {
            self::fail('Voting is not over yet. Results can only be published after voting closes.');
        }
        $stand = self::standings((int) $ed['id']);
        $w = self::pdo()->prepare('SELECT category_id FROM winners WHERE edition_id = ?');
        $w->execute([$ed['id']]);
        $have = array_map('intval', $w->fetchAll(\PDO::FETCH_COLUMN));
        if ($have === []) {
            self::fail('Save the winners first. Nothing has been chosen yet.');
        }
        $missing = array_values(array_diff(array_keys($stand), $have));
        if ($missing !== [] && empty($in['allowMissing'])) {
            self::fail(count($missing) . ' categor' . (count($missing) === 1 ? 'y has' : 'ies have') . ' no winner yet. Choose them, or confirm that they should be left out.', 422);
        }
        $at = null;
        if (($in['at'] ?? null) !== null && $in['at'] !== '') {
            $ts = strtotime((string) $in['at']);
            if ($ts === false) {
                self::fail('That date and time is not valid.');
            }
            $at = max($ts, time());
        }
        $when = date('Y-m-d H:i:s', $at ?? time());
        self::pdo()->prepare('UPDATE editions SET results_published_at = ?, results_published_by = ? WHERE id = ?')->execute([$when, $actor['id'], $ed['id']]);
        $warning = null;
        if ($ed['state_mode'] === 'auto') {
            // The site's "winners announced" state moves at the same moment.
            self::pdo()->prepare('UPDATE editions SET results_publish_at = ? WHERE id = ?')->execute([$when, $ed['id']]);
        } elseif ($ed['force_state'] !== 'results') {
            $warning = 'This edition is set by hand to “' . $ed['force_state'] . '”, so visitors will not see the winners until you change its state to “Winners announced” in Settings.';
        }
        Cache::flush();
        Audit::log((int) $actor['id'], $at !== null && $at > time() + 5 ? 'Scheduled results' : 'Published results', count($have) . ' winners, live ' . $when . ($missing ? ', ' . count($missing) . ' categories left out' : ''), (int) $ed['id']);
        Response::json(['ok' => true, 'published' => self::publishedShape(self::edition($slug)), 'warning' => $warning]);
    }

    /** Takes published (or scheduled) results back down. The reason is kept in the activity log. */
    public static function retractResults(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('results.publish', $slug);
        $ed = self::edition($slug);
        if (($ed['results_published_at'] ?? null) === null) {
            self::fail('The results are not published.');
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            self::fail('Say why the results are being taken down (at least 5 characters).');
        }
        self::pdo()->prepare('UPDATE editions SET results_published_at = NULL, results_published_by = NULL WHERE id = ?')->execute([$ed['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Retracted results', mb_substr($reason, 0, 200), (int) $ed['id']);
        Response::json(['ok' => true]);
    }
}