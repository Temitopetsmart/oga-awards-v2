<?php
declare(strict_types=1);

namespace App;

/**
 * Admin side of advertising. Permissions (all global, like Content):
 *   ads.view    see ads, reports and ad revenue
 *   ads.review  approve, reject and correct ads
 *   ads.manage  packages, settings, free ads, cash deals, extending, removing, refunds
 * Every change is written to the audit log.
 */
final class AdAdminApi
{
    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    /** The edition the admin is looking at (the permission check needs one). Works for JSON and for form uploads. */
    private static function slug(): string
    {
        $q = $_GET['edition'] ?? null;
        if (is_string($q) && $q !== '') {
            return $q;
        }
        $p = $_POST['edition'] ?? null;
        if (is_string($p) && $p !== '') {
            return $p;
        }
        return Request::str(Request::json(), 'edition', 60);
    }

    private static function who(string $perm): array
    {
        return Access::require($perm, self::slug());
    }

    private static function audit(array $actor, string $action, string $target, ?array $details = null): void
    {
        Audit::log((int) $actor['id'], $action, $target, (int) $actor['edition_id'], $details);
    }

    private static function ad(int $id): array
    {
        try {
            return Ads::find($id);
        } catch (\DomainException) {
            self::fail('That ad was not found.', 404);
        }
    }

    private static function run(callable $work): array
    {
        try {
            return $work();
        } catch (\DomainException | \InvalidArgumentException $e) {
            self::fail($e->getMessage());
        }
    }

    // ------------------------------------------------------------------ lists

    private const FILTERS = ['inbox', 'running', 'scheduled', 'refund', 'draft', 'awaiting_payment', 'paid', 'approved', 'expired', 'rejected', 'removed'];

    /** GET /admin/ads?status=inbox|running|scheduled|refund|<a status>&q=&page= */
    public static function list(): never
    {
        self::who('ads.view');
        Ads::sweepIfDue();
        $status = Paging::filter('status');
        $where = ['1=1'];
        $args = [];
        switch ($status) {
            case 'inbox':     $where[] = "a.status = 'paid'"; break;
            case 'running':   $where[] = "a.status = 'approved' AND a.starts_at <= NOW() AND a.ends_at > NOW()"; break;
            case 'scheduled': $where[] = "a.status = 'approved' AND a.starts_at > NOW()"; break;
            case 'refund':    $where[] = "a.status IN ('rejected', 'removed') AND a.paid_via IN ('online', 'manual') AND a.amount > 0 AND a.refunded_at IS NULL"; break;
            default:
                if (in_array($status, self::FILTERS, true)) {
                    $where[] = 'a.status = ?';
                    $args[] = $status;
                }
        }
        if (($q = Paging::q()) !== '') {
            $where[] = '(a.reference LIKE ? OR a.business_name LIKE ? OR a.headline LIKE ? OR a.email LIKE ?)';
            $like = Paging::like($q);
            array_push($args, $like, $like, $like, $like);
        }
        $pdo = self::pdo();
        $count = $pdo->prepare('SELECT COUNT(*) FROM ads a WHERE ' . implode(' AND ', $where));
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $perPage = Paging::perPage(25);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
        $order = $status === 'inbox' ? 'a.paid_at ASC, a.id ASC' : 'a.id DESC';
        $st = $pdo->prepare(
            'SELECT a.*, (a.starts_at <= NOW() AND a.ends_at > NOW() AND a.status = \'approved\') AS is_live, p.name AS reviewer_name FROM ads a LEFT JOIN people p ON p.id = a.reviewed_by
              WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
        );
        $st->execute($args);
        $counts = $pdo->query("SELECT
            SUM(status = 'paid') AS inbox,
            SUM(status = 'approved' AND starts_at <= NOW() AND ends_at > NOW()) AS running,
            SUM(status = 'approved' AND starts_at > NOW()) AS scheduled,
            SUM(status IN ('rejected', 'removed') AND paid_via IN ('online', 'manual') AND amount > 0 AND refunded_at IS NULL) AS refund FROM ads")->fetch();
        Response::json(['ok' => true, 'rows' => array_map([Ads::class, 'adminShape'], $st->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => $pages,
            'counts' => array_map('intval', array_map(fn ($v) => $v ?? 0, $counts))]);
    }

    /** GET /admin/ads/{id} */
    public static function detail(int $id): never
    {
        self::who('ads.view');
        $ad = self::ad($id);
        $out = Ads::adminShape($ad);
        $st = self::pdo()->prepare('SELECT day, placement, impressions, clicks FROM ad_stats WHERE ad_id = ? AND day >= (CURDATE() - INTERVAL 29 DAY) ORDER BY day, placement');
        $st->execute([$id]);
        $out['stats'] = array_map(fn ($r) => ['day' => $r['day'], 'placement' => $r['placement'], 'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks']], $st->fetchAll());
        if ($ad['payment_id'] !== null) {
            $p = self::pdo()->prepare('SELECT reference, status, paid_amount, verified_at FROM payments WHERE id = ?');
            $p->execute([$ad['payment_id']]);
            $out['payment'] = $p->fetch() ?: null;
        }
        Response::json(['ok' => true, 'ad' => $out]);
    }

    // ------------------------------------------------------------------ creating and correcting

    /** POST /admin/ads (form upload): an ad made by an admin. Free, or a cash deal. It skips review and starts at once (or on the date given). */
    public static function create(): never
    {
        $actor = self::who('ads.manage');
        $in = $_POST;
        $cat = Ads::category((int) ($in['category_id'] ?? 0)) ?? self::fail('Choose a package.');
        [$clean, $f] = Ads::content($in, $cat, true);
        $max = Ads::settings()['maxDurationDays'];
        $days = $in['duration_days'] ?? $cat['duration_days'];
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > $max) {
            $f['duration_days'] = "Choose 1 to $max days. No ad runs for ever.";
        }
        $cash = (string) ($in['deal'] ?? 'free') === 'cash';
        $amount = 0.0;
        if ($cash) {
            if (!is_numeric($in['amount'] ?? null) || (float) $in['amount'] <= 0 || (float) $in['amount'] > 100000000) {
                $f['amount'] = 'Enter the amount received.';
            } else {
                $amount = round((float) $in['amount'], 2);
            }
        }
        $places = Ads::cleanPlacements($in['placements'] ?? json_decode((string) $cat['placements'], true));
        if ($places === []) {
            $f['placements'] = 'Choose at least one place.';
        }
        $start = date('Y-m-d H:i:s');
        if (trim((string) ($in['starts_at'] ?? '')) !== '') {
            try {
                $start = (new \DateTimeImmutable(trim((string) $in['starts_at']), new \DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
                if (strtotime($start) < time()) {
                    $start = date('Y-m-d H:i:s');
                }
            } catch (\Throwable) {
                $f['starts_at'] = 'Enter a valid start date.';
            }
        }
        [$paths, $imageErrors, $saved] = Ads::images($cat, $_FILES);
        $f += $imageErrors;
        if ($f) {
            foreach ($saved as $p) {
                AdMedia::delete($p);
            }
            Response::error(reset($f), 422, $f);
        }
        $row = Ads::insert($cat, $clean, $paths, ['status' => 'draft', 'source' => 'admin', 'amount' => $amount, 'duration_days' => (int) $days, 'placements' => $places, 'created_by' => (int) $actor['id']]);
        self::pdo()->prepare("UPDATE ads SET status = 'approved', paid_via = ?, paid_at = NOW(), marked_paid_by = ?, paid_note = ?, starts_at = ?, ends_at = DATE_ADD(?, INTERVAL duration_days DAY), reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$cash ? 'manual' : 'free', (int) $actor['id'], $cash ? Ads::plain((string) ($in['note'] ?? ''), 255) : 'Free ad added by an admin', $start, $start, (int) $actor['id'], $row['id']]);
        Cache::flush();
        $row = Ads::find((int) $row['id']);
        Ads::mail('ad_approved', $row);
        self::audit($actor, $cash ? 'Added a paid-in-cash ad' : 'Added a free ad', $row['reference'], ['days' => (int) $days, 'amount' => $amount]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape($row)]);
    }

    /** PATCH /admin/ads/{id} : correct the wording, link or places (before or after approval). */
    public static function update(int $id): never
    {
        $actor = self::who('ads.review');
        $ad = self::ad($id);
        if ($ad['status'] === 'removed') {
            self::fail('A removed ad cannot be changed.');
        }
        $in = Request::json();
        $cat = Ads::category((int) $ad['category_id']);
        $current = ['business_name' => $ad['business_name'], 'contact_name' => $ad['contact_name'], 'email' => $ad['email'], 'phone' => $ad['phone'], 'headline' => $ad['headline'],
            'body' => $ad['body'], 'link_url' => $ad['link_url'], 'cta_label' => $ad['cta_label'], 'video_url' => $ad['video_url'] ?? ''];
        [$clean, $f] = Ads::content($in + $current, $cat, true);
        $places = isset($in['placements']) ? Ads::cleanPlacements($in['placements']) : Ads::cleanPlacements(json_decode((string) $ad['placements'], true));
        if ($places === []) {
            $f['placements'] = 'Choose at least one place.';
        }
        if ($f) {
            Response::error(reset($f), 422, $f);
        }
        self::pdo()->prepare('UPDATE ads SET business_name = ?, contact_name = ?, email = ?, phone = ?, headline = ?, body = ?, link_url = ?, cta_label = ?, video_url = ?, placements = ? WHERE id = ?')
            ->execute([$clean['business_name'], $clean['contact_name'], $clean['email'], $clean['phone'], $clean['headline'], $clean['body'], $clean['link_url'], $clean['cta_label'], $clean['video_url'], json_encode($places), $id]);
        Cache::flush();
        self::audit($actor, 'Edited an ad', $ad['reference']);
        Response::json(['ok' => true, 'ad' => Ads::adminShape(Ads::find($id))]);
    }

    /** POST /admin/ads/{id}/media (form upload): replace or remove the logo and banner. */
    public static function media(int $id): never
    {
        $actor = self::who('ads.review');
        $ad = self::ad($id);
        $cat = Ads::category((int) $ad['category_id']);
        if ($ad['status'] === 'removed' || !$cat) {
            self::fail('That ad cannot be changed.');
        }
        $old = ['logo_path' => $ad['logo_path'], 'banner_path' => $ad['banner_path']];
        [$paths, $err, $saved] = Ads::images($cat, $_FILES, $old);
        if ($err) {
            Response::error(reset($err), 422, $err);
        }
        foreach (['logo', 'banner'] as $kind) {
            if (!empty($_POST["remove_$kind"]) && empty($_FILES[$kind]['name'])) {
                if ($cat['require_' . $kind]) {
                    foreach ($saved as $p) AdMedia::delete($p);
                    self::fail("This package needs a $kind, so it cannot be removed.");
                }
                $paths[$kind . '_path'] = null;
            }
        }
        self::pdo()->prepare('UPDATE ads SET logo_path = ?, banner_path = ? WHERE id = ?')->execute([$paths['logo_path'], $paths['banner_path'], $id]);
        foreach (['logo_path', 'banner_path'] as $k) {
            if ($old[$k] !== null && $old[$k] !== $paths[$k]) {
                AdMedia::delete($old[$k]);
            }
        }
        Cache::flush();
        self::audit($actor, 'Changed the pictures of an ad', $ad['reference']);
        Response::json(['ok' => true, 'ad' => Ads::adminShape(Ads::find($id))]);
    }

    // ------------------------------------------------------------------ review and lifecycle

    public static function approve(int $id): never
    {
        $actor = self::who('ads.review');
        $in = Request::json();
        $ad = self::run(fn () => Ads::approve($id, (int) $actor['id'], isset($in['startsAt']) ? (string) $in['startsAt'] : null, isset($in['placements']) && is_array($in['placements']) ? $in['placements'] : null));
        self::audit($actor, 'Approved an ad', $ad['reference'], ['starts' => $ad['starts_at'], 'ends' => $ad['ends_at']]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape($ad)]);
    }

    public static function reject(int $id): never
    {
        $actor = self::who('ads.review');
        $ad = self::run(fn () => Ads::reject($id, (int) $actor['id'], (string) (Request::json()['note'] ?? '')));
        self::audit($actor, 'Rejected an ad', $ad['reference'], ['note' => $ad['review_note']]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape($ad)]);
    }

    public static function remove(int $id): never
    {
        $actor = self::who('ads.manage');
        $ad = self::run(fn () => Ads::remove($id, (int) $actor['id'], (string) (Request::json()['reason'] ?? '')));
        self::audit($actor, 'Removed an ad', $ad['reference'], ['reason' => $ad['review_note']]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape($ad)]);
    }

    public static function extend(int $id): never
    {
        $actor = self::who('ads.manage');
        $days = Request::json()['days'] ?? null;
        $ad = self::run(fn () => Ads::extend($id, is_numeric($days) ? (int) $days : 0));
        self::audit($actor, 'Extended an ad', $ad['reference'], ['days' => (int) $days, 'newEnd' => $ad['ends_at']]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape($ad)]);
    }

    /** POST /admin/ads/{id}/mark-paid {note} : the advertiser paid in cash or by transfer. */
    public static function markPaid(int $id): never
    {
        $actor = self::who('ads.manage');
        $note = Ads::plain((string) (Request::json()['note'] ?? ''), 255);
        if (mb_strlen($note) < 3) {
            self::fail('Write how it was paid and who received it.');
        }
        $ad = self::ad($id);
        if (!in_array($ad['status'], ['awaiting_payment', 'draft'], true)) {
            self::fail('Only an ad that is waiting for payment can be marked as paid.');
        }
        $pdo = self::pdo();
        Database::transaction(function (\PDO $db) use ($ad, $actor, $note) {
            if ($ad['payment_id'] !== null) {
                $db->prepare("UPDATE payments SET status = 'abandoned' WHERE id = ? AND status = 'pending'")->execute([$ad['payment_id']]);   // the online link must not be paid as well
            }
            Ads::markPaid($db, (int) $ad['id'], (float) $ad['amount'] > 0 ? 'manual' : 'free', (int) $actor['id'], $note);
        });
        self::audit($actor, 'Marked an ad as paid (not online)', $ad['reference'], ['amount' => (float) $ad['amount'], 'note' => $note]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape(Ads::find($id))]);
    }

    /** POST /admin/ads/{id}/refunded {note} : money for a rejected or removed ad was handed back. */
    public static function refunded(int $id): never
    {
        $actor = self::who('ads.manage');
        $ad = self::ad($id);
        $shape = Ads::adminShape($ad);
        if (!$shape['refundDue']) {
            self::fail('There is no refund waiting on this ad.');
        }
        $note = Ads::plain((string) (Request::json()['note'] ?? ''), 255);
        if (mb_strlen($note) < 3) {
            self::fail('Write how the money was returned.');
        }
        self::pdo()->prepare('UPDATE ads SET refunded_at = NOW(), refund_note = ? WHERE id = ? AND refunded_at IS NULL')->execute([$note, $id]);
        self::audit($actor, 'Recorded an ad refund', $ad['reference'], ['amount' => (float) $ad['amount'], 'note' => $note]);
        Response::json(['ok' => true, 'ad' => Ads::adminShape(Ads::find($id))]);
    }

    // ------------------------------------------------------------------ packages

    private static function slugify(string $name): string
    {
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return $s !== '' ? mb_substr($s, 0, 50) : 'package';
    }

    /** @return array<string,mixed> */
    private static function cleanCategory(array $in): array
    {
        $max = Ads::settings()['maxDurationDays'];
        $name = Ads::plain((string) ($in['name'] ?? ''), 81);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            self::fail('Enter a package name of up to 80 characters.');
        }
        $desc = Ads::plain((string) ($in['description'] ?? ''), 501);
        if (mb_strlen($desc) > 500) {
            self::fail('The description can have up to 500 characters.');
        }
        $num = function (string $key, int $min, int $maxV, string $label) use ($in): int {
            $v = $in[$key] ?? null;
            if (!is_numeric($v) || (float) $v != (int) $v || (int) $v < $min || (int) $v > $maxV) {
                self::fail("$label: enter a whole number from $min to $maxV.");
            }
            return (int) $v;
        };
        $price = $in['price'] ?? null;
        if (!is_numeric($price) || (float) $price < 0 || (float) $price > 100000000) {
            self::fail('Price: enter an amount (0 for a free package).');
        }
        $places = Ads::cleanPlacements($in['placements'] ?? []);
        if ($places === []) {
            self::fail('Choose at least one place where this package shows.');
        }
        $mode = (string) ($in['mediaMode'] ?? 'image');
        if (!in_array($mode, ['text', 'image', 'image_video'], true)) {
            self::fail('Choose what the advertiser may upload.');
        }
        return [
            'name' => $name, 'description' => $desc, 'price' => round((float) $price, 2), 'duration_days' => $num('durationDays', 1, $max, 'Days'),
            'placements' => json_encode($places), 'media_mode' => $mode,
            'require_logo' => $mode !== 'text' && !empty($in['requireLogo']) ? 1 : 0, 'require_banner' => $mode !== 'text' && !empty($in['requireBanner']) ? 1 : 0,
            'max_image_kb' => $num('maxImageKb', 50, 5120, 'Largest image (KB)'), 'max_headline' => $num('maxHeadline', 10, 120, 'Headline length'),
            'max_text' => $num('maxText', 0, 500, 'Description length'), 'max_running' => $num('maxRunning', 0, 1000, 'Most running at once'),
            'active' => !empty($in['active']) ? 1 : 0, 'sort_order' => $num('sortOrder', -1000, 1000, 'Order'),
        ];
    }

    /** GET /admin/ads/categories : packages (all of them) plus the settings and places, for the setup page. */
    public static function categories(): never
    {
        self::who('ads.view');
        $rows = self::pdo()->query('SELECT c.*, (SELECT COUNT(*) FROM ads a WHERE a.category_id = c.id) AS used FROM ad_categories c ORDER BY c.sort_order, c.id')->fetchAll();
        Response::json(['ok' => true, 'categories' => array_map(fn ($c) => Ads::categoryShape($c, true) + ['used' => (int) $c['used']], $rows),
            'settings' => Ads::settings(), 'placements' => Ads::PLACEMENTS, 'currency' => Ads::currency()]);
    }

    public static function createCategory(): never
    {
        $actor = self::who('ads.manage');
        $c = self::cleanCategory(Request::json());
        $slug = self::slugify($c['name']);
        $n = 1;
        $exists = self::pdo()->prepare('SELECT COUNT(*) FROM ad_categories WHERE slug = ?');
        while (true) {
            $try = $n === 1 ? $slug : "$slug-$n";
            $exists->execute([$try]);
            if ((int) $exists->fetchColumn() === 0) {
                $slug = $try;
                break;
            }
            $n++;
        }
        self::pdo()->prepare('INSERT INTO ad_categories (slug, name, description, price, duration_days, placements, media_mode, require_logo, require_banner, max_image_kb, max_headline, max_text, max_running, active, sort_order)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$slug, $c['name'], $c['description'], $c['price'], $c['duration_days'], $c['placements'], $c['media_mode'], $c['require_logo'], $c['require_banner'], $c['max_image_kb'], $c['max_headline'], $c['max_text'], $c['max_running'], $c['active'], $c['sort_order']]);
        $newId = (string) self::pdo()->lastInsertId();          // read before the audit row below changes it
        Cache::flush();
        self::audit($actor, 'Created an ad package', $c['name'], ['price' => $c['price'], 'days' => $c['duration_days']]);
        Response::json(['ok' => true, 'id' => $newId]);
    }

    public static function updateCategory(int $id): never
    {
        $actor = self::who('ads.manage');
        $cat = Ads::category($id) ?? self::fail('That package was not found.', 404);
        $c = self::cleanCategory(Request::json());
        self::pdo()->prepare('UPDATE ad_categories SET name = ?, description = ?, price = ?, duration_days = ?, placements = ?, media_mode = ?, require_logo = ?, require_banner = ?, max_image_kb = ?, max_headline = ?, max_text = ?, max_running = ?, active = ?, sort_order = ? WHERE id = ?')
            ->execute([$c['name'], $c['description'], $c['price'], $c['duration_days'], $c['placements'], $c['media_mode'], $c['require_logo'], $c['require_banner'], $c['max_image_kb'], $c['max_headline'], $c['max_text'], $c['max_running'], $c['active'], $c['sort_order'], $id]);
        Cache::flush();
        self::audit($actor, 'Edited an ad package', $cat['name'], ['price' => $c['price'], 'days' => $c['duration_days'], 'active' => (bool) $c['active']]);
        Response::json(['ok' => true]);
    }

    public static function deleteCategory(int $id): never
    {
        $actor = self::who('ads.manage');
        $cat = Ads::category($id) ?? self::fail('That package was not found.', 404);
        $used = self::pdo()->prepare('SELECT COUNT(*) FROM ads WHERE category_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            self::fail('Ads have been sold in this package, so it cannot be deleted. Switch it off instead.');
        }
        self::pdo()->prepare('DELETE FROM ad_categories WHERE id = ?')->execute([$id]);
        Cache::flush();
        self::audit($actor, 'Deleted an ad package', $cat['name']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ settings and reports

    public static function settings(): never
    {
        self::who('ads.view');
        Response::json(['ok' => true, 'settings' => Ads::settings(), 'placements' => Ads::PLACEMENTS]);
    }

    public static function saveSettings(): never
    {
        $actor = self::who('ads.manage');
        $in = Request::json();
        try {
            $clean = Ads::cleanSettings((array) ($in['value'] ?? []));
        } catch (\InvalidArgumentException $e) {
            self::fail($e->getMessage());
        }
        Ads::saveSettings($clean, (int) $actor['id']);
        self::audit($actor, 'Changed the advertising settings', 'ads', ['submissionsOpen' => $clean['submissionsOpen']]);
        Response::json(['ok' => true]);
    }

    /** GET /admin/ads/report?from=YYYY-MM-DD&to=YYYY-MM-DD (default: the last 30 days) */
    public static function report(): never
    {
        self::who('ads.view');
        $day = fn (mixed $v, string $fallback): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : $fallback;
        $to = $day($_GET['to'] ?? null, date('Y-m-d'));
        $from = $day($_GET['from'] ?? null, date('Y-m-d', strtotime('-29 days')));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        Response::json(['ok' => true, 'report' => Ads::report($from, $to)]);
    }
}