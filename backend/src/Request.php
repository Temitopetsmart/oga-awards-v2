<?php
declare(strict_types=1);

namespace App;

final class Request
{
    /** The connecting address. (We deliberately ignore X-Forwarded-For: it can be faked.) */
    public static function ip(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    }

    /** The JSON body of a POST/PUT request as an array. */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Response::error('Invalid request.', 400);
        }
        return $data;
    }

    /** A trimmed string field, cut to $max characters. */
    public static function str(array $data, string $key, int $max = 255): string
    {
        $v = $data[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }
}