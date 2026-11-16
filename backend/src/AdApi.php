<?php
declare(strict_types=1);

namespace App;

/**
 * Public advertising endpoints: what to show (and counting it), the click redirect, the order form, paying, and the advertiser's private status page.
 * The price always comes from the package in the database, never from the browser. Nothing here shows vote counts or rankings.
 */
final class AdApi
{
    private static function fail(string $message, int $status = 422, array $fields = []): never
    {
        Response::error($message, $status, $fields);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function throttle(string $kind, int $max, int $minutes, bool $failuresOnly = false): void
    {
        $id = hash('sha256', "$kind:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)' . ($failuresOnly ? ' AND success = 0' : ''));
        $st->execute([$id]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many requests. Please wait a little and try again.', 429);
        }
    }

    private static function record(string $kind, bool $success): void
    {
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, ?)')
            ->execute([hash('sha256', "$kind:" . Request::ip()), Request::ip(), $success ? 1 : 0]);
    }

    private static function appUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/');
    }

    // ------------------------------------------------------------------ showing ads

    /** GET /ads/serve?placement=site_footer : the ads for one place. Cached for half a minute. */
    public static function serve(): never
    {
        $placement = (string) ($_GET['placement'] ?? '');
        if (!in_array($placement, Ads::PLACEMENTS, true)) {
            self::fail('Unknown place.', 404);
        }
        $json = Cache::remember('ads_serve_' . $placement, 30, fn (): string => (string) json_encode(Ads::serve($placement), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Response::cachedJson($json, 30);
    }

    /** POST /ads/seen {placement, ids[]} : the page showed these ads. Counts only. */
    public static function seen(): never
    {
        $in = Request::json();
        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
        $n = Ads::recordSeen(Request::str($in, 'placement', 40), $ids);
        Response::json(['ok' => true, 'counted' => $n]);
    }

    /** GET /ads/click/{id}?p=place : count the click, then send the visitor on. A stopped ad just goes to the home page. */
    public static function click(int $id): never
    {
        $home = self::appUrl() . '/';
        $ad = Ads::liveLink($id);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        if ($ad === null) {
            header('Location: ' . $home, true, 302);
            exit;
        }
        $place = (string) ($_GET['p'] ?? '');
        $key = hash('sha256', "adclick:$id:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL 60 MINUTE)');
        $st->execute([$key]);
        if ((int) $st->fetchColumn() < 20) {                         // one visitor cannot inflate the count by pressing again and again
            self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, 1)')->execute([$key, Request::ip()]);
            Ads::bump($id, in_array($place, Ads::PLACEMENTS, true) ? $place : 'site_footer', 'clicks');
        }
        header('Location: ' . $ad['link_url'], true, 302);
        exit;
    }

    // ------------------------------------------------------------------ ordering

    /** POST /ads/apply (multipart form: the fields, plus the logo and banner files the package asks for). */
    public static function apply(): never
    {
        self::throttle('ad-apply', 10, 60);
        $s = Ads::settings();
        if (!$s['enabled'] || !$s['submissionsOpen']) {
            self::fail('Ad orders are closed right now.', 403);
        }
        $in = $_POST;
        $cat = Ads::category((int) ($in['category_id'] ?? 0));
        if (!$cat || !(int) $cat['active']) {
            self::fail('Choose an ad package.', 422, ['category_id' => 'Choose an ad package.']);
        }
        [$clean, $f] = Ads::content($in, $cat);
        if ($s['terms'] !== '' && empty($in['accept'])) {
            $f['accept'] = 'Please accept the terms.';
        }
        [$paths, $imageErrors, $saved] = Ads::images($cat, $_FILES);
        $f += $imageErrors;
        if (!$f && $clean['email'] !== '' && Ads::openOrders($clean['email']) >= $s['maxPendingPerEmail']) {
            $f['email'] = 'You already have the most open orders allowed. Wait for them to be reviewed.';
        }
        if ($f) {
            foreach ($saved as $p) {
                AdMedia::delete($p);
            }
            self::fail(reset($f), 422, $f);
        }
        self::record('ad-apply', true);
        self::startOrder($cat, $clean, $paths, [], $clean['contact_name']);
    }

    /**
     * Creates the ad and, when it costs money, the payment. Free packages go straight to review.
     * @param array $extra renewal_of for a renewal
     */
    private static function startOrder(array $cat, array $clean, array $paths, array $extra, string $payerName): never
    {
        $pdo = self::pdo();
        $price = round((float) $cat['price'], 2);                    // OUR price, never the browser's
        $edition = Ads::paymentEdition();
        if ($price > 0 && $edition === null) {
            self::fail('Payments are not available yet.', 503);
        }
        $ad = Ads::insert($cat, $clean, $paths, $extra + ['status' => $price > 0 ? 'awaiting_payment' : 'draft', 'amount' => $price]);

        if ($price <= 0) {
            $status = Database::transaction(fn (\PDO $db) => Ads::markPaid($db, (int) $ad['id'], 'free', null, null));
            Response::json(['ok' => true, 'reference' => $ad['reference'], 'amount' => 0, 'free' => true, 'status' => $status, 'email' => $ad['email']]);
        }

        $currency = Ads::currency();
        Database::transaction(function (\PDO $db) use ($ad, $edition, $price, $currency) {
            $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (?, 'ad', ?, ?, ?, ?, 'pending')")
                ->execute([$edition, $ad['reference'], $price, $currency, $ad['email']]);
            $db->prepare('UPDATE ads SET payment_id = ? WHERE id = ?')->execute([(int) $db->lastInsertId(), $ad['id']]);
        });

        try {
            $link = Flutterwave::createPayment([
                'reference' => $ad['reference'], 'amount' => $price, 'currency' => $currency, 'email' => $ad['email'], 'name' => $payerName,
                'redirectUrl' => self::appUrl() . '/advertise?' . http_build_query(['pay' => 'return', 'ref' => $ad['reference']]), 'purpose' => 'ad',
                'title' => SettingsApi::brandName() . ' advertising', 'description' => $cat['name'],
            ]);
        } catch (GatewayException $e) {
            error_log((string) $e);
            $pdo->prepare("UPDATE payments SET status = 'failed' WHERE reference = ?")->execute([$ad['reference']]);
            $pdo->prepare("UPDATE ads SET status = 'draft' WHERE id = ?")->execute([$ad['id']]);
            self::fail('The payment service is unavailable right now. You have not been charged. Please try again shortly.', 502);
        }
        Response::json(['ok' => true, 'reference' => $ad['reference'], 'amount' => $price, 'email' => $ad['email'], 'paymentLink' => $link]);
    }

    /** POST /ads/orders/{ref}/confirm : the advertiser came back from the payment page. Nothing is paid until the provider says so. */
    public static function confirm(string $reference): never
    {
        self::throttle('ad-confirm', 240, 15);
        self::record('ad-confirm', true);
        $ad = Ads::findByRef($reference) ?? self::fail('We could not find that order.', 404);
        $note = null;
        if ($ad['payment_id'] !== null) {
            $p = self::pdo()->prepare('SELECT reference FROM payments WHERE id = ?');
            $p->execute([$ad['payment_id']]);
            $result = PaymentService::settle((string) $p->fetchColumn());
            $status = $result['status'];
            $note = $result['note'] ?? null;
            $ad = Ads::findByRef($reference);
        } else {
            $status = in_array($ad['status'], ['paid', 'approved'], true) ? 'paid' : 'failed';
        }
        Response::json(['ok' => true, 'status' => $status, 'note' => $note, 'ad' => ['reference' => $ad['reference'], 'status' => $ad['status'], 'statusLabel' => Ads::statusLabel($ad['status'])]]);
    }

    private static function owned(array $in): array
    {
        $ref = strtoupper(Request::str($in, 'ref', 40));
        $email = mb_strtolower(Request::str($in, 'email', 190));
        $ad = $ref !== '' ? Ads::findByRef($ref) : null;
        if (!$ad || $ad['email'] === '' || $ad['email'] !== $email) {
            self::record('ad-lookup', false);
            self::fail('We could not find an ad with that reference and email address.', 404);
        }
        return $ad;
    }

    /** POST /ads/lookup {ref, email} : the advertiser's private status page. */
    public static function lookup(): never
    {
        self::throttle('ad-lookup', 40, 15, true);
        $ad = self::owned(Request::json());
        Response::json(['ok' => true, 'ad' => Ads::advertiserView($ad)]);
    }

    /** POST /ads/renew {ref, email} : buy another period of an ad that was already approved. Same content, current price. */
    public static function renew(): never
    {
        self::throttle('ad-lookup', 40, 15, true);
        self::throttle('ad-apply', 10, 60);
        $ad = self::owned(Request::json());
        if (!Ads::canRenew($ad)) {
            self::fail('This ad cannot be renewed right now.', 422);
        }
        $cat = Ads::category((int) $ad['category_id']) ?? self::fail('That package is no longer available.');
        self::record('ad-apply', true);
        $clean = ['business_name' => $ad['business_name'], 'contact_name' => $ad['contact_name'], 'email' => $ad['email'], 'phone' => $ad['phone'], 'headline' => $ad['headline'],
            'body' => $ad['body'], 'link_url' => $ad['link_url'], 'cta_label' => $ad['cta_label'], 'video_url' => $ad['video_url']];
        self::startOrder($cat, $clean, ['logo_path' => $ad['logo_path'], 'banner_path' => $ad['banner_path']],
            ['renewal_of' => (int) $ad['id'], 'placements' => Ads::cleanPlacements(json_decode((string) $ad['placements'], true))], $ad['contact_name']);
    }
}