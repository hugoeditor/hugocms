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
     * Datei mit ihrer Art (siehe classifyStatus).
     *
     * @return array{branch: string, clean: bool, entries: list<array{path: string, status: string, from: string|null}>}
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
            'entries' => $entries,
        ];
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
     * Seitenweise Commit-Liste des aktuellen Branches.
     *
     * @return array{commits: list<array{sha: string, shortSha: string, author: string, email: string, date: string, message: string}>, page: int, perPage: int, total: int}
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

        $format = implode(self::FIELD_SEP, ['%H', '%h', '%an', '%ae', '%aI', '%s']) . self::RECORD_SEP;
        $res = $this->run([
            'log',
            '--skip=' . (($page - 1) * $perPage),
            '--max-count=' . $perPage,
            '--no-color',
            '--pretty=format:' . $format,
        ]);

        $commits = [];
        foreach (explode(self::RECORD_SEP, $res['output']) as $record) {
            $record = trim($record, "\r\n");
            if ($record === '') {
                continue;
            }
            $f = explode(self::FIELD_SEP, $record);
            if (count($f) < 6) {
                continue;
            }
            $commits[] = [
                'sha' => $f[0],
                'shortSha' => $f[1],
                'author' => $f[2],
                'email' => $f[3],
                'date' => $f[4],
                'message' => $f[5],
            ];
        }

        return ['commits' => $commits, 'page' => $page, 'perPage' => $perPage, 'total' => $total];
    }

    /**
     * Diff eines Commits (ohne Commit-Kopf, nur die Änderungen).
     *
     * @return array{sha: string, diff: string}
     */
    public function diff(string $sha): array
    {
        $this->assertRepo();
        $sha = $this->requireHash($sha);

        $res = $this->run(['show', '--format=', '--no-color', $sha]);
        if ($res['exit'] !== 0) {
            throw ApiException::notFound('GIT-COMMIT-NOT-FOUND', [$sha]);
        }

        return ['sha' => $sha, 'diff' => $res['output']];
    }

    /**
     * Alle Änderungen committen (add -A). Ein fehlgeschlagener Commit (z. B.
     * nichts zu committen, fehlende git-Identität) ist KEIN API-Fehler: Die
     * Antwort trägt success=false samt Git-Ausgabe.
     *
     * @return array{success: bool, sha: ?string, output: string}
     */
    public function commit(string $message): array
    {
        $this->assertRepo();
        $message = trim($message);
        if ($message === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['message']);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            throw ApiException::badRequest('GIT-MESSAGE-TOO-LONG', [self::MAX_MESSAGE]);
        }

        $add = $this->run(['add', '-A']);
        if ($add['exit'] !== 0) {
            return ['success' => false, 'sha' => null, 'output' => $add['output']];
        }
        $commit = $this->run(['commit', '-m', $message]);
        if ($commit['exit'] !== 0) {
            return ['success' => false, 'sha' => null, 'output' => $commit['output']];
        }
        $head = $this->run(['rev-parse', 'HEAD']);

        return [
            'success' => true,
            'sha' => $head['exit'] === 0 ? trim($head['output']) : null,
            'output' => $commit['output'],
        ];
    }

    /**
     * Zum konfigurierten Remote pushen. Authentifizierung (SSH-Schlüssel,
     * Credential-Helper) muss in der Serverumgebung eingerichtet sein. Ein
     * Fehlschlag (kein Remote, keine Berechtigung) ist KEIN API-Fehler.
     *
     * @return array{success: bool, output: string}
     */
    public function push(): array
    {
        $this->assertRepo();
        $res = $this->run(['push']);

        return ['success' => $res['exit'] === 0, 'output' => $res['output']];
    }

    /**
     * Stellt den Arbeitsbaum auf einen Ref zurück (Standard: HEAD — verwirft
     * nicht committete Änderungen an verfolgten Dateien). HEAD selbst wird nicht
     * verschoben. Ein Fehlschlag ist KEIN API-Fehler.
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
    private function assertRepo(): void
    {
        $res = $this->run(['rev-parse', '--is-inside-work-tree']);
        if ($res['exit'] !== 0 || trim($res['output']) !== 'true') {
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
