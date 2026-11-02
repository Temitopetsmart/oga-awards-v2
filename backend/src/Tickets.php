<?php
declare(strict_types=1);

namespace App;

/** Turns a PAID ticket order into tickets: one ticket per seat, each with its own code, linked by a group code. */
final class Tickets
{
    private static function code(string $prefix, int $bytes): string
    {
        return $prefix . strtoupper(bin2hex(random_bytes($bytes)));
    }

    /**
     * Issues the tickets of an order. Safe to call twice: only the call that flips the order from "pending" to
     * "paid" issues anything. Runs inside PaymentService's lock (or the free-ticket transaction).
     */
    public static function issue(\PDO $db, int $orderId): int
    {
        $flip = $db->prepare("UPDATE ticket_orders SET status = 'paid', paid_at = NOW() WHERE id = ? AND status = 'pending'");
        $flip->execute([$orderId]);
        if ($flip->rowCount() === 0) {
            return 0;                                         // already issued (or void): nothing to do
        }
        $st = $db->prepare('SELECT o.*, t.seats FROM ticket_orders o JOIN ticket_types t ON t.id = o.ticket_type_id WHERE o.id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch();

        $groupIns = $db->prepare('INSERT INTO ticket_groups (edition_id, order_id, group_code, seats) VALUES (?, ?, ?, ?)');
        $ticketIns = $db->prepare('INSERT INTO tickets (edition_id, order_id, group_id, ticket_code, seat_no, holder_name) VALUES (?, ?, ?, ?, ?, ?)');
        $count = 0;
        for ($unit = 0; $unit < (int) $o['qty']; $unit++) {
            $groupIns->execute([$o['edition_id'], $orderId, self::code('GRP-', 4), (int) $o['seats']]);
            $groupId = (int) $db->lastInsertId();
            for ($seat = 1; $seat <= (int) $o['seats']; $seat++) {
                $ticketIns->execute([$o['edition_id'], $orderId, $groupId, self::code('TKT-', 6), $seat, $o['buyer_name']]);
                $count++;
            }
        }
        return $count;
    }

    /** Called when a verified payment arrives for a ticket order. */
    public static function issueForPayment(\PDO $db, array $payment): void
    {
        $st = $db->prepare('SELECT id FROM ticket_orders WHERE payment_id = ?');
        $st->execute([$payment['id']]);
        if (($id = $st->fetchColumn()) !== false) {
            self::issue($db, (int) $id);
        }
    }
}