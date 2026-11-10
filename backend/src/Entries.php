<?php
declare(strict_types=1);

namespace App;

/** Phase 8: a nominee can enter several categories in one edition. Shared rules. */
final class Entries
{
    public static function maxPerNominee(int $editionId): int
    {
        $st = Database::pdo()->prepare('SELECT max_categories_per_nominee FROM edition_settings WHERE edition_id = ?');
        $st->execute([$editionId]);
        $n = $st->fetchColumn();
        return $n === false || (int) $n < 1 ? 3 : (int) $n;
    }

    /** "categoryIds" (a list) or the older single "categoryId". Whole numbers, no repeats. */
    public static function requested(array $in): array
    {
        $raw = $in['categoryIds'] ?? ($in['categoryId'] ?? []);
        $raw = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($raw as $v) {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $ids[(int) $v] = (int) $v;
            }
        }
        return array_values($ids);
    }

    public static function takenCategories(\PDO $db, int $accountId, int $editionId): array
    {
        $st = $db->prepare('SELECT category_id FROM nominee_entries WHERE account_id = ? AND edition_id = ? ORDER BY id');
        $st->execute([$accountId, $editionId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Already registered for this edition (entry exists, or a paid registration completed)? Then the fee is settled. */
    public static function feeSettled(\PDO $db, int $accountId, int $editionId): bool
    {
        if (self::takenCategories($db, $accountId, $editionId) !== []) {
            return true;
        }
        $st = $db->prepare("SELECT COUNT(*) FROM pending_registrations WHERE account_id = ? AND edition_id = ? AND status = 'completed'");
        $st->execute([$accountId, $editionId]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function syncProfile(\PDO $db, int $accountId, int $editionId, array $f): void
    {
        $db->prepare('UPDATE nominee_entries SET name = ?, phone = ?, department = ?, level = ?, bio = ? WHERE account_id = ? AND edition_id = ?')
            ->execute([$f['name'], $f['phone'], $f['department'], $f['level'], $f['bio'], $accountId, $editionId]);
    }

    public static function photosOf(\PDO $db, int $accountId, int $editionId): array
    {
        $st = $db->prepare('SELECT DISTINCT photo_path FROM nominee_entries WHERE account_id = ? AND edition_id = ? AND photo_path IS NOT NULL');
        $st->execute([$accountId, $editionId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function setPhoto(\PDO $db, int $accountId, int $editionId, ?string $path): void
    {
        $db->prepare('UPDATE nominee_entries SET photo_path = ? WHERE account_id = ? AND edition_id = ?')->execute([$path, $accountId, $editionId]);
    }
}