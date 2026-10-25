<?php
declare(strict_types=1);

namespace App;

/**
 * Photo uploads. Every upload is decoded and re-encoded as WebP (a big and a small copy), so whatever the
 * file really contained — a script hidden in an image, odd metadata — is thrown away. Names are random.
 */
final class Photos
{
    private const MAX_BYTES = 5 * 1024 * 1024;      // 5 MB upload
    private const MIN_SIDE  = 200;                   // pixels
    private const MAX_SIDE  = 4000;                  // bigger images use too much memory to resize safely
    private const BIG       = 800;                   // longest side of the main photo
    private const THUMB     = 240;                   // square thumbnail
    private const QUALITY   = 82;

    private static function fail(string $message, int $status = 422): never
    {
        Response::error($message, $status);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/uploads';
    }

    /** "uploads/x/ab/c0ffee.webp" -> "uploads/x/ab/c0ffee_t.webp" */
    public static function thumbOf(?string $path): ?string
    {
        return $path === null ? null : preg_replace('/\.webp$/', '_t.webp', $path);
    }

    /** @param array $file one entry of $_FILES. Returns the relative path of the saved main photo. */
    public static function save(array $file, string $folder): string
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            self::fail('Photo uploads need the PHP "gd" extension with WebP support.', 500);
        }
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            self::fail('That photo is too large (maximum 5 MB).');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            self::fail('Choose a photo to upload.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            self::fail('That photo is too large (maximum 5 MB).');
        }

        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            self::fail('Use a JPG, PNG or WebP photo.');
        }
        [$w, $h] = $info;
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            self::fail('The photo is too small. Use one at least ' . self::MIN_SIDE . ' pixels wide and tall.');
        }
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            self::fail('The photo is too big. Use one no larger than ' . self::MAX_SIDE . ' pixels on either side.');
        }

        $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$src) {
            self::fail('That file could not be read as a photo.');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file['tmp_name']);
            $angle = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($angle !== 0 && ($rot = imagerotate($src, $angle, 0))) {
                $src = $rot;
                [$w, $h] = [imagesx($src), imagesy($src)];
            }
        }

        $main  = self::fit($src, $w, $h, self::BIG);
        $thumb = self::square($src, $w, $h, self::THUMB);

        $name = bin2hex(random_bytes(10));
        $dir  = self::root() . "/$folder/" . substr($name, 0, 2);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail('The server could not save the photo. Check the uploads folder permissions.', 500);
        }
        $relative = "uploads/$folder/" . substr($name, 0, 2) . "/$name.webp";
        $ok = imagewebp($main, dirname(self::root()) . '/' . $relative, self::QUALITY)
           && imagewebp($thumb, dirname(self::root()) . '/' . self::thumbOf($relative), self::QUALITY);
        if (!$ok) {
            self::fail('The server could not save the photo. Check the uploads folder permissions.', 500);
        }
        return $relative;
    }

    public static function delete(?string $path): void
    {
        if ($path === null || !preg_match('#^uploads/[a-z0-9_-]+/[a-f0-9]{2}/[a-f0-9]+\.webp$#', $path)) {
            return;                                      // only ever delete files we made ourselves
        }
        foreach ([$path, self::thumbOf($path)] as $p) {
            @unlink(dirname(self::root()) . '/' . $p);
        }
    }

    private static function canvas(int $w, int $h): \GdImage
    {
        $c = imagecreatetruecolor($w, $h);
        imagealphablending($c, false);
        imagesavealpha($c, true);
        imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));
        return $c;
    }

    private static function fit(\GdImage $src, int $w, int $h, int $max): \GdImage
    {
        $scale = min(1, $max / max($w, $h));             // never enlarge
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = self::canvas($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    private static function square(\GdImage $src, int $w, int $h, int $size): \GdImage
    {
        $side = min($w, $h);
        $dst = self::canvas($size, $size);
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        return $dst;
    }
}