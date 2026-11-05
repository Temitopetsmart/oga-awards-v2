<?php
declare(strict_types=1);

namespace App;

/** Public voting: start an order, confirm it after paying, and the Flutterwave webhook. Prices and limits are decided HERE. */
final class VoteApi
{
    private const PENDING_HOLD_MINUTES = 30;          // an unpaid order holds a "payment slot" for this long

    private static function fail(string $message, int $status = 422, array $extra = []): never
    {
        Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    /** Stops with 429 after too many requests from one address (generous: a campus shares one). */
    private static function throttle(string $kind, int $max, int $minutes): void
    {
        $id = hash('sha256', "$kind:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many requests. Please wait a little and try again.', 429);
        }
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, 1)')->execute([$id, Request::ip()]);
    }

    private static function appUrl(): string
    {
        $u = (string) Env::get('APP_URL', '');
        if ($u === '') {
            throw new \RuntimeException('APP_URL is not set in config.php (the address of your site, e.g. http://localhost/oga-awards-v2/)');
        }
        return rtrim($u, '/') . '/';
    }

    // ------------------------------------------------------------ create

    public static function createOrder(): never
    {
        self::throttle('vote-order', 120, 60);
        $in = Request::json();
        $pdo = self::pdo();

        $name  = Request::str($in, 'name', 100);
        $email = mb_strtolower(Request::str($in, 'email', 190));
        $votes = $in['votes'] ?? null;
        if (mb_strlen($name) < 2) {
            self::fail('Enter your name.', 422, ['fields' => ['name' => 'Enter your name.']]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Enter a valid email address.', 422, ['fields' => ['email' => 'Enter a valid email address.']]);
        }

        $ed = $pdo->query("SELECT e.*, s.price_per_vote, s.max_votes_per_payment, s.max_paid_payments_per_email_per_category, s.currency
                             FROM editions e LEFT JOIN edition_settings s ON s.edition_id = e.id WHERE e.status = 'active'")->fetch();
        if (!$ed || EditionState::of($ed) !== 'voting') {
            self::fail('Voting is not open right now.');
        }

        $st = $pdo->prepare("SELECT n.id, n.name, n.category_id FROM nominee_entries n
                               JOIN categories c ON c.id = n.category_id AND c.active = 1
                              WHERE n.id = ? AND n.edition_id = ? AND n.status = 'approved'");
        $st->execute([(int) ($in['nomineeId'] ?? 0), $ed['id']]);
        $nominee = $st->fetch();
        if (!$nominee || (int) $nominee['category_id'] !== (int) ($in['categoryId'] ?? 0)) {
            self::fail('That nominee is not available for voting.');
        }

        $max = $ed['max_votes_per_payment'] === null ? 10000 : (int) $ed['max_votes_per_payment'];
        if (!is_int($votes) && !(is_string($votes) && ctype_digit($votes))) {
            self::fail('Enter at least 1 vote.', 422, ['fields' => ['votes' => 'Enter at least 1 vote.']]);
        }
        $votes = (int) $votes;
        if ($votes < 1) {
            self::fail('Enter at least 1 vote.', 422, ['fields' => ['votes' => 'Enter at least 1 vote.']]);
        }
        if ($votes > $max) {
            self::fail("You can buy up to $max votes per payment.", 422, ['fields' => ['votes' => "You can buy up to $max votes per payment."]]);
        }

        $amount = round($votes * (float) $ed['price_per_vote'], 2);          // the price is OURS; the browser never sends one
        $currency = $ed['currency'] ?: 'NGN';
        $reference = 'VOT-' . strtoupper(bin2hex(random_bytes(6)));
        $limit = $ed['max_paid_payments_per_email_per_category'];

        try {
            Database::transaction(function (\PDO $db) use ($ed, $nominee, $votes, $amount, $currency, $reference, $name, $email, $limit) {
                // Serialise every order for this email+category, so two quick clicks cannot both squeeze under the limit.
                $db->prepare('INSERT IGNORE INTO vote_limit_counters (edition_id, category_id, voter_email, paid_orders) VALUES (?, ?, ?, 0)')
                    ->execute([$ed['id'], $nominee['category_id'], $email]);
                $db->prepare('SELECT paid_orders FROM vote_limit_counters WHERE edition_id = ? AND category_id = ? AND voter_email = ? FOR UPDATE')
                    ->execute([$ed['id'], $nominee['category_id'], $email]);

                if ($limit !== null) {
                    $c = $db->prepare("SELECT COUNT(*) FROM vote_orders
                                        WHERE edition_id = ? AND category_id = ? AND voter_email = ? AND source = 'payment'
                                          AND (status = 'paid' OR (status = 'pending' AND created_at > (NOW() - INTERVAL " . self::PENDING_HOLD_MINUTES . ' MINUTE)))');
                    $c->execute([$ed['id'], $nominee['category_id'], $email]);
                    if ((int) $c->fetchColumn() >= (int) $limit) {
                        throw new \DomainException("You have reached the limit of $limit paid vote payments for this category.");
                    }
                }
                $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (?, 'vote', ?, ?, ?, ?, 'pending')")
                    ->execute([$ed['id'], $reference, $amount, $currency, $email]);
                $paymentId = (int) $db->lastInsertId();
                $db->prepare("INSERT INTO vote_orders (edition_id, reference, nominee_entry_id, category_id, votes, amount, voter_name, voter_email, status, source, payment_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'payment', ?)")
                    ->execute([$ed['id'], $reference, $nominee['id'], $nominee['category_id'], $votes, $amount, $name, $email, $paymentId]);
            });
        } catch (\DomainException $e) {
            self::fail($e->getMessage());            // e.g. the per-email limit was reached
        }

        try {
            $link = Flutterwave::createPayment([
                'reference' => $reference, 'amount' => $amount, 'currency' => $currency, 'email' => $email, 'name' => $name,
                'redirectUrl' => self::appUrl() . 'vote?pay=return', 'purpose' => 'vote',
                'title' => $ed['name'] . ' ' . $ed['edition_label'] . ' — votes', 'description' => "$votes vote(s) for {$nominee['name']}",
            ]);
        } catch (GatewayException $e) {
            error_log((string) $e);
            $pdo->prepare("UPDATE payments SET status = 'failed' WHERE reference = ?")->execute([$reference]);
            $pdo->prepare("UPDATE vote_orders SET status = 'void', voided_at = NOW(), void_reason = 'Could not start the payment' WHERE reference = ?")->execute([$reference]);
            self::fail('The payment service is unavailable right now. You have not been charged. Please try again shortly.', 502);
        }

        Response::json(['ok' => true, 'reference' => $reference, 'votes' => $votes, 'amount' => $amount, 'nomineeId' => (string) $nominee['id'],
                        'categoryId' => (string) $nominee['category_id'], 'paymentLink' => $link]);
    }

    // ----------------------------------------------------------- confirm

    private static function orderView(string $reference): ?array
    {
        $st = self::pdo()->prepare('SELECT reference, votes, nominee_entry_id, category_id, status FROM vote_orders WHERE reference = ?');
        $st->execute([$reference]);
        $o = $st->fetch();
        return $o ? ['reference' => $o['reference'], 'votes' => (int) $o['votes'], 'nomineeId' => (string) $o['nominee_entry_id'],
                     'categoryId' => (string) $o['category_id'], 'status' => $o['status']] : null;
    }

    /** The buyer came back from Flutterwave. We ask Flutterwave ourselves; nothing the browser says counts as proof. */
    public static function confirm(string $reference): never
    {
        self::throttle('vote-confirm', 240, 15);
        $order = self::orderView($reference);
        if ($order === null) {
            self::fail('We could not find that payment.', 404);
        }
        $result = PaymentService::settle($reference);
        $order = self::orderView($reference);
        Response::json(['ok' => true, 'status' => $result['status'], 'note' => $result['note'] ?? null, 'order' => $order]);
    }

    // ----------------------------------------------------------- webhook

    public static function webhook(): never
    {
        $secret = (string) Env::get('FLW_WEBHOOK_HASH', '');
        $sent = (string) ($_SERVER['HTTP_VERIF_HASH'] ?? '');
        if ($secret === '' || !hash_equals($secret, $sent)) {
            Response::json(['ok' => false], 401);
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            Response::json(['ok' => false], 400);
        }
        $event = (string) ($body['event'] ?? $body['event.type'] ?? '');
        $data = (array) ($body['data'] ?? []);
        if ($event !== 'charge.completed' || empty($data['id'])) {
            Response::json(['ok' => true, 'ignored' => true]);                 // other events: nothing to do
        }

        $pdo = self::pdo();
        $key = $event . ':' . $data['id'] . ':' . ($data['status'] ?? '');
        $pdo->prepare('INSERT IGNORE INTO webhook_events (provider, event_key, payload) VALUES (?, ?, ?)')
            ->execute(['flutterwave', $key, json_encode($body)]);
        $st = $pdo->prepare('SELECT id, processed_at FROM webhook_events WHERE provider = ? AND event_key = ?');
        $st->execute(['flutterwave', $key]);
        $row = $st->fetch();
        if ($row['processed_at'] !== null) {
            Response::json(['ok' => true, 'duplicate' => true]);              // already handled: do nothing twice
        }

        try {
            // The webhook only tells us "look at this payment". We look, ourselves.
            $verified = Flutterwave::verifyById((string) $data['id']);
            $reference = (string) ($verified['tx_ref'] ?? $data['tx_ref'] ?? '');
            if ($verified !== null && PaymentService::find($reference) !== null) {
                PaymentService::settle($reference, $verified);
            }
            $pdo->prepare('UPDATE webhook_events SET processed_at = NOW() WHERE id = ?')->execute([$row['id']]);
        } catch (\Throwable $e) {
            error_log((string) $e);
            Response::json(['ok' => false], 500);                              // a non-200 makes Flutterwave send it again
        }
        Response::json(['ok' => true]);
    }
}