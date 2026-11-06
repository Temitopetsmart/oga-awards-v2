<?php
declare(strict_types=1);

/**
 * One-time clean-up for a database that was filled with the old sample data (Sample Nominee One, Sample Member ...).
 * It EMPTIES every content and activity table, keeps the schema, roles, permissions, settings, encrypted secrets and
 * admin accounts, then creates the single empty OGA 10.0 edition (migration 009).
 *
 *   C:\xampp\php\php.exe backend\tools\purge-demo-data.php          (shows what it would remove, changes nothing)
 *   C:\xampp\php\php.exe backend\tools\purge-demo-data.php --yes    (does it)
 *
 * Refuses when real money is on record (paid votes, tickets or registrations) unless you add --even-if-paid,
 * and refuses when invited or named admin accounts exist other than Super admins.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Cache;
use App\Database;

$pdo = Database::pdo();
$yes = in_array('--yes', $argv, true);
$evenIfPaid = in_array('--even-if-paid', $argv, true);
$count = fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

$wipe = [
    'edition_settings', 'occasions', 'edition_assignments', 'assignment_roles', 'assignment_overrides',
    'invitations', 'invitation_roles', 'categories', 'accounts', 'account_tokens', 'nominee_entries',
    'payments', 'webhook_events', 'vote_orders', 'vote_limit_counters', 'winners', 'ticket_types',
    'ticket_orders', 'ticket_groups', 'tickets', 'ticket_scans', 'committee_config', 'committee_applications',
    'committee_application_history', 'contact_messages', 'notification_outbox', 'audit_log', 'login_attempts',
    'committee_units', 'editions',
];

echo 'Database: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n\nRows that would be removed:\n";
$total = 0;
foreach ($wipe as $t) {
    $n = $count("SELECT COUNT(*) FROM `$t`");
    $total += $n;
    if ($n > 0) {
        echo sprintf("  %-32s %d\n", $t, $n);
    }
}
$memberOnly = $count('SELECT COUNT(*) FROM people WHERE email IS NULL');
echo sprintf("  %-32s %d  (people with no login)\n", 'people', $memberOnly);
echo "\nKept: roles, permissions, settings, encrypted secrets, levels, and admin accounts that can sign in.\n";

$paid = $count("SELECT COUNT(*) FROM vote_orders WHERE status = 'paid'")
      + $count("SELECT COUNT(*) FROM ticket_orders WHERE status = 'paid'")
      + $count("SELECT COUNT(*) FROM payments WHERE status = 'successful'");
if ($paid > 0 && !$evenIfPaid) {
    fwrite(STDERR, "\nRefusing: $paid paid order(s) exist. If they are only test payments, run again with --even-if-paid.\n");
    exit(1);
}
$team = $count('SELECT COUNT(*) FROM people WHERE email IS NOT NULL AND is_super_admin = 0');
if ($team > 0) {
    fwrite(STDERR, "\nRefusing: $team non-Super admin account(s) exist and would lose their edition roles. Remove the sample data by hand instead.\n");
    exit(1);
}
if (!$yes) {
    echo "\nNothing changed. Add --yes to do it.\n";
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($wipe as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec('DELETE FROM people WHERE email IS NULL');
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/009_initial_edition.sql'));
foreach (preg_split('/;\s*(\r?\n|$)/', (string) $sql) as $statement) {
    if (trim($statement) !== '') {
        $pdo->exec(trim($statement));
    }
}
Cache::flush();
echo "\nDone. Now: 1 edition ({$count('SELECT COUNT(*) FROM editions')}), {$count('SELECT COUNT(*) FROM committee_units')} committee units, no sample data.\n";
echo "Uploaded photos in /uploads are not touched: delete old nominee photos by hand if you want.\n";