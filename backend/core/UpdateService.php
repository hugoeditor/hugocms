<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Software-Update dieser Installation aus der Oberfläche heraus.
 *
 * Kapselt alles rund um die ausgelieferten Skripte `bin/update.sh` und
 * `bin/get-hugo.sh` des Release-Repos, damit der Connector nur dünne
 * Befehlshüllen braucht:
 *
 *   - Voraussetzungen: Sind die Skripte da und ausführbar, ist `exec`
 *     freigegeben, sind `bash` und `git` erreichbar, darf der PHP-Prozess das
 *     Release-Repo schreiben? Das Ergebnis trägt der Systemstatus; die
 *     Oberfläche schaltet daran die Knöpfe frei.
 *   - Prüfung: Steht ein neuer Release-Stand bereit (`git fetch` und Vergleich
 *     mit dem Upstream), gibt es eine neuere Hugo-Fassung (`get-hugo.sh
 *     --dry-run`)? Beides fragt nach außen und läuft nur auf Knopfdruck.
 *   - Ausführung: `update.sh --all` (Release ziehen, Webseiten auffrischen,
 *     Hugo erneuern), `--hugo` (nur Hugo) oder ohne Option (Release und
 *     Webseiten). Ein Aufruf, ein Ergebnis mit Ausgabe und Exit-Code — kein
 *     Streaming, damit das Verfahren auf einfachem Hosting bleibt.
 *
 * Das Release-Repo ist das Elternverzeichnis von backend/; der Dienst leitet
 * es aus seiner eigenen Lage ab. In den Befehl fließt keine Benutzereingabe —
 * nur feste Argumente.
 *
 * Der Pull tauscht während des Laufs auch die PHP-Dateien aus. Für den
 * auslösenden Request ist das unkritisch (die Klassen sind geladen); der
 * Aufrufer sollte danach aber nichts mehr nachladen und nur noch antworten.
 */
final class UpdateService
{
    /** Zeilen der Skriptausgabe, die an den Client gehen. */
    private const MAX_OUTPUT_LINES = 300;

    /** Zulässige Umfänge und die zugehörige Option von update.sh. */
    private const SCOPES = [
        'all' => '--all',
        'hugo' => '--hugo',
        'sites' => null,
    ];

    public function __construct(
        private readonly string $releaseDir,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Voraussetzungen auf diesem Server — rein lokal ermittelt, ohne Netz.
     *
     * `ready` erlaubt die Hugo-Erneuerung (Skripte, exec, bash); `gitReady`
     * zusätzlich den Release-Pull (git vorhanden, Repo beschreibbar). Die
     * `reasons` sind Schlüssel, die der Client übersetzt.
     *
     * @return array{releaseDir: string, ready: bool, gitReady: bool, reasons: list<string>}
     */
    public function capabilities(): array
    {
        $reasons = [];
        $execOk = function_exists('exec') && !self::disabled('exec');
        if (!$execOk) {
            $reasons[] = 'exec-disabled';
        }

        $update = $this->script('update.sh');
        $getHugo = $this->script('get-hugo.sh');
        if (!is_file($update) || !is_file($getHugo)) {
            $reasons[] = 'script-missing';
        } elseif (!is_executable($update) || !is_executable($getHugo)) {
            $reasons[] = 'script-not-executable';
        }

        $bashOk = $execOk && $this->which('bash');
        if ($execOk && !$bashOk) {
            $reasons[] = 'bash-missing';
        }

        $ready = $reasons === [];

        $gitReasons = [];
        if ($execOk && !$this->which('git')) {
            $gitReasons[] = 'git-missing';
        }
        $gitDir = $this->releaseDir . '/.git';
        if (!is_dir($gitDir)) {
            $gitReasons[] = 'not-git-repo';
        } elseif (!is_writable($gitDir) || !is_writable($this->releaseDir)) {
            $gitReasons[] = 'git-not-writable';
        }

        return [
            'releaseDir' => $this->releaseDir,
            'ready' => $ready,
            'gitReady' => $ready && $gitReasons === [],
            'reasons' => array_merge($reasons, $gitReasons),
        ];
    }

    /**
     * Prüft, ob Aktualisierungen bereitstehen. Fragt nach außen (GitHub) und
     * dauert entsprechend — nur auf Knopfdruck aufrufen.
     *
     * @return array{
     *   capabilities: array<string, mixed>,
     *   release: array{commit: ?string, subject: ?string, date: ?string, remote: ?string, behind: ?int, error: ?string},
     *   hugo: array{installed: ?string, newest: ?string, updateAvailable: ?bool, error: ?string}
     * }
     */
    public function check(): array
    {
        $caps = $this->capabilities();

        return [
            'capabilities' => $caps,
            'release' => $caps['gitReady'] ? $this->checkRelease() : $this->emptyRelease('not-available'),
            'hugo' => $caps['ready'] ? $this->checkHugo() : ['installed' => null, 'newest' => null, 'updateAvailable' => null, 'error' => 'not-available'],
        ];
    }

    /**
     * Führt das Update aus und liefert Ausgabe und Ergebnis zurück. Ein
     * fehlgeschlagener Lauf ist KEINE Ausnahme — die Ausgabe ist dann gerade
     * das, was der Administrator sehen muss.
     *
     * @param string $scope all | hugo | sites
     * @return array{scope: string, success: bool, exit: int, seconds: float, output: list<string>, truncated: bool}
     */
    public function run(string $scope): array
    {
        if (!array_key_exists($scope, self::SCOPES)) {
            throw ApiException::badRequest('UPDATE-INVALID-SCOPE', [$scope]);
        }
        $caps = $this->capabilities();
        $needsGit = $scope !== 'hugo';
        if (!$caps['ready'] || ($needsGit && !$caps['gitReady'])) {
            throw new ApiException('EUPDATE', 500, 'UPDATE-NOT-AVAILABLE', [implode(', ', $caps['reasons'])]);
        }

        $cmd = escapeshellarg($this->script('update.sh'));
        $option = self::SCOPES[$scope];
        if ($option !== null) {
            $cmd .= ' ' . $option;
        }
        $cmd .= ' 2>&1';

        $this->logger?->info("Software-Update gestartet (Umfang: {$scope}).");
        $lines = [];
        $exit = 1;
        $start = hrtime(true);
        exec($cmd, $lines, $exit);
        $seconds = round((hrtime(true) - $start) / 1e9, 1);

        $truncated = count($lines) > self::MAX_OUTPUT_LINES;
        if ($truncated) {
            $lines = array_slice($lines, -self::MAX_OUTPUT_LINES);
        }

        if ($exit === 0) {
            $this->logger?->info("Software-Update abgeschlossen ({$seconds}s, Umfang: {$scope}).");
        } else {
            $tail = implode("\n", array_slice($lines, -10));
            $this->logger?->warning("Software-Update fehlgeschlagen (Code {$exit}, Umfang: {$scope}): {$tail}");
        }

        return [
            'scope' => $scope,
            'success' => $exit === 0,
            'exit' => $exit,
            'seconds' => $seconds,
            'output' => array_values($lines),
            'truncated' => $truncated,
        ];
    }

    // --- Prüfungen -----------------------------------------------------------

    /**
     * Lokaler Stand und Abstand zum Upstream des Release-Repos. `git fetch`
     * holt nur die Verweise; der Arbeitsbaum bleibt unberührt.
     *
     * @return array{commit: ?string, subject: ?string, date: ?string, remote: ?string, behind: ?int, error: ?string}
     */
    private function checkRelease(): array
    {
        $info = $this->emptyRelease(null);

        $head = $this->git(['log', '-1', '--pretty=%h%x09%cI%x09%s']);
        if ($head['exit'] === 0 && isset($head['lines'][0])) {
            [$commit, $date, $subject] = array_pad(explode("\t", $head['lines'][0], 3), 3, null);
            $info['commit'] = $commit;
            $info['date'] = $date;
            $info['subject'] = $subject;
        }

        $fetch = $this->git(['fetch', '--quiet']);
        if ($fetch['exit'] !== 0) {
            $info['error'] = self::lastLine($fetch['lines']) ?? 'fetch-failed';

            return $info;
        }

        $remote = $this->git(['rev-parse', '--short', '@{u}']);
        if ($remote['exit'] !== 0) {
            $info['error'] = 'no-upstream';

            return $info;
        }
        $info['remote'] = $remote['lines'][0] ?? null;

        $behind = $this->git(['rev-list', '--count', 'HEAD..@{u}']);
        if ($behind['exit'] === 0 && isset($behind['lines'][0]) && ctype_digit($behind['lines'][0])) {
            $info['behind'] = (int) $behind['lines'][0];
        }

        return $info;
    }

    /**
     * Installierte und neueste Hugo-Fassung über `get-hugo.sh --dry-run`. Das
     * Skript fragt die GitHub-API; seine Ausgabeform ist der Vertrag:
     *   „Installed: v0.164.0   newest: v0.169.0“
     *   „Hugo v0.169.0 is up to date: …“  bzw.  „Dry run: would download …“
     *
     * @return array{installed: ?string, newest: ?string, updateAvailable: ?bool, error: ?string}
     */
    private function checkHugo(): array
    {
        $info = ['installed' => null, 'newest' => null, 'updateAvailable' => null, 'error' => null];

        $lines = [];
        $exit = 1;
        exec(escapeshellarg($this->script('get-hugo.sh')) . ' --dry-run 2>&1', $lines, $exit);
        $text = implode("\n", $lines);

        if (preg_match('/Installed:\s+(?:v([\d.]+)|none)\s+newest:\s+v([\d.]+)/', $text, $m) === 1) {
            $info['installed'] = $m[1] !== '' ? $m[1] : null;
            $info['newest'] = $m[2];
        }
        if ($exit !== 0) {
            $info['error'] = self::lastLine($lines) ?? 'check-failed';

            return $info;
        }
        $info['updateAvailable'] = str_contains($text, 'Dry run: would download');

        return $info;
    }

    // --- Hilfsfunktionen -----------------------------------------------------

    /** @return array{commit: null, subject: null, date: null, remote: null, behind: null, error: ?string} */
    private function emptyRelease(?string $error): array
    {
        return ['commit' => null, 'subject' => null, 'date' => null, 'remote' => null, 'behind' => null, 'error' => $error];
    }

    private function script(string $name): string
    {
        return $this->releaseDir . '/bin/' . $name;
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, lines: list<string>}
     */
    private function git(array $args): array
    {
        $cmd = 'git -C ' . escapeshellarg($this->releaseDir);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $lines = [];
        $exit = 1;
        exec($cmd . ' 2>&1', $lines, $exit);

        return ['exit' => $exit, 'lines' => $lines];
    }

    /** Ist das Programm über den PATH des PHP-Prozesses erreichbar? */
    private function which(string $program): bool
    {
        $lines = [];
        $exit = 1;
        @exec('command -v ' . escapeshellarg($program) . ' 2>/dev/null', $lines, $exit);

        return $exit === 0 && isset($lines[0]) && $lines[0] !== '';
    }

    /** Steht die Funktion in php.ini unter disable_functions? */
    private static function disabled(string $function): bool
    {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $list, true);
    }

    /** @param list<string> $lines */
    private static function lastLine(array $lines): ?string
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }
}
