<?php
declare(strict_types=1);

namespace App;

/**
 * Ticket distribution. Three ways tickets reach people other than through the public shop:
 *  - a SELLER (a committee member with the "Ticket seller" role) takes tickets from an allocation an admin set for them:
 *      pay_now   -> they pay the admin's price online, then the tickets are issued
 *      owe_later -> the tickets are issued at once and the amount they owe is tracked until they hand the cash in
 *      free      -> the tickets are issued at once and nothing is owed
 *  - a BULK batch: an authorised admin issues free tickets (sponsors, guests, press) with a label.
 *  - REMITTANCES: cash a seller hands in against what they owe.
 * Prices come from the allocation, never from the browser. Quotas are counted in units (a table of 4 is one unit).
 */
final class TicketSalesApi
{
    private const MAX_UNITS_PER_BATCH = 1000;    // a safety valve for one request; the real limits are the quotas an admin sets
    private const PENDING_HOLD_HOURS = 2;        // an unpaid pay_now order holds its quota this long

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
        $st = self::pdo()->prepare('SELECT e.id, e.slug, e.name, e.edition_label, s.currency FROM editions e LEFT JOIN edition_settings s ON s.edition_id = e.id WHERE e.slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    /** The first of these permissions the signed-in person holds in the edition; otherwise 401/403. */
    private static function either(array $perms, string $slug): array
    {
        $person = Auth::person();
        if ($person === null) {
            Response::error('Please sign in.', 401);
        }
        $st = self::pdo()->prepare('SELECT id FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        $edId = $st->fetchColumn();
        if ($edId !== false) {
            foreach ($perms as $p) {
                if (Access::can($person, $p, (int) $edId)) {
                    return $person + ['edition_id' => (int) $edId, 'via' => $p];
                }
            }
        }
        Response::error('You do not have permission to do that.', 403);
    }

    private static function money(mixed $v, string $label, bool $allowZero = true): float
    {
        if (!is_numeric($v) || (float) $v < ($allowZero ? 0 : 0.01) || (float) $v > 100000000) {
            self::fail("$label: enter an amount" . ($allowZero ? '' : ' above zero') . '.');
        }
        return round((float) $v, 2);
    }

    private static function whole(mixed $v, int $min, int $max, string $label): int
    {
        if (!is_numeric($v) || (float) $v != (int) $v || (int) $v < $min || (int) $v > $max) {
            self::fail("$label: enter a whole number from $min to $max.");
        }
        return (int) $v;
    }

    // ------------------------------------------------------------------ numbers

    /** Units a person has taken of one ticket type: live units of issued orders, plus recent unpaid pay_now orders. */
    private static function taken(int $personId, int $typeId): int
    {
        $st = self::pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN o.status = 'pending' THEN o.qty
                    ELSE (SELECT COUNT(DISTINCT g.id) FROM ticket_groups g JOIN tickets k ON k.group_id = g.id AND k.status = 'issued' WHERE g.order_id = o.id) END), 0)
               FROM ticket_orders o
              WHERE o.issued_by = ? AND o.ticket_type_id = ? AND o.channel = 'seller'
                AND (o.status = 'paid' OR (o.status = 'pending' AND o.created_at > (NOW() - INTERVAL " . self::PENDING_HOLD_HOURS . ' HOUR)))'
        );
        $st->execute([$personId, $typeId]);
        return (int) $st->fetchColumn();
    }

    /** Per seller: units taken, what they owe (pay later), paid up front, handed in, and the balance still to collect. */
    public static function ledger(int $editionId, ?int $personId = null): array
    {
        $pdo = self::pdo();
        $sql = "SELECT o.issued_by AS pid,
                       COALESCE(SUM(u.units), 0) AS units,
                       COALESCE(SUM(CASE WHEN o.settlement = 'free' THEN u.units ELSE 0 END), 0) AS free_units,
                       COALESCE(SUM(CASE WHEN o.settlement = 'owe_later' THEN o.unit_price * u.units ELSE 0 END), 0) AS owed,
                       COALESCE(SUM(CASE WHEN o.settlement = 'paid' THEN o.amount ELSE 0 END), 0) AS paid_online
                  FROM ticket_orders o
                  JOIN (SELECT g.order_id, COUNT(DISTINCT g.id) AS units FROM ticket_groups g JOIN tickets k ON k.group_id = g.id AND k.status = 'issued' GROUP BY g.order_id) u ON u.order_id = o.id
                 WHERE o.edition_id = ? AND o.channel = 'seller' AND o.status = 'paid'" . ($personId !== null ? ' AND o.issued_by = ?' : '') . ' GROUP BY o.issued_by';
        $st = $pdo->prepare($sql);
        $st->execute($personId !== null ? [$editionId, $personId] : [$editionId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['pid']] = ['personId' => (int) $r['pid'], 'units' => (int) $r['units'], 'freeUnits' => (int) $r['free_units'], 'owed' => (float) $r['owed'], 'paidOnline' => (float) $r['paid_online'], 'remitted' => 0.0];
        }
        $rs = $pdo->prepare('SELECT person_id, SUM(amount) FROM ticket_remittances WHERE edition_id = ?' . ($personId !== null ? ' AND person_id = ?' : '') . ' GROUP BY person_id');
        $rs->execute($personId !== null ? [$editionId, $personId] : [$editionId]);
        foreach ($rs->fetchAll(\PDO::FETCH_NUM) as [$pid, $sum]) {
            $out[(int) $pid] ??= ['personId' => (int) $pid, 'units' => 0, 'freeUnits' => 0, 'owed' => 0.0, 'paidOnline' => 0.0, 'remitted' => 0.0];
            $out[(int) $pid]['remitted'] = (float) $sum;
        }
        foreach ($out as &$l) {
            $l['balance'] = round($l['owed'] - $l['remitted'], 2);          // above zero: still to collect. Below zero: handed in more than owed.
        }
        return $out;
    }

    private static function emptyLedger(int $pid): array
    {
        return ['personId' => $pid, 'units' => 0, 'freeUnits' => 0, 'owed' => 0.0, 'paidOnline' => 0.0, 'remitted' => 0.0, 'balance' => 0.0];
    }

    // ------------------------------------------------------------------ order payload

    private static function order(string $reference, int $editionId): array
    {
        $st = self::pdo()->prepare(
            'SELECT o.*, ty.name AS type_name, ty.look AS type_look, ty.seats AS type_seats, p.name AS issuer_name
               FROM ticket_orders o JOIN ticket_types ty ON ty.id = o.ticket_type_id LEFT JOIN people p ON p.id = o.issued_by
              WHERE o.reference = ? AND o.edition_id = ? AND o.channel <> \'online\''
        );
        $st->execute([$reference, $editionId]);
        return $st->fetch() ?: self::fail('We could not find that batch.', 404);
    }

    private static function orderShape(array $o, bool $withTickets): array
    {
        $out = [
            'reference' => $o['reference'], 'typeName' => $o['type_name'], 'look' => TicketLook::normalise($o['type_look'] ?? null, (string) $o['type_name']),
            'seats' => (int) $o['type_seats'], 'qty' => (int) $o['qty'], 'status' => $o['status'], 'channel' => $o['channel'], 'settlement' => $o['settlement'],
            'unitPrice' => $o['unit_price'] === null ? null : (float) $o['unit_price'], 'amount' => (float) $o['amount'], 'label' => $o['batch_label'],
            'issuedBy' => $o['issuer_name'], 'createdAt' => (new \DateTimeImmutable($o['created_at']))->format('c'),
        ];
        if ($withTickets) {
            $st = self::pdo()->prepare(
                'SELECT k.ticket_code, k.seat_no, k.holder_name, k.status, k.checked_in_at, g.group_code, g.seats, g.table_label
                   FROM tickets k JOIN ticket_groups g ON g.id = k.group_id WHERE k.order_id = ? ORDER BY g.id, k.seat_no'
            );
            $st->execute([$o['id']]);
            $out['tickets'] = array_map(fn ($k) => [
                'code' => $k['ticket_code'], 'seat' => (int) $k['seat_no'], 'seats' => (int) $k['seats'], 'groupCode' => $k['group_code'], 'tableLabel' => $k['table_label'],
                'holder' => $k['holder_name'], 'used' => $k['checked_in_at'] !== null, 'void' => $k['status'] === 'void',
            ], $st->fetchAll());
        }
        return $out;
    }

    private static function eventShape(array $ed): array
    {
        return ['name' => $ed['name'], 'edition' => $ed['edition_label']];
    }

    // ------------------------------------------------------------------ a seller's own page

    public static function me(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('tickets.distribute', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $st = $pdo->prepare(
            'SELECT a.*, t.name AS type_name, t.price AS face, t.seats, t.look FROM ticket_allocations a JOIN ticket_types t ON t.id = a.ticket_type_id
              WHERE a.person_id = ? AND a.edition_id = ? AND a.active = 1 ORDER BY t.sort_order, t.id'
        );
        $st->execute([$actor['id'], $ed['id']]);
        $allocs = array_map(function ($a) use ($actor) {
            $taken = self::taken((int) $actor['id'], (int) $a['ticket_type_id']);
            return [
                'id' => (string) $a['id'], 'typeId' => (string) $a['ticket_type_id'], 'typeName' => $a['type_name'], 'faceValue' => (float) $a['face'], 'seats' => (int) $a['seats'],
                'look' => TicketLook::normalise($a['look'] ?? null, (string) $a['type_name']),
                'settlement' => $a['settlement'], 'unitPrice' => (float) $a['unit_price'], 'quota' => (int) $a['quota'], 'taken' => $taken, 'remaining' => max(0, (int) $a['quota'] - $taken),
            ];
        }, $st->fetchAll());
        $os = $pdo->prepare("SELECT o.*, ty.name AS type_name, ty.look AS type_look, ty.seats AS type_seats, p.name AS issuer_name FROM ticket_orders o JOIN ticket_types ty ON ty.id = o.ticket_type_id LEFT JOIN people p ON p.id = o.issued_by WHERE o.edition_id = ? AND o.issued_by = ? AND o.channel = 'seller' AND o.status IN ('paid','pending') ORDER BY o.id DESC LIMIT 30");
        $os->execute([$ed['id'], $actor['id']]);
        Response::json([
            'ok' => true, 'event' => self::eventShape($ed), 'person' => ['id' => (int) $actor['id'], 'name' => $actor['name']], 'allocations' => $allocs,
            'ledger' => self::ledger((int) $ed['id'], (int) $actor['id'])[(int) $actor['id']] ?? self::emptyLedger((int) $actor['id']),
            'orders' => array_map(fn ($o) => self::orderShape($o, true), $os->fetchAll()),
        ]);
    }

    /** Take tickets from your allocation. pay_now answers with a payment link; the others issue straight away. */
    public static function generate(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('tickets.distribute', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $typeId = self::whole($in['typeId'] ?? null, 1, PHP_INT_MAX, 'Ticket type');
        $qty = self::whole($in['qty'] ?? null, 1, self::MAX_UNITS_PER_BATCH, 'Quantity');
        $label = Request::str($in, 'label', 100);

        $st = $pdo->prepare('SELECT a.*, t.name AS type_name FROM ticket_allocations a JOIN ticket_types t ON t.id = a.ticket_type_id WHERE a.person_id = ? AND a.ticket_type_id = ? AND a.edition_id = ? AND a.active = 1');
        $st->execute([$actor['id'], $typeId, $ed['id']]);
        $a = $st->fetch() ?: self::fail('You have no allocation of that ticket. Ask the organisers to set one for you.', 403);
        $remaining = (int) $a['quota'] - self::taken((int) $actor['id'], $typeId);
        if ($qty > $remaining) {
            self::fail($remaining <= 0 ? 'You have used your whole allocation of this ticket.' : "You can take $remaining more of this ticket.");
        }
        $unit = $a['settlement'] === 'free' ? 0.0 : (float) $a['unit_price'];
        $total = round($qty * $unit, 2);
        $reference = 'TIK-' . strtoupper(bin2hex(random_bytes(6)));
        $settlement = $a['settlement'] === 'pay_now' ? 'paid' : $a['settlement'];
        $insert = "INSERT INTO ticket_orders (edition_id, reference, ticket_type_id, qty, amount, buyer_name, buyer_email, buyer_phone, status, payment_id, channel, settlement, unit_price, issued_by, batch_label)
                   VALUES (?, ?, ?, ?, ?, ?, ?, '', 'pending', ?, 'seller', ?, ?, ?, ?)";

        if ($a['settlement'] !== 'pay_now' || $total <= 0) {                  // issued now: free, owe later, or a pay-now ticket priced at nothing
            Database::transaction(function (\PDO $db) use ($insert, $ed, $reference, $typeId, $qty, $total, $actor, $settlement, $unit, $label, $a) {
                $db->prepare($insert)->execute([$ed['id'], $reference, $typeId, $qty, $total, $actor['name'], $actor['email'], null, $a['settlement'] === 'pay_now' ? 'free' : $settlement, $unit, $actor['id'], $label ?: null]);
                Tickets::issue($db, (int) $db->lastInsertId());
            });
            Audit::log((int) $actor['id'], 'Took tickets from allocation', "$qty × {$a['type_name']} ($reference)", (int) $ed['id']);
            Response::json(['ok' => true, 'reference' => $reference, 'qty' => $qty, 'settlement' => $a['settlement'], 'amountOwed' => $a['settlement'] === 'owe_later' ? $total : 0.0]);
        }

        $currency = $ed['currency'] ?: 'NGN';
        Database::transaction(function (\PDO $db) use ($insert, $ed, $reference, $typeId, $qty, $total, $actor, $unit, $label, $currency) {
            $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (?, 'ticket', ?, ?, ?, ?, 'pending')")
                ->execute([$ed['id'], $reference, $total, $currency, $actor['email']]);
            $paymentId = (int) $db->lastInsertId();
            $db->prepare($insert)->execute([$ed['id'], $reference, $typeId, $qty, $total, $actor['name'], $actor['email'], $paymentId, 'paid', $unit, $actor['id'], $label ?: null]);
        });
        try {
            $link = Flutterwave::createPayment([
                'reference' => $reference, 'amount' => $total, 'currency' => $currency, 'email' => $actor['email'], 'name' => $actor['name'],
                'redirectUrl' => rtrim((string) Env::get('APP_URL', ''), '/') . '/admin/sales?pay=return', 'purpose' => 'ticket',
                'title' => $ed['name'] . ' ' . $ed['edition_label'] . ' — seller tickets', 'description' => "$qty × {$a['type_name']}",
            ]);
        } catch (GatewayException $e) {
            error_log((string) $e);
            $pdo->prepare("UPDATE payments SET status = 'failed' WHERE reference = ?")->execute([$reference]);
            $pdo->prepare("UPDATE ticket_orders SET status = 'failed' WHERE reference = ?")->execute([$reference]);
            self::fail('The payment service is unavailable right now. You have not been charged. Please try again shortly.', 502);
        }
        Response::json(['ok' => true, 'reference' => $reference, 'qty' => $qty, 'settlement' => 'pay_now', 'amount' => $total, 'paymentLink' => $link]);
    }

    /** The seller came back from paying. The tickets exist only after the provider confirms. */
    public static function confirm(string $reference): never
    {
        $slug = (string) ($_GET['edition'] ?? Request::json()['edition'] ?? '');
        $actor = Access::require('tickets.distribute', $slug);
        $ed = self::edition($slug);
        $o = self::order($reference, (int) $ed['id']);
        if ((int) $o['issued_by'] !== (int) $actor['id']) {
            self::fail('That payment belongs to someone else.', 403);
        }
        $status = $o['status'];
        if ($o['payment_id'] !== null && $o['status'] === 'pending') {
            $p = self::pdo()->prepare('SELECT reference FROM payments WHERE id = ?');
            $p->execute([$o['payment_id']]);
            $status = PaymentService::settle((string) $p->fetchColumn())['status'];
            $o = self::order($reference, (int) $ed['id']);
        }
        Response::json(['ok' => true, 'status' => $status, 'order' => self::orderShape($o, true)]);
    }

    // ------------------------------------------------------------------ viewing one batch, renaming, returning

    public static function showOrder(string $reference): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        $actor = self::either(['tickets.distribute', 'tickets.allocations', 'tickets.bulk'], $slug);
        $ed = self::edition($slug);
        $o = self::order($reference, (int) $ed['id']);
        if ((int) $o['issued_by'] !== (int) $actor['id'] && !Access::can($actor, 'tickets.allocations', (int) $ed['id']) && !Access::can($actor, 'tickets.bulk', (int) $ed['id'])) {
            self::fail('That batch belongs to someone else.', 403);
        }
        Response::json(['ok' => true, 'event' => self::eventShape($ed), 'order' => self::orderShape($o, true)]);
    }

    private static function ticketOwner(string $code, array $actor, int $editionId): array
    {
        $st = self::pdo()->prepare('SELECT k.*, o.issued_by, o.channel FROM tickets k JOIN ticket_orders o ON o.id = k.order_id WHERE k.ticket_code = ? AND k.edition_id = ? AND o.channel <> \'online\'');
        $st->execute([$code, $editionId]);
        $k = $st->fetch() ?: self::fail('We could not find that ticket.', 404);
        $manager = Access::can($actor, 'tickets.allocations', $editionId) || Access::can($actor, 'tickets.bulk', $editionId);
        if ((int) $k['issued_by'] !== (int) $actor['id'] && !$manager) {
            self::fail('That ticket belongs to someone else.', 403);
        }
        return $k;
    }

    /** Put a name on one seat of a batch (so the ticket image carries the attendee's name). */
    public static function setHolder(string $code): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = self::either(['tickets.distribute', 'tickets.allocations', 'tickets.bulk'], $slug);
        $k = self::ticketOwner(strtoupper($code), $actor, $actor['edition_id']);
        $name = Request::str($in, 'name', 80);
        if (mb_strlen($name) < 2) {
            self::fail('Enter the name of the person who will use this ticket.');
        }
        if ($k['checked_in_at'] !== null || $k['status'] !== 'issued') {
            self::fail('This ticket has already been used or cancelled.');
        }
        self::pdo()->prepare('UPDATE tickets SET holder_name = ? WHERE id = ?')->execute([$name, $k['id']]);
        Response::json(['ok' => true, 'holder' => $name]);
    }

    /** Hand back a ticket (or a whole table) nobody took. Pay-later and free batches only; a used ticket cannot be returned. */
    public static function returnUnit(string $groupCode): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = self::either(['tickets.distribute', 'tickets.allocations', 'tickets.bulk'], $slug);
        $edId = $actor['edition_id'];
        $st = self::pdo()->prepare("SELECT g.id, g.group_code, o.issued_by, o.channel, o.settlement, o.reference FROM ticket_groups g JOIN ticket_orders o ON o.id = g.order_id WHERE g.group_code = ? AND g.edition_id = ? AND o.channel <> 'online'");
        $st->execute([strtoupper($groupCode), $edId]);
        $g = $st->fetch() ?: self::fail('We could not find that ticket.', 404);
        $manager = Access::can($actor, 'tickets.allocations', $edId) || ($g['channel'] === 'bulk' && Access::can($actor, 'tickets.bulk', $edId));
        if ((int) $g['issued_by'] !== (int) $actor['id'] && !$manager) {
            self::fail('That ticket belongs to someone else.', 403);
        }
        if ($g['settlement'] === 'paid') {
            self::fail('Tickets paid for online cannot be returned here. Ask a Super admin to cancel them.');
        }
        $used = self::pdo()->prepare('SELECT COUNT(*) FROM tickets WHERE group_id = ? AND checked_in_at IS NOT NULL');
        $used->execute([$g['id']]);
        if ((int) $used->fetchColumn() > 0) {
            self::fail('Someone has already used this ticket, so it cannot be returned.');
        }
        $v = self::pdo()->prepare("UPDATE tickets SET status = 'void' WHERE group_id = ? AND status = 'issued'");
        $v->execute([$g['id']]);
        if ($v->rowCount() === 0) {
            self::fail('That ticket was already returned.');
        }
        Audit::log((int) $actor['id'], 'Returned unsold tickets', $g['group_code'] . ' (' . $g['reference'] . ')', $edId);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ managers: allocations, ledger, remittances

    public static function overview(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        $actor = self::either(['tickets.allocations', 'tickets.bulk'], $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $manage = Access::can($actor, 'tickets.allocations', (int) $ed['id']);
        $out = ['ok' => true, 'canManage' => $manage, 'canBulk' => Access::can($actor, 'tickets.bulk', (int) $ed['id']), 'event' => self::eventShape($ed)];

        $t = $pdo->prepare("SELECT id, name, price, seats, look FROM ticket_types WHERE edition_id = ? AND kind = 'public' ORDER BY sort_order, id");
        $t->execute([$ed['id']]);
        $out['types'] = array_map(fn ($r) => ['id' => (string) $r['id'], 'name' => $r['name'], 'price' => (float) $r['price'], 'seats' => (int) $r['seats'], 'look' => TicketLook::normalise($r['look'] ?? null, (string) $r['name'])], $t->fetchAll());

        $b = $pdo->prepare(
            "SELECT o.*, ty.name AS type_name, ty.look AS type_look, ty.seats AS type_seats, p.name AS issuer_name
               FROM ticket_orders o JOIN ticket_types ty ON ty.id = o.ticket_type_id LEFT JOIN people p ON p.id = o.issued_by
              WHERE o.edition_id = ? AND o.channel IN ('seller','bulk') AND o.status IN ('paid','pending') ORDER BY o.id DESC LIMIT 200"
        );
        $b->execute([$ed['id']]);
        $out['batches'] = array_map(fn ($o) => self::orderShape($o, false), $b->fetchAll());
        if (!$manage) {
            Response::json($out);
        }

        $al = $pdo->prepare(
            'SELECT a.*, p.name AS person_name, p.email AS person_email, t.name AS type_name, t.price AS face FROM ticket_allocations a
               JOIN people p ON p.id = a.person_id JOIN ticket_types t ON t.id = a.ticket_type_id WHERE a.edition_id = ? ORDER BY p.name, t.sort_order, t.id'
        );
        $al->execute([$ed['id']]);
        $out['allocations'] = array_map(function ($a) {
            $taken = self::taken((int) $a['person_id'], (int) $a['ticket_type_id']);
            return ['id' => (string) $a['id'], 'personId' => (string) $a['person_id'], 'personName' => $a['person_name'], 'personEmail' => $a['person_email'],
                'typeId' => (string) $a['ticket_type_id'], 'typeName' => $a['type_name'], 'faceValue' => (float) $a['face'], 'settlement' => $a['settlement'],
                'unitPrice' => (float) $a['unit_price'], 'quota' => (int) $a['quota'], 'taken' => $taken, 'remaining' => max(0, (int) $a['quota'] - $taken), 'active' => (bool) $a['active']];
        }, $al->fetchAll());
        $pp = $pdo->query("SELECT id, name, email FROM people WHERE active = 1 AND email <> '' ORDER BY name")->fetchAll();
        $out['people'] = array_map(fn ($p) => ['id' => (string) $p['id'], 'name' => $p['name'], 'email' => $p['email']], $pp);
        $names = array_column($pp, 'name', 'id');
        $out['ledger'] = array_values(array_map(fn ($l) => $l + ['name' => $names[$l['personId']] ?? '—'], self::ledger((int) $ed['id'])));
        $rm = $pdo->prepare('SELECT r.*, p.name AS person_name, b.name AS by_name FROM ticket_remittances r JOIN people p ON p.id = r.person_id LEFT JOIN people b ON b.id = r.recorded_by WHERE r.edition_id = ? ORDER BY r.id DESC LIMIT 200');
        $rm->execute([$ed['id']]);
        $out['remittances'] = array_map(fn ($r) => ['id' => (string) $r['id'], 'personId' => (string) $r['person_id'], 'personName' => $r['person_name'], 'amount' => (float) $r['amount'],
            'method' => $r['method'], 'note' => $r['note'], 'recordedBy' => $r['by_name'], 'createdAt' => (new \DateTimeImmutable($r['created_at']))->format('c')], $rm->fetchAll());
        Response::json($out);
    }

    public static function saveAllocation(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('tickets.allocations', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $personId = self::whole($in['personId'] ?? null, 1, PHP_INT_MAX, 'Person');
        $typeId = self::whole($in['typeId'] ?? null, 1, PHP_INT_MAX, 'Ticket type');
        $settlement = (string) ($in['settlement'] ?? '');
        if (!in_array($settlement, ['pay_now', 'owe_later', 'free'], true)) {
            self::fail('Choose how this seller settles: pay now, pay later, or free.');
        }
        $unit = $settlement === 'free' ? 0.0 : self::money($in['unitPrice'] ?? null, 'Price per ticket');
        $quota = self::whole($in['quota'] ?? null, 1, 100000, 'How many they may take');
        $p = $pdo->prepare('SELECT name, email, active FROM people WHERE id = ?');
        $p->execute([$personId]);
        $person = $p->fetch();
        if (!$person || !(int) $person['active']) {
            self::fail('Choose an active person.');
        }
        if ($settlement === 'pay_now' && $unit > 0 && $person['email'] === '') {
            self::fail('A seller who pays online needs an email address on their account.');
        }
        $t = $pdo->prepare("SELECT name FROM ticket_types WHERE id = ? AND edition_id = ? AND kind = 'public'");
        $t->execute([$typeId, $ed['id']]);
        $typeName = $t->fetchColumn();
        if ($typeName === false) {
            self::fail('Choose one of this edition\'s ticket types.');
        }
        $taken = self::taken($personId, $typeId);
        if ($quota < $taken) {
            self::fail("They have already taken $taken. Set at least $taken, or switch the allocation off.");
        }
        $pdo->prepare(
            'INSERT INTO ticket_allocations (edition_id, person_id, ticket_type_id, settlement, unit_price, quota, active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE settlement = VALUES(settlement), unit_price = VALUES(unit_price), quota = VALUES(quota), active = VALUES(active)'
        )->execute([$ed['id'], $personId, $typeId, $settlement, $unit, $quota, !array_key_exists('active', $in) || !empty($in['active']) ? 1 : 0, $actor['id']]);
        Audit::log((int) $actor['id'], 'Set ticket allocation', "{$person['name']}: $quota × $typeName ($settlement, ₦" . number_format($unit) . ')', (int) $ed['id']);
        Response::json(['ok' => true]);
    }

    public static function deleteAllocation(int $id): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60) ?: (string) ($_GET['edition'] ?? '');
        $actor = Access::require('tickets.allocations', $slug);
        $st = self::pdo()->prepare('SELECT * FROM ticket_allocations WHERE id = ? AND edition_id = ?');
        $st->execute([$id, $actor['edition_id']]);
        $a = $st->fetch() ?: self::fail('That allocation was not found.', 404);
        $used = self::pdo()->prepare("SELECT COUNT(*) FROM ticket_orders WHERE issued_by = ? AND ticket_type_id = ? AND channel = 'seller'");
        $used->execute([$a['person_id'], $a['ticket_type_id']]);
        if ((int) $used->fetchColumn() > 0) {
            self::fail('They have already taken tickets from this. Switch it off instead; the history stays.');
        }
        self::pdo()->prepare('DELETE FROM ticket_allocations WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'Removed ticket allocation', '#' . $id, (int) $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function addRemittance(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('tickets.allocations', $slug);
        $ed = self::edition($slug);
        $personId = self::whole($in['personId'] ?? null, 1, PHP_INT_MAX, 'Person');
        $amount = self::money($in['amount'] ?? null, 'Amount received', false);
        $method = (string) ($in['method'] ?? 'cash');
        if (!in_array($method, ['cash', 'transfer', 'other'], true)) {
            self::fail('Choose cash, transfer or other.');
        }
        $p = self::pdo()->prepare('SELECT name FROM people WHERE id = ?');
        $p->execute([$personId]);
        $name = $p->fetchColumn() ?: self::fail('Choose a person.');
        self::pdo()->prepare('INSERT INTO ticket_remittances (edition_id, person_id, amount, method, note, recorded_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$ed['id'], $personId, $amount, $method, Request::str($in, 'note', 255) ?: null, $actor['id']]);
        Audit::log((int) $actor['id'], 'Recorded ticket money received', "$name: ₦" . number_format($amount) . " ($method)", (int) $ed['id']);
        Response::json(['ok' => true, 'ledger' => self::ledger((int) $ed['id'], $personId)[$personId] ?? self::emptyLedger($personId)]);
    }

    public static function deleteRemittance(int $id): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60) ?: (string) ($_GET['edition'] ?? '');
        $actor = Access::require('tickets.allocations', $slug);
        $st = self::pdo()->prepare('SELECT r.amount, p.name FROM ticket_remittances r JOIN people p ON p.id = r.person_id WHERE r.id = ? AND r.edition_id = ?');
        $st->execute([$id, $actor['edition_id']]);
        $r = $st->fetch() ?: self::fail('That entry was not found.', 404);
        self::pdo()->prepare('DELETE FROM ticket_remittances WHERE id = ?')->execute([$id]);
        Audit::log((int) $actor['id'], 'Removed a ticket money entry', "{$r['name']}: ₦" . number_format((float) $r['amount']), (int) $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ free bulk batches

    public static function bulk(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('tickets.bulk', $slug);
        $ed = self::edition($slug);
        $typeId = self::whole($in['typeId'] ?? null, 1, PHP_INT_MAX, 'Ticket type');
        $qty = self::whole($in['qty'] ?? null, 1, self::MAX_UNITS_PER_BATCH, 'Quantity');
        $label = Request::str($in, 'label', 100);
        if (mb_strlen($label) < 2) {
            self::fail('Give the batch a label, for example “Sponsors” or “Press”, so you can find it later.');
        }
        $t = self::pdo()->prepare("SELECT name FROM ticket_types WHERE id = ? AND edition_id = ? AND kind = 'public'");
        $t->execute([$typeId, $ed['id']]);
        $typeName = $t->fetchColumn() ?: self::fail('Choose one of this edition\'s ticket types.');
        $reference = 'TIK-' . strtoupper(bin2hex(random_bytes(6)));
        Database::transaction(function (\PDO $db) use ($ed, $reference, $typeId, $qty, $label, $actor) {
            $db->prepare("INSERT INTO ticket_orders (edition_id, reference, ticket_type_id, qty, amount, buyer_name, buyer_email, buyer_phone, status, channel, settlement, unit_price, issued_by, batch_label)
                          VALUES (?, ?, ?, ?, 0, ?, '', '', 'pending', 'bulk', 'free', 0, ?, ?)")
                ->execute([$ed['id'], $reference, $typeId, $qty, $label, $actor['id'], $label]);
            Tickets::issue($db, (int) $db->lastInsertId());
        });
        Audit::log((int) $actor['id'], 'Generated bulk tickets', "$qty × $typeName: $label ($reference)", (int) $ed['id']);
        Response::json(['ok' => true, 'reference' => $reference, 'qty' => $qty]);
    }
}