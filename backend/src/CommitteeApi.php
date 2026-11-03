<?php
declare(strict_types=1);

namespace App;

/**
 * Committee recruitment. PUBLIC: the application window and the apply form.
 * ADMIN: settings, applications (every stage change is checked here), and the committee member list.
 * Being appointed puts someone on the committee list. It never gives them admin access.
 */
final class CommitteeApi
{
    private const LEVELS = ['ND I', 'ND II', 'HND I', 'HND II'];
    private const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** action => [allowed current stages, new stage] */
    private const FLOW = [
        'shortlist'  => [['submitted', 'reserve', 'reconsideration'], 'shortlisted'],
        'screen'     => [['shortlisted'], null],                     // new stage depends on the result
        'approve'    => [['screened'], 'approved'],
        'appoint'    => [['approved'], 'appointed'],
        'reserve'    => [['screened', 'unsuccessful'], 'reserve'],
        'reconsider' => [['reserve', 'unsuccessful'], 'reconsideration'],
        'withdraw'   => [['approved', 'appointed'], 'withdrawn'],
    ];

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function throttle(string $kind, int $max, int $minutes): void
    {
        $id = hash('sha256', "$kind:" . Request::ip());
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() >= $max) {
            self::fail('Too many requests. Please wait a little and try again.', 429);
        }
        self::pdo()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, 1)')->execute([$id, Request::ip()]);
    }

    private static function edition(string $slug): array
    {
        $st = self::pdo()->prepare('SELECT id, slug, status, features FROM editions WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: self::fail('That edition was not found.', 404);
    }

    private static function iso(?string $d): ?string
    {
        return $d === null ? null : (new \DateTimeImmutable($d))->format('c');
    }

    // ------------------------------------------------------------------ settings

    private static function defaults(): array
    {
        return [
            'enabled' => false, 'opensAt' => '', 'closesAt' => '', 'targetApplicants' => 100,
            'screeningDate' => '', 'screeningTime' => '', 'screeningVenue' => '', 'screeningInstructions' => '',
            'approvalMessage' => '',
            'unsuccessfulMessage' => 'Thank you for your interest. You may be considered again if a position becomes available.',
            'reserveMessage' => 'You are on the reserve list and may be contacted if a position becomes available.',
            'onboardingMessage' => '', 'whatsappGroupUrl' => '',
            'photoUploadsEnabled' => true, 'photoMaxSizeKB' => 1024, 'allowedPhotoTypes' => self::PHOTO_TYPES,
        ];
    }

    private static function config(int $editionId): array
    {
        $st = self::pdo()->prepare('SELECT * FROM committee_config WHERE edition_id = ?');
        $st->execute([$editionId]);
        $r = $st->fetch();
        $d = self::defaults();
        if (!$r) {
            return $d;
        }
        $dt = fn (?string $v) => $v === null ? '' : (new \DateTimeImmutable($v))->format('Y-m-d\TH:i');
        $types = json_decode((string) ($r['allowed_photo_types'] ?? ''), true);
        return [
            'enabled'               => (bool) $r['enabled'],
            'opensAt'               => $dt($r['opens_at']),
            'closesAt'              => $dt($r['closes_at']),
            'targetApplicants'      => (int) $r['target_applicants'],
            'screeningDate'         => (string) ($r['screening_date'] ?? ''),
            'screeningTime'         => (string) ($r['screening_time'] ?? ''),
            'screeningVenue'        => (string) ($r['screening_venue'] ?? ''),
            'screeningInstructions' => (string) ($r['screening_instructions'] ?? ''),
            'approvalMessage'       => (string) ($r['approval_message'] ?? ''),
            'unsuccessfulMessage'   => (string) ($r['unsuccessful_message'] ?? ''),
            'reserveMessage'        => (string) ($r['reserve_message'] ?? ''),
            'onboardingMessage'     => (string) ($r['onboarding_message'] ?? ''),
            'whatsappGroupUrl'      => (string) ($r['whatsapp_group_url'] ?? ''),
            'photoUploadsEnabled'   => (bool) $r['photo_uploads_enabled'],
            'photoMaxSizeKB'        => (int) $r['photo_max_kb'],
            'allowedPhotoTypes'     => is_array($types) && $types ? array_values(array_intersect($types, self::PHOTO_TYPES)) : self::PHOTO_TYPES,
        ];
    }

    /** @return array{open:bool,reason:string} decided by the database clock, never the browser's */
    private static function window(array $ed, array $c): array
    {
        $features = json_decode((string) $ed['features'], true) ?: [];
        if (empty($features['committeeRecruitment'])) {
            return ['open' => false, 'reason' => 'Committee recruitment is not running for this edition.'];
        }
        if ($ed['status'] !== 'active') {
            return ['open' => false, 'reason' => 'Applications are closed for this edition.'];
        }
        if (!$c['enabled']) {
            return ['open' => false, 'reason' => 'Applications are currently disabled.'];
        }
        if ($c['opensAt'] === '' || $c['closesAt'] === '') {
            return ['open' => false, 'reason' => 'The application dates have not been configured.'];
        }
        $now = (int) self::pdo()->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
        $tz  = new \DateTimeZone(date_default_timezone_get());
        $o   = (new \DateTimeImmutable($c['opensAt'], $tz))->getTimestamp();
        $cl  = (new \DateTimeImmutable($c['closesAt'], $tz))->getTimestamp();
        if ($now < $o) {
            return ['open' => false, 'reason' => 'Applications have not opened yet.'];
        }
        if ($now > $cl) {
            return ['open' => false, 'reason' => 'The application period has closed.'];
        }
        return ['open' => true, 'reason' => ''];
    }

    private static function received(int $editionId): int
    {
        $st = self::pdo()->prepare("SELECT COUNT(*) FROM committee_applications WHERE edition_id = ? AND status <> 'withdrawn'");
        $st->execute([$editionId]);
        return (int) $st->fetchColumn();
    }

    private static function dateTime(string $v, string $label): ?string
    {
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $v, $m) || !strtotime($m[1] . ' ' . $m[2])) {
            self::fail("Enter a valid $label.");
        }
        return $m[1] . ' ' . $m[2] . ':00';
    }

    public static function saveConfig(): never
    {
        $in    = Request::json();
        $actor = Access::require('committee.review', Request::str($in, 'edition', 60));
        $edId  = (int) $actor['edition_id'];
        $cur   = self::config($edId);

        $enabled = (bool) ($in['enabled'] ?? false);
        $opens   = self::dateTime(Request::str($in, 'opensAt', 30), 'opening date and time');
        $closes  = self::dateTime(Request::str($in, 'closesAt', 30), 'closing date and time');
        if ($enabled && ($opens === null || $closes === null)) {
            self::fail('Set both the opening and closing date before enabling recruitment.');
        }
        if ($opens !== null && $closes !== null && strtotime($closes) <= strtotime($opens)) {
            self::fail('The closing date must be after the opening date.');
        }
        $target = (int) ($in['targetApplicants'] ?? 0);
        if ($target < 1 || $target > 100000) {
            self::fail('The applicant target must be at least 1.');
        }
        $maxKb = (int) ($in['photoMaxSizeKB'] ?? 0);
        if ($maxKb < 50 || $maxKb > 5120) {
            self::fail('Photo size must be between 50 KB and 5 MB.');
        }
        $date = Request::str($in, 'screeningDate', 10);
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            self::fail('Enter a valid screening date.');
        }
        $time = Request::str($in, 'screeningTime', 5);
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) {
            self::fail('Enter a valid screening time.');
        }
        $url = Request::str($in, 'whatsappGroupUrl', 255);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            self::fail('The WhatsApp group link must start with https://');
        }
        $text = fn (string $k, int $max = 2000) => Request::str($in, $k, $max);

        self::pdo()->prepare(
            'INSERT INTO committee_config (edition_id, enabled, opens_at, closes_at, target_applicants, screening_date, screening_time, screening_venue,
                 screening_instructions, approval_message, unsuccessful_message, reserve_message, onboarding_message, whatsapp_group_url,
                 photo_uploads_enabled, photo_max_kb, allowed_photo_types)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), opens_at = VALUES(opens_at), closes_at = VALUES(closes_at),
                 target_applicants = VALUES(target_applicants), screening_date = VALUES(screening_date), screening_time = VALUES(screening_time),
                 screening_venue = VALUES(screening_venue), screening_instructions = VALUES(screening_instructions),
                 approval_message = VALUES(approval_message), unsuccessful_message = VALUES(unsuccessful_message),
                 reserve_message = VALUES(reserve_message), onboarding_message = VALUES(onboarding_message),
                 whatsapp_group_url = VALUES(whatsapp_group_url), photo_uploads_enabled = VALUES(photo_uploads_enabled),
                 photo_max_kb = VALUES(photo_max_kb), allowed_photo_types = VALUES(allowed_photo_types)'
        )->execute([
            $edId, $enabled ? 1 : 0, $opens, $closes, $target, $date === '' ? null : $date, $time === '' ? null : $time,
            $text('screeningVenue', 255), $text('screeningInstructions'), $text('approvalMessage'), $text('unsuccessfulMessage'),
            $text('reserveMessage'), $text('onboardingMessage'), $url,
            (bool) ($in['photoUploadsEnabled'] ?? false) ? 1 : 0, $maxKb, json_encode($cur['allowedPhotoTypes']),
        ]);
        Audit::log((int) $actor['id'], 'Updated committee recruitment settings', null, $edId);
        Response::json(['ok' => true, 'config' => self::config($edId)]);
    }

    // ------------------------------------------------------------------ public

    public static function publicWindow(): never
    {
        $ed = self::edition((string) ($_GET['edition'] ?? ''));
        if ($ed['status'] === 'draft') {
            self::fail('That edition was not found.', 404);
        }
        $c = self::config((int) $ed['id']);
        $w = self::window($ed, $c);
        header('Cache-Control: no-store');
        Response::json(['ok' => true, 'open' => $w['open'], 'reason' => $w['reason'], 'received' => self::received((int) $ed['id']), 'config' => [
            'closesAt' => $c['closesAt'], 'targetApplicants' => $c['targetApplicants'], 'photoUploadsEnabled' => $c['photoUploadsEnabled'],
            'photoMaxSizeKB' => $c['photoMaxSizeKB'], 'allowedPhotoTypes' => $c['allowedPhotoTypes'],
        ]]);
    }

    public static function apply(): never
    {
        self::throttle('committee-apply', 20, 60);
        $in = $_POST;                                              // multipart form (so a photo can come with it)
        $ed = self::edition((string) ($in['editionId'] ?? ''));
        if ($ed['status'] === 'draft') {
            self::fail('That edition was not found.', 404);
        }
        $edId = (int) $ed['id'];
        $c = self::config($edId);
        $w = self::window($ed, $c);
        if (!$w['open']) {
            self::fail($w['reason'] ?: 'Applications are closed.');
        }

        $name  = Request::str($in, 'name', 150);
        $email = strtolower(Request::str($in, 'email', 190));
        $phone = Request::str($in, 'phone', 30);
        $dept  = Request::str($in, 'department', 150);
        $level = Request::str($in, 'level', 30);
        $unit  = (int) ($in['unitId'] ?? 0);
        $why   = Request::str($in, 'motivation', 3000);

        if (mb_strlen($name) < 2) { self::fail('Your name must contain at least 2 characters.'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { self::fail('Enter a valid email address.'); }
        if (!preg_match('/^[0-9+()\s-]{7,30}$/', $phone)) { self::fail('Enter a valid phone number.'); }
        if ($dept === '') { self::fail('Enter your department.'); }
        if (!in_array($level, self::LEVELS, true)) { self::fail('Choose your level.'); }
        if (mb_strlen($why) < 30) { self::fail('Your motivation statement must contain at least 30 characters.'); }
        $q = self::pdo()->prepare('SELECT COUNT(*) FROM committee_units WHERE id = ?');
        $q->execute([$unit]);
        if ($unit < 1 || (int) $q->fetchColumn() === 0) { self::fail('Choose your preferred committee unit.'); }

        $photo = null;
        $file  = $_FILES['photo'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (!$c['photoUploadsEnabled']) { self::fail('Photo uploads are currently disabled.'); }
            $mime = is_uploaded_file($file['tmp_name'] ?? '') ? (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
            if (!in_array($mime, $c['allowedPhotoTypes'], true)) { self::fail('Upload a JPG, PNG or WebP image.'); }
            if ((int) $file['size'] > $c['photoMaxSizeKB'] * 1024) { self::fail('Your photo must not exceed ' . $c['photoMaxSizeKB'] . ' KB.'); }
        } else {
            $file = null;
        }

        $dup = self::pdo()->prepare("SELECT COUNT(*) FROM committee_applications WHERE edition_id = ? AND email = ? AND status <> 'withdrawn'");
        $dup->execute([$edId, $email]);
        if ((int) $dup->fetchColumn() > 0) {
            self::fail('An application with this email already exists for this edition.');
        }
        if (self::received($edId) >= max($c['targetApplicants'] * 5, 500)) {
            self::fail('We are no longer accepting applications.');          // safety stop: far beyond the target
        }

        if ($file) {
            $photo = Photos::save($file, 'committee-applicants');
        }
        try {
            $id = Database::transaction(function (\PDO $db) use ($edId, $name, $email, $phone, $dept, $level, $unit, $why, $photo) {
                $db->prepare('INSERT INTO committee_applications (edition_id, name, email, phone, department, level, unit_id, motivation, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$edId, $name, $email, $phone, $dept, $level, $unit, $why, $photo]);
                $id = (int) $db->lastInsertId();
                $db->prepare("INSERT INTO committee_application_history (application_id, stage, note) VALUES (?, 'submitted', 'Application submitted.')")->execute([$id]);
                Outbox::queueAndSend('committee_application_received', $email, ['applicationId' => (string) $id, 'applicantName' => $name], $edId);
                return $id;
            });
        } catch (\PDOException $e) {
            Photos::delete($photo);
            if ($e->getCode() === '23000') {                                  // two submissions at the same moment
                self::fail('An application with this email already exists for this edition.');
            }
            throw $e;
        }
        Audit::log(null, 'Committee application received', $email, $edId);
        Response::json(['ok' => true, 'applicationId' => (string) $id]);
    }

    // ------------------------------------------------------------------ admin: read

    private static function appShape(array $a, array $history): array
    {
        $sr = json_decode((string) ($a['screening_result'] ?? ''), true);
        return [
            'id'                => (string) $a['id'],
            'editionId'         => $a['edition_slug'],
            'name'              => $a['name'],
            'email'             => $a['email'],
            'phone'             => $a['phone'],
            'department'        => $a['department'],
            'level'             => $a['level'],
            'unitId'            => (string) $a['unit_id'],
            'motivation'        => $a['motivation'],
            'photo'             => $a['photo_path'],
            'thumb'             => Photos::thumbOf($a['photo_path']),
            'status'            => $a['status'],
            'screening'         => json_decode((string) ($a['screening'] ?? ''), true),
            'screeningResult'   => is_array($sr) ? ($sr['result'] ?? null) : null,
            'screeningNote'     => is_array($sr) ? ($sr['note'] ?? '') : '',
            'decision'          => $a['decision'],
            'decisionReason'    => (string) ($a['decision_reason'] ?? ''),
            'onboardingMessage' => (string) ($a['onboarding_message'] ?? ''),
            'appointedPersonId' => $a['appointed_person_id'] === null ? null : (string) $a['appointed_person_id'],
            'appointedAt'       => self::iso($a['appointed_at']),
            'createdAt'         => self::iso($a['created_at']),
            'updatedAt'         => self::iso($a['updated_at']),
            'history'           => $history,
        ];
    }

    public static function overview(): never
    {
        $slug  = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('committee.view', $slug);
        $edId  = (int) $actor['edition_id'];
        $pdo   = self::pdo();

        $st = $pdo->prepare('SELECT a.*, ? AS edition_slug FROM committee_applications a WHERE a.edition_id = ? ORDER BY a.id DESC LIMIT 5000');
        $st->execute([$slug, $edId]);
        $apps = $st->fetchAll();

        $hist = [];
        $st = $pdo->prepare('SELECT h.application_id, h.stage, h.note, h.created_at, p.name AS actor FROM committee_application_history h
                               JOIN committee_applications a ON a.id = h.application_id AND a.edition_id = ?
                               LEFT JOIN people p ON p.id = h.actor_id ORDER BY h.id');
        $st->execute([$edId]);
        foreach ($st->fetchAll() as $h) {
            $hist[$h['application_id']][] = ['stage' => $h['stage'], 'at' => self::iso($h['created_at']), 'actor' => $h['actor'] ?? 'Applicant', 'note' => (string) ($h['note'] ?? '')];
        }

        $st = $pdo->prepare(
            "SELECT a.id, a.person_id, a.position, a.unit_id, a.visible, p.name, p.photo_path,
                    (SELECT COUNT(*) FROM assignment_roles r WHERE r.assignment_id = a.id)
                  + (SELECT COUNT(*) FROM assignment_overrides o WHERE o.assignment_id = a.id AND o.effect = 'grant') AS access_count
               FROM edition_assignments a JOIN people p ON p.id = a.person_id
              WHERE a.edition_id = ? AND a.position <> '' ORDER BY a.id"
        );
        $st->execute([$edId]);
        $members = array_map(fn ($m) => [
            'id' => (string) $m['id'], 'personId' => (string) $m['person_id'], 'name' => $m['name'], 'position' => $m['position'],
            'unitId' => $m['unit_id'] === null ? '' : (string) $m['unit_id'], 'visible' => (bool) $m['visible'],
            'hasAccess' => (int) $m['access_count'] > 0, 'photo' => $m['photo_path'], 'thumb' => Photos::thumbOf($m['photo_path']),
        ], $st->fetchAll());

        $people = [];
        if (Access::can($actor, 'committee.manage', $edId)) {
            $taken = array_column($members, 'personId');
            foreach ($pdo->query('SELECT id, name FROM people WHERE active = 1 ORDER BY name')->fetchAll() as $p) {
                if (!in_array((string) $p['id'], $taken, true)) {
                    $people[] = ['id' => (string) $p['id'], 'name' => $p['name']];
                }
            }
        }

        header('Cache-Control: no-store');
        Response::json(['ok' => true, 'config' => ['editionId' => $slug] + self::config($edId),
            'applications' => array_map(fn ($a) => self::appShape($a, $hist[$a['id']] ?? []), $apps),
            'members' => $members, 'people' => $people]);
    }

    // ------------------------------------------------------------------ admin: applications

    private static function unitName(int $id): string
    {
        $q = self::pdo()->prepare('SELECT name FROM committee_units WHERE id = ?');
        $q->execute([$id]);
        return (string) ($q->fetchColumn() ?: '');
    }

    public static function act(int $id): never
    {
        $in  = Request::json();
        $pdo = self::pdo();
        $st  = $pdo->prepare('SELECT a.*, e.slug AS edition_slug FROM committee_applications a JOIN editions e ON e.id = a.edition_id WHERE a.id = ?');
        $st->execute([$id]);
        $app = $st->fetch() ?: self::fail('That application was not found.', 404);
        $actor = Access::require('committee.review', $app['edition_slug']);      // checked in the application's own edition
        $edId  = (int) $app['edition_id'];

        $action = Request::str($in, 'action', 20);
        if (!isset(self::FLOW[$action])) {
            self::fail('That action is not recognised.');
        }
        $note = Request::str($in, 'note', 1000);
        $cfg  = self::config($edId);

        Database::transaction(function (\PDO $db) use ($id, $in, $action, $note, $cfg, $edId, $actor) {
            $st = $db->prepare('SELECT * FROM committee_applications WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $a = $st->fetch();
            [$from, $to] = self::FLOW[$action];
            if (!in_array($a['status'], $from, true)) {
                self::fail('This applicant is now at the "' . $a['status'] . '" stage, so that action is no longer available. Refresh the page.', 409);
            }
            $name  = $a['name'];
            $email = $a['email'];
            $set   = [];
            $params = [];
            $mail  = null;
            $historyNote = '';

            switch ($action) {
                case 'shortlist':
                    if ($cfg['screeningDate'] === '' || $cfg['screeningTime'] === '' || trim($cfg['screeningVenue']) === '') {
                        self::fail('Set the screening date, time and venue in Recruitment settings first.');
                    }
                    $sched = ['date' => $cfg['screeningDate'], 'time' => $cfg['screeningTime'], 'venue' => $cfg['screeningVenue'], 'instructions' => $cfg['screeningInstructions']];
                    $set = ['screening = ?', 'screening_result = NULL', 'decision = NULL'];
                    $params = [json_encode($sched)];
                    $mail = ['committee_shortlisted', $sched + ['applicantName' => $name, 'message' => $note !== '' ? $note : 'You have been shortlisted for the committee screening exercise.']];
                    $historyNote = 'Shortlisted for screening';
                    break;

                case 'screen':
                    $result = Request::str($in, 'result', 10);
                    if (!in_array($result, ['passed', 'failed', 'pending'], true)) {
                        self::fail('Choose a screening outcome.');
                    }
                    $to = ['passed' => 'screened', 'failed' => 'unsuccessful', 'pending' => 'shortlisted'][$result];
                    $set = ['screening_result = ?'];
                    $params = [json_encode(['result' => $result, 'note' => $note])];
                    if ($result === 'failed') {
                        $set[] = 'decision = ?';
                        $params[] = 'unsuccessful';
                        $mail = ['committee_unsuccessful', ['applicantName' => $name, 'message' => $cfg['unsuccessfulMessage']]];
                    }
                    $historyNote = ['passed' => 'Screening passed', 'failed' => 'Did not pass screening', 'pending' => 'Screening requires further review'][$result];
                    break;

                case 'approve':
                    $r = json_decode((string) $a['screening_result'], true);
                    if (!is_array($r) || ($r['result'] ?? '') !== 'passed') {
                        self::fail('Only applicants who passed screening can be approved.');
                    }
                    $msg = Request::str($in, 'message', 2000);
                    $onb = Request::str($in, 'onboarding', 2000);
                    $set = ['decision = ?', 'decision_reason = ?', 'onboarding_message = ?'];
                    $params = ['approved', $msg, $onb];
                    $mail = ['committee_approved', ['applicantName' => $name, 'message' => $msg, 'onboarding' => $onb, 'whatsappGroupUrl' => $cfg['whatsappGroupUrl']]];
                    $historyNote = 'Approved after screening';
                    break;

                case 'appoint':
                    $position = mb_substr(trim(Request::str($in, 'position', 100)), 0, 100);
                    $unit     = (int) ($in['unitId'] ?? 0);
                    if ($position === '') { self::fail('Enter the committee position.'); }
                    $uname = self::unitName($unit);
                    if ($uname === '') { self::fail('Choose a committee unit.'); }
                    $visible = (int) (bool) ($in['visible'] ?? true);

                    $q = $db->prepare('SELECT id, active FROM people WHERE email = ?');
                    $q->execute([$email]);
                    $person = $q->fetch();
                    if ($person && !(int) $person['active']) {
                        self::fail('This person is inactive in Team & Access. Activate them before appointment.');
                    }
                    if ($person) {
                        $pid = (int) $person['id'];
                    } else {
                        $db->prepare('INSERT INTO people (name, email, active) VALUES (?, ?, 1)')->execute([$name, $email]);
                        $pid = (int) $db->lastInsertId();
                    }
                    // Position, unit and visibility only. Roles and permissions are never touched.
                    $q = $db->prepare('SELECT id FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
                    $q->execute([$pid, $edId]);
                    if ($aid = $q->fetchColumn()) {
                        $db->prepare('UPDATE edition_assignments SET position = ?, unit_id = ?, visible = ? WHERE id = ?')->execute([$position, $unit, $visible, $aid]);
                    } else {
                        $db->prepare('INSERT INTO edition_assignments (person_id, edition_id, position, unit_id, visible) VALUES (?, ?, ?, ?, ?)')->execute([$pid, $edId, $position, $unit, $visible]);
                    }
                    $set = ['decision = ?', 'appointed_person_id = ?', 'appointed_at = NOW()'];
                    $params = ['appointed', $pid];
                    $mail = ['committee_appointed', ['applicantName' => $name, 'position' => $position, 'unitName' => $uname, 'message' => 'Congratulations. You have been appointed to the committee.']];
                    $historyNote = "Appointed as $position";
                    break;

                case 'reserve':
                    $msg = $note !== '' ? $note : $cfg['reserveMessage'];
                    $set = ['decision = ?', 'decision_reason = ?'];
                    $params = ['reserve', $msg];
                    $mail = ['committee_reserve', ['applicantName' => $name, 'message' => $msg]];
                    $historyNote = 'Placed on reserve list';
                    break;

                case 'reconsider':
                    if ($note === '') { self::fail('Enter the reason for reconsideration.'); }
                    $set = ['decision = NULL'];
                    $historyNote = 'Reconsideration: ' . $note;
                    break;

                case 'withdraw':
                    if ($note === '') { self::fail('Enter the reason or notes for the withdrawal.'); }
                    $set = ['decision_reason = ?'];
                    $params = [$note];
                    if ($a['appointed_person_id'] !== null) {
                        // Leaves the committee list; any admin access they hold is kept.
                        $db->prepare("UPDATE edition_assignments SET position = '', unit_id = NULL, visible = 0 WHERE person_id = ? AND edition_id = ?")->execute([$a['appointed_person_id'], $edId]);
                        Cache::flush();
                    }
                    $historyNote = 'Withdrew or forfeited their position: ' . $note;
                    break;
            }

            $set[] = 'status = ?';
            $params[] = $to;
            $params[] = $id;
            $db->prepare('UPDATE committee_applications SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
            $db->prepare('INSERT INTO committee_application_history (application_id, stage, actor_id, note) VALUES (?, ?, ?, ?)')
                ->execute([$id, $to, $actor['id'], mb_substr($historyNote, 0, 1000)]);
            if ($mail) {
                Outbox::queueAndSend($mail[0], $email, $mail[1], $edId);
            }
            Audit::log((int) $actor['id'], 'Committee applicant: ' . $action, $name, $edId, ['to' => $to]);
            if ($action === 'appoint') {
                Cache::flush();                                          // the public committee page changes
            }
        });
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ admin: members

    private static function member(int $assignmentId, string $perm): array
    {
        $st = self::pdo()->prepare('SELECT a.*, e.slug AS edition_slug, p.name FROM edition_assignments a JOIN editions e ON e.id = a.edition_id JOIN people p ON p.id = a.person_id WHERE a.id = ?');
        $st->execute([$assignmentId]);
        $m = $st->fetch() ?: self::fail('That committee member was not found.', 404);
        return $m + ['actor' => Access::require($perm, $m['edition_slug'])];
    }

    private static function position(array $in): array
    {
        $pos = mb_substr(trim(Request::str($in, 'position', 100)), 0, 100);
        if ($pos === '') { self::fail('Enter the position.'); }
        $unit = (int) ($in['unitId'] ?? 0);
        if (self::unitName($unit) === '') { self::fail('Choose a unit.'); }
        return [$pos, $unit, (int) (bool) ($in['visible'] ?? false)];
    }

    public static function addMember(): never
    {
        $in    = Request::json();
        $actor = Access::require('committee.manage', Request::str($in, 'edition', 60));
        $edId  = (int) $actor['edition_id'];
        [$pos, $unit, $visible] = self::position($in);
        $pdo = self::pdo();

        $pid = (string) ($in['personId'] ?? 'new');
        if ($pid === 'new') {
            $name = Request::str($in, 'name', 150);
            if (mb_strlen($name) < 2) { self::fail('Enter the person’s name.'); }
            $pdo->prepare('INSERT INTO people (name, email, active) VALUES (?, NULL, 1)')->execute([$name]);
            $personId = (int) $pdo->lastInsertId();
        } else {
            $q = $pdo->prepare('SELECT id, name, active FROM people WHERE id = ?');
            $q->execute([(int) $pid]);
            $p = $q->fetch() ?: self::fail('That person was not found.', 404);
            if (!(int) $p['active']) { self::fail('That person is inactive.'); }
            $personId = (int) $p['id'];
            $name = $p['name'];
        }
        $q = $pdo->prepare('SELECT id, position FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
        $q->execute([$personId, $edId]);
        $existing = $q->fetch();
        if ($existing && $existing['position'] !== '') { self::fail('That person is already on this edition’s committee.'); }
        if ($existing) {
            $pdo->prepare('UPDATE edition_assignments SET position = ?, unit_id = ?, visible = ? WHERE id = ?')->execute([$pos, $unit, $visible, $existing['id']]);
        } else {
            $pdo->prepare('INSERT INTO edition_assignments (person_id, edition_id, position, unit_id, visible) VALUES (?, ?, ?, ?, ?)')->execute([$personId, $edId, $pos, $unit, $visible]);
        }
        Cache::flush();
        Audit::log((int) $actor['id'], 'Added committee member', $name, $edId, ['position' => $pos]);
        Response::json(['ok' => true]);
    }

    public static function editMember(int $id): never
    {
        $in = Request::json();
        $m  = self::member($id, 'committee.manage');
        [$pos, $unit, $visible] = self::position($in);
        self::pdo()->prepare('UPDATE edition_assignments SET position = ?, unit_id = ?, visible = ? WHERE id = ?')->execute([$pos, $unit, $visible, $id]);
        Cache::flush();
        Audit::log((int) $m['actor']['id'], 'Edited committee member', $m['name'], (int) $m['edition_id'], ['position' => $pos]);
        Response::json(['ok' => true]);
    }

    public static function removeMember(int $id): never
    {
        $m   = self::member($id, 'committee.manage');
        $pdo = self::pdo();
        $q = $pdo->prepare("SELECT (SELECT COUNT(*) FROM assignment_roles WHERE assignment_id = ?) + (SELECT COUNT(*) FROM assignment_overrides WHERE assignment_id = ?)");
        $q->execute([$id, $id]);
        if ((int) $q->fetchColumn() > 0) {                       // keeps their admin access; only leaves the committee list
            $pdo->prepare("UPDATE edition_assignments SET position = '', unit_id = NULL, visible = 0 WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare('DELETE FROM edition_assignments WHERE id = ?')->execute([$id]);
        }
        Cache::flush();
        Audit::log((int) $m['actor']['id'], 'Removed committee member', $m['name'], (int) $m['edition_id']);
        Response::json(['ok' => true]);
    }
}