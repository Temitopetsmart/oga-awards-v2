<?php
declare(strict_types=1);

namespace App;

/** A nominee can enter several categories in one edition, paying per category. Shared rules. */
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

    /** The first entry a person made in an edition (their "main" entry). Later entries follow its photo and bio until changed. */
    public static function main(\PDO $db, int $accountId, int $editionId): ?array
    {
        $st = $db->prepare('SELECT * FROM nominee_entries WHERE account_id = ? AND edition_id = ? ORDER BY id LIMIT 1');
        $st->execute([$accountId, $editionId]);
        return $st->fetch() ?: null;
    }

    /**
     * What a NEW entry starts with: the person's main entry of this edition, or (for a new edition) their newest earlier entry.
     * @return array|null name, phone, department, level, nickname, bio, photo_path
     */
    public static function template(\PDO $db, int $accountId, int $editionId): ?array
    {
        $main = self::main($db, $accountId, $editionId);
        if ($main !== null) {
            return $main;
        }
        $st = $db->prepare('SELECT * FROM nominee_entries WHERE account_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$accountId]);
        return $st->fetch() ?: null;
    }

    /** Adds entries (pending) for each category, copying the person's details from $from. Returns the new entry ids. */
    public static function addFrom(\PDO $db, int $editionId, int $accountId, array $from, array $categoryIds, ?string $level, ?int $paymentId = null): array
    {
        $ins = $db->prepare("INSERT INTO nominee_entries (edition_id, account_id, category_id, name, phone, department, level, bio, photo_path, nickname, status, registration_payment_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
        $ids = [];
        foreach ($categoryIds as $cid) {
            $ins->execute([$editionId, $accountId, $cid, $from['name'], $from['phone'], $from['department'], $level ?? $from['level'], $from['bio'], $from['photo_path'], $from['nickname'] ?? null, $paymentId]);
            $ids[] = (int) $db->lastInsertId();
        }
        return $ids;
    }

    /** The person's own details, the same in every category: name, nickname, phone, department, level. (Bio and photo are per category.) */
    public static function syncShared(\PDO $db, int $accountId, int $editionId, array $f): void
    {
        $db->prepare('UPDATE nominee_entries SET name = ?, nickname = ?, phone = ?, department = ?, level = ? WHERE account_id = ? AND edition_id = ?')
            ->execute([$f['name'], $f['nickname'] ?? null, $f['phone'], $f['department'], $f['level'], $accountId, $editionId]);
    }

    private static function isMain(\PDO $db, array $entry): bool
    {
        $m = self::main($db, (int) $entry['account_id'], (int) $entry['edition_id']);
        return $m !== null && (int) $m['id'] === (int) $entry['id'];
    }

    /**
     * Saves a bio. On the main entry it also reaches every category still using the main bio; on any other entry it
     * applies to that category only (and is remembered as that category's own).
     */
    public static function setBio(\PDO $db, array $entry, ?string $bio): void
    {
        if (self::isMain($db, $entry)) {
            $db->prepare('UPDATE nominee_entries SET bio = ? WHERE account_id = ? AND edition_id = ? AND (id = ? OR bio_custom = 0)')
                ->execute([$bio, $entry['account_id'], $entry['edition_id'], $entry['id']]);
            return;
        }
        $db->prepare('UPDATE nominee_entries SET bio = ?, bio_custom = 1 WHERE id = ?')->execute([$bio, $entry['id']]);
    }

    /**
     * Saves a photo path the same way (null removes it). Returns the paths that were replaced, for the caller to delete
     * (Photos::delete keeps any file another entry still uses).
     * @return string[]
     */
    public static function setPhotoFor(\PDO $db, array $entry, ?string $path): array
    {
        if (self::isMain($db, $entry)) {
            $st = $db->prepare('SELECT DISTINCT photo_path FROM nominee_entries WHERE account_id = ? AND edition_id = ? AND (id = ? OR photo_custom = 0) AND photo_path IS NOT NULL');
            $st->execute([$entry['account_id'], $entry['edition_id'], $entry['id']]);
            $old = $st->fetchAll(\PDO::FETCH_COLUMN);
            $db->prepare('UPDATE nominee_entries SET photo_path = ? WHERE account_id = ? AND edition_id = ? AND (id = ? OR photo_custom = 0)')
                ->execute([$path, $entry['account_id'], $entry['edition_id'], $entry['id']]);
            return $old;
        }
        $old = $entry['photo_path'] !== null ? [$entry['photo_path']] : [];
        $db->prepare('UPDATE nominee_entries SET photo_path = ?, photo_custom = 1 WHERE id = ?')->execute([$path, $entry['id']]);
        return $old;
    }

    /**
     * Puts a category back on the main bio and/or the main photo. Returns replaced photo paths (for deletion).
     * @return string[]
     */
    public static function useMain(\PDO $db, array $entry, bool $bio, bool $photo): array
    {
        $main = self::main($db, (int) $entry['account_id'], (int) $entry['edition_id']);
        $old = [];
        if ($main === null || (int) $main['id'] === (int) $entry['id']) {
            return $old;
        }
        if ($bio) {
            $db->prepare('UPDATE nominee_entries SET bio = ?, bio_custom = 0 WHERE id = ?')->execute([$main['bio'], $entry['id']]);
        }
        if ($photo) {
            $old = $entry['photo_path'] !== null ? [$entry['photo_path']] : [];
            $db->prepare('UPDATE nominee_entries SET photo_path = ?, photo_custom = 0 WHERE id = ?')->execute([$main['photo_path'], $entry['id']]);
        }
        return $old;
    }
}