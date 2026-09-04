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
 *
 * Ignorierte Funde ({@see IgnoreStore}) werden erst BEIM AUSLIEFERN angewandt:
 * Der abgelegte Bericht bleibt der unverfälschte Schnappschuss seines Laufs,
 * die Ignorierung wirkt dadurch aber rückwirkend auf alle gespeicherten Läufe
 * und nicht nur auf den nächsten.
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
    /** Speicher der ignorierten Funde — liegt neben den Läufen dieser Webseite. */
    private readonly IgnoreStore $ignored;

    public function __construct(
        private readonly string $publicDir,
        private readonly string $sourceDir,
        private readonly string $storageDir,
        private readonly array $excludePrefixes = [],
        private readonly array $excludeFiles = [],
    ) {
        $this->ignored = new IgnoreStore($storageDir);
    }

    /**
     * Setzt oder löst die Ignorierung mehrerer Funde. Liefert die Zahl der
     * geänderten Einträge und den neuen Gesamtstand.
     *
     * @param list<string> $keys Fund-Kennungen ({@see IgnoreStore::keyFor})
     * @return array{changed: int, ignoredTotal: int}
     */
    public function ignore(array $keys, bool $ignored): array
    {
        $changed = $this->ignored->set($keys, $ignored);

        return ['changed' => $changed, 'ignoredTotal' => $this->ignored->count()];
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

        return $this->applyIgnored($report);
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
            // Auch der Verlaufseintrag zählt ohne die ignorierten Funde — sonst
            // widerspräche die Auswahlliste dem geöffneten Bericht.
            $report = $this->applyIgnored($report);
            $runs[] = [
                'id' => $report['id'] ?? basename($path, '.json'),
                'startedAt' => $report['startedAt'] ?? null,
                'seconds' => $report['seconds'] ?? null,
                'pagesScanned' => $report['pagesScanned'] ?? 0,
                'rulesApplied' => $report['rulesApplied'] ?? 0,
                'truncated' => $report['truncated'] ?? false,
                'summary' => $report['summary'] ?? ['error' => 0, 'warning' => 0, 'hint' => 0],
                'ignoredCount' => $report['ignoredCount'] ?? 0,
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
        if ($files === []) {
            return null;
        }
        $report = $this->readReport($files[0]);

        return $report === null ? null : $this->applyIgnored($report);
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

        return $this->applyIgnored($report);
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
     * Wendet die Ignorierliste auf einen gelesenen Bericht an: Jeder ignorierte
     * Fund wird mit `ignored: true` markiert und aus Zusammenfassung und
     * Kategoriezählern herausgerechnet; `ignoredCount` nennt ihre Zahl.
     *
     * Die Funde selbst BLEIBEN im Bericht — der Client zeigt sie am Ende der
     * Liste und über einen eigenen Filter, damit eine Ignorierung sichtbar und
     * rücknehmbar bleibt. Wer sie nicht sehen will (E-Mail-Bericht, Verknüpfung
     * mit der Content-Prüfung), filtert auf das Kennzeichen.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function applyIgnored(array $report): array
    {
        if (!is_array($report['issues'] ?? null) || $report['issues'] === []) {
            $report['ignoredCount'] = 0;

            return $report;
        }
        // Ohne einen einzigen ignorierten Fund bleiben die im Lauf berechneten
        // Zähler unangetastet (der Normalfall, kein Nachrechnen nötig).
        if ($this->ignored->count() === 0) {
            $report['ignoredCount'] = 0;

            return $report;
        }

        $summary = ['error' => 0, 'warning' => 0, 'hint' => 0];
        $byCategory = [];
        $ignoredCount = 0;
        foreach ($report['issues'] as &$issue) {
            if (!is_array($issue)) {
                continue;
            }
            if ($this->ignored->has(IgnoreStore::keyFor($issue))) {
                $issue['ignored'] = true;
                ++$ignoredCount;
                continue;
            }
            $sev = (string) ($issue['severity'] ?? 'hint');
            $cat = (string) ($issue['category'] ?? '');
            $summary[$sev] = ($summary[$sev] ?? 0) + 1;
            if ($cat === '') {
                continue;
            }
            $byCategory[$cat] ??= ['error' => 0, 'warning' => 0, 'hint' => 0, 'total' => 0];
            $byCategory[$cat][$sev] = ($byCategory[$cat][$sev] ?? 0) + 1;
            ++$byCategory[$cat]['total'];
        }
        unset($issue);

        $report['summary'] = $summary;
        // Kategorien ohne verbleibenden Fund fallen weg — ihr Filter-Chip wäre
        // sonst eine Schaltfläche, die auf eine leere Liste führt.
        $report['byCategory'] = $byCategory === [] ? new \stdClass() : $byCategory;
        $report['ignoredCount'] = $ignoredCount;

        return $report;
    }

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

    /**
     * Liest die in der Hugo-Konfiguration hinterlegte baseURL (Live-Adresse der
     * Webseite) — zur Vorbelegung des PageSpeed-Feldes im Konfigurationsdialog.
     * TOML/YAML/JSON werden wie bei {@see detectContentDir} per Regex abgegriffen,
     * ohne Parser-Abhängigkeit. Liefert die getrimmte Adresse oder null, wenn
     * keine (brauchbare) baseURL gefunden wird. Ein reines "/" (Hugo-Vorgabe für
     * relative URLs) gilt als nicht gesetzt.
     */
    public static function detectBaseUrl(string $sourceDir): ?string
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
            if (preg_match('/^\s*["\']?baseURL["\']?\s*[:=]\s*["\']([^"\']+)["\']/mi', $raw, $m) === 1) {
                $url = trim($m[1]);
                if ($url !== '' && $url !== '/' && preg_match('#^https?://#i', $url) === 1) {
                    return rtrim($url, '/') . '/';
                }
            }
        }

        return null;
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
     * Es zählt NUR, was eine gültige Lauf-ID trägt: Im selben Verzeichnis liegt
     * auch die Ignorierliste ({@see IgnoreStore}). Ohne diese Prüfung erschiene
     * sie als Lauf im Verlauf — und die Retention würde sie nach zehn Läufen
     * löschen.
     *
     * @return list<string>
     */
    private function files(): array
    {
        if (!is_dir($this->storageDir)) {
            return [];
        }
        $files = array_values(array_filter(
            glob($this->storageDir . '/*.json') ?: [],
            static fn (string $path): bool => preg_match(self::ID_PATTERN, basename($path, '.json')) === 1,
        ));
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
