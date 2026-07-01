<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Führt einen vollständigen Audit-Lauf über den gebauten public/-Ordner und
 * die Hugo-Quellen aus. Reihenfolge: Dateien sammeln → URL-Verzeichnis bauen →
 * jede HTML-Seite einmal parsen und prüfen → seitenübergreifende Prüfungen →
 * Datei- und Quellprüfungen → Bericht zusammensetzen.
 *
 * Bewusst synchron und ohne requestübergreifenden Zustand: Eine Instanz lebt
 * nur für die Dauer eines Laufs.
 */
final class AuditRunner
{
    /** Verzeichnisse im public/, die zur HugoCMS-Installation gehören. */
    private const array EXCLUDED_PREFIXES = ['edit/', 'cms-api/', 'cms-admin/'];

    /** Obergrenze für die Anzahl Funde (Schutz vor Speicherüberlauf). */
    private const int MAX_ISSUES = 20000;

    /**
     * Cache je Content-Verzeichnis: normalisierter Dateiname → Quellpfad. Für
     * den unscharfen Rückabgleich URL → Quelldatei (siehe guessSource).
     *
     * @var array<string, array<string, string>>
     */
    private array $dirIndex = [];

    public function __construct(
        private readonly string $publicDir,
        private readonly string $sourceDir,
        private readonly string $contentDir = 'content',
    ) {
    }

    /**
     * Führt den Lauf aus und liefert den vollständigen Bericht als Array.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $start = hrtime(true);

        $allFiles = $this->collectFiles();
        $map = new UrlMap($allFiles);

        /** @var list<PageRecord> $pages */
        $pages = [];
        /** @var list<Issue> $issues */
        $issues = [];
        $truncated = false;

        foreach ($allFiles as $rel) {
            if (!self::isHtml($rel) || self::isExcluded($rel)) {
                continue;
            }
            $url = self::urlForFile($rel);
            $source = $this->guessSource($url);
            $html = (string) file_get_contents($this->publicDir . '/' . $rel);
            $page = HtmlInspector::inspect($html, $url, $rel, $source);
            $pages[] = $page;

            foreach (Checks::page($page, $map) as $issue) {
                $issues[] = $issue;
                if (count($issues) >= self::MAX_ISSUES) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        if (!$truncated) {
            foreach (Checks::site($pages, $map) as $issue) {
                $issues[] = $issue;
            }
            foreach (Checks::files($this->publicDir) as $issue) {
                $issues[] = $issue;
            }
            foreach (Checks::hugoSource($this->sourceDir . '/' . $this->contentDir, $this->contentDir) as $issue) {
                $issues[] = $issue;
            }
        }

        $seconds = round((hrtime(true) - $start) / 1e9, 2);

        return $this->buildReport($pages, $issues, $seconds, $truncated);
    }

    // --- Berichtsaufbau ----------------------------------------------------

    /**
     * @param list<PageRecord> $pages
     * @param list<Issue>      $issues
     * @return array<string, mixed>
     */
    private function buildReport(array $pages, array $issues, float $seconds, bool $truncated): array
    {
        $summary = ['error' => 0, 'warning' => 0, 'hint' => 0];
        $byCategory = [];
        $serialized = [];

        foreach ($issues as $issue) {
            $summary[$issue->severity] = ($summary[$issue->severity] ?? 0) + 1;
            $cat = $issue->category;
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['error' => 0, 'warning' => 0, 'hint' => 0, 'total' => 0];
            }
            $byCategory[$cat][$issue->severity]++;
            $byCategory[$cat]['total']++;
            $serialized[] = $issue->toArray();
        }

        return [
            'startedAt' => gmdate('c'),
            'seconds' => $seconds,
            'pagesScanned' => count($pages),
            'rulesApplied' => RuleCatalog::count(),
            'truncated' => $truncated,
            'summary' => $summary,
            'byCategory' => $byCategory === [] ? new \stdClass() : $byCategory,
            'issues' => $serialized,
        ];
    }

    // --- Dateisammlung / URL-Ableitung -------------------------------------

    /**
     * Alle Dateien unterhalb von public/ als Pfade relativ zu public/.
     *
     * @return list<string>
     */
    private function collectFiles(): array
    {
        if (!is_dir($this->publicDir)) {
            return [];
        }
        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->publicDir, \FilesystemIterator::SKIP_DOTS),
        );
        $prefixLen = strlen($this->publicDir) + 1;
        foreach ($iter as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), $prefixLen));
            }
        }
        sort($files);

        return $files;
    }

    private static function isHtml(string $rel): bool
    {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

        return $ext === 'html' || $ext === 'htm';
    }

    private static function isExcluded(string $rel): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Leitet aus einem public-relativen Dateipfad den Request-Pfad ab. */
    private static function urlForFile(string $rel): string
    {
        if ($rel === 'index.html') {
            return '/';
        }
        if (str_ends_with($rel, '/index.html')) {
            return '/' . substr($rel, 0, -strlen('index.html'));
        }

        return '/' . $rel;
    }

    /**
     * Best-effort-Zuordnung einer URL zur Hugo-Quelldatei (relativ zum Projekt).
     * Liefert den ersten existierenden Kandidaten oder null.
     */
    private function guessSource(string $url): ?string
    {
        $c = $this->contentDir;
        $path = trim($url, '/');
        if ($path === '') {
            return $this->firstExisting([$c . '/_index.md']);
        }
        if (str_ends_with($path, '.html')) {
            $path = substr($path, 0, -strlen('.html'));
        }

        $direct = $this->firstExisting([
            $c . '/' . $path . '.md',
            $c . '/' . $path . '/index.md',
            $c . '/' . $path . '/_index.md',
        ]);
        if ($direct !== null) {
            return $direct;
        }

        // Fallback: Hugo normalisiert Dateinamen für die URL (entfernt z. B.
        // Kommas: "1,9l" → "19l"), was sich nicht 1:1 zurückrechnen lässt. Daher
        // im Content-Verzeichnis der Sektion die Datei suchen, deren
        // normalisierter Name dem letzten URL-Segment entspricht.
        $slash = strrpos($path, '/');
        $section = $slash === false ? '' : substr($path, 0, $slash);
        $segment = $slash === false ? $path : substr($path, $slash + 1);
        $dirRel = $section === '' ? $c : $c . '/' . $section;

        return $this->sectionIndex($dirRel)[self::slugKey($segment)] ?? null;
    }

    /**
     * Baut (gecacht) den Index eines Content-Verzeichnisses: normalisierter
     * Name → Quellpfad (relativ zum Projekt). Erfasst flache .md-Dateien und
     * Ordner-Bundles (index.md/_index.md).
     *
     * @return array<string, string>
     */
    private function sectionIndex(string $dirRel): array
    {
        if (isset($this->dirIndex[$dirRel])) {
            return $this->dirIndex[$dirRel];
        }
        $index = [];
        $abs = $this->sourceDir . '/' . $dirRel;
        if (is_dir($abs)) {
            foreach (scandir($abs) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $abs . '/' . $entry;
                if (is_file($full) && str_ends_with($entry, '.md') && $entry !== '_index.md' && $entry !== 'index.md') {
                    $index[self::slugKey(substr($entry, 0, -3))] ??= $dirRel . '/' . $entry;
                } elseif (is_dir($full)) {
                    foreach (['index.md', '_index.md'] as $bundle) {
                        if (is_file($full . '/' . $bundle)) {
                            $index[self::slugKey($entry)] ??= $dirRel . '/' . $entry . '/' . $bundle;
                            break;
                        }
                    }
                }
            }
        }

        return $this->dirIndex[$dirRel] = $index;
    }

    /** Normalisiert einen Namen für den unscharfen Abgleich (nur a-z0-9). */
    private static function slugKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    }

    /**
     * @param list<string> $candidates Pfade relativ zum Projekt
     */
    private function firstExisting(array $candidates): ?string
    {
        foreach ($candidates as $rel) {
            if (is_file($this->sourceDir . '/' . $rel)) {
                return $rel;
            }
        }

        return null;
    }
}
