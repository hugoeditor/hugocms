<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Schlanker HTTP-Client für die Anthropic Messages-API (Claude). Bewusst über
 * cURL statt über das offizielle SDK, da HugoCMS ohne Composer auskommt.
 *
 * Nur der HTTP-Teil: nimmt ein fertiges Payload-Array, sendet es an
 * /v1/messages und gibt die dekodierte Antwort zurück. Den Gesprächs- und
 * Werkzeug-Ablauf steuert der AssistantService.
 */
final class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {
    }

    /**
     * Schickt einen Messages-Request und liefert die dekodierte Antwort.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createMessage(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new ApiException('EAI', 500, 'AI-REQUEST-FAILED', ['JSON-Kodierung fehlgeschlagen']);
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::VERSION,
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', [$err]);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', ['ungültige Antwort']);
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($data['error']['message'] ?? ('HTTP ' . $status));
            // 401/403 → Schlüsselproblem getrennt melden, sonst generisch.
            if ($status === 401 || $status === 403) {
                throw new ApiException('EAI', 502, 'AI-AUTH-FAILED', [$message]);
            }
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', [$message]);
        }

        return $data;
    }
}
