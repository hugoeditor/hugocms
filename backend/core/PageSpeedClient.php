<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Schlanker Client für die Google PageSpeed-Insights-API (v5). Misst eine
 * öffentlich erreichbare Live-URL und liefert die Lighthouse-Kennzahlen. Wie
 * die übrigen HTTP-Clients bewusst über cURL statt SDK — HugoCMS kommt ohne
 * Composer aus.
 *
 * Der API-Schlüssel ist OPTIONAL: ohne Schlüssel funktioniert die API mit einem
 * kleinen Kontingent, mit kostenlosem Google-Schlüssel deutlich großzügiger.
 * Der Schlüssel bleibt serverseitig; der Client sieht ihn nie.
 *
 * Nur der HTTP-Teil samt Antwort-Reduktion: Die Roh-Antwort ist ein sehr großes
 * Lighthouse-JSON (mehrere hundert KB). {@see extract()} zieht daraus die
 * Kategorie-Scores und die Kern-Web-Vitalwerte, damit nicht die volle Antwort
 * durch die Anwendung gereicht wird.
 */
final class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** Von Google unterstützte Prüf-Kategorien (Lighthouse). */
    public const CATEGORIES = ['performance', 'seo', 'accessibility', 'best-practices'];

    /** Lab-Kennwerte (Lighthouse-Audit-IDs), die {@see extract()} übernimmt. */
    private const METRIC_AUDITS = [
        'first-contentful-paint',
        'largest-contentful-paint',
        'cumulative-layout-shift',
        'total-blocking-time',
        'speed-index',
        'interactive',
    ];

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * Misst $url und liefert die reduzierten Kennzahlen.
     *
     * @param 'mobile'|'desktop' $strategy
     * @param list<string>       $categories Teilmenge von {@see CATEGORIES}
     * @return array<string, mixed>
     */
    public function run(string $url, string $strategy = 'mobile', array $categories = ['performance']): array
    {
        // Nur bekannte Kategorien; leere Auswahl → Performance als Vorgabe.
        $categories = array_values(array_intersect($categories, self::CATEGORIES));
        if ($categories === []) {
            $categories = ['performance'];
        }

        // category ist ein wiederholter Query-Parameter (category=performance&
        // category=seo…). http_build_query erzeugt aus einem Array category[0]=…
        // — das akzeptiert die API nicht, daher von Hand zusammensetzen.
        $params = ['url' => $url, 'strategy' => $strategy === 'desktop' ? 'desktop' : 'mobile'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $params['key'] = $this->apiKey;
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        foreach ($categories as $c) {
            $query .= '&category=' . rawurlencode($c);
        }

        $ch = curl_init(self::ENDPOINT . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            // PageSpeed misst live und kann je nach Zielseite lange brauchen.
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        return $this->extract($this->send($ch), $strategy);
    }

    /**
     * Führt die vorbereitete Anfrage aus und behandelt Transport-, JSON- und
     * HTTP-Fehler einheitlich (analog {@see SpeechClient}).
     *
     * @return array<string, mixed>
     */
    private function send(\CurlHandle $ch): array
    {
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            // Transportfehler (DNS, Verbindung, Timeout, TLS): Google wurde nicht
            // erreicht. Das cURL-Detail hilft beim Prüfen (z. B. Timeout).
            throw new ApiException('EPAGESPEED', 502, 'PAGESPEED-UNREACHABLE', [$err]);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $snippet = trim(preg_replace('/\s+/', ' ', substr((string) $raw, 0, 200)) ?? '');
            throw new ApiException('EPAGESPEED', 502, 'PAGESPEED-REQUEST-FAILED', [
                'HTTP ' . $status . ': ' . ($snippet === '' ? '(leere Antwort)' : $snippet),
            ]);
        }

        if ($status < 200 || $status >= 300) {
            // Google meldet Fehler als { error: { code, message, errors[] } }.
            $message = (string) ($data['error']['message'] ?? ('HTTP ' . $status));
            $reason = (string) ($data['error']['errors'][0]['reason'] ?? '');

            // Kontingent erschöpft: 429, oder 403 mit rate-/quota-Begründung.
            if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'], true)) {
                throw new ApiException('EPAGESPEED', 502, 'PAGESPEED-QUOTA-EXCEEDED');
            }
            // Ungültiger/gesperrter Schlüssel: 400/403 mit Schlüssel-Begründung.
            if (in_array($reason, ['keyInvalid', 'keyExpired', 'ipRefererBlocked', 'accessNotConfigured'], true)
                || $status === 401) {
                throw new ApiException('EPAGESPEED', 502, 'PAGESPEED-AUTH-FAILED', [$message]);
            }
            throw new ApiException('EPAGESPEED', 502, 'PAGESPEED-REQUEST-FAILED', [$message]);
        }

        return $data;
    }

    /**
     * Reduziert die Lighthouse-Antwort auf das Anzeigenötige: Kategorie-Scores
     * (0–100), Lab-Kennwerte als Zahl + lesbare Darstellung und — falls
     * vorhanden — das CrUX-Felddaten-Gesamturteil (echte Nutzererfahrung).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extract(array $data, string $strategy): array
    {
        $lh = is_array($data['lighthouseResult'] ?? null) ? $data['lighthouseResult'] : [];

        /** @var array<string, int> $scores */
        $scores = [];
        foreach (($lh['categories'] ?? []) as $key => $cat) {
            // score kann null sein (nicht anwendbar) — dann auslassen.
            if (is_array($cat) && isset($cat['score']) && is_numeric($cat['score'])) {
                $scores[(string) $key] = (int) round(((float) $cat['score']) * 100);
            }
        }

        /** @var array<string, array{value: float, display: string}> $metrics */
        $metrics = [];
        $audits = is_array($lh['audits'] ?? null) ? $lh['audits'] : [];
        foreach (self::METRIC_AUDITS as $id) {
            $audit = $audits[$id] ?? null;
            if (is_array($audit) && isset($audit['numericValue'])) {
                $metrics[$id] = [
                    'value' => (float) $audit['numericValue'],
                    'display' => (string) ($audit['displayValue'] ?? ''),
                ];
            }
        }

        return [
            'strategy' => $strategy,
            'analyzedUrl' => (string) ($lh['finalUrl'] ?? ($data['id'] ?? '')),
            'fetchTime' => (string) ($lh['fetchTime'] ?? ''),
            'scores' => $scores,
            'metrics' => $metrics,
            // CrUX-Gesamturteil der Felddaten: FAST | AVERAGE | SLOW | null.
            'fieldData' => isset($data['loadingExperience']['overall_category'])
                ? (string) $data['loadingExperience']['overall_category']
                : null,
        ];
    }
}
