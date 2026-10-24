<?php
declare(strict_types=1);

namespace App;

/** Append-only record of who did what. Call this for every sensitive admin action. */
final class Audit
{
    public static function log(?int $personId, string $action, ?string $target = null, ?int $editionId = null, ?array $details = null): void
    {
        Database::pdo()->prepare(
            'INSERT INTO audit_log (edition_id, person_id, action, target, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $editionId,
            $personId,
            mb_substr($action, 0, 150),
            $target === null ? null : mb_substr($target, 0, 255),
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            Request::ip(),
        ]);
    }
}