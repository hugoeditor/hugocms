<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Schlanker Proxy-Client zum externen Transkriptionsdienst (seo-success).
 * Bekommt die Basis-Adresse des Dienstes und bildet die Endpunkte selbst
 * (/v1/transcribe, /v1/verify).
 *
 * Bewusst über cURL statt SDK — HugoCMS kommt ohne Composer aus. Der
 * Dienst-Schlüssel bleibt serverseitig; der Client sieht ihn nie.
 */
final class SpeechClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {
    }

    /**
     * Schickt die Audiodatei an /v1/transcribe und liefert die dekodierte
     * Antwort ({text, duration}).
     *
     * @return array<string, mixed>
     */
    public function transcribe(string $audioPath, string $filename, string $mime, ?string $language = null): array
    {
        $fields = [
            'audio' => new \CURLFile($audioPath, $mime, $filename),
        ];
        if ($language !== null && $language !== '') {
            $fields['lang'] = $language;
        }

        $ch = curl_init($this->endpoint('/v1/transcribe'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                // KEIN content-type — cURL setzt multipart/form-data samt boundary
                // selbst, sobald CURLOPT_POSTFIELDS ein Array mit CURLFile ist.
            ],
            CURLOPT_POSTFIELDS => $fields,
        ]);

        return $this->send($ch);
    }

    /**
     * Prüft Schlüssel und Erreichbarkeit über /v1/verify — ohne zu
     * transkribieren. Liefert die Antwort ({valid, quotaLimit, quotaUsed,
     * quotaExceeded}); wirft bei ungültigem Schlüssel oder nicht erreichbarem
     * Dienst.
     *
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $ch = curl_init($this->endpoint('/v1/verify'));
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            // Prüfung soll zügig antworten, nicht bis zum vollen Timeout warten.
            CURLOPT_TIMEOUT => min($this->timeout, 15),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
        ]);

        return $this->send($ch);
    }

    /** Volle Endpunkt-URL aus der Basis-Adresse (Dopplung vermeiden). */
    private function endpoint(string $path): string
    {
        $base = rtrim($this->baseUrl, '/');

        return str_ends_with($base, $path) ? $base : $base . $path;
    }

    /**
     * Führt die vorbereitete cURL-Anfrage aus und behandelt Transport-, JSON-
     * und HTTP-Fehler einheitlich.
     *
     * @return array<string, mixed>
     */
    private function send(\CurlHandle $ch): array
    {
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            // Transportfehler (DNS, Verbindung, Timeout, TLS): der Dienst wurde
            // gar nicht erreicht — fast immer ein URL-/Erreichbarkeitsproblem.
            // Das cURL-Detail (z. B. „Could not resolve host") hilft beim Prüfen.
            throw new ApiException('ESPEECH', 502, 'SPEECH-UNREACHABLE', [$err]);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            // Keine JSON-Antwort: meist ein Fatal-Error oder eine HTML-Fehlerseite
            // vor der eigentlichen Ausgabe. Status + Ausschnitt ins Log, damit die
            // Ursache erkennbar ist (statt nur „ungültige Antwort").
            $snippet = trim(preg_replace('/\s+/', ' ', substr((string) $raw, 0, 200)) ?? '');
            throw new ApiException('ESPEECH', 502, 'SPEECH-REQUEST-FAILED', [
                'HTTP ' . $status . ': ' . ($snippet === '' ? '(leere Antwort)' : $snippet),
            ]);
        }

        if ($status < 200 || $status >= 300) {
            // seo-success meldet Fehler als { "error": "CODE" }.
            $code = (string) ($data['error'] ?? ('HTTP ' . $status));
            if ($status === 429 || $code === 'QUOTA-EXCEEDED') {
                throw new ApiException('ESPEECH', 502, 'SPEECH-QUOTA-EXCEEDED');
            }
            if ($status === 401) {
                throw new ApiException('ESPEECH', 502, 'SPEECH-AUTH-FAILED', [$code]);
            }
            throw new ApiException('ESPEECH', 502, 'SPEECH-REQUEST-FAILED', [$code]);
        }

        return $data;
    }
}
