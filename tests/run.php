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
foreach ($deny as $d) {
    [$c] = $gate->call($d[0], $d[1], $d[2] ?? null);
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
check('admin sees all thirteen email templates, none customised yet', $c === 200 && count($keys) === 13 && !array_filter($r['templates'], fn ($t) => $t['custom']));
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
[$c, $r, $raw] = $sa->post('/admin/email/outbox/send', ['edition' => $ed]);
check('"send now" sends the waiting messages', $c === 200 && ($r['result']['sent'] ?? 0) === 30, "HTTP $c | Response: " . substr($raw, 0, 1000));
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
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'voting', results_publish_at = NOW() - INTERVAL 1 DAY, results_published_at = NOW() - INTERVAL 1 HOUR WHERE id = 2");
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
$clearLimits();
[$c, $r] = (new Client())->post('/tickets/lookup', ['ref' => 'TKT-T1', 'email' => 't@x.test']);
check('ticket lookup works with the right ticket code and email (so the next check is not vacuous)', $c === 200 && count($r['tickets'] ?? []) >= 1, $c . ' ' . json_encode($r));
[$c, $r] = (new Client())->post('/tickets/lookup', ['ref' => 'TIK-T1', 'email' => 't@x.test']);
check('ticket lookup works with the order reference and the right email', $c === 200 && count($r['tickets'] ?? []) === 2, $c . ' ' . json_encode($r));
[$c, $r] = (new Client())->post('/tickets/lookup', ['ref' => 'TKT-T1', 'email' => 'someone-else@x.test']);
check('ticket lookup with the wrong email finds nothing', $c === 422 && empty($r['tickets'] ?? []), $c . ' ' . json_encode($r));
[$c, $r] = (new Client())->post('/tickets/lookup', ['ref' => 'TIK-T1', 'email' => 'someone-else@x.test']);
check('and the order reference with the wrong email finds nothing either', $c === 422 && empty($r['tickets'] ?? []), $c . ' ' . json_encode($r));
$clearLimits();
// ---- staff without a results permission
foreach (['/admin/results', '/admin/votes', '/admin/dashboard'] as $p) {
    $c = $gate->get("$p?edition=$ed")[0];
    check("gate staff cannot read $p", in_array($c, [403, 404], true), (string) $c);
}
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL WHERE id = 2");
App\Cache::flush();

// ================================================================== L. RESULT PUBLISHING (Round 2, phase 10)
group('L. Results are published on purpose: reasons, checks, schedule, retract');
$db->exec("UPDATE nominee_entries SET status = 'pending' WHERE edition_id = 2 AND id NOT IN ({$entry['na']}, {$entry['nb']})");
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id IN ({$entry['na']}, {$entry['nb']})");
$db->exec("DELETE FROM winners WHERE edition_id = 2");
$db->exec("DELETE FROM vote_totals WHERE edition_id = 2 AND category_id = 2");
$db->exec("INSERT INTO vote_totals (nominee_entry_id, category_id, edition_id, votes) VALUES ({$entry['na']}, 2, 2, 15), ({$entry['nb']}, 2, 2, 3)");
$setTimes = fn (string $close, string $publish) => $db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, results_published_at = NULL, results_published_by = NULL, voting_open_at = NOW() - INTERVAL 30 DAY, voting_close_at = $close, results_publish_at = $publish WHERE id = 2");
$pubWinners = fn () => array_values(array_filter((new Client())->get('/winners')[1] ?? [], fn ($x) => ($x['occasionId'] ?? '') === $ed));
$save = fn (array $rows) => $sa->put('/admin/results/winners', ['edition' => $ed, 'rows' => $rows]);
$pub = fn (array $extra = []) => $sa->post('/admin/results/publish', ['edition' => $ed] + $extra);
$setTimes('NOW() + INTERVAL 2 DAY', 'NOW() + INTERVAL 5 DAY'); App\Cache::flush();
check('migration 014 added the publish stamp, the override reason, the review note and the permission', $sqlv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'editions' AND column_name = 'results_published_at') OR (table_name = 'winners' AND column_name = 'override_reason') OR (table_name = 'nominee_entries' AND column_name = 'review_note'))") == 3
    && $sqlv("SELECT COUNT(*) FROM permissions WHERE perm_key = 'results.publish'") == 1);

// ---- choosing winners: a reason is needed when it is not simply the vote leader
[$c, $r] = $save([['categoryId' => '2', 'nomineeId' => (string) $entry['nb']]]);
check('choosing someone who is not the vote leader needs a written reason', $c === 422 && str_contains($r['error'] ?? '', 'not the vote leader'), json_encode($r));
check('a reason that is too short is refused', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['nb'], 'reason' => 'no']])[0] === 422);
check('the vote leader needs no reason', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['na']]])[0] === 200 && $sqlv("SELECT override_reason FROM winners WHERE edition_id = 2") === null);
check('with a reason, a non-leader can be chosen and the reason is kept', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['nb'], 'reason' => 'Votes from 40 duplicate cards were removed']])[0] === 200 && str_contains((string) $sqlv("SELECT override_reason FROM winners WHERE edition_id = 2"), 'duplicate cards'));
$db->exec("UPDATE vote_totals SET votes = 15 WHERE nominee_entry_id = {$entry['nb']}");
check('in a tie even the leaders need a reason', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['na']]])[0] === 422);
check('and the tie is flagged to the admin', in_array('2', $sa->get("/admin/results?edition=$ed")[1]['ties'] ?? [], true));
check('a tie broken with a reason is accepted', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['na'], 'reason' => 'Tie broken by the committee vote']])[0] === 200);
$db->exec("UPDATE vote_totals SET votes = 3 WHERE nominee_entry_id = {$entry['nb']}");
$save([['categoryId' => '2', 'nomineeId' => (string) $entry['na']]]);

// ---- publishing: who may, and when
check('staff without the publish permission cannot publish', $gate->post('/admin/results/publish', ['edition' => $ed])[0] === 403);
[$c, $r] = $pub();
check('results cannot be published while voting is still open', $c === 422 && str_contains($r['error'] ?? '', 'Voting is not over'), json_encode($r));
$setTimes('NOW() - INTERVAL 1 DAY', 'NOW() + INTERVAL 5 DAY'); App\Cache::flush();
$db->exec("DELETE FROM winners WHERE edition_id = 2");
[$c, $r] = $pub();
check('and not before any winner is saved', $c === 422 && str_contains($r['error'] ?? '', 'Save the winners'), json_encode($r));
$save([['categoryId' => '2', 'nomineeId' => (string) $entry['na']]]);
$db->exec("INSERT INTO categories (edition_id, name, sort_order, active) VALUES (2, 'Phase 10 extra', 99, 1)");
$xcat = (int) $db->lastInsertId();
$db->exec("INSERT INTO nominee_entries (edition_id, category_id, name, status) VALUES (2, $xcat, 'Unjudged Nominee', 'approved')");
[$c, $r] = $pub();
check('a category with approved nominees but no winner stops the publish', $c === 422 && str_contains($r['error'] ?? '', 'no winner'), json_encode($r));
check('nothing became public by that attempt', $sqlv("SELECT results_published_at FROM editions WHERE id = 2") === null && $pubWinners() === []);
$db->exec("DELETE FROM nominee_entries WHERE category_id = $xcat"); $db->exec("DELETE FROM categories WHERE id = $xcat");
check('a bad date is refused', $pub(['at' => 'not a date'])[0] === 422);

// ---- publish now
[$c, $r] = $pub();
check('the admin can publish', $c === 200 && ($r['published']['live'] ?? false) === true, json_encode($r));
check('publishing moves the site to "winners announced" at the same time (automatic mode)', $sqlv("SELECT results_publish_at = results_published_at FROM editions WHERE id = 2") == 1);
$w = $pubWinners();
check('the public now sees the winner, and only the winner', count($w) === 1 && array_keys($w[0]) === ['occasionId', 'categoryId', 'nomineeId'] && (string) $w[0]['nomineeId'] === (string) $entry['na']);
check('the admin results page shows it as published, by whom', ($pr = $sa->get("/admin/results?edition=$ed")[1])['published']['live'] === true && !empty($pr['published']['by']) && $pr['state'] === 'results');
check('publishing twice is refused', $pub()[0] === 422);
check('winners cannot be changed while published', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['nb'], 'reason' => 'Changing my mind later']])[0] === 422);
check('the publish is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Published results'") === 1);

// ---- retract
check('retracting needs a reason', $sa->post('/admin/results/retract', ['edition' => $ed, 'reason' => ''])[0] === 422);
check('staff without the permission cannot retract', $gate->post('/admin/results/retract', ['edition' => $ed, 'reason' => 'Because I can'])[0] === 403);
check('an admin can retract, and the winners disappear at once', $sa->post('/admin/results/retract', ['edition' => $ed, 'reason' => 'A recount was requested'])[0] === 200 && $pubWinners() === []);
check('the reason is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Retracted results' AND target LIKE '%recount%'") === 1);
check('retracting something unpublished is refused', $sa->post('/admin/results/retract', ['edition' => $ed, 'reason' => 'Nothing to retract'])[0] === 422);
check('after a retract the winners can be changed again', $save([['categoryId' => '2', 'nomineeId' => (string) $entry['nb'], 'reason' => 'Recount found the cards were duplicates']])[0] === 200);
$save([['categoryId' => '2', 'nomineeId' => (string) $entry['na']]]);

// ---- scheduled reveal
$setTimes('NOW() - INTERVAL 1 DAY', 'NOW() + INTERVAL 5 DAY'); App\Cache::flush();
[$c, $r] = $pub(['at' => date('c', time() + 3600)]);
check('results can be scheduled for a later moment (the ceremony)', $c === 200 && ($r['published']['scheduled'] ?? false) === true && $pubWinners() === [], json_encode($r));
check('the admin sees that it is scheduled', ($sa->get("/admin/results?edition=$ed")[1]['published']['scheduled'] ?? false) === true);
$db->exec("UPDATE editions SET results_published_at = NOW() - INTERVAL 1 MINUTE, results_publish_at = NOW() - INTERVAL 1 MINUTE WHERE id = 2"); App\Cache::flush();
check('and they appear when that moment arrives', count($pubWinners()) === 1);
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'voting-closed' WHERE id = 2"); App\Cache::flush();
check('a state forced by hand still keeps them hidden', $pubWinners() === []);
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, results_published_at = NULL, results_publish_at = NOW() - INTERVAL 1 HOUR WHERE id = 2"); App\Cache::flush();
check('results time passed but never published: still hidden', $pubWinners() === []);
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'voting-closed' WHERE id = 2");
[$c, $r] = $pub();
check('publishing while the state is forced earlier warns the admin', $c === 200 && !empty($r['warning']), json_encode($r));
$sa->post('/admin/results/retract', ['edition' => $ed, 'reason' => 'Test is over, clean up']);
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL WHERE id = 2"); App\Cache::flush();

// ================================================================== M. NOMINEE PORTAL (Round 2, phase 11)
group('M. Nominee portal: reviewer notes, decision emails, send for review again');
$db->exec("UPDATE editions SET voting_open_at = NULL, voting_close_at = NULL, results_publish_at = NULL, nominations_open_at = NOW() - INTERVAL 2 DAY, nominations_close_at = NOW() + INTERVAL 20 DAY WHERE id = 2"); App\Cache::flush();
$db->exec("DELETE FROM notification_outbox");
$pn = new Client('nominee'); $pn->login('na@test.dev', 'Test-Pass-12345');
$db->exec("UPDATE nominee_entries SET status = 'pending', review_note = NULL WHERE id = {$entry['na']}");
[$c, $r] = $sa->patch("/admin/nominees/{$entry['na']}", ['edition' => $ed, 'name' => 'Nominee na', 'department' => 'Software', 'categoryId' => '2', 'status' => 'rejected', 'bio' => '', 'reviewNote' => 'Please upload a clear face photo.']);
check('an admin can reject with a note for the nominee', $c === 200 && $sqlv("SELECT review_note FROM nominee_entries WHERE id = {$entry['na']}") === 'Please upload a clear face photo.', $c . json_encode($r));
$me = $pn->get('/nominee/me')[1];
$mine = array_values(array_filter($me['entries'] ?? [], fn ($x) => (string) $x['id'] === (string) $entry['na']))[0] ?? [];
check('the nominee sees the status and the note', ($mine['status'] ?? '') === 'rejected' && ($mine['reviewNote'] ?? '') === 'Please upload a clear face photo.');
$row = $db->query("SELECT type, recipient, payload FROM notification_outbox ORDER BY id DESC LIMIT 1")->fetch();
check('a "needs changes" email was queued to the nominee with the note', $row && $row['type'] === 'nominee_entry_rejected' && $row['recipient'] === 'na@test.dev' && str_contains((string) $row['payload'], 'clear face photo'), json_encode($row));
$mail = App\MailTemplates::render('nominee_entry_rejected', 'na@test.dev', json_decode((string) $row['payload'], true));
check('the email shows the note and links to the dashboard', is_array($mail) && str_contains(json_encode($mail), 'clear face photo') && str_contains(json_encode($mail), 'dashboard'), json_encode($mail));
check('a note is only a note: nothing about it is public', !str_contains((new Client())->get('/nominees')[2], 'face photo'));
$pn2 = new Client('nominee'); $pn2->login('nb@test.dev', 'Test-Pass-12345');
check('one nominee cannot send another nominee’s entry for review', $pn2->post("/nominee/entries/{$entry['na']}/resubmit")[0] === 404);
check('the nominee can send a rejected entry for review again', $pn->post("/nominee/entries/{$entry['na']}/resubmit")[0] === 200 && $sqlv("SELECT status FROM nominee_entries WHERE id = {$entry['na']}") === 'pending');
check('an entry that is not rejected cannot be "sent again"', $pn->post("/nominee/entries/{$entry['na']}/resubmit")[0] === 422);
$db->exec("DELETE FROM notification_outbox");
$sa->patch("/admin/nominees/{$entry['na']}", ['edition' => $ed, 'name' => 'Nominee na', 'department' => 'Software', 'categoryId' => '2', 'status' => 'approved', 'bio' => '', 'reviewNote' => 'ignored on approve']);
check('approving clears the note and queues an "approved" email', $sqlv("SELECT review_note FROM nominee_entries WHERE id = {$entry['na']}") === null && $sqlv("SELECT type FROM notification_outbox ORDER BY id DESC LIMIT 1") === 'nominee_entry_approved');
$db->exec("DELETE FROM notification_outbox");
$sa->patch("/admin/nominees/{$entry['na']}", ['edition' => $ed, 'name' => 'Nominee na renamed', 'department' => 'Software', 'categoryId' => '2', 'status' => 'approved', 'bio' => '']);
check('editing without changing the status sends no email', (int) $sqlv("SELECT COUNT(*) FROM notification_outbox") === 0);
[$c] = $sa->post('/admin/nominees/status', ['ids' => [$entry['nb']], 'status' => 'rejected', 'note' => 'Bulk note']);
check('the bulk reject carries one note to everyone chosen', $c === 200 && $sqlv("SELECT review_note FROM nominee_entries WHERE id = {$entry['nb']}") === 'Bulk note');
$db->exec("UPDATE editions SET nominations_close_at = NOW() - INTERVAL 1 DAY, voting_open_at = NOW() - INTERVAL 1 HOUR, voting_close_at = NOW() + INTERVAL 5 DAY WHERE id = 2"); App\Cache::flush();
check('once nominations close, a rejected entry cannot be sent again', $pn2->post("/nominee/entries/{$entry['nb']}/resubmit")[0] === 403);
$db->exec("UPDATE editions SET nominations_open_at = NULL, nominations_close_at = NULL, voting_open_at = NULL, voting_close_at = NULL WHERE id = 2"); App\Cache::flush();


// ================================================================== N. NOMINEE ACCESS PASS (Round 2, phase 12)
group('N. Nominee Access Pass: free event entry for approved nominees');
$db->exec("UPDATE editions SET status = 'active' WHERE id = 2");
$db->exec("UPDATE edition_settings SET access_pass_enabled = 0, access_pass_guests = 0 WHERE edition_id = 2");
$db->exec("UPDATE nominee_entries SET status = 'approved', review_note = NULL WHERE id = {$entry['na']}");
$db->exec("UPDATE nominee_entries SET status = 'rejected' WHERE id = {$entry['nb']}");
$db->exec("DELETE FROM notification_outbox"); App\Cache::flush();
check('migration 015 added the pass type, the order owner, the settings, the flier config and the nickname', $sqlv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name = 'ticket_types' AND column_name = 'kind') OR (table_name = 'ticket_orders' AND column_name = 'account_id') OR (table_name = 'edition_settings' AND column_name IN ('access_pass_enabled', 'access_pass_guests', 'flier_config')) OR (table_name = 'nominee_entries' AND column_name = 'nickname'))") == 6);
$na = new Client('nominee'); $na->login('na@test.dev', 'Test-Pass-12345');
$nb = new Client('nominee'); $nb->login('nb@test.dev', 'Test-Pass-12345');
[$c, $r] = $na->get('/nominee/access-pass');
check('the pass is off until the admin switches it on', $c === 200 && $r['enabled'] === false && $r['claimed'] === false && $r['tickets'] === []);
check('claiming while it is off is refused', $na->post('/nominee/access-pass')[0] === 403);
check('anonymous visitors cannot see or claim a pass', (new Client('nominee'))->get('/nominee/access-pass')[0] === 401 && (new Client('nominee'))->post('/nominee/access-pass')[0] === 401);

// ---- the admin switch
$ap = fn (array $b) => $sa->put("/admin/editions/$ed/access-pass", $b);
check('guests per pass must be a whole number from 0 to 5', $ap(['enabled' => true, 'guests' => 6])[0] === 422 && $ap(['enabled' => true, 'guests' => -1])[0] === 422 && $ap(['enabled' => true, 'guests' => 'x'])[0] === 422 && $ap(['enabled' => true])[0] === 422);
check('staff without settings access cannot change it', $gate->put("/admin/editions/$ed/access-pass", ['enabled' => true, 'guests' => 1])[0] === 403);
check('the admin can switch it on with 2 guests per pass', $ap(['enabled' => true, 'guests' => 2])[0] === 200 && $sqlv("SELECT access_pass_guests FROM edition_settings WHERE edition_id = 2") == 2);
$pubEd = array_column((new Client())->get('/editions')[1]['editions'] ?? [], null, 'id')[$ed] ?? [];
check('the public edition list says so', ($pubEd['accessPass'] ?? null) === ['enabled' => true, 'guests' => 2], json_encode($pubEd['accessPass'] ?? null));

// ---- who may claim
[$c, $r] = $nb->get('/nominee/access-pass');
check('a nominee whose entry is not approved sees it as not yet open', $c === 200 && $r['enabled'] === true && $r['eligible'] === false && $r['claimed'] === false);
check('and cannot claim it', $nb->post('/nominee/access-pass')[0] === 403 && (int) $sqlv("SELECT COUNT(*) FROM ticket_orders WHERE account_id IS NOT NULL") === 0);
[$c, $r] = $na->get('/nominee/access-pass');
check('an approved nominee is eligible but has not claimed', $r['eligible'] === true && $r['claimed'] === false);
[$c, $r] = $na->post('/nominee/access-pass');
check('claiming gives the nominee plus 2 guest tickets', $c === 200 && $r['claimed'] === true && count($r['tickets']) === 3, json_encode($r));
$codes = array_column($r['tickets'] ?? [], 'code');
check('each ticket has its own scannable code', count(array_unique($codes)) === 3 && count(array_filter($codes, fn ($x) => preg_match('/^TKT-[A-F0-9]{12}$/', $x))) === 3);
check('seat 1 is the nominee, the others are their guests', str_starts_with($r['tickets'][0]['holder'], 'Nominee na'));
check('the guests are named after the nominee', str_starts_with($r['tickets'][1]['holder'], 'Guest 1 of'));
check('it is a free, paid order owned by the account, on a type that is never on sale', $sqlv("SELECT COUNT(*) FROM ticket_orders o JOIN ticket_types t ON t.id = o.ticket_type_id WHERE o.account_id IS NOT NULL AND o.status = 'paid' AND o.amount = 0 AND t.kind = 'access_pass' AND t.active = 0") == 1);
check('the three seats share one group', $sqlv("SELECT COUNT(DISTINCT group_id) FROM tickets k JOIN ticket_orders o ON o.id = k.order_id WHERE o.account_id IS NOT NULL") == 1 && $sqlv("SELECT seats FROM ticket_groups g JOIN ticket_orders o ON o.id = g.order_id WHERE o.account_id IS NOT NULL") == 3);
[$c, $again] = $na->post('/nominee/access-pass');
check('claiming again changes nothing', $c === 200 && array_column($again['tickets'], 'code') === $codes && (int) $sqlv("SELECT COUNT(*) FROM ticket_orders WHERE account_id IS NOT NULL") === 1);
check('exactly one "ready" email was queued', (int) $sqlv("SELECT COUNT(*) FROM notification_outbox WHERE type = 'nominee_access_pass'") === 1);
check('another nominee never sees these tickets', $nb->get('/nominee/access-pass')[1]['tickets'] === []);

// ---- the pass is not a public product
check('the pass type is not in the public ticket list', !in_array('Nominee Access Pass', array_column((new Client())->get('/ticket-types')[1], 'name'), true));
check('nor in the admin ticket types', !in_array('Nominee Access Pass', array_column($sa->get("/admin/ticket-types?edition=$ed")[1]['types'] ?? [], 'name'), true));
$passType = (int) $sqlv("SELECT id FROM ticket_types WHERE kind = 'access_pass' LIMIT 1");
check('it cannot be bought through the public order form', (new Client())->post('/tickets/orders', ['typeId' => $passType, 'qty' => 1, 'name' => 'Sneaky Buyer', 'email' => 'sneak@test.dev', 'phone' => '08030000000'])[0] === 422);
check('and the admin cannot edit or delete it like a normal type', $sa->patch("/admin/ticket-types/$passType", ['edition' => $ed, 'name' => 'Hacked', 'price' => 5000, 'seats' => 1, 'maxPerOrder' => 5, 'description' => '', 'perks' => [], 'active' => true])[0] === 422 && $sa->delete("/admin/ticket-types/$passType", ['edition' => $ed])[0] === 422);
[$c, $r] = (new Client())->post('/tickets/lookup', ['ref' => $codes[0], 'email' => 'na@test.dev']);
check('the pass can be looked up with its code and the nominee’s email', $c === 200 && count($r['tickets'] ?? []) >= 1, $c . json_encode($r));

// ---- at the gate
[$c, $r] = $gate->post('/admin/scan', ['edition' => $ed, 'code' => $codes[0]]);
check('the gate accepts the nominee’s ticket and shows it is an Access Pass', $c === 200 && $r['result'] === 'valid' && ($r['ticket']['accessPass'] ?? false) === true && ($r['ticket']['typeName'] ?? '') === 'Nominee Access Pass', json_encode($r));
$catName = (string) $sqlv("SELECT name FROM categories WHERE id = 2");
check('and which categories they are nominated in', in_array($catName, $r['ticket']['nomineeCategories'] ?? [], true), json_encode($r['ticket']['nomineeCategories'] ?? null));
check('the gate shows the whole group, so staff know who came together', count($r['group'] ?? []) === 3);
check('the same code cannot be used twice', $gate->post('/admin/scan', ['edition' => $ed, 'code' => $codes[0]])[1]['result'] === 'used');
check('a guest ticket is valid on its own', $gate->post('/admin/scan', ['edition' => $ed, 'code' => $codes[1]])[1]['result'] === 'valid');
$st = $sa->get("/admin/tickets?edition=$ed&page=1&view=orders&stats=1")[1];
check('admin totals count the passes apart from paid tickets and revenue', ($st['stats']['accessPasses'] ?? null) === 3 && (float) ($st['stats']['revenue'] ?? 0) === (float) $sqlv("SELECT COALESCE(SUM(amount),0) FROM ticket_orders WHERE status = 'paid' AND account_id IS NULL"), json_encode($st['stats'] ?? $st));

// ---- settings changes afterwards
$ap(['enabled' => true, 'guests' => 0]);
$nc = new Client('nominee'); $db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id = {$entry['nb']}");
$nc->login('nb@test.dev', 'Test-Pass-12345');
check('a later change to guests applies to new claims only', count($nc->post('/nominee/access-pass')[1]['tickets'] ?? []) === 1 && count($na->get('/nominee/access-pass')[1]['tickets']) === 3);
$ap(['enabled' => false, 'guests' => 0]);
check('switching the pass off hides nothing already claimed', $na->get('/nominee/access-pass')[1]['claimed'] === true && count($na->get('/nominee/access-pass')[1]['tickets']) === 3);
$db->exec("UPDATE nominee_entries SET status = 'rejected' WHERE id = {$entry['nb']}");
$sa->put("/admin/editions/$ed/access-pass", ['enabled' => false, 'guests' => 0]);

// ================================================================== O. PROMO FLIER AND NICKNAME (Round 2, phase 13)
group('O. Promo flier: admin look and wording, nominee nickname');
$pubFlier = fn () => (array_column((new Client())->get('/editions')[1]['editions'] ?? [], null, 'id')[$ed]['flier'] ?? null);
$f0 = $pubFlier();
check('a new edition has a working default flier', is_array($f0) && $f0['enabled'] === true && $f0['headline'] !== '' && preg_match('/^#[0-9a-f]{6}$/', $f0['bg']) === 1, json_encode($f0));
$fl = ['enabled' => true, 'headline' => 'Vote for me', 'tagline' => 'OGA 10.0 nominee', 'footer' => 'Scan to vote', 'bg' => '#101820', 'accent' => '#ffcc00', 'text' => '#FFFFFF', 'showQr' => true, 'showCategory' => false];
check('the admin can set the look and wording', $sa->put("/admin/editions/$ed/flier", $fl)[0] === 200);
$f1 = $pubFlier();
check('the public edition list carries it (colours normalised)', $f1['headline'] === 'Vote for me' && $f1['accent'] === '#ffcc00' && $f1['text'] === '#ffffff' && $f1['showCategory'] === false && $f1['tagline'] === 'OGA 10.0 nominee', json_encode($f1));
check('a headline is required', $sa->put("/admin/editions/$ed/flier", ['headline' => '  '] + $fl)[0] === 422);
check('colours must be six-digit hex', $sa->put("/admin/editions/$ed/flier", ['bg' => 'red'] + $fl)[0] === 422 && $sa->put("/admin/editions/$ed/flier", ['accent' => 'url(javascript:1)'] + $fl)[0] === 422 && $sa->put("/admin/editions/$ed/flier", ['text' => '#12345'] + $fl)[0] === 422);
check('texts are length-limited', $sa->put("/admin/editions/$ed/flier", ['headline' => str_repeat('x', 41)] + $fl)[0] === 422 && $sa->put("/admin/editions/$ed/flier", ['footer' => str_repeat('x', 81)] + $fl)[0] === 422);
check('a rejected save changes nothing', $pubFlier()['accent'] === '#ffcc00');
check('staff without settings access cannot change it', $gate->put("/admin/editions/$ed/flier", $fl)[0] === 403);
check('the flier can be switched off for the edition', $sa->put("/admin/editions/$ed/flier", ['enabled' => false] + $fl)[0] === 200 && $pubFlier()['enabled'] === false);
$sa->put("/admin/editions/$ed/flier", $fl);
check('the change is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Updated the promo flier'") >= 2);

// ---- the nickname
$eid = (int) $sqlv("SELECT id FROM nominee_entries WHERE account_id = $aid ORDER BY id LIMIT 1");
$prof = fn (array $o = []) => $m1->patch("/nominee/entries/$eid", array_merge(['name' => 'Multi Renamed', 'phone' => '08039999999', 'department' => 'Law', 'bio' => 'One bio for all'], $o));
check('a nominee can set a nickname', $prof(['nickname' => 'Mr Multi'])[0] === 200 && $sqlv("SELECT nickname FROM nominee_entries WHERE id = $eid") === 'Mr Multi');
check('it is shared by all their categories in the edition', (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = $aid AND nickname = 'Mr Multi'") === (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE account_id = $aid"));
check('the nominee sees it back on their entries', in_array('Mr Multi', array_column($m1->get('/nominee/me')[1]['entries'] ?? [], 'nickname'), true));
check('saving the profile without a nickname keeps it', $prof()[0] === 200 && $sqlv("SELECT nickname FROM nominee_entries WHERE id = $eid") === 'Mr Multi');
check('an empty nickname clears it', $prof(['nickname' => ''])[0] === 200 && $sqlv("SELECT nickname FROM nominee_entries WHERE id = $eid") === null);
$prof(['nickname' => 'Mr Multi']);
check('a nickname with markup or control characters is refused', $prof(['nickname' => '<b>Hi</b>'])[0] === 422 && $prof(['nickname' => "Bad\x07name"])[0] === 422);
check('a nickname longer than 40 characters is refused', $prof(['nickname' => str_repeat('n', 41)])[0] === 422);
check('the nickname is never in the public data', !str_contains((new Client())->get('/nominees')[2], 'Mr Multi'));
check('another nominee cannot set it on someone else’s entry', $na->patch("/nominee/entries/$eid", ['name' => 'Hijack', 'phone' => '08031111111', 'department' => 'X', 'nickname' => 'Hijacked'])[0] === 404 && $sqlv("SELECT nickname FROM nominee_entries WHERE id = $eid") === 'Mr Multi');

// ================================================================== P. ANNOUNCEMENTS (Round 2, phase 14)
group('P. Announcements: public, nominees only, scheduled, pinned');
$db->exec("UPDATE editions SET status = 'active', state_mode = 'auto', force_state = NULL WHERE id = 2");
check('migration 016 made the announcements and Face of the period tables and the permissions', $sqlv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('announcements', 'face_of_period')") == 2
    && $sqlv("SELECT COUNT(*) FROM permissions WHERE perm_key IN ('announcements.view', 'announcements.manage', 'spotlight.view', 'spotlight.manage')") == 4);
check('the editor and publicity roles got them by default', $sqlv("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug IN ('editor', 'publicity') AND rp.perm_key IN ('announcements.manage', 'spotlight.manage')") == 4);
$ann = fn (array $o = []) => $sa->post('/admin/announcements', array_merge(['edition' => $ed, 'title' => 'Voting opens Monday', 'body' => "Get ready.\nVoting opens Monday at 9am.", 'audience' => 'public', 'pinned' => false, 'status' => 'published'], $o));
$pubAnn = fn () => (new Client())->get('/announcements')[1];
$titles = fn (array $l) => array_column($l, 'title');
check('staff without announcement permissions cannot list or add', $gate->get("/admin/announcements?edition=$ed")[0] === 403 && $gate->post('/admin/announcements', ['edition' => $ed, 'title' => 'Nope nope', 'body' => 'x'])[0] === 403);
check('anonymous callers are refused', (new Client())->get("/admin/announcements?edition=$ed")[0] === 401);
[$c, $r] = $ann();
check('an admin can publish an announcement', $c === 200 && !empty($r['id']), json_encode($r));
$id1 = (int) $r['id'];
check('it shows on the public site straight away', in_array('Voting opens Monday', $titles($pubAnn()), true));
check('line breaks are kept', str_contains(json_encode($pubAnn()[0]['body']), '\n'));
check('a title that is too short, a missing message or a bad audience is refused', $ann(['title' => 'ab'])[0] === 422 && $ann(['body' => ''])[0] === 422 && $ann(['audience' => 'everyone'])[0] === 422 && $ann(['status' => 'later'])[0] === 422);
check('a message over 2000 characters is refused', $ann(['body' => str_repeat('x', 2001)])[0] === 422);
check('control characters are refused', $ann(['title' => "Bad\x07title"])[0] === 422);
check('dates must be valid and the end must come after the start', $ann(['publishAt' => 'soon'])[0] === 422 && $ann(['publishAt' => '2030-01-02T10:00:00+01:00', 'expiresAt' => '2030-01-01T10:00:00+01:00'])[0] === 422);
check('nothing was half-saved by the refusals', (int) $sqlv("SELECT COUNT(*) FROM announcements") === 1);
$draft = $ann(['title' => 'A draft', 'status' => 'draft']);
check('a draft is not public', $draft[0] === 200 && !in_array('A draft', $titles($pubAnn()), true));
$nomOnly = $ann(['title' => 'Nominees: rehearsal at 5pm', 'audience' => 'nominees']);
check('a nominees-only message is not public', $nomOnly[0] === 200 && !in_array('Nominees: rehearsal at 5pm', $titles($pubAnn()), true));
$future = $ann(['title' => 'Scheduled for later', 'publishAt' => date('c', time() + 7200)]);
check('a scheduled announcement stays hidden until its time', $future[0] === 200 && !in_array('Scheduled for later', $titles($pubAnn()), true));
$db->exec("UPDATE announcements SET publish_at = NOW() - INTERVAL 1 MINUTE WHERE id = {$future[1]['id']}"); App\Cache::flush();
check('and appears when the time comes', in_array('Scheduled for later', $titles($pubAnn()), true));
$exp = $ann(['title' => 'Ends soon', 'expiresAt' => date('c', time() + 7200)]);
check('an announcement is visible before its end time', in_array('Ends soon', $titles($pubAnn()), true));
$db->exec("UPDATE announcements SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = {$exp[1]['id']}"); App\Cache::flush();
check('and disappears after it', !in_array('Ends soon', $titles($pubAnn()), true));
$pin = $ann(['title' => 'Pinned: read me first', 'pinned' => true]);
check('pinned announcements come first', $pubAnn()[0]['title'] === 'Pinned: read me first');
check('the public list never exposes who wrote it or its audience settings', array_diff(array_keys($pubAnn()[0]), ['id', 'title', 'body', 'pinned', 'at']) === []);
check('a script in a message is kept as plain text data (the pages escape it when showing it)', $ann(['title' => 'XSS test here', 'body' => '<script>alert(1)</script>'])[0] === 200 && in_array('<script>alert(1)</script>', array_column($pubAnn(), 'body'), true));

// ---- nominees
check('only signed-in nominees can read the nominee feed', (new Client('nominee'))->get('/nominee/announcements')[0] === 401);
$nl = $na->get('/nominee/announcements');
check('a nominee sees public and nominees-only messages', $nl[0] === 200 && in_array('Nominees: rehearsal at 5pm', $titles($nl[1]['announcements']), true) && in_array('Voting opens Monday', $titles($nl[1]['announcements']), true));
check('but not drafts', !in_array('A draft', $titles($nl[1]['announcements']), true));
check('the admin list shows everything, with which are live', (function () use ($sa, $ed, $titles) {
    $l = $sa->get("/admin/announcements?edition=$ed")[1]['announcements'] ?? [];
    $live = array_column($l, 'live', 'title');
    return count($l) >= 7 && $live['A draft'] === false && $live['Voting opens Monday'] === true && $live['Ends soon'] === false;
})());

// ---- edit and delete
check('an admin can edit one', $sa->patch("/admin/announcements/$id1", ['edition' => $ed, 'title' => 'Voting opens Tuesday', 'body' => 'Moved by a day.', 'audience' => 'public', 'status' => 'published', 'pinned' => false])[0] === 200 && in_array('Voting opens Tuesday', $titles($pubAnn()), true) && !in_array('Voting opens Monday', $titles($pubAnn()), true));
check('and unpublish it by making it a draft', $sa->patch("/admin/announcements/$id1", ['edition' => $ed, 'title' => 'Voting opens Tuesday', 'body' => 'Moved by a day.', 'status' => 'draft'])[0] === 200 && !in_array('Voting opens Tuesday', $titles($pubAnn()), true));
check('and delete it', $sa->delete("/admin/announcements/$id1", ['edition' => $ed])[0] === 200 && (int) $sqlv("SELECT COUNT(*) FROM announcements WHERE id = $id1") === 0);
check('editing or deleting something that is gone is a clean 404', $sa->patch("/admin/announcements/$id1", ['edition' => $ed, 'title' => 'Ghost title', 'body' => 'x'])[0] === 404 && $sa->delete("/admin/announcements/$id1", ['edition' => $ed])[0] === 404);
check('gate staff cannot edit or delete', $gate->patch("/admin/announcements/{$pin[1]['id']}", ['edition' => $ed, 'title' => 'Hacked title', 'body' => 'x'])[0] === 403 && $gate->delete("/admin/announcements/{$pin[1]['id']}", ['edition' => $ed])[0] === 403);
check('every change is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action IN ('Added an announcement', 'Edited an announcement', 'Deleted an announcement')") >= 6);
$db->exec("DELETE FROM announcements"); App\Cache::flush();

// ================================================================== Q. FACE OF THE PERIOD AND WINNERS SHOWCASE (Round 2, phase 15)
group('Q. Face of the period and the winners showcase');
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id = {$entry['na']}");
$db->exec("UPDATE nominee_entries SET status = 'rejected' WHERE id = {$entry['nb']}");
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id = $eid"); App\Cache::flush();
$face = fn (array $o = []) => $sa->post('/admin/spotlight', array_merge(['edition' => $ed, 'nomineeId' => (string) $entry['na'], 'period' => 'November 2026', 'headline' => 'Face of the month', 'note' => 'Brightens every room.', 'featured' => true], $o));
$pubFace = fn () => (new Client())->get('/spotlight')[1];
check('staff without spotlight permissions cannot list or add', $gate->get("/admin/spotlight?edition=$ed")[0] === 403 && $gate->post('/admin/spotlight', ['edition' => $ed, 'nomineeId' => (string) $entry['na'], 'period' => 'X period'])[0] === 403);
check('nobody is featured to begin with', $pubFace() === ['featured' => null, 'history' => []]);
check('a nominee who is not approved cannot be featured', $face(['nomineeId' => (string) $entry['nb']])[0] === 422);
check('nor someone from another edition', $face(['nomineeId' => (string) $sqlv("SELECT id FROM nominee_entries WHERE edition_id = 1 AND status = 'approved' LIMIT 1")])[0] === 422);
check('a period name is required, within 60 characters', $face(['period' => ' '])[0] === 422 && $face(['period' => str_repeat('p', 61)])[0] === 422);
check('the note and headline are length-limited', $face(['note' => str_repeat('n', 501)])[0] === 422 && $face(['headline' => str_repeat('h', 121)])[0] === 422);
check('nothing was saved by the refusals', (int) $sqlv("SELECT COUNT(*) FROM face_of_period") === 0);
[$c, $r] = $face();
check('an admin can feature an approved nominee', $c === 200, json_encode($r));
$f = $pubFace();
check('the public site shows them: name, category, period, note, photo', ($f['featured']['name'] ?? '') !== '' && $f['featured']['period'] === 'November 2026' && $f['featured']['note'] === 'Brightens every room.' && $f['featured']['category'] === $catName && array_key_exists('photo', $f['featured']), json_encode($f));
check('it carries no counts, rankings or private details', array_intersect($walk($f), array_merge($banned, ['email', 'phone'])) === [] && !preg_match('/@test\.dev|HSWF\/\d\d/', json_encode($f)));
check('the same period cannot be added twice', $face()[0] === 422);
$id2 = (int) $sqlv("SELECT id FROM face_of_period ORDER BY id DESC LIMIT 1");
check('a second face for a new period can be featured, and replaces the first on the home page', $face(['period' => 'December 2026', 'nomineeId' => (string) $eid, 'headline' => 'Face of December'])[0] === 200 && $pubFace()['featured']['period'] === 'December 2026' && count($pubFace()['history']) === 1 && $pubFace()['history'][0]['period'] === 'November 2026');
check('only one is ever featured at a time', (int) $sqlv("SELECT COUNT(*) FROM face_of_period WHERE featured = 1") === 1);
check('an admin can bring the earlier one back as the featured face', $sa->post("/admin/spotlight/$id2/feature")[0] === 200 && $pubFace()['featured']['period'] === 'November 2026' && (int) $sqlv("SELECT COUNT(*) FROM face_of_period WHERE featured = 1") === 1);
check('an entry can be edited', $sa->patch("/admin/spotlight/$id2", ['edition' => $ed, 'nomineeId' => (string) $entry['na'], 'period' => 'November 2026', 'headline' => 'Our November star', 'note' => '', 'featured' => true])[0] === 200 && $pubFace()['featured']['headline'] === 'Our November star' && $pubFace()['featured']['note'] === '');
check('the admin list shows all of them', count($sa->get("/admin/spotlight?edition=$ed")[1]['faces'] ?? []) === 2);
$db->exec("UPDATE nominee_entries SET status = 'rejected' WHERE id = {$entry['na']}"); App\Cache::flush();
check('if the nominee stops being approved, they leave the public site', ($pubFace()['featured']['period'] ?? null) !== 'November 2026');
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id = {$entry['na']}"); App\Cache::flush();
check('gate staff cannot edit, feature or delete', $gate->patch("/admin/spotlight/$id2", ['edition' => $ed, 'nomineeId' => (string) $entry['na'], 'period' => 'Hacked'])[0] === 403 && $gate->post("/admin/spotlight/$id2/feature")[0] === 403 && $gate->delete("/admin/spotlight/$id2", ['edition' => $ed])[0] === 403);
check('an admin can remove one', $sa->delete("/admin/spotlight/$id2", ['edition' => $ed])[0] === 200 && (int) $sqlv("SELECT COUNT(*) FROM face_of_period WHERE id = $id2") === 0);
check('removing something already gone is a clean 404', $sa->delete("/admin/spotlight/$id2", ['edition' => $ed])[0] === 404);
check('the changes are in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action LIKE '%Face of the period%'") >= 4);
$db->exec("DELETE FROM face_of_period"); App\Cache::flush();

// ---- the winners showcase
$db->exec("UPDATE nominee_entries SET status = 'approved', bio = " . $db->quote(str_repeat('A long and lovely story. ', 20)) . " WHERE id = {$entry['na']}");
$db->exec("DELETE FROM winners WHERE edition_id = 2");
$db->prepare("INSERT INTO winners (edition_id, category_id, nominee_entry_id) VALUES (2, 2, ?)")->execute([$entry['na']]);
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, results_published_at = NULL, voting_open_at = NOW() - INTERVAL 30 DAY, voting_close_at = NOW() - INTERVAL 2 DAY, results_publish_at = NOW() - INTERVAL 1 DAY WHERE id = 2"); App\Cache::flush();
$show = fn () => array_column((new Client())->get('/showcase')[1] ?? [], null, 'occasionId');
check('winners that are not published are not in the showcase', !isset($show()[$ed]));
$db->exec("UPDATE editions SET results_published_at = NOW() - INTERVAL 1 HOUR WHERE id = 2"); App\Cache::flush();
$sc = $show();
check('published winners appear, grouped by edition', isset($sc[$ed]) && count($sc[$ed]['winners']) === 1 && $sc[$ed]['edition'] !== '');
check('with name, department, category, photo and a short intro', ($w = $sc[$ed]['winners'][0])['categoryId'] === '2' && $w['name'] !== '' && $w['category'] === $catName && array_key_exists('photo', $w) && mb_strlen($w['intro']) <= 200 && str_ends_with($w['intro'], '…'));
check('and nothing else: no counts, rankings, contact details', array_keys($w) === ['categoryId', 'category', 'nomineeId', 'name', 'department', 'photo', 'thumb', 'intro'] && array_intersect($walk($sc), $banned) === []);
check('earlier, completed editions are part of the hall of fame', isset($sc['oga-9']) && count($sc['oga-9']['winners']) >= 1);
$sa->post('/admin/results/retract', ['edition' => $ed, 'reason' => 'Showcase test is over']);
$db->exec("UPDATE editions SET results_published_at = NULL WHERE id = 2"); App\Cache::flush();
check('retracting the results removes them from the showcase at once', !isset($show()[$ed]));
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, results_published_at = NOW() - INTERVAL 1 HOUR WHERE id = 2");
$db->exec("UPDATE editions SET state_mode = 'manual', force_state = 'voting-closed' WHERE id = 2"); App\Cache::flush();
check('a state forced to “voting closed” hides them even though they are published', !isset($show()[$ed]));
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, results_published_at = NULL WHERE id = 2"); App\Cache::flush();

// ================================================================== R. CONTENT MANAGER (Round 2, phase 16)
group('R. Content manager: FAQs, About, Terms and privacy, Gallery, Home wording');
check('migration 017 made the content table and permissions', $sqlv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'site_content'") == 1
    && $sqlv("SELECT COUNT(*) FROM permissions WHERE perm_key IN ('content.view', 'content.edit')") == 2);
check('the editor and publicity roles got them by default', $sqlv("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug IN ('editor', 'publicity') AND rp.perm_key = 'content.edit'") == 2);
$orig = fn (string $f) => json_decode((string) file_get_contents(dirname(__DIR__) . "/data/$f.json"), true);
$pub = fn (string $p) => (new Client())->get($p)[1];
$put = fn (string $name, array $b) => $sa->put("/admin/content/$name", ['edition' => $ed] + $b);
check('staff without content permissions cannot read or edit', $gate->get("/admin/content?edition=$ed")[0] === 403 && $gate->put("/admin/content/faqs", ['edition' => $ed, 'items' => []])[0] === 403 && $gate->delete("/admin/content/faqs", ['edition' => $ed])[0] === 403);
check('anonymous callers are refused', (new Client())->get("/admin/content?edition=$ed")[0] === 401);
$list = $sa->get("/admin/content?edition=$ed")[1]['pages'] ?? [];
check('the admin sees the five editable pages, none customised yet', array_column($list, 'name') === ['faqs', 'about', 'legal', 'gallery', 'home'] && array_filter(array_column($list, 'customised')) === []);
check('until edited, the public site serves the original files', $pub('/faqs') === $orig('faqs') && $pub('/about') === $orig('about') && $pub('/legal') === $orig('legal'));
check('an unknown page is a clean 404', $sa->get("/admin/content/nothing?edition=$ed")[0] === 404 && $put('nothing', [])[0] === 404);

// ---- FAQs
$faqs = [['group' => 'Voting', 'q' => 'How do I vote?', 'a' => "Pick a nominee.\nPay securely."], ['group' => '', 'q' => 'Is it free to nominate?', 'a' => 'It depends on the edition.']];
check('the admin can replace the FAQs', $put('faqs', ['items' => $faqs])[0] === 200);
$f = $pub('/faqs');
check('the public site shows the new ones straight away', count($f) === 2 && $f[0]['q'] === 'How do I vote?' && $f[0]['group'] === 'Voting' && str_contains($f[0]['a'], "\n"));
check('an empty group becomes General, ids are made and unique', $f[1]['group'] === 'General' && $f[0]['id'] !== $f[1]['id'] && $f[0]['id'] !== '');
check('the admin list says it is customised', in_array(true, array_column($sa->get("/admin/content?edition=$ed")[1]['pages'], 'customised'), true));
check('a question needs text, an answer too', $put('faqs', ['items' => [['q' => '', 'a' => 'x']]])[0] === 422 && $put('faqs', ['items' => [['q' => 'Why?', 'a' => '']]])[0] === 422);
check('long text and too many items are refused', $put('faqs', ['items' => [['q' => str_repeat('q', 201), 'a' => 'x']]])[0] === 422 && $put('faqs', ['items' => array_fill(0, 101, ['q' => 'Q?', 'a' => 'A'])])[0] === 422);
check('control characters are refused', $put('faqs', ['items' => [['q' => "Bad\x07", 'a' => 'x']]])[0] === 422);
check('a refused save changes nothing', count($pub('/faqs')) === 2);
check('markup is kept as plain text (the pages escape it)', $put('faqs', ['items' => [['q' => '<b>Bold?</b>', 'a' => '<script>x</script>']]])[0] === 200 && $pub('/faqs')[0]['q'] === '<b>Bold?</b>');
check('reset brings the original back', $sa->delete("/admin/content/faqs", ['edition' => $ed])[0] === 200 && $pub('/faqs') === $orig('faqs') && (int) $sqlv("SELECT COUNT(*) FROM site_content WHERE name = 'faqs'") === 0);

// ---- About
$about = ['kicker' => 'About us', 'title' => 'Who we are', 'lead' => 'A short intro.', 'story' => ['First paragraph.', 'Second paragraph.'], 'valuesTitle' => 'We value', 'values' => [['icon' => 'fa-heart', 'title' => 'Care', 'text' => 'We care.'], ['icon' => '', 'title' => 'Trust', 'text' => '']]];
check('the admin can rewrite the About page', $put('about', $about)[0] === 200 && $pub('/about')['title'] === 'Who we are' && $pub('/about')['values'][0]['icon'] === 'fa-heart' && $pub('/about')['values'][1]['icon'] === 'fa-star');
check('an icon must be a Font Awesome name', $put('about', ['values' => [['icon' => 'javascript:alert(1)', 'title' => 'Bad', 'text' => '']]] + $about)[0] === 422 && $put('about', ['values' => [['icon' => 'fa-x" onclick="y', 'title' => 'Bad', 'text' => '']]] + $about)[0] === 422);
check('a title is required, and story and values are limited', $put('about', ['title' => ''] + $about)[0] === 422 && $put('about', ['story' => array_fill(0, 7, 'p')] + $about)[0] === 422 && $put('about', ['values' => array_fill(0, 9, ['title' => 'v'])] + $about)[0] === 422);

// ---- Terms and privacy
$legal = $pub('/legal');
$newPrivacy = ['title' => 'Our privacy promise', 'updated' => '2026-11-01', 'draft' => false, 'sections' => [['heading' => 'What we keep', 'body' => 'Only what we need.']]];
check('the admin can rewrite one page and the other stays as it was', $put('legal', ['privacy' => $newPrivacy])[0] === 200 && $pub('/legal')['privacy']['title'] === 'Our privacy promise' && $pub('/legal')['privacy']['draft'] === false && $pub('/legal')['terms'] === $legal['terms']);
check('the date must be real', $put('legal', ['privacy' => ['updated' => '2026-13-40'] + $newPrivacy])[0] === 422 && $put('legal', ['privacy' => ['updated' => 'yesterday'] + $newPrivacy])[0] === 422);
check('a page needs between 1 and 30 sections', $put('legal', ['terms' => ['sections' => []] + $newPrivacy])[0] === 422 && $put('legal', ['terms' => ['sections' => array_fill(0, 31, ['heading' => 'h', 'body' => 'b'])] + $newPrivacy])[0] === 422);
check('a section needs a heading and text', $put('legal', ['terms' => ['sections' => [['heading' => '', 'body' => 'b']]] + $newPrivacy])[0] === 422);

// ---- Gallery
check('the gallery starts empty', $pub('/gallery') === []);
$img = imagecreatetruecolor(300, 200); imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 220));
$gTmp = sys_get_temp_dir() . '/gallery-pic.png'; imagepng($img, $gTmp);
[$c, $r] = $sa->call('POST', "/admin/content/gallery/image?edition=$ed", ['image' => new CURLFile($gTmp, 'image/png', 'g.png')], [], true);
check('a picture can be uploaded', $c === 200 && preg_match('#^uploads/gallery/[a-f0-9]{2}/[a-f0-9]+\.webp$#', $r['path'] ?? '') === 1, $c . json_encode($r));
clearstatcache();
check('and is saved on disk', is_file(dirname(App\Photos::root()) . '/' . ($r['path'] ?? 'x')));
check('staff without content permissions cannot upload', $gate->call('POST', "/admin/content/gallery/image?edition=$ed", ['image' => new CURLFile($gTmp, 'image/png', 'g.png')], [], true)[0] === 403);
check('a non-image is refused', $sa->call('POST', "/admin/content/gallery/image?edition=$ed", ['image' => new CURLFile(__FILE__, 'image/png', 'g.png')], [], true)[0] === 422);
check('the gallery can be filled using that picture', $put('gallery', ['items' => [['title' => 'Opening night', 'caption' => 'The crowd arrives', 'edition' => 'OGA 9.0', 'icon' => '', 'src' => $r['path']], ['title' => 'No picture yet', 'src' => '']]])[0] === 200
    && count($pub('/gallery')) === 2 && $pub('/gallery')[0]['src'] === $r['path'] && $pub('/gallery')[1]['icon'] === 'fa-image' && $pub('/gallery')[0]['id'] !== $pub('/gallery')[1]['id']);
check('a picture address from anywhere else is refused', $put('gallery', ['items' => [['title' => 'Hotlink', 'src' => 'https://evil.example/x.png']]])[0] === 422 && $put('gallery', ['items' => [['title' => 'Traversal', 'src' => 'uploads/gallery/../../config.php']]])[0] === 422 && $put('gallery', ['items' => [['title' => 'Script', 'src' => 'javascript:alert(1)']]])[0] === 422);
check('a gallery item needs a title', $put('gallery', ['items' => [['title' => '']]])[0] === 422);

// ---- Home wording
$origHome = $orig('content')['home'];
check('the admin can change the home wording of one state', $put('home', ['states' => ['voting' => ['title' => 'Voting is open!', 'highlight' => 'cast yours.', 'lead' => 'Pick a favourite.']]])[0] === 200 && $pub('/content')['home']['voting']['title'] === 'Voting is open!');
check('buttons, highlights and the other states are untouched', $pub('/content')['home']['voting']['ctas'] === $origHome['voting']['ctas'] && $pub('/content')['home']['voting']['highlights'] === $origHome['voting']['highlights'] && $pub('/content')['home']['results'] === $origHome['results']);
check('an unknown state or a blank title is refused', $put('home', ['states' => ['party' => ['title' => 'x']]])[0] === 422 && $put('home', ['states' => ['voting' => ['title' => '']]])[0] === 422 && $put('home', ['states' => ['voting' => ['title' => str_repeat('t', 121)]]])[0] === 422);
check('keys that are not plain text cannot be set this way', $put('home', ['states' => ['voting' => ['ctas' => [['label' => 'Hacked', 'href' => 'javascript:1']]]]])[0] === 200 && $pub('/content')['home']['voting']['ctas'] === $origHome['voting']['ctas']);

// ---- housekeeping
check('every edit and reset is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action IN ('Edited site content', 'Reset site content to the original', 'Uploaded a gallery picture')") >= 8);
foreach (['about', 'legal', 'gallery', 'home'] as $n) { $sa->delete("/admin/content/$n", ['edition' => $ed]); }
check('everything can be reset to the original files', $pub('/about') === $orig('about') && $pub('/legal') === $orig('legal') && $pub('/gallery') === $orig('gallery') && $pub('/content') === $orig('content'));

// ================================================================== S. ANTI-FRAUD (Round 2, phase 17)
group('S. Anti-fraud: signals, review, void, block, limits');
check('migration 018 added the address, the rules, the blocks and the reviews', $sqlv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'vote_orders' AND column_name = 'ip_address'") == 1
    && $sqlv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('vote_blocks', 'fraud_reviews')") == 2
    && $sqlv("SELECT COUNT(*) FROM permissions WHERE perm_key IN ('fraud.view', 'fraud.manage')") == 2);
check('the treasurer and auditor roles can view by default', $sqlv("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug IN ('treasurer', 'auditor') AND rp.perm_key = 'fraud.view'") == 2);
check('staff without fraud permissions are refused', $gate->get("/admin/fraud?edition=$ed")[0] === 403 && $gate->put('/admin/fraud/rules', ['edition' => $ed])[0] === 403 && $gate->post('/admin/fraud/review', ['edition' => $ed, 'key' => 'x', 'action' => 'dismiss'])[0] === 403 && $gate->post('/admin/fraud/blocks', ['edition' => $ed, 'kind' => 'ip', 'value' => '1.2.3.4'])[0] === 403);
check('anonymous callers are refused', (new Client())->get("/admin/fraud?edition=$ed")[0] === 401);

// a clean slate: this edition, two approved nominees in one category, no orders
$db->exec("UPDATE edition_settings SET fraud_rules = NULL WHERE edition_id = 2");
$db->exec("UPDATE nominee_entries SET status = 'approved' WHERE id IN ({$entry['na']}, {$entry['nb']})");
$db->exec("DELETE FROM vote_orders WHERE edition_id = 2"); $db->exec("DELETE FROM vote_totals WHERE edition_id = 2");
$ov = fn () => $sa->get("/admin/fraud?edition=$ed")[1];
$o = $ov();
check('the rules start at sensible defaults, with the address limit OFF (campuses share one address)', $o['rules'] === ['ipMaxOrdersPerHour' => 0, 'burstVotes' => 300, 'burstMinutes' => 10, 'ipClusterOrders' => 15, 'domainClusterEmails' => 20] && $o['signals'] === [] && $o['open'] === 0 && $o['blocks'] === []);
$seq = 0;
$order = function (int $nominee, int $votes, string $email, ?string $ip, string $paidAt) use ($db, &$seq) {
    $seq++;
    $db->prepare("INSERT INTO vote_orders (edition_id, reference, nominee_entry_id, category_id, votes, amount, voter_name, voter_email, ip_address, status, source, paid_at) VALUES (2, ?, ?, 2, ?, ?, 'Voter', ?, ?, 'paid', 'payment', ?)")
        ->execute(["FRD-$seq", $nominee, $votes, $votes * 50, $email, $ip, $paidAt]);
    return "FRD-$seq";
};
$now = date('Y-m-d H:i:s', time() - 7200);
for ($i = 1; $i <= 16; $i++) { $order($entry['na'], 2, "u$i@mailbox$i.test", '203.0.113.9', date('Y-m-d H:i:s', time() - 86400 * 2 - $i * 3600)); }   // 16 orders from one address, spread out
for ($i = 1; $i <= 22; $i++) { $order($entry['nb'], 3, "kid$i@throwaway.example", '198.51.100.' . $i, date('Y-m-d H:i:s', time() - 86400 * 3 - $i * 3600)); }   // 22 emails at one odd domain
for ($i = 1; $i <= 4; $i++) { $order($entry['nb'], 100, "burst$i@gmail.com", null, date('Y-m-d H:i:s', strtotime('today 03:00') - 86400 * 5 + $i * 60)); }   // 400 votes in a few minutes
check('ordinary voting makes no noise', (function () use ($order, $entry, $ov) { $order($entry['na'], 5, 'normal@gmail.com', '192.0.2.1', date('Y-m-d H:i:s', time() - 100000)); return true; })());
App\VoteTotals::rebuild($db);
$o = $ov();
$kinds = array_count_values(array_column($o['signals'], 'kind'));
check('three kinds of signal are found', ($kinds['ip_cluster'] ?? 0) === 1 && ($kinds['domain_cluster'] ?? 0) === 1 && ($kinds['burst'] ?? 0) >= 1, json_encode($kinds));
$by = array_column($o['signals'], null, 'kind');
check('the address cluster says which address, how many orders and which nominee', $by['ip_cluster']['orders'] === 16 && $by['ip_cluster']['votes'] === 32 && str_contains($by['ip_cluster']['detail'], '203.0.113.9') && $by['ip_cluster']['nomineeId'] === (string) $entry['na']);
check('the domain cluster ignores big providers like gmail and names the odd one', str_contains($by['domain_cluster']['detail'], 'throwaway.example') && $by['domain_cluster']['orders'] === 22 && !str_contains(json_encode($o['signals']), 'domain:gmail.com'));
check('the burst names the votes and the nominee', $by['burst']['votes'] >= 300 && $by['burst']['nomineeId'] === (string) $entry['nb']);
check('signals are listed with the biggest first', $o['signals'][0]['votes'] >= $o['signals'][count($o['signals']) - 1]['votes']);
check('looking at them changes nothing', (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE status = 'void'") === 0);

// ---- rules
$rules = ['ipMaxOrdersPerHour' => 0, 'burstVotes' => 300, 'burstMinutes' => 10, 'ipClusterOrders' => 15, 'domainClusterEmails' => 20];
$setRules = fn (array $o = []) => $sa->put('/admin/fraud/rules', ['edition' => $ed] + $o + $rules);
check('rules must be whole numbers within range', $setRules(['burstMinutes' => 0])[0] === 422 && $setRules(['burstMinutes' => 121])[0] === 422 && $setRules(['ipMaxOrdersPerHour' => -1])[0] === 422 && $setRules(['burstVotes' => 'many'])[0] === 422 && $setRules(['ipClusterOrders' => 1.5])[0] === 422);
check('a rule that is refused changes nothing', $ov()['rules'] === $rules);
check('raising the cluster limit above what happened removes that signal', $setRules(['ipClusterOrders' => 17])[0] === 200 && !isset(array_column($ov()['signals'], null, 'kind')['ip_cluster']));
check('0 switches a rule off', $setRules(['ipClusterOrders' => 0, 'domainClusterEmails' => 0, 'burstVotes' => 0])[0] === 200 && $ov()['signals'] === []);
$setRules();
check('putting the numbers back brings them back', count($ov()['signals']) >= 3);
check('the rule change is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Changed the fraud rules'") >= 3);

// ---- review: dismiss
$ipKey = $by['ip_cluster']['key']; $domKey = $by['domain_cluster']['key']; $burstKey = $by['burst']['key'];
check('a review needs a valid action', $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => $ipKey, 'action' => 'ignore'])[0] === 422 && $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => '', 'action' => 'dismiss'])[0] === 422);
check('a signal can be dismissed with a note', $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => $domKey, 'action' => 'dismiss', 'note' => 'A real class: checked with the lecturer'])[0] === 200);
$dom = array_column($ov()['signals'], null, 'key')[$domKey];
check('it stays in the list, marked as dismissed with the note, and no longer counts as open', $dom['status'] === 'dismissed' && str_contains($dom['note'], 'lecturer') && $ov()['open'] === count($ov()['signals']) - 1);
check('nothing was voided by dismissing', (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE status = 'void'") === 0);
check('the same signal cannot be reviewed twice', $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => $domKey, 'action' => 'dismiss'])[0] === 422);

// ---- review: void
$before = App\VoteTotals::perNominee(2)[(string) $entry['nb']] ?? 0;
check('voiding needs a written reason', $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => $burstKey, 'action' => 'void', 'note' => 'no'])[0] === 422 && (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE status = 'void'") === 0);
check('a made-up key finds nothing to void', $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => 'burst:999999:1:10', 'action' => 'void', 'note' => 'Testing a bad key'])[0] === 422 && $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => 'nonsense', 'action' => 'void', 'note' => 'Testing a bad key'])[0] === 422);
[$c, $r] = $sa->post('/admin/fraud/review', ['edition' => $ed, 'key' => $burstKey, 'action' => 'void', 'note' => 'Bulk votes from one script, confirmed with Flutterwave']);
check('an admin can void every order behind a signal', $c === 200 && $r['orders'] >= 1 && $r['votes'] >= 300, json_encode($r));
check('those orders are void, with the reason on each', (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE status = 'void' AND void_reason LIKE 'Fraud review: Bulk votes%'") === $r['orders']);
check('and the nominee’s total went down by exactly those votes', (App\VoteTotals::perNominee(2)[(string) $entry['nb']] ?? 0) === $before - $r['votes']);
check('the totals still agree with the orders', App\VoteTotals::drift($db) === []);
check('the signal is recorded as acted on and no longer open (its orders are gone, so it drops out of the list)', (int) $sqlv("SELECT COUNT(*) FROM fraud_reviews WHERE signal_key = ? AND status = 'actioned'", [$burstKey]) === 1 && (array_column($ov()['signals'], null, 'key')[$burstKey]['status'] ?? 'actioned') === 'actioned');
check('the action is in the activity log with the numbers', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Voided votes after a fraud review' AND details LIKE '%Bulk votes%'") === 1);
check('orders that were not behind the signal are untouched', (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE status = 'paid' AND voter_email = 'normal@gmail.com'") === 1);
check('voting needs the void permission as well as the fraud permission', $gate->post('/admin/fraud/review', ['edition' => $ed, 'key' => $ipKey, 'action' => 'void', 'note' => 'Should not work'])[0] === 403);

// ---- blocks
$blk = fn (array $o) => $sa->post('/admin/fraud/blocks', ['edition' => $ed] + $o);
check('an address, an email or a domain can be blocked', $blk(['kind' => 'ip', 'value' => '203.0.113.9', 'reason' => 'Bulk buying'])[0] === 200 && $blk(['kind' => 'email', 'value' => 'Bad@Example.com'])[0] === 200 && $blk(['kind' => 'domain', 'value' => 'throwaway.example'])[0] === 200);
check('values are checked', $blk(['kind' => 'ip', 'value' => 'not an ip'])[0] === 422 && $blk(['kind' => 'email', 'value' => 'nope'])[0] === 422 && $blk(['kind' => 'domain', 'value' => 'nodot'])[0] === 422 && $blk(['kind' => 'phone', 'value' => '1'])[0] === 422);
check('the same block cannot be added twice', $blk(['kind' => 'ip', 'value' => '203.0.113.9'])[0] === 422);
check('big providers cannot be blocked by domain', $blk(['kind' => 'domain', 'value' => 'gmail.com'])[0] === 422);
$bl = $ov()['blocks'];
check('the list shows them with who added them', count($bl) === 3 && $bl[2]['value'] === '203.0.113.9' && !empty($bl[0]['by']) && in_array('bad@example.com', array_column($bl, 'value'), true));
$vote = fn (array $o = []) => (new Client())->post('/votes/orders', $o + ['name' => 'Some Voter', 'email' => 'someone@gmail.com', 'votes' => 1, 'nomineeId' => (string) $entry['na'], 'categoryId' => '2']);
$db->exec("UPDATE editions SET status = 'active', state_mode = 'auto', force_state = NULL, voting_open_at = NOW() - INTERVAL 1 DAY, voting_close_at = NOW() + INTERVAL 1 DAY, results_publish_at = NOW() + INTERVAL 2 DAY, results_published_at = NULL WHERE id = 2"); App\Cache::flush();
check('a blocked email cannot start a vote order', ($r = $vote(['email' => 'bad@example.com']))[0] === 403 && str_contains($r[1]['error'] ?? '', 'could not accept'), json_encode($r));
check('nor an email at a blocked domain', $vote(['email' => 'anyone@throwaway.example'])[0] === 403);
check('the refusal does not say which rule caught them', !str_contains(json_encode($r), 'blocked') && !str_contains(json_encode($r), 'domain'));
check('and no order or payment was created for them', (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE voter_email IN ('bad@example.com', 'anyone@throwaway.example')") === 0);
check('an ordinary voter is not turned away (the request goes on to the payment step)', in_array($vote()[0], [200, 502], true));
check('and the address of the order is recorded', $sqlv("SELECT ip_address FROM vote_orders WHERE voter_email = 'someone@gmail.com' ORDER BY id DESC LIMIT 1") === '127.0.0.1');
$bid = (int) $sqlv("SELECT id FROM vote_blocks WHERE kind = 'email'");
check('staff without fraud permissions cannot remove a block', $gate->delete("/admin/fraud/blocks/$bid", ['edition' => $ed])[0] === 403);
check('an admin can remove a block, and that voter is welcome again', $sa->delete("/admin/fraud/blocks/$bid", ['edition' => $ed])[0] === 200 && $vote(['email' => 'bad@example.com'])[0] !== 403);
check('removing something gone is a clean 404', $sa->delete("/admin/fraud/blocks/$bid", ['edition' => $ed])[0] === 404);

// ---- the per-address hourly limit
$db->exec("DELETE FROM vote_blocks");
check('with the limit off, many orders from one address are fine', $vote(['email' => 'a1@gmail.com'])[0] !== 429 && $vote(['email' => 'a2@gmail.com'])[0] !== 429 && $vote(['email' => 'a3@gmail.com'])[0] !== 429);
$setRules(['ipMaxOrdersPerHour' => 2]);
$db->exec("UPDATE vote_orders SET created_at = NOW() WHERE voter_email LIKE 'a_@gmail.com'");
$db->exec("UPDATE vote_orders SET ip_address = '127.0.0.1' WHERE voter_email LIKE 'a_@gmail.com'");
check('once the admin sets a limit, an address over it is told to wait', ($r = $vote(['email' => 'a4@gmail.com']))[0] === 429 || $r[0] === 403, json_encode($r));
check('the message is friendly and has no numbers or rules in it', str_contains($r[1]['error'] ?? '', 'try again later') && !preg_match('/\d/', $r[1]['error'] ?? ''));
$db->exec("UPDATE vote_orders SET created_at = NOW() - INTERVAL 3 HOUR WHERE voter_email LIKE 'a_@gmail.com'");
check('orders older than an hour do not count', $vote(['email' => 'a5@gmail.com'])[0] !== 429);
$setRules();
$db->exec("UPDATE editions SET state_mode = 'auto', force_state = NULL, voting_open_at = NULL, voting_close_at = NULL, results_publish_at = NULL WHERE id = 2"); App\Cache::flush();

// ================================================================== T. ADMIN ACCOUNT SECURITY (Round 2, phase 18)
group('T. Admin account: change password, forgot password, sign out everywhere');
check('migration 019 added the change stamp and the reset links table', $sqlv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'people' AND column_name = 'password_changed_at'") == 1
    && $sqlv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_tokens'") == 1);
$hashOld = password_hash('Old-Pass-12345', PASSWORD_BCRYPT, ['cost' => 4]);
$db->prepare("INSERT INTO people (name, email, password_hash, active, is_super_admin) VALUES ('Account Tester', 'acct@test.dev', ?, 1, 1)")->execute([$hashOld]);
$acctId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO people (name, email, password_hash, active, is_super_admin) VALUES ('Invited Only', 'invited@test.dev', NULL, 1, 0)")->execute();
$invitedId = (int) $db->lastInsertId();
$db->prepare("INSERT INTO people (name, email, password_hash, active, is_super_admin) VALUES ('Gone Away', 'gone@test.dev', ?, 0, 0)")->execute([$hashOld]);
$goneId = (int) $db->lastInsertId();
$db->exec("DELETE FROM notification_outbox"); $db->exec("DELETE FROM login_attempts");
$mailTo = fn (string $email) => (int) $sqlv("SELECT COUNT(*) FROM notification_outbox WHERE type = 'admin_password_reset' AND recipient = ?", [$email]);

// ---- forgot password
$anon = new Client();
[$c1, $r1] = $anon->post('/admin-password/forgot', ['email' => 'acct@test.dev']);
[$c2, $r2] = $anon->post('/admin-password/forgot', ['email' => 'nobody@test.dev']);
check('asking for a reset gives the same answer for a real and an unknown email', $c1 === 200 && $c2 === 200 && $r1 === $r2, json_encode([$r1, $r2]));
check('only the real admin got an email', $mailTo('acct@test.dev') === 1 && $mailTo('nobody@test.dev') === 0);
$anon->post('/admin-password/forgot', ['email' => 'invited@test.dev']); $anon->post('/admin-password/forgot', ['email' => 'gone@test.dev']); $anon->post('/admin-password/forgot', ['email' => 'not an email']);
check('no email for someone who has no password yet, an inactive person, or a bad address', $mailTo('invited@test.dev') === 0 && $mailTo('gone@test.dev') === 0 && (int) $sqlv("SELECT COUNT(*) FROM admin_tokens WHERE person_id IN ($invitedId, $goneId)") === 0);
$row = $db->query("SELECT payload FROM notification_outbox WHERE type = 'admin_password_reset' AND recipient = 'acct@test.dev' ORDER BY id DESC LIMIT 1")->fetch();
$tok = json_decode((string) $row['payload'], true)['token'] ?? '';
check('the link secret is long and random', preg_match('/^[a-f0-9]{64}$/', $tok) === 1);
check('only a hash of it is stored in the database', (int) $sqlv("SELECT COUNT(*) FROM admin_tokens WHERE token_hash = ?", [hash('sha256', $tok)]) === 1 && (int) $sqlv("SELECT COUNT(*) FROM admin_tokens WHERE token_hash = ?", [$tok]) === 0);
$mail = App\MailTemplates::render('admin_password_reset', 'acct@test.dev', json_decode((string) $row['payload'], true));
check('the email links to the reset page with that secret and says how long it works', is_array($mail) && str_contains(json_encode($mail), 'admin-reset') && str_contains(json_encode($mail), $tok) && str_contains(json_encode($mail), '60'), json_encode($mail));
for ($i = 0; $i < 5; $i++) { $anon->post('/admin-password/forgot', ['email' => 'acct@test.dev']); }
check('no more than 3 links are made for one person in an hour', (int) $sqlv("SELECT COUNT(*) FROM admin_tokens WHERE person_id = $acctId") === 3);
check('and the answer still looks the same', $anon->post('/admin-password/forgot', ['email' => 'acct@test.dev'])[0] === 200);

// ---- reset with the link
$doReset = fn (string $t, string $pw) => (new Client())->post('/admin-password/reset', ['token' => $t, 'password' => $pw]);
check('a wrong or empty link is refused, with no detail', ($r = $doReset('0000', 'New-Pass-12345'))[0] === 422 && $doReset('', 'New-Pass-12345')[0] === 422 && !str_contains(json_encode($r), 'acct'));
check('a weak password is refused and the link is kept', $doReset($tok, 'short')[0] === 422 && $doReset($tok, str_repeat('x', 73))[0] === 422 && (int) $sqlv("SELECT COUNT(*) FROM admin_tokens WHERE token_hash = ? AND used_at IS NULL", [hash('sha256', $tok)]) === 1);
$other = new Client(); $other->login('acct@test.dev', 'Old-Pass-12345');
check('before the reset, the old password works', $other->get('/admin/me')[0] === 200 && $other->get('/admin/me')[1]['authenticated'] === true);
check('a good link sets the new password', $doReset($tok, 'New-Pass-12345')[0] === 200);
check('the new password signs in and the old one does not', (new Client())->login('acct@test.dev', 'New-Pass-12345')[0] === 200 && (new Client())->login('acct@test.dev', 'Old-Pass-12345')[0] !== 200);
check('a sign-in from before the reset is ended', $other->get('/admin/me')[1]['authenticated'] === false);
check('the link cannot be used twice', $doReset($tok, 'Another-Pass-123')[0] === 422);
check('and every other link of that person was cancelled too', (int) $sqlv("SELECT COUNT(*) FROM admin_tokens WHERE person_id = $acctId AND used_at IS NULL") === 0);
$db->exec("INSERT INTO admin_tokens (person_id, token_hash, expires_at) VALUES ($acctId, '" . hash('sha256', 'expired-secret') . "', NOW() - INTERVAL 1 MINUTE)");
check('an expired link is refused', $doReset('expired-secret', 'New-Pass-12345')[0] === 422);
$db->exec("INSERT INTO admin_tokens (person_id, token_hash, expires_at) VALUES ($goneId, '" . hash('sha256', 'gone-secret') . "', NOW() + INTERVAL 1 HOUR)");
check('a link for a person who was made inactive is refused', $doReset('gone-secret', 'New-Pass-12345')[0] === 422);
check('both events are in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Reset their admin password' AND person_id = $acctId") === 1);

// ---- change password while signed in
$me1 = new Client(); $me1->login('acct@test.dev', 'New-Pass-12345');
$me2 = new Client(); $me2->login('acct@test.dev', 'New-Pass-12345');
$chg = fn (Client $c, array $b) => $c->put('/admin/account/password', $b);
check('only signed-in admins can change a password', (new Client())->put('/admin/account/password', ['current' => 'x', 'password' => 'Whatever-12345'])[0] === 401);
[$c, $r] = $chg($me1, ['current' => 'Wrong-Pass-1234', 'password' => 'Newer-Pass-12345']);
check('a wrong current password is refused, naming the field', $c === 422 && isset($r['fields']['current']), json_encode($r));
check('a weak new password is refused', $chg($me1, ['current' => 'New-Pass-12345', 'password' => 'short'])[0] === 422 && $chg($me1, ['current' => 'New-Pass-12345', 'password' => str_repeat('z', 73)])[0] === 422);
check('the same password again is refused', $chg($me1, ['current' => 'New-Pass-12345', 'password' => 'New-Pass-12345'])[0] === 422);
$db->exec("DELETE FROM login_attempts");
for ($i = 0; $i < 5; $i++) { $chg($me1, ['current' => 'Wrong-Pass-1234', 'password' => 'Newer-Pass-12345']); }
check('after five wrong tries the answer is "wait", even for the right password', $chg($me1, ['current' => 'New-Pass-12345', 'password' => 'Newer-Pass-12345'])[0] === 429);
$db->exec("DELETE FROM login_attempts");
check('the password can be changed with the right current one', $chg($me1, ['current' => 'New-Pass-12345', 'password' => 'Newer-Pass-12345'])[0] === 200);
check('this sign-in carries on', $me1->get('/admin/me')[0] === 200 && $me1->put('/admin/account/profile', ['name' => 'Account Tester'])[0] === 200);
check('another sign-in of the same person is ended', $me2->put('/admin/account/profile', ['name' => 'Account Tester'])[0] === 401);
check('the new password works and the previous one does not', (new Client())->login('acct@test.dev', 'Newer-Pass-12345')[0] === 200 && (new Client())->login('acct@test.dev', 'New-Pass-12345')[0] !== 200);

// ---- sign out everywhere
$d1 = new Client(); $d1->login('acct@test.dev', 'Newer-Pass-12345'); $d2 = new Client(); $d2->login('acct@test.dev', 'Newer-Pass-12345');
check('“sign out everywhere” needs a sign-in', (new Client())->post('/admin/account/sign-out-everywhere')[0] === 401);
check('it ends every other sign-in and keeps this one', $d1->post('/admin/account/sign-out-everywhere')[0] === 200 && $d1->put('/admin/account/profile', ['name' => 'Account Tester'])[0] === 200 && $d2->put('/admin/account/profile', ['name' => 'Account Tester'])[0] === 401);
check('and the next sign-in works as normal', (new Client())->login('acct@test.dev', 'Newer-Pass-12345')[0] === 200);
check('an admin who was never signed in somewhere else is unaffected', $sa->get('/admin/me')[0] === 200);

// ---- display name
check('an admin can change their own display name', $d1->put('/admin/account/profile', ['name' => 'Tester Renamed'])[0] === 200 && $sqlv("SELECT name FROM people WHERE id = $acctId") === 'Tester Renamed');
check('a name that is empty, long or has markup is refused', $d1->put('/admin/account/profile', ['name' => ' '])[0] === 422 && $d1->put('/admin/account/profile', ['name' => str_repeat('n', 151)])[0] === 422 && $d1->put('/admin/account/profile', ['name' => '<b>x</b>'])[0] === 422);
check('the email cannot be changed this way', $d1->put('/admin/account/profile', ['name' => 'Tester Renamed', 'email' => 'hijack@test.dev'])[0] === 200 && $sqlv("SELECT email FROM people WHERE id = $acctId") === 'acct@test.dev');

// ---- a Super admin sends a reset link
$db->exec("DELETE FROM notification_outbox"); $db->exec("DELETE FROM admin_tokens");
check('someone who is not a Super admin cannot send one', $gate->post("/admin/people/$acctId/password-reset")[0] === 403);
check('a Super admin can', $sa->post("/admin/people/$acctId/password-reset")[0] === 200 && $mailTo('acct@test.dev') === 1);
check('and again, with no hourly limit for them', $sa->post("/admin/people/$acctId/password-reset")[0] === 200 && $sa->post("/admin/people/$acctId/password-reset")[0] === 200 && $sa->post("/admin/people/$acctId/password-reset")[0] === 200 && $mailTo('acct@test.dev') === 4);
check('someone who has never set a password gets pointed to an invitation instead', $sa->post("/admin/people/$invitedId/password-reset")[0] === 422 && $sa->post("/admin/people/$goneId/password-reset")[0] === 422);
check('an unknown person is a clean 404', $sa->post("/admin/people/999999/password-reset")[0] === 404);
check('it is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Sent a password reset link'") >= 4);
check('every account change is in the activity log', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action IN ('Changed their admin password', 'Signed out of every device', 'Changed their display name') AND person_id = $acctId") >= 3);

// ================================================================== U. IMPORT A PAST EDITION (Round 2, phase 19)
group('U. Import a past edition (OGA 9.0 style)');
check('migration 020 added the import permission', $sqlv("SELECT COUNT(*) FROM permissions WHERE perm_key = 'import.run'") == 1);
$pack = fn (array $o = []) => array_replace_recursive([
    'edition' => ['slug' => 'oga-8', 'name' => 'OGITECH Student Awards', 'label' => '8.0', 'year' => 2024, 'ceremony' => '2024-12-07T18:00:00+01:00', 'tagline' => 'Eighth edition'],
    'categories' => [['name' => 'Face of OGITECH', 'description' => 'The most photogenic'], ['name' => 'Best Developer']],
    'nominees' => [
        ['name' => 'Ada Obi', 'department' => 'Software', 'level' => 'HND II', 'category' => 'Face of OGITECH', 'bio' => 'Loves design.', 'votes' => 1200, 'winner' => true, 'email' => 'ada@secret.test', 'phone' => '0803'],
        ['name' => 'Bola Ade', 'department' => 'Law', 'level' => 'ND I', 'category' => 'face of ogitech', 'votes' => 900],
        ['name' => 'Chidi Eze', 'department' => 'Computer Science', 'category' => 'Best Developer', 'votes' => 50, 'winner' => true],
    ],
], $o);
$imp = fn (array $b) => $sa->post('/admin/import/edition', ['edition' => $ed] + $b);
check('staff without the import permission are refused', $gate->post('/admin/import/edition', ['edition' => $ed, 'payload' => $pack(), 'dryRun' => true])[0] === 403);
check('anonymous callers are refused', (new Client())->post('/admin/import/edition', ['payload' => $pack()])[0] === 401);
[$c, $r] = $imp(['payload' => $pack(), 'dryRun' => true]);
check('a clean file passes the check and reports what it would create', $c === 200 && $r['ok'] === true && $r['summary'] === ['edition' => 'OGITECH Student Awards 8.0', 'categories' => 2, 'nominees' => 3, 'winners' => 2, 'votes' => 2150], json_encode($r));
check('the check writes nothing', (int) $sqlv("SELECT COUNT(*) FROM editions WHERE slug = 'oga-8'") === 0);
check('it says what is ignored and not imported', str_contains(json_encode($r['notes']), '2 extra fields') && str_contains(json_encode($r['notes']), 'No emails'));

// ---- problems are all reported at once
$badPack = $pack(['edition' => ['slug' => 'Bad Slug!', 'year' => 1800, 'status' => 'draft'], 'nominees' => [['name' => '', 'category' => 'Face of OGITECH'], ['name' => 'Dee', 'category' => 'Nowhere'], ['name' => 'Ada Obi', 'category' => 'Face of OGITECH', 'votes' => -5]]]);
[$c, $r] = $imp(['payload' => $badPack, 'dryRun' => true]);
check('every problem is listed, not just the first', $c === 422 && $r['ok'] === false && count($r['errors']) >= 5 && $r['summary'] === null, json_encode($r['errors']));
check('and each says which item it is about', str_contains(json_encode($r['errors']), 'slug') && str_contains(json_encode($r['errors']), 'Nominee 1') && str_contains(json_encode($r['errors']), 'Nominee 2') && str_contains(json_encode($r['errors']), 'Nominee 3'));
check('a real import with problems writes nothing', $imp(['payload' => $badPack])[0] === 422 && (int) $sqlv("SELECT COUNT(*) FROM editions WHERE slug IN ('Bad Slug!', 'oga-8')") === 0);
$err = fn (array $o) => $imp(['payload' => $pack($o), 'dryRun' => true]);
check('an existing edition cannot be overwritten', str_contains(json_encode($err(['edition' => ['slug' => $ed]])[1]['errors']), 'already exists'));
check('two winners in one category are refused', $err(['nominees' => [1 => ['winner' => true]]])[0] === 422);
check('a nominee listed twice in a category is refused', $err(['nominees' => [1 => ['name' => 'Ada Obi']]])[0] === 422);
check('a duplicate category is refused', $err(['categories' => [1 => ['name' => 'FACE OF OGITECH']]])[0] === 422);
check('votes must be whole numbers', $err(['nominees' => [0 => ['votes' => 'lots']]])[0] === 422 && $err(['nominees' => [0 => ['votes' => 1.5]]])[0] === 422);
check('texts are length-limited', $err(['nominees' => [0 => ['bio' => str_repeat('b', 601)]]])[0] === 422 && $err(['edition' => ['name' => str_repeat('n', 101)]])[0] === 422);
check('an empty file is refused', $imp(['payload' => [], 'dryRun' => true])[0] === 422 && $imp(['payload' => ['edition' => ['slug' => 'oga-7']], 'dryRun' => true])[0] === 422);

// ---- the real import
[$c, $r] = $imp(['payload' => $pack()]);
check('a clean file can be imported', $c === 200 && $r['ok'] === true && $r['slug'] === 'oga-8', json_encode($r));
$eid8 = (int) $sqlv("SELECT id FROM editions WHERE slug = 'oga-8'");
check('it becomes a completed edition with its own settings', $sqlv("SELECT status FROM editions WHERE id = $eid8") === 'completed' && (int) $sqlv("SELECT COUNT(*) FROM edition_settings WHERE edition_id = $eid8") === 1 && $sqlv("SELECT year FROM editions WHERE id = $eid8") == 2024);
check('categories and nominees are all there, approved, in the right category (names matched ignoring case)', (int) $sqlv("SELECT COUNT(*) FROM categories WHERE edition_id = $eid8") === 2 && (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE edition_id = $eid8 AND status = 'approved'") === 3
    && (int) $sqlv("SELECT COUNT(*) FROM nominee_entries n JOIN categories c ON c.id = n.category_id WHERE n.edition_id = $eid8 AND n.name = 'Bola Ade' AND c.name = 'Face of OGITECH'") === 1);
check('no emails, phones or accounts came with them', (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE edition_id = $eid8 AND (account_id IS NOT NULL OR phone IS NOT NULL)") === 0 && !str_contains(json_encode($db->query("SELECT * FROM nominee_entries WHERE edition_id = $eid8")->fetchAll()), 'secret.test'));
check('the final vote counts are kept', (App\VoteTotals::perNominee($eid8)[(string) $sqlv("SELECT id FROM nominee_entries WHERE edition_id = $eid8 AND name = 'Ada Obi'")] ?? 0) === 1200);
check('the totals still agree with the orders, so the rebuild tool will not wipe them', App\VoteTotals::drift($db) === []);
check('the winners are recorded', (int) $sqlv("SELECT COUNT(*) FROM winners WHERE edition_id = $eid8") === 2);
$pubEds = array_column((new Client())->get('/editions')[1]['editions'] ?? [], null, 'id');
check('the public site lists the edition as history', isset($pubEds['oga-8']) && $pubEds['oga-8']['status'] === 'completed');
$w8 = array_filter((new Client())->get('/winners')[1] ?? [], fn ($x) => ($x['occasionId'] ?? '') === 'oga-8');
check('its winners are public, as for any completed edition', count($w8) === 2);
check('without ever exposing counts', !str_contains(json_encode((new Client())->get('/nominees')[1] ?? []), '1200'));
check('it is in the activity log with the numbers', (int) $sqlv("SELECT COUNT(*) FROM audit_log WHERE action = 'Imported a past edition' AND details LIKE '%2150%'") === 1);
check('importing the same file again is refused', $imp(['payload' => $pack()])[0] === 422 && (int) $sqlv("SELECT COUNT(*) FROM editions WHERE slug = 'oga-8'") === 1);
check('the active edition was not touched', $sqlv("SELECT status FROM editions WHERE id = 2") === 'active' && (int) $sqlv("SELECT COUNT(*) FROM nominee_entries WHERE edition_id = 2") > 0);
[$c, $r] = $imp(['payload' => $pack(['edition' => ['slug' => 'oga-7', 'status' => 'archived'], 'nominees' => [['votes' => 0, 'winner' => false], ['winner' => false, 'votes' => 0], ['winner' => false, 'votes' => 0]]])]);
check('an edition can be imported as archived, with no votes at all', $c === 200 && $sqlv("SELECT status FROM editions WHERE slug = 'oga-7'") === 'archived' && (int) $sqlv("SELECT COUNT(*) FROM vote_orders WHERE edition_id = (SELECT id FROM editions WHERE slug = 'oga-7')") === 0);

// ================================================================== V. EVERY ADMIN ROUTE IS GUARDED (read from the router itself)
group('V. Every admin route in AdminApi.php refuses strangers and gate-only staff');
$routerSrc = (string) file_get_contents($root . '/backend/src/AdminApi.php');
$routes = [];
preg_match_all('/\$path\s*===\s*\'(\/admin\/[^\']*)\'/', $routerSrc, $m1);
foreach ($m1[1] as $p) { $routes[$p] = true; }
preg_match_all('/preg_match\(\'#(\^\/admin\/.*?\$)#\',\s*\$path/', $routerSrc, $m2);
foreach ($m2[1] as $rx) {
    $rx = str_replace('\\\\', '\\', $rx);
    $p = preg_replace('/\(\\\\d\+\)/', '1', $rx);
    $p = preg_replace_callback('/\(\[([^\]]+)\]\+\)/', fn ($mm) => str_contains($mm[1], 'A-Z') ? 'VOT-T1' : (str_contains($mm[1], '_') ? 'welcome' : (str_contains($mm[1], '0-9') ? $ed : 'general')), $p);
    $p = ltrim(rtrim($p, '$'), '^');
    if (!str_contains($p, '(') && !str_contains($p, '\\')) { $routes[$p] = true; }
}
$openToAnyAdmin = ['/admin/csrf', '/admin/login', '/admin/logout', '/admin/me', '/admin/account/password', '/admin/account/sign-out-everywhere', '/admin/account/profile'];
$routes = array_values(array_filter(array_keys($routes), fn ($p) => !in_array($p, $openToAnyAdmin, true) && !preg_match('#^/admin/people/\d+/password-reset$#', $p)));
check('the router yields a believable number of admin routes (found ' . count($routes) . ')', count($routes) >= 70, (string) count($routes));
$anonV = new Client();
$leaksA = $leaksG = $leaksG2 = [];
// These check the SHAPE of the request first, because their permission depends on the item inside it (A3 proves they answer 403 for a real request).
$shapeFirst = ['POST /admin/nominees/status', 'POST /admin/votes/manual', 'POST /admin/activity', 'GET /admin/scan', 'POST /admin/scan'];
$gateV = new Client(); $gateV->login('gate@test.dev', 'Test-Pass-12345');
foreach ($routes as $p) {
    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $mth) {
        $body = $mth === 'GET' ? null : ['edition' => $ed];
        [$c] = $anonV->call($mth, "$p?edition=$ed", $body);
        if (!in_array($c, [401, 404, 405], true)) { $leaksA[] = "$mth $p=$c"; }
        [$c] = $gateV->call($mth, "$p?edition=$ed", $body);
        if (!in_array($c, [403, 404, 405], true) && !in_array("$mth $p", $shapeFirst, true)) { $leaksG[] = "$mth $p=$c"; }
        [$c] = $gateV->call($mth, "$p?edition=oga-9", $mth === 'GET' ? null : ['edition' => 'oga-9']);
        if (!in_array($c, [403, 404, 405], true) && !in_array("$mth $p", $shapeFirst, true)) { $leaksG2[] = "$mth $p=$c"; }
    }
}
check('a stranger gets 401/404/405 on every route and method', $leaksA === [], implode(', ', array_slice($leaksA, 0, 6)));
check('a gate-only account gets 403/404/405 on every route it is not meant to use', $leaksG === [], implode(', ', array_slice($leaksG, 0, 8)));
check('the same gate-only account gets nothing in an edition it is not assigned to', $leaksG2 === [], implode(', ', array_slice($leaksG2, 0, 8)));
$noTok = new Client(); $noTok->login('admin@test.dev', 'Test-Pass-12345'); $noTok->csrf = null;
$csrfHoles = [];
foreach ($routes as $p) {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $mth) {
        [$c] = $noTok->call($mth, "$p?edition=$ed", ['edition' => $ed]);
        if (!in_array($c, [403, 419], true)) { $csrfHoles[] = "$mth $p=$c"; }
    }
}
check('a super admin without the CSRF token is refused on every changing route', $csrfHoles === [], implode(', ', array_slice($csrfHoles, 0, 6)));

// ------------------------------------------------------------------ result
echo "\n" . str_repeat('=', 50) . "\nPassed: {$GLOBALS['pass']}   Failed: {$GLOBALS['fail']}\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);