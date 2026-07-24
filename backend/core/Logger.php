<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use Throwable;

/**
 * Schlankes Datei-Logging mit Stufen. Ohne konfigurierte Datei (oder wenn
 * diese nicht beschreibbar ist) fällt das Log auf error_log() zurück, damit
 * nie etwas verloren geht.
 *
 *   $log = new Logger(__DIR__ . '/log/hugocms.log', 'error');
 *   $log->error('Etwas ging schief', ['target' => $id]);
 *
 * Größenbasierte Rotation ohne externe Werkzeuge (logrotate/Cron): Überschreitet
 * die Datei $maxBytes, wird sie beim nächsten Schreiben durchrotiert
 * (hugocms.log → .1 → .2 … bis .$keep, ältester Stand fällt weg). So bleibt der
 * Plattenverbrauch bei ~ $maxBytes × ($keep + 1) gedeckelt. $maxBytes = 0 schaltet
 * die Rotation ab.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private readonly int $threshold;
    private readonly int $maxBytes;
    private readonly int $keep;

    public function __construct(
        private readonly ?string $file = null,
        string $level = 'error',
        int $maxBytes = 1_048_576,
        int $keep = 3,
    ) {
        $this->threshold = self::LEVELS[$level] ?? self::LEVELS['error'];
        $this->maxBytes = max(0, $maxBytes);
        $this->keep = max(1, $keep);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Protokolliert eine Ausnahme mit Typ, Ort und Stacktrace.
     */
    public function exception(Throwable $e): void
    {
        $this->log('error', $e->getMessage(), [
            'type' => $e::class,
            'at' => $e->getFile() . ':' . $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ]);
    }

    /**
     * Liest die letzten $maxLines Zeilen einer Logdatei — für die
     * Protokollansicht im Systemstatus. Liest blockweise von hinten, damit auch
     * ein hochgesetztes max_bytes den Speicher nicht sprengt.
     *
     * $index wählt den Stand: 0 die aktuelle Datei, N > 0 den rotierten Stand
     * „.N“ (.1 der jüngste). So lässt sich über das Auswahlfeld auch im Archiv
     * blättern.
     *
     * Die Zeilen kommen in Dateireihenfolge (älteste zuerst) und bereits
     * zerlegt: Zeitstempel, Stufe und Text. Zeilen, die dem Format nicht
     * entsprechen (Fortsetzungen eines Stacktrace etwa), tragen level = null
     * und den Rohtext — sie gehen nicht verloren.
     *
     * @return array{file: ?string, index: int, exists: bool, size: int, mtime: ?int, truncated: bool, lines: list<array{time: ?string, level: ?string, text: string}>}
     */
    public function tail(int $maxLines = 200, int $index = 0): array
    {
        $maxLines = max(1, min(2000, $maxLines));
        $index = max(0, $index);
        $target = $this->pathForIndex($index);
        $out = [
            'file' => $target,
            'index' => $index,
            'exists' => false,
            'size' => 0,
            'mtime' => null,
            'truncated' => false,
            'lines' => [],
        ];
        if ($target === null || !is_file($target) || !is_readable($target)) {
            return $out;
        }

        $size = (int) (@filesize($target) ?: 0);
        $out['exists'] = true;
        $out['size'] = $size;
        $out['mtime'] = @filemtime($target) ?: null;

        $fp = @fopen($target, 'rb');
        if ($fp === false) {
            return $out;
        }

        // Von hinten in 8-KiB-Schritten lesen, bis genug Zeilenumbrüche
        // zusammengekommen sind oder der Dateianfang erreicht ist.
        $chunk = 8192;
        $pos = $size;
        $buffer = '';
        $found = 0;
        while ($pos > 0 && $found <= $maxLines) {
            $read = (int) min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $part = (string) fread($fp, $read);
            $buffer = $part . $buffer;
            $found = substr_count($buffer, "\n");
        }
        fclose($fp);
        $out['truncated'] = $pos > 0;

        $lines = preg_split('/\R/', trim($buffer, "\r\n")) ?: [];
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
            $out['truncated'] = true;
        }

        foreach ($lines as $line) {
            $out['lines'][] = self::parseLine($line);
        }

        return $out;
    }

    /**
     * Vollständiger Pfad eines Standes: Index 0 die aktuelle Datei, N > 0 der
     * rotierte Stand „.N“. Ohne konfigurierte Datei null.
     */
    private function pathForIndex(int $index): ?string
    {
        if ($this->file === null) {
            return null;
        }

        return $index <= 0 ? $this->file : $this->file . '.' . $index;
    }

    /**
     * Listet die Protokollstände: die aktuelle Datei (Index 0) und die
     * rotierten Stände .1 … .$keep, soweit vorhanden — jüngster zuerst. Für das
     * Auswahlfeld im Systemstatus.
     *
     * Die aktuelle Datei (Index 0) wird immer aufgeführt, auch direkt nach einer
     * Rotation, wenn sie noch leer ist und der nächste Schreibvorgang sie erst
     * anlegt — sonst verschwände der Eintrag „Aktuell“ und mit ihm das
     * Auswahlfeld, obwohl es rotierte Stände gibt. Rotierte Stände erscheinen
     * nur, wenn sie vorhanden sind.
     *
     * @return list<array{index: int, file: string, size: int, mtime: ?int}>
     */
    public function archives(): array
    {
        if ($this->file === null) {
            return [];
        }

        $out = [];
        for ($i = 0; $i <= $this->keep; $i++) {
            $path = $this->pathForIndex($i);
            if ($path === null) {
                continue;
            }
            $exists = is_file($path);
            if (!$exists && $i !== 0) {
                continue;
            }
            $out[] = [
                'index' => $i,
                'file' => $path,
                'size' => $exists ? (int) (@filesize($path) ?: 0) : 0,
                'mtime' => $exists ? (@filemtime($path) ?: null) : null,
            ];
        }

        return $out;
    }

    /**
     * Rotiert die Logdatei sofort — unabhängig von der Größe und selbst bei
     * abgeschaltetem max_bytes. Für den Knopf „Jetzt rotieren“ im Systemstatus:
     * eine ausdrückliche Handlung, kein Automatismus. Der laufende Stand wird
     * zur .1, die älteren rücken nach, der älteste (.$keep) fällt weg; der
     * nächste Schreibvorgang legt eine frische Datei an.
     *
     * @return bool true, wenn rotiert wurde; false, wenn keine (nicht leere)
     *              Logdatei zum Rotieren vorlag.
     */
    public function rotate(): bool
    {
        if ($this->file === null || !is_file($this->file)) {
            return false;
        }
        clearstatcache(false, $this->file);
        if ((int) (@filesize($this->file) ?: 0) === 0) {
            return false;
        }

        $this->shift();

        return true;
    }

    /**
     * Zerlegt eine Logzeile im Format „[Y-m-d H:i:s] STUFE: Text“. Passt das
     * Muster nicht, gilt die ganze Zeile als Text ohne Stufe.
     *
     * @return array{time: ?string, level: ?string, text: string}
     */
    private static function parseLine(string $line): array
    {
        if (preg_match('/^\[([^\]]+)\]\s+([A-Z]+):\s*(.*)$/s', $line, $m) === 1
            && isset(self::LEVELS[strtolower($m[2])])
        ) {
            return ['time' => $m[1], 'level' => strtolower($m[2]), 'text' => $m[3]];
        }

        return ['time' => null, 'level' => null, 'text' => $line];
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 3) < $this->threshold) {
            return;
        }

        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), strtoupper($level), $message);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->write($line);
    }

    private function write(string $line): void
    {
        if ($this->file !== null) {
            $dir = dirname($this->file);
            $writable = (is_file($this->file) && is_writable($this->file))
                || (!file_exists($this->file) && is_dir($dir) && is_writable($dir));
            if ($writable) {
                $this->maybeRotate();
                if (@file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
                    return;
                }
            }
        }

        // Fallback: PHP-eigenes Fehlerlog (Server-Log).
        error_log('[hugocms] ' . $line);
    }

    /**
     * Rotiert die Logdatei automatisch, sobald sie $maxBytes erreicht. Die
     * eigentliche Verschiebung übernimmt shift(); danach legt der
     * Schreibvorgang eine frische Datei an.
     */
    private function maybeRotate(): void
    {
        if ($this->maxBytes <= 0 || $this->file === null) {
            return;
        }
        clearstatcache(false, $this->file);
        $size = @filesize($this->file);
        if ($size === false || $size < $this->maxBytes) {
            return;
        }

        $this->shift();
    }

    /**
     * Schiebt die Stände durch: ältesten (.$keep) löschen, übrige um eins
     * hochschieben, aktuelles Log zur .1 machen. rename() ist atomar — ein
     * seltener paralleler Doppelaufruf verliert höchstens einen Altstand, nie
     * den laufenden Eintrag oder die Datei-Integrität.
     */
    private function shift(): void
    {
        if ($this->file === null) {
            return;
        }

        @unlink($this->file . '.' . $this->keep);
        for ($i = $this->keep - 1; $i >= 1; $i--) {
            if (is_file($this->file . '.' . $i)) {
                @rename($this->file . '.' . $i, $this->file . '.' . ($i + 1));
            }
        }
        @rename($this->file, $this->file . '.1');
    }
}
