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
foreach (['na', 'nb'] as $n) {
    $db->prepare('INSERT INTO accounts (email,password_hash,email_verified_at) VALUES (?,?,NOW())')->execute(["$n@test.dev", $hash]);
    $db->prepare("INSERT INTO nominee_entries (edition_id,account_id,category_id,name,status) VALUES (2,?,2,?, 'pending')")->execute([$db->lastInsertId(), "Nominee $n"]);
    $entry[$n] = (int) $db->lastInsertId();
}
$db->exec("INSERT INTO vote_orders (edition_id,reference,nominee_entry_id,category_id,votes,amount,voter_name,voter_email,status,source) VALUES
  (2,'VOT-T1',{$entry['na']},2,10,500,'A','a@x.test','paid','payment'),(2,'VOT-T2',{$entry['na']},2,5,250,'B','b@x.test','paid','payment'),(2,'VOT-T3',{$entry['na']},2,99,0,'C','c@x.test','pending','payment')");
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
$apply = fn (array $over = [], ?string $file = null, string $mime = 'image/png') => (new Client())->call('POST', '/committee/applications', array_merge($over, $form, $over) + ($file ? ['photo' => new CURLFile($file, $mime, 'p')] : []), [], true);
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

// ------------------------------------------------------------------ result
echo "\n" . str_repeat('=', 50) . "\nPassed: {$GLOBALS['pass']}   Failed: {$GLOBALS['fail']}\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);