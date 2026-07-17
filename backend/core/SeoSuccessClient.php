<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Schlanker Proxy-Client zum externen seo-success-Dienst. Ein Schlüssel, ein
 * Kontingent — derselbe Dienst liefert Sprach-zu-Text UND die Live-Analyse.
 * Bekommt die Basis-Adresse und bildet die Endpunkte selbst:
 *   - Sprache:  /v1/transcribe, /v1/verify
 *   - Analyse:  /v1/analyze (anstoßen), /v1/analyze/<id> (Status/Export),
 *               /v1/analyze/<id>/cancel (Abbruch), /v1/analyze/history (Verlauf)
 *
 * Bewusst über cURL statt SDK — HugoCMS kommt ohne Composer aus. Der
 * Dienst-Schlüssel bleibt serverseitig; der Client sieht ihn nie.
 */
final class SeoSuccessClient
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

    /**
     * Stößt eine Live-Analyse an (POST /v1/analyze). Liefert sofort
     * {job_id, status:"queued"}; der audit-worker arbeitet den Job ab.
     *
     * @return array<string, mixed>
     */
    public function analyzeStart(string $url): array
    {
        $ch = curl_init($this->endpoint('/v1/analyze'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min($this->timeout, 20),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode(['url' => $url]),
        ]);

        return $this->sendAnalyze($ch);
    }

    /**
     * Fragt Status/Ergebnis eines Auftrags ab (GET /v1/analyze/<id>). Trägt bei
     * `queued` ohne lebenden Worker das Feld `stale`, bei `done` das `result`.
     *
     * @return array<string, mixed>
     */
    public function analyzeStatus(string $jobId): array
    {
        $ch = curl_init($this->endpoint('/v1/analyze/' . rawurlencode($jobId)));
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min($this->timeout, 15),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
        ]);

        return $this->sendAnalyze($ch);
    }

    /**
     * Bricht einen laufenden/wartenden Auftrag wirklich ab
     * (POST /v1/analyze/<id>/cancel) → {job_id, status:"cancelled"}.
     *
     * @return array<string, mixed>
     */
    public function analyzeCancel(string $jobId): array
    {
        $ch = curl_init($this->endpoint('/v1/analyze/' . rawurlencode($jobId) . '/cancel'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min($this->timeout, 15),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS => '',
        ]);

        return $this->sendAnalyze($ch);
    }

    /**
     * Trend-Historie einer Site (GET /v1/analyze/history?host=&limit=), neueste
     * zuerst — Grundlage für die Verlaufskurve im CMS.
     *
     * @return array<string, mixed>
     */
    public function analyzeHistory(string $host, int $limit = 20): array
    {
        $query = http_build_query(['host' => $host, 'limit' => $limit]);
        $ch = curl_init($this->endpoint('/v1/analyze/history') . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min($this->timeout, 15),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
        ]);

        return $this->sendAnalyze($ch);
    }

    /**
     * Export eines abgeschlossenen Auftrags (GET /v1/analyze/<id>?format=html|csv).
     * Liefert die Roh-Bytes und den Content-Type — der Connector reicht beides am
     * JSON-Umschlag vorbei an den Browser weiter.
     *
     * @return array{body: string, contentType: string}
     */
    public function analyzeExport(string $jobId, string $format): array
    {
        $query = http_build_query(['format' => $format === 'csv' ? 'csv' : 'html']);
        $ch = curl_init($this->endpoint('/v1/analyze/' . rawurlencode($jobId)) . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
        ]);

        return $this->sendAnalyzeRaw($ch);
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
        [$status, $raw, $transportError] = $this->exec($ch);
        if ($transportError !== null) {
            // Transportfehler (DNS, Verbindung, Timeout, TLS): der Dienst wurde
            // gar nicht erreicht — fast immer ein URL-/Erreichbarkeitsproblem.
            // Das cURL-Detail (z. B. „Could not resolve host") hilft beim Prüfen.
            throw new ApiException('ESPEECH', 502, 'SPEECH-UNREACHABLE', [$transportError]);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // Keine JSON-Antwort: meist ein Fatal-Error oder eine HTML-Fehlerseite
            // vor der eigentlichen Ausgabe. Status + Ausschnitt ins Log, damit die
            // Ursache erkennbar ist (statt nur „ungültige Antwort").
            throw new ApiException('ESPEECH', 502, 'SPEECH-REQUEST-FAILED', [self::snippet($status, $raw)]);
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

    /**
     * Wie {@see send()}, aber mit der Fehler-Familie der Analyse (ANALYZE-*). Die
     * spezifischen Gateway-Codes (URL-*, JOB-*, WORKER-DOWN) werden als eigene
     * Übersetzungsschlüssel durchgereicht, damit das CMS sie gezielt anzeigt.
     *
     * @return array<string, mixed>
     */
    private function sendAnalyze(\CurlHandle $ch): array
    {
        [$status, $raw, $transportError] = $this->exec($ch);
        if ($transportError !== null) {
            throw new ApiException('ESEOANALYZE', 502, 'ANALYZE-UNREACHABLE', [$transportError]);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException('ESEOANALYZE', 502, 'ANALYZE-REQUEST-FAILED', [self::snippet($status, $raw)]);
        }

        if ($status < 200 || $status >= 300) {
            throw self::analyzeError($status, (string) ($data['error'] ?? ''));
        }

        return $data;
    }

    /**
     * Wie {@see sendAnalyze()}, aber für den Export: liefert die Roh-Bytes und den
     * Content-Type statt JSON (der Bericht ist HTML bzw. CSV). Fehler kommen
     * weiterhin als JSON und werden gleich abgebildet.
     *
     * @return array{body: string, contentType: string}
     */
    private function sendAnalyzeRaw(\CurlHandle $ch): array
    {
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiException('ESEOANALYZE', 502, 'ANALYZE-UNREACHABLE', [$err]);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $data = json_decode((string) $raw, true);
            throw self::analyzeError($status, is_array($data) ? (string) ($data['error'] ?? '') : '');
        }

        return [
            'body' => (string) $raw,
            'contentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
        ];
    }

    /**
     * Führt die vorbereitete Anfrage aus und liefert Status + Rohtext, ohne zu
     * werfen. Die familienspezifische Fehlerabbildung macht der Aufrufer.
     *
     * @return array{0: int, 1: string, 2: ?string} [Status, Rohtext, Transportfehler]
     */
    private function exec(\CurlHandle $ch): array
    {
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);

            return [0, '', $err];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, (string) $raw, null];
    }

    /**
     * Bildet Status/Gateway-Code eines Analyse-Fehlers auf einen ANALYZE-*
     * Übersetzungsschlüssel ab. Bekannte Codes werden mit ANALYZE-Präfix
     * durchgereicht; sonst greift ein Rückfall über den HTTP-Status.
     */
    private static function analyzeError(int $status, string $apiCode): ApiException
    {
        $key = match ($apiCode) {
            'URL-INVALID', 'URL-FORBIDDEN', 'URL-MISSING' => 'ANALYZE-' . $apiCode,
            'JOB-NOT-READY' => 'ANALYZE-JOB-NOT-READY',
            'JOB-NOT-CANCELABLE' => 'ANALYZE-JOB-NOT-CANCELABLE',
            'WORKER-DOWN' => 'ANALYZE-WORKER-DOWN',
            'QUOTA-EXCEEDED' => 'ANALYZE-QUOTA-EXCEEDED',
            'NOT-FOUND' => 'ANALYZE-JOB-NOT-FOUND',
            'AUTH-INVALID', 'AUTH-MISSING' => 'ANALYZE-AUTH-FAILED',
            default => match (true) {
                $status === 401 => 'ANALYZE-AUTH-FAILED',
                $status === 404 => 'ANALYZE-JOB-NOT-FOUND',
                $status === 429 => 'ANALYZE-QUOTA-EXCEEDED',
                default => 'ANALYZE-REQUEST-FAILED',
            },
        };

        return new ApiException('ESEOANALYZE', 502, $key, $apiCode === '' ? [] : [$apiCode]);
    }

    /** Kurzer Ausschnitt einer Nicht-JSON-Antwort für die Fehlermeldung. */
    private static function snippet(int $status, string $raw): string
    {
        $text = trim(preg_replace('/\s+/', ' ', substr($raw, 0, 200)) ?? '');

        return 'HTTP ' . $status . ': ' . ($text === '' ? '(leere Antwort)' : $text);
    }
}
