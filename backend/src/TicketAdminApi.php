<?php
declare(strict_types=1);

namespace App;

/** Admin side of ticketing: orders and tickets, ticket types, check-in override, table labels, and the gate scanner. */
final class TicketAdminApi
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
        $st = self::pdo()->prepare('SELECT id, slug, name, edition_label FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    // -------------------------------------------------------- orders, tickets

    public static function list(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('tickets.view', $slug);
        $ed = self::edition($slug);
        $money = Access::can($actor, 'finance.view', (int) $ed['id']);
        $pdo = self::pdo();

        $o = $pdo->prepare('SELECT o.*, t.name AS type_name FROM ticket_orders o JOIN ticket_types t ON t.id = o.ticket_type_id WHERE o.edition_id = ? ORDER BY o.id DESC LIMIT 5000');
        $o->execute([$ed['id']]);
        $t = $pdo->prepare(
            "SELECT k.ticket_code, k.holder_name, k.seat_no, k.checked_in_at, k.status, o.reference, ty.name AS type_name,
                    g.group_code, g.seats, g.table_label, p.name AS checked_by
               FROM tickets k JOIN ticket_orders o ON o.id = k.order_id JOIN ticket_types ty ON ty.id = o.ticket_type_id
               JOIN ticket_groups g ON g.id = k.group_id LEFT JOIN people p ON p.id = k.checked_in_by
              WHERE k.edition_id = ? ORDER BY k.id DESC LIMIT 20000"
        );
        $t->execute([$ed['id']]);
        Response::json(['ok' => true,
            'orders' => array_map(fn ($r) => [
                'reference' => $r['reference'], 'name' => $r['buyer_name'], 'email' => $r['buyer_email'], 'phone' => $r['buyer_phone'],
                'typeName' => $r['type_name'], 'qty' => (int) $r['qty'], 'amount' => $money ? (float) $r['amount'] : 0,
                'status' => $r['status'], 'createdAt' => (new \DateTimeImmutable($r['created_at']))->format('c'),
            ], $o->fetchAll()),
            'tickets' => array_map(fn ($r) => [
                'code' => $r['ticket_code'], 'holder' => $r['holder_name'], 'typeName' => $r['type_name'], 'orderReference' => $r['reference'],
                'used' => $r['checked_in_at'] !== null, 'usedAt' => $r['checked_in_at'] ? (new \DateTimeImmutable($r['checked_in_at']))->format('c') : null,
                'usedBy' => $r['checked_by'], 'void' => $r['status'] === 'void',
                'seat' => (int) $r['seat_no'], 'seats' => (int) $r['seats'], 'groupCode' => $r['group_code'], 'tableLabel' => $r['table_label'],
            ], $t->fetchAll()),
        ]);
    }

    /** Manual check-in / undo. Needs tickets.override in the ticket's own edition. */
    public static function override(string $code): never
    {
        $in = Request::json();
        $st = self::pdo()->prepare('SELECT k.*, e.slug AS edition_slug FROM tickets k JOIN editions e ON e.id = k.edition_id WHERE k.ticket_code = ?');
        $st->execute([$code]);
        $k = $st->fetch() ?: self::fail('That ticket was not found.', 404);
        $actor = Access::require('tickets.override', $k['edition_slug']);
        $used = (bool) ($in['used'] ?? false);
        if ($used) {
            self::pdo()->prepare('UPDATE tickets SET checked_in_at = COALESCE(checked_in_at, NOW()), checked_in_by = COALESCE(checked_in_by, ?) WHERE id = ?')->execute([$actor['id'], $k['id']]);
        } else {
            self::pdo()->prepare('UPDATE tickets SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ?')->execute([$k['id']]);
        }
        Audit::log((int) $actor['id'], $used ? 'Manually checked in ticket' : 'Undid ticket check-in', $code, (int) $k['edition_id']);
        Response::json(['ok' => true]);
    }

    /** The table (or seating note) for a whole group, so gate staff know where to direct everyone who came together. */
    public static function setTable(string $groupCode): never
    {
        $in = Request::json();
        $st = self::pdo()->prepare('SELECT g.*, e.slug AS edition_slug FROM ticket_groups g JOIN editions e ON e.id = g.edition_id WHERE g.group_code = ?');
        $st->execute([$groupCode]);
        $g = $st->fetch() ?: self::fail('That group was not found.', 404);
        $actor = Access::require('tickets.manageTypes', $g['edition_slug']);
        $label = Request::str($in, 'tableLabel', 61);
        if (mb_strlen($label) > 60) {
            self::fail('The table name can be at most 60 characters.');
        }
        self::pdo()->prepare('UPDATE ticket_groups SET table_label = ? WHERE id = ?')->execute([$label === '' ? null : $label, $g['id']]);
        Audit::log((int) $actor['id'], 'Set table for ticket group', "$groupCode → " . ($label ?: 'cleared'), (int) $g['edition_id']);
        Response::json(['ok' => true]);
    }

    // -------------------------------------------------------------- types

    public static function types(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('tickets.view', $slug);
        $ed = self::edition($slug);
        $st = self::pdo()->prepare('SELECT * FROM ticket_types WHERE edition_id = ? ORDER BY sort_order, id');
        $st->execute([$ed['id']]);
        Response::json(['ok' => true, 'types' => array_map(fn ($t) => [
            'id' => (string) $t['id'], 'occasionId' => $slug, 'name' => $t['name'], 'description' => $t['description'],
            'price' => (float) $t['price'], 'seats' => (int) $t['seats'], 'maxPerOrder' => (int) $t['max_per_order'],
            'perks' => json_decode((string) ($t['perks'] ?? '[]'), true) ?: [], 'active' => (bool) $t['active'],
        ], $st->fetchAll())]);
    }

    private static function typeFields(array $in): array
    {
        $name = Request::str($in, 'name', 100);
        $desc = Request::str($in, 'description', 500);
        $whole = function (string $key, int $min, int $max, string $label) use ($in): int {
            $v = $in[$key] ?? null;
            if (!is_numeric($v) || (float) $v != (int) $v || (int) $v < $min || (int) $v > $max) {
                self::fail("$label: enter a whole number from $min to $max.");
            }
            return (int) $v;
        };
        if (mb_strlen($name) < 2) {
            self::fail('Give the ticket a name.');
        }
        $price = $whole('price', 0, 10000000, 'Price');
        $seats = $whole('seats', 1, 50, 'Seats per ticket');
        $max = $whole('maxPerOrder', 1, 50, 'Max per order');
        $perks = [];
        foreach (array_slice((array) ($in['perks'] ?? []), 0, 10) as $p) {
            $p = mb_substr(trim((string) $p), 0, 100);
            if ($p !== '') {
                $perks[] = $p;
            }
        }
        return [$name, $desc ?: null, $price, $seats, $max, json_encode($perks, JSON_UNESCAPED_UNICODE), !empty($in['active']) ? 1 : 0];
    }

    public static function createType(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('tickets.manageTypes', $slug);
        $ed = self::edition($slug);
        [$name, $desc, $price, $seats, $max, $perks, $active] = self::typeFields($in);
        self::pdo()->prepare('INSERT INTO ticket_types (edition_id, name, description, price, seats, max_per_order, perks, active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(s.sort_order), 0) + 1 FROM ticket_types s WHERE s.edition_id = ?))')
            ->execute([$ed['id'], $name, $desc, $price, $seats, $max, $perks, $active, $ed['id']]);
        $id = (string) self::pdo()->lastInsertId();
        Cache::flush();
        Audit::log((int) $actor['id'], 'Added ticket type', $name, (int) $ed['id']);
        Response::json(['ok' => true, 'typeId' => $id]);
    }

    private static function ownType(int $id): array
    {
        $st = self::pdo()->prepare('SELECT t.*, e.slug AS edition_slug FROM ticket_types t JOIN editions e ON e.id = t.edition_id WHERE t.id = ?');
        $st->execute([$id]);
        $t = $st->fetch() ?: self::fail('That ticket type was not found.', 404);
        return $t + ['actor' => Access::require('tickets.manageTypes', $t['edition_slug'])];
    }

    public static function updateType(int $id): never
    {
        $t = self::ownType($id);
        [$name, $desc, $price, $seats, $max, $perks, $active] = self::typeFields(Request::json());
        self::pdo()->prepare('UPDATE ticket_types SET name = ?, description = ?, price = ?, seats = ?, max_per_order = ?, perks = ?, active = ? WHERE id = ?')
            ->execute([$name, $desc, $price, $seats, $max, $perks, $active, $id]);
        Cache::flush();                                   // existing orders keep what they were sold; new orders use the new rules
        Audit::log((int) $t['actor']['id'], 'Edited ticket type', $name, (int) $t['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function deleteType(int $id): never
    {
        $t = self::ownType($id);
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM ticket_orders WHERE ticket_type_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            self::fail('Orders already use this ticket type. Turn “On sale” off to stop selling it; the sold tickets stay valid.');
        }
        self::pdo()->prepare('DELETE FROM ticket_types WHERE id = ?')->execute([$id]);
        Cache::flush();
        Audit::log((int) $t['actor']['id'], 'Deleted ticket type', $t['name'], (int) $t['edition_id']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------ scanner

    private static function groupInfo(int $groupId): array
    {
        $st = self::pdo()->prepare('SELECT seat_no, holder_name, checked_in_at FROM tickets WHERE group_id = ? ORDER BY seat_no');
        $st->execute([$groupId]);
        return array_map(fn ($r) => ['seat' => (int) $r['seat_no'], 'holder' => $r['holder_name'], 'checkedIn' => $r['checked_in_at'] !== null], $st->fetchAll());
    }

    /** One scan. The check-in is a single UPDATE that only succeeds for an unused ticket, so two phones scanning at once cannot both win. */
    public static function scan(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('scanner.use', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $code = strtoupper(trim(Request::str($in, 'code', 100)));

        $log = function (string $result, ?int $ticketId) use ($pdo, $ed, $code, $actor) {
            $pdo->prepare('INSERT INTO ticket_scans (edition_id, code_scanned, ticket_id, result, scanned_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$ed['id'], mb_substr($code, 0, 60), $ticketId, $result, $actor['id']]);
        };
        $answer = function (string $result, ?array $k, string $detail = '') use ($log): never {
            $log($result === 'bad' ? 'invalid' : $result, $k ? (int) $k['id'] : null);
            $out = ['ok' => true, 'result' => $result, 'detail' => $detail];
            if ($k) {
                $out['ticket'] = ['code' => $k['ticket_code'], 'holder' => $k['holder_name'], 'typeName' => $k['type_name'], 'seat' => (int) $k['seat_no'],
                    'seats' => (int) $k['seats'], 'groupCode' => $k['group_code'], 'tableLabel' => $k['table_label'],
                    'usedAt' => $k['checked_in_at'] ? (new \DateTimeImmutable($k['checked_in_at']))->format('c') : null, 'usedBy' => $k['checked_by'] ?? null];
                $out['group'] = self::groupInfo((int) $k['group_id']);
            }
            Response::json($out);
        };

        if (!preg_match('/^TKT-[A-F0-9]{12}$/', $code)) {
            $answer('bad', null, 'That is not a valid ticket code.');
        }
        $flip = $pdo->prepare("UPDATE tickets SET checked_in_at = NOW(), checked_in_by = ? WHERE ticket_code = ? AND edition_id = ? AND status = 'issued' AND checked_in_at IS NULL");
        $flip->execute([$actor['id'], $code, $ed['id']]);
        $won = $flip->rowCount() === 1;

        $st = $pdo->prepare(
            'SELECT k.*, ty.name AS type_name, g.group_code, g.seats, g.table_label, p.name AS checked_by
               FROM tickets k JOIN ticket_orders o ON o.id = k.order_id JOIN ticket_types ty ON ty.id = o.ticket_type_id
               JOIN ticket_groups g ON g.id = k.group_id LEFT JOIN people p ON p.id = k.checked_in_by
              WHERE k.ticket_code = ?'
        );
        $st->execute([$code]);
        $k = $st->fetch();
        if (!$k) {
            $answer('bad', null, "No ticket matches “$code”.");
        }
        if ((int) $k['edition_id'] !== (int) $ed['id']) {
            $answer('wrong_edition', $k, "This ticket is for a different edition, not {$ed['name']} {$ed['edition_label']}.");
        }
        if ($won) {
            Audit::log((int) $actor['id'], 'Checked in ticket', $code, (int) $ed['id']);
            $answer('valid', $k, "{$k['holder_name']} · {$k['type_name']}");
        }
        if ($k['status'] === 'void') {
            $answer('void', $k, 'This ticket has been cancelled.');
        }
        $answer('used', $k, 'Already checked in.');
    }

    public static function scanSummary(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('scanner.use', $slug);
        $ed = self::edition($slug);
        $pdo = self::pdo();
        $c = $pdo->prepare("SELECT COUNT(*) AS issued, COALESCE(SUM(checked_in_at IS NOT NULL), 0) AS inside FROM tickets WHERE edition_id = ? AND status = 'issued'");
        $c->execute([$ed['id']]);
        $counts = $c->fetch();
        $r = $pdo->prepare('SELECT s.code_scanned, s.result, s.scanned_at, k.holder_name FROM ticket_scans s LEFT JOIN tickets k ON k.id = s.ticket_id WHERE s.edition_id = ? ORDER BY s.id DESC LIMIT 8');
        $r->execute([$ed['id']]);
        Response::json(['ok' => true, 'issued' => (int) $counts['issued'], 'checkedIn' => (int) $counts['inside'], 'recent' => array_map(fn ($x) => [
            'code' => $x['code_scanned'], 'holder' => $x['holder_name'], 'result' => $x['result'] === 'valid' ? 'valid' : ($x['result'] === 'used' ? 'used' : 'bad'),
            'at' => (new \DateTimeImmutable($x['scanned_at']))->format('c'),
        ], $r->fetchAll())]);
    }
}