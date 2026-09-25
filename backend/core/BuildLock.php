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

    /** Vermerkt den Beginn eines Laufs; der Auslöser ist manual, cron oder shop. */
    public function recordStart(string $trigger): void
    {
        $this->write([
            'trigger' => $trigger,
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'success' => null,
            'exitCode' => null,
            'seconds' => null,
            'output' => '',
        ]);
    }

    /**
     * Vermerkt das Ende eines Laufs.
     *
     * @param array{success: bool, exitCode: int, output: string, seconds: float} $result
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
