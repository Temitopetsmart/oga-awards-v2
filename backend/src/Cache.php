<?php
declare(strict_types=1);

namespace App;

/**
 * Tiny file cache for public data. Admin saves call Cache::flush() so changes show immediately.
 * Stored in backend/storage/cache (blocked from the web by backend/.htaccess).
 */
final class Cache
{
    private static function dir(): string
    {
        $dir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.cache';
    }

    /** Return the cached string for $key, or build it with $build(), store it for $ttl seconds. */
    public static function remember(string $key, int $ttl, callable $build): string
    {
        $file = self::file($key);
        if (is_file($file) && (time() - filemtime($file)) < $ttl) {
            $hit = file_get_contents($file);
            if ($hit !== false) {
                return $hit;
            }
        }
        $value = (string) $build();
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $value, LOCK_EX) !== false) {
            rename($tmp, $file);            // atomic: readers never see a half-written file
        }
        return $value;
    }

    /** Clear everything (call after any admin change to public data). */
    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }
}