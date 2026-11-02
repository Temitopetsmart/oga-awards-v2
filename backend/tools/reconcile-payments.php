<?php
declare(strict_types=1);

// Safety net: asks Flutterwave about every payment that is still "pending" (older than 2 minutes, newer than 3 days)
// and credits the ones Flutterwave confirms. Catches buyers who paid but never came back to the site.
// Run by hand, or every 10 minutes with Windows Task Scheduler / cron:
//   C:\xampp\php\php.exe backend\tools\reconcile-payments.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;
use App\PaymentService;

$pdo = Database::pdo();
$refs = $pdo->query("SELECT reference FROM payments WHERE status = 'pending'
                       AND created_at < (NOW() - INTERVAL 2 MINUTE) AND created_at > (NOW() - INTERVAL 3 DAY)
                     ORDER BY id LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);

$counts = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'error' => 0];
foreach ($refs as $ref) {
    try {
        $r = PaymentService::settle($ref);
        $counts[$r['status']]++;
        if ($r['status'] === 'paid') {
            echo "Credited $ref\n";
        }
    } catch (Throwable $e) {
        $counts['error']++;
        fwrite(STDERR, "$ref: " . $e->getMessage() . "\n");
    }
}
printf("Checked %d payment(s): %d credited, %d still pending, %d failed, %d errors.\n", count($refs), $counts['paid'], $counts['pending'], $counts['failed'], $counts['error']);