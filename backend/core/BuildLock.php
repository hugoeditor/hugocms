<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

/**
 * Sperre und letzter Stand der Hugo-Läufe einer Webseite.
 *
 * Gebaut wird aus drei Richtungen: über den Knopf, aus dem Cron und — seit der
 * Shop-Anbindung — auf Anstoß von OpensourceERP. Ohne Sperre konnten zwei
 * Läufe gleichzeitig in dasselbe Ziel schreiben; mit --cleanDestinationDir
 * räumte der eine dann weg, was der andere gerade erzeugt hat.
 *
 * Die Sperre wartet (flock ohne LOCK_NB): Ein zweiter Lauf baut erst, wenn der
 * erste fertig ist, und sieht dann den neuesten Stand. Keiner der Aufrufer
 * braucht dafür einen neuen Fehlerfall.
 *
 * Daneben steht der letzte Lauf in last.json — für die Abfrage des Baustands,
 * die OpensourceERP nach einem Anstoß stellt.
 *
 * Für den Abbruch eines Laufs liegt während des Hugo-Aufrufs dessen
 * Prozessnummer in hugo.pid. Der Abbruch kommt als eigene Anfrage
 * ({@see cancel()}): Sie hinterlegt eine Markierung und beendet den Prozess;
 * der wartende Lauf erkennt daran, dass er abgebrochen wurde und nicht
 * gescheitert ist.
 */
final class BuildLock
{
    /** Zeilen der Hugo-Ausgabe, die im Stand aufbewahrt werden. */
    private const OUTPUT_LINES = 50;

    /** @var resource|null */
    private $handle = null;

    /**
     * @param string $dir Laufzeitverzeichnis dieser Webseite, etwa
     *                    backend/var/build/<sha1(Quelle)>
     */
    public function __construct(private readonly string $dir)
    {
    }

    /** Wartet, bis kein anderer Lauf dieser Webseite mehr baut, und hält dann die Sperre. */
    public function acquire(): void
    {
        $this->ensureDir();
        $handle = @fopen($this->dir . '/build.lock', 'c');
        if ($handle === false) {
            // Ohne Sperrdatei (fehlende Rechte) wird trotzdem gebaut — so wie
            // vor Einführung der Sperre. Der Fehler steht im Stand nicht, weil
            // es auch keinen Ort dafür gibt.
            return;
        }
        flock($handle, LOCK_EX);
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Baut gerade ein Lauf? Prüft, ob sich die Sperre nehmen ließe, und gibt sie
     * sofort wieder frei. Ein Lauf, der genau jetzt starten will, wartet dabei
     * nur diesen Augenblick — er gibt nicht auf.
     */
    public function isRunning(): bool
    {
        $file = $this->dir . '/build.lock';
        if (!is_file($file)) {
            return false;
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /**
     * Merkt die Prozessnummer des laufenden Hugo-Aufrufs für {@see cancel()}
     * und verwirft eine Abbruch-Markierung aus einem früheren Lauf. Nur
     * aufrufen, solange die Sperre gehalten wird.
     */
    public function recordProcess(int $pid): void
    {
        $this->ensureDir();
        @unlink($this->dir . '/cancel');
        @file_put_contents($this->dir . '/hugo.pid', (string) $pid, LOCK_EX);
    }

    /** Der Hugo-Aufruf ist beendet: Prozessnummer vergessen. */
    public function clearProcess(): void
    {
        @unlink($this->dir . '/hugo.pid');
    }

    /**
     * Wurde der gerade beendete Lauf abgebrochen? Liest die Markierung von
     * {@see cancel()} und entfernt sie.
     */
    public function takeCancelled(): bool
    {
        $file = $this->dir . '/cancel';
        if (!is_file($file)) {
            return false;
        }
        @unlink($file);

        return true;
    }

    /**
     * Bricht den laufenden Hugo-Aufruf dieser Webseite ab (SIGTERM).
     *
     * Die Prozessnummer gilt nur, solange die Sperre gehalten wird: Ohne
     * laufenden Lauf wird nichts beendet — auch dann nicht, wenn nach einem
     * hart beendeten PHP-Prozess eine veraltete hugo.pid liegen geblieben ist
     * und die Nummer inzwischen einem fremden Prozess gehört.
     *
     * @return bool true, wenn das Signal zugestellt wurde
     */
    public function cancel(): bool
    {
        if (!$this->isRunning()) {
            return false;
        }
        $pid = (int) trim((string) @file_get_contents($this->dir . '/hugo.pid'));
        if ($pid <= 1) {
            // Der Lauf wartet noch, ist schon beim Tausch oder lässt sich
            // nicht abbrechen (direkter Lauf ohne Zwischenverzeichnis).
            return false;
        }
        @file_put_contents($this->dir . '/cancel', gmdate('c'));
        if (function_exists('posix_kill')) {
            $sent = @posix_kill($pid, 15);
        } elseif (function_exists('exec')) {
            // Shared Hosting ohne POSIX-Erweiterung: über die Shell.
            $code = 1;
            @exec('kill -TERM ' . $pid . ' 2>/dev/null', $unused, $code);
            $sent = $code === 0;
        } else {
            $sent = false;
        }
        if (!$sent) {
            @unlink($this->dir . '/cancel');
        }

        return $sent;
    }

    /** Vermerkt den Beginn eines Laufs; der Auslöser ist manual, cron oder shop. */
    public function recordStart(string $trigger): void
    {
        $this->write([
            'trigger' => $trigger,
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'success' => null,
            'cancelled' => null,
            'exitCode' => null,
            'seconds' => null,
            'output' => '',
        ]);
    }

    /**
     * Vermerkt das Ende eines Laufs.
     *
     * @param array{success: bool, cancelled?: bool, exitCode: int, output: string, seconds: float} $result
     */
    public function recordFinish(array $result): void
    {
        $last = $this->last() ?? [];
        $lines = explode("\n", (string) $result['output']);
        $this->write([
            'trigger' => $last['trigger'] ?? null,
            'startedAt' => $last['startedAt'] ?? null,
            'finishedAt' => gmdate('c'),
            'success' => (bool) $result['success'],
            'cancelled' => (bool) ($result['cancelled'] ?? false),
            'exitCode' => (int) $result['exitCode'],
            'seconds' => (float) $result['seconds'],
            'output' => implode("\n", array_slice($lines, -self::OUTPUT_LINES)),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function last(): ?array
    {
        $file = $this->dir . '/last.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $this->ensureDir();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        // Über eine Zwischendatei: Wer gerade liest, sieht den alten oder den
        // neuen Stand, nie eine halbe Datei.
        $tmp = $this->dir . '/last.json.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $this->dir . '/last.json');
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }
}
