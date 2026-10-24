<?php
declare(strict_types=1);

namespace App;

/**
 * Public, read-only data. Every method returns the SAME JSON shape the V2 frontend
 * already reads from /data/<name>.json, so the frontend needs no code changes.
 */
final class Catalog
{
    private static function db(): \PDO
    {
        return Database::pdo();
    }

    private static function num(mixed $v): int|float|null
    {
        if ($v === null) {
            return null;
        }
        $f = (float) $v;
        return floor($f) === $f ? (int) $f : $f;
    }

    private static function iso(?string $dt): ?string
    {
        return $dt === null ? null : (new \DateTimeImmutable($dt))->format('c');
    }

    private static function jsonCol(?string $v, mixed $default): mixed
    {
        return $v === null || $v === '' ? $default : (json_decode($v, true) ?? $default);
    }

        public static function editions(bool $includeDrafts = false): array
    {
        
        $rows = self::db()->query(
            'SELECT e.*,
                s.price_per_vote,
                s.max_votes_per_payment,
                s.max_paid_payments_per_email_per_category,
                s.nominee_registration_fee
            FROM editions e
            LEFT JOIN edition_settings s ON s.edition_id = e.id
            ORDER BY e.year DESC, e.id DESC;'
        )->fetchAll();


        $occ = [];
        foreach (self::db()->query('SELECT * FROM occasions ORDER BY id')->fetchAll() as $o) {
            $occ[$o['edition_id']][] = [
                'id'          => $o['slug'],
                'type'        => $o['type'],
                'name'        => $o['name'],
                'tagline'     => $o['tagline'],
                'icon'        => $o['icon'],
                'enabled'     => (bool) $o['enabled'],
                'link'        => $o['link_href'] ? ['label' => $o['link_label'], 'href' => $o['link_href']] : null,
                'description' => $o['description'],
                'actions'     => self::jsonCol($o['actions'], []),
            ] + ($o['event_at'] !== null ? ['timeline' => ['ceremony' => self::iso($o['event_at'])]] : []);
        }

        $active = null;
        $editions = [];
        foreach ($rows as $e) {
            if ($e['status'] === 'active') {
                $active = $e['slug'];
            }
            $timeline = array_filter([
                'nominationsOpen'  => self::iso($e['nominations_open_at']),
                'nominationsClose' => self::iso($e['nominations_close_at']),
                'votingOpen'       => self::iso($e['voting_open_at']),
                'votingClose'      => self::iso($e['voting_close_at']),
                'resultsPublish'   => self::iso($e['results_publish_at']),
                'ceremony'         => self::iso($e['ceremony_at']),
            ], fn ($v) => $v !== null);

            $editions[] = [
                'id'         => $e['slug'],
                'name'       => $e['name'],
                'edition'    => $e['edition_label'],
                'year'       => (int) $e['year'],
                'status'     => $e['status'],
                'tagline'    => $e['tagline'],
                'mode'       => $e['state_mode'],
                'forceState' => $e['force_state'],
                'timeline'   => $timeline ?: new \stdClass(),
                'features'   => self::jsonCol($e['features'], new \stdClass()),
                'voting'     => [
                    'pricePerVote'                    => self::num($e['price_per_vote']),
                    'maxVotesPerPayment'              => self::num($e['max_votes_per_payment']),
                    'maxPaidVotesPerEmailPerCategory' => self::num($e['max_paid_payments_per_email_per_category']),
                    'nomineeRegistrationFee'          => self::num($e['nominee_registration_fee']),                
                    ],
                'occasions'  => $occ[$e['id']] ?? [],
            ];
        }
        return ['active' => $active, 'editions' => $editions];
    }

    public static function categories(): array
    {
        $rows = self::db()->query(
            'SELECT c.*, e.slug AS edition_slug FROM categories c
               JOIN editions e ON e.id = c.edition_id
              WHERE c.active = 1 AND e.status <> \'draft\'
              ORDER BY e.year DESC, c.sort_order, c.id'
        )->fetchAll();
        return array_map(fn ($c) => [
            'id'          => (string) $c['id'],
            'occasionId'  => $c['edition_slug'],
            'name'        => $c['name'],
            'description' => $c['description'],
            'icon'        => $c['icon'],
            'order'       => (int) $c['sort_order'],
            'active'      => true,
        ], $rows);
    }

    /** Approved nominees only, and only fields meant for the public (no email, phone or account). */
    public static function nominees(): array
    {
        $rows = self::db()->query(
            'SELECT n.*, e.slug AS edition_slug FROM nominee_entries n
               JOIN editions e ON e.id = n.edition_id
              WHERE n.status = \'approved\' AND e.status <> \'draft\'
              ORDER BY e.year DESC, n.id'
        )->fetchAll();
        return array_map(fn ($n) => [
            'id'         => (string) $n['id'],
            'occasionId' => $n['edition_slug'],
            'categoryId' => (string) $n['category_id'],
            'name'       => $n['name'],
            'department' => $n['department'],
            'photo'      => $n['photo_path'],
            'status'     => 'approved',
        ], $rows);
    }

    public static function ticketTypes(): array
    {
        $rows = self::db()->query(
            'SELECT t.*, e.slug AS edition_slug FROM ticket_types t
               JOIN editions e ON e.id = t.edition_id
              WHERE t.active = 1 AND e.status <> \'draft\'
              ORDER BY e.year DESC, t.sort_order, t.id'
        )->fetchAll();
        return array_map(fn ($t) => [
            'id'          => (string) $t['id'],
            'occasionId'  => $t['edition_slug'],
            'name'        => $t['name'],
            'price'       => self::num($t['price']),
            'seats'       => (int) $t['seats'],
            'description' => $t['description'],
            'perks'       => self::jsonCol($t['perks'], []),
            'maxPerOrder' => (int) $t['max_per_order'],
            'active'      => true,
        ], $rows);
    }

    /**
     * Winners are secret until results are public: the edition is completed/archived, the publish
     * time has passed, or an admin manually forced the "results" state.
     */
    public static function winners(): array
    {
        $rows = self::db()->query(
            'SELECT w.category_id, w.nominee_entry_id, e.slug AS edition_slug
               FROM winners w JOIN editions e ON e.id = w.edition_id
              WHERE e.status IN (\'completed\',\'archived\')
                 OR (e.status = \'active\' AND (
                        (e.results_publish_at IS NOT NULL AND e.results_publish_at <= NOW())
                     OR (e.state_mode = \'manual\' AND e.force_state = \'results\')))
              ORDER BY e.year DESC, w.category_id'
        )->fetchAll();
        return array_map(fn ($w) => [
            'occasionId' => $w['edition_slug'],
            'categoryId' => (string) $w['category_id'],
            'nomineeId'  => (string) $w['nominee_entry_id'],
        ], $rows);
    }

    public static function committee(): array
    {
        $file = self::staticFile('committee');
        $units = array_map(fn ($u) => [
            'id'          => (string) $u['id'],
            'name'        => $u['name'],
            'description' => $u['description'],
            'icon'        => $u['icon'],
        ], self::db()->query('SELECT * FROM committee_units ORDER BY sort_order, id')->fetchAll());

        $members = array_map(fn ($m) => [
            'id'        => (string) $m['id'],
            'name'      => $m['name'],
            'role'      => $m['position'],
            'unitId'    => $m['unit_id'] === null ? '' : (string) $m['unit_id'],
            'photo'     => $m['photo_path'],
            'editionId' => $m['edition_slug'],
        ], self::db()->query(
            'SELECT a.id, a.position, a.unit_id, p.name, p.photo_path, e.slug AS edition_slug
               FROM edition_assignments a
               JOIN people p ON p.id = a.person_id AND p.active = 1
               JOIN editions e ON e.id = a.edition_id AND e.status <> \'draft\'
              WHERE a.visible = 1 AND a.position <> \'\'
              ORDER BY e.year DESC, a.id'
        )->fetchAll());

        return ['intro' => $file['intro'] ?? '', 'units' => $units, 'members' => $members];
    }

    /** Editorial content still managed as files: about, content, faqs, gallery, legal, committee intro. */
    public static function staticFile(string $name): mixed
    {
        if (!in_array($name, ['about', 'content', 'faqs', 'gallery', 'legal', 'committee'], true)) {
            throw new \InvalidArgumentException('Unknown content file');
        }
        $path = dirname(__DIR__, 2) . '/data/' . $name . '.json';
        return json_decode((string) file_get_contents($path), true);
    }
}