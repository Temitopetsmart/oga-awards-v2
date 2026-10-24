<?php
declare(strict_types=1);

namespace App;

/** Editions: create, details, state & dates, voting rules, lifecycle, delete. All rules enforced here. */
final class EditionApi
{
    private const STATE_KEYS = ['landing', 'nominations', 'voting', 'voting-closed', 'results'];
    /** Order matters: each date may not be earlier than the one before it. */
    private const TIMELINE = [
        'nominationsOpen'  => 'nominations_open_at',
        'nominationsClose' => 'nominations_close_at',
        'votingOpen'       => 'voting_open_at',
        'votingClose'      => 'voting_close_at',
        'resultsPublish'   => 'results_publish_at',
        'ceremony'         => 'ceremony_at',
    ];
    private const ALLOWED = [
        'draft'     => ['active'],
        'active'    => ['completed'],
        'completed' => ['archived', 'active'],
        'archived'  => ['completed'],
    ];

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function find(string $slug): array
    {
        $st = self::pdo()->prepare('SELECT * FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    private static function label(array $e): string
    {
        return $e['name'] . ' ' . $e['edition_label'];
    }

    private static function slugify(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($s)), '-');
    }

    private static function isSuper(array $p): bool
    {
        return (int) $p['is_super_admin'] === 1;
    }

    /** Validates the name / number / year / tagline shared by "create" and "edit details". */
    private static function details(array $in): array
    {
        $name  = Request::str($in, 'name', 200);
        $label = Request::str($in, 'label', 200);
        $tag   = Request::str($in, 'tagline', 500);
        $year  = (int) ($in['year'] ?? 0);
        if ($name === '' || $label === '') {
            self::fail('Enter an edition name and edition number.');
        }
        if (mb_strlen($name) > 30 || mb_strlen($label) > 10 || mb_strlen($tag) > 120) {
            self::fail('Too long: the name can be 30 characters, the edition number 10 and the tagline 120.');
        }
        if ($year < 2000 || $year > 2100) {
            self::fail('Enter a valid year between 2000 and 2100.');
        }
        return [$name, $label, $year, $tag];
    }

    private static function duplicate(string $name, string $label, ?int $exceptId): bool
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM editions WHERE LOWER(name) = LOWER(?) AND LOWER(edition_label) = LOWER(?) AND id <> ?');
        $st->execute([$name, $label, $exceptId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    // ---------------------------------------------------------------- create

    public static function create(): never
    {
        $in = Request::json();
        $actor = Access::require('editions.manage', Request::str($in, 'edition', 60));
        [$name, $label, $year, $tagline] = self::details($in);
        if (self::duplicate($name, $label, null)) {
            self::fail('That edition already exists.');
        }
        $src = null;
        if (!empty($in['copyFrom'])) {
            $src = self::find((string) $in['copyFrom']);
        }
        $copy = is_array($in['copy'] ?? null) ? $in['copy'] : [];
        if ($src && !empty($copy['team']) && !self::isSuper($actor)) {
            // Copying the team hands out the same access again, so it is limited to Super admins.
            self::fail('Only a Super admin can copy the team into a new edition.', 403);
        }

        $slug = self::slugify($name) . '-' . self::slugify($label);
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM editions WHERE slug = ?');
        for ($i = 0; ; $i++) {
            $try = $i === 0 ? $slug : $slug . '-' . substr(bin2hex(random_bytes(2)), 0, 3);
            $st->execute([$try]);
            if ((int) $st->fetchColumn() === 0) {
                $slug = $try;
                break;
            }
        }

        $newId = Database::transaction(function (\PDO $db) use ($slug, $name, $label, $year, $tagline, $src, $copy, $actor) {
            $cols = array_values(self::TIMELINE);
            $copySettings = $src && !empty($copy['settings']);
            $db->prepare('INSERT INTO editions (slug, name, edition_label, year, tagline, status, state_mode, features'
                . ($copySettings ? ', ' . implode(', ', $cols) : '') . ')
                VALUES (?, ?, ?, ?, ?, \'draft\', \'auto\', ?' . ($copySettings ? str_repeat(', ?', count($cols)) : '') . ')')
                ->execute(array_merge(
                    [$slug, $name, $label, $year, $tagline !== '' ? $tagline : ($src['tagline'] ?? null),
                     $copySettings ? $src['features'] : json_encode(['tickets' => false, 'committeeRecruitment' => false, 'showVoteCounts' => false])],
                    $copySettings ? array_map(fn ($c) => $src[$c], $cols) : []
                ));
            $id = (int) $db->lastInsertId();

            if ($copySettings) {
                $db->prepare('INSERT INTO edition_settings (edition_id, price_per_vote, max_votes_per_payment, max_paid_payments_per_email_per_category, nominee_registration_fee)
                              SELECT ?, price_per_vote, max_votes_per_payment, max_paid_payments_per_email_per_category, nominee_registration_fee FROM edition_settings WHERE edition_id = ?')
                    ->execute([$id, $src['id']]);
            } else {
                $db->prepare('INSERT INTO edition_settings (edition_id) VALUES (?)')->execute([$id]);
            }
            if ($src && !empty($copy['categories'])) {
                $db->prepare('INSERT INTO categories (edition_id, name, description, icon, sort_order, active)
                              SELECT ?, name, description, icon, sort_order, active FROM categories WHERE edition_id = ?')
                    ->execute([$id, $src['id']]);
            }
            if ($src && !empty($copy['ticketTypes'])) {
                $db->prepare('INSERT INTO ticket_types (edition_id, name, price, seats, description, perks, max_per_order, active, sort_order)
                              SELECT ?, name, price, seats, description, perks, max_per_order, active, sort_order FROM ticket_types WHERE edition_id = ?')
                    ->execute([$id, $src['id']]);
            }
            if ($src && !empty($copy['occasions'])) {
                $db->prepare('INSERT INTO occasions (edition_id, slug, type, name, tagline, icon, enabled, link_href, link_label, description, event_at, actions)
                              SELECT ?, slug, type, name, tagline, icon, 0, link_href, link_label, description, event_at, actions FROM occasions WHERE edition_id = ?')
                    ->execute([$id, $src['id']]);
            }
            if ($src && !empty($copy['team'])) {
                $rows = $db->prepare('SELECT * FROM edition_assignments WHERE edition_id = ?');
                $rows->execute([$src['id']]);
                foreach ($rows->fetchAll() as $a) {
                    $db->prepare('INSERT INTO edition_assignments (person_id, edition_id, position, unit_id, visible) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$a['person_id'], $id, $a['position'], $a['unit_id'], $a['visible']]);
                    $aid = (int) $db->lastInsertId();
                    $db->prepare('INSERT INTO assignment_roles (assignment_id, role_id) SELECT ?, role_id FROM assignment_roles WHERE assignment_id = ?')->execute([$aid, $a['id']]);
                    $db->prepare('INSERT INTO assignment_overrides (assignment_id, perm_key, effect) SELECT ?, perm_key, effect FROM assignment_overrides WHERE assignment_id = ?')->execute([$aid, $a['id']]);
                }
            }
            // A creator who is not a Super admin would otherwise not be able to open the edition they just made,
            // so they get the SAME roles there that they already hold here (nothing more).
            if (!self::isSuper($actor)) {
                $mine = $db->prepare('SELECT * FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
                $mine->execute([$actor['id'], $actor['edition_id']]);
                $a = $mine->fetch();
                $has = $db->prepare('SELECT COUNT(*) FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
                $has->execute([$actor['id'], $id]);
                if ($a && (int) $has->fetchColumn() === 0) {
                    $db->prepare('INSERT INTO edition_assignments (person_id, edition_id, position, unit_id, visible) VALUES (?, ?, ?, ?, 0)')
                        ->execute([$actor['id'], $id, $a['position'], $a['unit_id']]);
                    $aid = (int) $db->lastInsertId();
                    $db->prepare('INSERT INTO assignment_roles (assignment_id, role_id) SELECT ?, role_id FROM assignment_roles WHERE assignment_id = ?')->execute([$aid, $a['id']]);
                    $db->prepare('INSERT INTO assignment_overrides (assignment_id, perm_key, effect) SELECT ?, perm_key, effect FROM assignment_overrides WHERE assignment_id = ?')->execute([$aid, $a['id']]);
                }
            }
            return $id;
        });

        Cache::flush();
        Audit::log((int) $actor['id'], 'Created edition', "$name $label", $newId, ['copiedFrom' => $src['slug'] ?? null, 'copy' => array_keys(array_filter($copy))]);
        Response::json(['ok' => true, 'editionId' => $slug]);
    }

    // --------------------------------------------------------------- details

    public static function updateDetails(string $slug): never
    {
        $in = Request::json();
        $e = self::find($slug);
        $actor = self::allowedEither($in, $e);
        [$name, $label, $year, $tagline] = self::details($in);
        if (self::duplicate($name, $label, (int) $e['id'])) {
            self::fail('That edition already exists.');
        }
        self::pdo()->prepare('UPDATE editions SET name = ?, edition_label = ?, year = ?, tagline = ? WHERE id = ?')
            ->execute([$name, $label, $year, $tagline, $e['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Edited edition', "$name $label", (int) $e['id']);
        Response::json(['ok' => true]);
    }

    /** The editions page needs editions.manage; the settings page needs settings.edit for that edition. */
    private static function allowedEither(array $in, array $e): array
    {
        $person = Auth::person() ?? Response::error('Please sign in.', 401);
        $st = self::pdo()->prepare('SELECT id FROM editions WHERE slug = ?');
        $st->execute([Request::str($in, 'edition', 60)]);
        $viewing = $st->fetchColumn();
        $ok = ($viewing !== false && Access::can($person, 'editions.manage', (int) $viewing))
            || Access::can($person, 'settings.edit', (int) $e['id']);
        if (!$ok) {
            self::fail('You do not have permission to do that.', 403);
        }
        return $person;
    }

    // ------------------------------------------------------- state and dates

    private static function localTime(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Africa/Lagos'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            self::fail('Enter valid dates.');
        }
    }

    public static function saveState(string $slug): never
    {
        $in = Request::json();
        $e = self::find($slug);
        $actor = Access::require('settings.edit', $slug);

        $mode = (string) ($in['mode'] ?? 'auto');
        if (!in_array($mode, ['auto', 'manual'], true)) {
            self::fail('Choose automatic or manual.');
        }
        $force = $mode === 'manual' ? (string) ($in['forceState'] ?? '') : null;
        if ($mode === 'manual' && !in_array($force, self::STATE_KEYS, true)) {
            self::fail('Pick the state to show.');
        }

        $times = [];
        $prev = null;
        $prevLabel = '';
        foreach (self::TIMELINE as $key => $col) {
            $raw = $in['timeline'][$key] ?? null;
            $times[$col] = $raw ? self::localTime((string) $raw) : null;
            if ($times[$col] !== null) {
                if ($prev !== null && $times[$col] < $prev) {
                    self::fail("Can’t be before “{$prevLabel}”: check the dates.");
                }
                $prev = $times[$col];
                $prevLabel = $key;
            }
        }

        $features = json_decode((string) ($e['features'] ?? '{}'), true) ?: [];
        foreach (['tickets', 'committeeRecruitment', 'showVoteCounts'] as $f) {
            if (array_key_exists($f, (array) ($in['features'] ?? []))) {
                $features[$f] = (bool) $in['features'][$f];
            }
        }

        $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($times)));
        self::pdo()->prepare("UPDATE editions SET state_mode = ?, force_state = ?, features = ?, $set WHERE id = ?")
            ->execute(array_merge([$mode, $force, json_encode($features)], array_values($times), [$e['id']]));
        Cache::flush();
        Audit::log((int) $actor['id'], 'Updated state, dates and features', self::label($e), (int) $e['id']);
        Response::json(['ok' => true]);
    }

    // ---------------------------------------------------------- voting rules

    public static function saveVoting(string $slug): never
    {
        $in = Request::json();
        $e = self::find($slug);
        $actor = Access::require('settings.edit', $slug);

        $whole = function (string $key, int $min, int $max, string $label) use ($in): int {
            $v = $in[$key] ?? null;
            if (!is_numeric($v) || (float) $v != (int) $v || (int) $v < $min || (int) $v > $max) {
                self::fail("$label: enter a whole number from $min to $max.");
            }
            return (int) $v;
        };
        $price = $whole('pricePerVote', 1, 1000000, 'Price per vote');
        $maxVotes = $whole('maxVotesPerPayment', 1, 10000, 'Max votes per payment');
        $maxPays = $whole('maxPaidVotesPerEmailPerCategory', 1, 1000, 'Max paid payments per email');
        $fee = $whole('nomineeRegistrationFee', 0, 10000000, 'Nominee registration fee');

        self::pdo()->prepare(
            'INSERT INTO edition_settings (edition_id, price_per_vote, max_votes_per_payment, max_paid_payments_per_email_per_category, nominee_registration_fee, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE price_per_vote = VALUES(price_per_vote), max_votes_per_payment = VALUES(max_votes_per_payment),
               max_paid_payments_per_email_per_category = VALUES(max_paid_payments_per_email_per_category),
               nominee_registration_fee = VALUES(nominee_registration_fee), updated_by = VALUES(updated_by)'
        )->execute([$e['id'], $price, $maxVotes, $maxPays, $fee, $actor['id']]);
        Cache::flush();
        Audit::log((int) $actor['id'], 'Updated voting rules', "₦$price per vote · nominee fee ₦$fee", (int) $e['id'],
            ['maxVotesPerPayment' => $maxVotes, 'maxPaymentsPerEmailPerCategory' => $maxPays]);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------- lifecycle

    public static function transition(string $slug): never
    {
        $in = Request::json();
        $actor = Access::require('editions.manage', Request::str($in, 'edition', 60));
        $e = self::find($slug);
        $to = Request::str($in, 'to', 20);
        if (!in_array($to, self::ALLOWED[$e['status']] ?? [], true)) {
            self::fail("A {$e['status']} edition cannot become $to.");
        }

        $reopen = $e['status'] === 'completed' && $to === 'active';
        $reason = Request::str($in, 'reason', 500);
        if ($reopen) {
            if (!self::isSuper($actor)) {
                self::fail('Only a Super admin can reopen a completed edition.', 403);
            }
            if (mb_strlen($reason) < 8) {
                self::fail('Give a reason for reopening (at least 8 characters). It is kept in the audit log.');
            }
        }
        $busy = 'is still active. Complete it first. Only one edition can be active at a time.';
        if ($to === 'active') {
            $st = self::pdo()->prepare("SELECT name, edition_label FROM editions WHERE status = 'active' AND id <> ?");
            $st->execute([$e['id']]);
            if ($other = $st->fetch()) {
                self::fail(self::label($other) . " $busy");
            }
        }
        try {
            self::pdo()->prepare('UPDATE editions SET status = ? WHERE id = ?')->execute([$to, $e['id']]);
        } catch (\PDOException $ex) {
            if ($ex->getCode() === '23000') {            // the database itself allows only one active edition
                self::fail("Another edition $busy");
            }
            throw $ex;
        }
        Cache::flush();
        $action = match (true) {
            $reopen                                          => 'Edition reopened',
            $e['status'] === 'archived' && $to === 'completed' => 'Edition unarchived',
            $to === 'active'                                 => 'Edition activated',
            $to === 'completed'                              => 'Edition completed',
            default                                          => 'Edition archived',
        };
        Audit::log((int) $actor['id'], $action, self::label($e), (int) $e['id'], $reopen ? ['reason' => $reason] : null);
        Response::json(['ok' => true]);
    }

    public static function delete(string $slug): never
    {
        $in = Request::json();
        $actor = Access::require('editions.manage', Request::str($in, 'edition', 60));
        $e = self::find($slug);
        if ($e['status'] === 'active') {
            self::fail('The active edition cannot be deleted.');
        }
        if ($e['status'] !== 'draft') {
            self::fail('Only draft editions can be deleted. Archive completed editions instead.');
        }
        $pdo = self::pdo();
        foreach (['nominee_entries', 'vote_orders', 'ticket_orders', 'payments', 'committee_applications', 'winners'] as $table) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE edition_id = ?");
            $st->execute([$e['id']]);
            if ((int) $st->fetchColumn() > 0) {
                self::fail('This draft already has records. Keep its history and remove the records through the appropriate management pages instead.');
            }
        }
        Database::transaction(function (\PDO $db) use ($e) {
            $id = $e['id'];
            $db->prepare('DELETE FROM assignment_overrides WHERE assignment_id IN (SELECT id FROM edition_assignments WHERE edition_id = ?)')->execute([$id]);
            $db->prepare('DELETE FROM assignment_roles WHERE assignment_id IN (SELECT id FROM edition_assignments WHERE edition_id = ?)')->execute([$id]);
            $db->prepare('DELETE FROM edition_assignments WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM invitation_roles WHERE invitation_id IN (SELECT id FROM invitations WHERE edition_id = ?)')->execute([$id]);
            $db->prepare('DELETE FROM invitations WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ticket_types WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM categories WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM occasions WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM committee_config WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM edition_settings WHERE edition_id = ?')->execute([$id]);
            $db->prepare('UPDATE contact_messages SET edition_id = NULL WHERE edition_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM editions WHERE id = ?')->execute([$id]);
        });
        Cache::flush();
        Audit::log((int) $actor['id'], 'Deleted draft edition', self::label($e));
        Response::json(['ok' => true]);
    }
}