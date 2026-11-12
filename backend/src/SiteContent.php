<?php
declare(strict_types=1);

namespace App;

/**
 * Phase 16: the content manager. Text that used to live only in /data/*.json can be edited by an admin.
 * Until a page is edited the file is used; "reset" deletes the saved copy and the file shows again.
 */
final class SiteContent
{
    /** Editable names. "home" is the home-page wording per state, stored inside content.json's "home". */
    public const NAMES = ['faqs' => 'FAQs', 'about' => 'About page', 'legal' => 'Terms and privacy', 'gallery' => 'Gallery', 'home' => 'Home page wording'];
    private const STATES = ['landing', 'nominations', 'voting', 'voting-closed', 'results'];
    private const HOME_KEYS = ['kicker' => 80, 'title' => 120, 'highlight' => 80, 'lead' => 400, 'sectionTitle' => 100, 'sectionLead' => 300];

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    /** The saved copy of a public file (about, content, faqs, gallery, legal), or null. */
    public static function stored(string $file): ?array
    {
        try {
            $st = self::pdo()->prepare('SELECT content FROM site_content WHERE name = ?');
            $st->execute([$file]);
            $json = $st->fetchColumn();
        } catch (\Throwable) {
            return null;                    // table not there yet (before the migration): use the files
        }
        if ($json === false) {
            return null;
        }
        $v = json_decode((string) $json, true);
        return is_array($v) ? ['value' => $v] : null;
    }

    private static function fileName(string $name): string
    {
        return $name === 'home' ? 'content' : $name;
    }

    private static function original(string $file): mixed
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/' . $file . '.json'), true);
    }

    private static function effective(string $name): mixed
    {
        $file = self::fileName($name);
        $v = self::stored($file)['value'] ?? self::original($file);
        return $name === 'home' ? ($v['home'] ?? []) : $v;
    }

    private static function checkName(string $name): void
    {
        if (!isset(self::NAMES[$name])) {
            self::fail('Unknown page.', 404);
        }
    }

    // ------------------------------------------------------------ validation

    private static function text(mixed $v, int $max, string $label, bool $required = false): string
    {
        $s = trim((string) $v);
        if ($required && $s === '') {
            self::fail("$label is required.");
        }
        if (mb_strlen($s) > $max) {
            self::fail("$label can be at most $max characters.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s)) {
            self::fail("$label has characters that are not allowed.");
        }
        return $s;
    }

    private static function icon(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s !== '' && !preg_match('/^fa-[a-z0-9-]{1,40}$/', $s)) {
            self::fail('An icon must be a Font Awesome name such as fa-star.');
        }
        return $s;
    }

    private static function faqs(array $in): array
    {
        $items = array_values((array) ($in['items'] ?? []));
        if (count($items) > 100) {
            self::fail('At most 100 questions.');
        }
        $out = [];
        $ids = [];
        foreach ($items as $i => $it) {
            $n = $i + 1;
            $id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($it['id'] ?? '')) ?: 'f' . $n;
            while (isset($ids[$id])) {
                $id .= 'x';
            }
            $ids[$id] = true;
            $out[] = [
                'id'    => mb_substr($id, 0, 20),
                'group' => self::text($it['group'] ?? '', 40, "Question $n group") ?: 'General',
                'q'     => self::text($it['q'] ?? '', 200, "Question $n", true),
                'a'     => self::text($it['a'] ?? '', 2000, "Answer to question $n", true),
            ];
        }
        return $out;
    }

    private static function legalPage(array $p, string $label): array
    {
        $updated = trim((string) ($p['updated'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $updated);
        if (!$d || $d->format('Y-m-d') !== $updated) {
            self::fail("$label: the “updated” date must look like 2026-10-17.");
        }
        $sections = array_values((array) ($p['sections'] ?? []));
        if ($sections === [] || count($sections) > 30) {
            self::fail("$label: add between 1 and 30 sections.");
        }
        return [
            'title'    => self::text($p['title'] ?? '', 100, "$label title", true),
            'updated'  => $updated,
            'draft'    => !empty($p['draft']),
            'sections' => array_map(fn ($s, $i) => [
                'heading' => self::text($s['heading'] ?? '', 120, "$label section " . ($i + 1) . ' heading', true),
                'body'    => self::text($s['body'] ?? '', 4000, "$label section " . ($i + 1) . ' text', true),
            ], $sections, array_keys($sections)),
        ];
    }

    private static function legal(array $in): array
    {
        $current = self::effective('legal');
        $out = [];
        foreach (['privacy' => 'Privacy policy', 'terms' => 'Terms of use'] as $k => $label) {
            $out[$k] = isset($in[$k]) ? self::legalPage((array) $in[$k], $label) : ($current[$k] ?? self::fail("$label is missing."));
        }
        return $out;
    }

    private static function about(array $in): array
    {
        $story = array_values((array) ($in['story'] ?? []));
        $values = array_values((array) ($in['values'] ?? []));
        if (count($story) > 6 || count($values) > 8) {
            self::fail('At most 6 story paragraphs and 8 values.');
        }
        return [
            'kicker'      => self::text($in['kicker'] ?? '', 60, 'Small heading'),
            'title'       => self::text($in['title'] ?? '', 150, 'Title', true),
            'lead'        => self::text($in['lead'] ?? '', 400, 'Introduction'),
            'story'       => array_map(fn ($s, $i) => self::text($s, 1200, 'Story paragraph ' . ($i + 1), true), $story, array_keys($story)),
            'valuesTitle' => self::text($in['valuesTitle'] ?? '', 80, 'Values heading'),
            'values'      => array_map(fn ($v, $i) => [
                'icon'  => self::icon($v['icon'] ?? '') ?: 'fa-star',
                'title' => self::text($v['title'] ?? '', 60, 'Value ' . ($i + 1) . ' title', true),
                'text'  => self::text($v['text'] ?? '', 200, 'Value ' . ($i + 1) . ' text'),
            ], $values, array_keys($values)),
        ];
    }

    private static function gallery(array $in): array
    {
        $items = array_values((array) ($in['items'] ?? []));
        if (count($items) > 200) {
            self::fail('At most 200 gallery items.');
        }
        $out = [];
        foreach ($items as $i => $g) {
            $n = $i + 1;
            $src = trim((string) ($g['src'] ?? ''));
            if ($src !== '' && !preg_match('#^uploads/gallery/[a-f0-9]{2}/[a-f0-9]+\.webp$#', $src)) {
                self::fail("Gallery item $n: use the Upload button for the picture.");
            }
            $out[] = [
                'id'      => 'g' . $n,
                'title'   => self::text($g['title'] ?? '', 80, "Gallery item $n title", true),
                'caption' => self::text($g['caption'] ?? '', 200, "Gallery item $n caption"),
                'edition' => self::text($g['edition'] ?? '', 60, "Gallery item $n edition") ?: 'Gallery',
                'icon'    => self::icon($g['icon'] ?? '') ?: 'fa-image',
                'src'     => $src,
            ];
        }
        return $out;
    }

    /** Home wording: only the plain text keys of each state are editable; buttons and highlights stay as they are. */
    private static function home(array $in): array
    {
        $whole = self::stored('content')['value'] ?? self::original('content');
        foreach ((array) ($in['states'] ?? []) as $state => $vals) {
            if (!in_array($state, self::STATES, true) || !is_array($vals)) {
                self::fail('Unknown home page state.');
            }
            foreach (self::HOME_KEYS as $k => $max) {
                if (array_key_exists($k, $vals)) {
                    $whole['home'][$state][$k] = self::text($vals[$k], $max, ucfirst($k), in_array($k, ['title', 'lead'], true));
                }
            }
        }
        return $whole;
    }

    // ------------------------------------------------------------ admin routes

    private static function actor(string $perm): array
    {
        $slug = (string) ($_GET['edition'] ?? (Request::json()['edition'] ?? ''));
        return Access::require($perm, $slug);
    }

    public static function index(): never
    {
        self::actor('content.view');
        $saved = self::pdo()->query('SELECT name, updated_at FROM site_content')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (self::NAMES as $name => $label) {
            $at = $saved[self::fileName($name)] ?? null;
            $out[] = ['name' => $name, 'label' => $label, 'customised' => $at !== null, 'updatedAt' => $at ? (new \DateTimeImmutable($at))->format('c') : null];
        }
        Response::json(['ok' => true, 'pages' => $out]);
    }

    public static function read(string $name): never
    {
        self::checkName($name);
        self::actor('content.view');
        Response::json(['ok' => true, 'name' => $name, 'content' => self::effective($name)]);
    }

    public static function save(string $name): never
    {
        self::checkName($name);
        $actor = self::actor('content.edit');
        $in = Request::json();
        $file = self::fileName($name);
        $value = match ($name) {
            'faqs'    => self::faqs($in),
            'about'   => self::about($in),
            'legal'   => self::legal($in),
            'gallery' => self::gallery($in),
            'home'    => self::home($in),
        };
        self::pdo()->prepare('INSERT INTO site_content (name, content, updated_by) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)')
            ->execute([$file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actor['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Edited site content', self::NAMES[$name], (int) $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    public static function reset(string $name): never
    {
        self::checkName($name);
        $actor = self::actor('content.edit');
        self::pdo()->prepare('DELETE FROM site_content WHERE name = ?')->execute([self::fileName($name)]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Reset site content to the original', self::NAMES[$name], (int) $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    /** One gallery picture. Returns the saved path to put in the gallery item. */
    public static function galleryImage(): never
    {
        $actor = self::actor('content.edit');
        $path = Photos::save($_FILES['image'] ?? [], 'gallery');
        Audit::log((int) $actor['id'], 'Uploaded a gallery picture', $path, (int) $actor['edition_id']);
        Response::json(['ok' => true, 'path' => $path]);
    }
}