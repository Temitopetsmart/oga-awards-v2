<?php
// Old-style connection file. In any script that needs the database, write:
//     require __DIR__ . '/connect.php';     // adjust the path
// and you get a ready PDO connection in $pdo (errors throw exceptions, rows come back as arrays).
require_once __DIR__ . '/src/bootstrap.php';

$pdo = \App\Database::pdo();