<?php
declare(strict_types=1);

namespace App;

/**
 * Ad images (a logo and a banner). Every upload is decoded and re-encoded as WebP, so whatever the file really
 * contained (a script hidden in an image, odd metadata) is thrown away. Names are random. Photos.php is for people's
 * photos (square crops); ads need their own shapes, so they have their own small class.
 */
final class AdMedia
{
    private const HARD_MAX_BYTES = 5 * 1024 * 1024;
    private const MIN_SIDE       = 80;
    private const MAX_SIDE       = 4000;
    private const BANNER_WIDE    = 1200;      // longest side kept for a banner
    private const LOGO_WIDE      = 400;       // longest side kept for a logo
    private const QUALITY        = 82;

    /** @throws \InvalidArgumentException with a message that is safe to show */
    public static function save(array $file, string $kind, int $maxKb): string
    {
        if (!in_array($kind, ['logo', 'banner'], true)) {
            throw new \InvalidArgumentException('Unknown image type.');
        }
        $label = $kind === 'logo' ? 'logo' : 'banner';
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new \InvalidArgumentException('Image uploads need the PHP "gd" extension with WebP support.');
        }
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $limit = min(self::HARD_MAX_BYTES, max(50, $maxKb) * 1024);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException("The $label is too large (maximum " . self::kb($limit) . ').');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new \InvalidArgumentException("Choose a $label to upload.");
        }
        if ((int) $file['size'] > $limit) {
            throw new \InvalidArgumentException("The $label is too large (maximum " . self::kb($limit) . ').');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new \InvalidArgumentException("Use a JPG, PNG or WebP image for the $label.");
        }
        [$w, $h] = $info;
        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            throw new \InvalidArgumentException("The $label is too small. Use one at least " . self::MIN_SIDE . ' pixels wide and tall.');
        }
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            throw new \InvalidArgumentException("The $label is too big. Use one no larger than " . self::MAX_SIDE . ' pixels on either side.');
        }
        $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$src) {
            throw new \InvalidArgumentException("That file could not be read as an image.");
        }
        $max   = $kind === 'logo' ? self::LOGO_WIDE : self::BANNER_WIDE;
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $name = bin2hex(random_bytes(10));
        $dir  = self::root() . '/ads/' . substr($name, 0, 2);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \InvalidArgumentException('The server could not save the image. Check the uploads folder permissions.');
        }
        $relative = 'uploads/ads/' . substr($name, 0, 2) . "/$name.webp";
        if (!imagewebp($dst, dirname(self::root()) . '/' . $relative, self::QUALITY)) {
            throw new \InvalidArgumentException('The server could not save the image. Check the uploads folder permissions.');
        }
        return $relative;
    }

    /** Deletes a file this class made, and only that. */
    public static function delete(?string $path): void
    {
        if ($path === null || !preg_match('#^uploads/ads/[a-f0-9]{2}/[a-f0-9]+\.webp$#', $path)) {
            return;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM ads WHERE logo_path = ? OR banner_path = ?');
        $st->execute([$path, $path]);
        if ((int) $st->fetchColumn() > 0) {
            return;                                   // a renewal still uses the same file
        }
        @unlink(dirname(self::root()) . '/' . $path);
    }

    private static function root(): string
    {
        return Photos::root();
    }

    private static function kb(int $bytes): string
    {
        return $bytes >= 1048576 ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB' : round($bytes / 1024) . ' KB';
    }
}