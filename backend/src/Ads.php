<?php
declare(strict_types=1);

namespace App;

/**
 * Advertising: the rules in one place. The public form (AdApi) and the admin pages (AdAdminApi) both call this.
 *
 * An ad's life:  awaiting_payment -> paid (waits for review) -> approved (runs from starts_at to ends_at) -> expired.
 * An ad may also end as rejected or removed. "Draft" is an ad that was started but never paid for (or was made by an admin and not finished).
 * NOTHING runs for ever: every approved ad has an end date, and the sweep (run lazily and by cron) moves it to expired.
 */
final class Ads
{
    /** Where an ad can appear. The places are fixed in the page code; their names, on/off, speed and size are admin settings. */
    public const PLACEMENTS = ['site_footer', 'home_banner', 'portal'];

    private const STATUS_LABEL = [
        'draft' => 'Draft', 'awaiting_payment' => 'Awaiting payment', 'paid' => 'Paid, awaiting review', 'approved' => 'Approved',
        'expired' => 'Expired', 'rejected' => 'Rejected', 'removed' => 'Removed',
    ];

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    // ------------------------------------------------------------------ settings

    /** The ad settings (Admin, Ad setup) with safe values filled in. */
    public static function settings(): array
    {
        $s = SettingsApi::value('ads');
        $place = [];
        foreach (self::PLACEMENTS as $k) {
            $p = (array) ($s['placements'][$k] ?? []);
            $place[$k] = [
                'label'         => (string) ($p['label'] ?? $k),
                'enabled'       => !array_key_exists('enabled', $p) || !empty($p['enabled']),
                'rotateSeconds' => max(3, min(120, (int) ($p['rotateSeconds'] ?? 8))),
                'maxAds'        => max(1, min(20, (int) ($p['maxAds'] ?? 6))),
            ];
        }
        return [
            'enabled'            => !array_key_exists('enabled', $s) || !empty($s['enabled']),
            'submissionsOpen'    => !empty($s['submissionsOpen']),
            'label'              => (string) (($s['label'] ?? '') !== '' ? $s['label'] : 'Ad'),
            'defaultCta'         => (string) (($s['defaultCta'] ?? '') !== '' ? $s['defaultCta'] : 'Learn more'),
            'intro'              => (string) ($s['intro'] ?? ''),
            'terms'              => (string) ($s['terms'] ?? ''),
            'expiryWarningDays'  => max(0, min(60, (int) ($s['expiryWarningDays'] ?? 3))),
            'maxDurationDays'    => max(1, min(3650, (int) ($s['maxDurationDays'] ?? 365))),
            'maxPendingPerEmail' => max(1, min(50, (int) ($s['maxPendingPerEmail'] ?? 3))),
            'placements'         => $place,
        ];
    }

    /** Validates and stores the settings an admin sent. Throws \InvalidArgumentException with a safe message. */
    public static function cleanSettings(array $v): array
    {
        $int = function (mixed $x, int $min, int $max, string $label): int {
            if (!is_numeric($x) || (float) $x != (int) $x || (int) $x < $min || (int) $x > $max) {
                throw new \InvalidArgumentException("$label: enter a whole number from $min to $max.");
            }
            return (int) $x;
        };
        $text = fn (string $k, int $max, string $label, bool $required = false): string => (function () use ($v, $k, $max, $label, $required) {
            $t = self::plain((string) ($v[$k] ?? ''), $max + 1);
            if (mb_strlen($t) > $max) {
                throw new \InvalidArgumentException("$label is too long (maximum $max characters).");
            }
            if ($required && $t === '') {
                throw new \InvalidArgumentException("$label cannot be empty.");
            }
            return $t;
        })();
        $place = [];
        foreach (self::PLACEMENTS as $k) {
            $p = (array) (($v['placements'] ?? [])[$k] ?? []);
            $label = self::plain((string) ($p['label'] ?? ''), 81);
            if ($label === '' || mb_strlen($label) > 80) {
                throw new \InvalidArgumentException('Every place needs a name of up to 80 characters.');
            }
            $place[$k] = [
                'label' => $label, 'enabled' => !empty($p['enabled']),
                'rotateSeconds' => $int($p['rotateSeconds'] ?? null, 3, 120, "$label: seconds per ad"),
                'maxAds' => $int($p['maxAds'] ?? null, 1, 20, "$label: most ads shown"),
            ];
        }
        return [
            'enabled' => !empty($v['enabled']), 'submissionsOpen' => !empty($v['submissionsOpen']),
            'label' => $text('label', 20, 'The ad label', true), 'defaultCta' => $text('defaultCta', 30, 'The default button text', true),
            'intro' => $text('intro', 600, 'The introduction'), 'terms' => $text('terms', 2000, 'The terms'),
            'expiryWarningDays' => $int($v['expiryWarningDays'] ?? null, 0, 60, 'Days before expiry to warn'),
            'maxDurationDays' => $int($v['maxDurationDays'] ?? null, 1, 3650, 'Longest an ad may run (days)'),
            'maxPendingPerEmail' => $int($v['maxPendingPerEmail'] ?? null, 1, 50, 'Open orders per email'),
            'placements' => $place,
        ];
    }

    public static function saveSettings(array $clean, int $actorId): void
    {
        self::pdo()->prepare('INSERT INTO site_settings (setting_key, value, updated_by) VALUES (\'ads\', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)')
            ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $actorId]);
        Cache::flush();
    }

    // ------------------------------------------------------------------ small helpers

    /** Plain text: control characters removed, trimmed, cut to $max characters. */
    public static function plain(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr(trim($s), 0, $max);
    }

    /** https:// only, with a real host. Returns the address or null. */
    public static function link(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 500 || preg_match('/[\s<>"\'\\\\]/', $url)) {
            return null;
        }
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $host = strtolower((string) $p['host']);
        if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;                                 // no bare IP addresses, no "localhost", no odd characters
        }
        return $url;
    }

    /** A YouTube or Vimeo address as ['provider','id','embed'], or null. We never host video. */
    public static function video(string $url): ?array
    {
        $u = self::link($url);
        if ($u === null) {
            return null;
        }
        $p = parse_url($u);
        $host = preg_replace('/^(www\.|m\.)/', '', strtolower((string) $p['host']));
        $path = (string) ($p['path'] ?? '');
        parse_str((string) ($p['query'] ?? ''), $q);
        if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})$#', $path, $m)) {
            $id = $m[1];
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch' && isset($q['v']) && is_string($q['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $q['v'])) {
                $id = $q['v'];
            } elseif (preg_match('#^/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})$#', $path, $m)) {
                $id = $m[1];
            } else {
                return null;
            }
        } elseif ($host === 'vimeo.com' && preg_match('#^/(\d{6,12})$#', $path, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1], 'embed' => 'https://player.vimeo.com/video/' . $m[1]];
        } else {
            return null;
        }
        return ['provider' => 'youtube', 'id' => $id, 'embed' => 'https://www.youtube-nocookie.com/embed/' . $id];
    }

    /** A list of placement keys, kept only if valid, in a fixed order. */
    public static function cleanPlacements(mixed $v): array
    {
        $v = is_array($v) ? $v : [];
        return array_values(array_filter(self::PLACEMENTS, fn ($k) => in_array($k, $v, true)));
    }

    private static function lagos(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($v), new \DateTimeZone('Africa/Lagos')))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Enter a valid start date.');
        }
    }

    private static function when(?string $db): string
    {
        return $db === null ? '' : date('j M Y', strtotime($db));
    }

    public static function statusLabel(string $s): string
    {
        return self::STATUS_LABEL[$s] ?? $s;
    }

    public static function currency(): string
    {
        $c = self::pdo()->query("SELECT s.currency FROM editions e LEFT JOIN edition_settings s ON s.edition_id = e.id WHERE e.status = 'active' LIMIT 1")->fetchColumn();
        return $c ? (string) $c : 'NGN';
    }

    /** Payments need an edition. Ads belong to the whole site, so use the active one (or the newest). */
    public static function paymentEdition(): ?int
    {
        $id = self::pdo()->query("SELECT id FROM editions WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $id = $id ?: self::pdo()->query('SELECT id FROM editions ORDER BY id DESC LIMIT 1')->fetchColumn();
        return $id === false || $id === null ? null : (int) $id;
    }

    public static function reference(): string
    {
        return 'AD-' . strtoupper(bin2hex(random_bytes(5)));
    }

    // ------------------------------------------------------------------ categories

    public static function categoryShape(array $c, bool $admin = false): array
    {
        $out = [
            'id' => (string) $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'price' => (float) $c['price'],
            'durationDays' => (int) $c['duration_days'], 'placements' => self::cleanPlacements(json_decode((string) $c['placements'], true)),
            'mediaMode' => $c['media_mode'], 'requireLogo' => (bool) $c['require_logo'], 'requireBanner' => (bool) $c['require_banner'],
            'maxImageKb' => (int) $c['max_image_kb'], 'maxHeadline' => (int) $c['max_headline'], 'maxText' => (int) $c['max_text'],
        ];
        if ($admin) {
            $out += ['slug' => $c['slug'], 'maxRunning' => (int) $c['max_running'], 'active' => (bool) $c['active'], 'sortOrder' => (int) $c['sort_order']];
        }
        return $out;
    }

    public static function category(int $id): ?array
    {
        $st = self::pdo()->prepare('SELECT * FROM ad_categories WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** What the public form shows. */
    public static function publicOptions(): array
    {
        $s = self::settings();
        $rows = self::pdo()->query('SELECT * FROM ad_categories WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
        return [
            'open' => $s['enabled'] && $s['submissionsOpen'] && $rows !== [],
            'intro' => $s['intro'], 'terms' => $s['terms'], 'label' => $s['label'], 'defaultCta' => $s['defaultCta'], 'currency' => self::currency(),
            'categories' => array_map(fn ($c) => self::categoryShape($c), $rows),
        ];
    }

    // ------------------------------------------------------------------ content rules (shared by the public form and admin)

    /** @return array{0:array,1:array} [clean values, field errors] */
    public static function content(array $in, array $cat, bool $admin = false): array
    {
        $f = [];
        $c = [];
        $c['business_name'] = self::plain((string) ($in['business_name'] ?? ''), 120);
        if (mb_strlen($c['business_name']) < 2) $f['business_name'] = 'Enter the business name.';
        $c['contact_name'] = self::plain((string) ($in['contact_name'] ?? ''), 100);
        if (!$admin && mb_strlen($c['contact_name']) < 2) $f['contact_name'] = 'Enter a contact name.';
        $c['email'] = mb_strtolower(self::plain((string) ($in['email'] ?? ''), 190));
        if (($c['email'] !== '' || !$admin) && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $f['email'] = 'Enter a valid email address.';
        $c['phone'] = self::plain((string) ($in['phone'] ?? ''), 30);
        if (($c['phone'] !== '' || !$admin) && !preg_match('/^[0-9+()\s-]{7,30}$/', $c['phone'])) $f['phone'] = 'Enter a valid phone number.';
        $maxH = (int) $cat['max_headline'];
        $maxT = (int) $cat['max_text'];
        $c['headline'] = self::plain((string) ($in['headline'] ?? ''), $maxH + 1);
        if (mb_strlen($c['headline']) < 3) $f['headline'] = 'Enter a headline.';
        elseif (mb_strlen($c['headline']) > $maxH) $f['headline'] = "The headline can have up to $maxH characters.";
        $c['body'] = self::plain((string) ($in['body'] ?? ''), $maxT + 1);
        if (mb_strlen($c['body']) > $maxT) $f['body'] = "The description can have up to $maxT characters.";
        elseif ($cat['media_mode'] === 'text' && mb_strlen($c['body']) < 10) $f['body'] = 'Write a short description (at least 10 characters).';
        $link = self::link((string) ($in['link_url'] ?? ''));
        if ($link === null) $f['link_url'] = 'Enter a full https:// address.';
        $c['link_url'] = $link ?? '';
        $c['cta_label'] = self::plain((string) ($in['cta_label'] ?? ''), 30);
        $c['video_url'] = null;
        $v = trim((string) ($in['video_url'] ?? ''));
        if ($v !== '' && $cat['media_mode'] === 'image_video') {
            if (self::video($v) === null) $f['video_url'] = 'Use a YouTube or Vimeo link.';
            else $c['video_url'] = self::link($v);
        }
        return [$c, $f];
    }

    /**
     * Saves the logo and banner that came with a form, as the category allows. Returns [paths, errors, savedFiles]; on any error nothing stays on disk.
     * @param array|null $existing the ad's current paths (when changing an ad); a missing upload keeps the old file
     */
    public static function images(array $cat, array $files, ?array $existing = null): array
    {
        $paths = ['logo_path' => $existing['logo_path'] ?? null, 'banner_path' => $existing['banner_path'] ?? null];
        $err = [];
        $saved = [];
        $mode = $cat['media_mode'];
        foreach (['logo' => 'require_logo', 'banner' => 'require_banner'] as $kind => $requireKey) {
            $has = isset($files[$kind]) && ($files[$kind]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($mode === 'text') {
                continue;                                  // a text-only package takes no images
            }
            if (!$has) {
                if ($cat[$requireKey] && empty($paths[$kind . '_path'])) {
                    $err[$kind] = 'Upload your ' . $kind . '.';
                }
                continue;
            }
            try {
                $saved[] = $paths[$kind . '_path'] = AdMedia::save($files[$kind], $kind, (int) $cat['max_image_kb']);
            } catch (\InvalidArgumentException $e) {
                $err[$kind] = $e->getMessage();
            }
        }
        if ($err) {
            foreach ($saved as $p) {
                @unlink(dirname(Photos::root()) . '/' . $p);
            }
            return [$existing ?? ['logo_path' => null, 'banner_path' => null], $err, []];
        }
        return [$paths, [], $saved];
    }

    // ------------------------------------------------------------------ shapes

    public static function adminShape(array $r): array
    {
        $live = !empty($r['is_live']);
        $phase = $r['status'] === 'approved' ? ($live ? 'live' : (strtotime((string) $r['ends_at']) <= time() ? 'expired' : 'scheduled')) : $r['status'];
        $video = $r['video_url'] ? self::video((string) $r['video_url']) : null;
        return [
            'id' => (string) $r['id'], 'reference' => $r['reference'], 'status' => $r['status'], 'phase' => $phase, 'statusLabel' => self::statusLabel($r['status']),
            'source' => $r['source'], 'paidVia' => $r['paid_via'], 'categoryId' => (string) $r['category_id'], 'category' => $r['category_name'],
            'business' => $r['business_name'], 'contact' => $r['contact_name'], 'email' => $r['email'], 'phone' => $r['phone'],
            'headline' => $r['headline'], 'text' => $r['body'], 'link' => $r['link_url'], 'cta' => $r['cta_label'], 'videoUrl' => $r['video_url'], 'video' => $video,
            'logo' => $r['logo_path'], 'banner' => $r['banner_path'], 'placements' => self::cleanPlacements(json_decode((string) $r['placements'], true)),
            'durationDays' => (int) $r['duration_days'], 'amount' => (float) $r['amount'], 'paidAt' => $r['paid_at'], 'paidNote' => $r['paid_note'],
            'startsAt' => $r['starts_at'], 'endsAt' => $r['ends_at'], 'reviewNote' => $r['review_note'], 'reviewedAt' => $r['reviewed_at'],
            'reviewer' => $r['reviewer_name'] ?? null, 'renewalOf' => $r['renewal_of'] === null ? null : (string) $r['renewal_of'],
            'refundDue' => in_array($r['status'], ['rejected', 'removed'], true) && $r['paid_via'] !== null && $r['paid_via'] !== 'free' && (float) $r['amount'] > 0 && $r['refunded_at'] === null,
            'refundedAt' => $r['refunded_at'], 'refundNote' => $r['refund_note'],
            'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks'], 'createdAt' => $r['created_at'],
        ];
    }

    private static function payload(array $r): array
    {
        $video = $r['video_url'] ? self::video((string) $r['video_url']) : null;
        return [
            'id' => (int) $r['id'], 'headline' => $r['headline'], 'text' => $r['body'], 'business' => $r['business_name'],
            'cta' => $r['cta_label'] !== '' ? $r['cta_label'] : self::settings()['defaultCta'],
            'logo' => $r['logo_path'], 'banner' => $r['banner_path'],
            'video' => $video ? ['provider' => $video['provider'], 'embed' => $video['embed']] : null,
        ];
    }

    // ------------------------------------------------------------------ serving

    private const LIVE = "a.status = 'approved' AND a.starts_at <= NOW() AND a.ends_at > NOW()";

    /** What one place on the site shows right now. Mixed in a fresh order each time the cache is rebuilt, so every ad gets its turn. */
    public static function serve(string $placement): array
    {
        self::sweepIfDue();
        $s = self::settings();
        $cfg = $s['placements'][$placement] ?? null;
        $empty = ['placement' => $placement, 'label' => $s['label'], 'rotateSeconds' => $cfg['rotateSeconds'] ?? 8, 'ads' => []];
        if ($cfg === null || !$s['enabled'] || !$cfg['enabled']) {
            return $empty;
        }
        $rows = self::pdo()->query('SELECT a.* FROM ads a WHERE ' . self::LIVE . ' ORDER BY a.id')->fetchAll();
        $mine = array_values(array_filter($rows, fn ($r) => in_array($placement, json_decode((string) $r['placements'], true) ?: [], true)));
        shuffle($mine);
        $empty['ads'] = array_map([self::class, 'payload'], array_slice($mine, 0, $cfg['maxAds']));
        return $empty;
    }

    /** Counts one showing of each listed ad (only ads that are really live in that place). Aggregates only. */
    public static function recordSeen(string $placement, array $ids): int
    {
        if (!in_array($placement, self::PLACEMENTS, true)) {
            return 0;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_slice($ids, 0, 30)), fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = self::pdo()->prepare("SELECT a.id, a.placements FROM ads a WHERE " . self::LIVE . " AND a.id IN ($in)");
        $st->execute($ids);
        $n = 0;
        foreach ($st->fetchAll() as $r) {
            if (in_array($placement, json_decode((string) $r['placements'], true) ?: [], true)) {
                self::bump((int) $r['id'], $placement, 'impressions');
                $n++;
            }
        }
        return $n;
    }

    public static function bump(int $adId, string $placement, string $col): void
    {
        $col = $col === 'clicks' ? 'clicks' : 'impressions';
        $pdo = self::pdo();
        $pdo->prepare("INSERT INTO ad_stats (ad_id, day, placement, $col) VALUES (?, CURDATE(), ?, 1) ON DUPLICATE KEY UPDATE $col = $col + 1")->execute([$adId, $placement]);
        $pdo->prepare("UPDATE ads SET $col = $col + 1 WHERE id = ?")->execute([$adId]);
    }

    /** The address to send a clicker to, if the ad is live. */
    public static function liveLink(int $id): ?array
    {
        $st = self::pdo()->prepare('SELECT a.id, a.link_url FROM ads a WHERE ' . self::LIVE . ' AND a.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    // ------------------------------------------------------------------ creating

    /**
     * Inserts an ad. $clean comes from content(), $paths from images().
     * @return array the new row
     */
    public static function insert(array $cat, array $clean, array $paths, array $extra): array
    {
        $pdo = self::pdo();
        $ref = self::reference();
        $pdo->prepare(
            'INSERT INTO ads (reference, category_id, category_name, status, source, business_name, contact_name, email, phone, headline, body, link_url, cta_label, video_url,
                              logo_path, banner_path, placements, duration_days, amount, renewal_of, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ref, $cat['id'], $cat['name'], $extra['status'] ?? 'draft', $extra['source'] ?? 'advertiser', $clean['business_name'], $clean['contact_name'], $clean['email'], $clean['phone'],
            $clean['headline'], $clean['body'], $clean['link_url'], $clean['cta_label'], $clean['video_url'], $paths['logo_path'], $paths['banner_path'],
            json_encode($extra['placements'] ?? self::cleanPlacements(json_decode((string) $cat['placements'], true))), $extra['duration_days'] ?? (int) $cat['duration_days'],
            $extra['amount'] ?? (float) $cat['price'], $extra['renewal_of'] ?? null, $extra['created_by'] ?? null,
        ]);
        return self::find((int) $pdo->lastInsertId());
    }

    public static function find(int $id): array
    {
        $st = self::pdo()->prepare('SELECT a.*, (a.starts_at <= NOW() AND a.ends_at > NOW() AND a.status = \'approved\') AS is_live, p.name AS reviewer_name FROM ads a LEFT JOIN people p ON p.id = a.reviewed_by WHERE a.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: throw new \DomainException('That ad was not found.');
    }

    public static function findByRef(string $ref): ?array
    {
        $st = self::pdo()->prepare('SELECT a.*, (a.starts_at <= NOW() AND a.ends_at > NOW() AND a.status = \'approved\') AS is_live, p.name AS reviewer_name FROM ads a LEFT JOIN people p ON p.id = a.reviewed_by WHERE a.reference = ?');
        $st->execute([$ref]);
        return $st->fetch() ?: null;
    }

    /** How many orders from this email are still open (not paid for, or paid and waiting for review). */
    public static function openOrders(string $email): int
    {
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM ads WHERE email = ? AND source = 'advertiser' AND status IN ('awaiting_payment', 'paid')");
        $st->execute([$email]);
        return (int) $st->fetchColumn();
    }

    // ------------------------------------------------------------------ payment is complete

    /** Runs inside the payment lock (PaymentService::credit): the ad that was waiting for this payment is now paid. */
    public static function creditPayment(\PDO $db, array $payment): void
    {
        $st = $db->prepare("SELECT id FROM ads WHERE payment_id = ? AND status IN ('awaiting_payment', 'draft') FOR UPDATE");
        $st->execute([$payment['id']]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            self::markPaid($db, (int) $id, 'online', null, null);
        }
    }

    /**
     * An ad has been paid for (online, by cash, or it was free). A first order then waits for review.
     * A renewal of an ad that was already approved goes straight on: it starts when the old one ends, because the content was already reviewed.
     */
    public static function markPaid(\PDO $db, int $id, string $via, ?int $actorId, ?string $note): string
    {
        $db->prepare("UPDATE ads SET status = 'paid', paid_via = ?, paid_at = NOW(), marked_paid_by = ?, paid_note = ? WHERE id = ? AND status IN ('awaiting_payment', 'draft')")
            ->execute([$via, $actorId, $note, $id]);
        $ad = self::find($id);
        $status = 'paid';
        if ($ad['renewal_of'] !== null) {
            $orig = $db->prepare('SELECT * FROM ads WHERE id = ?');
            $orig->execute([$ad['renewal_of']]);
            $o = $orig->fetch();
            if ($o && in_array($o['status'], ['approved', 'expired'], true) && $o['link_url'] === $ad['link_url']) {
                $start = $o['status'] === 'approved' && $o['ends_at'] !== null && strtotime((string) $o['ends_at']) > time() ? (string) $o['ends_at'] : date('Y-m-d H:i:s');
                $db->prepare("UPDATE ads SET status = 'approved', starts_at = ?, ends_at = DATE_ADD(?, INTERVAL duration_days DAY), reviewed_at = NOW(), review_note = 'Renewal of an ad that was already approved.' WHERE id = ? AND status = 'paid'")
                    ->execute([$start, $start, $id]);
                $status = 'approved';
                Cache::flush();
            }
        }
        $ad = self::find($id);
        if ($status === 'approved') {
            self::mail('ad_approved', $ad);
        } else {
            self::mail('ad_received', $ad);
            self::mailStaff($ad);
        }
        return $status;
    }

    // ------------------------------------------------------------------ review

    /** @throws \DomainException a message that is safe to show */
    public static function approve(int $id, int $actorId, ?string $startsAt, ?array $placements): array
    {
        $ad = self::find($id);
        if ($ad['status'] !== 'paid') {
            throw new \DomainException('Only an ad that is paid and waiting for review can be approved.');
        }
        $start = self::lagos($startsAt);
        if ($start === null || strtotime($start) < time()) {
            $start = date('Y-m-d H:i:s');
        }
        if (strtotime($start) > time() + 90 * 86400) {
            throw new \DomainException('Choose a start date within the next 90 days.');
        }
        $places = $placements === null ? self::cleanPlacements(json_decode((string) $ad['placements'], true)) : self::cleanPlacements($placements);
        if ($places === []) {
            throw new \DomainException('Choose at least one place for this ad to appear.');
        }
        $ends = date('Y-m-d H:i:s', strtotime($start . ' +' . (int) $ad['duration_days'] . ' days'));
        $cat = self::category((int) $ad['category_id']);
        if ($cat && (int) $cat['max_running'] > 0) {
            $st = self::pdo()->prepare("SELECT COUNT(*) FROM ads WHERE category_id = ? AND status = 'approved' AND ends_at > ? AND starts_at < ? AND id <> ?");
            $st->execute([$cat['id'], $start, $ends, $id]);
            if ((int) $st->fetchColumn() >= (int) $cat['max_running']) {
                throw new \DomainException('This package already has its limit of ads running in that period. Choose a later start date.');
            }
        }
        self::pdo()->prepare("UPDATE ads SET status = 'approved', starts_at = ?, ends_at = ?, placements = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = NULL, expiring_notified = 0 WHERE id = ? AND status = 'paid'")
            ->execute([$start, $ends, json_encode($places), $actorId, $id]);
        Cache::flush();
        $ad = self::find($id);
        self::mail('ad_approved', $ad);
        return $ad;
    }

    public static function reject(int $id, int $actorId, string $note): array
    {
        $note = self::plain($note, 500);
        if (mb_strlen($note) < 3) {
            throw new \DomainException('Write a short reason; the advertiser will receive it.');
        }
        $ad = self::find($id);
        if (!in_array($ad['status'], ['paid', 'awaiting_payment', 'draft'], true)) {
            throw new \DomainException('Only an ad that has not gone live can be rejected. Use Remove for a running ad.');
        }
        self::pdo()->prepare("UPDATE ads SET status = 'rejected', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('paid', 'awaiting_payment', 'draft')")
            ->execute([$note, $actorId, $id]);
        $ad = self::find($id);
        self::mail('ad_rejected', $ad, ['note' => $note]);
        return $ad;
    }

    public static function remove(int $id, int $actorId, string $reason): array
    {
        $reason = self::plain($reason, 500);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('Write a short reason for removing this ad.');
        }
        $ad = self::find($id);
        if ($ad['status'] === 'removed') {
            throw new \DomainException('That ad is already removed.');
        }
        self::pdo()->prepare("UPDATE ads SET status = 'removed', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([$reason, $actorId, $id]);
        Cache::flush();
        return self::find($id);
    }

    public static function extend(int $id, int $days): array
    {
        $ad = self::find($id);
        $max = self::settings()['maxDurationDays'];
        if ($ad['status'] !== 'approved' || strtotime((string) $ad['ends_at']) <= time()) {
            throw new \DomainException('Only a running or scheduled ad can be extended.');
        }
        if ($days < 1 || $days > $max) {
            throw new \DomainException("Add between 1 and $max days.");
        }
        $newEnd = strtotime((string) $ad['ends_at'] . " +$days days");
        if ($newEnd - strtotime((string) $ad['starts_at']) > $max * 86400) {
            throw new \DomainException("No ad may run for more than $max days in total.");
        }
        self::pdo()->prepare('UPDATE ads SET ends_at = ?, expiring_notified = 0 WHERE id = ?')->execute([date('Y-m-d H:i:s', $newEnd), $id]);
        Cache::flush();
        return self::find($id);
    }

    // ------------------------------------------------------------------ the sweep: expire, warn, tidy up

    public static function sweepIfDue(): void
    {
        try {
            Cache::remember('ads_sweep', 300, static function (): string {
                self::sweep();
                return '1';
            });
        } catch (\Throwable $e) {
            error_log('Ad sweep failed: ' . $e->getMessage());
        }
    }

    /** @return array{expired:int,warned:int,abandoned:int} */
    public static function sweep(): array
    {
        $pdo = self::pdo();
        $out = ['expired' => 0, 'warned' => 0, 'abandoned' => 0];

        $ids = $pdo->query("SELECT id FROM ads WHERE status = 'approved' AND ends_at <= NOW()")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $u = $pdo->prepare("UPDATE ads SET status = 'expired' WHERE id = ? AND status = 'approved' AND ends_at <= NOW()");
            $u->execute([$id]);
            if ($u->rowCount() === 1) {
                $out['expired']++;
                self::mail('ad_expired', self::find((int) $id));
            }
        }
        if ($out['expired'] > 0) {
            Cache::flush();
        }

        $warn = self::settings()['expiryWarningDays'];
        if ($warn > 0) {
            $st = $pdo->prepare("SELECT id FROM ads WHERE status = 'approved' AND expiring_notified = 0 AND duration_days > ? AND starts_at <= NOW() AND ends_at > NOW() AND ends_at <= DATE_ADD(NOW(), INTERVAL ? DAY)");
            $st->execute([$warn, $warn]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $u = $pdo->prepare("UPDATE ads SET expiring_notified = 1 WHERE id = ? AND expiring_notified = 0");
                $u->execute([$id]);
                if ($u->rowCount() === 1) {
                    $out['warned']++;
                    self::mail('ad_expiring', self::find((int) $id));
                }
            }
        }

        // An order nobody paid for within a day stops counting against the person's open-order limit. A late payment still completes it.
        $u = $pdo->exec("UPDATE ads SET status = 'draft' WHERE status = 'awaiting_payment' AND created_at < (NOW() - INTERVAL 1 DAY)");
        $out['abandoned'] = (int) $u;
        return $out;
    }

    // ------------------------------------------------------------------ email

    public static function statusLink(array $ad): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/advertise?' . http_build_query(['ref' => $ad['reference'], 'email' => $ad['email']]);
    }

    /** One of the five advertiser emails. Does nothing when the ad has no email address (an admin's free ad). */
    public static function mail(string $type, array $ad, array $extra = []): void
    {
        if ($ad['email'] === '') {
            return;
        }
        $daysLeft = $ad['ends_at'] !== null ? max(0, (int) ceil((strtotime((string) $ad['ends_at']) - time()) / 86400)) : 0;
        Outbox::queueAndSend($type, $ad['email'], $extra + [
            'applicantName' => $ad['contact_name'], 'business' => $ad['business_name'], 'reference' => $ad['reference'], 'headline' => $ad['headline'],
            'category' => $ad['category_name'], 'starts' => self::when($ad['starts_at']), 'ends' => self::when($ad['ends_at']), 'daysLeft' => $daysLeft,
            'amount' => (float) $ad['amount'] > 0 ? '₦' . number_format((float) $ad['amount']) : 'Free', 'link' => self::statusLink($ad),
        ]);
    }

    /** Tells the contact address that an ad is waiting for review. */
    private static function mailStaff(array $ad): void
    {
        $to = (string) (SettingsApi::value('contact')['email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        Outbox::queueAndSend('ad_review_needed', $to, [
            'business' => $ad['business_name'], 'reference' => $ad['reference'], 'headline' => $ad['headline'], 'category' => $ad['category_name'],
            'amount' => (float) $ad['amount'] > 0 ? '₦' . number_format((float) $ad['amount']) : 'Free',
        ]);
    }

    // ------------------------------------------------------------------ what an advertiser may see about their own ad

    public static function advertiserView(array $ad): array
    {
        $a = self::adminShape($ad);
        $st = self::pdo()->prepare('SELECT day, SUM(impressions) AS i, SUM(clicks) AS c FROM ad_stats WHERE ad_id = ? AND day >= (CURDATE() - INTERVAL 29 DAY) GROUP BY day ORDER BY day');
        $st->execute([$ad['id']]);
        $days = array_map(fn ($r) => ['day' => $r['day'], 'impressions' => (int) $r['i'], 'clicks' => (int) $r['c']], $st->fetchAll());
        return [
            'reference' => $a['reference'], 'status' => $a['status'], 'phase' => $a['phase'], 'statusLabel' => $a['statusLabel'], 'category' => $a['category'],
            'business' => $a['business'], 'headline' => $a['headline'], 'text' => $a['text'], 'link' => $a['link'], 'logo' => $a['logo'], 'banner' => $a['banner'],
            'amount' => $a['amount'], 'startsAt' => $a['startsAt'], 'endsAt' => $a['endsAt'], 'durationDays' => $a['durationDays'],
            'note' => in_array($a['status'], ['rejected', 'removed'], true) ? $a['reviewNote'] : null,
            'refundDue' => $a['refundDue'], 'refundedAt' => $a['refundedAt'],
            'impressions' => $a['impressions'], 'clicks' => $a['clicks'], 'days' => $days,
            'canRenew' => self::canRenew($ad),
        ];
    }

    public static function canRenew(array $ad): bool
    {
        if (!in_array($ad['status'], ['approved', 'expired'], true)) {
            return false;
        }
        $cat = self::category((int) $ad['category_id']);
        if (!$cat || !(int) $cat['active'] || !self::settings()['submissionsOpen']) {
            return false;
        }
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM ads WHERE renewal_of = ? AND (status IN ('awaiting_payment', 'paid') OR (status = 'approved' AND ends_at > ?))");
        $st->execute([$ad['id'], $ad['ends_at'] ?? date('Y-m-d H:i:s')]);
        return (int) $st->fetchColumn() === 0;
    }

    // ------------------------------------------------------------------ report

    public static function report(string $from, string $to): array
    {
        $pdo = self::pdo();
        $rng = [$from, $to];
        $tot = $pdo->prepare('SELECT COALESCE(SUM(impressions),0) AS i, COALESCE(SUM(clicks),0) AS c FROM ad_stats WHERE day BETWEEN ? AND ?');
        $tot->execute($rng);
        $t = $tot->fetch();
        $byDay = $pdo->prepare('SELECT day, SUM(impressions) AS i, SUM(clicks) AS c FROM ad_stats WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day');
        $byDay->execute($rng);
        $byPlace = $pdo->prepare('SELECT placement, SUM(impressions) AS i, SUM(clicks) AS c FROM ad_stats WHERE day BETWEEN ? AND ? GROUP BY placement ORDER BY placement');
        $byPlace->execute($rng);
        $perAd = $pdo->prepare(
            'SELECT a.id, a.reference, a.headline, a.business_name, a.status, a.category_name, COALESCE(SUM(s.impressions),0) AS i, COALESCE(SUM(s.clicks),0) AS c
               FROM ads a JOIN ad_stats s ON s.ad_id = a.id AND s.day BETWEEN ? AND ? GROUP BY a.id ORDER BY i DESC LIMIT 100'
        );
        $perAd->execute($rng);
        $ctr = fn ($i, $c) => (int) $i > 0 ? round(100 * (int) $c / (int) $i, 2) : 0.0;
        $rev = $pdo->query(
            "SELECT category_name, COUNT(*) AS n, SUM(amount) AS total FROM ads
              WHERE paid_via IN ('online', 'manual') AND amount > 0 AND refunded_at IS NULL AND status NOT IN ('draft', 'awaiting_payment') GROUP BY category_name ORDER BY total DESC"
        )->fetchAll();
        $counts = $pdo->query("SELECT status, COUNT(*) FROM ads GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $refunds = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM ads WHERE status IN ('rejected', 'removed') AND paid_via IN ('online', 'manual') AND amount > 0 AND refunded_at IS NULL")->fetch();
        return [
            'from' => $from, 'to' => $to,
            'impressions' => (int) $t['i'], 'clicks' => (int) $t['c'], 'ctr' => $ctr($t['i'], $t['c']),
            'days' => array_map(fn ($r) => ['day' => $r['day'], 'impressions' => (int) $r['i'], 'clicks' => (int) $r['c']], $byDay->fetchAll()),
            'placements' => array_map(fn ($r) => ['placement' => $r['placement'], 'label' => self::settings()['placements'][$r['placement']]['label'] ?? $r['placement'], 'impressions' => (int) $r['i'], 'clicks' => (int) $r['c'], 'ctr' => $ctr($r['i'], $r['c'])], $byPlace->fetchAll()),
            'ads' => array_map(fn ($r) => ['id' => (string) $r['id'], 'reference' => $r['reference'], 'headline' => $r['headline'], 'business' => $r['business_name'], 'status' => $r['status'], 'category' => $r['category_name'],
                'impressions' => (int) $r['i'], 'clicks' => (int) $r['c'], 'ctr' => $ctr($r['i'], $r['c'])], $perAd->fetchAll()),
            'revenue' => ['total' => (float) array_sum(array_column($rev, 'total')), 'byCategory' => array_map(fn ($r) => ['category' => $r['category_name'], 'ads' => (int) $r['n'], 'total' => (float) $r['total']], $rev)],
            'counts' => array_map('intval', $counts),
            'refundsDue' => ['count' => (int) $refunds['n'], 'total' => (float) $refunds['total']],
        ];
    }

    /** Ad revenue for the dashboard: money received for ads, not refunded. Never mixed into ticket or vote money. */
    public static function revenue(): float
    {
        return (float) self::pdo()->query("SELECT COALESCE(SUM(amount),0) FROM ads WHERE paid_via IN ('online', 'manual') AND amount > 0 AND refunded_at IS NULL AND status NOT IN ('draft', 'awaiting_payment')")->fetchColumn();
    }
}