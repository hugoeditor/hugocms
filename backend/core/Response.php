<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Einheitliche JSON-Antworten.
 * Erfolg:  { "ok": true,  "data": ... }
 * Fehler:  { "ok": false, "error": { "code": ..., "key"?: ..., "params": [...] } }
 *
 * Der Fehler trägt keinen übersetzten Text: "code" ist die Fehlerklasse,
 * "key" der optionale Übersetzungsschlüssel (entfällt, wenn der Code die
 * Meldung eindeutig bestimmt), "params" die einzusetzenden dynamischen Teile.
 * Der Client übersetzt darüber und setzt die Meldung zusammen.
 */
final class Response
{
    public static function ok(mixed $data = null): never
    {
        self::send(200, ['ok' => true, 'data' => $data]);
    }

    /**
     * @param array<int, mixed> $params
     */
    public static function error(string $code, ?string $key = null, int $httpStatus = 400, array $params = []): never
    {
        $error = ['code' => $code];
        if ($key !== null) {
            $error['key'] = $key;
        }
        $error['params'] = array_values($params);

        self::send($httpStatus, ['ok' => false, 'error' => $error]);
    }

    /** Bequemlichkeit: baut die Fehlerantwort direkt aus einer ApiException. */
    public static function fromException(ApiException $e): never
    {
        self::error($e->errorCode(), $e->messageKey(), $e->httpStatus(), $e->params());
    }

    private static function send(int $httpStatus, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            // API-Antworten NIE zwischenspeichern. Sonst liefert ein
            // vorgeschalteter Cache (Cloudflare/Proxy) oder der Browser bei
            // GET-Befehlen wie whoami einen veralteten Stand — etwa weiterhin
            // "community", nachdem die Pro-Lizenz schon aktiviert wurde.
            header('Cache-Control: no-store, max-age=0');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
