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
    private const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models';
    private const VERSION = '2023-06-01';

    /**
     * Modelle, die listModels() aus der API-Antwort entfernt. Die API liefert
     * alles, wofür der Schlüssel freigeschaltet ist — auch Vorgänger-
     * generationen; ein Kennzeichen „veraltet" gibt es dort nicht, daher diese
     * Liste. Sie ist nach dem Abschneiden des Datums-Suffixes zu lesen (also
     * claude-opus-4-5, nicht claude-opus-4-5-20251101) und braucht Pflege,
     * sobald eine Generation nachrückt.
     *
     * claude-fable-5 steht bewusst hier: doppelter Preis gegenüber Opus 4.8 und
     * abweichendes API-Verhalten (u. a. der Abbruchgrund „refusal", den der
     * AssistantService nicht behandelt).
     */
    private const HIDDEN_MODELS = [
        'claude-opus-4-5',
        'claude-opus-4-1',
        'claude-sonnet-4-5',
        'claude-fable-5',
    ];

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

            // Monatliches Nutzungslimit des Kontos: verständliche Meldung samt
            // Freigabezeitpunkt statt der rohen englischen API-Meldung. Vor der
            // Statusprüfung, da Anthropic dies je nach Fall mit 400/403/429
            // zurückgibt und die Meldung das verlässliche Merkmal ist.
            if (stripos($message, 'usage limit') !== false) {
                if (preg_match(
                    '/regain access on\s+(\d{4}-\d{2}-\d{2})\s+at\s+(\d{1,2}:\d{2})/i',
                    $message,
                    $m,
                ) === 1) {
                    // Datum als {d:…}-Objekt: der Client formatiert es
                    // sprachabhängig (siehe i18n/apiMessage.js).
                    throw new ApiException('EAI', 502, 'AI-USAGE-LIMIT', [['d' => $m[1]], $m[2]]);
                }
                throw new ApiException('EAI', 502, 'AI-USAGE-LIMIT-UNKNOWN');
            }

            // 401/403 → Schlüsselproblem getrennt melden, sonst generisch.
            if ($status === 401 || $status === 403) {
                throw new ApiException('EAI', 502, 'AI-AUTH-FAILED', [$message]);
            }
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', [$message]);
        }

        return $data;
    }

    /**
     * Erreichbarkeits- und Schlüsselprüfung ohne Token-Verbrauch: ein GET auf
     * /v1/models. Verifiziert Netzwerk-Erreichbarkeit UND gültigen API-Schlüssel,
     * ohne die Messages-API (und damit Kosten) zu bemühen. Wirft dieselben
     * Fehler wie createMessage; bei Erfolg kehrt die Methode ohne Rückgabe zurück.
     */
    public function ping(): void
    {
        // Ein Eintrag genügt: geprüft wird nur der HTTP-Status.
        $this->requestModels(1);
    }

    /**
     * Liefert die anbietbaren Modell-Kennungen, neueste zuerst (Reihenfolge der
     * API). Grundlage der Modell-Auswahl im Konfigurationsdialog; ohne Abruf
     * gilt die fest verdrahtete Liste des Clients.
     *
     * Zwei Aufbereitungsschritte, weil die API rohen Katalog liefert:
     * 1. Das Datums-Suffix fällt weg — die API mischt beide Namensformen
     *    (claude-opus-4-8 neben claude-haiku-4-5-20251001), gemeint ist aber
     *    dasselbe Modell. Ohne diesen Schritt fiele Haiku 4.5 aus der Auswahl.
     * 2. Veraltete Modelle fliegen raus (siehe HIDDEN_MODELS).
     * Unbekannte neue Modelle kommen dadurch automatisch durch.
     *
     * @return list<string>
     */
    public function listModels(): array
    {
        // 100 deckt den Katalog um ein Vielfaches ab (aktuell unter zehn
        // Modelle), daher wird die Seitenweiterschaltung (has_more/after_id)
        // bewusst nicht ausgewertet.
        $data = $this->requestModels(100);

        $models = [];
        foreach ($data['data'] ?? [] as $entry) {
            $id = is_array($entry) ? trim((string) ($entry['id'] ?? '')) : '';
            if ($id === '') {
                continue;
            }
            // claude-haiku-4-5-20251001 → claude-haiku-4-5
            $id = (string) preg_replace('/-\d{8}$/', '', $id);
            if (in_array($id, self::HIDDEN_MODELS, true) || in_array($id, $models, true)) {
                continue;
            }
            $models[] = $id;
        }
        if ($models === []) {
            throw new ApiException('EAI', 502, 'AI-MODELS-EMPTY');
        }

        return $models;
    }

    /**
     * Gemeinsamer GET auf /v1/models für ping() und listModels(). Kurzer
     * Timeout: beide Aufrufe hängen an einer Nutzeraktion und dürfen sie nicht
     * spürbar verzögern.
     *
     * @return array<string, mixed>
     */
    private function requestModels(int $limit): array
    {
        $ch = curl_init(self::MODELS_ENDPOINT . '?limit=' . $limit);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min($this->timeout, 15),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::VERSION,
            ],
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

        if ($status >= 200 && $status < 300) {
            return is_array($data) ? $data : [];
        }

        $message = is_array($data)
            ? (string) ($data['error']['message'] ?? ('HTTP ' . $status))
            : ('HTTP ' . $status);

        // 401/403 → Schlüsselproblem getrennt melden, sonst generisch.
        if ($status === 401 || $status === 403) {
            throw new ApiException('EAI', 502, 'AI-AUTH-FAILED', [$message]);
        }
        throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', [$message]);
    }
}
