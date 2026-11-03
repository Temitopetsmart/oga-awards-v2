<?php
declare(strict_types=1);

namespace App;

final class CommitteeRecruitmentApi
{
    public static function saveConfig(): never {
        $in = Request::json();
        $edition = Request::str($in, 'edition', 60);
        $actor = Access::require('committee.review', $edition);
        $config = (array) ($in['config'] ?? []);

        $enabled = !empty($config['enabled']) ? 1 : 0;
        $opensAt = self::dateTime($config['opensAt'] ?? '');
        $closesAt = self::dateTime($config['closesAt'] ?? '');

        if ($enabled && (!$opensAt || !$closesAt)) {
            Response::error('Set both application dates before enabling recruitment.');
        }

        if ($opensAt && $closesAt && $closesAt <= $opensAt) {
            Response::error('The closing date must be after the opening date.');
        }

        $target = (int) ($config['targetApplicants'] ?? 100);
        $photoMax = (int) ($config['photoMaxSizeKB'] ?? 1024);

        if ($target < 1) {
            Response::error('The applicant target must be at least 1.');
        }

        if ($photoMax < 50 || $photoMax > 10240) {
            Response::error('Photo size must be between 50 KB and 10 MB.');
        }

        $screeningDate = self::dateOnly($config['screeningDate'] ?? '');

        $allowed = array_values(array_intersect(
            (array) ($config['allowedPhotoTypes'] ?? [
                'image/jpeg',
                'image/png',
                'image/webp'
            ]),
            ['image/jpeg', 'image/png', 'image/webp']
        ));

        $pdo = Database::pdo();

        $sql = 'INSERT INTO committee_config (
            edition_id, enabled, opens_at, closes_at,
            target_applicants, screening_date, screening_time,
            screening_venue, screening_instructions,
            approval_message, unsuccessful_message, reserve_message,
            onboarding_message, whatsapp_group_url,
            photo_uploads_enabled, photo_max_kb, allowed_photo_types
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            opens_at = VALUES(opens_at),
            closes_at = VALUES(closes_at),
            target_applicants = VALUES(target_applicants),
            screening_date = VALUES(screening_date),
            screening_time = VALUES(screening_time),
            screening_venue = VALUES(screening_venue),
            screening_instructions = VALUES(screening_instructions),
            approval_message = VALUES(approval_message),
            unsuccessful_message = VALUES(unsuccessful_message),
            reserve_message = VALUES(reserve_message),
            onboarding_message = VALUES(onboarding_message),
            whatsapp_group_url = VALUES(whatsapp_group_url),
            photo_uploads_enabled = VALUES(photo_uploads_enabled),
            photo_max_kb = VALUES(photo_max_kb),
            allowed_photo_types = VALUES(allowed_photo_types)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $actor['edition_id'],
            $enabled,
            $opensAt,
            $closesAt,
            $target,
            $screeningDate,
            Request::str($config, 'screeningTime', 20),
            Request::str($config, 'screeningVenue', 255),
            Request::str($config, 'screeningInstructions', 10000),
            Request::str($config, 'approvalMessage', 10000),
            Request::str($config, 'unsuccessfulMessage', 10000),
            Request::str($config, 'reserveMessage', 10000),
            Request::str($config, 'onboardingMessage', 10000),
            Request::str($config, 'whatsappGroupUrl', 255),
            !empty($config['photoUploadsEnabled']) ? 1 : 0,
            $photoMax,
            json_encode($allowed),
        ]);

        Audit::log(
            (int) $actor['id'],
            'Updated committee recruitment settings',
            $edition,
            (int) $actor['edition_id']
        );

        Response::json(['ok' => true]);
    }

    public static function listApplications(): never {
        $edition = trim((string) ($_GET['edition'] ?? ''));

        if ($edition === '') {
            Response::error('A valid edition is required.');
        }

        $actor = Access::require('committee.view', $edition);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT ca.*, cu.name AS unit_name
            FROM committee_applications ca
            JOIN committee_units cu ON cu.id = ca.unit_id
            WHERE ca.edition_id = ?
            ORDER BY ca.created_at DESC'
        );

        $stmt->execute([$actor['edition_id']]);
        $rows = $stmt->fetchAll();

        $applications = [];

        foreach ($rows as $row) {
            $historyStmt = $pdo->prepare(
                'SELECT h.stage, h.note, h.created_at, p.name AS actor
                FROM committee_application_history h
                LEFT JOIN people p ON p.id = h.actor_id
                WHERE h.application_id = ?
                ORDER BY h.created_at ASC'
            );

            $historyStmt->execute([$row['id']]);

            $screening = json_decode($row['screening'] ?? '', true) ?: null;
            $result = json_decode($row['screening_result'] ?? '', true) ?: null;

            $applications[] = [
                'id' => (string) $row['id'],
                'editionId' => $edition,
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'department' => $row['department'],
                'level' => $row['level'],
                'unitId' => (string) $row['unit_id'],
                'unitName' => $row['unit_name'],
                'motivation' => $row['motivation'],
                'photo' => $row['photo_path'],
                'status' => $row['status'],
                'screening' => $screening,
                'screeningResult' => $result['result'] ?? null,
                'screeningNote' => $result['note'] ?? '',
                'decision' => $row['decision'],
                'decisionReason' => $row['decision_reason'],
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at'],
                'history' => array_map(
                    fn ($item) => [
                        'stage' => $item['stage'],
                        'at' => $item['created_at'],
                        'note' => $item['note'],
                        'actor' => $item['actor'],
                    ],
                    $historyStmt->fetchAll()
                ),
            ];
        }

        Response::json([
            'ok' => true,
            'editionId' => $edition,
            'applications' => $applications,
        ]);
    }

    private static function dateTime(mixed $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $value,
            new \DateTimeZone('Africa/Lagos')
        );

        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            Response::error('Enter a valid application date and time.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private static function dateOnly(mixed $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            Response::error('Enter a valid screening date.');
        }

        return $value;
    }
}