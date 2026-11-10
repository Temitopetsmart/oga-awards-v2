<?php
declare(strict_types=1);

/**
 * OGA Awards V2 automated tests. Run:  C:\xampp\php\php.exe tests\run.php
 * Uses its own database (<DB_NAME>_test), cache folder and uploads folder. See tests/README.md.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$root = dirname(__DIR__);
$base = require $root . '/backend/config.php';
$testDb = rtrim((string) $base['DB_NAME'], '_') . '_test';
$GLOBALS['testDb'] = $testDb;
if (!str_ends_with($testDb, '_test')) {
    exit("Refusing to run: the test database name must end in _test.\n");
}
$port = (int) (getenv('OGA_TEST_PORT') ?: 8099);
$tmp  = sys_get_temp_dir() . '/oga-tests-' . getmypid();
@mkdir($tmp . '/cache', 0775, true);
@mkdir($tmp . '/uploads', 0775, true);

$cfg = array_merge($base, [
    'DB_NAME' => $testDb, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => "http://127.0.0.1:$port/",
    'CACHE_DIR' => $tmp . '/cache', 'UPLOAD_DIR' => $tmp . '/uploads',
    'SMTP_HOST' => '', 'SMTP_USER' => '', 'SMTP_PASSWORD' => '', 'MAIL_FROM' => '',       // never send real email
    'FLW_SECRET_KEY' => 'test', 'FLW_WEBHOOK_HASH' => 'test-hash',
]);
$cfgFile = $tmp . '/config.php';
file_put_contents($cfgFile, '<?php return ' . var_export($cfg, true) . ';');
putenv('OGA_CONFIG=' . $cfgFile);

// ------------------------------------------------------------------ database
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $base['DB_HOST'], $base['DB_PORT'] ?? '3306');
try {
    $admin = new PDO($dsn, (string) $base['DB_USER'], (string) ($base['DB_PASS'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    exit('Could not connect to MySQL: ' . $e->getMessage() . "\n");
}
$admin->exec("DROP DATABASE IF EXISTS `$testDb`");
$admin->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
$db = new PDO($dsn . ";dbname=$testDb", (string) $base['DB_USER'], (string) ($base['DB_PASS'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrations = glob($root . '/database/migrations/*.sql');
sort($migrations);
foreach ($migrations as $file) {                       // every migration in order, except the seed migration: tests use their own fixture
    if (!str_contains(basename($file), 'initial_edition')) {
        $db->exec((string) file_get_contents($file));
    }
}
// Sample editions, nominees and people for the tests. They live in tests/fixtures and are never loaded on a real site.
foreach (file($root . '/tests/fixtures/sample-data.sql', FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line !== '' && !str_starts_with($line, '--')) {
        $db->exec($line);                              // one statement per line
    }
}
$pw = 'Test-Pass-12345';
$hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
$db->prepare("INSERT INTO people (name,email,password_hash,active,is_super_admin) VALUES ('Test Admin','admin@test.dev',?,1,1)")->execute([$hash]);
$db->prepare("INSERT INTO people (name,email,password_hash,active) VALUES ('Gate Person','gate@test.dev',?,1)")->execute([$hash]);
$gateId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO edition_assignments (person_id,edition_id) VALUES (?,2)')->execute([$gateId]);
$db->prepare('INSERT INTO assignment_roles (assignment_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([(int) $db->lastInsertId(), 'gate']);
$entry = [];
foreach (['na' => 'HSWF/24/0001', 'nb' => 'HSWF/24/0002'] as $n => $matricNo) {
    $db->prepare('INSERT INTO accounts (email,password_hash,email_verified_at,matric_no,level) VALUES (?,?,NOW(),?,?)')->execute(["$n@test.dev", $hash, $matricNo, 'ND I']);
    $accId = $db->lastInsertId();
    $db->prepare("INSERT INTO matric_registry (matric_no,email,source) VALUES (?,?,'nominee')")->execute([$matricNo, "$n@test.dev"]);
    $db->prepare("INSERT INTO nominee_entries (edition_id,account_id,category_id,name,status) VALUES (2,?,2,?, 'pending')")->execute([$accId, "Nominee $n"]);
    $entry[$n] = (int) $db->lastInsertId();
}
$db->exec("INSERT INTO vote_orders (edition_id,reference,nominee_entry_id,category_id,votes,amount,voter_name,voter_email,status,source) VALUES
  (2,'VOT-T1',{$entry['na']},2,10,500,'A','a@x.test','paid','payment'),(2,'VOT-T2',{$entry['na']},2,5,250,'B','b@x.test','paid','payment'),(2,'VOT-T3',{$entry['na']},2,99,0,'C','c@x.test','pending','payment')");
$db->exec(
    "INSERT INTO vote_totals
        (nominee_entry_id, category_id, edition_id, votes, manual_votes, amount)
     SELECT nominee_entry_id, category_id, edition_id,
            SUM(votes),
            SUM(CASE WHEN source = 'manual' THEN votes ELSE 0 END),
            SUM(amount)
     FROM vote_orders
     WHERE status = 'paid'
     GROUP BY nominee_entry_id, category_id, edition_id"
);
$db->exec("INSERT INTO ticket_orders (edition_id,reference,ticket_type_id,qty,amount,buyer_name,buyer_email,buyer_phone,status) VALUES (2,'TIK-T1',1,1,3000,'T','t@x.test','0801','paid')");
$db->exec("INSERT INTO ticket_groups (edition_id,order_id,group_code,seats) SELECT 2,id,'GRP-TEST0001',2 FROM ticket_orders WHERE reference='TIK-T1'");
$db->exec("INSERT INTO tickets (edition_id,order_id,group_id,ticket_code,seat_no,holder_name,status) SELECT 2,o.id,g.id,CONCAT('TKT-T',n),n,'T','issued' FROM ticket_orders o JOIN ticket_groups g ON g.order_id=o.id JOIN (SELECT 1 n UNION SELECT 2) s WHERE o.reference='TIK-T1'");

// ------------------------------------------------------------------ server
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'w'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes, $root, array_merge(getenv(), ['OGA_CONFIG' => $cfgFile]));
register_shutdown_function(function () use ($server, $admin, $testDb, $tmp) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
        echo "\n===== TEST SERVER LOG =====\n";
    echo is_file($tmp . '/server.log')
        ? file_get_contents($tmp . '/server.log')
        : "Server log not found.\n";
    echo "===== END SERVER LOG =====\n";
    $admin->exec("DROP DATABASE IF EXISTS `$testDb`");
    if (is_dir($tmp)) {                                   // remove the temporary cache, uploads and config
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmp);
    }
});
define('API', "http://127.0.0.1:$port/api/index.php");
for ($i = 0; $i < 50; $i++) {
    $c = @file_get_contents(API . '/health');
    if ($c && str_contains($c, '"ok":true')) { break; }
    usleep(100000);
    if ($i === 49) { exit("The test web server did not start. See $tmp/server.log\n"); }
}

// ------------------------------------------------------------------ tiny test kit
$GLOBALS['pass'] = $GLOBALS['fail'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS[$ok ? 'pass' : 'fail']++;
    if (!$ok) { echo "  FAIL: $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function group(string $title): void { echo "\n== $title\n"; }

final class Client
{
    public ?string $csrf = null;
    private string $jar;
    public function __construct(private string $scope = 'admin')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'ck');
        $this->csrf = $this->get("/$scope/csrf")[1]['csrf'] ?? null;
    }
    /** @return array{0:int,1:?array,2:string} */
    public function call(string $method, string $path, mixed $body = null, array $headers = [], bool $multipart = false): array
    {
        $ch = curl_init(API . $path);
        $h = ['Accept: application/json'];
        if ($method !== 'GET' && $this->csrf !== null && !isset($headers['X-CSRF-Token'])) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
        if ($body !== null && !$multipart) { $h[] = 'Content-Type: application/json'; }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $body : (is_string($body) ? $body : json_encode($body))); }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['csrf'])) { $this->csrf = $json['csrf']; }
        return [$code, is_array($json) ? $json : null, $raw];
    }
    public function get(string $p): array { return $this->call('GET', $p); }
    public function post(string $p, mixed $b = []): array { return $this->call('POST', $p, $b); }
    public function put(string $p, mixed $b = []): array { return $this->call('PUT', $p, $b); }
    public function patch(string $p, mixed $b = []): array { return $this->call('PATCH', $p, $b); }
    public function delete(string $p, mixed $b = []): array { return $this->call('DELETE', $p, $b); }
    public function login(string $email, string $pass): array { return $this->post("/{$this->scope}/login", ['email' => $email, 'password' => $pass]); }
}
$ed = 'oga-10';
$q = fn (string $sql, array $a = []) => (function () use ($db, $sql, $a) { $s = $db->prepare($sql); $s->execute($a); return $s; })();
$clearLimits = fn () => $db->exec('DELETE FROM login_attempts');

// ================================================================== A. SECURITY
group('A1. Anonymous callers are refused on every admin route');
$anon = new Client();
$paths = ['/admin/people', '/admin/roles', '/admin/editions', '/admin/categories', '/admin/nominees', '/admin/votes', '/admin/votes/manual', '/admin/results', '/admin/tickets',
    '/admin/ticket-types', '/admin/scan', '/admin/settings', '/admin/committee', '/admin/committee/config', '/admin/committee/members', '/admin/messages', '/admin/dashboard',
    '/admin/audit', '/admin/audit/export', '/admin/invitations', '/admin/occasions', '/admin/assignments', '/admin/activity', '/admin/preview', '/admin/nominees/status'];
$leaks = [];
foreach ($paths as $p) {
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
        [$code] = $anon->call($m, "$p?edition=$ed", $m === 'GET' ? null : []);
        if (!in_array($code, [401, 404, 405], true)) { $leaks[] = "$m $p=$code"; }
    }
}
check('all ' . count($paths) . ' admin paths x 5 methods answer 401/404/405 to a stranger', $leaks === [], implode(', ', array_slice($leaks, 0, 4)));

group('A2. CSRF protection');
$sa = new Client(); $sa->login('admin@test.dev', 'Test-Pass-12345');
[$c] = $sa->call('POST', '/admin/committee/members', ['edition' => $ed, 'personId' => 'new', 'name' => 'Zed Zed', 'position' => 'X', 'unitId' => 1], ['X-CSRF-Token' => 'wrong']);
check('wrong CSRF token refused', in_array($c, [403, 419], true), (string) $c);
$noCsrf = new Client(); $noCsrf->login('admin@test.dev', 'Test-Pass-12345'); $noCsrf->csrf = null;
[$c] = $noCsrf->post('/admin/committee/members', ['edition' => $ed, 'personId' => 'new', 'name' => 'Zed Zed', 'position' => 'X', 'unitId' => 1]);
check('missing CSRF token refused', in_array($c, [403, 419], true), (string) $c);

group('A3. A gate-staff account can only do gate work');
$gate = new Client(); [$c] = $gate->login('gate@test.dev', 'Test-Pass-12345'); check('gate staff can sign in', $c === 200, (string) $c);
$approvedNominee = (string) $q("SELECT id FROM nominee_entries WHERE edition_id = 2 AND status = 'approved' LIMIT 1")->fetchColumn();
$deny = [
    ['GET', "/admin/votes?edition=$ed"], ['GET', "/admin/tickets?edition=$ed"], ['GET', "/admin/committee?edition=$ed"], ['GET', "/admin/messages?edition=$ed"],
    ['GET', "/admin/nominees?edition=$ed"], ['GET', "/admin/settings?edition=$ed"], ['GET', "/admin/audit?edition=$ed"], ['GET', "/admin/invitations?edition=$ed"], ['GET', "/admin/dashboard?edition=$ed"],
    ['POST', '/admin/people', ['edition' => $ed, 'name' => 'Evil', 'email' => 'evil@x.dev']],
    ['POST', '/admin/people/1/super', ['edition' => $ed, 'value' => true]],
    ['POST', '/admin/editions', ['edition' => $ed, 'name' => 'X']],
    ['POST', '/admin/votes/manual', ['edition' => $ed, 'nomineeId' => $approvedNominee, 'votes' => 999, 'reason' => 'x']],
    ['PUT', '/admin/assignments', ['edition' => $ed, 'editionId' => $ed, 'personId' => '1', 'patch' => ['roleIds' => ['role-super']]]],
    ['POST', '/admin/activity', ['edition' => $ed, 'kind' => 'export:votes']],
];
// foreach ($deny as $d) {
//     [$c] = $gate->call($d[0], $d[1], $d[2] ?? null);
//     check("gate staff refused: {$d[0]} " . strtok($d[1], '?'), $c === 403, (string) $c);
// }

foreach ($deny as $d) {
    [$c, $data, $raw] = $gate->call($d[0], $d[1], $d[2] ?? null);

    if ($d[0] === 'POST' && $d[1] === '/admin/editions') {
        echo "\nGATE ERROR RESPONSE:\n";
        echo $raw . "\n";
    }

    check("gate staff refused: {$d[0]} " . strtok($d[1], '?'), $c === 403, (string) $c);
}



check('gate staff may open the scanner', $gate->get("/admin/scan?edition=$ed")[0] === 200);
check('gate staff refused in an edition they are not assigned to', $gate->get('/admin/scan?edition=oga-9')[0] === 403);
[, $me] = $gate->get('/admin/me');
check('sign-in data shows only their own person record', count($me['people'] ?? []) === 1);
check('no password hash in sign-in data', !str_contains(json_encode($me), '$2y$'));

group('A4. Hostile input never causes a server error or leak');
$probes = ["' OR '1'='1", '1; DROP TABLE people;--', '" OR 1=1 --', '%27%20UNION%20SELECT%201', '<script>alert(1)</script>'];
foreach ($probes as $v) {
    foreach (['/admin/dashboard?edition=', '/admin/votes?edition=', '/admin/committee?edition=', '/committee/recruitment?edition='] as $p) {
        [$c] = $sa->get($p . rawurlencode($v));
        check('probe ' . substr($p, 0, 22) . substr($v, 0, 8), in_array($c, [403, 404, 422], true), (string) $c);
    }
}
[$c] = (new Client())->call('POST', '/votes/orders', ['nomineeId' => '1 OR 1=1', 'categoryId' => '2', 'votes' => '1;DROP TABLE x', 'email' => 'a@b.co', 'name' => 'x']);
check('vote order with junk values is a 4xx', $c >= 400 && $c < 500, (string) $c);
[$c] = (new Client())->post('/admin/login', ['email' => "' OR 1=1 --", 'password' => 'x']);
check('login injection refused', in_array($c, [401, 403, 419, 422, 429], true), (string) $c);
check('people table intact', (int) $q('SELECT COUNT(*) FROM people')->fetchColumn() >= 2);
[$c, , $raw] = (new Client())->get('/no-such-route'); check('unknown route is a clean 404', $c === 404 && !str_contains($raw, '/home') && !str_contains($raw, $root));
[$c, , $raw] = (new Client())->call('POST', '/contact', '{bad json');
check('malformed JSON is a clean 400 with no stack trace', $c === 400 && !str_contains($raw, 'Stack') && !str_contains($raw, $root), $raw);
[, , $raw] = (new Client())->get('/health'); check('health reveals no secrets', !preg_match('/password|secret|FLW_|SMTP/i', $raw));

group('A5. Public data does not leak private details');
foreach (['/nominees', '/editions', '/committee', '/winners', '/ticket-types', '/categories'] as $p) {
    [, , $raw] = (new Client())->get($p);
    check("$p has no email addresses, phones or hashes", !preg_match('/@[a-z0-9-]+\.[a-z]{2,}|\$2y\$|"phone"|password/i', $raw));
}
[, $eds] = (new Client())->get('/editions'); check('draft editions are never public', array_reduce($eds['editions'] ?? [], fn ($ok, $e) => $ok && ($e['status'] ?? '') !== 'draft', true));

group('A6. Nominees cannot touch each other\'s entries');
$na = new Client('nominee'); [$c, $ra] = $na->login('na@test.dev', 'Test-Pass-12345'); check('nominee A signs in', $c === 200, (string) $c);
$nb = new Client('nominee'); $nb->login('nb@test.dev', 'Test-Pass-12345');
$other = $entry['nb']; $mine = $entry['na'];
[$c] = $na->patch("/nominee/entries/$other", ['name' => 'HACKED', 'phone' => '0800000000', 'department' => 'x', 'level' => 'ND I', 'bio' => 'x']);
check('A cannot edit B\'s entry', in_array($c, [403, 404], true), (string) $c);
[$c] = $na->delete("/nominee/entries/$other/photo"); check('A cannot remove B\'s photo', in_array($c, [403, 404], true), (string) $c);
$na->patch("/nominee/entries/$mine", ['name' => 'Mine', 'phone' => '0800000000', 'department' => 'd', 'level' => 'ND I', 'bio' => 'b', 'status' => 'approved', 'categoryId' => '3', 'account_id' => 999]);
$row = $q('SELECT status, category_id, account_id FROM nominee_entries WHERE id = ?', [$mine])->fetch();
check('status, category and owner cannot be changed by the nominee', $row['status'] === 'pending' && (int) $row['category_id'] === 2 && (int) $row['account_id'] !== 999);
check('a nominee session is not an admin session', ($na->get('/admin/me')[1]['authenticated'] ?? false) === false);
check('B\'s name unchanged', $q('SELECT name FROM nominee_entries WHERE id = ?', [$other])->fetchColumn() === 'Nominee nb');

// ================================================================== B. COMMITTEE RECRUITMENT
group('B. Committee recruitment: apply, review, appoint');
$clearLimits();
$pub = new Client('nominee');
$now = new DateTimeImmutable();
$cfg = ['edition' => $ed, 'enabled' => true, 'opensAt' => $now->modify('-1 hour')->format('Y-m-d\TH:i'), 'closesAt' => $now->modify('+1 day')->format('Y-m-d\TH:i'), 'targetApplicants' => 50,
    'screeningDate' => '2026-12-01', 'screeningTime' => '10:00', 'screeningVenue' => 'Hall A', 'screeningInstructions' => 'Bring ID', 'approvalMessage' => 'Welcome aboard',
    'unsuccessfulMessage' => 'Sorry', 'reserveMessage' => 'Reserve', 'onboardingMessage' => 'Join group', 'whatsappGroupUrl' => 'https://chat.whatsapp.com/x', 'photoUploadsEnabled' => true, 'photoMaxSizeKB' => 1024];
check('closing before opening is refused', $sa->put('/admin/committee/config', array_merge($cfg, ['closesAt' => $cfg['opensAt']]))[0] === 422);
check('non-web link refused', $sa->put('/admin/committee/config', array_merge($cfg, ['whatsappGroupUrl' => 'javascript:alert(1)']))[0] === 422);
check('settings saved', $sa->put('/admin/committee/config', $cfg)[0] === 200);
check('window reports open', ((new Client())->get("/committee/recruitment?edition=$ed")[1]['open'] ?? false) === true);

function png(int $side): string { $im = imagecreatetruecolor($side, $side); ob_start(); imagepng($im); return (string) ob_get_clean(); }
$png = tempnam(sys_get_temp_dir(), 'png'); file_put_contents($png, png(300));
$tiny = tempnam(sys_get_temp_dir(), 'png'); file_put_contents($tiny, png(50));
$txt = tempnam(sys_get_temp_dir(), 'txt'); file_put_contents($txt, 'hello');
$form = ['editionId' => $ed, 'name' => 'Ada Test', 'email' => 'ADA@test.dev', 'phone' => '08011112222', 'department' => 'SWD', 'level' => 'HND II', 'unitId' => '2', 'motivation' => 'I want to help the committee deliver a great awards night.'];
$matricOf = [];
$apply = function (array $over = [], ?string $file = null, string $mime = 'image/png') use ($form, &$matricOf) {
    $email = strtolower((string) ($over['email'] ?? $form['email']));
    $matricOf[$email] ??= 'HSWF/24/' . str_pad((string) (100 + count($matricOf)), 4, '0', STR_PAD_LEFT);   // one matric number per applicant email
    return (new Client())->call('POST', '/committee/applications', array_merge($over, $form, ['matricNo' => $matricOf[$email]], $over) + ($file ? ['photo' => new CURLFile($file, $mime, 'p')] : []), [], true);
};
check('short motivation refused', $apply(['motivation' => 'short'])[0] === 422);
check('bad level refused', $apply(['level' => 'PhD'])[0] === 422);
check('unknown unit refused', $apply(['unitId' => '999'])[0] === 422);
check('a text file posing as a photo refused', $apply([], $txt, 'image/png')[0] === 422);
check('a photo that is too small refused', $apply([], $tiny)[0] === 422);
[$c, $r] = $apply([], $png); check('application with photo accepted', $c === 200, json_encode($r)); $appId = $r['applicationId'] ?? '0';
check('same email (any capitals) cannot apply twice', $apply()[0] === 422);
[, $ov] = $sa->get("/admin/committee?edition=$ed");
$mineApp = array_values(array_filter($ov['applications'], fn ($a) => $a['id'] === $appId))[0] ?? [];
check('stored with lower-case email, photo path and a history entry', ($mineApp['email'] ?? '') === 'ada@test.dev' && str_starts_with($mineApp['photo'] ?? '', 'uploads/committee-applicants/') && ($mineApp['history'][0]['stage'] ?? '') === 'submitted');
$act = fn (string $id, array $b) => $sa->post("/admin/committee/applications/$id", $b)[0];
check('cannot approve straight from "submitted"', $act($appId, ['action' => 'approve', 'message' => 'x']) === 409);
check('unknown action refused', $act($appId, ['action' => 'boom']) === 422);
check('shortlist', $act($appId, ['action' => 'shortlist', 'note' => 'Come along']) === 200);
check('screening needs a valid result', $act($appId, ['action' => 'screen', 'result' => 'maybe']) === 422);
check('screening "pending" keeps them shortlisted', $act($appId, ['action' => 'screen', 'result' => 'pending', 'note' => 'hmm']) === 200);
check('cannot approve before passing screening', in_array($act($appId, ['action' => 'approve']), [409, 422], true));
check('screening passed', $act($appId, ['action' => 'screen', 'result' => 'passed', 'note' => 'great']) === 200);
check('approve', $act($appId, ['action' => 'approve', 'message' => 'Welcome aboard', 'onboarding' => 'Join group']) === 200);
check('appoint needs a position', $act($appId, ['action' => 'appoint', 'unitId' => 2]) === 422);
check('appoint', $act($appId, ['action' => 'appoint', 'position' => 'Publicity Lead', 'unitId' => 2, 'visible' => true]) === 200);
[, $ov] = $sa->get("/admin/committee?edition=$ed");
$mem = array_values(array_filter($ov['members'], fn ($m) => $m['name'] === 'Ada Test'));
check('appointee is on the committee list WITHOUT any admin access', count($mem) === 1 && $mem[0]['position'] === 'Publicity Lead' && $mem[0]['hasAccess'] === false);
check('appointee has no roles in the database', (int) $q('SELECT COUNT(*) FROM assignment_roles ar JOIN edition_assignments a ON a.id = ar.assignment_id JOIN people p ON p.id = a.person_id WHERE p.email = ?', ['ada@test.dev'])->fetchColumn() === 0);
check('appointee cannot sign in (no password)', $q('SELECT password_hash FROM people WHERE email = ?', ['ada@test.dev'])->fetchColumn() === null);
[, $pubC] = (new Client())->get('/committee'); check('public committee page shows them', count(array_filter($pubC['members'], fn ($m) => $m['name'] === 'Ada Test')) === 1);
check('hide a member', $sa->patch("/admin/committee/members/{$mem[0]['id']}", ['position' => 'Head of Publicity', 'unitId' => 2, 'visible' => false])[0] === 200);
[, $pubC] = (new Client())->get('/committee'); check('hidden member leaves the public page', count(array_filter($pubC['members'], fn ($m) => $m['name'] === 'Ada Test')) === 0);
check('withdrawal needs a reason', $act($appId, ['action' => 'withdraw']) === 422);
check('withdraw', $act($appId, ['action' => 'withdraw', 'note' => 'Moved abroad']) === 200);
[, $ov] = $sa->get("/admin/committee?edition=$ed"); check('withdrawing clears the committee seat', count(array_filter($ov['members'], fn ($m) => $m['name'] === 'Ada Test')) === 0);
check('a withdrawn applicant may apply again', $apply()[0] === 200);
$bo = $apply(['email' => 'bo@test.dev', 'name' => 'Bo Fail'])[1]['applicationId'];
$act($bo, ['action' => 'shortlist']);
check('failed screening -> unsuccessful', $act($bo, ['action' => 'screen', 'result' => 'failed']) === 200);
check('reserve from unsuccessful', $act($bo, ['action' => 'reserve', 'note' => 'Maybe later']) === 200);
check('reconsideration needs a reason', $act($bo, ['action' => 'reconsider']) === 422);
check('reconsider', $act($bo, ['action' => 'reconsider', 'note' => 'Slot opened']) === 200);
check('can be shortlisted again after reconsideration', $act($bo, ['action' => 'shortlist']) === 200);
check('add a plain member', $sa->post('/admin/committee/members', ['edition' => $ed, 'personId' => 'new', 'name' => 'Chidi New', 'position' => 'Treasurer', 'unitId' => 1, 'visible' => true])[0] === 200);
[, $ov] = $sa->get("/admin/committee?edition=$ed"); $chidi = array_values(array_filter($ov['members'], fn ($m) => $m['name'] === 'Chidi New'))[0];
check('same person cannot hold two seats', $sa->post('/admin/committee/members', ['edition' => $ed, 'personId' => $chidi['personId'], 'position' => 'X', 'unitId' => 1])[0] === 422);
check('remove member', $sa->delete("/admin/committee/members/{$chidi['id']}")[0] === 200);
$rid = $apply(['email' => 'race@test.dev', 'name' => 'Race Case'])[1]['applicationId'];
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 6; $i++) { // six simultaneous clicks on the same button
    $c2 = new Client(); $c2->login('admin@test.dev', 'Test-Pass-12345');
    $ch = curl_init(API . "/admin/committee/applications/$rid");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode(['action' => 'shortlist']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $c2->csrf], CURLOPT_COOKIEFILE => (new ReflectionProperty($c2, 'jar'))->getValue($c2)]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh); } while ($run > 0);
$codes = array_map(fn ($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $hs); sort($codes);
check('six simultaneous shortlist clicks: exactly one wins', count(array_keys($codes, 200, true)) === 1 && count(array_diff($codes, [200, 409])) === 0, implode(',', $codes));
$sa->put('/admin/committee/config', array_merge($cfg, ['enabled' => false]));
[$c, $r] = $apply(['email' => 'late@test.dev']); check('closed window refuses applications', $c === 422 && str_contains((string) ($r['error'] ?? ''), 'disabled'));
check('committee emails were queued (no SMTP in tests)', (int) $q("SELECT COUNT(*) FROM notification_outbox WHERE type LIKE 'committee_%' AND status = 'queued'")->fetchColumn() >= 6);

// ================================================================== C. MESSAGES, DASHBOARD, ACTIVITY
group('C. Contact messages, dashboard, badges, activity log');
$clearLimits();
$msg = ['name' => 'Ife Ade', 'email' => 'ife@test.dev', 'subject' => 'Sponsorship', 'message' => 'Hello, we would like to discuss sponsoring the event.', 'editionId' => $ed];
$post = fn (array $b) => (new Client())->post('/contact', $b)[0];
check('short message refused', $post(array_merge($msg, ['message' => 'too short'])) === 422);
check('bad email refused', $post(array_merge($msg, ['email' => 'nope'])) === 422);
check('bot (hidden field filled) gets "ok" but nothing is stored', $post(array_merge($msg, ['website' => 'http://spam'])) === 200 && (int) $q("SELECT COUNT(*) FROM contact_messages WHERE message LIKE '%sponsoring%'")->fetchColumn() === 0);
check('valid message accepted', $post($msg) === 200);
check('admin list needs login', (new Client())->get("/admin/messages?edition=$ed")[0] === 401);
[, $l] = $sa->get("/admin/messages?edition=$ed"); $m1 = $l['messages'][0] ?? [];
check('listed once, unread', count($l['messages']) === 1 && $m1['read'] === false);
check('sidebar badge counts it', ($sa->get('/admin/me')[1]['badges'][$ed]['unreadMessages'] ?? 0) === 1);
check('mark read', $sa->patch("/admin/messages/{$m1['id']}", ['edition' => $ed, 'read' => true])[0] === 200);
check('badge drops to zero', ($sa->get('/admin/me')[1]['badges'][$ed]['unreadMessages'] ?? 1) === 0);
check('delete', $sa->delete("/admin/messages/{$m1['id']}", ['edition' => $ed])[0] === 200 && $sa->delete("/admin/messages/{$m1['id']}", ['edition' => $ed])[0] === 404);
[, $d] = $sa->get("/admin/dashboard?edition=$ed");
check('dashboard counts only PAID votes', ($d['votes'] ?? 0) === 15 && ($d['voteAmount'] ?? 0) == 750.0, json_encode($d));
check('dashboard counts tickets per seat and revenue', ($d['ticketsSold'] ?? 0) === 2 && ($d['ticketRevenue'] ?? 0) == 3000.0);
check('dashboard is limited to the viewed edition', ($sa->get('/admin/dashboard?edition=oga-9')[1]['votes'] ?? 1) === 0);
foreach (['export:nominees', 'export:votes', 'export:tickets', 'export:results', 'preview:nominee'] as $k) {
    check("activity $k logged", $sa->post('/admin/activity', ['edition' => $ed, 'kind' => $k, 'target' => 'x'])[0] === 200);
}
check('unknown activity refused', $sa->post('/admin/activity', ['edition' => $ed, 'kind' => 'export:everything'])[0] === 422);
$acts = $q("SELECT action FROM audit_log WHERE action IN ('Exported votes','Deleted message')")->fetchAll(PDO::FETCH_COLUMN);
check('exports and deletions are in the audit log', in_array('Exported votes', $acts, true) && in_array('Deleted message', $acts, true));
$codes = []; for ($i = 0; $i < 12; $i++) { $codes[] = $post(array_merge($msg, ['subject' => "s$i"])); }
check('contact form is rate limited', in_array(429, $codes, true));

// ================================================================== D. EMAIL QUEUE (in-process)
group('D. Email queue: expiry, secrets wiped, templates');
require $root . '/backend/src/bootstrap.php';
$ins = fn (string $type, string $to, array $p, string $when = 'NOW()') => (function () use ($db, $type, $to, $p, $when) { $db->prepare("INSERT INTO notification_outbox (type, recipient, payload, created_at) VALUES (?, ?, ?, $when)")->execute([$type, $to, json_encode($p)]); return (int) $db->lastInsertId(); })();
$stale = $ins('nominee_verify_email', 'old@x.test', ['token' => 'stale-secret', 'expiresMinutes' => 30], 'NOW() - INTERVAL 2 DAY');
$weird = $ins('weird_type', 'w@x.test', ['token' => 'weird-secret']);
$res = App\Outbox::sendPending(100);
check('an expired code is never sent', $q('SELECT status FROM notification_outbox WHERE id = ?', [$stale])->fetchColumn() === 'failed' && $res['expired'] >= 1);
check('secrets are wiped from expired and unknown messages', !str_contains((string) $q('SELECT payload FROM notification_outbox WHERE id IN (?, ?) LIMIT 1', [$stale, $weird])->fetchColumn(), 'secret') && !str_contains((string) $q('SELECT payload FROM notification_outbox WHERE id = ?', [$weird])->fetchColumn(), 'secret'));
foreach (['nominee_verify_email' => ['token' => 'abc'], 'nominee_password_reset' => ['token' => 'abc'], 'admin_invitation' => ['token' => 'abc', 'editionName' => 'OGA 10.0'],
    'committee_application_received' => ['applicantName' => 'Ada'], 'committee_shortlisted' => ['applicantName' => 'Ada', 'venue' => 'Hall A'], 'committee_approved' => ['applicantName' => 'Ada', 'message' => 'Hi'],
    'committee_appointed' => ['applicantName' => 'Ada', 'position' => 'Lead'], 'committee_reserve' => ['applicantName' => 'Ada'], 'committee_unsuccessful' => ['applicantName' => 'Ada']] as $type => $p) {
    $m = App\MailTemplates::render($type, 'a@b.co', $p); check("template renders: $type", is_array($m) && $m['subject'] !== '' && $m['html'] !== '' && $m['text'] !== '');
}
$m = App\MailTemplates::render('committee_approved', 'a@b.co', ['applicantName' => '<script>alert(1)</script>', 'message' => '<b>x</b>']);
check('email templates escape HTML in names and messages', !str_contains($m['html'], '<script>') && !str_contains($m['html'], '<b>x</b>'));

// ================================================================== E. SETTINGS HUB (Round 2, phase 2)
group('E. Settings hub: levels, matric format, branding, encrypted secrets');
$pub = fn () => (new Client())->get('/settings')[1] ?? [];
check('public settings list the six default levels', ($pub()['levels'] ?? []) === ['ND I', 'ND II', 'HND I', 'HND II', 'Staff', 'Alumni']);

[$c, $r] = $sa->put('/admin/settings/levels', ['edition' => $ed, 'value' => [
    ['name' => 'ND I', 'active' => true], ['name' => 'ND II', 'active' => true], ['name' => 'HND I', 'active' => true], ['name' => 'HND II', 'active' => true],
    ['name' => 'Staff', 'active' => true], ['name' => 'Alumni', 'active' => false], ['name' => 'Postgraduate', 'active' => true]]]);
check('admin can edit the levels list', $c === 200 && count($r['items'] ?? []) === 7, (string) $c);
$lv = $pub()['levels'] ?? [];
check('new level is public, switched-off level is hidden, order kept', in_array('Postgraduate', $lv, true) && !in_array('Alumni', $lv, true) && $lv[0] === 'ND I');
check('registration rules use the live list', App\Options::isActive('level', 'Postgraduate') && !App\Options::isActive('level', 'Alumni') && !App\Options::isActive('level', 'ND III'));
check('duplicate level names are refused', $sa->put('/admin/settings/levels', ['edition' => $ed, 'value' => [['name' => 'ND I', 'active' => true], ['name' => 'nd i', 'active' => true]]])[0] === 422);
check('at least one level must stay active', $sa->put('/admin/settings/levels', ['edition' => $ed, 'value' => [['name' => 'ND I', 'active' => false]]])[0] === 422);
check('hostile level names are refused', $sa->put('/admin/settings/levels', ['edition' => $ed, 'value' => [['name' => '<script>x</script>', 'active' => true]]])[0] === 422);
check('levels cannot be changed by gate staff', $gate->put('/admin/settings/levels', ['edition' => $ed, 'value' => [['name' => 'X', 'active' => true]]])[0] === 403);
$sa->put('/admin/settings/levels', ['edition' => $ed, 'value' => [['name' => 'ND I', 'active' => true], ['name' => 'ND II', 'active' => true], ['name' => 'HND I', 'active' => true], ['name' => 'HND II', 'active' => true]]]);
check('a level left out of the list is switched off, not deleted', (int) $q("SELECT COUNT(*) FROM lookup_options WHERE kind='level'")->fetchColumn() === 7);

check('matric defaults accept full-time and part-time', App\Matric::normalize('hswf/24/0010') === 'HSWF/24/0010' && App\Matric::normalize('HSWF/24E/0010') === 'HSWF/24E/0010');
check('matric rejects wrong shapes and over-length', App\Matric::normalize('HSWF/24/001') === null && App\Matric::normalize('HSWF/24E/00100') === null && App\Matric::normalize('24/HSWF/0010') === null && App\Matric::normalize('') === null);
$cfg = ['maxLength' => 13, 'formats' => [['label' => 'Full-time', 'pattern' => '@{3,5}/#{2}/#{4}', 'example' => 'HSWF/24/0010'], ['label' => 'Evening', 'pattern' => '@{3,5}/#{2}X/#{4}', 'example' => '']]];
check('admin can save a matric format', $sa->put('/admin/settings/matric', ['edition' => $ed, 'value' => $cfg])[0] === 200);
check('saved matric format is applied', App\Matric::normalize('HSWF/24X/0010') === 'HSWF/24X/0010' && App\Matric::normalize('HSWF/24E/0010') === null);
check('matric format is public for the forms', (($pub()['matric']['formats'][1]['label'] ?? '') === 'Evening') && ($pub()['matric']['maxLength'] ?? 0) === 13);
check('matric pattern that can never fit the maximum is refused', $sa->put('/admin/settings/matric', ['edition' => $ed, 'value' => ['maxLength' => 8] + $cfg])[0] === 422);
check('broken matric pattern is refused', $sa->put('/admin/settings/matric', ['edition' => $ed, 'value' => ['maxLength' => 13, 'formats' => [['label' => 'x', 'pattern' => '@{3', 'example' => '']]]])[0] === 422);
check('matric example must match its pattern', $sa->put('/admin/settings/matric', ['edition' => $ed, 'value' => ['maxLength' => 13, 'formats' => [['label' => 'x', 'pattern' => '@{3}/#{4}', 'example' => 'ABCD/12']]]])[0] === 422);

check('admin can save the site branding', $sa->put('/admin/settings/brand', ['edition' => $ed, 'value' => ['name' => 'OGA Awards Test', 'org' => 'OGITECH', 'tagline' => 'x']])[0] === 200 && ($pub()['brand']['name'] ?? '') === 'OGA Awards Test');
check('email footer uses the admin-set site name', str_contains(App\MailTemplates::render('committee_application_received', 'a@b.co', ['applicantName' => 'Ada'])['html'], 'OGA Awards Test'));
check('branding needs a name', $sa->put('/admin/settings/brand', ['edition' => $ed, 'value' => ['name' => '']])[0] === 422);

$hash = 'db-hash-secret-12345';
[$c, $r, $raw] = $sa->put('/admin/settings/secrets', ['edition' => $ed, 'value' => ['flutterwaveWebhookHash' => $hash]]);
check('super admin can save a payment secret', $c === 200 && !str_contains($raw, $hash), (string) $c);
[, , $raw] = $sa->get("/admin/settings?edition=$ed");
check('a saved secret is reported as saved but never sent back', str_contains($raw, '"saved":true') && !str_contains($raw, $hash));
check('the secret is encrypted in the database', !str_contains((string) $q("SELECT ciphertext FROM app_secrets WHERE secret_key='flutterwave.webhook_hash'")->fetchColumn(), $hash));
check('the secret is not in the audit log', !str_contains(implode('|', $q('SELECT COALESCE(details, "") FROM audit_log')->fetchAll(PDO::FETCH_COLUMN)), $hash));
$hook = fn (string $h) => (new Client())->call('POST', '/webhooks/flutterwave', ['event' => 'ping', 'data' => []], ['verif-hash' => $h])[0];
check('webhook uses the secret saved in the admin', $hook($hash) === 200 && $hook('test-hash') === 401 && $hook('') === 401);
check('a PUBLIC key is refused as a secret key', $sa->put('/admin/settings/secrets', ['edition' => $ed, 'value' => ['flutterwaveSecretKey' => 'FLWPUBK_TEST-abcdef123456-X']])[0] === 422);
check('a secret with spaces is refused', $sa->put('/admin/settings/secrets', ['edition' => $ed, 'value' => ['flutterwaveSecretKey' => 'has space in it 123']])[0] === 422);
check('secrets cannot be changed by gate staff', $gate->put('/admin/settings/secrets', ['edition' => $ed, 'value' => ['flutterwaveWebhookHash' => 'abcdefgh123']])[0] === 403);
check('secrets are never in public settings', !str_contains((new Client())->get('/settings')[2], $hash));
[$c, $r] = $sa->post('/admin/settings/secrets/test', ['edition' => $ed]);
check('key test answers cleanly even when Flutterwave cannot be reached', $c === 200 && array_key_exists('ok', $r ?? []) && !str_contains(json_encode($r), $hash));
check('a secret can be removed, then config.php is the fallback', $sa->put('/admin/settings/secrets', ['edition' => $ed, 'value' => ['clear' => ['flutterwaveWebhookHash']]])[0] === 200 && $hook('test-hash') === 200 && $hook($hash) === 401);
check('config.example.php holds no real credentials', !preg_match('/@[a-z0-9.-]+\.[a-z]{2,}|[a-z]{16}/', preg_replace('/\/\/.*$/m', '', (string) file_get_contents($root . '/backend/config.example.php'))));

// ================================================================== F. A REAL SITE STARTS EMPTY (Round 2, phase 3)
group('F. Production starts empty: one OGA 10.0 edition, no sample data');
$mk = function (string $suffix, bool $seed, bool $fixture) use ($admin, $dsn, $base, $cfg, $tmp, $migrations, $root) {
    $name = $GLOBALS['testDb'] . '_' . $suffix;
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $p = new PDO($dsn . ";dbname=$name", (string) $base['DB_USER'], (string) ($base['DB_PASS'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($migrations as $f) {
        if ($seed || !str_contains(basename($f), 'initial_edition')) { $p->exec((string) file_get_contents($f)); }
    }
    if ($fixture) {
        foreach (file($root . '/tests/fixtures/sample-data.sql', FILE_IGNORE_NEW_LINES) as $l) { $l = trim($l); if ($l !== '' && !str_starts_with($l, '--')) { $p->exec($l); } }
    }
    $file = "$tmp/config-$suffix.php";
    file_put_contents($file, '<?php return ' . var_export(array_merge($cfg, ['DB_NAME' => $name]), true) . ';');
    return [$p, $name, $file];
};
$tool = function (string $cfgFile, string $script, array $args = []) use ($root) {
    $proc = proc_open(array_merge([PHP_BINARY, "$root/backend/tools/$script"], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['OGA_CONFIG' => $cfgFile]));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($proc), $out];
};
$n = fn (PDO $p, string $sql) => (int) $p->query($sql)->fetchColumn();

[$fresh, $freshName] = $mk('fresh', true, false);
check('fresh database has exactly one edition: OGA 10.0, active', $n($fresh, 'SELECT COUNT(*) FROM editions') === 1 && $n($fresh, "SELECT COUNT(*) FROM editions WHERE slug='oga-10' AND status='active' AND edition_label='10.0'") === 1);
check('the edition has its settings row and the committee structure', $n($fresh, 'SELECT COUNT(*) FROM edition_settings') === 1 && $n($fresh, 'SELECT COUNT(*) FROM committee_units') === 4);
check('fresh database has no categories, nominees, tickets, winners, people or orders',
    array_sum(array_map(fn ($t) => $n($fresh, "SELECT COUNT(*) FROM $t"), ['categories', 'nominee_entries', 'ticket_types', 'winners', 'people', 'accounts', 'vote_orders', 'ticket_orders', 'occasions', 'contact_messages'])) === 0);
check('roles and permissions are still seeded', $n($fresh, 'SELECT COUNT(*) FROM permissions') > 30 && $n($fresh, 'SELECT COUNT(*) FROM roles') >= 9);
$fresh->exec(preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($root . '/database/migrations/009_initial_edition.sql')));
check('the seed migration is safe to run twice', $n($fresh, 'SELECT COUNT(*) FROM editions') === 1 && $n($fresh, 'SELECT COUNT(*) FROM committee_units') === 4);

[$old, $oldName, $oldCfg] = $mk('old', false, true);
$old->prepare("INSERT INTO people (name,email,password_hash,active,is_super_admin) VALUES ('Boss','boss@x.test','x',1,1)")->execute();
check('sample data is in place before the purge', $n($old, 'SELECT COUNT(*) FROM editions') === 2 && $n($old, "SELECT COUNT(*) FROM nominee_entries WHERE name LIKE 'Sample%'") > 0);
[$code, $out] = $tool($oldCfg, 'purge-demo-data.php');
check('purge without --yes changes nothing', $code === 0 && str_contains($out, 'Nothing changed') && $n($old, 'SELECT COUNT(*) FROM editions') === 2);
$old->prepare("INSERT INTO people (name,email,password_hash,active) VALUES ('Real Editor','editor@x.test','x',1)")->execute();
[$code] = $tool($oldCfg, 'purge-demo-data.php', ['--yes']);
check('purge refuses when real admin accounts would be affected', $code === 1 && $n($old, 'SELECT COUNT(*) FROM editions') === 2);
$old->exec("DELETE FROM people WHERE email = 'editor@x.test'");
[$code, $out] = $tool($oldCfg, 'purge-demo-data.php', ['--yes']);
check('purge --yes succeeds', $code === 0 && str_contains($out, 'Done'), $out);
check('after the purge: one empty OGA 10.0, no sample people or nominees, Super admin kept',
    $n($old, 'SELECT COUNT(*) FROM editions') === 1 && $n($old, "SELECT COUNT(*) FROM editions WHERE slug='oga-10' AND status='active'") === 1
    && $n($old, 'SELECT COUNT(*) FROM nominee_entries') === 0 && $n($old, 'SELECT COUNT(*) FROM winners') === 0 && $n($old, "SELECT COUNT(*) FROM people WHERE name LIKE 'Sample%'") === 0
    && $n($old, "SELECT COUNT(*) FROM people WHERE email = 'boss@x.test'") === 1 && $n($old, 'SELECT COUNT(*) FROM committee_units') === 4);
check('purge keeps permissions and roles', $n($old, 'SELECT COUNT(*) FROM permissions') > 30);
$admin->exec("DROP DATABASE IF EXISTS `$freshName`");
$admin->exec("DROP DATABASE IF EXISTS `$oldName`");
check('shipped site files contain no sample data', !is_file($root . '/data/nominees.json') && !is_file($root . '/data/editions.json') && !is_file($root . '/data/winners.json') && !is_file($root . '/backend/tools/load-demo-data.php')
    && !array_key_exists('members', json_decode((string) file_get_contents($root . '/data/committee.json'), true)) && json_decode((string) file_get_contents($root . '/data/gallery.json'), true) === []);

// ================================================================== G. EMAIL IN ONE PLACE (Round 2, phase 4)
group('G. Email: editable templates, preview, test send, outbox viewer, retry');
$mails = sys_get_temp_dir() . '/oga-smtp-' . bin2hex(random_bytes(4)) . '.out';
$smtpPort = 0;
$smtpProc = null;
for ($try = 0; $try < 20 && !$smtpProc; $try++) {
    $smtpPort = random_int(20000, 40000);
    $p = proc_open([PHP_BINARY, $root . '/tests/fake-smtp.php', (string) $smtpPort, $mails], [1 => ['file', sys_get_temp_dir() . '/fake-smtp.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/fake-smtp.log', 'a']], $pp);
    for ($i = 0; $i < 30; $i++) { if (($s = @fsockopen('127.0.0.1', $smtpPort, $e1, $e2, 0.2)) !== false) { fclose($s); $smtpProc = $p; break; } usleep(100000); }
}
check('a test mail server is running', $smtpProc !== null);
$sent = fn () => is_file($mails) ? (string) file_get_contents($mails) : '';

[$c, $r] = $sa->get("/admin/email?edition=$ed");
$keys = array_column($r['templates'] ?? [], 'key');
check('admin sees all nine email templates, none customised yet', $c === 200 && count($keys) === 9 && !array_filter($r['templates'], fn ($t) => $t['custom']));
check('every template lists its placeholders', !array_filter($r['templates'], fn ($t) => !isset($t['placeholders']['site_name']) || $t['placeholders'] === []));
check('email page is hidden from gate staff', $gate->get("/admin/email?edition=$ed")[0] === 403 && $gate->get("/admin/email/outbox?edition=$ed")[0] === 403);
check('email is not set up before SMTP is saved', ($r['configured'] ?? null) === false);

// wording and the layout rules
$m = App\MailTemplates::render('committee_shortlisted', 'a@b.co', ['applicantName' => 'Ada', 'venue' => 'Hall A']);
check('a line with only empty placeholders is dropped, others stay', str_contains($m['text'], 'Venue: Hall A') && !str_contains($m['text'], 'Date:') && !str_contains($m['text'], 'Time:'));
$m = App\MailTemplates::render('nominee_verify_email', 'a@b.co', ['token' => 'CODE-123', 'expiresMinutes' => 15]);
check('verification email shows the code in a code block and the expiry', str_contains($m['html'], 'monospace') && str_contains($m['html'], 'CODE-123') && str_contains($m['text'], '15 minutes'));
check('a message whose one-time code was wiped cannot be built', App\MailTemplates::render('nominee_verify_email', 'a@b.co', []) === null);
check('the approval button is left out when there is no WhatsApp link', !str_contains(App\MailTemplates::render('committee_approved', 'a@b.co', ['applicantName' => 'Ada'])['html'], 'WhatsApp') && str_contains(App\MailTemplates::render('committee_approved', 'a@b.co', ['applicantName' => 'Ada', 'whatsappGroupUrl' => 'https://chat.whatsapp.com/x'])['html'], 'WhatsApp'));

// editing a template
$t = "/admin/email/templates/nominee_verify_email";
$good = ['edition' => $ed, 'subject' => 'Your {{site_name}} code', 'body' => "Hi! Your code is below. It works for {{expires_minutes}} minutes.\n\n{{code}}\n\n<b>Thanks</b>"];
[$c, $r] = $sa->put($t, $good);
check('admin can edit a template', $c === 200 && count(array_filter($r['templates'], fn ($x) => $x['custom'])) === 1, (string) $c);
App\MailTemplates::forget();
$m = App\MailTemplates::render('nominee_verify_email', 'a@b.co', ['token' => 'CODE-9', 'expiresMinutes' => 20]);
check('the edited wording is what gets sent', str_contains($m['subject'], 'Your ') && str_contains($m['subject'], ' code') && str_contains($m['text'], 'works for 20 minutes') && str_contains($m['text'], 'CODE-9'));
check('HTML typed into a template is escaped, not run', str_contains($m['html'], '&lt;b&gt;Thanks&lt;/b&gt;') && !str_contains($m['html'], '<b>Thanks</b>'));
check('unknown placeholders are refused', $sa->put($t, ['body' => 'Hi {{password}} {{code}}'] + $good)[0] === 422);
check('a template must keep its essential placeholder', $sa->put($t, ['body' => 'Hello, no code here.'] + $good)[0] === 422);
check('a subject cannot span lines (no header injection)', $sa->put($t, ['subject' => "Hi\r\nBcc: x@y.z"] + $good)[0] === 422);
check('an over-long template is refused', $sa->put($t, ['body' => str_repeat('a ', 3000) . '{{code}}'] + $good)[0] === 422);
check('an unknown template is a 404', $sa->put('/admin/email/templates/nope', $good)[0] === 404);
check('gate staff cannot edit templates', $gate->put($t, $good)[0] === 403);
check('template edits are in the audit log', (int) $q("SELECT COUNT(*) FROM audit_log WHERE action = 'Edited email template'")->fetchColumn() >= 1);

// preview (unsaved wording, sample values)
[$c, $r] = $sa->post("$t/preview", ['edition' => $ed, 'subject' => 'Preview {{site_name}}', 'body' => "Code:\n\n{{code}}\n\n{{expires_minutes}}"]);
check('preview renders unsaved wording with sample values', $c === 200 && str_contains($r['html'] ?? '', 'K7Q2-M9XD-4TRA') && str_contains($r['subject'] ?? '', 'Preview'));
App\MailTemplates::forget();
check('preview refuses bad wording and saves nothing', $sa->post("$t/preview", ['edition' => $ed, 'subject' => 'x', 'body' => '{{nope}}'])[0] === 422 && App\MailTemplates::wording('nominee_verify_email')['subject'] === 'Your {{site_name}} code');

// reset
[$c, $r] = $sa->call('DELETE', $t, ['edition' => $ed]);
App\MailTemplates::forget();
check('reset puts the default wording back', $c === 200 && App\MailTemplates::wording('nominee_verify_email')['custom'] === false && App\MailTemplates::wording('nominee_verify_email')['subject'] === 'Verify your email address');

// delivery settings, one place
check('only SMTP delivery is accepted', $sa->put('/admin/settings/communications', ['edition' => $ed, 'value' => ['emailMode' => 'api', 'fromEmail' => 'a@b.co', 'smtpHost' => '127.0.0.1', 'smtpPort' => '25', 'smtpEncryption' => 'none']])[0] === 422);
[$c] = $sa->post('/admin/email/test', ['edition' => $ed, 'to' => 'me@x.test']);
$before = $sa->post('/admin/email/test', ['edition' => $ed, 'to' => 'me@x.test'])[1];
check('test email says so plainly when email is not set up', $c === 200 && ($before['ok'] ?? null) === false && str_contains($before['message'] ?? '', 'not set up'));
[$c] = $sa->put('/admin/settings/communications', ['edition' => $ed, 'value' => ['emailMode' => 'smtp', 'fromName' => 'OGA Test', 'fromEmail' => 'noreply@x.test', 'replyTo' => 'help@x.test', 'footerText' => 'Footer from the admin.', 'smtpHost' => '127.0.0.1', 'smtpPort' => (string) $smtpPort, 'smtpEncryption' => 'none', 'smtpUsername' => '', 'smtpPassword' => '', 'templateReceived' => 'old dead field']]);
check('admin saves sender and SMTP in one place', $c === 200, (string) $c);
[, $r, $raw] = $sa->get("/admin/email?edition=$ed");
check('email now reports as set up; no password in the response', ($r['configured'] ?? null) === true && ($r['delivery']['fromEmail'] ?? '') === 'noreply@x.test' && !str_contains($raw, 'smtpPassword"'));
check('the old do-nothing template fields are no longer stored', !str_contains(json_encode($sa->get("/admin/settings?edition=$ed")[1]['settings']['communications'] ?? []), 'templateReceived'));

// real sending through the test mail server
[$c, $r] = $sa->post('/admin/email/test', ['edition' => $ed, 'to' => 'me@x.test']);
check('plain test email is delivered', $c === 200 && ($r['ok'] ?? false) === true && str_contains($sent(), '[TEST] Email from'), json_encode($r));
[$c, $r] = $sa->post('/admin/email/test', ['edition' => $ed, 'to' => 'me@x.test', 'template' => 'nominee_password_reset']);
check('a template test email uses sample values, never real codes', ($r['ok'] ?? false) === true && str_contains($sent(), '[TEST] Reset your password') && str_contains($sent(), 'K7Q2-M9XD-4TRA'));
check('the sender name, reply-to and footer come from the admin settings', str_contains($sent(), 'OGA Test') && str_contains($sent(), 'help@x.test') && str_contains($sent(), 'Footer from the admin.'));
check('test email refuses a bad address', $sa->post('/admin/email/test', ['edition' => $ed, 'to' => 'not-an-email'])[0] === 422);
check('gate staff cannot send test email', $gate->post('/admin/email/test', ['edition' => $ed, 'to' => 'me@x.test'])[0] === 403);

$db->exec('DELETE FROM notification_outbox');
$sa->put("/admin/email/templates/committee_reserve", ['edition' => $ed, 'subject' => 'Reserve news', 'body' => "Dear {{name}},\n\nCUSTOM-RESERVE-TEXT {{message}}"]);
App\MailTemplates::forget();
$a = App\Outbox::queue('nominee_verify_email', 'v@x.test', ['token' => 'REAL-SECRET-CODE', 'expiresMinutes' => 30]);
$b = App\Outbox::queue('committee_reserve', 'r@x.test', ['applicantName' => 'Ada', 'message' => 'wait']);
$res = App\Outbox::sendPending(10);
check('queued emails are sent over SMTP', $res['sent'] === 2 && str_contains($sent(), 'REAL-SECRET-CODE') && str_contains($sent(), 'CUSTOM-RESERVE-TEXT'), json_encode($res));
check('the real code is wiped from the outbox after sending', !str_contains((string) $q('SELECT payload FROM notification_outbox WHERE id = ?', [$a])->fetchColumn(), 'REAL-SECRET-CODE'));

// outbox viewer
for ($i = 1; $i <= 30; $i++) { App\Outbox::queue('committee_unsuccessful', "bulk$i@x.test", ['applicantName' => "B$i"]); }
[$c, $r, $raw] = $sa->get("/admin/email/outbox?edition=$ed");
check('outbox is paged on the server', $c === 200 && count($r['rows']) === 25 && $r['total'] === 32 && $r['pages'] === 2);
check('page two has the rest', count($sa->get("/admin/email/outbox?edition=$ed&page=2")[1]['rows']) === 7);
check('outbox filters by status', $sa->get("/admin/email/outbox?edition=$ed&status=sent")[1]['total'] === 2 && $sa->get("/admin/email/outbox?edition=$ed&status=queued")[1]['total'] === 30);
check('outbox searches by recipient on request', $sa->get("/admin/email/outbox?edition=$ed&q=bulk17")[1]['total'] === 1 && $sa->get("/admin/email/outbox?edition=$ed&q=" . rawurlencode('%'))[1]['total'] === 0);
check('outbox never shows message contents or codes', !str_contains($raw, 'payload') && !str_contains($raw, 'REAL-SECRET') && !str_contains($raw, '"token"'));
check('outbox is closed to gate staff', $gate->post('/admin/email/outbox/send', ['edition' => $ed])[0] === 403);
[$c, $r] = $sa->post('/admin/email/outbox/send', ['edition' => $ed]);
check('"send now" sends the waiting messages', $c === 200 && ($r['result']['sent'] ?? 0) === 30, json_encode($r));

// failures and retry
$q("INSERT INTO notification_outbox (type, recipient, payload, status, attempts, last_error) VALUES ('committee_reserve', 'retry@x.test', ?, 'failed', 5, 'Connection refused')", [json_encode(['applicantName' => 'Zed', 'message' => 'm'])]);
$failOk = (int) $db->lastInsertId();
$q("INSERT INTO notification_outbox (type, recipient, payload, status, attempts, last_error) VALUES ('nominee_verify_email', 'gone@x.test', '{}', 'failed', 5, 'Mailbox full')");
$failWiped = (int) $db->lastInsertId();
$rows = array_column($sa->get("/admin/email/outbox?edition=$ed&status=failed")[1]['rows'], null, 'recipient');
check('failure reasons are visible and retryability is worked out', ($rows['retry@x.test']['error'] ?? '') === 'Connection refused' && $rows['retry@x.test']['retryable'] === true && $rows['gone@x.test']['retryable'] === false);
[$c, $r] = $sa->post("/admin/email/outbox/$failOk/retry", ['edition' => $ed]);
check('a failed email can be retried and is sent', $c === 200 && ($r['ok'] ?? false) === true && $q('SELECT status FROM notification_outbox WHERE id = ?', [$failOk])->fetchColumn() === 'sent');
[$c, $r] = $sa->post("/admin/email/outbox/$failWiped/retry", ['edition' => $ed]);
check('an email whose one-time code was deleted is not retried', ($r['ok'] ?? true) === false && str_contains($r['message'] ?? '', 'one-time code'));
check('a sent email cannot be "retried"', ($sa->post("/admin/email/outbox/$failOk/retry", ['edition' => $ed])[1]['ok'] ?? true) === false);
check('retry is closed to gate staff', $gate->post("/admin/email/outbox/$failOk/retry", ['edition' => $ed])[0] === 403);

$last = 0;
for ($i = 0; $i < 12; $i++) { $last = $sa->post('/admin/email/test', ['edition' => $ed, 'to' => 'me@x.test'])[0]; }
check('test emails are rate limited', $last === 429);

if ($smtpProc) { proc_terminate($smtpProc); proc_close($smtpProc); }
@unlink($mails);

// ================================================================== H. SPEED (Round 2, phases 5 and 6)
group('H. Speed: paged lists, indexes, precomputed vote totals, caching, search button');
$idx = fn (string $table, string $name) => (int) $q("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?", [$table, $name])->fetchColumn() > 0;
check('migration 011 created the list indexes', $idx('vote_orders', 'ix_vo_ed_id') && $idx('vote_orders', 'ix_vo_ed_status_id') && $idx('vote_orders', 'ix_vo_ed_cat_status_id') && $idx('ticket_orders', 'ix_to_ed_status_id')
    && $idx('tickets', 'ix_ticket_ed_id') && $idx('audit_log', 'ix_audit_ed_id') && $idx('contact_messages', 'ix_cm_ed_read_id') && $idx('notification_outbox', 'ix_no_status_id'));

// ---- data: 60 vote orders in three states, two nominees, one category
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id IN ({$entry['na']}, {$entry['nb']})");
$stmt = $db->prepare("INSERT INTO vote_orders (edition_id,reference,nominee_entry_id,category_id,votes,amount,voter_name,voter_email,status,source,reason) VALUES (2,?,?,2,?,?,?,?,?,?,?)");
for ($i = 1; $i <= 60; $i++) {
    $manual = $i % 10 === 0;
    $stmt->execute(["VOT-P$i", $i % 2 ? $entry['na'] : $entry['nb'], $i, $i * 50, "Voter $i", "v$i@x.test", ['paid', 'paid', 'paid', 'pending', 'void'][$i % 5], $manual ? 'manual' : 'payment', $manual ? 'cash votes at booth' : null]);
}
App\VoteTotals::rebuild($db);
$sql = fn (string $s, array $a = []) => $q($s, $a)->fetchColumn();
$allOrders = (int) $sql('SELECT COUNT(*) FROM vote_orders WHERE edition_id = 2');

// ---- the vote ledger, paged on the server
[$c, $r] = $sa->get("/admin/votes?edition=$ed&page=1&perPage=25");
check('ledger is paged on the server', $c === 200 && count($r['orders']) === 25 && $r['total'] === $allOrders && $r['pages'] === (int) ceil($allOrders / 25) && $r['page'] === 1, json_encode(array_diff_key($r, ['orders' => 1])));
check('newest order first, with nominee and category names joined in', $r['orders'][0]['reference'] === 'VOT-P60' && !empty($r['orders'][0]['nomineeName']) && $r['orders'][0]['categoryName'] === 'Academic Excellence');
check('page size is limited to 5-500', $sa->get("/admin/votes?edition=$ed&page=1&perPage=1")[1]['perPage'] === 5 && $sa->get("/admin/votes?edition=$ed&page=1&perPage=99999")[1]['perPage'] === 500);
check('a page past the end shows the last page', $sa->get("/admin/votes?edition=$ed&page=999&perPage=25")[1]['page'] === (int) ceil($allOrders / 25));
$r = $sa->get("/admin/votes?edition=$ed&page=1&perPage=100&status=pending")[1];
check('ledger filters by status', $r['total'] === (int) $sql("SELECT COUNT(*) FROM vote_orders WHERE edition_id = 2 AND status = 'pending'") && !array_filter($r['orders'], fn ($o) => $o['status'] !== 'pending'));
$r = $sa->get("/admin/votes?edition=$ed&page=1&perPage=100&source=manual")[1];
check('ledger filters by source', $r['total'] === 6 && !array_filter($r['orders'], fn ($o) => $o['source'] !== 'manual'));
check('ledger filters by category', $sa->get("/admin/votes?edition=$ed&page=1&categoryId=2")[1]['total'] === $allOrders && $sa->get("/admin/votes?edition=$ed&page=1&categoryId=3")[1]['total'] === 0);
check('ledger searches by voter name', ($sa->get("/admin/votes?edition=$ed&page=1&q=" . rawurlencode('Voter 17'))[1]['orders'][0]['reference'] ?? '') === 'VOT-P17');
check('ledger searches by reference and email', $sa->get("/admin/votes?edition=$ed&page=1&q=VOT-P5")[1]['total'] === 11 && $sa->get("/admin/votes?edition=$ed&page=1&q=v42@x.test")[1]['total'] === 1);
$naName = (string) $sql('SELECT name FROM nominee_entries WHERE id = ?', [$entry['na']]);   // earlier tests renamed it
check('ledger searches by nominee and category name', $sa->get("/admin/votes?edition=$ed&page=1&q=" . rawurlencode($naName))[1]['total'] >= (int) $sql("SELECT COUNT(*) FROM vote_orders WHERE nominee_entry_id = ?", [$entry['na']]) && $sa->get("/admin/votes?edition=$ed&page=1&q=Academic")[1]['total'] === $allOrders);
check('a search for % finds nothing (wildcards are plain characters)', $sa->get("/admin/votes?edition=$ed&page=1&q=" . rawurlencode('%'))[1]['total'] === 0);
check('ledger sorts on the server', $sa->get("/admin/votes?edition=$ed&page=1&sort=votes&dir=asc")[1]['orders'][0]['votes'] === 1 && $sa->get("/admin/votes?edition=$ed&page=1&sort=votes&dir=desc")[1]['orders'][0]['votes'] === 99);
check('sort names outside the whitelist are ignored (no SQL injection)', ($sa->get("/admin/votes?edition=$ed&page=1&sort=" . rawurlencode('id; DROP TABLE vote_orders'))[1]['orders'][0]['reference'] ?? '') === 'VOT-P60' && ($sa->get("/admin/votes?edition=$ed&page=1&sort=nomineeName")[1]['orders'][0]['reference'] ?? '') === 'VOT-P60' && (int) $sql('SELECT COUNT(*) FROM vote_orders') > 0);
$st = $sa->get("/admin/votes?edition=$ed&page=1&stats=1")[1]['stats'] ?? [];
check('ledger totals match the orders', ($st['paidVotes'] ?? -1) === (int) $sql("SELECT SUM(votes) FROM vote_orders WHERE edition_id = 2 AND status = 'paid' AND source <> 'manual'") && ($st['manualVotes'] ?? -1) === (int) $sql("SELECT SUM(votes) FROM vote_orders WHERE edition_id = 2 AND status = 'paid' AND source = 'manual'")
    && ($st['pending'] ?? -1) === (int) $sql("SELECT COUNT(*) FROM vote_orders WHERE edition_id = 2 AND status = 'pending'") && ($st['void'] ?? -1) === (int) $sql("SELECT COUNT(*) FROM vote_orders WHERE edition_id = 2 AND status = 'void'")
    && abs(($st['amount'] ?? -1) - (float) $sql("SELECT SUM(amount) FROM vote_orders WHERE edition_id = 2 AND status = 'paid'")) < 0.01, json_encode($st));
$r = $sa->get("/admin/votes?edition=$ed")[1];
check('without ?page= the ledger answers as before', isset($r['orders']) && !isset($r['total']));
check('the ledger is closed to gate staff', $gate->get("/admin/votes?edition=$ed&page=1")[0] === 403);

// ---- precomputed vote totals
check('vote totals match the orders after the data load', App\VoteTotals::drift() === []);
$tally = fn (int $n) => (int) ($sa->get("/admin/results?edition=$ed")[1]['tally'][(string) $n] ?? 0);
$t0 = $tally($entry['na']);
[$c, $r] = $sa->post('/admin/votes/manual', ['edition' => $ed, 'nomineeId' => $entry['na'], 'votes' => 7, 'reason' => 'cash votes at the booth']);
$manualRef = $r['reference'] ?? '';
check('manual votes raise the total at once', $c === 200 && $tally($entry['na']) === $t0 + 7 && App\VoteTotals::drift() === []);
check('results read the precomputed totals', $tally($entry['na']) === (int) $sql("SELECT SUM(votes) FROM vote_totals WHERE nominee_entry_id = ?", [$entry['na']]));
check('voiding a paid manual order lowers the totals', $sa->post("/admin/votes/$manualRef/void", ['edition' => $ed, 'reason' => 'entered twice'])[0] === 200 && $tally($entry['na']) === $t0 && App\VoteTotals::drift() === []);
check('voiding the same order twice is refused', $sa->post("/admin/votes/$manualRef/void", ['edition' => $ed])[0] === 422 && $tally($entry['na']) === $t0);
$t1 = $tally($entry['na']);
check('voiding a paid online order lowers the totals', $sa->post('/admin/votes/VOT-P1/void', ['edition' => $ed])[0] === 200 && $tally($entry['na']) === $t1 - 1 && App\VoteTotals::drift() === []);
check('voiding a pending order does not change the totals', $sa->post('/admin/votes/VOT-P3/void', ['edition' => $ed])[0] === 200 && App\VoteTotals::drift() === []);
$d = $sa->get("/admin/dashboard?edition=$ed")[1];
check('the dashboard reads the precomputed totals', ($d['votes'] ?? -1) === (int) $sql("SELECT SUM(votes) FROM vote_orders WHERE edition_id = 2 AND status = 'paid'"), json_encode($d['votes'] ?? null));
// a payment being confirmed (the code path Flutterwave's confirmation runs)
$db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (2, 'vote', 'PAY-H1', 200, 'NGN', 'p@x.test', 'pending')")->execute();
$payId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO vote_orders (edition_id,reference,nominee_entry_id,category_id,votes,amount,voter_name,voter_email,status,source,payment_id) VALUES (2,'VOT-H1',?,2,4,200,'P','p@x.test','pending','payment',?)")->execute([$entry['nb'], $payId]);
$tb = $tally($entry['nb']);
$db->prepare("UPDATE vote_orders SET status = 'paid' WHERE payment_id = ?")->execute([$payId]);
App\VoteTotals::creditPayment($db, $payId);
check('crediting a payment adds its votes to the totals', $tally($entry['nb']) === $tb + 4 && App\VoteTotals::drift() === []);
$db->exec('UPDATE vote_totals SET votes = votes + 5 LIMIT 1');
[$code, $out] = $tool($cfgFile, 'rebuild-vote-totals.php');
check('the check tool notices totals that drifted', $code === 1 && str_contains($out, 'differ') && App\VoteTotals::drift() !== []);
[$code, $out] = $tool($cfgFile, 'rebuild-vote-totals.php', ['--fix']);
check('the tool rebuilds them from the orders', $code === 0 && App\VoteTotals::drift() === [] && str_contains($out, 'Rebuilt'), $out);

// ---- tickets, paged
$types = (int) $sql('SELECT id FROM ticket_types WHERE edition_id = 2 LIMIT 1');
$ins = $db->prepare("INSERT INTO ticket_orders (edition_id,reference,ticket_type_id,qty,amount,buyer_name,buyer_email,buyer_phone,status) VALUES (2,?,?,?,?,?,?,'0800',?)");
for ($i = 1; $i <= 30; $i++) {
    $ins->execute(["TK-P$i", $types, 1 + $i % 2, 1000 * $i, "Buyer $i", "b$i@x.test", ['paid', 'paid', 'pending'][$i % 3]]);
    $oid = (int) $db->lastInsertId();
    if ($i % 3 !== 2) {
        $db->prepare("INSERT INTO ticket_groups (edition_id,order_id,group_code,seats) VALUES (2,?,?,1)")->execute([$oid, "GRP-P$i"]);
        $db->prepare("INSERT INTO tickets (edition_id,order_id,group_id,ticket_code,seat_no,holder_name,status,checked_in_at) VALUES (2,?,?,?,1,?,'issued',?)")->execute([$oid, $db->lastInsertId(), "TKT-P$i", "Holder $i", $i % 4 === 0 ? date('Y-m-d H:i:s') : null]);
    }
}
$allTk = (int) $sql('SELECT COUNT(*) FROM ticket_orders WHERE edition_id = 2');
[$c, $r] = $sa->get("/admin/tickets?edition=$ed&view=orders&page=1&perPage=10&stats=1");
check('ticket orders are paged on the server', $c === 200 && count($r['orders']) === 10 && $r['total'] === $allTk && $r['pages'] === (int) ceil($allTk / 10), json_encode(array_diff_key($r, ['orders' => 1])));
check('ticket orders filter, search and sort', $sa->get("/admin/tickets?edition=$ed&view=orders&page=1&status=pending")[1]['total'] === (int) $sql("SELECT COUNT(*) FROM ticket_orders WHERE edition_id = 2 AND status = 'pending'")
    && $sa->get("/admin/tickets?edition=$ed&view=orders&page=1&q=" . rawurlencode('Buyer 7'))[1]['total'] === 1 && $sa->get("/admin/tickets?edition=$ed&view=orders&page=1&sort=amount&dir=desc")[1]['orders'][0]['amount'] == 30000);
check('ticket totals match the orders and tickets', ($r['stats']['issued'] ?? -1) === (int) $sql('SELECT COUNT(*) FROM tickets WHERE edition_id = 2') && ($r['stats']['used'] ?? -1) === (int) $sql('SELECT COUNT(*) FROM tickets WHERE edition_id = 2 AND checked_in_at IS NOT NULL')
    && ($r['stats']['paidQty'] ?? -1) === (int) $sql("SELECT SUM(qty) FROM ticket_orders WHERE edition_id = 2 AND status = 'paid'") && abs(($r['stats']['revenue'] ?? -1) - (float) $sql("SELECT SUM(amount) FROM ticket_orders WHERE edition_id = 2 AND status = 'paid'")) < 0.01, json_encode($r['stats'] ?? null));
[$c, $r] = $sa->get("/admin/tickets?edition=$ed&view=issued&page=1&perPage=10");
check('issued tickets are paged on the server', $c === 200 && count($r['tickets']) === 10 && $r['total'] === (int) $sql('SELECT COUNT(*) FROM tickets WHERE edition_id = 2'));
check('issued tickets filter by check-in and search by holder', $sa->get("/admin/tickets?edition=$ed&view=issued&page=1&state=used")[1]['total'] === (int) $sql('SELECT COUNT(*) FROM tickets WHERE edition_id = 2 AND checked_in_at IS NOT NULL') && $sa->get("/admin/tickets?edition=$ed&view=issued&page=1&q=" . rawurlencode('Holder 12'))[1]['total'] === 1);
$r = $sa->get("/admin/tickets?edition=$ed")[1];
check('without ?page= tickets answer as before', isset($r['orders'], $r['tickets']) && !isset($r['total']));
check('tickets are closed to gate staff on the paged list', $gate->get("/admin/tickets?edition=$ed&view=orders&page=1")[0] === 403);

// ---- messages, nominees, audit
for ($i = 1; $i <= 30; $i++) { $db->prepare("INSERT INTO contact_messages (edition_id,name,email,subject,message,is_read) VALUES (2,?,?,?,?,?)")->execute(["Sender $i", "s$i@x.test", "Subject $i", "Hello number $i", $i % 3 === 0 ? 1 : 0]); }
$allMsg = (int) $sql('SELECT COUNT(*) FROM contact_messages WHERE edition_id = 2');
[$c, $r] = $sa->get("/admin/messages?edition=$ed&page=1&perPage=10");
check('messages are paged on the server', $c === 200 && count($r['messages']) === 10 && $r['total'] === $allMsg);
check('messages filter, search and sort', $sa->get("/admin/messages?edition=$ed&page=1&status=read")[1]['total'] === (int) $sql('SELECT COUNT(*) FROM contact_messages WHERE edition_id = 2 AND is_read = 1')
    && $sa->get("/admin/messages?edition=$ed&page=1&q=" . rawurlencode('number 22'))[1]['total'] === 1 && ($sa->get("/admin/messages?edition=$ed&page=1&sort=name&dir=asc")[1]['messages'][0]['name'] ?? '') <= 'Sender 2');
check('without ?page= messages answer as before', isset($sa->get("/admin/messages?edition=$ed")[1]['messages']) && !isset($sa->get("/admin/messages?edition=$ed")[1]['total']));
$ins = $db->prepare("INSERT INTO nominee_entries (edition_id,category_id,name,department,status) VALUES (2,?,?,?,?)");
for ($i = 1; $i <= 30; $i++) { $ins->execute([$i % 2 ? 2 : 3, "Paged Person $i", $i % 2 ? 'Law' : 'Art', ['approved', 'pending', 'rejected'][$i % 3]]); }
$allNom = (int) $sql('SELECT COUNT(*) FROM nominee_entries WHERE edition_id = 2');
[$c, $r] = $sa->get("/admin/nominees?edition=$ed&page=1&perPage=10");
check('nominees are paged on the server', $c === 200 && count($r['nominees']) === 10 && $r['total'] === $allNom);
check('nominees filter, search and sort', $sa->get("/admin/nominees?edition=$ed&page=1&status=rejected")[1]['total'] === (int) $sql("SELECT COUNT(*) FROM nominee_entries WHERE edition_id = 2 AND status = 'rejected'")
    && $sa->get("/admin/nominees?edition=$ed&page=1&categoryId=3")[1]['total'] === (int) $sql('SELECT COUNT(*) FROM nominee_entries WHERE edition_id = 2 AND category_id = 3')
    && $sa->get("/admin/nominees?edition=$ed&page=1&q=Law")[1]['total'] === (int) $sql("SELECT COUNT(*) FROM nominee_entries WHERE edition_id = 2 AND department = 'Law'")
    && ($sa->get("/admin/nominees?edition=$ed&page=1&sort=name&dir=asc&perPage=50")[1]['nominees'][0]['name'] ?? '') === (string) $sql('SELECT MIN(name) FROM nominee_entries WHERE edition_id = 2'));
$all = $sa->get("/admin/nominees?edition=$ed")[1];
check('without ?page= the nominee list is complete (the other pages use it as a lookup)', count($all['nominees'] ?? []) === $allNom && !isset($all['total']));
[$c, $r] = $sa->get('/admin/audit?page=1&perPage=10&meta=1');
check('the audit log is paged on the server', $c === 200 && count($r['entries']) === 10 && $r['total'] > 10 && !empty($r['admins']));
check('audit pages do not repeat rows', $sa->get('/admin/audit?page=2&perPage=10')[1]['entries'][0]['id'] !== $r['entries'][0]['id']);
check('the audit log filters and searches', $sa->get('/admin/audit?page=1&q=' . rawurlencode('manual votes'))[1]['total'] >= 1 && $sa->get('/admin/audit?page=1&editionKey=none')[1]['total'] === (int) $sql('SELECT COUNT(*) FROM audit_log WHERE edition_id IS NULL') && $sa->get('/admin/audit?page=1&admin=' . $r['admins'][0]['id'])[0] === 200);
check('without ?page= the audit log answers as before', isset($sa->get('/admin/audit')[1]['entries']) && !isset($sa->get('/admin/audit')[1]['total']));

// ---- caching
check('ETag matching ignores Apache\'s -gzip suffix, weak markers and lists', App\Response::etagMatches('"abc"', '"abc"') && App\Response::etagMatches('"abc"', '"abc-gzip"') && App\Response::etagMatches('"abc"', 'W/"abc"')
    && App\Response::etagMatches('"abc"', '"zzz", "abc-gzip"') && !App\Response::etagMatches('"abc"', '"abd"') && !App\Response::etagMatches('"abc"', ''));
$httpGet = function (string $path, array $headers) {
    $ch = curl_init(API . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    $raw = (string) curl_exec($ch);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, strtolower(substr($raw, 0, $hs)), substr($raw, $hs)];
};
[$code, $h, $body] = $httpGet('/nominees', ['Accept-Encoding: gzip']);
check('public data is compressed for browsers that accept it', $code === 200 && str_contains($h, 'content-encoding: gzip') && str_contains($h, 'vary: accept-encoding') && str_starts_with($body, "\x1f\x8b"));
preg_match('/etag: ("[^"]+")/', $h, $m);
check('public data is cacheable and carries an ETag', str_contains($h, 'cache-control: public, max-age=30') && !empty($m[1]));
check('an unchanged page answers 304 (also with Apache\'s -gzip suffix)', $httpGet('/nominees', ['If-None-Match: ' . $m[1]])[0] === 304 && $httpGet('/nominees', ['Accept-Encoding: gzip', 'If-None-Match: ' . substr($m[1], 0, -1) . '-gzip"'])[0] === 304);
$key = 'h-test-' . bin2hex(random_bytes(3));
check('a cache entry is built once, then served from the cache', App\Cache::remember($key, 60, fn () => 'one') === 'one' && App\Cache::remember($key, 60, fn () => 'two') === 'one');
$file = rtrim((string) App\Env::get('CACHE_DIR'), '/\\') . '/' . $key . '.cache';
touch($file, time() - 120);
$lock = fopen($file . '.lock', 'c');
flock($lock, LOCK_EX);
check('while one request rebuilds, an expired entry is served as it was (no pile-up on the database)', App\Cache::remember($key, 60, fn () => 'two') === 'one');
flock($lock, LOCK_UN);
fclose($lock);
check('once the rebuild lock is free the next request rebuilds', App\Cache::remember($key, 60, fn () => 'two') === 'two');

// ---- search button rule, and what ships
$files = [];
foreach (['site', 'shared', 'admin'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with($f->getFilename(), '.js') && $f->getFilename() !== 'search.js') { $files[] = $f->getPathname(); }
    }
}
$bad = [];
foreach ($files as $f) {
    $src = (string) file_get_contents($f);
    if (str_contains($src, 'type="search"') && !str_contains($src, 'bindSearch(') && !str_contains($src, 'data-dt="searchBtn"')) { $bad[] = basename($f); }
}
check('every search box has a Search button (nothing searches on each keystroke)', $bad === [], implode(', ', $bad));
$dt = (string) file_get_contents($root . '/admin/js/components/data-table.js');
check('the admin tables search on the button or Enter, never on "input"', str_contains($dt, 'data-dt="searchBtn"') && !preg_match('/data-dt="search"\]\x27\)\.addEventListener\("input"/', $dt) && !str_contains($dt, "addEventListener(\"input\", (e) => {\n      this.state.q"));
$serverPages = array_filter(['votes', 'nominees', 'tickets', 'messages', 'audit'], fn ($p) => !str_contains((string) file_get_contents("$root/admin/js/pages/$p.js"), 'server: async'));
check('the lists that can grow are paged on the server', $serverPages === [], implode(', ', $serverPages));
$ht = (string) file_get_contents($root . '/.htaccess');
check('.htaccess compresses, revalidates scripts, and no longer carries the development-only cache block', str_contains($ht, 'mod_deflate') && str_contains($ht, 'RequestHeader edit "If-None-Match"') && !str_contains($ht, 'Development only'));


// ================================================================== I. MATRIC NUMBERS AND LEVELS (Round 2, phase 7)
group('I. Matric numbers, level eligibility, level progression');
$sqlv = fn (string $s, array $a = []) => $q($s, $a)->fetchColumn();
$pwHash = password_hash('Test-Pass-12345', PASSWORD_BCRYPT, ['cost' => 4]);   // ($hash above was reused for the webhook secret)
check('migration 012 added the matric and level columns and tables', $sqlv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'accounts' AND column_name IN ('matric_no','level')) OR (table_name = 'committee_applications' AND column_name = 'matric_no') OR (table_name = 'lookup_options' AND column_name = 'requires_matric'))") == 4
    && $sqlv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('matric_registry','category_levels','level_promotions')") == 3);
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'nominations' WHERE id = 2");
$six = [['name' => 'ND I', 'active' => true, 'requiresMatric' => true], ['name' => 'ND II', 'active' => true, 'requiresMatric' => true], ['name' => 'HND I', 'active' => true, 'requiresMatric' => true],
        ['name' => 'HND II', 'active' => true, 'requiresMatric' => true], ['name' => 'Staff', 'active' => true, 'requiresMatric' => false], ['name' => 'Alumni', 'active' => true, 'requiresMatric' => true]];
check('admin sets which levels need a matric number (staff do not)', $sa->put('/admin/settings/levels', ['edition' => $ed, 'value' => $six])[0] === 200);
$rules = array_column((new Client())->get('/settings')[1]['levelRules'] ?? [], 'requiresMatric', 'name');
check('the public site is told which levels need one', ($rules['HND II'] ?? null) === true && ($rules['Staff'] ?? null) === false);
check('the level editor remembers the setting', array_column(array_filter($sa->get("/admin/settings?edition=$ed")[1]['settings']['levels'], fn ($l) => $l['name'] === 'Staff'), 'requiresMatric') === [false]);

// ---- registering
$reg = fn (array $over = []) => (new Client('nominee'))->post('/nominee/register', array_merge(['occasionId' => $ed, 'name' => 'New Student', 'email' => 'new1@test.dev', 'phone' => '08031234567', 'department' => 'Software', 'level' => 'HND II',
    'matricNo' => 'hswf/24/0500', 'categoryId' => '2', 'password' => 'Test-Pass-12345', 'terms' => 'on'], $over));
[$c, $r] = $reg(['matricNo' => '']);
check('a student must give a matric number', $c === 422 && isset($r['fields']['matricNo']), json_encode($r));
check('a badly shaped matric number is refused', $reg(['matricNo' => 'HSWF-24-0500'])[0] === 422 && $reg(['matricNo' => 'HSWF/24/05'])[0] === 422 && $reg(['matricNo' => 'HSWF/24E/00500'])[0] === 422);
[$c, $r] = $reg();
check('a valid matric number registers, and is stored in capitals', $c === 200 && (string) $sqlv('SELECT matric_no FROM accounts WHERE email = ?', ['new1@test.dev']) === 'HSWF/24/0500' && ($r['account']['matricNo'] ?? '') === 'HSWF/24/0500' && ($r['account']['needsMatric'] ?? true) === false, (string) $c);
check('the number is reserved for that email', (string) $sqlv('SELECT email FROM matric_registry WHERE matric_no = ?', ['HSWF/24/0500']) === 'new1@test.dev');
[$c, $r] = $reg(['email' => 'new2@test.dev']);
check('the same matric number cannot be used with a different email', $c === 422 && isset($r['fields']['matricNo']) && str_contains($r['error'] ?? '', 'already registered'), json_encode($r));
check('the account was not created by the refused attempt', (int) $sqlv("SELECT COUNT(*) FROM accounts WHERE email = 'new2@test.dev'") === 0);
check('every format the admin set up works (here the evening format)', $reg(['email' => 'new3@test.dev', 'matricNo' => 'HSWF/24X/0501'])[0] === 200);
[$c, $r] = $reg(['email' => 'staff1@test.dev', 'level' => 'Staff', 'matricNo' => '']);
check('staff register without a matric number', $c === 200 && ($r['account']['needsMatric'] ?? true) === false && $sqlv("SELECT matric_no FROM accounts WHERE email = 'staff1@test.dev'") === null, json_encode($r));
check('a number is not required to be valid if the level has none and it is empty, but must be valid when given', $reg(['email' => 'staff2@test.dev', 'level' => 'Staff', 'matricNo' => 'bad'])[0] === 422);

// ---- category eligibility
[$c, $r] = $sa->post('/admin/categories', ['edition' => $ed, 'name' => 'HND Only Award', 'description' => 'For final years', 'icon' => 'fa-award', 'order' => 9, 'active' => true, 'eligibleLevels' => ['HND I', 'HND II']]);
$hndCat = (int) ($r['categoryId'] ?? 0);
check('admin can limit a category to some levels', $c === 200 && $hndCat > 0 && $sqlv('SELECT COUNT(*) FROM category_levels WHERE category_id = ?', [$hndCat]) == 2, json_encode($r));
check('an unknown level is refused', $sa->post('/admin/categories', ['edition' => $ed, 'name' => 'Bad Levels', 'description' => 'x', 'order' => 10, 'active' => true, 'eligibleLevels' => ['Year 9']])[0] === 422);
$pubCat = fn () => array_column((new Client())->get("/categories?edition=$ed")[1]['categories'] ?? (new Client())->get("/categories")[1], null, 'id');
$pc = $pubCat();
check('the public category list shows who may enter', ($pc[(string) $hndCat]['eligibleLevels'] ?? null) === ['HND I', 'HND II'] && ($pc['2']['eligibleLevels'] ?? null) === []);
$adminCats = array_column($sa->get("/admin/categories?edition=$ed")[1]['categories'], null, 'id');
check('the admin list shows it too', ($adminCats[(string) $hndCat]['eligibleLevels'] ?? null) === ['HND I', 'HND II']);
[$c, $r] = $reg(['email' => 'nd1@test.dev', 'matricNo' => 'HSWF/24/0502', 'level' => 'ND I', 'categoryId' => (string) $hndCat]);
check('a student whose level is not eligible cannot enter that category', $c === 422 && isset($r['fields']['categoryId']) && str_contains($r['fields']['categoryId'], 'HND I, HND II'), json_encode($r));
check('an eligible student can', $reg(['email' => 'hnd1@test.dev', 'matricNo' => 'HSWF/24/0503', 'level' => 'HND I', 'categoryId' => (string) $hndCat])[0] === 200);
check('a category with no levels is open to everyone', $reg(['email' => 'nd2@test.dev', 'matricNo' => 'HSWF/24/0504', 'level' => 'ND I', 'categoryId' => '2'])[0] === 200);
check('admin can open the category to everyone again', $sa->patch("/admin/categories/$hndCat", ['edition' => $ed, 'name' => 'HND Only Award', 'description' => 'For final years', 'icon' => 'fa-award', 'order' => 9, 'active' => true, 'eligibleLevels' => []])[0] === 200 && $sqlv('SELECT COUNT(*) FROM category_levels WHERE category_id = ?', [$hndCat]) == 0);
$tmpCat = (int) ($sa->post('/admin/categories', ['edition' => $ed, 'name' => 'Temp Category', 'description' => 'x', 'order' => 11, 'active' => true, 'eligibleLevels' => ['Staff', 'Alumni']])[1]['categoryId'] ?? 0);
check('deleting a category removes its level list', $sqlv('SELECT COUNT(*) FROM category_levels WHERE category_id = ?', [$tmpCat]) == 2 && $sa->delete("/admin/categories/$tmpCat", ['edition' => $ed])[0] === 200 && $sqlv('SELECT COUNT(*) FROM category_levels WHERE category_id = ?', [$tmpCat]) == 0);

// ---- accounts that existed before matric numbers
$db->prepare("INSERT INTO accounts (email,password_hash,email_verified_at,level) VALUES ('legacy@test.dev',?,NOW(),'HND I')")->execute([$pwHash]);
$legacyId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO nominee_entries (edition_id,account_id,category_id,name,department,level,status) VALUES (2,?,2,'Legacy One','Law','HND I','approved')")->execute([$legacyId]);
$legacyEntry = (int) $db->lastInsertId();
$lg = new Client('nominee');
[$c, $r] = $lg->login('legacy@test.dev', 'Test-Pass-12345');
check('an account without a matric number is told to add one', $c === 200 && ($r['account']['needsMatric'] ?? false) === true && array_key_exists('matricNo', $r['account'] ?? []) && $r['account']['matricNo'] === null, json_encode($r['account'] ?? null));
[$c, $r] = $lg->patch("/nominee/entries/$legacyEntry", ['name' => 'Legacy One', 'phone' => '08031234567', 'department' => 'Law', 'bio' => 'b']);
check('until then it cannot change its profile', $c === 403 && ($r['code'] ?? '') === 'matric_required', json_encode($r));
check('or add entries or photos', $lg->post('/nominee/entries', ['occasionId' => $ed, 'categoryId' => '3'])[0] === 403);
check('a bad number is refused', $lg->post('/nominee/matric', ['matricNo' => 'nope'])[0] === 422 && $lg->post('/nominee/matric', ['matricNo' => ''])[0] === 422);
check('a number that belongs to someone else is refused', $lg->post('/nominee/matric', ['matricNo' => 'HSWF/24/0001'])[0] === 422);
[$c, $r] = $lg->post('/nominee/matric', ['matricNo' => 'hswf/24/0600']);
check('the nominee can add their number once', $c === 200 && ($r['account']['matricNo'] ?? '') === 'HSWF/24/0600' && ($r['account']['needsMatric'] ?? true) === false, json_encode($r['account'] ?? null));
check('after that they can work normally again', $lg->patch("/nominee/entries/$legacyEntry", ['name' => 'Legacy One', 'phone' => '08031234567', 'department' => 'Law', 'bio' => 'b'])[0] === 200);
check('the number cannot be changed by the nominee', $lg->post('/nominee/matric', ['matricNo' => 'HSWF/24/0601'])[0] === 409);
$db->prepare("INSERT INTO accounts (email,password_hash,email_verified_at,level) VALUES ('oldstaff@test.dev',?,NOW(),'Staff')")->execute([$pwHash]);
$db->prepare("INSERT INTO nominee_entries (edition_id,account_id,category_id,name,department,level,status) VALUES (2,?,2,'Old Staff','Library','Staff','approved')")->execute([$db->query("SELECT id FROM accounts WHERE email = 'oldstaff@test.dev'")->fetchColumn()]);
$st1 = new Client('nominee');
check('a staff account without a number is not asked for one', ($st1->login('oldstaff@test.dev', 'Test-Pass-12345')[1]['account']['needsMatric'] ?? true) === false);

// ---- level is kept by the admin
$db->prepare("UPDATE nominee_entries SET level = 'ND I' WHERE id = ?")->execute([$mine]);
$na->patch("/nominee/entries/$mine", ['name' => 'Mine', 'phone' => '08031234567', 'department' => 'd', 'level' => 'HND II', 'bio' => 'b']);
check('a nominee cannot change their own level', $sqlv('SELECT level FROM nominee_entries WHERE id = ?', [$mine]) === 'ND I');

// ---- committee applications
$comm = fn (array $over = []) => (new Client())->call('POST', '/committee/applications', array_merge(['editionId' => $ed, 'name' => 'Comm Student', 'email' => 'comm1@test.dev', 'phone' => '08031234567', 'department' => 'SWD', 'level' => 'HND II',
    'matricNo' => 'HSWF/24/0700', 'unitId' => (string) $sqlv('SELECT id FROM committee_units LIMIT 1'), 'motivation' => 'I want to help the committee deliver a great awards night for everyone.'], $over), [], true);
$db->exec("UPDATE committee_config SET enabled = 1, opens_at = NOW() - INTERVAL 1 DAY, closes_at = NOW() + INTERVAL 30 DAY WHERE edition_id = 2");
check('a committee applicant must give a matric number', $comm(['matricNo' => ''])[0] === 422 && $comm(['matricNo' => 'nope'])[0] === 422);
[$c, $r] = $comm();
check('a valid application stores the number', $c === 200 && $sqlv("SELECT matric_no FROM committee_applications WHERE email = 'comm1@test.dev'") === 'HSWF/24/0700', json_encode($r));
check('a number used by another email is refused', $comm(['email' => 'comm2@test.dev'])[0] === 422);
check('a number that belongs to a nominee account is refused for another email', $comm(['email' => 'comm3@test.dev', 'matricNo' => 'HSWF/24/0001'])[0] === 422);
check('the same student (same email) can use their number in both places', $comm(['email' => 'na@test.dev', 'matricNo' => 'HSWF/24/0001', 'name' => 'Nominee A'])[0] === 200);
check('staff can apply without a number', $comm(['email' => 'comm4@test.dev', 'level' => 'Staff', 'matricNo' => ''])[0] === 200);

// ---- admin: see and correct a matric number
$r = $sa->get("/admin/nominees?edition=$ed&page=1&q=" . rawurlencode('HSWF/24/0500'))[1];
check('admin can find a nominee by matric number', ($r['total'] ?? 0) === 1 && ($r['nominees'][0]['matricNo'] ?? '') === 'HSWF/24/0500', json_encode($r['total'] ?? null));
$nid = (int) ($r['nominees'][0]['id'] ?? 0);
$editN = fn (array $over) => $sa->patch("/admin/nominees/$nid", array_merge(['edition' => $ed, 'name' => 'New Student', 'department' => 'Software', 'categoryId' => '2', 'status' => 'approved', 'bio' => ''], $over));
check('admin corrections must be valid and unique', $editN(['matricNo' => 'nope'])[0] === 422 && $editN(['matricNo' => 'HSWF/24/0001'])[0] === 422);
check('admin can correct a number', $editN(['matricNo' => 'HSWF/24/0999'])[0] === 200 && $sqlv("SELECT matric_no FROM accounts WHERE email = 'new1@test.dev'") === 'HSWF/24/0999'
    && $sqlv("SELECT COUNT(*) FROM matric_registry WHERE matric_no = 'HSWF/24/0500'") == 0 && $sqlv("SELECT email FROM matric_registry WHERE matric_no = 'HSWF/24/0999'") === 'new1@test.dev');
check('the old number is free again', $reg(['email' => 'new9@test.dev', 'matricNo' => 'HSWF/24/0500'])[0] === 200);
$editN(['level' => 'ND II']);
check('an admin level change is kept on the account too', $sqlv("SELECT level FROM accounts WHERE email = 'new1@test.dev'") === 'ND II');
check('admin edits without a level keep the level', $editN(['bio' => 'hello'])[0] === 200 && $sqlv("SELECT level FROM accounts WHERE email = 'new1@test.dev'") === 'ND II');

// ---- paid registration: the account is made when the payment is confirmed
$paidReg = function (string $ref, string $email, string $matricNo) use ($db, $pwHash) {
    $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (2, 'nominee_fee', ?, 500, 'NGN', ?, 'successful')")->execute([$ref, $email]);
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO pending_registrations (reference, edition_id, account_id, email, password_hash, profile, payment_id, status) VALUES (?, 2, NULL, ?, ?, ?, ?, 'pending')")
        ->execute([$ref, $email, $pwHash, json_encode(['name' => 'Paid Student', 'phone' => '0803', 'department' => 'Law', 'level' => 'ND II', 'matricNo' => $matricNo, 'categoryId' => 2]), $pid]);
    App\Registrations::complete($db, ['id' => $pid]);
    return $pid;
};
$db->exec('DELETE FROM smtp_settings');          // the fake mail server of group G is gone: do not try to send from the shutdown handler
$paidReg('REG-H7A', 'paid1@test.dev', 'HSWF/24/0800');
check('a paid registration creates the account with its matric number and level', $sqlv("SELECT matric_no FROM accounts WHERE email = 'paid1@test.dev'") === 'HSWF/24/0800' && $sqlv("SELECT level FROM accounts WHERE email = 'paid1@test.dev'") === 'ND II' && $sqlv("SELECT email FROM matric_registry WHERE matric_no = 'HSWF/24/0800'") === 'paid1@test.dev');
$paidReg('REG-H7B', 'paid2@test.dev', 'HSWF/24/0800');
check('if the number was taken meanwhile the registration is flagged, not half-made', (int) $sqlv("SELECT COUNT(*) FROM accounts WHERE email = 'paid2@test.dev'") === 0 && $sqlv("SELECT status FROM pending_registrations WHERE reference = 'REG-H7B'") === 'failed' && (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Paid registration needs attention'") >= 1);

// ---- level progression
$prog = fn (array $b) => $sa->post('/admin/levels/promote', ['edition' => $ed] + $b);
check('nothing moves until a progression is saved', $prog(['mode' => 'preview'])[0] === 422);
check('a level cannot move to itself or to a level that does not exist', $sa->put('/admin/settings/progression', ['edition' => $ed, 'value' => ['map' => ['ND I' => 'ND I']]])[0] === 422 && $sa->put('/admin/settings/progression', ['edition' => $ed, 'value' => ['map' => ['ND I' => 'Year 9']]])[0] === 422);
check('admin saves the progression', $sa->put('/admin/settings/progression', ['edition' => $ed, 'value' => ['map' => ['ND I' => 'ND II', 'ND II' => 'HND I', 'HND II' => 'Alumni', 'Staff' => '']]])[0] === 200
    && ($sa->get("/admin/settings?edition=$ed")[1]['settings']['progression']['map'] ?? null) == ['ND I' => 'ND II', 'ND II' => 'HND I', 'HND II' => 'Alumni']);
$cnt = fn (string $l) => (int) $sqlv('SELECT COUNT(*) FROM accounts WHERE level = ?', [$l]);
[$ndI, $ndII, $hndI, $hndII, $staff, $alumni] = [$cnt('ND I'), $cnt('ND II'), $cnt('HND I'), $cnt('HND II'), $cnt('Staff'), $cnt('Alumni')];
[$c, $r] = $prog(['mode' => 'preview']);
check('preview counts the students that would move, and changes nothing', $c === 200 && ($r['counts']['ND I'] ?? 0) === $ndI && ($r['counts']['ND II'] ?? 0) === $ndII && ($r['total'] ?? 0) === $ndI + $ndII + $hndII && $cnt('ND I') === $ndI, json_encode($r));
check('applying needs a confirmation', $prog(['mode' => 'apply'])[0] === 422 && $cnt('ND I') === $ndI);
check('only a Super admin can move students', $gate->post('/admin/levels/promote', ['edition' => $ed, 'mode' => 'apply', 'confirm' => true])[0] === 403);
[$c, $r] = $prog(['mode' => 'apply', 'confirm' => true]);
check('students move exactly one level', $c === 200 && ($r['moved'] ?? 0) === $ndI + $ndII + $hndII && $cnt('ND I') === 0 && $cnt('ND II') === $ndI && $cnt('HND I') === $hndI + $ndII && $cnt('HND II') === 0 && $cnt('Alumni') === $alumni + $hndII && $cnt('Staff') === $staff, json_encode([$r, $ndI, $ndII, $hndI, $hndII]));
check('the move is logged and audited', (int) $sqlv('SELECT COUNT(*) FROM level_promotions') === 1 && (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Moved students up a level'") === 1);
[$c, $r] = $prog(['mode' => 'apply', 'confirm' => true]);
check('doing it twice by accident is refused', $c === 409 && $cnt('ND I') === 0 && $cnt('ND II') === $ndI);
check('unless the admin says it is meant', $prog(['mode' => 'apply', 'confirm' => true, 'again' => true])[0] === 200 && $cnt('ND II') === 0);
check('past entries keep the level they were made with', (string) $sqlv('SELECT level FROM nominee_entries WHERE id = ?', [$mine]) === 'ND I');
$db->exec('DELETE FROM level_promotions');

// ================================================================== J. MULTI-CATEGORY NOMINATIONS AND THE ONE-TIME FEE (Round 2, phase 8)
group('J. Several categories per nominee, one registration fee per edition');
check('migration 013 made entries unique per account, edition and category', $sqlv("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'nominee_entries' AND index_name = 'uq_entry_account_edition_cat'") == 3
    && $sqlv("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'nominee_entries' AND index_name = 'uq_entry_account_edition'") == 0);
check('the limit is a setting, 3 until the admin changes it', $sqlv("SELECT max_categories_per_nominee FROM edition_settings WHERE edition_id = 2") == 3);
$db->exec("UPDATE edition_settings SET nominee_registration_fee = 0 WHERE edition_id = 2");
$cats = array_map('intval', $db->query("SELECT id FROM categories WHERE edition_id = 2 AND active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
check('the sample edition has enough categories for these tests', count($cats) >= 4, (string) count($cats));
$db->exec("DELETE FROM category_levels WHERE category_id IN (" . implode(',', $cats) . ")");
$mk = fn (array $over = []) => (new Client('nominee'))->post('/nominee/register', array_merge(['occasionId' => $ed, 'name' => 'Multi Student', 'email' => 'multi1@test.dev', 'phone' => '08031234567', 'department' => 'Software', 'level' => 'HND II',
    'matricNo' => 'HSWF/24/0910', 'categoryIds' => [(string) $cats[0], (string) $cats[1]], 'password' => 'Test-Pass-12345', 'terms' => 'on'], $over));
$entriesOf = fn (string $email) => (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = (SELECT id FROM accounts WHERE email = ?)", [$email]);

// ---- registering for several categories at once
[$c, $r] = $mk();
check('a nominee can register in two categories at once', $c === 200 && count($r['entries'] ?? []) === 2, json_encode($r['error'] ?? $c));
check('each category is its own entry', $sqlv("SELECT COUNT(DISTINCT category_id) FROM nominee_entries WHERE account_id = (SELECT id FROM accounts WHERE email = 'multi1@test.dev')") == 2);
check('the single "categoryId" from older pages still works', $mk(['email' => 'multi2@test.dev', 'matricNo' => 'HSWF/24/0911', 'categoryIds' => null, 'categoryId' => (string) $cats[0]])[0] === 200 && $entriesOf('multi2@test.dev') === 1);
check('a repeated category in one request is counted once', $mk(['email' => 'multi3@test.dev', 'matricNo' => 'HSWF/24/0912', 'categoryIds' => [(string) $cats[0], (string) $cats[0]]])[0] === 200 && $entriesOf('multi3@test.dev') === 1);
check('no category at all is refused', $mk(['email' => 'multi4@test.dev', 'matricNo' => 'HSWF/24/0913', 'categoryIds' => []])[0] === 422);
check('a category from nowhere is refused, and nothing is half-made', $mk(['email' => 'multi4@test.dev', 'matricNo' => 'HSWF/24/0913', 'categoryIds' => [(string) $cats[0], '999999']])[0] === 422 && (int) $sqlv("SELECT COUNT(*) FROM accounts WHERE email = 'multi4@test.dev'") === 0);
[$c, $r] = $mk(['email' => 'multi5@test.dev', 'matricNo' => 'HSWF/24/0914', 'categoryIds' => array_map('strval', array_slice($cats, 0, 4))]);
check('more than the admin allows is refused', $c === 422 && isset($r['fields']['categoryId']) && str_contains($r['error'] ?? '', 'up to 3'), json_encode($r));
$db->exec("INSERT INTO category_levels (category_id, level) VALUES ({$cats[2]}, 'HND I')");
$c = $mk(['email' => 'multi6@test.dev', 'matricNo' => 'HSWF/24/0915', 'categoryIds' => [(string) $cats[0], (string) $cats[2]]])[0];
$db->exec("DELETE FROM category_levels WHERE category_id = {$cats[2]}");
check('an ineligible category in the list refuses the whole request', $c === 422 && (int) $sqlv("SELECT COUNT(*) FROM accounts WHERE email = 'multi6@test.dev'") === 0);

// ---- adding categories later, from the dashboard
$m1 = new Client('nominee');
$m1->login('multi1@test.dev', 'Test-Pass-12345');
$apply = fn (Client $c, array $ids) => $c->post('/nominee/entries', ['occasionId' => $ed, 'categoryIds' => $ids]);
[$c, $r] = $apply($m1, [(string) $cats[1]]);
check('entering a category you already have is refused', $c === 422 && str_contains($r['error'] ?? '', 'already entered'), json_encode($r));
[$c, $r] = $apply($m1, [(string) $cats[2]]);
check('a third category can be added', $c === 200 && count($r['entries'] ?? []) === 3 && !empty($r['entryId']) && count($r['entryIds'] ?? []) === 1, json_encode($r['error'] ?? $c));
[$c, $r] = $apply($m1, [(string) $cats[3]]);
check('but not a fourth: the limit counts what they already have', $c === 422 && str_contains($r['error'] ?? '', '3 of the 3'), json_encode($r));
check('an empty choice is refused', $apply($m1, [])[0] === 422);
$vr = ['pricePerVote' => 50, 'maxVotesPerPayment' => 100, 'maxPaidVotesPerEmailPerCategory' => 5, 'nomineeRegistrationFee' => 0];
check('the limit must be a whole number from 1 to 20', $sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 0])[0] === 422 && $sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 21])[0] === 422);
check('the admin can raise the limit, and the public site is told', $sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 4])[0] === 200 && $sqlv("SELECT max_categories_per_nominee FROM edition_settings WHERE edition_id = 2") == 4
    && (int) (array_column((new Client())->get('/editions')[1]['editions'] ?? [], null, 'id')[$ed]['voting']['maxCategoriesPerNominee'] ?? 0) === 4);
check('saving the other voting rules without the limit keeps it', $sa->put("/admin/editions/$ed/voting", $vr)[0] === 200 && $sqlv("SELECT max_categories_per_nominee FROM edition_settings WHERE edition_id = 2") == 4);
check('and then the fourth goes in', $apply($m1, [(string) $cats[3]])[0] === 200 && $entriesOf('multi1@test.dev') === 4);
$sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 1]);
check('a limit of 1 gives the old one-category rule', $mk(['email' => 'multi7@test.dev', 'matricNo' => 'HSWF/24/0916'])[0] === 422 && $mk(['email' => 'multi7@test.dev', 'matricNo' => 'HSWF/24/0916', 'categoryIds' => [(string) $cats[0]]])[0] === 200);
$sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 3]);
$m7 = new Client('nominee'); $m7->login('multi7@test.dev', 'Test-Pass-12345');
check('with a limit of 3 again, that nominee can add two more at once', $apply($m7, [(string) $cats[1], (string) $cats[2]])[0] === 200 && $entriesOf('multi7@test.dev') === 3);
$sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 2]);
$m8 = new Client('nominee');
$m8->post('/nominee/register', ['occasionId' => $ed, 'name' => 'Race Student', 'email' => 'multi8@test.dev', 'phone' => '08031234567', 'department' => 'Software', 'level' => 'HND II', 'matricNo' => 'HSWF/24/0917', 'categoryIds' => [(string) $cats[0]], 'password' => 'Test-Pass-12345', 'terms' => 'on']);
$one = $apply($m8, [(string) $cats[1]])[0]; $two = $apply($m8, [(string) $cats[2]])[0];
check('the limit holds for repeated requests', $one === 200 && $two === 422 && $entriesOf('multi8@test.dev') === 2);
$sa->put("/admin/editions/$ed/voting", $vr + ['maxCategoriesPerNominee' => 4]);

// ---- one profile, one photo, several categories
$aid = (int) $sqlv("SELECT id FROM accounts WHERE email = 'multi1@test.dev'");
$entryIds = array_map('intval', $db->query("SELECT id FROM nominee_entries WHERE account_id = $aid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
check('editing the profile in one category updates them all', $m1->patch("/nominee/entries/{$entryIds[0]}", ['name' => 'Multi Renamed', 'phone' => '08039999999', 'department' => 'Law', 'bio' => 'One bio for all'])[0] === 200
    && (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = $aid AND name = 'Multi Renamed' AND department = 'Law' AND bio = 'One bio for all'") === count($entryIds));
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id = {$entryIds[0]}");
check('approval stays separate for each category', (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = $aid AND status = 'approved'") === 1);
$img = imagecreatetruecolor(400, 400); imagefill($img, 0, 0, imagecolorallocate($img, 200, 60, 60));
$tmpImg = sys_get_temp_dir() . '/multi-photo.png'; imagepng($img, $tmpImg);
$upload = fn (int $id) => $m1->call('POST', "/nominee/entries/$id/photo", ['photo' => new CURLFile($tmpImg, 'image/png', 'p.png')], [], true);
[$c, $r] = $upload($entryIds[1]);
$photoPath = (string) $sqlv("SELECT photo_path FROM nominee_entries WHERE id = {$entryIds[0]}");
$photoFile = dirname(App\Photos::root()) . '/' . $photoPath;
check('a photo added in one category shows in all of them', $c === 200 && $photoPath !== '' && (int) $sqlv("SELECT COUNT(DISTINCT photo_path) FROM nominee_entries WHERE account_id = $aid") === 1 && (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = $aid AND photo_path IS NOT NULL") === count($entryIds), $c . ' ' . json_encode($r));
check('the photo file is on disk', is_file($photoFile), $photoFile);
App\Photos::delete($photoPath);
clearstatcache();
check('a photo still used by an entry is never deleted from disk', is_file($photoFile));
[$c, $r] = $m1->delete("/nominee/entries/{$entryIds[0]}/photo");
clearstatcache();
check('removing it from one category removes it from all, and deletes the file', $c === 200 && (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = $aid AND photo_path IS NOT NULL") === 0 && !is_file($photoFile), $c . ' ' . $photoFile);
$upload($entryIds[0]);
$p2 = (string) $sqlv("SELECT photo_path FROM nominee_entries WHERE id = {$entryIds[0]}");
check('a new photo replaces the old one in every category', $p2 !== '' && $p2 !== $photoPath && (int) $sqlv("SELECT COUNT(DISTINCT photo_path) FROM nominee_entries WHERE account_id = $aid") === 1);

// ---- the registration fee is paid once per edition
$db->exec("UPDATE edition_settings SET nominee_registration_fee = 500 WHERE edition_id = 2");
[$c, $r] = (new Client('nominee'))->post('/nominee/register', ['occasionId' => $ed, 'name' => 'Paying Student', 'email' => 'fee1@test.dev', 'phone' => '08031234567', 'department' => 'Software', 'level' => 'HND II', 'matricNo' => 'HSWF/24/0920', 'categoryIds' => [(string) $cats[0], (string) $cats[1]], 'password' => 'Test-Pass-12345', 'terms' => 'on']);
check('with a fee, two categories start ONE payment (or fail cleanly if the gateway is unreachable)', (($r['paymentRequired'] ?? false) === true && (float) ($r['amount'] ?? 0) === 500.0) || $c === 502, json_encode($r));
check('no account or entry exists before the money is confirmed', (int) $sqlv("SELECT COUNT(*) FROM accounts WHERE email = 'fee1@test.dev'") === 0 && (int) $sqlv("SELECT COUNT(*) FROM payments WHERE payer_email = 'fee1@test.dev'") === 1);
check('the pending registration remembers both categories', count(json_decode((string) $sqlv("SELECT profile FROM pending_registrations WHERE email = 'fee1@test.dev'"), true)['categoryIds'] ?? []) === 2);
$paid = function (string $ref, string $email, string $matricNo, array $profileCats) use ($db, $pwHash) {
    $db->prepare("INSERT INTO payments (edition_id, purpose, reference, expected_amount, currency, payer_email, status) VALUES (2, 'nominee_fee', ?, 500, 'NGN', ?, 'successful')")->execute([$ref, $email]);
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO pending_registrations (reference, edition_id, account_id, email, password_hash, profile, payment_id, status) VALUES (?, 2, NULL, ?, ?, ?, ?, 'pending')")
        ->execute([$ref, $email, $pwHash, json_encode(['name' => 'Paid Multi', 'phone' => '0803', 'department' => 'Law', 'level' => 'ND II', 'matricNo' => $matricNo] + $profileCats), $pid]);
    App\Registrations::complete($db, ['id' => $pid]);
    return $pid;
};
$pid = $paid('REG-J1', 'fee2@test.dev', 'HSWF/24/0921', ['categoryIds' => [$cats[0], $cats[1]]]);
check('one payment creates an entry for every category chosen', (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE registration_payment_id = ?", [$pid]) === 2);
$paid('REG-J2', 'fee3@test.dev', 'HSWF/24/0922', ['categoryIds' => []]);
check('a paid registration with no category is flagged for an admin, not half-made', $sqlv("SELECT status FROM pending_registrations WHERE reference = 'REG-J2'") === 'failed' && (int) $sqlv("SELECT COUNT(*) FROM accounts WHERE email = 'fee3@test.dev'") === 0);
$pid3 = $paid('REG-J3', 'fee4@test.dev', 'HSWF/24/0923', ['categoryId' => $cats[0]]);
check('an older pending registration with a single "categoryId" still completes', (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE registration_payment_id = ?", [$pid3]) === 1);
$fee2 = new Client('nominee'); $fee2->login('fee2@test.dev', 'Test-Pass-12345');
[$c, $r] = $apply($fee2, [(string) $cats[2]]);
check('a third category for someone who already paid is free: no second payment', $c === 200 && empty($r['paymentRequired']) && $entriesOf('fee2@test.dev') === 3 && (int) $sqlv("SELECT COUNT(*) FROM payments WHERE payer_email = 'fee2@test.dev'") === 1, json_encode($r['error'] ?? $c));
$fee2id = (int) $sqlv("SELECT id FROM accounts WHERE email = 'fee2@test.dev'");
$db->exec("DELETE FROM nominee_entries WHERE account_id = $fee2id");
check('even if every entry is removed, they do not pay again for that edition', App\Entries::feeSettled($db, $fee2id, 2) === true);
check('someone who never registered in an edition still owes its fee', App\Entries::feeSettled($db, $fee2id, 1) === false);
$db->exec("UPDATE edition_settings SET nominee_registration_fee = 0 WHERE edition_id = 2");

// ---- admin side
$entryA = (int) $sqlv("SELECT id FROM nominee_entries WHERE account_id = $aid ORDER BY id LIMIT 1");
$entryB = (int) $sqlv("SELECT id FROM nominee_entries WHERE account_id = $aid ORDER BY id DESC LIMIT 1");
$catA = $sqlv("SELECT category_id FROM nominee_entries WHERE id = $entryA");
[$c, $r] = $sa->patch("/admin/nominees/$entryB", ['edition' => $ed, 'name' => 'Multi Renamed', 'department' => 'Law', 'categoryId' => (string) $catA, 'status' => 'pending', 'bio' => '']);
check('an admin cannot move an entry into a category the nominee already has (clean message, no 500)', $c === 422 && str_contains($r['error'] ?? '', 'already has an entry'), $c . ' ' . json_encode($r));
$list = $sa->get("/admin/nominees?edition=$ed&page=1&q=" . rawurlencode('Multi Renamed'))[1];
check('the admin list shows every category as its own row', ($list['total'] ?? 0) === count($entryIds), json_encode($list['total'] ?? null));

// ================================================================== K. LEAK AUDIT AND SUSPENSE RESULTS (Round 2, phase 9)
group('K. Suspense results: no counts, rankings or early winners leak');
$walk = function ($v, string $path = '') use (&$walk): array {
    $keys = [];
    if (is_array($v)) {
        foreach ($v as $k => $x) { if (is_string($k)) { $keys[] = $k; } $keys = array_merge($keys, $walk($x)); }
    }
    return $keys;
};
$banned = ['votes', 'vote', 'voteCount', 'votesCount', 'tally', 'rank', 'ranking', 'position', 'total', 'totals', 'percent', 'percentage', 'revenue', 'runnerUp', 'runnersUp', 'showVoteCounts', 'passwordHash', 'password_hash', 'accountId', 'matricNo', 'matric_no'];
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'voting', results_publish_at = NOW() - INTERVAL 1 DAY WHERE id = 2");
$db->exec("DELETE FROM winners WHERE edition_id = 2");
$winCat = (int) $sqlv("SELECT category_id FROM nominee_entries WHERE id = ?", [$entry['na']]);
$db->prepare("INSERT INTO winners (edition_id, category_id, nominee_entry_id) VALUES (2, ?, ?)")->execute([$winCat, $entry['na']]);
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id IN ({$entry['na']}, {$entry['nb']})");
App\Cache::flush();
$pub = new Client();
$winnersOf = fn () => array_values(array_filter($pub->get('/winners')[1] ?? [], fn ($x) => ($x['occasionId'] ?? '') === $ed));   // oga-9 is completed: its winners are public by design
foreach (['/editions', '/categories', '/nominees', '/ticket-types', '/winners', '/committee', '/settings', '/about', '/content', '/faqs', '/gallery', '/legal'] as $p) {
    [$c, $body, $rawBody] = $pub->get($p);
    $hits = array_values(array_intersect($walk($body), $banned));
    check("public $p exposes no counts, rankings or private fields", $c === 200 && $hits === [], $p . ' ' . implode(',', $hits));
}
[, $nomList, $rawNom] = $pub->get('/nominees');
check('public nominees carry no email, phone or matric number', !preg_match('/@test\.dev|HSWF\/\d\d|"phone"|"email"/', $rawNom));
check('the public edition list no longer carries a vote-count switch', !str_contains($pub->get('/editions')[2], 'showVoteCounts'));
check('winners stay hidden while the edition is forced to an earlier state, even if the publish time has passed', $winnersOf() === []);
$db->exec("UPDATE editions SET force_state = 'voting-closed' WHERE id = 2"); App\Cache::flush();
check('also hidden while voting is closed and results are not yet out', $winnersOf() === []);
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, results_publish_at = NOW() + INTERVAL 1 DAY, voting_close_at = NOW() - INTERVAL 1 HOUR WHERE id = 2"); App\Cache::flush();
check('hidden in automatic mode until the publish time', $winnersOf() === []);
$db->exec("UPDATE editions SET results_publish_at = NOW() - INTERVAL 1 MINUTE WHERE id = 2"); App\Cache::flush();
$w = $winnersOf();
check('winners appear once the publish time passes', count($w) === 1 && (string) $w[0]['nomineeId'] === (string) $entry['na']);
check('and only the winner: no counts, no ranking, no runner-up', array_keys($w[0] ?? []) === ['occasionId', 'categoryId', 'nomineeId'] && count($w) === 1);
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'voting' WHERE id = 2"); App\Cache::flush();
check('an admin can pull results back by forcing an earlier state', $winnersOf() === []);
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'results' WHERE id = 2"); App\Cache::flush();
check('or push them out by forcing the results state', count($winnersOf()) === 1);

// ---- what a nominee can see about themselves
$ns = new Client('nominee'); $ns->login('na@test.dev', 'Test-Pass-12345');
[$c, $me, $rawMe] = $ns->get('/nominee/me');
$voteKeys = array_diff($banned, ['matricNo', 'accountId', 'matric_no']);   // their own matric number and account id are theirs to see
check('a nominee never sees counts or rankings, even for themselves', $c === 200 && array_intersect($walk($me), $voteKeys) === [], implode(',', array_intersect($walk($me), $voteKeys)));
// ---- order references and lookups
check('an unknown vote order reference reveals nothing', ($r = (new Client())->post('/votes/orders/VOT-NOPE00000000/confirm', []))[0] === 404 && !str_contains(json_encode($r[1]), 'votes"'));
[$c, $r] = (new Client())->post('/tickets/lookup', ['code' => 'TKT-T1', 'email' => 'someone-else@x.test']);
check('ticket lookup with the wrong email finds nothing', $c !== 200 || empty($r['tickets'] ?? []), $c . ' ' . json_encode($r));
// ---- staff without a results permission
foreach (['/admin/results', '/admin/votes', '/admin/dashboard'] as $p) {
    $c = $gate->get("$p?edition=$ed")[0];
    check("gate staff cannot read $p", in_array($c, [403, 404], true), (string) $c);
}
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL WHERE id = 2");
App\Cache::flush();

// ------------------------------------------------------------------ result
echo "\n" . str_repeat('=', 50) . "\nPassed: {$GLOBALS['pass']}   Failed: {$GLOBALS['fail']}\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);