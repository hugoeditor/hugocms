<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;
use Throwable;

/**
 * KI-Assistent: führt das Gespräch mit Claude und übersetzt dessen Werkzeug-
 * aufrufe in Dateioperationen. Die Werkzeuge sind dünne Hüllen um FileService
 * und MountResolver — der Assistent erbt damit exakt die Sicherheits- und
 * Rechteschicht der übrigen Befehle (Mount-Einsperrung, permissions, erlaubte
 * Endungen). Pfade haben die lesbare Form "<mount>/<relativer/pfad>".
 *
 * Der Ablauf ist ZUSTANDSLOS: Der Client hält den Gesprächsverlauf
 * (Anthropic-Nachrichtenformat) und schickt ihn bei jedem Zug mit. Im Modus
 * "confirm" pausiert der Loop vor einer Schreibaktion und gibt sie als
 * "pending" an den Client zurück; nach Bestätigung wird derselbe Verlauf mit
 * einer Entscheidung erneut gesendet.
 */
final class AssistantService
{
    /** Obergrenze für Werkzeug-Runden je Zug (Schutz vor Endlosschleifen). */
    private const MAX_STEPS = 16;
    private const MAX_TOKENS = 16000;

    /** Werkzeuge, die schreiben (im Modus confirm bestätigungspflichtig). */
    private const WRITE_TOOLS = ['write_file', 'create_dir', 'rename', 'delete', 'move'];

    /** Projektweite Anweisungsdatei (im Wurzelverzeichnis eines Mounts). */
    private const INSTRUCTION_FILE = '.hugocms-assistant.md';
    private const MAX_INSTRUCTION_BYTES = 8192;

    /**
     * @param ?\Closure(string): array<string, mixed> $fileReport Liefert zu einer
     *        Dateimanager-ID den Gesamt-Bericht (Qualität + SEO) einer Content-
     *        Datei. null → das Werkzeug get_file_report wird nicht angeboten
     *        (kein Pro-Audit verfügbar).
     * @param ?\Closure(string): void $onWrite Wird nach jedem erfolgreichen
     *        write_file mit der fileId der Datei aufgerufen (z. B. um eine
     *        KI-Bearbeitung am Content-Qualitäts-Eintrag zu vermerken). Fehler
     *        dort dürfen den Schreibvorgang nicht kippen.
     * @param ?\Closure(Mount, string, string, string): void $draftSink Ist er
     *        gesetzt, KANN ein write_file als Entwurf zur Freigabe abgelegt
     *        werden statt in die Datei zu gehen; die Live-Datei bleibt dann
     *        unangetastet und onWrite entfällt (der Vermerk greift erst bei der
     *        Freigabe). Aufruf mit (Mount, rel, abs, content). Ob der Weg
     *        genommen wird, entscheidet der Schreibmodus: `auto` legt IMMER
     *        Entwürfe an (gestaffelte Veröffentlichung), in `confirm` wählt der
     *        Benutzer je Schreibvorgang mit der Antwort `draft`. null → kein
     *        Entwurfsweg (kein Hugo-Projekt, also kein draft/publishDate).
     * @param bool $forceAdaptiveThinking Erzwingt adaptives Thinking auch für
     *        Modelle außerhalb der Positivliste (globale Einstellung [ai]
     *        force_thinking). Für ein Modell, das es doch nicht kann, lehnt die
     *        API dann mit einem Fehler ab.
     */
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly string $model,
        private readonly string $writeMode, // readonly | confirm | auto
        private readonly MountResolver $resolver,
        private readonly FileService $files,
        private readonly ?\Closure $fileReport = null,
        private readonly ?\Closure $onWrite = null,
        private readonly ?\Closure $draftSink = null,
        private readonly bool $forceAdaptiveThinking = false,
    ) {
    }

    /**
     * Führt einen Assistenten-Zug aus.
     *
     * @param array<int, mixed> $messages Verlauf im Anthropic-Format
     * @param ?string $confirm 'allow' | 'draft' | 'reject' beantwortet eine
     *                         pending Schreibaktion; null für einen normalen
     *                         Zug. 'draft' legt den Vorschlag als Entwurf zur
     *                         Freigabe ab, statt live zu schreiben — nur bei
     *                         write_file und nur mit gesetztem draftSink
     * @param string $locale Sprachkürzel für die Antwort
     * @return array{messages: array, reply: string, actions: array, pending: ?array}
     */
    public function run(array $messages, ?string $confirm, string $locale, ?string $openFilePath = null, ?string $openDirPath = null): array
    {
        $system = $this->systemPrompt($locale, $openFilePath, $openDirPath);
        $tools = $this->toolDefs();
        $actions = [];

        // Bestätigung einer pausierten Schreibaktion: das zuletzt angeforderte
        // Werkzeug ausführen bzw. ablehnen, dann normal weiterlaufen.
        if ($confirm !== null) {
            $messages = $this->resolvePending($messages, $confirm, $actions);
        }

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $payload = [
                'model' => $this->model,
                'max_tokens' => self::MAX_TOKENS,
                'system' => $system,
                'messages' => $messages,
                'tools' => $tools,
                // Höchstens ein Werkzeug pro Antwort — vereinfacht den
                // Bestätigungsablauf (immer genau eine pending Aktion).
                'tool_choice' => ['type' => 'auto', 'disable_parallel_tool_use' => true],
            ];
            // Adaptives Thinking gibt es erst ab der 4.6-Generation. Ältere
            // Modelle (z. B. das günstige claude-haiku-4-5 als model_cron)
            // lehnen den Parameter mit einem 400 ab — deshalb nur setzen, wenn
            // das gewählte Modell es kann.
            if ($this->supportsAdaptiveThinking($this->model)) {
                $payload['thinking'] = ['type' => 'adaptive'];
            }
            $response = $this->client->createMessage($payload);

            $content = is_array($response['content'] ?? null) ? $response['content'] : [];
            // Den Assistenten-Block UNVERÄNDERT übernehmen (inkl. thinking-
            // Blöcke mit Signatur) — sonst lehnt die API den nächsten Zug ab.
            $messages[] = ['role' => 'assistant', 'content' => $content];

            if (($response['stop_reason'] ?? null) !== 'tool_use') {
                return $this->result($messages, $this->textFromContent($content), $actions, null);
            }

            $toolUse = $this->firstToolUse($content);
            if ($toolUse === null) {
                return $this->result($messages, $this->textFromContent($content), $actions, null);
            }
            $name = (string) ($toolUse['name'] ?? '');
            $input = is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [];

            // Schreibaktion im confirm-Modus: anhalten und an den Client geben.
            if ($this->writeMode === 'confirm' && in_array($name, self::WRITE_TOOLS, true)) {
                return $this->result(
                    $messages,
                    $this->textFromContent($content),
                    $actions,
                    $this->buildPending($toolUse),
                );
            }

            [$resultText, $isError] = $this->executeTool($name, $input);
            if (!$isError && in_array($name, self::WRITE_TOOLS, true)) {
                $actions[] = [
                    'tool' => $name,
                    'path' => (string) ($input['path'] ?? ''),
                    'draft' => $this->isDraftWrite($name),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => [
                $this->toolResult((string) $toolUse['id'], $resultText, $isError),
            ]];
        }

        // Schrittgrenze erreicht: als sichtbare Assistenten-Nachricht in den
        // Verlauf schreiben (sonst bliebe der Zug ohne Rückmeldung stehen) und
        // den Client über das aborted-Flag einen „Weiter"-Knopf anbieten lassen.
        $note = $this->abortNote($locale);
        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $note]]];

        return $this->result($messages, $note, $actions, null, true);
    }

    /**
     * Kann das Modell adaptives Thinking (thinking.type=adaptive)? Das gibt es
     * erst ab der 4.6-Generation (Opus 5 und 4.6/4.7/4.8, Sonnet 5/4.6,
     * Fable/Mythos 5);
     * ältere Modelle und die Haiku-Reihe lehnen den Parameter mit einem 400 ab.
     * Konservativ per Positivliste — unbekannte Modelle senden kein thinking.
     * Die globale Einstellung [ai] force_thinking übergeht die Liste und erzwingt
     * es für jedes Modell (für neu eingetragene Modelle, die es können).
     */
    private function supportsAdaptiveThinking(string $model): bool
    {
        if ($this->forceAdaptiveThinking) {
            return true;
        }
        $m = strtolower($model);
        // Haiku kann es (noch) nicht — auch nicht 4.5.
        if (str_contains($m, 'haiku')) {
            return false;
        }
        foreach (['opus-5', 'opus-4-6', 'opus-4-7', 'opus-4-8', 'sonnet-5', 'sonnet-4-6', 'fable-5', 'mythos-5'] as $family) {
            if (str_contains($m, $family)) {
                return true;
            }
        }
        return false;
    }

    /**
     * true, wenn dieser Schreibvorgang als Freigabe-Entwurf statt live landet.
     * Der Client frischt daraufhin die Warteschlange auf. Nur write_file geht
     * über den Entwurfs-Weg — für Umbenennen, Verschieben, Löschen und Anlegen
     * eines Verzeichnisses gibt es keinen Entwurfsbegriff.
     *
     * Zwei Wege führen dorthin: der Modus `auto` (gestaffelte Veröffentlichung,
     * jeder Schreibvorgang wird Entwurf) und die ausdrückliche Antwort `draft`
     * auf eine Bestätigung im Modus `confirm` ($asDraft).
     */
    private function isDraftWrite(string $tool, bool $asDraft = false): bool
    {
        return $tool === 'write_file'
            && $this->draftSink !== null
            && ($asDraft || $this->writeMode === 'auto');
    }

    /** Hinweistext, wenn die Schrittgrenze eines Zugs erreicht wurde. */
    private function abortNote(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'en')
            ? 'I reached this turn\'s step limit before finishing. Send “continue” and I will carry on.'
            : 'Ich habe die Schrittgrenze dieses Zugs erreicht, bevor ich fertig war. Sende „weiter“, dann mache ich weiter.';
    }

    /** Führt die zuletzt angeforderte (pausierte) Schreibaktion aus oder lehnt sie ab. */
    private function resolvePending(array $messages, string $confirm, array &$actions): array
    {
        $last = is_array(end($messages)) ? end($messages) : [];
        $toolUse = ($last['role'] ?? '') === 'assistant'
            ? $this->firstToolUse(is_array($last['content'] ?? null) ? $last['content'] : [])
            : null;
        if ($toolUse === null) {
            throw ApiException::badRequest('AI-NO-PENDING');
        }

        // 'draft' ist ein Ja mit Aufschub: ausführen, aber als Entwurf zur
        // Freigabe statt in die Live-Datei.
        if ($confirm === 'allow' || $confirm === 'draft') {
            $name = (string) ($toolUse['name'] ?? '');
            $input = is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [];
            $asDraft = $confirm === 'draft';
            [$resultText, $isError] = $this->executeTool($name, $input, $asDraft);
            if (!$isError) {
                $actions[] = [
                    'tool' => $name,
                    'path' => (string) ($input['path'] ?? ''),
                    'draft' => $this->isDraftWrite($name, $asDraft),
                ];
            }
        } else {
            $resultText = 'Der Benutzer hat diese Aktion abgelehnt. Frage nach, wie du fortfahren sollst.';
            $isError = true;
        }

        $messages[] = ['role' => 'user', 'content' => [
            $this->toolResult((string) $toolUse['id'], $resultText, $isError),
        ]];

        return $messages;
    }

    /**
     * Führt ein Werkzeug aus. Fehler werden NICHT geworfen, sondern als
     * Werkzeugergebnis (is_error) an Claude zurückgegeben, damit es sich
     * korrigieren kann.
     *
     * @param bool $asDraft Der Benutzer hat diesen einen Schreibvorgang als
     *                      Entwurf zur Freigabe bestätigt (siehe resolvePending).
     * @return array{0: string, 1: bool} Ergebnistext und Fehler-Flag
     */
    private function executeTool(string $name, array $input, bool $asDraft = false): array
    {
        try {
            $text = match ($name) {
                'list_dir' => $this->toolListDir($input),
                'read_file' => $this->toolReadFile($input),
                'get_file_report' => $this->toolGetFileReport($input),
                'write_file' => $this->toolWriteFile($input, $asDraft),
                'create_dir' => $this->toolCreateDir($input),
                'rename' => $this->toolRename($input),
                'delete' => $this->toolDelete($input),
                'move' => $this->toolMove($input),
                default => throw ApiException::badRequest('AI-UNKNOWN-TOOL', [$name]),
            };

            return [$text, false];
        } catch (ApiException $e) {
            // Kurzform (Schlüssel + Parameter) genügt Claude zur Korrektur.
            return ['Fehler: ' . $e->getMessage(), true];
        } catch (Throwable) {
            return ['Fehler: interne Werkzeugausführung fehlgeschlagen.', true];
        }
    }

    private function toolListDir(array $input): string
    {
        $r = $this->resolvePath((string) ($input['path'] ?? ''), true, 'read');
        $entries = $this->files->listDir($r['mount'], $r['rel'], $r['abs']);
        if ($entries === []) {
            return '(leeres Verzeichnis)';
        }
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = (($entry['type'] ?? '') === 'dir' ? '[dir] ' : '      ') . ($entry['name'] ?? '');
        }

        return implode("\n", $lines);
    }

    private function toolReadFile(array $input): string
    {
        $r = $this->resolvePath((string) ($input['path'] ?? ''), true, 'read');

        return (string) $this->files->readText($r['mount'], $r['abs'])['content'];
    }

    /**
     * Liefert den Gesamt-Bericht (LLM-Qualitätsurteil + zugehörige SEO-Funde aus
     * dem jüngsten Audit-Lauf) der Datei als JSON. Nur-Lesen; setzt das
     * Leserecht des Mounts voraus.
     */
    private function toolGetFileReport(array $input): string
    {
        if ($this->fileReport === null) {
            throw ApiException::badRequest('AI-UNKNOWN-TOOL', ['get_file_report']);
        }
        $r = $this->resolvePath((string) ($input['path'] ?? ''), true, 'read');
        $id = $this->resolver->encodeId($r['mount']->name(), $r['rel']);
        $report = ($this->fileReport)($id);
        $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $json === false ? '{}' : $json;
    }

    private function toolWriteFile(array $input, bool $asDraft = false): string
    {
        $path = (string) ($input['path'] ?? '');
        $r = $this->resolvePath($path, false, 'write');
        $content = (string) ($input['content'] ?? '');

        // Statt live zu schreiben, den Vorschlag als Entwurf zur Freigabe
        // ablegen — im Modus auto immer, im Modus confirm auf ausdrücklichen
        // Wunsch. Die Live-Datei bleibt unangetastet, der Bearbeitungs-Vermerk
        // (onWrite) entfällt: Er greift erst bei der Freigabe.
        if ($this->isDraftWrite('write_file', $asDraft)) {
            ($this->draftSink)($r['mount'], $r['rel'], $r['abs'], $content);

            return 'Als Entwurf zur Freigabe vorgemerkt: ' . $path;
        }

        $this->files->writeText($r['mount'], $r['rel'], $r['abs'], $content);

        // KI-Bearbeitung vermerken (best effort — nie den Schreibvorgang kippen).
        if ($this->onWrite !== null) {
            try {
                ($this->onWrite)($this->resolver->encodeId($r['mount']->name(), $r['rel']));
            } catch (Throwable) {
                // Vermerk ist optional; Fehler ignorieren.
            }
        }

        return 'Gespeichert: ' . $path;
    }

    private function toolCreateDir(array $input): string
    {
        $path = (string) ($input['path'] ?? '');
        $r = $this->resolvePath($path, false, 'mkdir');
        $this->files->makeDir($r['mount'], self::parentRel($r['rel']), dirname($r['abs']), basename($r['rel']));

        return 'Ordner angelegt: ' . $path;
    }

    private function toolRename(array $input): string
    {
        $path = (string) ($input['path'] ?? '');
        $newName = (string) ($input['new_name'] ?? '');
        $r = $this->resolvePath($path, true, 'rename');
        $this->files->rename($r['mount'], $r['rel'], $r['abs'], $newName);

        return 'Umbenannt: ' . $path . ' → ' . $newName;
    }

    private function toolDelete(array $input): string
    {
        $path = (string) ($input['path'] ?? '');
        $r = $this->resolvePath($path, true, 'delete');
        $this->files->trash($r['mount'], $r['rel'], $r['abs']);

        return 'In den Papierkorb verschoben: ' . $path;
    }

    private function toolMove(array $input): string
    {
        $path = (string) ($input['path'] ?? '');
        $destDir = (string) ($input['dest_dir'] ?? '');
        $src = $this->resolvePath($path, true, 'move');
        $dest = $this->resolvePath($destDir, true, 'move'); // Zielordner muss existieren
        // FileService::move arbeitet innerhalb EINES Mounts.
        if ($src['mount'] !== $dest['mount']) {
            throw ApiException::denied('CROSS-MOUNT-NOT-ALLOWED');
        }
        $this->files->move($src['mount'], $src['rel'], $src['abs'], $dest['rel'], $dest['abs']);

        return 'Verschoben: ' . $path . ' → ' . $destDir;
    }

    /**
     * Übersetzt einen lesbaren Pfad in einen aufgelösten Mount-Pfad und prüft
     * das nötige Recht.
     *
     * @return array{mount: Mount, abs: string, rel: string}
     */
    private function resolvePath(string $path, bool $mustExist, string $permission): array
    {
        $id = $this->pathToId($path);
        $r = $this->resolver->resolve($id, $mustExist);
        if (!$r['mount']->allows($permission)) {
            throw ApiException::denied('OPERATION-NOT-ALLOWED', [$permission]);
        }

        return $r;
    }

    /** "<mount>/<rel>" → undurchsichtige Mount-ID. */
    private function pathToId(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $slash = strpos($path, '/');
        if ($slash === false) {
            $mount = $path;
            $rel = '';
        } else {
            $mount = substr($path, 0, $slash);
            $rel = substr($path, $slash + 1);
        }
        if ($mount === '') {
            throw ApiException::badRequest('AI-PATH-INVALID', [$path]);
        }

        return $this->resolver->encodeId($mount, $rel);
    }

    private static function parentRel(string $rel): string
    {
        $pos = strrpos($rel, '/');

        return $pos === false ? '' : substr($rel, 0, $pos);
    }

    /** Baut die Vorschau einer pausierten Schreibaktion (für die Bestätigung). */
    private function buildPending(array $toolUse): array
    {
        $input = is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [];
        $name = (string) ($toolUse['name'] ?? '');
        $pending = [
            'tool' => $name,
            'input' => $input,
            // Darf der Benutzer statt „jetzt live" auch „als Entwurf zur
            // Freigabe" wählen? Nur bei write_file und nur mit Entwurfsweg
            // (Hugo-Projekt vorhanden) — der Client blendet den Knopf danach ein.
            'canDraft' => $this->isDraftWrite($name, true),
        ];

        // Für write_file den bisherigen Inhalt mitgeben, damit der Client einen
        // Diff zeigen kann (null = neue Datei).
        if ($name === 'write_file') {
            $pending['oldContent'] = null;
            try {
                $r = $this->resolvePath((string) ($input['path'] ?? ''), true, 'read');
                $pending['oldContent'] = (string) $this->files->readText($r['mount'], $r['abs'])['content'];
            } catch (Throwable) {
                // existiert noch nicht — bleibt null
            }
        }

        return $pending;
    }

    private function firstToolUse(array $content): ?array
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                return $block;
            }
        }

        return null;
    }

    private function textFromContent(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return trim(implode("\n", $parts));
    }

    private function toolResult(string $toolUseId, string $content, bool $isError): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $content,
            'is_error' => $isError,
        ];
    }

    private function result(array $messages, string $reply, array $actions, ?array $pending, bool $aborted = false): array
    {
        return [
            'messages' => $messages,
            'reply' => $reply,
            'actions' => $actions,
            'pending' => $pending,
            'aborted' => $aborted,
        ];
    }

    /** Werkzeugdefinitionen; Schreib-Werkzeuge nur außerhalb von "readonly". */
    private function toolDefs(): array
    {
        $tools = [
            [
                'name' => 'list_dir',
                'description' => 'List the entries of a directory. "path" is "<mount>/<relative path>"; use just "<mount>" for the mount root.',
                'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
            [
                'name' => 'read_file',
                'description' => 'Read a text file. "path" is "<mount>/<relative path>".',
                'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ],
        ];
        // Gesamt-Bericht einer Content-Datei (Qualität + SEO) — nur, wenn eine
        // Bericht-Quelle injiziert wurde (Pro-Audit verfügbar).
        if ($this->fileReport !== null) {
            $tools[] = [
                'name' => 'get_file_report',
                'description' => 'Get the combined quality/SEO report for a content file: the AI content-quality verdict (score, readability, findings, suggestions) and the SEO audit findings for that file from the latest audit run. "path" is "<mount>/<relative path>". Use this before improving a page to know what to fix. In the SEO part, "audit.issues[].fixable" marks findings you can fix from this content file, "audit.issues[].duplicateOf" lists the other pages carrying the same title or meta description, and "audit.rules" explains every rule that occurs.',
                'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ];
        }
        if ($this->writeMode === 'readonly') {
            return $tools;
        }

        $tools[] = [
            'name' => 'write_file',
            'description' => 'Create a file or overwrite an existing one with "content". "path" is "<mount>/<relative path>". Read the file first before overwriting it.',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['path', 'content']],
        ];
        $tools[] = [
            'name' => 'create_dir',
            'description' => 'Create a new directory. "path" is "<mount>/<relative path>".',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
        ];
        $tools[] = [
            'name' => 'rename',
            'description' => 'Rename a file or directory within its folder. "path" is the existing item; "new_name" is the new base name.',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'new_name' => ['type' => 'string']], 'required' => ['path', 'new_name']],
        ];
        $tools[] = [
            'name' => 'delete',
            'description' => 'Move a file or directory to the trash (recoverable). "path" is "<mount>/<relative path>".',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
        ];
        $tools[] = [
            'name' => 'move',
            'description' => 'Move a file or directory into another directory within the same mount. "path" is the item to move; "dest_dir" is the target directory (must already exist).',
            'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'dest_dir' => ['type' => 'string']], 'required' => ['path', 'dest_dir']],
        ];

        return $tools;
    }

    /**
     * Liest die projektweite Anweisungsdatei (.hugocms-assistant.md) aus dem
     * Wurzelverzeichnis des ersten Mounts, der sie enthält. Vom Maintainer
     * gepflegt — daher als vertrauenswürdige, vorrangige Anweisung behandelt.
     */
    private function projectInstructions(): ?string
    {
        foreach ($this->resolver->all() as $mount) {
            $path = $mount->root() . '/' . self::INSTRUCTION_FILE;
            if (!is_file($path)) {
                continue;
            }
            $text = @file_get_contents($path, false, null, 0, self::MAX_INSTRUCTION_BYTES);
            if ($text !== false && trim($text) !== '') {
                return trim($text);
            }
        }

        return null;
    }

    private function systemPrompt(string $locale, ?string $openFilePath = null, ?string $openDirPath = null): string
    {
        $mountLines = [];
        foreach ($this->resolver->all() as $mount) {
            $info = $mount->describe();
            $mountLines[] = sprintf(
                '- %s (%s) — rights: %s',
                $info['name'],
                $info['label'],
                implode(', ', $info['permissions']),
            );
        }
        $mounts = $mountLines === [] ? '(none)' : implode("\n", $mountLines);

        $writeNote = match ($this->writeMode) {
            'readonly' => 'You can only read files; no write tools are available.',
            'confirm' => 'Write actions require the user to confirm before they run.',
            default => 'Write actions are applied directly.',
        };

        // Hinweis auf die aktuell im Editor geöffnete Datei, damit "diese Datei"
        // / "die offene Datei" eindeutig ist.
        $openNote = '';
        if ($openFilePath !== null && $openFilePath !== '') {
            $openNote = "\n\nThe file `{$openFilePath}` is currently open in the editor. "
                . "When the user says \"this file\", \"the open file\" or similar without naming a path, they mean this one. "
                . "Its unsaved changes have already been saved before this turn, so read_file reflects the current state.";
        }

        // Hinweis auf das im Dateimanager angezeigte Verzeichnis — Zielort für
        // "eine neue Datei" ohne Pfadangabe.
        if ($openDirPath !== null && $openDirPath !== '') {
            $openNote .= "\n\nThe file manager is currently showing the directory `{$openDirPath}`. "
                . "When the user asks to create a file or folder without saying where, place it in this directory "
                . "unless the request clearly points elsewhere — in which case briefly say where you put it.";
        }

        // Projektweite Maintainer-Anweisungen (.hugocms-assistant.md) — haben
        // Vorrang vor der generischen Anleitung oben.
        $instructions = $this->projectInstructions();
        if ($instructions !== null) {
            $openNote .= "\n\nProject-specific instructions from the site maintainer "
                . "(authoritative — follow these; they override the general guidance above where they conflict):\n"
                . $instructions;
        }

        return <<<SYS
        You are an assistant built into HugoCMS, a file manager for Hugo static-site projects. You help the user manage their Hugo site: editing content with front matter, fixing configuration (hugo.toml / hugo.yaml / config.toml), creating and editing layouts and partials, and explaining Hugo concepts.

        You access the project through tools. A path has the form `<mount>/<relative/path>`, where `<mount>` is one of the configured mounts below; use just `<mount>` for its root. Discover structure with list_dir, and always read a file before overwriting it.

        Match the project's existing conventions — never introduce a format or style the project does not already use. Hugo has no global front-matter format; it is per file, recognizable only by the delimiters (`---` = YAML, `+++` = TOML, `{ }` = JSON). So BEFORE creating content or front matter, inspect what the project already does: read an existing file in the same section, or check the `archetypes/` templates and the Hugo config (hugo.toml / hugo.yaml / config.*). Adopt the same front-matter format, date format, and field names. The same applies to config edits (match the existing config language) and to layouts/partials (match the templating style and naming already in use). If existing files disagree or none exist, ask the user which convention to follow rather than guessing.

        When you write or edit prose content, it must NOT read as AI-generated. Use a natural, human voice; be specific and concrete; vary sentence length. Avoid generic filler, empty superlatives, marketing clichés, and formulaic phrasing (e.g. "In today's fast-paced world", "it's important to note", "when it comes to"). Preserve the author's existing tone and vocabulary.

        Configured mounts:
        {$mounts}

        {$writeNote} Write each file exactly once: decide on its complete, final content first and write it in a SINGLE write_file call. Never create a file and then immediately overwrite it again in the same task — in confirmation mode that would force the user to confirm the same file twice.{$openNote}

        Answer in the user's language (locale: {$locale}). Be concise and practical — prefer concrete edits and exact Hugo syntax over long explanations.
        SYS;
    }
}
