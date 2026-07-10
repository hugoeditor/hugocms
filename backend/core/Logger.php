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
     * Rotiert die Logdatei, sobald sie $maxBytes erreicht: ältesten Stand
     * löschen, übrige um eins hochschieben, aktuelles Log zur .1 machen. Danach
     * legt der Schreibvorgang eine frische Datei an. rename() ist atomar — ein
     * seltener paralleler Doppelaufruf verliert höchstens einen Altstand, nie
     * den laufenden Eintrag oder die Datei-Integrität.
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

        @unlink($this->file . '.' . $this->keep);
        for ($i = $this->keep - 1; $i >= 1; $i--) {
            if (is_file($this->file . '.' . $i)) {
                @rename($this->file . '.' . $i, $this->file . '.' . ($i + 1));
            }
        }
        @rename($this->file, $this->file . '.1');
    }
}
