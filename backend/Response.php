<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

/**
 * Einheitliche JSON-Antworten.
 * Erfolg:  { "ok": true,  "data": ... }
 * Fehler:  { "ok": false, "error": { "code": ..., "message": ... } }
 */
final class Response
{
    public static function ok(mixed $data = null): never
    {
        self::send(200, ['ok' => true, 'data' => $data]);
    }

    public static function error(string $code, string $message, int $httpStatus = 400): never
    {
        self::send($httpStatus, [
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    private static function send(int $httpStatus, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
