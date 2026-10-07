<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

/**
 * Was die Shop-Anbindung zuletzt getan hat — für den Systemstatus.
 *
 * HugoCMS wird von OpensourceERP nur aufgerufen und ruft nie selbst an. Ohne
 * diese Ablage wüsste die Statusansicht deshalb nicht, ob OpensourceERP
 * überhaupt durchkommt. Festgehalten werden drei Dinge in activity.json:
 *
 *   - contact: der letzte Aufruf mit gültigem Schlüssel (Verbindungstest,
 *     Abgleich, Bau …) — der letzte erfolgreiche Verbindungsaufbau.
 *   - running: die Aufgabe, die gerade läuft. Eine Lieferung besteht aus
 *     mehreren Aufrufen (Abgleich, Portionen, Übernahme); sie gilt von ihrem
 *     ersten bis zu ihrem letzten Aufruf als laufend.
 *   - last: die zuletzt beendete Aufgabe mit Ergebnis.
 *
 * Das Backend ist zustandslos: Bricht OpensourceERP eine Lieferung ab, meldet
 * niemand ihr Ende. Eine laufende Aufgabe, von der eine Weile nichts kam
 * ({@see STALE_AFTER}), gilt deshalb beim Lesen als abgebrochen.
 */
final class ShopActivity
{
    /** Aufgaben der Anbindung. */
    public const TASKS = ['sync', 'thumbnails', 'build'];

    /**
     * Sekunden ohne Lebenszeichen, nach denen eine laufende Aufgabe als
     * abgebrochen gilt — etwas über den Zeitgrenzen der einzelnen Aufrufe.
     */
    private const STALE_AFTER = ['sync' => 360, 'thumbnails' => 180, 'build' => 660];

    public function __construct(private readonly string $varDir)
    {
    }

    /** Vermerkt einen Aufruf mit gültigem Schlüssel. */
    public function contact(string $cmd): void
    {
        $this->update(static function (array $state) use ($cmd): array {
            $state['contact'] = ['at' => gmdate('c'), 'cmd' => $cmd];

            return $state;
        });
    }

    /**
     * Beginnt eine Aufgabe oder schreibt eine laufende fort. Läuft dieselbe
     * Aufgabe bereits, bleibt ihr Beginn erhalten; $detail ergänzt die
     * bisherigen Angaben, $add zählt Werte hinzu (übertragene Dateien).
     *
     * @param array<string, mixed> $detail
     * @param array<string, int> $add
     */
    public function progress(string $task, array $detail = [], array $add = [], bool $restart = false): void
    {
        $this->update(function (array $state) use ($task, $detail, $add, $restart): array {
            $now = gmdate('c');
            $running = $state['running'] ?? null;
            if ($restart || !is_array($running) || ($running['task'] ?? null) !== $task || $this->stale($running)) {
                $running = ['task' => $task, 'startedAt' => $now, 'detail' => []];
            }
            $running['updatedAt'] = $now;
            $running['detail'] = $detail + (array) ($running['detail'] ?? []);
            foreach ($add as $field => $count) {
                $running['detail'][$field] = (int) ($running['detail'][$field] ?? 0) + $count;
            }
            $state['running'] = $running;

            return $state;
        });
    }

    /**
     * Beendet eine Aufgabe. $error trägt bei einem Fehlschlag die Meldung im
     * Format der API ({code, key, params}), damit der Client sie übersetzt.
     *
     * @param array<string, mixed> $detail
     * @param array<string, mixed>|null $error
     */
    public function finish(string $task, bool $success, array $detail = [], ?array $error = null): void
    {
        $this->update(static function (array $state) use ($task, $success, $detail, $error): array {
            $running = $state['running'] ?? null;
            $same = is_array($running) && ($running['task'] ?? null) === $task;
            $state['last'] = [
                'task' => $task,
                'startedAt' => $same ? ($running['startedAt'] ?? null) : null,
                'finishedAt' => gmdate('c'),
                'success' => $success,
                'detail' => $detail + ($same ? (array) ($running['detail'] ?? []) : []),
                'error' => $error,
            ];
            if ($same) {
                $state['running'] = null;
            }

            return $state;
        });
    }

    /**
     * Stand für die Statusansicht. Eine verwaiste laufende Aufgabe erscheint
     * als abgebrochene letzte Aufgabe, sofern sie jünger ist als die zuletzt
     * beendete.
     *
     * @return array{contact: ?array, running: ?array, last: ?array}
     */
    public function state(): array
    {
        $state = $this->read();
        $running = is_array($state['running'] ?? null) ? $state['running'] : null;
        $last = is_array($state['last'] ?? null) ? $state['last'] : null;

        if ($running !== null && $this->stale($running)) {
            if ($last === null || strcmp((string) $running['updatedAt'], (string) ($last['finishedAt'] ?? '')) > 0) {
                $last = [
                    'task' => $running['task'] ?? null,
                    'startedAt' => $running['startedAt'] ?? null,
                    'finishedAt' => $running['updatedAt'] ?? null,
                    'success' => false,
                    'detail' => $running['detail'] ?? [],
                    'error' => ['code' => 'EABORTED', 'key' => 'SHOP-TASK-ABORTED', 'params' => []],
                ];
            }
            $running = null;
        }

        return [
            'contact' => is_array($state['contact'] ?? null) ? $state['contact'] : null,
            'running' => $running,
            'last' => $last,
        ];
    }

    /** @param array<string, mixed> $running */
    private function stale(array $running): bool
    {
        $updated = strtotime((string) ($running['updatedAt'] ?? '')) ?: 0;

        return time() - $updated > (self::STALE_AFTER[$running['task'] ?? ''] ?? 300);
    }

    /**
     * Lesen, ändern, schreiben unter einer Sperre: Die Portionen einer
     * Lieferung können sich mit einem Statusaufruf überschneiden. Ein Fehler
     * hier darf keinen Aufruf der Anbindung scheitern lassen — ohne Ablage
     * fehlt nur die Anzeige.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function update(callable $change): void
    {
        if (!is_dir($this->varDir) && !@mkdir($this->varDir, 0775, true)) {
            return;
        }
        $lock = @fopen($this->varDir . '/activity.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            flock($lock, LOCK_EX);
            $json = json_encode($change($this->read()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tmp = $this->varDir . '/activity.json.' . getmypid() . '.tmp';
            if ($json !== false && @file_put_contents($tmp, $json) !== false) {
                @rename($tmp, $this->varDir . '/activity.json');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $file = $this->varDir . '/activity.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
