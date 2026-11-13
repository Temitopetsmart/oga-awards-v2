<?php
declare(strict_types=1);

namespace App;

/**
 * Phase 19: bring a past edition (for example OGA 9.0) into the system as a completed edition, with its categories,
 * nominees, final vote counts and winners. Super admins only. Always check first ("dry run"): nothing is written until
 * the file is clean. No emails, phone numbers or photos are imported: only what a public history page needs.
 */
final class ImportApi
{
    private const MAX_NOMINEES = 5000;

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function str(mixed $v, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, $max + 1);        // one extra character so "too long" can be noticed
    }

    /**
     * Checks the whole file and collects every problem (not just the first).
     * Returns [clean plan or null, list of problems, list of notes].
     */
    public static function check(array $in): array
    {
        $errors = [];
        $notes = [];
        $bad = function (string $msg) use (&$errors) {
            if (count($errors) < 60) {
                $errors[] = $msg;
            }
        };

        // ---- edition
        $e = (array) ($in['edition'] ?? []);
        $slug = strtolower(trim((string) ($e['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) < 3 || strlen($slug) > 40) {
            $bad('Edition: the slug must be 3 to 40 letters, digits and dashes, like oga-9.');
        } else {
            $st = self::pdo()->prepare('SELECT COUNT(*) FROM editions WHERE slug = ?');
            $st->execute([$slug]);
            if ((int) $st->fetchColumn() > 0) {
                $bad("Edition: “$slug” already exists. An import only ever creates a new edition.");
            }
        }
        $name = self::str($e['name'] ?? null, 100);
        $label = self::str($e['label'] ?? null, 40);
        $year = $e['year'] ?? null;
        if ($name === null || mb_strlen($name) > 100) {
            $bad('Edition: a name is required (up to 100 characters).');
        }
        if ($label === null || mb_strlen($label) > 40) {
            $bad('Edition: a label such as 9.0 is required (up to 40 characters).');
        }
        if (!is_int($year) && !(is_string($year) && ctype_digit($year)) || (int) $year < 2000 || (int) $year > 2100) {
            $bad('Edition: the year must be between 2000 and 2100.');
        }
        $status = (string) ($e['status'] ?? 'completed');
        if (!in_array($status, ['completed', 'archived'], true)) {
            $bad('Edition: the status must be “completed” or “archived”.');
        }
        $ceremony = null;
        if (($e['ceremony'] ?? '') !== '' && isset($e['ceremony'])) {
            $ts = strtotime((string) $e['ceremony']);
            if ($ts === false) {
                $bad('Edition: the ceremony date is not valid.');
            } else {
                $ceremony = date('Y-m-d H:i:s', $ts);
            }
        }
        $tagline = self::str($e['tagline'] ?? null, 255);
        if ($tagline !== null && mb_strlen($tagline) > 255) {
            $bad('Edition: the tagline can be at most 255 characters.');
        }

        // ---- categories
        $cats = array_values((array) ($in['categories'] ?? []));
        if ($cats === [] || count($cats) > 100) {
            $bad('Categories: add between 1 and 100.');
        }
        $catByName = [];
        foreach ($cats as $i => $c) {
            $n = $i + 1;
            $cn = self::str($c['name'] ?? null, 150);
            if ($cn === null || mb_strlen($cn) > 150) {
                $bad("Category $n: a name is required (up to 150 characters).");
                continue;
            }
            $key = mb_strtolower($cn);
            if (isset($catByName[$key])) {
                $bad("Category $n: “$cn” appears twice.");
                continue;
            }
            $desc = self::str($c['description'] ?? null, 500);
            if ($desc !== null && mb_strlen($desc) > 500) {
                $bad("Category $n: the description can be at most 500 characters.");
            }
            $catByName[$key] = ['name' => $cn, 'description' => $desc, 'sort' => (int) ($c['sortOrder'] ?? $n)];
        }

        // ---- nominees
        $noms = array_values((array) ($in['nominees'] ?? []));
        if (count($noms) > self::MAX_NOMINEES) {
            $bad('Nominees: at most ' . self::MAX_NOMINEES . ' in one import.');
            $noms = array_slice($noms, 0, self::MAX_NOMINEES);
        }
        $rows = [];
        $winners = [];
        $totalVotes = 0;
        $ignored = 0;
        $seen = [];
        foreach ($noms as $i => $x) {
            $n = $i + 1;
            if (!is_array($x)) {
                $bad("Nominee $n: not an object.");
                continue;
            }
            $ignored += count(array_diff(array_keys($x), ['name', 'department', 'level', 'category', 'bio', 'votes', 'winner']));
            $nm = self::str($x['name'] ?? null, 150);
            if ($nm === null || mb_strlen($nm) > 150) {
                $bad("Nominee $n: a name is required (up to 150 characters).");
                continue;
            }
            $cat = $catByName[mb_strtolower((string) ($x['category'] ?? ''))] ?? null;
            if ($cat === null) {
                $bad("Nominee $n ($nm): the category “" . mb_substr((string) ($x['category'] ?? ''), 0, 60) . '” is not in the categories list.');
                continue;
            }
            $dupe = mb_strtolower($nm) . '|' . mb_strtolower($cat['name']);
            if (isset($seen[$dupe])) {
                $bad("Nominee $n: $nm is listed twice in {$cat['name']}.");
                continue;
            }
            $seen[$dupe] = true;
            $dept = self::str($x['department'] ?? null, 150);
            $level = self::str($x['level'] ?? null, 40);
            $bio = self::str($x['bio'] ?? null, 600);
            if (($dept !== null && mb_strlen($dept) > 150) || ($level !== null && mb_strlen($level) > 40) || ($bio !== null && mb_strlen($bio) > 600)) {
                $bad("Nominee $n ($nm): department up to 150, level up to 40, bio up to 600 characters.");
                continue;
            }
            $votes = $x['votes'] ?? 0;
            if (!(is_int($votes) || (is_string($votes) && ctype_digit($votes))) || (int) $votes < 0 || (int) $votes > 100000000) {
                $bad("Nominee $n ($nm): votes must be a whole number from 0 to 100,000,000.");
                continue;
            }
            $isWinner = !empty($x['winner']);
            if ($isWinner) {
                $ck = mb_strtolower($cat['name']);
                if (isset($winners[$ck])) {
                    $bad("Category “{$cat['name']}”: more than one winner is marked.");
                    continue;
                }
                $winners[$ck] = $i;
            }
            $totalVotes += (int) $votes;
            $rows[] = ['name' => $nm, 'department' => $dept, 'level' => $level, 'bio' => $bio, 'cat' => mb_strtolower($cat['name']), 'votes' => (int) $votes, 'winner' => $isWinner];
        }
        if ($noms === []) {
            $bad('Nominees: add at least one.');
        }
        $withoutWinner = array_values(array_filter(array_column($catByName, 'name'), fn ($n) => !isset($winners[mb_strtolower($n)])));
        if ($withoutWinner !== []) {
            $notes[] = count($withoutWinner) . ' categor' . (count($withoutWinner) === 1 ? 'y has' : 'ies have') . ' no winner marked: ' . implode(', ', array_slice($withoutWinner, 0, 5)) . (count($withoutWinner) > 5 ? '…' : '') . '.';
        }
        if ($ignored > 0) {
            $notes[] = "$ignored extra field" . ($ignored === 1 ? ' was' : 's were') . ' ignored (only names, departments, levels, categories, bios, votes and winners are imported).';
        }
        $notes[] = 'No emails, phone numbers or photos are imported. Photos can be added to a nominee afterwards.';

        $plan = $errors === [] ? [
            'edition' => ['slug' => $slug, 'name' => $name, 'label' => $label, 'year' => (int) $year, 'status' => $status, 'ceremony' => $ceremony, 'tagline' => $tagline],
            'categories' => array_values($catByName), 'nominees' => $rows, 'winners' => count($winners), 'votes' => $totalVotes,
        ] : null;
        return [$plan, $errors, $notes];
    }

    public static function run(array $actor): never
    {
        $in = Request::json();
        $dry = !empty($in['dryRun']);
        [$plan, $errors, $notes] = self::check((array) ($in['payload'] ?? []));
        $summary = $plan === null ? null : [
            'edition' => $plan['edition']['name'] . ' ' . $plan['edition']['label'], 'categories' => count($plan['categories']),
            'nominees' => count($plan['nominees']), 'winners' => $plan['winners'], 'votes' => $plan['votes'],
        ];
        if ($plan === null || $dry) {
            Response::json(['ok' => $errors === [], 'dryRun' => $dry, 'errors' => $errors, 'notes' => $notes, 'summary' => $summary], $errors === [] ? 200 : 422);
        }
        $editionId = Database::transaction(function (\PDO $db) use ($plan, $actor) {
            $e = $plan['edition'];
            $db->prepare("INSERT INTO editions (slug, name, edition_label, year, tagline, status, state_mode, ceremony_at, features) VALUES (?, ?, ?, ?, ?, ?, 'auto', ?, ?)")
                ->execute([$e['slug'], $e['name'], $e['label'], $e['year'], $e['tagline'], $e['status'], $e['ceremony'], json_encode(['tickets' => false, 'committeeRecruitment' => false])]);
            $eid = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO edition_settings (edition_id, updated_by) VALUES (?, ?)')->execute([$eid, $actor['id']]);
            $catIds = [];
            $cIns = $db->prepare('INSERT INTO categories (edition_id, name, description, sort_order, active) VALUES (?, ?, ?, ?, 1)');
            foreach ($plan['categories'] as $c) {
                $cIns->execute([$eid, $c['name'], $c['description'], $c['sort']]);
                $catIds[mb_strtolower($c['name'])] = (int) $db->lastInsertId();
            }
            $nIns = $db->prepare("INSERT INTO nominee_entries (edition_id, category_id, name, department, level, bio, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')");
            $oIns = $db->prepare("INSERT INTO vote_orders (edition_id, reference, nominee_entry_id, category_id, votes, amount, voter_name, voter_email, status, source, reason, created_by, paid_at)
                                  VALUES (?, ?, ?, ?, ?, 0, 'Imported', '', 'paid', 'manual', ?, ?, NOW())");
            $wIns = $db->prepare('INSERT INTO winners (edition_id, category_id, nominee_entry_id, set_by) VALUES (?, ?, ?, ?)');
            foreach ($plan['nominees'] as $i => $n) {
                $cid = $catIds[$n['cat']];
                $nIns->execute([$eid, $cid, $n['name'], $n['department'], $n['level'], $n['bio']]);
                $nid = (int) $db->lastInsertId();
                if ($n['votes'] > 0) {
                    $oIns->execute([$eid, 'IMP-' . strtoupper(bin2hex(random_bytes(6))), $nid, $cid, $n['votes'], 'Imported from ' . $plan['edition']['name'] . ' ' . $plan['edition']['label'], $actor['id']]);
                    VoteTotals::addOrder($db, ['nominee_entry_id' => $nid, 'category_id' => $cid, 'edition_id' => $eid, 'votes' => $n['votes'], 'amount' => 0, 'source' => 'manual']);
                }
                if ($n['winner']) {
                    $wIns->execute([$eid, $cid, $nid, $actor['id']]);
                }
            }
            return $eid;
        });
        Cache::flush();
        Audit::log((int) $actor['id'], 'Imported a past edition', $summary['edition'] . ': ' . $summary['nominees'] . ' nominees, ' . $summary['votes'] . ' votes', $editionId, $summary);
        Response::json(['ok' => true, 'dryRun' => false, 'errors' => [], 'notes' => $notes, 'summary' => $summary, 'slug' => $plan['edition']['slug']]);
    }
}