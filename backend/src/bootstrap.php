<?php
declare(strict_types=1);

use App\Env;
use App\Response;

// Simple autoloader: App\Foo\Bar -> backend/src/Foo/Bar.php
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Env::load(dirname(__DIR__) . '/.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lagos'));
$debug = Env::bool('APP_DEBUG', false);
ini_set('display_errors', '0');          // never print errors to visitors
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (\Throwable $e) use ($debug): void {
    error_log((string) $e);              // full detail goes to the server log only
    Response::error($debug ? $e->getMessage() : 'Something went wrong. Please try again.', 500);
});