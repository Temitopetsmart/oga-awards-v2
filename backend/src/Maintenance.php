<?php
declare(strict_types=1);

namespace App;

/**
 * Site mode. When an admin switches maintenance on (Admin, Settings), the public site shows the "We'll be right back"
 * page and every public change is refused here with 503. Reading stays open; the admin area, sign-in recovery,
 * payment confirmations and the Flutterwave webhook are never blocked, so a payment already in flight still completes.
 */
final class Maintenance
{
    private const ALWAYS_OPEN = [
        '#^/webhooks/flutterwave$#',
        '#^/votes/orders/[A-Z0-9-]+/confirm$#',
        '#^/tickets/orders/[A-Z0-9-]+/confirm$#',
        '#^/tickets/lookup$#',
        '#^/invite/accept$#',
        '#^/admin(-password)?/#',
    ];

    public static function state(): array
    {
        $v = SettingsApi::value('maintenance');
        return ['enabled' => !empty($v['enabled']), 'message' => (string) ($v['message'] ?? '')];
    }

    public static function guard(string $method, string $path): void
    {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        foreach (self::ALWAYS_OPEN as $rx) {
            if (preg_match($rx, $path)) {
                return;
            }
        }
        $s = self::state();
        if ($s['enabled']) {
            Response::json(['ok' => false, 'maintenance' => true, 'error' => $s['message'] !== '' ? $s['message'] : 'The site is undergoing maintenance. Please try again shortly.'], 503);
        }
    }
}