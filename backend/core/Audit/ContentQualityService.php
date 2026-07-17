<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

use HugoCMS\FileManager\AnthropicClient;
use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\FileService;
use HugoCMS\FileManager\MountResolver;

/**
 * Prüft EINE Content-Datei per LLM auf redaktionelle Qualität (Lesbarkeit,
 * Dünn-Content, Keyword-Fokus, Tonalität, konkrete Verbesserungen). Bewusst pro
 * Seite und auf Abruf — ein Aufruf ist genau ein {@see AnthropicClient::createMessage}
 * (kein Werkzeug-Loop, kein Hintergrundprozess): Das bleibt hosting-tauglich und
 * folgt demselben Ein-Request-eine-Antwort-Prinzip wie der übrige Backend-Code.
 *
 * Die Ergebnisse liegen als JSON — eine Datei je geprüfter Seite — im
 * Speicherverzeichnis (keine Datenbank), sodass sich die bereits geprüften
 * Seiten auflisten lassen. Erneutes Prüfen überschreibt den vorhandenen Eintrag.
 *
 * Die Datei wird ausschließlich über {@see MountResolver}/{@see FileService}
 * gelesen — damit greifen Mount-Einsperrung, erlaubte Endungen und Größenlimit.
 * Es wird nie geschrieben; das erzwungene Werkzeug liefert nur Struktur.
 */
final class ContentQualityService
{
    /** Obergrenze des an das Modell gesendeten Fließtexts (Zeichen). */
    private const int MAX_BODY_CHARS = 24000;

    /** Antwortlänge des Modells. */
    private const int MAX_TOKENS = 2000;

    /** Erlaubtes Format eines Eintragsschlüssels (sha1). */
    private const string KEY_PATTERN = '/^[a-f0-9]{40}$/';

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly string $model,
        private readonly MountResolver $resolver,
        private readonly FileService $files,
        private readonly string $storageDir,
    ) {
    }

    /**
     * Prüft die durch die Dateimanager-ID bezeichnete Content-Datei und legt das
     * Ergebnis ab.
     *
     * @return array<string, mixed> Eintrag inkl. Verdikt
     */
    public function analyze(string $fileId, string $locale): array
    {
        $r = $this->resolver->resolve($fileId, true);
        $read = $this->files->readText($r['mount'], $r['abs']);

        [$title, $body, $truncated] = self::prepareBody((string) $read['content']);
        if ($body === '') {
            throw new ApiException('ECONTENT', 422, 'AUDIT-CONTENT-EMPTY', [(string) $read['name']]);
        }
        $title ??= (string) $read['name'];

        $verdict = $this->askModel($title, $body, $locale);

        $mountName = $r['mount']->name();
        $key = sha1($mountName . ':' . $r['rel']);
        $entry = [
            'key' => $key,
            'mount' => $mountName,
            'rel' => $r['rel'],
            'title' => $title,
            'checkedAt' => gmdate('c'),
            'model' => $this->model,
            'contentHash' => sha1($body),
            'truncated' => $truncated,
            'verdict' => $verdict,
            // Eine frische Prüfung löscht einen früheren Verbesserungs-Vermerk.
            'improvedAt' => null,
            'improveModel' => null,
        ];

        $this->persist($key, $entry);

        return $entry;
    }

    /**
     * Metadaten aller geprüften Seiten dieser Webseite, neueste zuerst (ohne die
     * ausführlichen Findings/Vorschläge).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->files() as $path) {
            $entry = $this->read($path);
            if ($entry === null) {
                continue;
            }
            $verdict = is_array($entry['verdict'] ?? null) ? $entry['verdict'] : [];
            $readability = is_array($verdict['readability'] ?? null) ? $verdict['readability'] : [];
            // Veraltet-Erkennung: aktuellen Prüf-Hash der Quelle mit dem
            // gespeicherten vergleichen. null = Quelle fehlt/nicht lesbar.
            $current = $this->currentBodyHash($entry['mount'] ?? null, $entry['rel'] ?? null);
            $out[] = [
                'key' => $entry['key'] ?? basename($path, '.json'),
                'mount' => $entry['mount'] ?? null,
                'rel' => $entry['rel'] ?? null,
                'title' => $entry['title'] ?? null,
                'checkedAt' => $entry['checkedAt'] ?? null,
                'model' => $entry['model'] ?? null,
                'contentHash' => $entry['contentHash'] ?? null,
                'score' => $verdict['score'] ?? null,
                'rating' => $readability['rating'] ?? null,
                'sourceMissing' => $current === null,
                'stale' => $current !== null && $current !== ($entry['contentHash'] ?? null),
                'improvedAt' => $entry['improvedAt'] ?? null,
                'improveModel' => $entry['improveModel'] ?? null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['checkedAt'] ?? ''), (string) ($a['checkedAt'] ?? '')));

        return $out;
    }

    /**
     * Vollständiger Eintrag einer geprüften Seite.
     *
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $entry = $this->read($this->pathFor($key));
        if ($entry === null) {
            throw ApiException::notFound('AUDIT-CONTENT-NOT-FOUND', [$key]);
        }

        return $entry;
    }

    /**
     * Gespeicherter Eintrag zur Datei hinter einer Dateimanager-ID oder null,
     * falls die Datei noch nie geprüft wurde. Löst die ID über den Resolver auf
     * (Mount-Einsperrung greift) und bildet daraus denselben Schlüssel wie beim
     * Prüfen.
     *
     * @return array<string, mixed>|null
     */
    public function forFile(string $fileId): ?array
    {
        $r = $this->resolver->resolve($fileId, true);

        return $this->read($this->pathFor(sha1($r['mount']->name() . ':' . $r['rel'])));
    }

    /**
     * Vermerkt eine KI-Bearbeitung an der Datei hinter einer Dateimanager-ID:
     * setzt {@see improvedAt}/{@see improveModel} am zugehörigen Eintrag. Kein
     * Eintrag (Datei nie geprüft) → No-op. Wird aus dem Schreibpfad des
     * Assistenten aufgerufen, darf den Schreibvorgang nie stören.
     */
    public function markImproved(string $fileId, string $model): void
    {
        try {
            $r = $this->resolver->resolve($fileId, true);
        } catch (\Throwable) {
            return;
        }
        $path = $this->pathFor(sha1($r['mount']->name() . ':' . $r['rel']));
        $entry = $this->read($path);
        if ($entry === null) {
            return;
        }
        $entry['improvedAt'] = gmdate('c');
        $entry['improveModel'] = $model;
        $this->persist($entry['key'] ?? sha1($r['mount']->name() . ':' . $r['rel']), $entry);
    }

    /**
     * Nimmt eine bereits verbesserte Datei wieder in die Arbeitsliste auf:
     * löscht {@see improvedAt}/{@see improveModel} (ohne Neuprüfung). Danach
     * erscheint sie wieder unter „zu verbessern", falls der Score < 100 ist.
     *
     * @return array<string, mixed> aktualisierter Eintrag
     */
    public function requeue(string $key): array
    {
        $path = $this->pathFor($key);
        $entry = $this->read($path);
        if ($entry === null) {
            throw ApiException::notFound('AUDIT-CONTENT-NOT-FOUND', [$key]);
        }
        $entry['improvedAt'] = null;
        $entry['improveModel'] = null;
        $this->persist($key, $entry);

        return $entry;
    }

    /**
     * Überschreibt die vom Benutzer editierbaren Teile eines Eintrags: die
     * Vorschlagsliste ({@see verdict.suggestions}) sowie ein optionales Freitext-
     * Feld {@see userInstruction}, das der KI-Verbesserung zusätzlich mitgegeben
     * wird. Die KI-Befunde ({@see verdict.findings}) und die Bewertung bleiben
     * unangetastet. Setzt einen {@see editedAt}-Vermerk. Eine spätere Neuprüfung
     * baut den Eintrag frisch auf und verwirft damit diese Bearbeitungen.
     *
     * @param list<mixed> $suggestions
     * @return array<string, mixed> aktualisierter Eintrag
     */
    public function updateEditable(string $key, array $suggestions, ?string $userInstruction): array
    {
        $path = $this->pathFor($key);
        $entry = $this->read($path);
        if ($entry === null) {
            throw ApiException::notFound('AUDIT-CONTENT-NOT-FOUND', [$key]);
        }

        // Vorschläge säubern: nur Strings, getrimmt, Leereinträge verwerfen,
        // Anzahl und Länge begrenzen (defensiv gegen fehlerhafte Eingaben).
        $clean = [];
        foreach ($suggestions as $s) {
            if (!is_string($s)) {
                continue;
            }
            $s = trim($s);
            if ($s !== '') {
                $clean[] = mb_substr($s, 0, 1000);
            }
            if (count($clean) >= 50) {
                break;
            }
        }

        $instruction = $userInstruction !== null ? trim($userInstruction) : '';
        $instruction = $instruction === '' ? null : mb_substr($instruction, 0, 4000);

        if (!isset($entry['verdict']) || !is_array($entry['verdict'])) {
            $entry['verdict'] = [];
        }
        $entry['verdict']['suggestions'] = $clean;
        $entry['userInstruction'] = $instruction;
        $entry['editedAt'] = gmdate('c');
        $this->persist($key, $entry);

        return $entry;
    }

    /**
     * Löscht einen Eintrag.
     *
     * @return array{deleted: string}
     */
    public function delete(string $key): array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            throw ApiException::notFound('AUDIT-CONTENT-NOT-FOUND', [$key]);
        }
        @unlink($path);

        return ['deleted' => $key];
    }

    // --- LLM ----------------------------------------------------------------

    /**
     * Ein einziger, strukturierter Modellaufruf. Das erzwungene Werkzeug
     * report_quality garantiert eine schema-konforme Antwort.
     *
     * @return array<string, mixed>
     */
    private function askModel(string $title, string $body, string $locale): array
    {
        $lang = str_starts_with(strtolower($locale), 'en') ? 'en' : 'de';
        $system = $lang === 'en'
            ? 'You are an experienced editorial and SEO proofreader. Assess ONLY the page text supplied by the user. Do not invent facts and do not assume content that is not present. Report your assessment via the report_quality tool. Write all free-text fields in English.'
            : 'Du bist ein erfahrener Redaktions- und SEO-Lektor. Bewerte AUSSCHLIESSLICH den vom Nutzer gelieferten Seitentext. Erfinde keine Fakten und unterstelle keine nicht vorhandenen Inhalte. Melde deine Bewertung über das Werkzeug report_quality. Formuliere alle Freitextfelder auf Deutsch.';

        $userText = ($lang === 'en' ? "Title: " : "Titel: ") . $title . "\n\n" . $body;

        $payload = [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $userText],
            ],
            'tools' => [self::toolDef()],
            'tool_choice' => ['type' => 'tool', 'name' => 'report_quality'],
        ];

        $response = $this->client->createMessage($payload);

        foreach ($response['content'] ?? [] as $block) {
            if (is_array($block)
                && ($block['type'] ?? '') === 'tool_use'
                && ($block['name'] ?? '') === 'report_quality'
                && is_array($block['input'] ?? null)) {
                return self::normalizeVerdict($block['input']);
            }
        }

        throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', ['kein strukturiertes Ergebnis']);
    }

    /**
     * Werkzeugdefinition, die das Antwortschema erzwingt. Maschinenwerte
     * (rating, severity) sind stabil englisch; der Client übersetzt sie.
     *
     * `strict` lässt die API das Schema durchsetzen, statt es dem Modell nur
     * nahezulegen — ohne das kann die Antwort verschachtelte Felder flach oder
     * als Zeichenkette liefern. Dafür verlangt die API auf jedem Objekt
     * `additionalProperties: false` samt vollständigem `required`; Zahlen- und
     * Längengrenzen (minimum/maximum) unterstützt sie nicht, die gehören in die
     * Beschreibung.
     *
     * @return array<string, mixed>
     */
    private static function toolDef(): array
    {
        return [
            'name' => 'report_quality',
            'description' => 'Report the editorial/SEO quality assessment of the supplied page text.',
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'score' => [
                        'type' => 'integer',
                        'description' => 'Overall content quality from 0 (poor) to 100 (excellent).',
                    ],
                    'summary' => ['type' => 'string', 'description' => 'One or two sentences summarising the assessment.'],
                    'readability' => [
                        'type' => 'object',
                        'properties' => [
                            'rating' => ['type' => 'string', 'enum' => ['good', 'medium', 'weak']],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['rating', 'note'],
                        'additionalProperties' => false,
                    ],
                    'findings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'severity' => ['type' => 'string', 'enum' => ['hint', 'warning']],
                                'title' => ['type' => 'string'],
                                'detail' => ['type' => 'string'],
                            ],
                            'required' => ['severity', 'title', 'detail'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'suggestions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Concrete, actionable improvement suggestions.',
                    ],
                ],
                'required' => ['score', 'summary', 'readability', 'findings', 'suggestions'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Prüft und säubert das Modellergebnis, bevor es abgelegt wird.
     *
     * Nötig, weil die API `tool_use.input` nur bei Modellen mit `strict`-Support
     * gegen das Schema prüft. Ohne diese Schranke landet eine misslungene Antwort
     * (etwa `findings` als Zeichenkette statt Liste) unbemerkt im Speicher, und
     * der Client iteriert die Zeichenkette zeichenweise.
     *
     * Fehlt die Grundstruktur, ist das Ergebnis unbrauchbar → Fehler statt
     * Teilbericht: erneutes Prüfen liefert in aller Regel ein sauberes Verdikt.
     * Einzelne unbrauchbare Befunde/Vorschläge werden nur verworfen.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalizeVerdict(array $input): array
    {
        $score = $input['score'] ?? null;
        $summary = $input['summary'] ?? null;
        $readability = $input['readability'] ?? null;
        if (!is_numeric($score)
            || !is_string($summary)
            || trim($summary) === ''
            || !is_array($readability)
            || !in_array($readability['rating'] ?? null, ['good', 'medium', 'weak'], true)
            || !is_array($input['findings'] ?? null)) {
            throw new ApiException('EAI', 502, 'AI-REQUEST-FAILED', ['unbrauchbares Ergebnis']);
        }

        $findings = [];
        foreach ($input['findings'] as $f) {
            if (is_array($f)
                && in_array($f['severity'] ?? null, ['hint', 'warning'], true)
                && is_string($f['title'] ?? null)
                && is_string($f['detail'] ?? null)) {
                $findings[] = [
                    'severity' => $f['severity'],
                    'title' => $f['title'],
                    'detail' => $f['detail'],
                ];
            }
        }

        $suggestions = [];
        foreach (is_array($input['suggestions'] ?? null) ? $input['suggestions'] : [] as $s) {
            if (is_string($s) && trim($s) !== '') {
                $suggestions[] = $s;
            }
        }

        return [
            'score' => max(0, min(100, (int) $score)),
            'summary' => $summary,
            'readability' => [
                'rating' => $readability['rating'],
                'note' => is_string($readability['note'] ?? null) ? $readability['note'] : '',
            ],
            'findings' => $findings,
            'suggestions' => $suggestions,
        ];
    }

    // --- Textaufbereitung ---------------------------------------------------

    /**
     * Zerlegt den Rohinhalt in Titel (aus dem Front-Matter) und den zu prüfenden
     * Fließtext — getrimmt und auf {@see MAX_BODY_CHARS} begrenzt. Dieselbe
     * Aufbereitung liefert den Prüf-Hash (sha1 des Body), sodass sich später
     * erkennen lässt, ob die Quelle seit der Prüfung geändert wurde.
     *
     * @return array{0: ?string, 1: string, 2: bool} [title, body, truncated]
     */
    private static function prepareBody(string $raw): array
    {
        [$front, $body] = self::splitFrontMatter($raw);
        $body = trim($body);
        $truncated = false;
        if (mb_strlen($body) > self::MAX_BODY_CHARS) {
            $body = mb_substr($body, 0, self::MAX_BODY_CHARS);
            $truncated = true;
        }

        return [self::extractTitle($front), $body, $truncated];
    }

    /**
     * Aktueller Prüf-Hash der Quelldatei (Mount + Relativpfad) oder null, wenn
     * der Mount weg, die Datei gelöscht oder nicht lesbar ist. Für die
     * Veraltet-Erkennung in {@see list()}.
     */
    private function currentBodyHash(mixed $mountName, mixed $rel): ?string
    {
        if (!is_string($mountName) || !is_string($rel) || !isset($this->resolver->all()[$mountName])) {
            return null;
        }
        try {
            $r = $this->resolver->resolve($this->resolver->encodeId($mountName, $rel), true);
            $read = $this->files->readText($r['mount'], $r['abs']);
        } catch (\Throwable) {
            return null;
        }
        [, $body] = self::prepareBody((string) $read['content']);

        return sha1($body);
    }

    // --- Front-Matter -------------------------------------------------------

    /**
     * Trennt einen führenden Hugo-Front-Matter-Block (TOML `+++` oder YAML `---`)
     * vom Fließtext. Ohne Parser — best effort per Delimiter.
     *
     * @return array{0: string, 1: string} [front-matter, body]
     */
    private static function splitFrontMatter(string $raw): array
    {
        // BOM entfernen, führende Leerzeilen tolerieren.
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (preg_match('/^\s*(\+\+\+|---)\r?\n(.*?)\r?\n\1\r?\n?(.*)$/s', $text, $m) === 1) {
            return [$m[2], $m[3]];
        }

        return ['', $text];
    }

    /** Liest das title-Feld aus einem Front-Matter-Block (TOML/YAML). */
    private static function extractTitle(string $front): ?string
    {
        if ($front === '') {
            return null;
        }
        if (preg_match('/^\s*title\s*[:=]\s*["\']?(.+?)["\']?\s*$/mi', $front, $m) === 1) {
            $title = trim($m[1]);

            return $title === '' ? null : $title;
        }

        return null;
    }

    // --- Speicher -----------------------------------------------------------

    /** @param array<string, mixed> $entry */
    private function persist(string $key, array $entry): void
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new ApiException('EIO', 500, 'AUDIT-STORAGE-FAILED');
        }
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($this->pathFor($key), $json, LOCK_EX) === false) {
            throw new ApiException('EIO', 500, 'AUDIT-STORAGE-FAILED');
        }
    }

    /**
     * Gespeicherte Einträge (Dateipfade); Sortierung erfolgt in {@see list()}.
     *
     * @return list<string>
     */
    private function files(): array
    {
        if (!is_dir($this->storageDir)) {
            return [];
        }

        return array_values(glob($this->storageDir . '/*.json') ?: []);
    }

    private function pathFor(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw ApiException::badRequest('AUDIT-CONTENT-NOT-FOUND', [$key]);
        }

        return $this->storageDir . '/' . $key . '.json';
    }

    /**
     * Liest und dekodiert einen Eintrag oder null bei Fehler.
     *
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
