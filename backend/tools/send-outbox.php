<?php
declare(strict_types=1);

// Sends the emails waiting in notification_outbox (verification codes, resets, invitations ...).
// Run by hand, or every few minutes with Windows Task Scheduler / cron:
//   C:\xampp\php\php.exe backend\tools\send-outbox.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Mailer;
use App\Outbox;

if (!Mailer::configured()) {
    exit("Email is not configured. Set SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD, SMTP_SECURE and MAIL_FROM in backend/config.php\n");
}
$r = Outbox::sendPending(100);
echo "Sent: {$r['sent']}  Failed: {$r['failed']}  Expired: {$r['expired']}  Skipped: {$r['skipped']}\n";