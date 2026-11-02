<?php
declare(strict_types=1);

namespace App;

/** The only code that talks to Flutterwave. Keys come from config.php, never from the browser or the database. */
final class Flutterwave
{
    private static function base(): string
    {
        return rtrim(Env::get('FLW_BASE_URL', 'https://api.flutterwave.com/v3'), '/');
    }

    /** @return array{0:int,1:array} HTTP status and decoded JSON */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        $key = (string) Env::get('FLW_SECRET_KEY', '');
        if ($key === '') {
            throw new GatewayException('FLW_SECRET_KEY is not set in config.php');
        }
        $ch = curl_init(self::base() . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        if (($ca = Env::get('FLW_CA_BUNDLE')) !== null && $ca !== '') {
            $opts[CURLOPT_CAINFO] = $ca;                  // only needed if your XAMPP has no certificate list
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new GatewayException('Could not reach Flutterwave: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new GatewayException("Flutterwave sent an unreadable answer (HTTP $status)");
        }
        return [$status, $json];
    }

    /** Starts a hosted payment. Returns the page address to send the buyer to. */
    public static function createPayment(array $p): string
    {
        [, $r] = self::request('POST', '/payments', [
            'tx_ref'       => $p['reference'],
            'amount'       => $p['amount'],
            'currency'     => $p['currency'],
            'redirect_url' => $p['redirectUrl'],
            'customer'     => ['email' => $p['email'], 'name' => $p['name']],
            'customizations' => ['title' => $p['title'], 'description' => $p['description']],
            'meta'         => ['purpose' => $p['purpose']],
        ]);
        $link = $r['data']['link'] ?? null;
        if (($r['status'] ?? '') !== 'success' || !is_string($link) || !str_starts_with($link, 'https://') && !str_starts_with($link, 'http://')) {
            throw new GatewayException('Flutterwave refused to start the payment: ' . ($r['message'] ?? 'no message'));
        }
        return $link;
    }

    /** What Flutterwave itself says about our reference. null = it has no such payment (yet). */
    public static function verifyByReference(string $reference): ?array
    {
        [$status, $r] = self::request('GET', '/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference));
        return self::data($status, $r);
    }

    public static function verifyById(string $id): ?array
    {
        [$status, $r] = self::request('GET', '/transactions/' . rawurlencode($id) . '/verify');
        return self::data($status, $r);
    }

    private static function data(int $status, array $r): ?array
    {
        if (($r['status'] ?? '') === 'success' && is_array($r['data'] ?? null)) {
            return $r['data'];
        }
        if ($status === 404 || $status === 400) {
            return null;                                    // "no transaction found"
        }
        throw new GatewayException('Flutterwave verification failed: ' . ($r['message'] ?? "HTTP $status"));
    }
}