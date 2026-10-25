<?php
declare(strict_types=1);

namespace App;

/** Nominees in the admin: list, add, edit, approve/reject, delete, and photos. */
final class NomineeApi
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

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
        $st = self::pdo()->prepare('SELECT id, slug FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    /** Loads a nominee AND checks the permission in the nominee's own edition (never the request's). */
    private static function own(int $id, string $perm): array
    {
        $st = self::pdo()->prepare('SELECT n.*, e.slug AS edition_slug FROM nominee_entries n JOIN editions e ON e.id = n.edition_id WHERE n.id = ?');
        $st->execute([$id]);
        $n = $st->fetch() ?: self::fail('That nominee was not found.', 404);
        return $n + ['actor' => Access::require($perm, $n['edition_slug'])];
    }

    private static function shape(array $n, string $edSlug): array
    {
        return [
            'id'            => (string) $n['id'],
            'occasionId'    => $edSlug,
            'categoryId'    => (string) $n['category_id'],
            'name'          => $n['name'],
            'department'    => $n['department'],
            'level'         => $n['level'],
            'phone'         => $n['phone'],
            'email'         => $n['account_email'] ?? '',
            'emailVerified' => !empty($n['email_verified_at']),
            'bio'           => $n['bio'],
            'photo'         => $n['photo_path'],
            'thumb'         => Photos::thumbOf($n['photo_path']),
            'status'        => $n['status'],
            'source'        => $n['account_id'] !== null ? 'registration' : 'admin',
            'createdAt'     => $n['account_id'] !== null ? (new \DateTimeImmutable($n['created_at']))->format('c') : null,
        ];
    }

    public static function list(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('nominees.view', $slug);
        $ed = self::edition($slug);
        $st = self::pdo()->prepare(
            'SELECT n.*, a.email AS account_email, a.email_verified_at
               FROM nominee_entries n LEFT JOIN accounts a ON a.id = n.account_id
              WHERE n.edition_id = ? ORDER BY n.id DESC'
        );
        $st->execute([$ed['id']]);
        Response::json(['ok' => true, 'nominees' => array_map(fn ($n) => self::shape($n, $slug), $st->fetchAll())]);
    }

    /** One nominee in the shape the nominee dashboard uses — for the admin's "View as nominee" preview (read only). */
    public static function preview(int $id): never
    {
        $n = self::own($id, 'nominees.view');
        $st = self::pdo()->prepare('SELECT email, email_verified_at FROM accounts WHERE id = ?');
        $st->execute([$n['account_id'] ?? 0]);
        $acc = $st->fetch() ?: ['email' => '', 'email_verified_at' => null];
        Response::json(['ok' => true, 'nominee' => [
            'id' => (string) $n['id'], 'occasionId' => $n['edition_slug'], 'categoryId' => (string) $n['category_id'],
            'name' => $n['name'], 'phone' => $n['phone'] ?? '', 'department' => $n['department'] ?? '', 'level' => $n['level'] ?? '',
            'bio' => $n['bio'] ?? '', 'photo' => $n['photo_path'], 'thumb' => Photos::thumbOf($n['photo_path']), 'status' => $n['status'],
            'email' => $acc['email'], 'emailVerified' => $acc['email_verified_at'] !== null, 'entries' => [],
        ]]);
    }

    /** Cleans the form fields. Returns [name, department, level, phone, bio, categoryId]. */
    private static function fields(array $in, int $editionId): array
    {
        $name  = Request::str($in, 'name', 150);
        $dept  = Request::str($in, 'department', 150);
        $level = Request::str($in, 'level', 30);
        $phone = Request::str($in, 'phone', 30);
        $bio   = Request::str($in, 'bio', 600);
        if (mb_strlen($name) < 2) {
            self::fail('Enter the nominee’s full name.');
        }
        if ($dept === '') {
            self::fail('Enter the department.');
        }
        if (mb_strlen($bio) > 500) {
            self::fail('The bio can be at most 500 characters.');
        }
        if ($phone !== '' && !preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) {
            self::fail('Enter a valid phone number.');
        }
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND edition_id = ?');
        $st->execute([(int) ($in['categoryId'] ?? 0), $editionId]);
        if ((int) $st->fetchColumn() === 0) {
            self::fail('Choose a category from this edition.');
        }
        return [$name, $dept, $level ?: null, $phone ?: null, $bio ?: null, (int) $in['categoryId']];
    }

    public static function create(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('nominees.create', $slug);
        $ed = self::edition($slug);
        [$name, $dept, $level, $phone, $bio, $cat] = self::fields($in, (int) $ed['id']);

        $status = (string) ($in['status'] ?? 'approved');
        if (!in_array($status, self::STATUSES, true)) {
            self::fail('Choose a status.');
        }
        if ($status !== 'pending' && !Access::can($actor, 'nominees.approve', (int) $ed['id'])) {
            self::fail('You can add nominees as “Pending”, but approving or rejecting needs the approval permission.', 403);
        }
        self::pdo()->prepare('INSERT INTO nominee_entries (edition_id, category_id, name, department, level, phone, bio, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ed['id'], $cat, $name, $dept, $level, $phone, $bio, $status]);
        $id = (string) self::pdo()->lastInsertId();
        Cache::flush();
        Audit::log((int) $actor['id'], 'Added nominee', $name, (int) $ed['id']);
        Response::json(['ok' => true, 'nomineeId' => $id]);
    }

    public static function update(int $id): never
    {
        $in = Request::json();
        $n = self::own($id, 'nominees.edit');
        [$name, $dept, $level, $phone, $bio, $cat] = self::fields($in + ['level' => $n['level'], 'phone' => $n['phone']], (int) $n['edition_id']);

        $status = (string) ($in['status'] ?? $n['status']);
        if (!in_array($status, self::STATUSES, true)) {
            self::fail('Choose a status.');
        }
        if ($status !== $n['status'] && !Access::can($n['actor'], 'nominees.approve', (int) $n['edition_id'])) {
            self::fail('Changing a nominee’s status needs the approval permission.', 403);
        }
        self::pdo()->prepare('UPDATE nominee_entries SET name = ?, department = ?, level = ?, phone = ?, bio = ?, category_id = ?, status = ? WHERE id = ?')
            ->execute([$name, $dept, $level, $phone, $bio, $cat, $status, $id]);
        Cache::flush();
        Audit::log((int) $n['actor']['id'], 'Edited nominee', $name, (int) $n['edition_id']);
        Response::json(['ok' => true]);
    }

    /** Approve / reject one or many. All of them must be in editions where the actor may approve. */
    public static function setStatus(): never
    {
        $in = Request::json();
        $status = (string) ($in['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            self::fail('Choose a status.');
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($in['ids'] ?? []))));
        if ($ids === [] || count($ids) > 500) {
            self::fail('Choose between 1 and 500 nominees.');
        }
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = self::own($id, 'nominees.approve');         // refuses everything if any one is not allowed
        }
        Database::transaction(function (\PDO $db) use ($ids, $status) {
            $st = $db->prepare('UPDATE nominee_entries SET status = ? WHERE id = ?');
            foreach ($ids as $id) {
                $st->execute([$status, $id]);
            }
        });
        Cache::flush();
        $verb = $status === 'approved' ? 'Approved' : ($status === 'rejected' ? 'Rejected' : 'Set to pending');
        if (count($rows) === 1) {
            Audit::log((int) $rows[0]['actor']['id'], $verb . ' nominee', $rows[0]['name'], (int) $rows[0]['edition_id']);
        } else {
            Audit::log((int) $rows[0]['actor']['id'], "$verb " . count($rows) . ' nominees', 'bulk action', (int) $rows[0]['edition_id']);
        }
        Response::json(['ok' => true, 'updated' => count($rows)]);
    }

    public static function delete(int $id): never
    {
        $n = self::own($id, 'nominees.delete');
        $st = self::pdo()->prepare('SELECT (SELECT COUNT(*) FROM vote_orders WHERE nominee_entry_id = ?) + (SELECT COUNT(*) FROM winners WHERE nominee_entry_id = ?)');
        $st->execute([$id, $id]);
        if ((int) $st->fetchColumn() > 0) {
            self::fail('This nominee has votes or a result on record. Reject them instead so history is kept.');
        }
        self::pdo()->prepare('DELETE FROM nominee_entries WHERE id = ?')->execute([$id]);
        Photos::delete($n['photo_path']);
        Cache::flush();
        Audit::log((int) $n['actor']['id'], 'Deleted nominee', $n['name'], (int) $n['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function uploadPhoto(int $id): never
    {
        $n = self::own($id, 'nominees.edit');
        $path = Photos::save($_FILES['photo'] ?? [], 'nominees');
        self::pdo()->prepare('UPDATE nominee_entries SET photo_path = ? WHERE id = ?')->execute([$path, $id]);
        Photos::delete($n['photo_path']);
        Cache::flush();
        Audit::log((int) $n['actor']['id'], 'Changed nominee photo', $n['name'], (int) $n['edition_id']);
        Response::json(['ok' => true, 'photo' => $path, 'thumb' => Photos::thumbOf($path)]);
    }

    public static function removePhoto(int $id): never
    {
        $n = self::own($id, 'nominees.edit');
        self::pdo()->prepare('UPDATE nominee_entries SET photo_path = NULL WHERE id = ?')->execute([$id]);
        Photos::delete($n['photo_path']);
        Cache::flush();
        Audit::log((int) $n['actor']['id'], 'Removed nominee photo', $n['name'], (int) $n['edition_id']);
        Response::json(['ok' => true]);
    }
}