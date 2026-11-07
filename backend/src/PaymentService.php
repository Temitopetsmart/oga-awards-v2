<?php
declare(strict_types=1);

namespace App;

/**
 * The ONE place a payment turns into votes (and, later, tickets). The rules:
 *   - the answer comes from Flutterwave, asked by US, about OUR reference — never from the browser;
 *   - status must be "successful", the reference must match, the currency must match, the amount must cover what WE expected;
 *   - it happens at most once: a lock + a UNIQUE provider transaction id make a second try a harmless no-op.
 */
final class PaymentService
{
    public static function find(string $reference): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM payments WHERE reference = ?');
        $st->execute([$reference]);
        return $st->fetch() ?: null;
    }

    /**
     * @param array|null $verified Flutterwave's data for this payment if the caller already fetched it (webhook).
     * @return array{status:string, payment:array, note?:string}  status: paid | pending | failed
     */
    public static function settle(string $reference, ?array $verified = null): array
    {
        $p = self::find($reference) ?? throw new \DomainException('Unknown payment reference');
        if ($p['status'] === 'successful') {
            return ['status' => 'paid', 'payment' => $p];             // already credited: nothing to do
        }
        if ($p['status'] === 'failed' || $p['status'] === 'abandoned') {
            return ['status' => 'failed', 'payment' => $p, 'note' => 'This payment did not go through.'];
        }

        if ($verified === null) {
            try {
                $verified = Flutterwave::verifyByReference($reference);
            } catch (GatewayException $e) {
                error_log((string) $e);
                return ['status' => 'pending', 'payment' => $p, 'note' => 'We could not reach the payment provider. Please try again in a moment.'];
            }
        }
        if ($verified === null) {
            return ['status' => 'pending', 'payment' => $p, 'note' => 'The payment provider has no completed payment for this reference yet.'];
        }

        $state = strtolower((string) ($verified['status'] ?? ''));
        if (in_array($state, ['failed', 'cancelled'], true)) {
            self::markFailed($p, "Flutterwave reports: $state");
            return ['status' => 'failed', 'payment' => self::find($reference), 'note' => 'The payment was not completed. You have not been charged.'];
        }
        if ($state !== 'successful') {
            return ['status' => 'pending', 'payment' => $p, 'note' => 'The payment is still being processed.'];
        }

        // ---- Flutterwave says "successful": check it is really OUR payment, for the right money ----
        $problem = null;
        if ((string) ($verified['tx_ref'] ?? '') !== $p['reference']) {
            $problem = 'reference mismatch';
        } elseif (strtoupper((string) ($verified['currency'] ?? '')) !== strtoupper($p['currency'])) {
            $problem = 'currency mismatch';
        } elseif (round((float) ($verified['amount'] ?? 0), 2) < round((float) $p['expected_amount'], 2)) {
            $problem = 'amount too low';
        }
        if ($problem !== null) {
            self::markFailed($p, "Verification failed: $problem", $verified);
            Audit::log(null, 'Payment verification problem', $p['reference'] . ' — ' . $problem, (int) $p['edition_id'], [
                'expected' => $p['expected_amount'], 'received' => $verified['amount'] ?? null, 'currency' => $verified['currency'] ?? null,
            ]);
            return ['status' => 'failed', 'payment' => self::find($reference), 'note' => 'The payment could not be verified. Contact the organisers with your reference.'];
        }

        try {
            Database::transaction(function (\PDO $db) use ($p, $verified) {
                $st = $db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
                $st->execute([$p['id']]);
                $locked = $st->fetch();
                if ($locked['status'] === 'successful') {
                    return;                                         // a parallel request got here first
                }
                $db->prepare("UPDATE payments SET status = 'successful', provider_transaction_id = ?, paid_amount = ?, verified_at = NOW(), provider_payload = ? WHERE id = ?")
                    ->execute([(string) ($verified['id'] ?? ''), $verified['amount'], json_encode([
                        'id' => $verified['id'] ?? null, 'flw_ref' => $verified['flw_ref'] ?? null, 'amount' => $verified['amount'] ?? null,
                        'charged_amount' => $verified['charged_amount'] ?? null, 'currency' => $verified['currency'] ?? null,
                        'payment_type' => $verified['payment_type'] ?? null, 'created_at' => $verified['created_at'] ?? null,
                    ]), $p['id']]);
                self::credit($db, $locked);
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {                        // the same Flutterwave transaction id was already used
                self::markFailed($p, 'Duplicate provider transaction');
                return ['status' => 'failed', 'payment' => self::find($reference), 'note' => 'This payment was already used for another order.'];
            }
            throw $e;
        }
        return ['status' => 'paid', 'payment' => self::find($reference)];
    }

    /** What a verified payment unlocks. Runs inside the lock above, so it can only ever run once. */
    private static function credit(\PDO $db, array $p): void
    {
        switch ($p['purpose']) {
            case 'vote':
                $db->prepare("UPDATE vote_orders SET status = 'paid', paid_at = NOW() WHERE payment_id = ? AND status = 'pending'")->execute([$p['id']]);
                VoteTotals::creditPayment($db, (int) $p['id']);
                break;
            case 'nominee_fee':
                Registrations::complete($db, $p);
                break;
            case 'ticket':
                Tickets::issueForPayment($db, $p);
                break;        }
    }

    private static function markFailed(array $p, string $why, ?array $payload = null): void
    {
        Database::pdo()->prepare("UPDATE payments SET status = 'failed', provider_payload = ? WHERE id = ? AND status = 'pending'")
            ->execute([json_encode(['reason' => $why, 'provider' => $payload ? array_intersect_key($payload, array_flip(['id', 'status', 'amount', 'currency', 'tx_ref'])) : null]), $p['id']]);
    }
}