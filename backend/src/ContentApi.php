<?php
declare(strict_types=1);

namespace App;

/** Categories and special occasions of an edition. */
final class ContentApi
{
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function editionBySlug(string $slug): array
    {
        $st = self::pdo()->prepare('SELECT id, slug, name, edition_label FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    private static function link(string $v): bool
    {
        return $v === '' || preg_match('#^https?://\S+$#', $v) === 1 || preg_match('/^[a-z0-9_-]+\.html(\?\S*)?$/i', $v) === 1;
    }

    // ------------------------------------------------------------ categories

    public static function listCategories(): never
    {
        $slug = (string) ($_GET['edition'] ?? '');
        Access::require('categories.view', $slug);
        $ed = self::editionBySlug($slug);
        $st = self::pdo()->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM nominee_entries n WHERE n.category_id = c.id) AS nominee_count
               FROM categories c WHERE c.edition_id = ? ORDER BY c.sort_order, c.id'
        );
        $st->execute([$ed['id']]);
        Response::json(['ok' => true, 'categories' => array_map(fn ($c) => [
            'id'          => (string) $c['id'],
            'occasionId'  => $slug,
            'name'        => $c['name'],
            'description' => $c['description'],
            'icon'        => $c['icon'],
            'order'       => (int) $c['sort_order'],
            'active'      => (bool) $c['active'],
            'nominees'    => (int) $c['nominee_count'],
        ], $st->fetchAll())]);
    }

    /** Validates and cleans the fields of a category form. */
    private static function categoryFields(array $in): array
    {
        $name = Request::str($in, 'name', 60);
        $desc = Request::str($in, 'description', 160);
        $icon = Request::str($in, 'icon', 60) ?: 'fa-award';
        $order = $in['order'] ?? null;
        if (mb_strlen($name) < 2) {
            self::fail('Give the category a name (at least 2 characters).');
        }
        if ($desc === '') {
            self::fail('Add a short description.');
        }
        if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
            self::fail('Use a Font Awesome name like fa-trophy.');
        }
        if (!is_numeric($order) || (int) $order != $order || (int) $order < 0) {
            self::fail('Display order must be a whole number, 0 or more.');
        }
        return [$name, $desc, $icon, (int) $order, !empty($in['active']) ? 1 : 0];
    }

    public static function createCategory(): never
    {
        $in = Request::json();
        $slug = Request::str($in, 'edition', 60);
        $actor = Access::require('categories.manage', $slug);
        $ed = self::editionBySlug($slug);
        [$name, $desc, $icon, $order, $active] = self::categoryFields($in);
        try {
            self::pdo()->prepare('INSERT INTO categories (edition_id, name, description, icon, sort_order, active) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$ed['id'], $name, $desc, $icon, $order, $active]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('A category with that name already exists in this edition.');
            }
            throw $e;
        }
        $id = (string) self::pdo()->lastInsertId();      // read it BEFORE the audit insert changes it
        Cache::flush();
        Audit::log((int) $actor['id'], 'Added category', $name, (int) $ed['id']);
        Response::json(['ok' => true, 'categoryId' => $id]);
    }

    /** Loads a category AND checks the permission in the category's own edition (never trust the request's edition). */
    private static function ownCategory(int $id, string $perm): array
    {
        $st = self::pdo()->prepare('SELECT c.*, e.slug AS edition_slug FROM categories c JOIN editions e ON e.id = c.edition_id WHERE c.id = ?');
        $st->execute([$id]);
        $c = $st->fetch() ?: self::fail('That category was not found.', 404);
        $actor = Access::require($perm, $c['edition_slug']);
        return $c + ['actor' => $actor];
    }

    public static function updateCategory(int $id): never
    {
        $in = Request::json();
        $c = self::ownCategory($id, 'categories.manage');
        [$name, $desc, $icon, $order, $active] = self::categoryFields($in);
        try {
            self::pdo()->prepare('UPDATE categories SET name = ?, description = ?, icon = ?, sort_order = ?, active = ? WHERE id = ?')
                ->execute([$name, $desc, $icon, $order, $active, $id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('A category with that name already exists in this edition.');
            }
            throw $e;
        }
        Cache::flush();
        Audit::log((int) $c['actor']['id'], 'Edited category', $name, (int) $c['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function deleteCategory(int $id): never
    {
        $c = self::ownCategory($id, 'categories.manage');
        $count = function (string $sql) use ($id): int {
            $st = self::pdo()->prepare($sql);
            $st->execute([$id]);
            return (int) $st->fetchColumn();
        };
        $n = $count('SELECT COUNT(*) FROM nominee_entries WHERE category_id = ?');
        if ($n > 0) {
            self::fail("“{$c['name']}” still has $n nominee" . ($n === 1 ? '' : 's') . '. Move or remove them first, or hide the category instead.');
        }
        if ($count('SELECT COUNT(*) FROM vote_orders WHERE category_id = ?') + $count('SELECT COUNT(*) FROM winners WHERE category_id = ?') > 0) {
            self::fail('This category has vote or result records. Hide it instead so history is kept.');
        }
        self::pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        Cache::flush();
        Audit::log((int) $c['actor']['id'], 'Deleted category', $c['name'], (int) $c['edition_id']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------- occasions

    /** @return array{0:array,1:array} cleaned occasion fields */
    private static function occasionFields(array $o): array
    {
        $name = Request::str($o, 'name', 60);
        $tagline = Request::str($o, 'tagline', 120);
        $desc = Request::str($o, 'description', 1000);
        $icon = Request::str($o, 'icon', 60) ?: 'fa-star';
        $linkHref = Request::str((array) ($o['link'] ?? []), 'href', 255);
        $linkLabel = Request::str((array) ($o['link'] ?? []), 'label', 100) ?: 'Learn more';
        if (mb_strlen($name) < 2) {
            self::fail('Give the occasion a name (at least 2 characters).');
        }
        if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
            self::fail('Use a Font Awesome name like fa-crown.');
        }
        if (!self::link($linkHref)) {
            self::fail('Use a page like occasion.html?id=x or a full https:// address for the card link.');
        }
        $actions = [];
        foreach (array_slice((array) ($o['actions'] ?? []), 0, 3) as $a) {
            $label = Request::str((array) $a, 'label', 40);
            $href = Request::str((array) $a, 'href', 255);
            if ($label === '' && $href === '') {
                continue;
            }
            if ($label === '' || $href === '' || !self::link($href)) {
                self::fail('Use a page like contact.html or a full https:// address for the button.');
            }
            $actions[] = ['label' => $label, 'href' => $href, 'variant' => 'gold'];
        }
        $eventAt = null;
        if (!empty($o['timeline']['ceremony'])) {
            try {
                $eventAt = (new \DateTimeImmutable((string) $o['timeline']['ceremony']))->setTimezone(new \DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                self::fail('Enter a valid event date.');
            }
        }
        return [[$name, $tagline ?: null, $desc ?: null, $icon, !empty($o['enabled']) ? 1 : 0, $eventAt, json_encode($actions)], [$linkHref, $linkLabel]];
    }

    private static function occasion(string $edSlug, string $slug): array
    {
        $ed = self::editionBySlug($edSlug);
        $st = self::pdo()->prepare('SELECT * FROM occasions WHERE edition_id = ? AND slug = ?');
        $st->execute([$ed['id'], $slug]);
        return ($st->fetch() ?: self::fail('That occasion was not found.', 404)) + ['edition' => $ed];
    }

    public static function createOccasion(): never
    {
        $in = Request::json();
        $edSlug = Request::str($in, 'edition', 60);
        $actor = Access::require('occasions.manage', $edSlug);
        $ed = self::editionBySlug($edSlug);
        [$f, $link] = self::occasionFields((array) ($in['occasion'] ?? []));

        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($f[0])), '-') ?: 'occasion';
        $taken = self::pdo()->prepare('SELECT COUNT(*) FROM occasions WHERE slug = ?');   // unique across all editions, like V2
        $slug = $base;
        while (true) {
            $taken->execute([$slug]);
            if ((int) $taken->fetchColumn() === 0) {
                break;
            }
            $slug = $base . '-' . substr(bin2hex(random_bytes(2)), 0, 3);
        }
        $href = $link[0] !== '' ? $link[0] : "occasion.html?id=$slug";
        self::pdo()->prepare('INSERT INTO occasions (edition_id, slug, type, name, tagline, description, icon, enabled, event_at, actions, link_href, link_label)
                              VALUES (?, ?, \'special\', ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ed['id'], $slug, $f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $href, $link[1]]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Added occasion', $f[0], (int) $ed['id']);
        Response::json(['ok' => true, 'occasionId' => $slug]);
    }

    public static function updateOccasion(string $slug): never
    {
        $in = Request::json();
        $edSlug = Request::str($in, 'edition', 60);
        $actor = Access::require('occasions.manage', $edSlug);
        $o = self::occasion($edSlug, $slug);
        [$f, $link] = self::occasionFields((array) ($in['occasion'] ?? []));
        $href = $link[0] !== '' ? $link[0] : "occasion.html?id=$slug";
        self::pdo()->prepare('UPDATE occasions SET name = ?, tagline = ?, description = ?, icon = ?, enabled = ?, event_at = ?, actions = ?, link_href = ?, link_label = ? WHERE id = ?')
            ->execute([$f[0], $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $href, $link[1], $o['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Edited occasion', $f[0], (int) $o['edition']['id']);
        Response::json(['ok' => true]);
    }

    public static function toggleOccasion(string $slug): never
    {
        $in = Request::json();
        $edSlug = Request::str($in, 'edition', 60);
        $actor = Access::require('occasions.manage', $edSlug);
        $o = self::occasion($edSlug, $slug);
        $now = $o['enabled'] ? 0 : 1;
        self::pdo()->prepare('UPDATE occasions SET enabled = ? WHERE id = ?')->execute([$now, $o['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], $now ? 'Published occasion' : 'Hid occasion', $o['name'], (int) $o['edition']['id']);
        Response::json(['ok' => true, 'enabled' => (bool) $now]);
    }

    public static function deleteOccasion(string $slug): never
    {
        $in = Request::json();
        $edSlug = Request::str($in, 'edition', 60);
        $actor = Access::require('occasions.manage', $edSlug);
        $o = self::occasion($edSlug, $slug);
        try {
            self::pdo()->prepare('DELETE FROM occasions WHERE id = ?')->execute([$o['id']]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                self::fail('Ticket types are linked to this occasion. Hide it instead.');
            }
            throw $e;
        }
        Cache::flush();
        Audit::log((int) $actor['id'], 'Deleted occasion', $o['name'], (int) $o['edition']['id']);
        Response::json(['ok' => true]);
    }
}