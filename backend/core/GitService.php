<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Dünne, sichere Hülle um das Git-Programm — die Pro-Funktion von HugoCMS.
 *
 * Git läuft stets im Hugo-Projektverzeichnis (dort liegt das Repository). Alle
 * Aufrufe gehen über {@see run()}: das Arbeitsverzeichnis wird mit `git -C`
 * gesetzt, jedes Argument einzeln mit escapeshellarg() maskiert — es gibt keine
 * Shell-Interpolation von Nutzereingaben. Commit-Hashes und Refs werden zusätz-
 * lich per Whitelist geprüft.
 *
 * Die Freischaltung (Pro-Lizenz) prüft der Connector, bevor er diesen Dienst
 * überhaupt erzeugt.
 */
final class GitService
{
    /** Maximale Länge einer Commit-Nachricht. */
    private const int MAX_MESSAGE = 1000;

    /** Maximale Länge eines Tag-Namens. */
    private const int MAX_TAG = 100;

    /**
     * Präfix der automatisch vorgeschlagenen Versionsnummern (v1, v2, …).
     * Der nächste Wert wird NICHT gespeichert, sondern aus den vorhandenen Tags
     * des Repositorys abgeleitet ({@see nextTag()}) — so bleibt das Repository
     * die einzige Quelle der Wahrheit und ein Umzug, ein Klon oder ein von Hand
     * gesetztes Tag kann keine Kollision erzeugen.
     */
    private const string TAG_PREFIX = 'v';

    /** Obergrenze für die Seitengröße der Commit-Liste. */
    private const int MAX_PER_PAGE = 100;

    /** Feld- und Datensatztrenner für das log-Format (steuerzeichen, kollisionsfrei). */
    private const string FIELD_SEP = "\x1f";
    private const string RECORD_SEP = "\x1e";

    public function __construct(private readonly string $repoDir)
    {
    }

    /**
     * Arbeitsbaum-Status: aktueller Branch und die Liste der Änderungen, jede
     * Datei mit ihrer Art (siehe classifyStatus). `nextTag` ist die
     * vorgeschlagene nächste Versionsnummer für das Sichern-Formular.
     *
     * @return array{branch: string, clean: bool, nextTag: string, entries: list<array{path: string, status: string, from: string|null}>}
     */
    public function status(): array
    {
        $this->assertRepo();

        $branch = $this->run(['rev-parse', '--abbrev-ref', 'HEAD']);
        $branchName = $branch['exit'] === 0 ? trim($branch['output']) : 'HEAD';

        $porcelain = $this->run(['status', '--porcelain=v1', '--untracked-files=all']);
        $entries = [];
        foreach ($porcelain['lines'] as $line) {
            if ($line === '' || strlen($line) < 4) {
                continue;
            }
            $code = substr($line, 0, 2);
            $path = substr($line, 3);
            // Umbenennungen melden „alt -> neu“: angezeigt wird der Zielpfad,
            // der Quellpfad bleibt als `from` erhalten.
            $from = null;
            if (str_contains($path, ' -> ')) {
                $sep = (int) strpos($path, ' -> ');
                $from = $this->unquotePath(substr($path, 0, $sep));
                $path = substr($path, $sep + 4);
            }
            $entries[] = [
                'path' => $this->unquotePath($path),
                'status' => $this->classifyStatus($code),
                'from' => $from,
            ];
        }

        return [
            'branch' => $branchName,
            'clean' => $entries === [],
            'nextTag' => $this->nextTag(),
            'entries' => $entries,
        ];
    }

    /**
     * Nächste freie Versionsnummer im Schema v1, v2, v3 … — abgeleitet aus den
     * vorhandenen Tags, nicht aus einem gespeicherten Zähler. Gezählt wird die
     * höchste rein numerische Nummer; abweichend benannte Tags (etwa `v1.2.0`
     * oder `release-alt`) bleiben unberücksichtigt und stören nicht.
     */
    public function nextTag(): string
    {
        $res = $this->run(['tag', '--list', self::TAG_PREFIX . '[0-9]*']);
        $pattern = '/^' . preg_quote(self::TAG_PREFIX, '/') . '(\d+)$/';
        $max = 0;
        foreach ($res['lines'] as $line) {
            if (preg_match($pattern, trim($line), $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return self::TAG_PREFIX . ($max + 1);
    }

    /**
     * Übersetzt den zweistelligen Porcelain-Code (X = Index, Y = Arbeitsbaum)
     * in eine Art für die Anzeige. Da `commit` mit `add -A` ohnehin alles
     * übernimmt, zählt der Endzustand gegenüber HEAD — die beiden Spalten
     * werden deshalb zusammengefasst und nicht getrennt ausgewiesen.
     */
    private function classifyStatus(string $code): string
    {
        // Konflikte zuerst: beidseitig geändert (UU/AA/DD) oder eine U-Spalte.
        if ($code === 'AA' || $code === 'DD' || str_contains($code, 'U')) {
            return 'conflict';
        }
        return match (true) {
            $code === '??' => 'untracked',
            str_contains($code, 'R') => 'renamed',
            str_contains($code, 'D') => 'deleted',
            str_contains($code, 'A') => 'added',
            default => 'modified',
        };
    }

    /**
     * Porcelain v1 setzt Pfade mit Sonderzeichen in Anführungszeichen und
     * maskiert sie C-artig. Für die Anzeige wird das rückgängig gemacht.
     */
    private function unquotePath(string $path): string
    {
        if (!str_starts_with($path, '"') || !str_ends_with($path, '"')) {
            return $path;
        }
        $inner = substr($path, 1, -1);
        return stripcslashes($inner);
    }

    /**
     * Zieht die Tag-Namen aus der Ref-Liste eines Commits (`%D`). Die Liste
     * enthält auch Branches und den HEAD-Zeiger; Tags sind daran zu erkennen,
     * dass git ihnen `tag: ` voranstellt.
     *
     * @return list<string>
     */
    private function parseTags(string $refs): array
    {
        $tags = [];
        foreach (explode(',', $refs) as $ref) {
            $ref = trim($ref);
            if (str_starts_with($ref, 'tag: ')) {
                $tags[] = trim(substr($ref, 5));
            }
        }

        return $tags;
    }

    /**
     * Seitenweise Commit-Liste des aktuellen Branches. Jeder Commit trägt die
     * auf ihn zeigenden Tags (`tags`) — sie kommen über `%D` aus demselben
     * log-Aufruf, kosten also keinen zusätzlichen Prozess je Seite.
     *
     * @return array{commits: list<array{sha: string, shortSha: string, author: string, email: string, date: string, tags: list<string>, message: string}>, page: int, perPage: int, total: int}
     */
    public function log(int $page, int $perPage): array
    {
        $this->assertRepo();

        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        // Anzahl Commits (leeres Repository ohne Commits: 0, kein Fehler).
        $countRes = $this->run(['rev-list', '--count', 'HEAD']);
        $total = $countRes['exit'] === 0 ? (int) trim($countRes['output']) : 0;
        if ($total === 0) {
            return ['commits' => [], 'page' => $page, 'perPage' => $perPage, 'total' => 0];
        }

        // %D trägt die Ref-Namen des Commits („HEAD -> main, tag: v3, …“); die
        // Nachricht bleibt das letzte Feld, damit ein leeres %D den Datensatz
        // nicht verkürzt. --decorate=short legt die Schreibweise fest, sonst
        // könnte eine log.decorate-Einstellung des Servers sie verändern.
        $format = implode(self::FIELD_SEP, ['%H', '%h', '%an', '%ae', '%aI', '%D', '%s']) . self::RECORD_SEP;
        $res = $this->run([
            'log',
            '--skip=' . (($page - 1) * $perPage),
            '--max-count=' . $perPage,
            '--no-color',
            '--decorate=short',
            '--pretty=format:' . $format,
        ]);

        $commits = [];
        foreach (explode(self::RECORD_SEP, $res['output']) as $record) {
            $record = trim($record, "\r\n");
            if ($record === '') {
                continue;
            }
            $f = explode(self::FIELD_SEP, $record);
            if (count($f) < 7) {
                continue;
            }
            $commits[] = [
                'sha' => $f[0],
                'shortSha' => $f[1],
                'author' => $f[2],
                'email' => $f[3],
                'date' => $f[4],
                'tags' => $this->parseTags($f[5]),
                'message' => $f[6],
            ];
        }

        return ['commits' => $commits, 'page' => $page, 'perPage' => $perPage, 'total' => $total];
    }

    /**
     * Diff eines Commits samt seiner VOLLSTÄNDIGEN Beschreibung.
     *
     * Der Verlauf zeigt nur die erste Zeile (`%s`), weil die Tabellenspalte
     * einzeilig ist. Alles Weitere — bei den hier erzeugten Versionsständen die
     * Aufzählung der geänderten Dateien — steht nur in `%B` und wäre sonst in
     * der Oberfläche nirgends zu sehen.
     *
     * Beides kommt aus einem einzigen `show`: Die Beschreibung steht vor dem
     * Diff, getrennt durch RECORD_SEP. Getrennt wird am ERSTEN Vorkommen, damit
     * ein Steuerzeichen im Diff selbst nichts verschieben kann.
     *
     * `files` listet die geänderten Dateien einzeln auf — Grundlage dafür, eine
     * einzelne Datei aus diesem Stand zurückzuholen. Sie kommen aus einem
     * eigenen Aufruf statt aus einem weiteren Trennzeichen im selben: Der Diff
     * ist beliebiger Text, und ein zweiter Marker darin wäre nicht sicher vom
     * Inhalt zu unterscheiden.
     *
     * @return array{sha: string, message: string, diff: string, files: list<array{path: string, status: string}>}
     */
    public function diff(string $sha): array
    {
        $this->assertRepo();
        $sha = $this->requireHash($sha);

        $res = $this->run(['show', '--format=%B' . self::RECORD_SEP, '--no-color', $sha]);
        if ($res['exit'] !== 0) {
            throw ApiException::notFound('GIT-COMMIT-NOT-FOUND', [$sha]);
        }

        $parts = explode(self::RECORD_SEP, $res['output'], 2);

        return [
            'sha' => $sha,
            'message' => trim($parts[0]),
            // Der Diff beginnt hinter dem Trennzeichen; der Zeilenumbruch, den
            // git dahinter setzt, gehört nicht dazu.
            'diff' => ltrim($parts[1] ?? '', "\r\n"),
            'files' => $this->changedFiles($sha),
        ];
    }

    /**
     * Die in einem Commit geänderten Dateien mit ihrer Art. Beim ersten Commit
     * eines Repositorys gibt es keinen Vorgänger; `--root` sorgt dafür, dass
     * auch dort die Dateien erscheinen statt einer leeren Liste.
     *
     * @return list<array{path: string, status: string}>
     */
    private function changedFiles(string $sha): array
    {
        $res = $this->run(['show', '--name-status', '--format=', '--no-color', '--root', $sha]);
        $files = [];
        foreach ($res['lines'] as $line) {
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }
            $parts = explode("\t", $line);
            $files[] = [
                // Bei Umbenennungen nennt git Quelle UND Ziel; gezeigt wird das
                // Ziel, denn nur dieses lässt sich zurückholen.
                'path' => $this->unquotePath($parts[count($parts) - 1]),
                'status' => $this->classifyStatus(substr($parts[0], 0, 1)),
            ];
        }

        return $files;
    }

    /**
     * Alle Änderungen committen (add -A). Ein fehlgeschlagener Commit (z. B.
     * nichts zu committen, fehlende git-Identität) ist KEIN API-Fehler: Die
     * Antwort trägt success=false samt Git-Ausgabe.
     *
     * Ist $tag gesetzt, bekommt der neue Commit zusätzlich ein annotiertes Tag
     * als Versionsnummer. Name und Verfügbarkeit werden VOR dem Commit geprüft
     * und werfen bei einem Problem — sonst stünde der Commit bereits und ließe
     * sich nicht zurücknehmen. Scheitert erst das Tag selbst, bleibt der Commit
     * gültig; die Antwort weist das über `tagged` getrennt aus.
     *
     * $beforeAdd läuft unmittelbar vor `git add -A` und bekommt Beschreibung und
     * Versionsnummer. Darüber schreibt der Connector das Änderungsprotokoll:
     * Die Seite liegt selbst im Repository, muss also VOR dem Vormerken
     * entstehen, damit sie in genau dem Stand liegt, den sie beschreibt.
     * GitService selbst bleibt dabei frei von Mount- und Dateikenntnis — das
     * Schreiben gehört in die FileService-Schicht.
     *
     * @param ?callable(string, ?string): void $beforeAdd
     * @return array{success: bool, sha: ?string, output: string, tag: ?string, tagged: bool, tagOutput: string}
     */
    public function commit(string $message, ?string $tag = null, ?callable $beforeAdd = null): array
    {
        $this->assertRepo();
        $message = trim($message);
        if ($message === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['message']);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            throw ApiException::badRequest('GIT-MESSAGE-TOO-LONG', [self::MAX_MESSAGE]);
        }

        $tag = $tag === null ? '' : trim($tag);
        if ($tag !== '') {
            $tag = $this->requireTagName($tag);
            if ($this->tagExists($tag)) {
                throw ApiException::badRequest('GIT-TAG-EXISTS', [$tag]);
            }
        }

        $failed = ['success' => false, 'sha' => null, 'tag' => null, 'tagged' => false, 'tagOutput' => ''];
        if ($beforeAdd !== null) {
            $beforeAdd($message, $tag === '' ? null : $tag);
        }
        $add = $this->run(['add', '-A']);
        if ($add['exit'] !== 0) {
            return [...$failed, 'output' => $add['output']];
        }
        $commit = $this->run(['commit', '-m', $message]);
        if ($commit['exit'] !== 0) {
            return [...$failed, 'output' => $commit['output']];
        }
        $head = $this->run(['rev-parse', 'HEAD']);

        $result = [
            'success' => true,
            'sha' => $head['exit'] === 0 ? trim($head['output']) : null,
            'output' => $commit['output'],
            'tag' => null,
            'tagged' => false,
            'tagOutput' => '',
        ];

        if ($tag !== '') {
            // Annotiert (-a -m): Nur so nimmt `push --follow-tags` das Tag mit,
            // und es trägt Autor, Datum und die Beschreibung des Versionsstands.
            $res = $this->run(['tag', '-a', $tag, '-m', $message]);
            $result['tagged'] = $res['exit'] === 0;
            $result['tag'] = $result['tagged'] ? $tag : null;
            $result['tagOutput'] = $res['output'];
        }

        return $result;
    }

    /**
     * Zum konfigurierten Remote pushen. Authentifizierung (SSH-Schlüssel,
     * Credential-Helper) muss in der Serverumgebung eingerichtet sein. Ein
     * Fehlschlag (kein Remote, keine Berechtigung) ist KEIN API-Fehler.
     *
     * `--follow-tags` überträgt die annotierten Tags der gepushten Commits mit —
     * ohne den Schalter blieben die Versionsnummern lokal, denn ein einfaches
     * `git push` schickt keine Tags.
     *
     * @return array{success: bool, output: string}
     */
    public function push(): array
    {
        $this->assertRepo();
        $res = $this->run(['push', '--follow-tags']);

        return ['success' => $res['exit'] === 0, 'output' => $res['output']];
    }

    /**
     * Was eine Wiederherstellung von $sha am Arbeitsbaum ändern würde — dieselbe
     * Form wie {@see status()}, damit die Vorschau dieselbe Darstellung nutzen
     * kann wie die Liste der offenen Änderungen.
     *
     * Verglichen wird der ARBEITSBAUM gegen $sha, nicht HEAD gegen $sha: Die
     * Vorschau soll zeigen, was der Benutzer am Ende vorfindet — offene
     * Änderungen gehen dabei ebenso zurück und gehören deshalb in die Liste.
     *
     * `-R` dreht die Richtung um. `git diff <sha>` liefe von $sha zum
     * Arbeitsbaum und meldete eine seither hinzugekommene Datei als
     * hinzugefügt; beim Zurückgehen wird sie aber entfernt.
     *
     * @return array{sha: string, entries: list<array{path: string, status: string, from: null}>}
     */
    public function restorePreview(string $sha): array
    {
        $this->assertRepo();
        $sha = $this->requireCommit($sha);

        $res = $this->run(['diff', '--name-status', '--no-color', '-R', $sha]);
        $entries = [];
        foreach ($res['lines'] as $line) {
            if ($line === '') {
                continue;
            }
            // „<Code>\t<Pfad>“; bei Umbenennungen folgt ein zweiter Pfad, der
            // hier nicht gebraucht wird — gezeigt wird stets das Ziel.
            $parts = explode("\t", $line);
            $code = $parts[0];
            $path = $parts[count($parts) - 1];
            $entries[] = [
                'path' => $this->unquotePath($path),
                'status' => $this->classifyStatus(substr($code, 0, 1)),
                'from' => null,
            ];
        }

        // `git diff` kennt nur versionierte Dateien. Noch unversionierte
        // verschwinden bei der Wiederherstellung trotzdem: Das Vorab-Sichern
        // nimmt sie mit `add -A` auf, danach entfernt `read-tree` sie, weil es
        // sie im alten Stand nicht gibt. Ohne diesen Zusatz verschwiegen die
        // Vorschau genau die Dateien, an denen der Benutzer zuletzt saß.
        $known = array_column($entries, 'path');
        foreach ($this->status()['entries'] as $entry) {
            if ($entry['status'] === 'untracked' && !in_array($entry['path'], $known, true)) {
                $entries[] = ['path' => $entry['path'], 'status' => 'deleted', 'from' => null];
            }
        }

        return ['sha' => $sha, 'entries' => $entries];
    }

    /**
     * Kehrt zu einem alten Versionsstand zurück — vorwärts, nicht rückwärts:
     * Der alte Inhalt wird geholt und als NEUER Versionsstand gesichert. Die
     * Historie bleibt vollständig, jeder Stand bleibt erreichbar, und die
     * Wiederherstellung selbst lässt sich genauso wieder zurücknehmen. Ein
     * `reset --hard` würde stattdessen die späteren Stände auslöschen und den
     * Push nur noch mit Gewalt zulassen — für Benutzer ohne Git-Kenntnisse die
     * falsche Zusage.
     *
     * Offene Änderungen werden ZUVOR als eigener Stand gesichert, damit die
     * Wiederherstellung nichts vernichten kann, was noch nicht in der Historie
     * steht.
     *
     * Geholt wird mit `read-tree -u --reset`. Ein `checkout <sha> -- .` wäre
     * falsch: Es überschreibt nur, was im alten Stand existiert, und ließe alles
     * seither Hinzugekommene liegen — das Ergebnis wäre ein Mischzustand, den es
     * nie gab. `read-tree` entfernt diese Dateien und lässt zugleich
     * unversionierte Verzeichnisse (`public/`, `resources/`) unangetastet.
     *
     * $beforeAdd wird an BEIDE Commits durchgereicht — die Vorab-Sicherung ist
     * ein eigener Versionsstand und gehört ebenso ins Protokoll. Für den Stand
     * nach der Wiederherstellung greift der Aufruf erst hinter `read-tree`:
     * Das Protokoll liegt selbst im Repository und würde sonst mit auf den alten
     * Inhalt zurückgesetzt, samt des gerade geschriebenen Eintrags.
     *
     * @param ?callable(string, ?string): void $beforeAdd
     * @return array{success: bool, sha: ?string, output: string, tag: ?string, tagged: bool, tagOutput: string, presaved: bool, presavedSha: ?string}
     */
    public function restore(
        string $sha,
        string $message,
        ?string $tag,
        string $presaveMessage,
        ?callable $beforeAdd = null,
    ): array {
        $this->assertRepo();
        $sha = $this->requireCommit($sha);

        // Offene Änderungen zuerst in die Historie bringen.
        $presaved = false;
        $presavedSha = null;
        if (empty($this->status()['clean'])) {
            $pre = $this->commit($presaveMessage, null, $beforeAdd);
            if (!$pre['success']) {
                return [
                    'success' => false,
                    'sha' => null,
                    'output' => $pre['output'],
                    'tag' => null,
                    'tagged' => false,
                    'tagOutput' => '',
                    'presaved' => false,
                    'presavedSha' => null,
                ];
            }
            $presaved = true;
            $presavedSha = $pre['sha'];
        }

        // Erst jetzt prüfen, ob es überhaupt etwas zu holen gibt: Vor dem
        // Vorab-Sichern hätte ein inhaltsgleicher Stand trotz offener Änderungen
        // fälschlich als „nichts zu tun“ gegolten.
        if ($this->run(['diff', '--quiet', 'HEAD', $sha])['exit'] === 0) {
            throw ApiException::badRequest('GIT-RESTORE-NO-CHANGE', [substr($sha, 0, 7)]);
        }

        $read = $this->run(['read-tree', '-u', '--reset', $sha]);
        if ($read['exit'] !== 0) {
            return [
                'success' => false,
                'sha' => null,
                'output' => $read['output'],
                'tag' => null,
                'tagged' => false,
                'tagOutput' => '',
                'presaved' => $presaved,
                'presavedSha' => $presavedSha,
            ];
        }

        // Der Arbeitsbaum trägt jetzt den alten Inhalt; ihn als neuen Stand
        // festschreiben. Schlägt das fehl, bleibt der Inhalt als offene
        // Änderung stehen — verloren ist nichts, alles liegt in der Historie.
        $res = $this->commit($message, $tag, $beforeAdd);

        return [...$res, 'presaved' => $presaved, 'presavedSha' => $presavedSha];
    }

    /**
     * Holt EINE Datei aus einem alten Versionsstand in den Arbeitsbaum. Bewusst
     * ohne eigenen Commit: Die Datei erscheint als offene Änderung, und der
     * Benutzer sichert sie über dasselbe Formular wie jede andere Bearbeitung.
     *
     * Ein Fehlschlag ist KEIN API-Fehler; dass die Datei in jenem Stand gar
     * nicht existiert, schon — das wäre ein Bedienfehler und keine Störung.
     *
     * @return array{success: bool, sha: string, path: string, output: string}
     */
    public function restoreFile(string $sha, string $path): array
    {
        $this->assertRepo();
        $sha = $this->requireCommit($sha);
        $path = $this->requirePath($path);

        // Existiert die Datei in jenem Stand überhaupt? Sonst legte `checkout`
        // sie nicht an und meldete nur eine wenig sprechende git-Fehlzeile.
        if ($this->run(['cat-file', '-e', $sha . ':' . $path])['exit'] !== 0) {
            throw ApiException::notFound('GIT-FILE-NOT-IN-COMMIT', [$path, substr($sha, 0, 7)]);
        }

        // `--` trennt den Pfad verlässlich von den Optionen.
        $res = $this->run(['checkout', $sha, '--', $path]);

        return [
            'success' => $res['exit'] === 0,
            'sha' => $sha,
            'path' => $path,
            'output' => $res['output'],
        ];
    }

    /**
     * Stellt den Arbeitsbaum auf einen Ref zurück (Standard: HEAD — verwirft
     * nicht committete Änderungen an verfolgten Dateien). HEAD selbst wird nicht
     * verschoben. Ein Fehlschlag ist KEIN API-Fehler.
     *
     * Für die Rückkehr zu einem ALTEN Stand ist dieser Weg ungeeignet — siehe
     * {@see restore()}.
     *
     * @return array{success: bool, output: string}
     */
    public function reset(string $ref): array
    {
        $this->assertRepo();
        $ref = $this->requireRef($ref);

        $res = $this->run(['checkout', $ref, '--', '.']);

        return ['success' => $res['exit'] === 0, 'output' => $res['output']];
    }

    // --- Intern ------------------------------------------------------------

    /** Stellt sicher, dass das Verzeichnis ein Git-Arbeitsbaum ist. */
    /** true, wenn das Verzeichnis ein Git-Arbeitsbaum ist (ohne zu werfen). */
    public function isRepository(): bool
    {
        $res = $this->run(['rev-parse', '--is-inside-work-tree']);

        return $res['exit'] === 0 && trim($res['output']) === 'true';
    }

    private function assertRepo(): void
    {
        if (!$this->isRepository()) {
            throw new ApiException('EGIT', 409, 'GIT-NOT-A-REPO', [$this->repoDir]);
        }
    }

    /** Prüft einen Commit-Hash (4–40 Hex-Zeichen). */
    private function requireHash(string $sha): string
    {
        $sha = trim($sha);
        if (preg_match('/^[0-9a-fA-F]{4,40}$/', $sha) !== 1) {
            throw ApiException::badRequest('GIT-INVALID-HASH', [$sha]);
        }

        return $sha;
    }

    /**
     * Prüft einen Commit-Hash UND dass er in diesem Repository auf einen Commit
     * zeigt. `^{commit}` schließt aus, dass ein gültig aussehender Hash auf ein
     * Blob oder einen Tree verweist — beides ließe die folgenden Befehle
     * Merkwürdiges tun statt sauber zu scheitern.
     */
    private function requireCommit(string $sha): string
    {
        $sha = $this->requireHash($sha);
        if ($this->run(['rev-parse', '--verify', '--quiet', $sha . '^{commit}'])['exit'] !== 0) {
            throw ApiException::notFound('GIT-COMMIT-NOT-FOUND', [$sha]);
        }

        return $sha;
    }

    /**
     * Prüft einen Pfad innerhalb des Repositorys. Die Einsperrung leistet hier
     * nicht der MountResolver, sondern diese Prüfung: absolute Pfade und jedes
     * „..“-Segment sind verboten, damit kein Pfad aus dem Projektverzeichnis
     * herausführt. Der Aufruf setzt zusätzlich `--` vor den Pfad, sodass er auch
     * nicht als Option gelesen werden kann.
     */
    private function requirePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['path']);
        }
        if (str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw ApiException::badRequest('GIT-INVALID-PATH', [$path]);
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                throw ApiException::badRequest('GIT-INVALID-PATH', [$path]);
            }
        }

        return $path;
    }

    /**
     * Prüft den Namen einer Versionsnummer (Tag). Die eigene Whitelist hält den
     * Namen bewusst enger, als git es täte — lesbar und ohne Sonderzeichen, die
     * in einer Anzeige oder auf einem anderen Dateisystem Ärger machen. Das
     * letzte Wort hat `check-ref-format`, damit auch Feinheiten wie ein
     * abschließendes ".lock" verlässlich abgefangen werden.
     */
    private function requireTagName(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) > self::MAX_TAG) {
            throw ApiException::badRequest('GIT-TAG-TOO-LONG', [self::MAX_TAG]);
        }
        if (str_contains($name, '..') || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $name) !== 1) {
            throw ApiException::badRequest('GIT-INVALID-TAG', [$name]);
        }
        if ($this->run(['check-ref-format', 'refs/tags/' . $name])['exit'] !== 0) {
            throw ApiException::badRequest('GIT-INVALID-TAG', [$name]);
        }

        return $name;
    }

    /** true, wenn bereits ein Tag dieses Namens existiert. */
    private function tagExists(string $name): bool
    {
        return $this->run(['rev-parse', '--verify', '--quiet', 'refs/tags/' . $name])['exit'] === 0;
    }

    /** Prüft einen Ref-Namen (inkl. HEAD~1, tag, branch); verbietet "..". */
    private function requireRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return 'HEAD';
        }
        if (str_contains($ref, '..') || preg_match('/^[A-Za-z0-9_\/.~^-]+$/', $ref) !== 1) {
            throw ApiException::badRequest('GIT-INVALID-REF', [$ref]);
        }

        return $ref;
    }

    /**
     * Führt ein git-Kommando im Repository aus. Arbeitsverzeichnis über `git -C`,
     * jedes Argument einzeln maskiert; stderr wird in die Ausgabe übernommen.
     *
     * @param list<string> $args
     * @return array{exit: int, lines: list<string>, output: string}
     */
    private function run(array $args): array
    {
        $cmd = 'git -C ' . escapeshellarg($this->repoDir);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $cmd .= ' 2>&1';

        $lines = [];
        $exit = 1;
        exec($cmd, $lines, $exit);

        return ['exit' => $exit, 'lines' => $lines, 'output' => implode("\n", $lines)];
    }
}
