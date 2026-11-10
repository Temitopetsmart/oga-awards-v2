<?php
declare(strict_types=1);

namespace App;

/** Finishes a PAID nominee registration. Runs inside PaymentService's lock, so it happens once and only after Flutterwave confirms. */
final class Registrations
{
    public static function complete(\PDO $db, array $payment): void
    {
        $st = $db->prepare('SELECT * FROM pending_registrations WHERE payment_id = ? FOR UPDATE');
        $st->execute([$payment['id']]);
        $r = $st->fetch();
        if (!$r || $r['status'] !== 'pending') {
            return;
        }
        $p = json_decode($r['profile'], true) ?: [];
        $accountId = $r['account_id'] === null ? null : (int) $r['account_id'];
        $newAccount = $accountId === null;

        // One fee covers every category asked for (the older single "categoryId" is still understood).
        $catIds = Entries::requested($p);
        if ($catIds === []) {
            self::conflict($db, $r, 'no category was chosen');
            return;
        }
        if ($newAccount) {
            $q = $db->prepare('SELECT COUNT(*) FROM accounts WHERE email = ?');
            $q->execute([$r['email']]);
            if ((int) $q->fetchColumn() > 0) {
                self::conflict($db, $r, 'an account with this email already exists');
                return;
            }
        } else {
            $catIds = array_values(array_diff($catIds, Entries::takenCategories($db, $accountId, (int) $r['edition_id'])));
            if ($catIds === []) {
                self::conflict($db, $r, 'the account already has an entry for every category chosen');
                return;
            }
        }

        if ($newAccount) {
            $matric = ($p['matricNo'] ?? null) ?: null;
            if ($matric !== null && ($why = Matric::problem($db, $matric, $r['email'])) !== null) {
                self::conflict($db, $r, $why);                      // paid, but the number was taken meanwhile: flagged for an admin
                return;
            }
            $db->prepare('INSERT INTO accounts (email, password_hash, matric_no, level) VALUES (?, ?, ?, ?)')->execute([$r['email'], $r['password_hash'], $matric, $p['level'] ?? null]);
            $accountId = (int) $db->lastInsertId();
            if ($matric !== null) {
                Matric::claim($db, $matric, $r['email'], 'nominee');
            }
        }
        
        $ins = $db->prepare("INSERT INTO nominee_entries (edition_id, account_id, category_id, name, phone, department, level, bio, status, registration_payment_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        foreach ($catIds as $cid) {
            $ins->execute([$r['edition_id'], $accountId, $cid, $p['name'], $p['phone'] ?? null, $p['department'] ?? null, $p['level'] ?? null, $p['bio'] ?? null, $payment['id']]);
        }
        if ($newAccount) {
            $token = AccountApi::newToken($accountId, 'email_verify');
            AccountApi::queue('nominee_verify_email', $r['email'], ['token' => $token, 'expiresMinutes' => 30]);
        }
        $db->prepare("UPDATE pending_registrations SET status = 'completed', account_id = ?, password_hash = NULL, completed_at = NOW() WHERE id = ?")
            ->execute([$accountId, $r['id']]);
        Audit::log(null, $newAccount ? 'Nominee registered (fee paid)' : 'Nominee applied for a new edition (fee paid)', $r['email'], (int) $r['edition_id']);
        Cache::flush();
    }

    /** The money arrived but the registration cannot be finished. Never lose track of it: flag it for an admin to refund or fix. */
    private static function conflict(\PDO $db, array $r, string $why): void
    {
        $db->prepare("UPDATE pending_registrations SET status = 'failed', password_hash = NULL WHERE id = ?")->execute([$r['id']]);
        Audit::log(null, 'Paid registration needs attention', $r['email'] . ' — ' . $why, (int) $r['edition_id'], ['reference' => $r['reference']]);
    }
}