<?php
declare(strict_types=1);

// Creates the first Super admin (full access to everything). Run once from the project folder:
//   C:\xampp\php\php.exe backend\tools\create-super-admin.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line, not the browser.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Database;

$ask = function (string $label): string {
    echo $label . ': ';
    return trim((string) fgets(STDIN));
};

$pdo   = Database::pdo();
$name  = $ask('Full name');
$email = mb_strtolower($ask('Email (this is your login)'));
$pass  = $ask('Password (at least 10 characters)');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Please enter a name and a valid email.\n");
}
if (mb_strlen($pass) < 10) {
    exit("The password must be at least 10 characters.\n");
}
$st = $pdo->prepare('SELECT COUNT(*) FROM people WHERE email = ?');
$st->execute([$email]);
if ((int) $st->fetchColumn() > 0) {
    exit("Someone with that email already exists.\n");
}

$pdo->prepare('INSERT INTO people (name, email, password_hash, active, is_super_admin) VALUES (?, ?, ?, 1, 1)')
    ->execute([$name, $email, Auth::hash($pass)]);

echo "Done. Super admin created: $email\n";
echo "Tip: clear your terminal history if you typed the password here (type 'cls' on Windows).\n";  