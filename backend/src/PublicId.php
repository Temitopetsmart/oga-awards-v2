<?php
declare(strict_types=1);

namespace App;

/**
 * Random numeric Public IDs, for display and identification only. Internal ids and every relationship stay as they are.
 *
 * One person = one Public ID: a nominee account (however many categories it has), a staff / committee person,
 * or an admin-created / imported nominee that has no account. The public_ids table is the single guard that makes
 * an ID unique across all of them; the same ID is copied to the owner's own row so lists can read it without a join.
 */
final class PublicId
{
    public const TYPES = ['account' => 'accounts', 'person' => 'people', 'entry' => 'nominee_entries'];
    private const START_DIGITS = 7;     // 1,000,000 to 9,999,999
    private const MAX_DIGITS = 10;

    /** A random number with exactly $digits digits (never a leading zero). */
    public static function generate(int $digits = self::START_DIGITS): string
    {
        $digits = max(4, min(self::MAX_DIGITS, $digits));
        return (string) random_int(10 ** ($digits - 1), 10 ** $digits - 1);
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{3,9}$/', $id);
    }

    /** Gives the owner a Public ID and returns it. If it already has one, that one is returned unchanged. */
    public static function assign(\PDO $db, string $type, int $ownerId): string
    {
        $table = self::TYPES[$type] ?? throw new \InvalidArgumentException("Unknown owner type: $type");
        $find = $db->prepare('SELECT public_id FROM public_ids WHERE owner_type = ? AND owner_id = ?');
        $find->execute([$type, $ownerId]);
        if (($have = $find->fetchColumn()) !== false) {
            return (string) $have;
        }
        $digits = self::START_DIGITS;
        for ($try = 1; $try <= 40; $try++) {
            if ($try % 8 === 0 && $digits < self::MAX_DIGITS) {
                $digits++;                                   // the short numbers are getting crowded: use longer ones
            }
            $id = self::generate($digits);
            try {
                $db->prepare('INSERT INTO public_ids (public_id, owner_type, owner_id) VALUES (?, ?, ?)')->execute([$id, $type, $ownerId]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $find->execute([$type, $ownerId]);           // somebody gave this owner one a moment ago, or the number is taken
                if (($have = $find->fetchColumn()) !== false) {
                    return (string) $have;
                }
                continue;
            }
            $up = $db->prepare("UPDATE $table SET public_id = ? WHERE id = ?");
            $up->execute([$id, $ownerId]);
            if ($up->rowCount() === 0) {                     // no such owner: leave nothing behind
                $db->prepare('DELETE FROM public_ids WHERE public_id = ?')->execute([$id]);
                throw new \RuntimeException("Cannot give a Public ID to $type $ownerId: it does not exist.");
            }
            return $id;
        }
        throw new \RuntimeException('Could not find a free Public ID.');
    }

    /** The owner's Public ID, giving it one first if it has none (older rows). */
    public static function ensure(string $type, int $ownerId, ?\PDO $db = null): string
    {
        $db ??= Database::pdo();
        $table = self::TYPES[$type] ?? throw new \InvalidArgumentException("Unknown owner type: $type");
        $st = $db->prepare("SELECT public_id FROM $table WHERE id = ?");
        $st->execute([$ownerId]);
        $have = $st->fetchColumn();
        return is_string($have) && $have !== '' ? $have : self::assign($db, $type, $ownerId);
    }

    /** Who owns this Public ID: ['type' => 'account'|'person'|'entry', 'id' => internal id], or null. */
    public static function lookup(string $publicId, ?\PDO $db = null): ?array
    {
        $db ??= Database::pdo();
        $st = $db->prepare('SELECT owner_type, owner_id FROM public_ids WHERE public_id = ?');
        $st->execute([$publicId]);
        $r = $st->fetch();
        return $r ? ['type' => $r['owner_type'], 'id' => (int) $r['owner_id']] : null;
    }

    /** Gives every existing person without one a Public ID. Safe to run again. Returns how many were added per type. */
    public static function backfill(\PDO $db): array
    {
        $done = [];
        foreach (self::TYPES as $type => $table) {
            $where = $type === 'entry' ? 'public_id IS NULL AND account_id IS NULL' : 'public_id IS NULL';
            $ids = $db->query("SELECT id FROM $table WHERE $where ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                self::assign($db, $type, (int) $id);
            }
            $done[$type] = count($ids);
        }
        return $done;
    }
}