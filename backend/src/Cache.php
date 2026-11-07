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
                $dir = (string) (Env::get('CACHE_DIR') ?: dirname(__DIR__) . '/storage/cache');   // CACHE_DIR is only set by the tests
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.cache';
    }

    private static function read(string $file, int $ttl): ?string
    {
        if (!is_file($file) || (time() - (int) @filemtime($file)) >= $ttl) {
            return null;
        }
        $hit = @file_get_contents($file);
        return $hit === false ? null : $hit;
    }

    /**
     * Return the cached string for $key, or build it with $build() and store it for $ttl seconds.
     * Only ONE request rebuilds an expired entry. While it works, the others get the slightly old copy
     * (or wait a moment if there is none), so a burst of visitors never makes the database do the same job a hundred times.
     */
    public static function remember(string $key, int $ttl, callable $build): string
    {
        $file = self::file($key);
        if (($hit = self::read($file, $ttl)) !== null) {
            return $hit;
        }

        $lock = @fopen($file . '.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            // someone else is rebuilding right now
            if (is_file($file) && ($stale = @file_get_contents($file)) !== false) {
                fclose($lock);
                return $stale;
            }
            flock($lock, LOCK_EX);                                  // nothing to serve yet: wait for the builder, then use its result
            if (($hit = self::read($file, $ttl)) !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
                return $hit;
            }
        }

        try {
            $value = (string) $build();
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $value, LOCK_EX) !== false) {
                rename($tmp, $file);                                // atomic: readers never see a half-written file
            }
            return $value;
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Clear everything (call after any admin change to public data). */
    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }
}