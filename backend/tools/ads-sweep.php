<?php
declare(strict_types=1);

// Stops ads whose period has ended, warns advertisers whose ad is about to end, and tidies unpaid orders.
// The site also does this by itself whenever someone loads an ad, so a missed run does no harm.
// Run by hand, or every hour with Windows Task Scheduler / cron:
//   C:\xampp\php\php.exe backend\tools\ads-sweep.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

$r = App\Ads::sweep();
echo "Expired: {$r['expired']}  Warned: {$r['warned']}  Abandoned orders: {$r['abandoned']}\n";