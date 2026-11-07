<?php
declare(strict_types=1);

/**
 * Checks (and optionally repairs) the precomputed vote totals.
 *   C:\xampp\php\php.exe backend\tools\rebuild-vote-totals.php          compares totals with the orders; exit code 1 if they differ
 *   C:\xampp\php\php.exe backend\tools\rebuild-vote-totals.php --fix    rebuilds the totals from the orders
 * Safe to run any time (also as a nightly cron job).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Cache;
use App\VoteTotals;

$drift = VoteTotals::drift();
if ($drift === []) {
    echo "Vote totals match the orders.\n";
    exit(0);
}
echo count($drift) . " nominee/category total(s) differ from the orders:\n";
foreach (array_slice($drift, 0, 20) as $d) {
    echo "  nominee {$d['nominee_entry_id']} category {$d['category_id']}: stored {$d['stored']}, actual {$d['actual']}\n";
}
if (!in_array('--fix', $argv, true)) {
    echo "Run again with --fix to rebuild them.\n";
    exit(1);
}
$n = VoteTotals::rebuild();
Cache::flush();
echo "Rebuilt $n total(s).\n";