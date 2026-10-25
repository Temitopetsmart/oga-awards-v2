<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/bootstrap.php';

use App\Cache;
use App\Catalog;
use App\Database;
use App\Response;
use App\SettingsApi;

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');                       // e.g. /oga-awards-v2/api
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = substr($path, strlen($base));
$path = '/' . trim(preg_replace('#^/index\.php#', '', $path), '/');         // works with or without URL rewriting
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $path === '/health') {
    $pdo = Database::pdo();
    $body = [
        'ok'       => true,
        'database' => 'connected',
        'tables'   => (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn(),
        'editions' => (int) $pdo->query('SELECT COUNT(*) FROM editions')->fetchColumn(),
        'nominees' => (int) $pdo->query('SELECT COUNT(*) FROM nominee_entries')->fetchColumn(),
        'time'     => date('c'),
    ];
    if ($body['editions'] === 0) {
        $body['hint'] = 'Connected, but the database has no data yet. Run: C:/xampp/php/php.exe backend/tools/load-demo-data.php';
    }
    Response::json($body);
}

// ---- Invitation links (public: the token is the secret) ----
if ($method === 'GET' && preg_match('#^/invite/([A-Za-z0-9]+)$#', $path, $m)) {
    App\InviteApi::info($m[1]);
}
if ($method === 'POST' && $path === '/invite/accept') {
    App\InviteApi::accept();
}

// ---- Admin area (sign-in, session, permissions) ----
if (str_starts_with($path, '/admin/')) {
    App\AdminApi::handle($method, $path);
}


 // ---- Nominee account area (registration, login, profile) ----
if (str_starts_with($path, '/nominee/')) {
    App\AccountApi::handle($method, $path);
}

// ---- Admin area (sign-in, session, permissions) ----
if (str_starts_with($path, '/admin/')) {
    App\AdminApi::handle($method, $path);
}


// ---- Public read-only data (same names and shapes as the V2 /data/*.json files) ----
$public = [
    '/editions'     => [fn () => Catalog::editions(), 30],
    '/categories'   => [fn () => Catalog::categories(), 30],
    '/nominees'     => [fn () => Catalog::nominees(), 30],
    '/ticket-types' => [fn () => Catalog::ticketTypes(), 30],
    '/winners'      => [fn () => Catalog::winners(), 30],
    '/committee'    => [fn () => Catalog::committee(), 60],
    '/settings'     => [fn () => SettingsApi::publicView(), 60],
    '/about'        => [fn () => Catalog::staticFile('about'), 300],
    '/content'      => [fn () => Catalog::staticFile('content'), 300],
    '/faqs'         => [fn () => Catalog::staticFile('faqs'), 300],
    '/gallery'      => [fn () => Catalog::staticFile('gallery'), 300],
    '/legal'        => [fn () => Catalog::staticFile('legal'), 300],
];

if (($method === 'GET' || $method === 'HEAD') && isset($public[$path])) {
    [$build, $ttl] = $public[$path];
    $json = Cache::remember('public' . str_replace('/', '_', $path), $ttl, function () use ($build): string {
        return json_encode($build(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    });
    Response::cachedJson($json, $ttl);
}

Response::error('Not found', 404);