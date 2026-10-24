<?php
declare(strict_types=1);

// Applies any new numbered SQL files in database/migrations/ that have not been applied yet.
// Run from the project folder:   C:\xampp\php\php.exe backend\tools\migrate.php
// (003_demo_data.sql is sample data and is loaded separately by load-demo-data.php.)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$pdo = Database::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(150) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$applied = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

// First run on a database that was set up by hand: 001 and 002 are already in place.
if ($applied === [] && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'editions'")->fetchColumn() > 0) {
    foreach (['001_schema.sql', '002_seed_permissions.sql'] as $done) {
        $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$done]);
        $applied[] = $done;
    }
}

$files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
sort($files);
$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true) || $name === '003_demo_data.sql') {
        continue;
    }
    echo "Applying $name ... ";
    $sql = (string) file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // drop comment lines
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
    echo "done\n";
    $ran++;
}
echo $ran === 0 ? "Database is up to date.\n" : "Applied $ran migration(s).\n";