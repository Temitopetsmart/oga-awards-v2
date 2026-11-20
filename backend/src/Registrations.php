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

        // The fee was charged PER category, so the money covers exactly the categories asked for.
        $catIds = Entries::requested($p);
        $editionId = (int) $r['edition_id'];
        if ($catIds === []) {
            self::conflict($db, $r, 'no category was chosen');
            return;
        }
        $level = ($p['level'] ?? null) ?: null;
        if ($newAccount) {
            $q = $db->prepare('SELECT COUNT(*) FROM accounts WHERE email = ?');
            $q->execute([$r['email']]);
            if ((int) $q->fetchColumn() > 0) {
                self::conflict($db, $r, 'an account with this email already exists');
                return;
            }
            if (count($catIds) !== 1) {
                self::conflict($db, $r, 'a new registration must be for one category');
                return;
            }
        } else {
            $acc = $db->prepare('SELECT level FROM accounts WHERE id = ?');
            $acc->execute([$accountId]);
            $level = ($acc->fetchColumn() ?: null) ?? $level;
            $have = Entries::takenCategories($db, $accountId, $editionId);
            $fresh = array_values(array_diff($catIds, $have));
            if ($fresh === []) {
                self::conflict($db, $r, 'the account already has an entry for every category chosen');
                return;
            }
            if (count($have) + count($fresh) > Entries::maxPerNominee($editionId)) {
                self::conflict($db, $r, 'the limit on categories per nominee would be passed');
                return;
            }
            if (count($fresh) < count($catIds)) {
                Audit::log(null, 'Paid registration needs attention', $r['email'] . ' — paid for ' . count($catIds) . ' categories but already had ' . (count($catIds) - count($fresh)) . ' of them', $editionId, ['reference' => $r['reference']]);
            }
            $catIds = $fresh;
        }
        // Each category must still exist in this edition and be open to this person's level.
        $okCat = $db->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND edition_id = ? AND active = 1');
        foreach ($catIds as $cid) {
            $okCat->execute([$cid, $editionId]);
            if ((int) $okCat->fetchColumn() === 0 || ($level !== null && !Eligibility::allows($cid, $level))) {
                self::conflict($db, $r, 'a chosen category is no longer open to this nominee');
                return;
            }
        }

        if ($newAccount) {
            $matric = ($p['matricNo'] ?? null) ?: null;
            if ($matric !== null && ($why = Matric::problem($db, $matric, $r['email'])) !== null) {
                self::conflict($db, $r, $why);                      // paid, but the number was taken meanwhile: flagged for an admin
                return;
            }
            $db->prepare('INSERT INTO accounts (email, password_hash, matric_no, level) VALUES (?, ?, ?, ?)')->execute([$r['email'], $r['password_hash'], $matric, $level]);
            $accountId = (int) $db->lastInsertId();
            if ($matric !== null) {
                Matric::claim($db, $matric, $r['email'], 'nominee');
            }
            $from = ['name' => $p['name'], 'phone' => $p['phone'] ?? null, 'department' => $p['department'] ?? null, 'level' => $level, 'bio' => $p['bio'] ?? null, 'photo_path' => null, 'nickname' => null];
        } else {
            // A returning nominee is never asked again: the new categories start from their main entry (name, phone, bio, photo...).
            $from = Entries::template($db, $accountId, $editionId) ?? ['name' => $p['name'], 'phone' => $p['phone'] ?? null, 'department' => $p['department'] ?? null, 'level' => $level, 'bio' => $p['bio'] ?? null, 'photo_path' => null, 'nickname' => null];
        }
        Entries::addFrom($db, $editionId, $accountId, $from, $catIds, $level, (int) $payment['id']);
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