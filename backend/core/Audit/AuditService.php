<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Dünner Dienst um den Audit-Lauf — das Pendant zu {@see \HugoCMS\FileManager\GitService}
 * (eine Pro-Funktion). Führt Läufe aus, legt sie als JSON-Bericht im
 * Speicherverzeichnis ab (keine Datenbank) und hält per Retention nur die
 * jüngsten {@see RETENTION} Läufe vor — damit lassen sich Vorher/Nachher
 * vergleichen.
 *
 * Die Freischaltung (Auth + Pro-Lizenz + Hugo-Projekt) prüft der Connector,
 * bevor er diesen Dienst überhaupt erzeugt.
 */
final class AuditService
{
    /** Wie viele Läufe je Webseite aufgehoben werden. */
    private const int RETENTION = 10;

    /** Erlaubtes Format einer Lauf-ID (Zeitstempel, ggf. mit Eindeutigkeitssuffix). */
    private const string ID_PATTERN = '/^[0-9]{8}-[0-9]{6}(?:-[a-z0-9]{1,8})?$/';

    /**
     * @param list<string> $excludePrefixes Zusätzliche public-relative Präfixe
     *        aus der [seo_report]-Sektion, die vom Audit übersprungen werden.
     * @param list<string> $excludeFiles Einzelne public-relative Dateien aus der
     *        [seo_report]-Sektion, die vom Audit übersprungen werden.
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly string $sourceDir,
        private readonly string $storageDir,
        private readonly array $excludePrefixes = [],
        private readonly array $excludeFiles = [],
    ) {
    }

    /**
     * Führt einen neuen Lauf aus, speichert ihn und liefert den vollständigen
     * Bericht (inkl. ID) zurück.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        if (!is_dir($this->publicDir)) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-BUILD-OUTPUT');
        }

        $report = (new AuditRunner(
            $this->publicDir,
            $this->sourceDir,
            self::detectContentDir($this->sourceDir),
            $this->excludePrefixes,
            $this->excludeFiles,
        ))->run();
        $report['id'] = $this->freshId();

        $this->persist($report);
        $this->applyRetention();

        return $report;
    }

    /**
     * Metadaten aller gespeicherten Läufe, neueste zuerst (ohne Einzelfunde).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $runs = [];
        foreach ($this->files() as $path) {
            $report = $this->readReport($path);
            if ($report === null) {
                continue;
            }
            $runs[] = [
                'id' => $report['id'] ?? basename($path, '.json'),
                'startedAt' => $report['startedAt'] ?? null,
                'seconds' => $report['seconds'] ?? null,
                'pagesScanned' => $report['pagesScanned'] ?? 0,
                'rulesApplied' => $report['rulesApplied'] ?? 0,
                'truncated' => $report['truncated'] ?? false,
                'summary' => $report['summary'] ?? ['error' => 0, 'warning' => 0, 'hint' => 0],
            ];
        }
        usort($runs, static fn (array $a, array $b): int => strcmp((string) $b['id'], (string) $a['id']));

        return $runs;
    }

    /**
     * Vollständiger Bericht des jüngsten Laufs oder null, falls es keinen gibt.
     * Für die Verknüpfung eines einzelnen Content-Berichts mit den SEO-Funden.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $files = $this->files(); // neueste zuerst
        return $files === [] ? null : $this->readReport($files[0]);
    }

    /**
     * Vollständiger Bericht eines Laufs.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $report = $this->readReport($this->pathFor($id));
        if ($report === null) {
            throw ApiException::notFound('AUDIT-RUN-NOT-FOUND', [$id]);
        }

        return $report;
    }

    /**
     * Löscht einen Lauf.
     *
     * @return array{deleted: string}
     */
    public function delete(string $id): array
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            throw ApiException::notFound('AUDIT-RUN-NOT-FOUND', [$id]);
        }
        @unlink($path);

        return ['deleted' => $id];
    }

    /**
     * Löscht alle gespeicherten Läufe bis auf den jüngsten. Aufräumhilfe, um den
     * Verlauf ohne Einzellöschungen zu leeren; der zuletzt erzeugte Bericht (für
     * Vergleiche der wichtigste) bleibt erhalten.
     *
     * @return array{deleted: int, kept: ?string}
     */
    public function deleteAllButLatest(): array
    {
        $files = $this->files(); // neueste zuerst
        $kept = $files === [] ? null : basename($files[0], '.json');
        $deleted = 0;
        foreach (array_slice($files, 1) as $old) {
            if (@unlink($old)) {
                ++$deleted;
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    // --- Intern ------------------------------------------------------------

    /**
     * Ermittelt den Hugo-contentDir-Namen (relativ zur Projektwurzel) aus der
     * Hugo-Konfiguration; Standard "content". Hugo erlaubt einen abweichenden
     * Namen (hier z. B. "inhalte"), deshalb wird er nicht festverdrahtet. TOML/
     * YAML/JSON werden per Regex abgegriffen — ohne Parser-Abhängigkeit.
     */
    public static function detectContentDir(string $sourceDir): string
    {
        $candidates = [
            'hugo.toml', 'hugo.yaml', 'hugo.yml', 'hugo.json',
            'config.toml', 'config.yaml', 'config.yml', 'config.json',
            'config/_default/hugo.toml', 'config/_default/config.toml',
        ];
        foreach ($candidates as $rel) {
            $path = $sourceDir . '/' . $rel;
            if (!is_file($path)) {
                continue;
            }
            $raw = (string) file_get_contents($path);
            if (preg_match('/^\s*["\']?contentDir["\']?\s*[:=]\s*["\']([^"\']+)["\']/mi', $raw, $m) === 1) {
                $dir = trim($m[1], " /\\");
                if ($dir !== '' && !str_contains($dir, '..')) {
                    return $dir;
                }
            }
        }

        return 'content';
    }

    /** Erzeugt eine eindeutige, dateinamenssichere Lauf-ID (UTC-Zeitstempel). */
    private function freshId(): string
    {
        $base = gmdate('Ymd-His');
        $id = $base;
        $suffix = 0;
        while (is_file($this->storageDir . '/' . $id . '.json')) {
            $id = $base . '-' . base_convert((string) (++$suffix), 10, 36);
        }

        return $id;
    }

    /** @param array<string, mixed> $report */
    private function persist(array $report): void
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new ApiException('EIO', 500, 'AUDIT-STORAGE-FAILED');
        }
        $path = $this->storageDir . '/' . $report['id'] . '.json';
        $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
            throw new ApiException('EIO', 500, 'AUDIT-STORAGE-FAILED');
        }
    }

    /** Behält nur die jüngsten RETENTION Läufe. */
    private function applyRetention(): void
    {
        $files = $this->files(); // neueste zuerst
        foreach (array_slice($files, self::RETENTION) as $old) {
            @unlink($old);
        }
    }

    /**
     * Gespeicherte Berichtsdateien, neueste zuerst (nach Dateiname/ID sortiert).
     *
     * @return list<string>
     */
    private function files(): array
    {
        if (!is_dir($this->storageDir)) {
            return [];
        }
        $files = glob($this->storageDir . '/*.json') ?: [];
        rsort($files);

        return array_values($files);
    }

    private function pathFor(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw ApiException::badRequest('AUDIT-RUN-NOT-FOUND', [$id]);
        }

        return $this->storageDir . '/' . $id . '.json';
    }

    /**
     * Liest und dekodiert einen Bericht oder null bei Fehler.
     *
     * @return array<string, mixed>|null
     */
    private function readReport(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
