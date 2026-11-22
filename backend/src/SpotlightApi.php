<?php
declare(strict_types=1);

namespace App;

/** Face of the period: one nominee or committee member per period. */
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
        $type = $r['subject_type'];

        return [
            'id' => (string) $r['id'],
            'type' => $type,
            'period' => $r['period_label'],
            'headline' => (string) ($r['headline'] ?? ''),
            'note' => (string) ($r['note'] ?? ''),
            'featured' => (bool) $r['featured'],
            'nomineeId' => $r['nominee_entry_id'] === null ? null : (string) $r['nominee_entry_id'],
            'committeeMemberId' => $r['committee_assignment_id'] === null ? null : (string) $r['committee_assignment_id'],
            'name' => $r['person_name'],
            'department' => $r['department'] ?? '',
            'level' => $r['level'] ?? '',
            'position' => $r['position'] ?? '',
            'unit' => $r['unit_name'] ?? '',
            'category' => $r['category_name'] ?? '',
            'photo' => $r['photo_path'],
            'thumb' => Photos::thumbOf($r['photo_path']),
            'occasionId' => $r['edition_slug'],
            'edition' => trim($r['edition_name'] . ' ' . ($r['edition_label'] ?? '')),
        ];
    }

    /** Public-safe version: never expose internal IDs, levels or staff position fields. */
    private static function publicShape(array $r): array
    {
        $face = self::shape($r);

        return [
            'id' => $face['id'],
            'type' => $face['type'],
            'period' => $face['period'],
            'headline' => $face['headline'],
            'note' => $face['note'],
            'featured' => $face['featured'],
            'nomineeId' => $face['nomineeId'],
            'name' => $face['name'],
            'department' => $face['department'],
            'category' => $face['category'],
            'photo' => $face['photo'],
            'thumb' => $face['thumb'],
            'occasionId' => $face['occasionId'],
            'edition' => $face['edition'],
        ];
    }


    private const SELECT = "
        SELECT
            f.*,
            COALESCE(n.name, p.name) AS person_name,
            n.department,
            n.level,
            a.position,
            u.name AS unit_name,
            n.photo_path AS nominee_photo,
            p.photo_path AS committee_photo,
            c.name AS category_name,
            e.slug AS edition_slug,
            e.name AS edition_name,
            e.edition_label,
            CASE
                WHEN f.subject_type = 'committee' THEN p.photo_path
                ELSE n.photo_path
            END AS photo_path
        FROM face_of_period f
        JOIN editions e ON e.id = f.edition_id
        LEFT JOIN nominee_entries n
            ON f.subject_type = 'nominee'
            AND n.id = f.nominee_entry_id
            AND n.status = 'approved'
        LEFT JOIN categories c ON c.id = n.category_id
        LEFT JOIN edition_assignments a
            ON f.subject_type = 'committee'
            AND a.id = f.committee_assignment_id
            AND a.edition_id = f.edition_id
            AND a.position <> ''
        LEFT JOIN people p ON p.id = a.person_id
        LEFT JOIN committee_units u ON u.id = a.unit_id
    ";

    private static function validSubject(array $r): bool
    {
        return !empty($r['person_name']);
    }

    /** Public featured person and history. */
    public static function publicView(): array
    {
        $rows = self::pdo()->query(
            self::SELECT . "
            WHERE e.status <> 'draft'
            ORDER BY f.featured DESC, f.id DESC
            LIMIT 60"
        )->fetchAll();

        $all = array_map(
            [self::class, 'publicShape'],
            array_filter($rows, [self::class, 'validSubject'])
        );

        $featured = $all && $all[0]['featured'] ? $all[0] : null;

        return [
            'featured' => $featured,
            'history' => array_values(
                array_filter($all, fn ($x) => $x !== $featured)
            ),
            // the live, self-changing face: pages draw it instead of the hand-picked one when it is on
            'auto' => ['enabled' => Spotlight::enabledNow()],
        ];
    }

    private static function edition(string $slug): array
    {
        $st = self::pdo()->prepare(
            'SELECT id, slug FROM editions WHERE slug = ?'
        );
        $st->execute([$slug]);

        return $st->fetch() ?: self::fail('Unknown edition.', 404);
    }

    public static function list(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('spotlight.view', $slug);
        $ed = self::edition($slug);

        $st = self::pdo()->prepare(
            self::SELECT . ' WHERE f.edition_id = ? ORDER BY f.id DESC'
        );
        $st->execute([$ed['id']]);

        $rows = array_filter($st->fetchAll(), [self::class, 'validSubject']);

        Response::json([
            'ok' => true,
            'faces' => array_map([self::class, 'shape'], $rows),
        ]);
    }

    /** GET /admin/spotlight/settings?edition=: how the live "Face of the moment" is set up, and how many people are in the draw. */
    public static function settings(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('spotlight.view', $slug);
        $ed = self::edition($slug);
        $cfg = Spotlight::settings((int) $ed['id']);
        $all = Spotlight::pool((int) $ed['id'], ['who' => 'both'] + $cfg);
        $nominees = count(array_filter($all, fn ($s) => $s['type'] === 'nominee'));
        Response::json(['ok' => true, 'settings' => $cfg, 'eligible' => ['nominees' => $nominees, 'committee' => count($all) - $nominees]]);
    }

    /** PUT /admin/spotlight/settings */
    public static function saveSettings(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('spotlight.manage', $slug);
        $ed = self::edition($slug);
        [$cfg, $error] = Spotlight::validate($in);
        if ($error !== null) {
            self::fail($error);
        }
        self::pdo()->prepare(
            'INSERT INTO edition_settings (edition_id, spotlight_auto, spotlight_every, spotlight_unit, spotlight_who, spotlight_show_bio, spotlight_require_photo, spotlight_heading, spotlight_tagline, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE spotlight_auto = VALUES(spotlight_auto), spotlight_every = VALUES(spotlight_every), spotlight_unit = VALUES(spotlight_unit),
                spotlight_who = VALUES(spotlight_who), spotlight_show_bio = VALUES(spotlight_show_bio), spotlight_require_photo = VALUES(spotlight_require_photo),
                spotlight_heading = VALUES(spotlight_heading), spotlight_tagline = VALUES(spotlight_tagline), updated_by = VALUES(updated_by)'
        )->execute([$ed['id'], (int) $cfg['enabled'], $cfg['every'], $cfg['unit'], $cfg['who'], (int) $cfg['showBio'], (int) $cfg['requirePhoto'], $cfg['heading'], $cfg['tagline'], $actor['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Updated the live Face of the moment', ($cfg['enabled'] ? 'on' : 'off') . ', every ' . Spotlight::periodWord($cfg) . ', ' . $cfg['who'], (int) $ed['id']);
        Response::json(['ok' => true]);
    }

    private static function fields(array $in, int $editionId): array
    {
        $type = (string) ($in['type'] ?? 'nominee');
        $period = trim((string) ($in['period'] ?? ''));
        $headline = trim((string) ($in['headline'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));

        if (!in_array($type, ['nominee', 'committee'], true)) {
            self::fail('Choose a valid profile type.');
        }

        if (mb_strlen($period) < 2 || mb_strlen($period) > 60) {
            self::fail('Period: 2 to 60 characters.');
        }

        if (mb_strlen($headline) > 120 || mb_strlen($note) > 500) {
            self::fail('Headline: up to 120 characters. Note: up to 500.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $period . $headline . $note)) {
            self::fail('The text has characters that are not allowed.');
        }

        $nomineeId = null;
        $committeeId = null;

        if ($type === 'nominee') {
            $nomineeId = (int) ($in['nomineeId'] ?? 0);

            $st = self::pdo()->prepare(
                "SELECT COUNT(*) FROM nominee_entries
                 WHERE id = ? AND edition_id = ? AND status = 'approved'"
            );
            $st->execute([$nomineeId, $editionId]);

            if (!$nomineeId || (int) $st->fetchColumn() === 0) {
                self::fail('Choose an approved nominee from this edition.');
            }
        } else {
            $committeeId = (int) ($in['committeeMemberId'] ?? 0);

            $st = self::pdo()->prepare(
                "SELECT COUNT(*) FROM edition_assignments
                 WHERE id = ? AND edition_id = ? AND position <> ''"
            );
            $st->execute([$committeeId, $editionId]);

            if (!$committeeId || (int) $st->fetchColumn() === 0) {
                self::fail('Choose a committee member from this edition.');
            }
        }

        return [
            $type,
            $period,
            $headline !== '' ? $headline : null,
            $note !== '' ? $note : null,
            $nomineeId,
            $committeeId,
            !empty($in['featured']) ? 1 : 0,
        ];
    }

    private static function save(?int $id, array $in, array $actor, array $ed): void
    {
        [
            $type,
            $period,
            $headline,
            $note,
            $nomineeId,
            $committeeId,
            $featured
        ] = self::fields($in, (int) $ed['id']);

        try {
            Database::transaction(function (\PDO $db) use (
                &$id, $ed, $type, $period, $headline, $note,
                $nomineeId, $committeeId, $featured, $actor
            ) {
                if ($featured) {
                    $db->prepare(
                        'UPDATE face_of_period SET featured = 0 WHERE edition_id = ?'
                    )->execute([$ed['id']]);
                }

                if ($id === null) {
                    $db->prepare(
                        'INSERT INTO face_of_period
                        (edition_id, subject_type, nominee_entry_id,
                         committee_assignment_id, period_label, headline,
                         note, featured, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $ed['id'], $type, $nomineeId, $committeeId,
                        $period, $headline, $note, $featured, $actor['id']
                    ]);

                    $id = (int) $db->lastInsertId();
                } else {
                    $db->prepare(
                        'UPDATE face_of_period SET
                         subject_type = ?, nominee_entry_id = ?,
                         committee_assignment_id = ?, period_label = ?,
                         headline = ?, note = ?, featured = ?
                         WHERE id = ? AND edition_id = ?'
                    )->execute([
                        $type, $nomineeId, $committeeId, $period,
                        $headline, $note, $featured, $id, $ed['id']
                    ]);
                }
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail(
                    'There is already a Face of the period for “' . $period . '”. Edit that one instead.'
                );
            }
            throw $e;
        }

        Cache::flush();

        Audit::log(
            (int) $actor['id'],
            'Saved a Face of the period',
            $period,
            (int) $ed['id']
        );
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
        $st = self::pdo()->prepare(
            'SELECT f.*, e.slug AS edition_slug
             FROM face_of_period f
             JOIN editions e ON e.id = f.edition_id
             WHERE f.id = ?'
        );
        $st->execute([$id]);

        $f = $st->fetch() ?: self::fail('That entry was not found.', 404);

        return $f + [
            'actor' => Access::require('spotlight.manage', $f['edition_slug'])
        ];
    }

    public static function update(int $id): never
    {
        $f = self::own($id);

        self::save(
            $id,
            Request::json(),
            $f['actor'],
            ['id' => $f['edition_id'], 'slug' => $f['edition_slug']]
        );

        Response::json(['ok' => true]);
    }

    public static function feature(int $id): never
    {
        $f = self::own($id);

        Database::transaction(function (\PDO $db) use ($f, $id) {
            $db->prepare(
                'UPDATE face_of_period SET featured = 0 WHERE edition_id = ?'
            )->execute([$f['edition_id']]);

            $db->prepare(
                'UPDATE face_of_period SET featured = 1 WHERE id = ?'
            )->execute([$id]);
        });

        Cache::flush();

        Audit::log(
            (int) $f['actor']['id'],
            'Featured a Face of the period',
            $f['period_label'],
            (int) $f['edition_id']
        );

        Response::json(['ok' => true]);
    }

    public static function delete(int $id): never
    {
        $f = self::own($id);

        self::pdo()->prepare(
            'DELETE FROM face_of_period WHERE id = ?'
        )->execute([$id]);

        Cache::flush();

        Audit::log(
            (int) $f['actor']['id'],
            'Removed a Face of the period',
            $f['period_label'],
            (int) $f['edition_id']
        );

        Response::json(['ok' => true]);
    }
}
