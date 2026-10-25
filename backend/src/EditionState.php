<?php
declare(strict_types=1);

namespace App;

/** Which public "state" an edition is in right now. Same rules as the V2 site (auto from dates, or forced by an admin). */
final class EditionState
{
    private const PHASES = [
        ['nominations',   'nominations_open_at', 'nominations_close_at'],
        ['voting',        'voting_open_at',      'voting_close_at'],
        ['voting-closed', 'voting_close_at',     'results_publish_at'],
        ['results',       'results_publish_at',  null],
    ];
    private const FORCEABLE = ['landing', 'nominations', 'voting', 'voting-closed', 'results'];

    public static function of(array $edition, ?int $now = null): string
    {
        if ($edition['state_mode'] === 'manual' && in_array($edition['force_state'], self::FORCEABLE, true)) {
            return $edition['force_state'];
        }
        $now ??= time();
        $state = 'landing';
        foreach (self::PHASES as [$name, $startCol, $endCol]) {
            $start = $edition[$startCol] ? strtotime($edition[$startCol]) : null;
            $end = $endCol && $edition[$endCol] ? strtotime($edition[$endCol]) : null;
            if ($start !== null && $now >= $start && ($endCol === null || $end === null || $now < $end)) {
                $state = $name;
            }
        }
        return $state;
    }

    /** The edition nominees can currently register for, or null. */
    public static function openForNominations(): ?array
    {
        $e = Database::pdo()->query("SELECT e.*, s.nominee_registration_fee FROM editions e LEFT JOIN edition_settings s ON s.edition_id = e.id WHERE e.status = 'active'")->fetch();
        return $e && self::of($e) === 'nominations' ? $e : null;
    }
}