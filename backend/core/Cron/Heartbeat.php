<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Cron;

/**
 * Herzschlag der CLI-/Cron-Läufe: hält je Aufgabe fest, wann sie zuletzt lief,
 * wie lange sie brauchte und ob sie erfolgreich war. Damit kann die Anwendung
 * zeigen, welche Cron-Jobs auf dem Hosting TATSÄCHLICH laufen — die Crontab
 * selbst ist nicht verlässlich lesbar (auf Shared Hosting ist `crontab -l`
 * meist gesperrt und gehört ohnehin oft einem anderen Benutzer).
 *
 * Speicher wie {@see \HugoCMS\FileManager\Review\ReviewStore}: eine JSON-Datei
 * je Aufgabe im Speicherverzeichnis, atomar über Tempdatei + rename. Kein
 * langlaufender Zustand, keine Datenbank.
 *
 * Aus den letzten Startzeitpunkten wird der Takt geschätzt (Median der
 * Abstände). Liegt der letzte Lauf deutlich länger zurück als dieser Takt, gilt
 * die Aufgabe als überfällig — der übliche Hinweis darauf, dass ein Cron-Eintrag
 * entfernt wurde oder stillschweigend scheitert.
 */
final class Heartbeat
{
    /** Aufgaben, die es gibt — feste Reihenfolge für die Anzeige. */
    public const array JOBS = ['build', 'improve', 'healthcheck'];

    /** Wie viele Startzeitpunkte je Aufgabe für die Taktschätzung aufgehoben werden. */
    private const int KEEP_RUNS = 10;

    /**
     * Ab welchem Vielfachen des geschätzten Takts eine Aufgabe als überfällig
     * gilt. 2.5 lässt Schwankungen (Serverlast, Sommerzeit) zu, schlägt aber an,
     * bevor zwei ganze Takte ausgefallen sind.
     */
    private const float OVERDUE_FACTOR = 2.5;

    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * Vermerkt einen abgeschlossenen Lauf. Fehler beim Schreiben werden
     * geschluckt — ein Herzschlag darf einen erfolgreichen Cron-Lauf niemals zu
     * einem Fehlschlag machen.
     *
     * @param string  $job     eine der {@see JOBS}
     * @param bool    $success false, wenn der Lauf mit einer Ausnahme endete
     * @param string  $summary kurze Zusammenfassung für die Anzeige
     * @param ?string $error   Fehlermeldung bzw. -code, falls fehlgeschlagen
     */
    public function record(
        string $job,
        bool $success,
        string $summary = '',
        ?string $error = null,
        float $seconds = 0.0,
        ?string $startedAt = null,
    ): void {
        if (!in_array($job, self::JOBS, true)) {
            return;
        }
        $startedAt ??= gmdate('c');

        $entry = $this->read($job) ?? [];
        $runs = is_array($entry['runs'] ?? null) ? array_values($entry['runs']) : [];
        $runs[] = $startedAt;
        if (count($runs) > self::KEEP_RUNS) {
            $runs = array_slice($runs, -self::KEEP_RUNS);
        }

        $this->write($job, [
            'job' => $job,
            'lastRun' => $startedAt,
            'seconds' => round($seconds, 2),
            'success' => $success,
            'summary' => $summary,
            'error' => $error,
            // Zeitpunkt des letzten ERFOLGREICHEN Laufs getrennt halten: Eine
            // Aufgabe kann seit Tagen laufen und dabei jedes Mal scheitern.
            'lastSuccessAt' => $success ? $startedAt : ($entry['lastSuccessAt'] ?? null),
            'runs' => $runs,
        ]);
    }

    /**
     * Zustand aller Aufgaben in der Reihenfolge von {@see JOBS}. Nie gelaufene
     * Aufgaben erscheinen mit `seen: false` — auch das ist eine Aussage
     * („dieser Cron-Job ist hier nicht eingerichtet“).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach (self::JOBS as $job) {
            $entry = $this->read($job);
            if ($entry === null) {
                $out[] = ['job' => $job, 'seen' => false];
                continue;
            }
            $runs = is_array($entry['runs'] ?? null) ? $entry['runs'] : [];
            $interval = self::estimateInterval($runs);
            $last = is_string($entry['lastRun'] ?? null) ? strtotime($entry['lastRun']) : false;

            $out[] = [
                'job' => $job,
                'seen' => true,
                'lastRun' => $entry['lastRun'] ?? null,
                'lastSuccessAt' => $entry['lastSuccessAt'] ?? null,
                'seconds' => $entry['seconds'] ?? 0,
                'success' => (bool) ($entry['success'] ?? false),
                'summary' => (string) ($entry['summary'] ?? ''),
                'error' => $entry['error'] ?? null,
                'runCount' => count($runs),
                // Geschätzter Takt in Sekunden; null bei weniger als zwei Läufen.
                'intervalSeconds' => $interval,
                'overdue' => $interval !== null && $last !== false
                    && ($now - $last) > (int) round($interval * self::OVERDUE_FACTOR),
            ];
        }

        return $out;
    }

    /**
     * Schätzt den Takt aus den Abständen der Startzeitpunkte (Median, robust
     * gegen einzelne Ausreißer wie einen nachgeholten Lauf). null, solange
     * weniger als zwei Läufe vorliegen.
     *
     * @param list<mixed> $runs
     */
    private static function estimateInterval(array $runs): ?int
    {
        $stamps = [];
        foreach ($runs as $r) {
            $t = is_string($r) ? strtotime($r) : false;
            if ($t !== false) {
                $stamps[] = $t;
            }
        }
        if (count($stamps) < 2) {
            return null;
        }
        sort($stamps);

        $gaps = [];
        for ($i = 1, $n = count($stamps); $i < $n; $i++) {
            $gap = $stamps[$i] - $stamps[$i - 1];
            if ($gap > 0) {
                $gaps[] = $gap;
            }
        }
        if ($gaps === []) {
            return null;
        }
        sort($gaps);
        $mid = intdiv(count($gaps), 2);

        return count($gaps) % 2 === 1
            ? $gaps[$mid]
            : (int) round(($gaps[$mid - 1] + $gaps[$mid]) / 2);
    }

    // --- Speicher -----------------------------------------------------------

    private function pathFor(string $job): string
    {
        return $this->storageDir . '/' . $job . '.json';
    }

    /** @return array<string, mixed>|null */
    private function read(string $job): ?array
    {
        $path = $this->pathFor($job);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $entry */
    private function write(string $job, array $entry): void
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            return;
        }
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = @tempnam($this->storageDir, '.cron');
        if ($tmp === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $this->pathFor($job))) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
