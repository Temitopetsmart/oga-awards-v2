<?php
declare(strict_types=1);

namespace App;

/** The public contact form, and the admin inbox for it. */
final class MessagesApi
{
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function throttle(string $kind, int $max, int $minutes): void
    {
        $id = hash('sha256', "$kind:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many messages from this connection. Please wait a little and try again.', 429);
        }
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, 1)')->execute([$id, Request::ip()]);
    }

    // ------------------------------------------------------------------ public

    public static function submit(): never
    {
        self::throttle('contact', 10, 60);
        $in = Request::json();
        if (Request::str($in, 'website', 100) !== '') {              // a hidden field only bots fill in
            Response::json(['ok' => true]);
        }
        $name    = Request::str($in, 'name', 150);
        $email   = strtolower(Request::str($in, 'email', 190));
        $subject = Request::str($in, 'subject', 200);
        $message = Request::str($in, 'message', 5000);
        if (mb_strlen($name) < 2) { self::fail('Enter your name.'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { self::fail('Enter a valid email address.'); }
        if ($subject === '') { self::fail('Enter a subject.'); }
        if (mb_strlen($message) < 20) { self::fail('Your message must contain at least 20 characters.'); }

        $st = self::pdo()->prepare("SELECT id FROM editions WHERE slug = ? AND status <> 'draft'");
        $st->execute([Request::str($in, 'editionId', 60)]);
        $edId = $st->fetchColumn() ?: null;

        self::pdo()->prepare('INSERT INTO contact_messages (edition_id, name, email, subject, message) VALUES (?, ?, ?, ?, ?)')
            ->execute([$edId, $name, $email, $subject, $message]);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ admin

    private static function shape(array $m): array
    {
        return [
            'id'        => (string) $m['id'],
            'name'      => $m['name'],
            'email'     => $m['email'],
            'subject'   => $m['subject'],
            'message'   => $m['message'],
            'read'      => (bool) $m['is_read'],
            'createdAt' => (new \DateTimeImmutable($m['created_at']))->format('c'),
        ];
    }

    /**
     * Messages of the edition. With ?page= they are paged on the server (filter: status=read|unread; search: q;
     * sort: name, subject, status, createdAt). Without it the newest 2000 are returned, as before.
     */
    public static function list(): never
    {
        $slug  = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('messages.view', $slug);
        header('Cache-Control: no-store');

        if (!Paging::wanted()) {
            $st = self::pdo()->prepare('SELECT * FROM contact_messages WHERE edition_id = ? ORDER BY id DESC LIMIT 2000');
            $st->execute([$actor['edition_id']]);
            Response::json(['ok' => true, 'messages' => array_map(fn ($m) => self::shape($m), $st->fetchAll())]);
        }

        $where = ['edition_id = ?'];
        $args = [$actor['edition_id']];
        $status = Paging::filter('status');
        if ($status === 'read' || $status === 'unread') {
            $where[] = 'is_read = ?';
            $args[] = $status === 'read' ? 1 : 0;
        }
        if (($q = Paging::q()) !== '') {
            $where[] = Paging::search(['name', 'email', 'subject', 'message'], $q, $args);
        }
        $order = Paging::order(['name' => 'name', 'subject' => 'subject', 'status' => 'is_read', 'createdAt' => 'id'], 'id DESC', 'id DESC');
        $r = Paging::run(self::pdo(), '*', 'contact_messages', $where, $args, $order);
        Response::json(['ok' => true, 'messages' => array_map(fn ($m) => self::shape($m), $r['rows'])] + Paging::meta($r));
    }
    
    /** Loads a message and checks the permission in the message's own edition. */
    private static function load(int $id, array $in): array
    {
        $st = self::pdo()->prepare('SELECT m.*, e.slug AS edition_slug FROM contact_messages m LEFT JOIN editions e ON e.id = m.edition_id WHERE m.id = ?');
        $st->execute([$id]);
        $m = $st->fetch() ?: self::fail('That message was not found.', 404);
        $slug = $m['edition_slug'] ?? Request::str($in, 'edition', 60);      // a message with no edition: use the one being viewed
        return $m + ['actor' => Access::require('messages.manage', $slug)];
    }

    public static function update(int $id): never
    {
        $in = Request::json();
        $m  = self::load($id, $in);
        self::pdo()->prepare('UPDATE contact_messages SET is_read = ? WHERE id = ?')->execute([(bool) ($in['read'] ?? false) ? 1 : 0, $id]);
        Response::json(['ok' => true]);
    }

    public static function delete(int $id): never
    {
        $m = self::load($id, Request::json());
        self::pdo()->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);
        Audit::log((int) $m['actor']['id'], 'Deleted message', $m['subject'], $m['edition_id'] === null ? null : (int) $m['edition_id']);
        Response::json(['ok' => true]);
    }
}