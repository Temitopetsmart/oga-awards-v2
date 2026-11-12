<?php
declare(strict_types=1);

namespace App;

/** Phase 15: Face of the period. A spotlight on one approved nominee for a period the admin names. No vote counts, ever. */
final class SpotlightApi
{
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function shape(array $r): array
    {
        return [
            'id'         => (string) $r['id'],
            'period'     => $r['period_label'],
            'headline'   => (string) ($r['headline'] ?? ''),
            'note'       => (string) ($r['note'] ?? ''),
            'featured'   => (bool) $r['featured'],
            'nomineeId'  => (string) $r['nominee_entry_id'],
            'name'       => $r['name'],
            'department' => $r['department'],
            'category'   => $r['category_name'],
            'photo'      => $r['photo_path'],
            'thumb'      => Photos::thumbOf($r['photo_path']),
            'occasionId' => $r['edition_slug'],
            'edition'    => trim($r['edition_name'] . ' ' . ($r['edition_label'] ?? '')),
        ];
    }

    private const SELECT = 'SELECT f.*, n.name, n.department, n.photo_path, c.name AS category_name, e.slug AS edition_slug, e.name AS edition_name, e.edition_label
        FROM face_of_period f
        JOIN nominee_entries n ON n.id = f.nominee_entry_id AND n.status = \'approved\'
        JOIN categories c ON c.id = n.category_id
        JOIN editions e ON e.id = f.edition_id';

    /** Public: the featured face, and the earlier ones. */
    public static function publicView(): array
    {
        $rows = self::pdo()->query(self::SELECT . " WHERE e.status <> 'draft' ORDER BY f.featured DESC, f.id DESC LIMIT 60")->fetchAll();
        $all = array_map([self::class, 'shape'], $rows);
        $featured = $all && $all[0]['featured'] ? $all[0] : null;
        return ['featured' => $featured, 'history' => array_values(array_filter($all, fn ($x) => $x !== $featured))];
    }

    // ------------------------------------------------------------ admin

    private static function edition(string $slug): array
    {
        $st = self::pdo()->prepare('SELECT id, slug FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('Unknown edition.', 404);
    }

    public static function list(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('spotlight.view', $slug);
        $ed = self::edition($slug);
        $st = self::pdo()->prepare(self::SELECT . ' WHERE f.edition_id = ? ORDER BY f.id DESC');
        $st->execute([$ed['id']]);
        Response::json(['ok' => true, 'faces' => array_map([self::class, 'shape'], $st->fetchAll())]);
    }

    private static function fields(array $in, int $editionId): array
    {
        $period = trim((string) ($in['period'] ?? ''));
        $headline = trim((string) ($in['headline'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($period) < 2 || mb_strlen($period) > 60) {
            self::fail('Period: 2 to 60 characters, for example “November 2026”.');
        }
        if (mb_strlen($headline) > 120 || mb_strlen($note) > 500) {
            self::fail('Headline: up to 120 characters. Note: up to 500.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $period . $headline . $note)) {
            self::fail('The text has characters that are not allowed.');
        }
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM nominee_entries WHERE id = ? AND edition_id = ? AND status = 'approved'");
        $st->execute([(int) ($in['nomineeId'] ?? 0), $editionId]);
        if ((int) $st->fetchColumn() === 0) {
            self::fail('Choose an approved nominee of this edition.');
        }
        return [$period, $headline !== '' ? $headline : null, $note !== '' ? $note : null, (int) $in['nomineeId'], !empty($in['featured']) ? 1 : 0];
    }

    private static function save(?int $id, array $in, array $actor, array $ed): void
    {
        [$period, $headline, $note, $nominee, $featured] = self::fields($in, (int) $ed['id']);
        try {
            Database::transaction(function (\PDO $db) use (&$id, $ed, $period, $headline, $note, $nominee, $featured, $actor) {
                if ($featured) {
                    $db->prepare('UPDATE face_of_period SET featured = 0 WHERE edition_id = ?')->execute([$ed['id']]);   // only one is featured at a time
                }
                if ($id === null) {
                    $db->prepare('INSERT INTO face_of_period (edition_id, nominee_entry_id, period_label, headline, note, featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$ed['id'], $nominee, $period, $headline, $note, $featured, $actor['id']]);
                    $id = (int) $db->lastInsertId();
                } else {
                    $db->prepare('UPDATE face_of_period SET nominee_entry_id = ?, period_label = ?, headline = ?, note = ?, featured = ? WHERE id = ?')
                        ->execute([$nominee, $period, $headline, $note, $featured, $id]);
                }
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('There is already a Face of the period for “' . $period . '”. Edit that one instead.');
            }
            throw $e;
        }
        Cache::flush();
        Audit::log((int) $actor['id'], $id !== null ? 'Saved a Face of the period' : 'Added a Face of the period', $period, (int) $ed['id']);
    }

    public static function create(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('spotlight.manage', $slug);
        self::save(null, $in, $actor, self::edition($slug));
        Response::json(['ok' => true]);
    }

    private static function own(int $id): array
    {
        $st = self::pdo()->prepare('SELECT f.*, e.slug AS edition_slug FROM face_of_period f JOIN editions e ON e.id = f.edition_id WHERE f.id = ?');
        $st->execute([$id]);
        $f = $st->fetch() ?: self::fail('That entry was not found.', 404);
        return $f + ['actor' => Access::require('spotlight.manage', $f['edition_slug'])];
    }

    public static function update(int $id): never
    {
        $f = self::own($id);
        self::save($id, Request::json(), $f['actor'], ['id' => $f['edition_id'], 'slug' => $f['edition_slug']]);
        Response::json(['ok' => true]);
    }

    /** Makes this one the face shown on the home page. */
    public static function feature(int $id): never
    {
        $f = self::own($id);
        Database::transaction(function (\PDO $db) use ($f, $id) {
            $db->prepare('UPDATE face_of_period SET featured = 0 WHERE edition_id = ?')->execute([$f['edition_id']]);
            $db->prepare('UPDATE face_of_period SET featured = 1 WHERE id = ?')->execute([$id]);
        });
        Cache::flush();
        Audit::log((int) $f['actor']['id'], 'Featured a Face of the period', $f['period_label'], (int) $f['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function delete(int $id): never
    {
        $f = self::own($id);
        self::pdo()->prepare('DELETE FROM face_of_period WHERE id = ?')->execute([$id]);
        Cache::flush();
        Audit::log((int) $f['actor']['id'], 'Removed a Face of the period', $f['period_label'], (int) $f['edition_id']);
        Response::json(['ok' => true]);
    }
}