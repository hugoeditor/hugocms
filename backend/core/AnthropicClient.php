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
     * claude-fable-5 ist bewusst NICHT mehr ausgefiltert — es steht als
     * stärkstes Modell zur Wahl. Zu beachten: doppelter Preis gegenüber Opus 5
     * und der zusätzliche Abbruchgrund „refusal", bei dem der AssistantService
     * eine leere Antwort zurückgibt.
     */
    private const HIDDEN_MODELS = [
        'claude-opus-4-5',
        'claude-opus-4-1',
        'claude-sonnet-4-5',
    ];

    /**
     * Absolute Obergrenze einer Anfrage als Sicherheitsnetz. Die eigentliche
     * Abbruchbedingung ist $timeout — siehe createMessage().
     */
    private const HARD_TIMEOUT = 900;

    /** Zeit für den Verbindungsaufbau; davon getrennt von der Antwortdauer. */
    private const CONNECT_TIMEOUT = 15;

    /**
     * @param int $timeout Zeit ohne EINEN empfangenen Datenblock, nach der
     *                     abgebrochen wird (nicht die Gesamtdauer der Anfrage).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {
    }

    /**
     * Schickt einen Messages-Request und liefert die dekodierte Antwort.
     *
     * Die Anfrage läuft IMMER im Streaming-Modus, auch wenn nach außen eine
     * fertige Antwort zurückkommt: Ohne Streaming schickt die API bis zum Ende
     * der Generierung kein einziges Byte, und ein längerer Schreibvorgang
     * (mehrere tausend Tokens Ausgabe) lief regelmäßig in die Zeitüberschreitung
     * — „timed out with 0 bytes received". Im Streaming-Modus treffen laufend
     * Datenblöcke ein; abgebrochen wird deshalb nicht nach einer festen
     * Gesamtdauer, sondern erst, wenn $timeout Sekunden lang NICHTS mehr kommt
     * (CURLOPT_LOW_SPEED_*). Die Einzelteile setzt assembleStream() wieder zu
     * genau der Antwort zusammen, die der nicht-streamende Aufruf liefern würde.
     *
     * Nach außen bleibt alles wie bisher: eine Anfrage, eine JSON-Antwort. Zum
     * Client wird nichts gestreamt (kein SSE) — die Hosting-Kompatibilität
     * bleibt damit unberührt.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createMessage(array $payload): array
    {
        $payload['stream'] = true;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new ApiException('EAI', 500, 'AI-REQUEST-FAILED', ['JSON-Kodierung fehlgeschlagen']);
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::HARD_TIMEOUT,
            // Abbruch bei Stillstand: weniger als 1 Byte/s über $timeout
            // Sekunden. Eine laufende Generierung sendet regelmäßig Blöcke
            // (mindestens die ping-Ereignisse) und läuft daher weiter, egal wie
            // lang die Antwort insgesamt wird.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $this->timeout,
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

        // Erfolgsfall: Ereignisstrom (text/event-stream). Fehler beantwortet die
        // API dagegen als gewöhnliches JSON — beides unten am Status getrennt.
        if ($status >= 200 && $status < 300) {
            return $this->assembleStream((string) $raw);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', ['ungültige Antwort']);
        }
        // Ab hier ausschließlich Fehlerstatus — der Erfolgsfall ist oben
        // abgehandelt.
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

    /**
     * Setzt den Ereignisstrom (SSE) wieder zu der Antwort zusammen, die ein
     * nicht-streamender Aufruf geliefert hätte: message_start bringt das Gerüst,
     * content_block_start/-_delta/-_stop füllen die Inhaltsblöcke, message_delta
     * trägt Abbruchgrund und Token-Zählung nach.
     *
     * Wichtig für den Werkzeug-Ablauf: thinking-Blöcke behalten ihre Signatur
     * und tool_use-Blöcke bekommen ihr `input` als Objekt zurück (im Strom
     * kommen die Argumente als JSON-Bruchstücke). Nur so lässt sich der
     * Antwortblock unverändert in den nächsten Zug übernehmen.
     *
     * @return array<string, mixed>
     */
    private function assembleStream(string $raw): array
    {
        $message = null;
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];
        /** @var array<int, string> $partialJson Argumente eines tool_use-Blocks */
        $partialJson = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue; // Ereignisnamen (event:) und Leerzeilen überspringen
            }
            $event = json_decode(trim(substr($line, 5)), true);
            if (!is_array($event)) {
                continue;
            }

            switch ($event['type'] ?? '') {
                case 'message_start':
                    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
                    break;

                case 'content_block_start':
                    $index = (int) ($event['index'] ?? 0);
                    $blocks[$index] = is_array($event['content_block'] ?? null) ? $event['content_block'] : [];
                    $partialJson[$index] = '';
                    break;

                case 'content_block_delta':
                    $index = (int) ($event['index'] ?? 0);
                    $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    switch ($delta['type'] ?? '') {
                        case 'text_delta':
                            $blocks[$index]['text'] = ($blocks[$index]['text'] ?? '') . (string) ($delta['text'] ?? '');
                            break;
                        case 'thinking_delta':
                            $blocks[$index]['thinking'] = ($blocks[$index]['thinking'] ?? '') . (string) ($delta['thinking'] ?? '');
                            break;
                        case 'signature_delta':
                            $blocks[$index]['signature'] = ($blocks[$index]['signature'] ?? '') . (string) ($delta['signature'] ?? '');
                            break;
                        case 'input_json_delta':
                            $partialJson[$index] = ($partialJson[$index] ?? '') . (string) ($delta['partial_json'] ?? '');
                            break;
                    }
                    break;

                case 'content_block_stop':
                    $index = (int) ($event['index'] ?? 0);
                    if (($blocks[$index]['type'] ?? '') === 'tool_use') {
                        $decoded = json_decode($partialJson[$index] ?? '', true);
                        // Leere Argumente MÜSSEN als Objekt zurückgehen: ein
                        // leeres PHP-Array würde beim nächsten Zug als []
                        // kodiert, und die API erwartet dort {}.
                        $blocks[$index]['input'] = is_array($decoded) && $decoded !== [] ? $decoded : new \stdClass();
                    }
                    break;

                case 'message_delta':
                    $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                    foreach (['stop_reason', 'stop_sequence'] as $field) {
                        if (array_key_exists($field, $delta)) {
                            $message[$field] = $delta[$field];
                        }
                    }
                    if (is_array($event['usage'] ?? null)) {
                        $message['usage'] = array_merge(
                            is_array($message['usage'] ?? null) ? $message['usage'] : [],
                            $event['usage'],
                        );
                    }
                    break;

                case 'error':
                    // Fehler MITTEN im Strom (z. B. overloaded_error). Die
                    // bereits empfangenen Teile sind dann unbrauchbar.
                    $errorMessage = is_array($event['error'] ?? null)
                        ? (string) ($event['error']['message'] ?? 'Fehler im Antwortstrom')
                        : 'Fehler im Antwortstrom';
                    throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', [$errorMessage]);
            }
        }

        if (!is_array($message)) {
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', ['unvollständiger Antwortstrom']);
        }

        ksort($blocks);
        $message['content'] = array_values($blocks);

        return $message;
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
