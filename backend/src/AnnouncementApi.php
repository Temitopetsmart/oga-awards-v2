<?php
declare(strict_types=1);

namespace App;

/** Phase 14: announcements. Public ones show on the site; "nominees" ones only inside a signed-in nominee's dashboard. */
final class AnnouncementApi
{
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
        return $st->fetch() ?: self::fail('Unknown edition.', 404);
    }

    private static function iso(?string $v): ?string
    {
        return $v ? (new \DateTimeImmutable($v))->format('c') : null;
    }

    /** What visitors may see right now, newest and pinned first. $audiences: ['public'] or ['public', 'nominees']. */
    public static function visible(array $audiences): array
    {
        $marks = implode(',', array_fill(0, count($audiences), '?'));
        $st = self::pdo()->prepare(
            "SELECT a.id, a.title, a.body, a.pinned, a.audience, COALESCE(a.publish_at, a.created_at) AS at
               FROM announcements a JOIN editions e ON e.id = a.edition_id
              WHERE e.status = 'active' AND a.status = 'published' AND a.audience IN ($marks)
                AND (a.publish_at IS NULL OR a.publish_at <= NOW()) AND (a.expires_at IS NULL OR a.expires_at > NOW())
              ORDER BY a.pinned DESC, COALESCE(a.publish_at, a.created_at) DESC, a.id DESC LIMIT 50"
        );
        $st->execute($audiences);
        return array_map(fn ($r) => [
            'id' => (string) $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'pinned' => (bool) $r['pinned'], 'at' => self::iso($r['at']),
        ], $st->fetchAll());
    }

    public static function publicList(): array
    {
        return self::visible(['public']);
    }

    // ------------------------------------------------------------ admin

    private static function row(array $r): array
    {
        $now = time();
        $live = $r['status'] === 'published' && ($r['publish_at'] === null || strtotime($r['publish_at']) <= $now) && ($r['expires_at'] === null || strtotime($r['expires_at']) > $now);
        return [
            'id' => (string) $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'audience' => $r['audience'], 'pinned' => (bool) $r['pinned'],
            'status' => $r['status'], 'publishAt' => self::iso($r['publish_at']), 'expiresAt' => self::iso($r['expires_at']),
            'live' => $live, 'createdAt' => self::iso($r['created_at']),
        ];
    }

    public static function list(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('announcements.view', $slug);
        $ed = self::edition($slug);
        $st = self::pdo()->prepare('SELECT * FROM announcements WHERE edition_id = ? ORDER BY pinned DESC, id DESC LIMIT 500');
        $st->execute([$ed['id']]);
        Response::json(['ok' => true, 'announcements' => array_map([self::class, 'row'], $st->fetchAll())]);
    }

    /** Checks what the admin sent. Returns the values ready to store. */
    private static function fields(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            self::fail('Title: 3 to 150 characters.');
        }
        if ($body === '' || mb_strlen($body) > 2000) {
            self::fail('Message: 1 to 2000 characters.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title . $body)) {
            self::fail('The text has characters that are not allowed.');
        }
        $audience = (string) ($in['audience'] ?? 'public');
        $status = (string) ($in['status'] ?? 'published');
        if (!in_array($audience, ['public', 'nominees'], true) || !in_array($status, ['draft', 'published'], true)) {
            self::fail('Choose who it is for and whether it is published.');
        }
        $when = function (string $key, string $label) use ($in): ?string {
            $v = trim((string) ($in[$key] ?? ''));
            if ($v === '') {
                return null;
            }
            $ts = strtotime($v);
            if ($ts === false) {
                self::fail("$label is not a valid date and time.");
            }
            return date('Y-m-d H:i:s', $ts);
        };
        $publishAt = $when('publishAt', 'Show from');
        $expiresAt = $when('expiresAt', 'Hide after');
        if ($publishAt !== null && $expiresAt !== null && $expiresAt <= $publishAt) {
            self::fail('“Hide after” must be later than “Show from”.');
        }
        return [$title, $body, $audience, !empty($in['pinned']) ? 1 : 0, $status, $publishAt, $expiresAt];
    }

    public static function create(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('announcements.manage', $slug);
        $ed = self::edition($slug);
        [$title, $body, $audience, $pinned, $status, $pub, $exp] = self::fields($in);
        self::pdo()->prepare('INSERT INTO announcements (edition_id, title, body, audience, pinned, status, publish_at, expires_at, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ed['id'], $title, $body, $audience, $pinned, $status, $pub, $exp, $actor['id'], $actor['id']]);
        $id = (string) self::pdo()->lastInsertId();
        Cache::flush();
        Audit::log((int) $actor['id'], 'Added an announcement', $title, (int) $ed['id']);
        Response::json(['ok' => true, 'id' => $id]);
    }

    private static function own(int $id): array
    {
        $st = self::pdo()->prepare('SELECT a.*, e.slug AS edition_slug FROM announcements a JOIN editions e ON e.id = a.edition_id WHERE a.id = ?');
        $st->execute([$id]);
        $a = $st->fetch() ?: self::fail('That announcement was not found.', 404);
        return $a + ['actor' => Access::require('announcements.manage', $a['edition_slug'])];
    }

    public static function update(int $id): never
    {
        $a = self::own($id);
        [$title, $body, $audience, $pinned, $status, $pub, $exp] = self::fields(Request::json());
        self::pdo()->prepare('UPDATE announcements SET title = ?, body = ?, audience = ?, pinned = ?, status = ?, publish_at = ?, expires_at = ?, updated_by = ? WHERE id = ?')
            ->execute([$title, $body, $audience, $pinned, $status, $pub, $exp, $a['actor']['id'], $id]);
        Cache::flush();
        Audit::log((int) $a['actor']['id'], 'Edited an announcement', $title, (int) $a['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function delete(int $id): never
    {
        $a = self::own($id);
        self::pdo()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        Cache::flush();
        Audit::log((int) $a['actor']['id'], 'Deleted an announcement', $a['title'], (int) $a['edition_id']);
        Response::json(['ok' => true]);
    }
}