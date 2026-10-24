<?php
declare(strict_types=1);

namespace App;

/** Reads private settings from backend/config.php (a PHP file that returns an array). */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Missing backend/config.php — copy backend/config.example.php to backend/config.php');
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException('backend/config.php must return an array');
        }
        self::$vars = array_map('strval', $values);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}