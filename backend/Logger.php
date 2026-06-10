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
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private readonly int $threshold;

    public function __construct(
        private readonly ?string $file = null,
        string $level = 'error',
    ) {
        $this->threshold = self::LEVELS[$level] ?? self::LEVELS['error'];
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
            if ($writable && @file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }

        // Fallback: PHP-eigenes Fehlerlog (Server-Log).
        error_log('[hugocms] ' . $line);
    }
}
