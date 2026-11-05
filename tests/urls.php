<?php
declare(strict_types=1);

/**
 * Checks the clean addresses of a RUNNING site (it does not need a database of its own).
 *   C:\xampp\php\php.exe tests\urls.php http://localhost/oga-awards-v2
 *   C:\xampp\php\php.exe tests\urls.php https://ogaaward.xyz
 * Every page must open without ".html"; every old ".html" address must redirect (301) to the clean one;
 * project internals must be refused; assets must load. Add --skip-api when PHP is not behind that address.
 */
if (PHP_SAPI !== 'cli') { exit('Run this from the command line.'); }
$base = rtrim((string) ($argv[1] ?? ''), '/');
if ($base === '') { exit("Usage: php tests/urls.php <site address, e.g. http://localhost/oga-awards-v2>\n"); }
$skipApi = in_array('--skip-api', $argv, true);
$prefix  = (string) (parse_url($base, PHP_URL_PATH) ?? '');
$root    = dirname(__DIR__);
$pass = $fail = 0;

function hit(string $url, string $method = 'GET'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    $body = (string) curl_exec($ch);
    $r = [curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), $body];
    curl_close($ch);
    return $r;
}
function expect(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    if (!$ok) { echo "  FAIL: $name" . ($detail !== '' ? "   [$detail]" : '') . "\n"; }
}
/** Where a relative link inside $page (e.g. "/admin/login") really points, the way a browser works it out. */
function resolve(string $base, string $page, string $ref): string
{
    if (str_starts_with($ref, '/')) {
        $p = parse_url($base);
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $ref;
    }
    $dir = str_ends_with($page, '/') ? $page : rtrim(dirname($page), '/') . '/';
    $parts = [];
    foreach (explode('/', $dir . $ref) as $seg) {
        if ($seg === '..') { array_pop($parts); } elseif ($seg !== '.' && $seg !== '') { $parts[] = $seg; }
    }
    return $base . '/' . implode('/', $parts);
}
/** $path is relative to the site, e.g. "/vote"; $code the status wanted; $to the redirect target (relative) or null. */
function check(string $path, int $code, ?string $to = null): void
{
    global $base;
    [$c, $loc] = hit($base . $path);
    $want = $to === null ? null : $base . $to;
    expect("$path -> $code" . ($to !== null ? " $to" : ''), $c === $code && ($want === null || $loc === $want), "got $c $loc");
}

// ---- every page, opened without .html, and its old .html address redirecting ----
$pages = [];
foreach (glob($root . '/*.html') as $f) { $pages[] = basename($f, '.html'); }
$adminPages = [];
foreach (glob($root . '/admin/*.html') as $f) { $adminPages[] = basename($f, '.html'); }

foreach ($pages as $p) {
    if ($p === 'index') {
        check('/', 200);
        check('/index.html', 301, '/');
        check('/index', 301, '/');
        continue;
    }
    if ($p === '404') { continue; }                      // the error page is shown for unknown addresses
    check("/$p", 200);
    check("/$p.html", 301, "/$p");
    check("/$p/", 301, "/$p");
}
foreach ($adminPages as $p) {
    if ($p === 'index') {
        check('/admin', 301, '/admin/');
        check('/admin/', 200);
        check('/admin/index', 301, '/admin/');
        check('/admin/index.html', 301, '/admin/');
        continue;
    }
    check("/admin/$p", 200);
    check("/admin/$p.html", 301, "/admin/$p");
}
// query strings must survive the redirect
check('/vote.html?nominee=3&x=a%20b', 301, '/vote?nominee=3&x=a%20b');
check('/vote?nominee=3', 200);
// nothing pretends to exist
check('/no-such-page', 404);
check('/admin/no-such-page', 404);

// ---- project internals must never be served ----
foreach (['/backend/config.php', '/backend/src/Outbox.php', '/backend/lib/PHPMailer/src/PHPMailer.php', '/database/migrations/001_schema.sql', '/docs/', '/tests/run.php', '/.git/config', '/.htaccess', '/make-pages.mjs', '/clean-urls.mjs'] as $p) {
    [$c] = hit($base . $p);
    expect("$p is refused", in_array($c, [403, 404], true), "got $c");
}

// ---- assets must load from every clean address (catches broken relative paths) ----
$broken = [];
$seen = 0;
foreach (array_merge(array_map(fn ($p) => $p === 'index' ? '/' : "/$p", array_diff($pages, ['404'])), array_map(fn ($p) => $p === 'index' ? '/admin/' : "/admin/$p", $adminPages)) as $page) {
    [, , $html] = hit($base . $page);
    preg_match_all('/(?:src|href)="([^"#]+)"/', $html, $m);
    foreach (array_unique($m[1]) as $ref) {
        if (preg_match('#^(https?:|//|mailto:|tel:|data:|javascript:)#i', $ref)) { continue; }
        $abs = resolve($base, $page, $ref);
        $seen++;
        [$c] = hit($abs);
        if ($c !== 200) { $broken[] = "$page -> $ref ($c)"; }
    }
}
expect("all $seen asset links load from their clean address", $broken === [], implode('; ', array_slice($broken, 0, 3)));

// ---- headers ----
$h = get_headers($base . '/vote', true) ?: [];
$h = array_change_key_case($h, CASE_LOWER);
expect('security headers are sent', isset($h['x-content-type-options'], $h['x-frame-options'], $h['referrer-policy']));

// ---- the API and a POST must not be broken by the rewrite rules ----
if (!$skipApi) {
    [$c, , $body] = hit($base . '/api/health');
    expect('/api/health answers with JSON from PHP', $c === 200 && str_contains($body, '"ok":true'), "got $c");
    [$c] = hit($base . '/api/nope');
    expect('/api/nope is a clean 404', $c === 404, "got $c");
}

echo "\n" . str_repeat('=', 50) . "\nPassed: $pass   Failed: $fail\n";
exit($fail === 0 ? 0 : 1);