<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Schlanker Proxy-Client zum externen Transkriptionsdienst (seo-success).
 * Reicht eine hochgeladene Audiodatei per multipart an dessen
 * /v1/transcribe-Endpunkt weiter und liefert die dekodierte JSON-Antwort.
 *
 * Bewusst über cURL statt SDK — HugoCMS kommt ohne Composer aus. Der
 * Dienst-Schlüssel bleibt serverseitig; der Client sieht ihn nie.
 */
final class SpeechClient
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {
    }

    /**
     * Schickt die Audiodatei und liefert die dekodierte Antwort ({text, duration}).
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

        $ch = curl_init($this->url);
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
