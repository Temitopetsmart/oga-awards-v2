<?php
declare(strict_types=1);

namespace App;

/** Public ticketing: buy, confirm after paying, and find your tickets. The price is decided HERE, never by the browser. */
final class TicketApi
{
    private static function fail(string $message, int $status = 422, array $extra = []): never
    {
        Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function throttle(string $kind, int $max, int $minutes, bool $failuresOnly = false): void
    {
        $id = hash('sha256', "$kind:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)' . ($failuresOnly ? ' AND success = 0' : ''));
        $st->execute([$id]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many requests. Please wait a little and try again.', 429);
        }
    }

    private static function record(string $kind, bool $success): void
    {
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)')
            ->execute([hash('sha256', "$kind:" . Request::ip()), Request::ip(), $success ? 1 : 0]);
    }

    public static function createOrder(): never
    {
        self::throttle('ticket-order', 120, 60);
        self::record('ticket-order', true);
        $in = Request::json();
        $pdo = self::pdo();

        $name  = Request::str($in, 'name', 100);
        $email = mb_strtolower(Request::str($in, 'email', 190));
        $phone = Request::str($in, 'phone', 30);
        $qty   = $in['qty'] ?? null;
        $f = [];
        if (mb_strlen($name) < 2) $f['name'] = 'Enter your name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $f['email'] = 'Enter a valid email address.';
        if (!preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) $f['phone'] = 'Enter a valid phone number.';
        if ($f) {
            self::fail(reset($f), 422, ['fields' => $f]);
        }

        $ed = $pdo->query("SELECT e.*, s.currency FROM editions e LEFT JOIN edition_settings s ON s.edition_id = e.id WHERE e.status = 'active'")->fetch();
        $features = $ed ? (json_decode((string) $ed['features'], true) ?: []) : [];
        if (!$ed || empty($features['tickets'])) {
            self::fail('Ticket sales are not open right now.');
        }
        $st = $pdo->prepare('SELECT * FROM ticket_types WHERE id = ? AND edition_id = ? AND active = 1');
        $st->execute([(int) ($in['typeId'] ?? 0), $ed['id']]);
        $type = $st->fetch() ?: self::fail('That ticket type is not available.');

        if (!(is_int($qty) || (is_string($qty) && ctype_digit($qty))) || (int) $qty < 1 || (int) $qty > (int) $type['max_per_order']) {
            self::fail("You can buy 1 to {$type['max_per_order']} tickets per order.", 422, ['fields' => ['qty' => 'Choose a valid quantity.']]);
        }
        $qty = (int) $qty;
        $amount = round($qty * (float) $type['price'], 2);          // OUR price, never the browser's
        $currency = $ed['currency'] ?: 'NGN';
        $reference = 'TIK-' . strtoupper(bin2hex(random_bytes(6)));

        // free tickets: no payment needed, issue straight away
        if ($amount <= 0) {
            Database::transaction(function (\PDO $db) use ($ed, $reference, $type, $qty, $name, $email, $phone) {
                $db->prepare("INSERT INTO ticket_orders (edition_id, reference, ticket_type_id, qty, amount, buyer_name, buyer_email, buyer_phone, status)
                              VALUES (?, ?, ?, ?, 0, ?, ?, ?, 'pending')")
                    ->execute([$ed['id'], $reference, $type['id'], $qty, $name, $email, $phone]);
                Tickets::issue($db, (int) $db->lastInsertId());
            });
            Response::json(['ok' => true, 'reference' => $reference, 'amount' => 0, 'free' => true, 'qty' => $qty, 'email' => $email]);
        }

        Database::transaction(function (\PDO $db) use ($ed, $reference, $type, $qty, $amount, $currency, $name, $email, $phone) {
            $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (?, 'ticket', ?, ?, ?, ?, 'pending')")
                ->execute([$ed['id'], $reference, $amount, $currency, $email]);
            $paymentId = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO ticket_orders (edition_id, reference, ticket_type_id, qty, amount, buyer_name, buyer_email, buyer_phone, status, payment_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
                ->execute([$ed['id'], $reference, $type['id'], $qty, $amount, $name, $email, $phone, $paymentId]);
        });

        try {
            $link = Flutterwave::createPayment([
                'reference' => $reference, 'amount' => $amount, 'currency' => $currency, 'email' => $email, 'name' => $name,
                'redirectUrl' => rtrim((string) Env::get('APP_URL', ''), '/') . '/tickets?pay=return', 'purpose' => 'ticket',
                'title' => $ed['name'] . ' ' . $ed['edition_label'] . ' — tickets', 'description' => "$qty × {$type['name']}",
            ]);
        } catch (GatewayException $e) {
            error_log((string) $e);
            $pdo->prepare("UPDATE payments SET status = 'failed' WHERE reference = ?")->execute([$reference]);
            $pdo->prepare("UPDATE ticket_orders SET status = 'failed' WHERE reference = ?")->execute([$reference]);
            self::fail('The payment service is unavailable right now. You have not been charged. Please try again shortly.', 502);
        }
        Response::json(['ok' => true, 'reference' => $reference, 'amount' => $amount, 'qty' => $qty, 'email' => $email, 'paymentLink' => $link]);
    }

    /** The buyer came back from Flutterwave (or a free order is being collected). Tickets exist only after the provider confirms. */
    public static function confirm(string $reference): never
    {
        self::throttle('ticket-confirm', 240, 15);
        self::record('ticket-confirm', true);
        $st = self::pdo()->prepare('SELECT * FROM ticket_orders WHERE reference = ?');
        $st->execute([$reference]);
        $o = $st->fetch() ?: self::fail('We could not find that order.', 404);

        if ($o['payment_id'] !== null) {
            $p = self::pdo()->prepare('SELECT reference FROM payments WHERE id = ?');
            $p->execute([$o['payment_id']]);
            $result = PaymentService::settle((string) $p->fetchColumn());
            $status = $result['status'];
            $note = $result['note'] ?? null;
            $st->execute([$reference]);
            $o = $st->fetch();
        } else {
            $status = $o['status'] === 'paid' ? 'paid' : 'failed';          // free orders are issued when created
            $note = null;
        }
        $n = self::pdo()->prepare("SELECT COUNT(*) FROM tickets WHERE order_id = ? AND status = 'issued'");
        $n->execute([$o['id']]);
        Response::json(['ok' => true, 'status' => $status, 'note' => $note,
            'order' => ['reference' => $o['reference'], 'qty' => (int) $o['qty'], 'status' => $o['status'], 'tickets' => (int) $n->fetchColumn()]]);
    }

    /** Find tickets by ticket code OR order reference, confirmed with the buyer's email. */
    public static function lookup(): never
    {
        self::throttle('ticket-lookup', 40, 15, true);
        $in = Request::json();
        $ref = strtoupper(Request::str($in, 'ref', 60));
        $email = mb_strtolower(Request::str($in, 'email', 190));
        $st = self::pdo()->prepare(
            "SELECT t.ticket_code, t.holder_name, t.seat_no, t.checked_in_at, t.status, t.edition_id, e.slug AS edition_slug,
                    o.reference AS order_reference, ty.name AS type_name, g.group_code, g.seats, g.table_label
               FROM tickets t
               JOIN ticket_orders o ON o.id = t.order_id
               JOIN ticket_types ty ON ty.id = o.ticket_type_id
               JOIN ticket_groups g ON g.id = t.group_id
               JOIN editions e ON e.id = t.edition_id
              WHERE (t.ticket_code = ? OR o.reference = ?) AND o.buyer_email = ? AND t.status = 'issued'
              ORDER BY t.id"
        );
        $st->execute([$ref, $ref, $email]);
        $rows = $st->fetchAll();
        if (!$rows) {
            self::record('ticket-lookup', false);
            self::fail('No ticket found for those details. Check the reference and email and try again.');
        }
        Response::json(['ok' => true, 'tickets' => array_map(fn ($r) => [
            'code'           => $r['ticket_code'],
            'editionId'      => $r['edition_slug'],
            'orderReference' => $r['order_reference'],
            'typeName'       => $r['type_name'],
            'holder'         => $r['holder_name'],
            'used'           => $r['checked_in_at'] !== null,
            'seat'           => (int) $r['seat_no'],
            'seats'          => (int) $r['seats'],
            'groupCode'      => $r['group_code'],
            'tableLabel'     => $r['table_label'],
        ], $rows)]);
    }
}