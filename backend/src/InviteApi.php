<?php
declare(strict_types=1);

namespace App;

/**
 * Admin invitations. The emailed link holds a random token; only its SHA-256 hash is stored,
 * so a database leak cannot be used to accept an invitation. Tokens work once and expire.
 */
final class InviteApi
{
    private const MAX_FAILS_PER_IP = 20;       // wrong tokens / bad forms per 15 minutes

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    private static function pdo(): \PDO
    {
        return Database::pdo();
    }

    private static function slugOf(string $roleId): string
    {
        return preg_replace('/^role-/', '', $roleId);
    }

    // ------------------------------------------------------------ admin side

    public static function create(): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $pdo = self::pdo();

        $email = mb_strtolower(Request::str($in, 'email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Enter a valid email address.');
        }
        $st = $pdo->prepare('SELECT id, name, edition_label FROM editions WHERE slug = ?');
        $st->execute([Request::str($in, 'editionId', 60)]);
        $ed = $st->fetch() ?: self::fail('That edition was not found.', 404);

        $slugs = array_values(array_unique(array_map(fn ($r) => self::slugOf((string) $r), (array) ($in['roleIds'] ?? []))));
        TeamApi::assertGrantable($actor, (int) $ed['id'], $slugs);

        $st = $pdo->prepare("SELECT COUNT(*) FROM invitations WHERE email = ? AND edition_id = ? AND status = 'pending' AND expires_at > NOW()");
        $st->execute([$email, $ed['id']]);
        if ((int) $st->fetchColumn() > 0) {
            self::fail('There is already a pending invitation for this email.');
        }

        $days    = max(1, min(30, (int) ($in['expiresInDays'] ?? 7)));
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + $days * 86400);

        $id = Database::transaction(function (\PDO $db) use ($token, $email, $ed, $actor, $expires, $slugs) {
            $db->prepare('INSERT INTO invitations (token_hash, email, edition_id, invited_by, status, expires_at) VALUES (?, ?, ?, ?, \'pending\', ?)')
                ->execute([hash('sha256', $token), $email, $ed['id'], $actor['id'], $expires]);
            $id = (int) $db->lastInsertId();
            foreach ($slugs as $slug) {
                $db->prepare('INSERT INTO invitation_roles (invitation_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $slug]);
            }
            return $id;
        });

        Audit::log((int) $actor['id'], 'Created admin invitation', "$email · {$ed['name']} {$ed['edition_label']}", (int) $ed['id'], ['roles' => $slugs]);
        Response::json(['ok' => true, 'invitation' => [
            'id'        => (string) $id,
            'token'     => $token,                         // shown once; only the hash is stored
            'email'     => $email,
            'editionId' => Request::str($in, 'editionId', 60),
            'roleIds'   => array_map(fn ($s) => 'role-' . $s, $slugs),
            'status'    => 'pending',
            'expiresAt' => (new \DateTimeImmutable($expires))->format('c'),
        ]]);
    }

    public static function list(): never
    {
        $edition = (string) ($_GET['edition'] ?? '');
        $actor = Access::require('team.view', $edition);
        $st = self::pdo()->prepare(
            "SELECT i.id, i.email, i.status, i.expires_at, i.accepted_at, i.created_at, p.name AS invited_by
               FROM invitations i
               JOIN editions e ON e.id = i.edition_id AND e.slug = ?
               LEFT JOIN people p ON p.id = i.invited_by
              ORDER BY i.id DESC LIMIT 200"
        );
        $st->execute([$edition]);
        $rows = array_map(fn ($r) => [
            'id'        => (string) $r['id'],
            'email'     => $r['email'],
            'status'    => $r['status'] === 'pending' && strtotime($r['expires_at']) <= time() ? 'expired' : $r['status'],
            'invitedBy' => $r['invited_by'] ?? 'Admin',
            'createdAt' => (new \DateTimeImmutable($r['created_at']))->format('c'),
            'expiresAt' => (new \DateTimeImmutable($r['expires_at']))->format('c'),
        ], $st->fetchAll());
        Response::json(['ok' => true, 'invitations' => $rows]);
    }

    public static function revoke(int $id): never
    {
        $in = Request::json();
        $actor = Access::require('team.manage', Request::str($in, 'edition', 60));
        $st = self::pdo()->prepare("UPDATE invitations SET status = 'revoked' WHERE id = ? AND status = 'pending'");
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            self::fail('That invitation is not pending.');
        }
        Audit::log((int) $actor['id'], 'Revoked admin invitation', '#' . $id, $actor['edition_id']);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------ public side

    /** Find a still-usable invitation by its token (and mark it expired if its time is up). */
    private static function open(string $token, bool $lock = false): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = self::pdo()->prepare(
            'SELECT i.*, e.name AS edition_name, e.edition_label FROM invitations i JOIN editions e ON e.id = i.edition_id
              WHERE i.token_hash = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $st->execute([hash('sha256', $token)]);
        $inv = $st->fetch();
        if (!$inv || $inv['status'] !== 'pending') {
            return null;
        }
        if (strtotime($inv['expires_at']) <= time()) {
            self::pdo()->prepare("UPDATE invitations SET status = 'expired' WHERE id = ?")->execute([$inv['id']]);
            return null;
        }
        return $inv;
    }

    private static function accountState(string $email): array
    {
        $st = self::pdo()->prepare('SELECT id, active, (password_hash IS NOT NULL) AS has_password FROM people WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: [];
    }

    public static function info(string $token): never
    {
        $inv = self::open($token);
        if ($inv === null) {
            Response::json(['ok' => true, 'valid' => false]);
        }
        $acc = self::accountState($inv['email']);
        Response::json(['ok' => true, 'valid' => true,
            'email'         => $inv['email'],
            'editionName'   => $inv['edition_name'],
            'editionLabel'  => $inv['edition_label'],
            'expiresAt'     => (new \DateTimeImmutable($inv['expires_at']))->format('c'),
            'needsPassword' => !$acc || !$acc['has_password'],
        ]);
    }

    public static function accept(): never
    {
        $in    = Request::json();
        $pdo   = self::pdo();
        $ip    = Request::ip();
        $ident = hash('sha256', 'invite-accept');

        $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND ip_address = ? AND success = 0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
        $st->execute([$ident, $ip]);
        if ((int) $st->fetchColumn() >= self::MAX_FAILS_PER_IP) {
            self::fail('Too many attempts. Please wait 15 minutes and try again.', 429);
        }

        $token    = Request::str($in, 'token', 100);
        $name     = Request::str($in, 'name', 150);
        $email    = mb_strtolower(Request::str($in, 'email', 190));
        $password = (string) ($in['password'] ?? '');

        try {
            $result = Database::transaction(function (\PDO $db) use ($token, $name, $email, $password) {
                $inv = self::open($token, true);
                if ($inv === null) {
                    throw new \DomainException('This invitation link is invalid, expired, revoked, or already used.', 422);
                }
                if ($email !== $inv['email']) {
                    throw new \DomainException('The email does not match the invitation.', 422);
                }
                if (mb_strlen($name) < 2) {
                    throw new \DomainException('Enter your full name.', 422);
                }

                $acc = self::accountState($inv['email']);
                if ($acc && !$acc['active']) {
                    throw new \DomainException('This email belongs to an inactive admin account. Contact the administrator.', 403);
                }
                $needsPassword = !$acc || !$acc['has_password'];
                if ($needsPassword) {
                    if (mb_strlen($password) < 10) {
                        throw new \DomainException('Choose a password of at least 10 characters.', 422);
                    }
                    if (strlen($password) > 72) {
                        throw new \DomainException('Use a password of at most 72 characters.', 422);   // bcrypt ignores everything after 72 bytes
                    }
                }

                if (!$acc) {
                    $db->prepare('INSERT INTO people (name, email, password_hash, active) VALUES (?, ?, ?, 1)')
                        ->execute([$name, $inv['email'], Auth::hash($password)]);
                    $personId = (int) $db->lastInsertId();
                } else {
                    $personId = (int) $acc['id'];
                    if ($needsPassword) {
                        $db->prepare('UPDATE people SET password_hash = ? WHERE id = ?')->execute([Auth::hash($password), $personId]);
                    }
                }

                // give the invited roles in that edition (added to any roles the person already has there)
                $q = $db->prepare('SELECT id FROM edition_assignments WHERE person_id = ? AND edition_id = ?');
                $q->execute([$personId, $inv['edition_id']]);
                $aid = $q->fetchColumn();
                if ($aid === false) {
                    $db->prepare('INSERT INTO edition_assignments (person_id, edition_id) VALUES (?, ?)')->execute([$personId, $inv['edition_id']]);
                    $aid = (int) $db->lastInsertId();
                }
                $db->prepare('INSERT IGNORE INTO assignment_roles (assignment_id, role_id) SELECT ?, role_id FROM invitation_roles WHERE invitation_id = ?')
                    ->execute([$aid, $inv['id']]);
                $db->prepare("UPDATE invitations SET status = 'accepted', accepted_at = NOW() WHERE id = ?")->execute([$inv['id']]);

                return ['personId' => $personId, 'editionId' => (int) $inv['edition_id'], 'passwordSet' => $needsPassword];
            });
        } catch (\DomainException $e) {
            // the transaction is rolled back; now remember the failed try (for the limiter) and answer
            $pdo->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (?, ?, 0)')->execute([$ident, $ip]);
            self::fail($e->getMessage(), $e->getCode());
        }

        Cache::flush();
        Audit::log($result['personId'], 'Accepted admin invitation', $email, $result['editionId']);
        Response::json(['ok' => true, 'passwordSet' => $result['passwordSet']]);
    }
}