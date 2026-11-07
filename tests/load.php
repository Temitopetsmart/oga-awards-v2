<?php
declare(strict_types=1);

/**
 * Load test: many visitors at once against a running site. Safe to point at a test site; it only READS.
 *
 *   C:\xampp\php\php.exe tests\load.php http://localhost/oga-awards-v2 --users=50 --seconds=20
 *   C:\xampp\php\php.exe tests\load.php https://v2.ogaaward.xyz --users=100 --seconds=30
 *
 * Options:  --users=N      simultaneous visitors (default 25)
 *           --seconds=N    how long to run (default 15)
 *           --edition=slug edition to ask the admin lists about (default: the first one)
 * To include the admin lists (votes, tickets, audit, nominees, messages), set the admin sign-in in the environment
 * (not on the command line, which is saved in your history):
 *   set OGA_LOAD_EMAIL=you@example.com
 *   set OGA_LOAD_PASSWORD=your-password
 * Do not run it against a site with real money moving unless you mean to: it opens many connections at once.
 *
 * It reports requests per second, how long visitors waited (median, 95th percentile, slowest), errors, and the
 * result per page. Anything over 5% errors, or a 95th percentile over 1 second, is marked as a problem.
 */
$args = $argv;
array_shift($args);
$base = rtrim((string) array_shift($args), '/');
if ($base === '' || str_starts_with($base, '--')) {
    fwrite(STDERR, "Usage: php tests/load.php <site address> [--users=25] [--seconds=15] [--edition=slug]\n");
    exit(2);
}
$opt = ['users' => 25, 'seconds' => 15, 'edition' => ''];
foreach ($args as $a) {
    if (preg_match('/^--(users|seconds|edition)=(.+)$/', $a, $m)) {
        $opt[$m[1]] = $m[1] === 'edition' ? $m[2] : max(1, (int) $m[2]);
    }
}

$agent = 'oga-load-test';

// ---- what the visitors do
$paths = [
    ['/api/settings', 'public data'], ['/api/editions', 'public data'], ['/api/categories', 'public data'], ['/api/nominees', 'public data'],
    ['/api/ticket-types', 'public data'], ['/api/winners', 'public data'], ['/api/committee', 'public data'],
    ['/', 'page'], ['/vote', 'page'], ['/results', 'page'], ['/shared/js/config/app.config.js', 'script'], ['/shared/css/tokens.css', 'style'],
];
$cookie = '';
$email = (string) getenv('OGA_LOAD_EMAIL');
$password = (string) getenv('OGA_LOAD_PASSWORD');
if ($email !== '' && $password !== '') {
    $jar = tempnam(sys_get_temp_dir(), 'ogaload');
    $do = function (string $method, string $url, ?array $body = null, array $h = []) use ($jar, $agent): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $agent, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $h)]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $out = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, json_decode($out, true)];
    };
    [, $csrf] = $do('GET', "$base/api/admin/csrf");
    [$code] = $do('POST', "$base/api/admin/login", ['email' => $email, 'password' => $password], ['X-CSRF-Token: ' . ($csrf['csrf'] ?? '')]);
    if ($code !== 200) {
        fwrite(STDERR, "Admin sign-in failed ($code). Check OGA_LOAD_EMAIL / OGA_LOAD_PASSWORD.\n");
        exit(2);
    }
    foreach (file($jar, FILE_IGNORE_NEW_LINES) as $line) {
        $p = explode("\t", ltrim($line, '#HttpOnly_'));
        if (count($p) >= 7 && $p[5] === 'oga_admin') { $cookie = 'oga_admin=' . $p[6]; }
    }
    [, $me] = $do('GET', "$base/api/admin/me");
    @unlink($jar);
    $ed = $opt['edition'] !== '' ? $opt['edition'] : (string) ($me['editions']['active'] ?? ($me['editions']['list'][0]['id'] ?? ''));
    if ($ed === '') { fwrite(STDERR, "Could not find an edition.\n"); exit(2); }
    $e = rawurlencode($ed);
    foreach (["/api/admin/votes?edition=$e&page=1&perPage=25&stats=1", "/api/admin/votes?edition=$e&page=3&status=paid", "/api/admin/tickets?edition=$e&page=1&view=orders&stats=1",
              "/api/admin/tickets?edition=$e&page=1&view=issued", "/api/admin/audit?page=1", "/api/admin/nominees?edition=$e&page=1", "/api/admin/messages?edition=$e&page=1",
              "/api/admin/results?edition=$e", "/api/admin/dashboard?edition=$e"] as $p) {
        $paths[] = [$p, 'admin list'];
    }
    echo "Admin sign-in OK: including the admin lists.\n";
}

// ---- run
echo sprintf("Testing %s with %d simultaneous visitors for %d seconds...\n", $base, $opt['users'], $opt['seconds']);
$mh = curl_multi_init();
$active = [];
$stats = [];
$all = [];
$errors = 0;
$bytes = 0;
$i = 0;
$start = microtime(true);
$end = $start + $opt['seconds'];

$launch = function () use (&$paths, &$active, $mh, $base, $agent, $cookie, &$i): void {
    [$path, $kind] = $paths[$i++ % count($paths)];
    $ch = curl_init($base . $path);
    $h = ['Accept-Encoding: gzip'];
    if ($kind === 'admin list') { $h[] = 'Cookie: ' . $cookie; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => $agent, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true]);
    curl_multi_add_handle($mh, $ch);
    $active[(int) $ch] = [$ch, $path];
};
for ($n = 0; $n < $opt['users']; $n++) { $launch(); }
do {
    curl_multi_exec($mh, $running);
    while ($done = curl_multi_info_read($mh)) {
        $ch = $done['handle'];
        [, $path] = $active[(int) $ch];
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $ok = $done['result'] === CURLE_OK && $code >= 200 && $code < 400;
        $stats[$path]['ms'][] = $ms;
        $stats[$path]['err'] = ($stats[$path]['err'] ?? 0) + ($ok ? 0 : 1);
        $stats[$path]['codes'][$code] = ($stats[$path]['codes'][$code] ?? 0) + 1;
        $all[] = $ms;
        $errors += $ok ? 0 : 1;
        $bytes += (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($active[(int) $ch]);
        if (microtime(true) < $end) { $launch(); }
    }
    if ($running) { curl_multi_select($mh, 0.2); }
} while ($running || $active);
$elapsed = microtime(true) - $start;

$pct = function (array $v, float $p): float { sort($v); return $v ? $v[(int) min(count($v) - 1, floor($p * count($v)))] : 0.0; };
echo "\n" . sprintf("%-62s %6s %7s %7s %7s %5s\n", 'page', 'hits', 'median', 'p95', 'slowest', 'errs');
ksort($stats);
foreach ($stats as $path => $s) {
    echo sprintf("%-62s %6d %5.0fms %5.0fms %5.0fms %5d\n", substr($path, 0, 62), count($s['ms']), $pct($s['ms'], 0.5), $pct($s['ms'], 0.95), max($s['ms']), $s['err']);
}
$total = count($all);
$p95 = $pct($all, 0.95);
$errRate = $total ? $errors / $total : 1;
echo "\n" . sprintf("Total: %d requests in %.1fs = %.0f requests/second, %.1f MB received\n", $total, $elapsed, $total / $elapsed, $bytes / 1048576);
echo sprintf("Waiting time: median %.0f ms, 95%% under %.0f ms, slowest %.0f ms\n", $pct($all, 0.5), $p95, max($all ?: [0]));
echo sprintf("Errors: %d (%.1f%%)\n", $errors, $errRate * 100);
$bad = $errRate > 0.05 || $p95 > 1000;
echo $bad ? "RESULT: PROBLEM: too many errors or too slow at this many visitors.\n" : "RESULT: OK at this many visitors.\n";
exit($bad ? 1 : 0);