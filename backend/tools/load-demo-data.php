<?php
declare(strict_types=1);

// Loads the sample data (OGA 10.0 + OGA 9.0) into an EMPTY database. Safe to run twice: it stops if data exists.
// Run from the project folder:   C:\xampp\php\php.exe backend\tools\load-demo-data.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Cache;
use App\Database;

$pdo = Database::pdo();
$count = fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

if ($count('SELECT COUNT(*) FROM editions') > 0) {
    echo "Demo data is already loaded (editions found). Nothing to do.\n";
    exit(0);
}

$hasLinkLabel = $count(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'occasions' AND column_name = 'link_label'"
);
if ($hasLinkLabel === 0) {
    $pdo->exec('ALTER TABLE occasions ADD COLUMN link_label VARCHAR(100) NULL AFTER link_href');
    echo "Added column occasions.link_label\n";
}

$file = dirname(__DIR__, 2) . '/database/migrations/003_demo_data.sql';
if (!is_file($file)) {
    fwrite(STDERR, "Cannot find $file\n");
    exit(1);
}

$done = 0;
$pdo->beginTransaction();
try {
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '--')) {
            continue;                       // each statement in the file is on one line
        }
        $pdo->exec($line);
        $done++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED, nothing was saved: " . $e->getMessage() . "\n");
    exit(1);
}

Cache::flush();
echo "Done. $done statements loaded. Editions: {$count('SELECT COUNT(*) FROM editions')}, nominees: {$count('SELECT COUNT(*) FROM nominee_entries')}.\n";