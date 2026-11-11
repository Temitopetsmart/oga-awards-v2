<?php
declare(strict_types=1);

namespace App;

/**
 * Phase 12: the Nominee Access Pass. An approved nominee can claim ONE free pass per edition: a normal ticket group
 * (the nominee plus the number of guests the admin allows), so the existing gate scanner and ticket pages just work.
 * Nominees attend whether or not they win; the winners stay secret until they are announced.
 */
final class AccessPass
{
    public static function settings(int $editionId): array
    {
        $st = Database::pdo()->prepare('SELECT access_pass_enabled, access_pass_guests FROM edition_settings WHERE edition_id = ?');
        $st->execute([$editionId]);
        $r = $st->fetch();
        return ['enabled' => $r ? (bool) $r['access_pass_enabled'] : false, 'guests' => $r ? (int) $r['access_pass_guests'] : 0];
    }

    public static function activeEdition(): ?array
    {
        return Database::pdo()->query("SELECT * FROM editions WHERE status = 'active' LIMIT 1")->fetch() ?: null;
    }

    /** At least one approved entry in the edition. */
    public static function eligible(\PDO $db, int $accountId, int $editionId): bool
    {
        $st = $db->prepare("SELECT COUNT(*) FROM nominee_entries WHERE account_id = ? AND edition_id = ? AND status = 'approved'");
        $st->execute([$accountId, $editionId]);
        return (int) $st->fetchColumn() > 0;
    }

    /** The pass type of an edition (never on sale, never listed). Made the first time it is needed. */
    private static function typeId(\PDO $db, int $editionId): int
    {
        $st = $db->prepare("SELECT id FROM ticket_types WHERE edition_id = ? AND kind = 'access_pass' LIMIT 1");
        $st->execute([$editionId]);
        if (($id = $st->fetchColumn()) !== false) {
            return (int) $id;
        }
        $db->prepare("INSERT INTO ticket_types (edition_id, name, kind, price, seats, description, max_per_order, active, sort_order) VALUES (?, 'Nominee Access Pass', 'access_pass', 0, 1, 'Free entry for approved nominees', 1, 0, 9999)")
            ->execute([$editionId]);
        return (int) $db->lastInsertId();
    }

    /** The tickets of this nominee's pass (empty if not claimed). */
    public static function tickets(\PDO $db, int $accountId, int $editionId): array
    {
        $st = $db->prepare(
            "SELECT t.ticket_code, t.holder_name, t.seat_no, t.checked_in_at, t.status
               FROM tickets t JOIN ticket_orders o ON o.id = t.order_id
              WHERE o.account_id = ? AND o.edition_id = ? ORDER BY t.seat_no"
        );
        $st->execute([$accountId, $editionId]);
        return array_map(fn ($r) => [
            'code' => $r['ticket_code'], 'holder' => $r['holder_name'], 'seat' => (int) $r['seat_no'],
            'used' => $r['checked_in_at'] !== null, 'void' => $r['status'] === 'void',
        ], $st->fetchAll());
    }

    /** Claims the pass. Safe to call twice: the second call finds the first. Returns the tickets. */
    public static function claim(array $account, array $ed): array
    {
        $guests = self::settings((int) $ed['id'])['guests'];
        return Database::transaction(function (\PDO $db) use ($account, $ed, $guests) {
            $db->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE')->execute([$account['id']]);   // one claim at a time
            $have = self::tickets($db, (int) $account['id'], (int) $ed['id']);
            if ($have !== []) {
                return $have;
            }
            $st = $db->prepare('SELECT name, phone FROM nominee_entries WHERE account_id = ? AND edition_id = ? ORDER BY id LIMIT 1');
            $st->execute([$account['id'], $ed['id']]);
            $who = $st->fetch() ?: ['name' => $account['email'], 'phone' => ''];

            $db->prepare("INSERT INTO ticket_orders (edition_id, reference, ticket_type_id, qty, amount, buyer_name, buyer_email, buyer_phone, status, paid_at, account_id)
                          VALUES (?, ?, ?, 1, 0, ?, ?, ?, 'paid', NOW(), ?)")
                ->execute([$ed['id'], 'ACP-' . strtoupper(bin2hex(random_bytes(6))), self::typeId($db, (int) $ed['id']), $who['name'], $account['email'], (string) ($who['phone'] ?? ''), $account['id']]);
            $orderId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO ticket_groups (edition_id, order_id, group_code, seats) VALUES (?, ?, ?, ?)')
                ->execute([$ed['id'], $orderId, 'GRP-' . strtoupper(bin2hex(random_bytes(4))), 1 + $guests]);
            $groupId = (int) $db->lastInsertId();
            $ins = $db->prepare('INSERT INTO tickets (edition_id, order_id, group_id, ticket_code, seat_no, holder_name) VALUES (?, ?, ?, ?, ?, ?)');
            for ($seat = 1; $seat <= 1 + $guests; $seat++) {
                $ins->execute([$ed['id'], $orderId, $groupId, 'TKT-' . strtoupper(bin2hex(random_bytes(6))), $seat, $seat === 1 ? $who['name'] : 'Guest ' . ($seat - 1) . ' of ' . $who['name']]);
            }
            return self::tickets($db, (int) $account['id'], (int) $ed['id']);
        });
    }
}