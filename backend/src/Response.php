<?php
declare(strict_types=1);

namespace App;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $fields = []): never
    {
        $body = ['ok' => false, 'error' => $message];
        if ($fields) {
            $body['fields'] = $fields;
        }
        self::json($body, $status);
    }

    /** Public, cacheable JSON with an ETag so browsers get a tiny 304 when nothing changed. */
    public static function cachedJson(string $json, int $maxAge): never
    {
        $etag = '"' . md5($json) . '"';
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=60');
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        echo $json;
        exit;
    }
}