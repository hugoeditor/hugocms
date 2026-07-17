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

    /**
     * Kern-Web-Vitalwerte (Labormessung, Lighthouse-Audit-IDs) — die
     * wichtigsten Ladekennwerte, die {@see extract()} als „metrics" übernimmt.
     */
    private const METRIC_AUDITS = [
        'first-contentful-paint',
        'largest-contentful-paint',
        'cumulative-layout-shift',
        'total-blocking-time',
        'speed-index',
        'interactive',
    ];

    /**
     * Weitere Diagnose-Kennwerte (Gruppe C): Server-Antwortzeit, Seitengewicht,
     * DOM-Größe, JavaScript-Ausführungs- und Hauptthread-Zeit. Ebenfalls als
     * „metrics" übernommen; das Panel zeigt sie in einem eigenen Abschnitt.
     */
    private const DIAGNOSTIC_AUDITS = [
        'server-response-time',
        'total-byte-weight',
        'dom-size',
        'bootup-time',
        'mainthread-work-breakdown',
    ];

    /**
     * Ausweich-IDs für Kennwerte, die Lighthouse 13 in „Insight"-Audits
     * umbenannt hat: fehlt die Original-ID, wird die Insight-Variante genutzt.
     */
    private const METRIC_ALIASES = [
        'dom-size' => 'dom-size-insight',
    ];

    /** Obergrenze der übernommenen Optimierungs-Chancen (Gruppe B). */
    private const MAX_OPPORTUNITIES = 12;

    /**
     * Obergrenze der Detailposten je Chance. Gekürzt gespeichert: die
     * wirkungsvollsten fünf Verursacher genügen; sind sie behoben, rücken beim
     * nächsten Lauf die nächsten nach (jeder Lauf misst frisch).
     */
    private const MAX_OPPORTUNITY_ITEMS = 5;

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
     * @param ?string            $locale     Sprache der Lighthouse-Texte (z. B. "de");
     *                                        wirkt v. a. auf die Titel der Chancen (B)
     * @return array<string, mixed>
     */
    public function run(string $url, string $strategy = 'mobile', array $categories = ['performance'], ?string $locale = null): array
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
        // Nur eine schlichte Sprachkennung durchreichen (a-z), nie roh.
        if ($locale !== null && preg_match('/^[a-z]{2}$/', $locale) === 1) {
            $params['locale'] = $locale;
        }
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
     * HTTP-Fehler einheitlich (analog {@see SeoSuccessClient}).
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
     * Reduziert die Lighthouse-Antwort auf das Anzeigenötige. Alles stammt aus
     * derselben Antwort — kein zusätzlicher Abruf:
     *   scores        Kategorie-Scores 0–100.
     *   metrics       Kern- und Diagnose-Kennwerte (Labormessung) als Zahl +
     *                 lesbare Darstellung.
     *   opportunities Optimierungs-Chancen mit geschätzter Zeitersparnis (B).
     *   fieldData     CrUX-Felddaten (echte Nutzererfahrung) je Metrik mit
     *                 Verteilung gut/verbesserungswürdig/schlecht — für die
     *                 URL und die gesamte Domain (A).
     *   runWarnings   Lauf-Warnungen; environment die Messbedingungen (E).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extract(array $data, string $strategy): array
    {
        $lh = is_array($data['lighthouseResult'] ?? null) ? $data['lighthouseResult'] : [];
        $audits = is_array($lh['audits'] ?? null) ? $lh['audits'] : [];

        /** @var array<string, int> $scores */
        $scores = [];
        foreach (($lh['categories'] ?? []) as $key => $cat) {
            // score kann null sein (nicht anwendbar) — dann auslassen.
            if (is_array($cat) && isset($cat['score']) && is_numeric($cat['score'])) {
                $scores[(string) $key] = (int) round(((float) $cat['score']) * 100);
            }
        }

        // Kern- (A/Bestand) und Diagnose-Kennwerte (C) in EINE Map — das Panel
        // ordnet sie über zwei feste Reihenfolgen in Abschnitte.
        /** @var array<string, array{value: float, display: string}> $metrics */
        $metrics = [];
        foreach ([...self::METRIC_AUDITS, ...self::DIAGNOSTIC_AUDITS] as $id) {
            // Original-ID; fehlt sie, die umbenannte Insight-Variante (Lighthouse 13).
            $audit = $audits[$id] ?? $audits[self::METRIC_ALIASES[$id] ?? ''] ?? null;
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
            'opportunities' => self::opportunities($audits),
            'fieldData' => [
                'url' => self::fieldExperience($data['loadingExperience'] ?? null),
                'origin' => self::fieldExperience($data['originLoadingExperience'] ?? null),
            ],
            'runWarnings' => array_values(array_filter(
                array_map(static fn (mixed $w): string => is_string($w) ? $w : '', (array) ($lh['runWarnings'] ?? [])),
                static fn (string $w): bool => $w !== '',
            )),
            'environment' => [
                'device' => (string) ($lh['configSettings']['formFactor'] ?? $strategy),
                'throttling' => (string) ($lh['configSettings']['throttlingMethod'] ?? ''),
                'lighthouseVersion' => (string) ($lh['lighthouseVersion'] ?? ''),
            ],
        ];
    }

    /**
     * Optimierungs-Chancen (B): Audits mit geschätzter Ersparnis (Zeit ODER
     * Datenmenge), nach Wirkung absteigend, gekappt auf {@see MAX_OPPORTUNITIES}.
     * Je Chance die konkreten Verursacher (Detailtabelle), gekürzt auf die
     * wirkungsvollsten {@see MAX_OPPORTUNITY_ITEMS}.
     *
     * Erkennung versionsübergreifend: bis Lighthouse 11 über das
     * opportunity-Detail samt overallSavingsMs; ab 12/13 über
     * scoreDisplayMode „metricSavings". Wichtig: Ab Lighthouse 13 kann eine
     * Chance NUR Byte-Ersparnis haben (metricSavings = 0 ms) — etwa die
     * Bildauslieferung. Deshalb zählt Byte-Ersparnis gleichwertig. Audits, die
     * bereits als Diagnose-Kennwert (Abschnitt C) erscheinen, werden nicht
     * doppelt gelistet.
     *
     * @param array<string, mixed> $audits
     * @return list<array{id: string, title: string, display: string, savingsMs: int, savingsBytes: ?int, items: list<array{label: string, bytes: ?int, ms: ?int}>, moreItems: int}>
     */
    private static function opportunities(array $audits): array
    {
        $metricIds = array_flip([...self::METRIC_AUDITS, ...self::DIAGNOSTIC_AUDITS, ...array_values(self::METRIC_ALIASES)]);

        $out = [];
        foreach ($audits as $id => $audit) {
            if (!is_array($audit) || isset($metricIds[$id])) {
                continue;
            }
            $details = is_array($audit['details'] ?? null) ? $audit['details'] : [];
            // Chance = Audit mit Metrik-Ersparnis (Lighthouse 12/13) bzw. das
            // alte opportunity-Detail (≤11).
            if (($audit['scoreDisplayMode'] ?? '') !== 'metricSavings' && ($details['type'] ?? '') !== 'opportunity') {
                continue;
            }
            // Nur unvollständige Prüfungen (bestandene haben nichts zu tun).
            $score = is_numeric($audit['score'] ?? null) ? (float) $audit['score'] : null;
            if ($score !== null && $score >= 1.0) {
                continue;
            }
            // Ersparnis: Zeit (ms) und/oder Datenmenge (Bytes).
            $savingsMs = self::intOrNull($details['overallSavingsMs'] ?? null) ?? 0;
            if ($savingsMs < 1) {
                $savingsMs = self::metricSavingsMax($audit['metricSavings'] ?? null);
            }
            $savingsBytes = self::opportunityBytes($details);
            // Spürbare Ersparnis verlangen: mindestens 1 ms Zeit ODER 1 KiB
            // Daten. So fällt Rauschen wie „0 KiB" (wenige hundert Byte) weg.
            if ($savingsMs < 1 && ($savingsBytes ?? 0) < 1024) {
                continue;
            }
            [$items, $total] = self::opportunityItems($details);
            $out[] = [
                'id' => (string) $id,
                'title' => (string) ($audit['title'] ?? $id),
                // Lighthouses eigene, lokalisierte Ersparnis-Angabe (z. B.
                // „Geschätzte Einsparung von 127 KiB" oder „… von 0,3 s").
                'display' => (string) ($audit['displayValue'] ?? ''),
                'savingsMs' => $savingsMs,
                'savingsBytes' => $savingsBytes,
                'items' => $items,
                'moreItems' => max(0, $total - count($items)),
            ];
        }
        // Nach Zeit-, bei Gleichstand nach Datenersparnis absteigend.
        usort($out, static fn (array $a, array $b): int =>
            ($b['savingsMs'] <=> $a['savingsMs']) ?: (($b['savingsBytes'] ?? 0) <=> ($a['savingsBytes'] ?? 0)));

        return array_slice($out, 0, self::MAX_OPPORTUNITIES);
    }

    /**
     * Geschätzte Byte-Ersparnis einer Chance: bevorzugt details.overallSavingsBytes
     * (≤11), sonst die Summe der wastedBytes aller Posten (12/13). null, wenn es
     * keine Byte-Ersparnis gibt.
     *
     * @param array<string, mixed> $details
     */
    private static function opportunityBytes(array $details): ?int
    {
        $overall = self::intOrNull($details['overallSavingsBytes'] ?? null);
        if ($overall !== null) {
            return $overall;
        }
        $sum = 0;
        $any = false;
        foreach ((array) ($details['items'] ?? []) as $item) {
            if (is_array($item) && is_numeric($item['wastedBytes'] ?? null)) {
                $sum += (int) round((float) $item['wastedBytes']);
                $any = true;
            }
        }

        return $any ? $sum : null;
    }

    /**
     * Konkrete Verursacher einer Chance aus deren details.items: je Posten die
     * bezeichnende Ressource (URL bzw. DOM-Element) und die Ersparnis in Bytes
     * oder Millisekunden. Lighthouse liefert die Posten bereits nach Wirkung
     * sortiert; wir übernehmen sie in dieser Reihenfolge und kürzen auf die
     * ersten {@see MAX_OPPORTUNITY_ITEMS}.
     *
     * @param array<string, mixed> $details
     * @return array{0: list<array{label: string, bytes: ?int, ms: ?int}>, 1: int} [Posten, Gesamtzahl]
     */
    private static function opportunityItems(array $details): array
    {
        $items = is_array($details['items'] ?? null) ? $details['items'] : [];
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = self::itemLabel($item);
            if ($label === '') {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'bytes' => self::intOrNull($item['wastedBytes'] ?? $item['totalBytes'] ?? null),
                'ms' => self::intOrNull($item['wastedMs'] ?? null),
            ];
        }

        return [array_slice($rows, 0, self::MAX_OPPORTUNITY_ITEMS), count($rows)];
    }

    /**
     * Bezeichnung eines Detailpostens: bevorzugt die Ressourcen-URL, sonst das
     * DOM-Element (Beschriftung/Selektor/Ausschnitt), sonst der erste
     * String-Wert. Leer, wenn nichts Brauchbares vorliegt.
     *
     * @param array<string, mixed> $item
     */
    private static function itemLabel(array $item): string
    {
        if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
            return $item['url'];
        }
        if (is_array($item['node'] ?? null)) {
            $node = $item['node'];
            $label = (string) ($node['nodeLabel'] ?? $node['selector'] ?? $node['snippet'] ?? '');
            if ($label !== '') {
                return $label;
            }
        }
        foreach ($item as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** Wandelt einen numerischen Wert in einen gerundeten Ganzzahlwert oder null. */
    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * Größte metrische Zeitersparnis aus audit.metricSavings (Lighthouse 12+),
     * in Millisekunden. CLS wird übersprungen (einheitenlos, keine ms). 0, wenn
     * nichts vorliegt.
     *
     * @param mixed $metricSavings Erwartet eine Map Metrik → Ersparnis
     */
    private static function metricSavingsMax(mixed $metricSavings): int
    {
        if (!is_array($metricSavings)) {
            return 0;
        }
        $max = 0;
        foreach ($metricSavings as $metric => $value) {
            if (strtoupper((string) $metric) === 'CLS' || !is_numeric($value)) {
                continue;
            }
            $max = max($max, (int) round((float) $value));
        }

        return $max;
    }

    /**
     * Reduziert eine CrUX-Erfahrung (loadingExperience/originLoadingExperience)
     * auf Gesamturteil und je Metrik Perzentil, Kategorie und die Verteilung
     * gut/verbesserungswürdig/schlecht. Generisch über die vorhandenen Metriken,
     * um Google-Schlüsselnamen nicht fest zu verdrahten. null, wenn keine
     * Felddaten vorliegen (zu wenig realer Verkehr).
     *
     * @return ?array{overallCategory: string, metrics: array<string, array{percentile: ?float, category: string, good: float, needsImprovement: float, poor: float}>}
     */
    private static function fieldExperience(mixed $exp): ?array
    {
        if (!is_array($exp) || !is_array($exp['metrics'] ?? null) || $exp['metrics'] === []) {
            return null;
        }
        $metrics = [];
        foreach ($exp['metrics'] as $key => $m) {
            if (!is_array($m)) {
                continue;
            }
            // distributions: drei Eimer in fester Reihenfolge gut/mittel/schlecht.
            $prop = [];
            foreach ((array) ($m['distributions'] ?? []) as $d) {
                $prop[] = is_array($d) && isset($d['proportion']) ? (float) $d['proportion'] : 0.0;
            }
            $metrics[(string) $key] = [
                'percentile' => isset($m['percentile']) && is_numeric($m['percentile']) ? (float) $m['percentile'] : null,
                'category' => (string) ($m['category'] ?? ''),
                'good' => $prop[0] ?? 0.0,
                'needsImprovement' => $prop[1] ?? 0.0,
                'poor' => $prop[2] ?? 0.0,
            ];
        }

        return [
            'overallCategory' => (string) ($exp['overall_category'] ?? ''),
            'metrics' => $metrics,
        ];
    }
}
