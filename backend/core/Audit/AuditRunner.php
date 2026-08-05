<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Führt einen vollständigen Audit-Lauf über den gebauten public/-Ordner und
 * die Hugo-Quellen aus. Reihenfolge: Dateien sammeln → URL-Verzeichnis bauen →
 * erreichbare Seiten bestimmen (Sitemap + interne Verlinkung) → diese parsen
 * und prüfen → nicht erreichbare Seiten melden → seitenübergreifende Prüfungen
 * → Datei- und Quellprüfungen → Bericht zusammensetzen.
 *
 * Erreichbarkeit: Geprüft wird nur, was in der Sitemap steht oder von dort (und
 * der Startseite) aus intern verlinkt ist (transitiver Erreichbarkeitslauf).
 * Nicht ausgeschlossene HTML-Dateien, die so nicht erreichbar sind, ergeben
 * einen Fehler (page.unreferenced) — für Suchmaschinen unauffindbare Seiten.
 * Fehlt die Sitemap ganz, gelten alle HTML-Dateien als Wurzeln (Rückfall auf
 * das frühere Verhalten, keine Fehlerflut).
 *
 * Bewusst synchron und ohne requestübergreifenden Zustand: Eine Instanz lebt
 * nur für die Dauer eines Laufs.
 */
final class AuditRunner
{
    /**
     * Verzeichnisse im public/, die zur HugoCMS-Installation gehören und fest
     * verdrahtet ausgeschlossen bleiben. Über die [seo_report]-Sektion der
     * hugocms.ini lassen sich WEITERE Präfixe ergänzen (siehe Konstruktor).
     */
    private const array EXCLUDED_PREFIXES = ['edit/', 'cms-api/', 'cms-admin/'];

    /**
     * Papierkorb eines Mounts (siehe FileService::trash). Ein Mount darf überall
     * liegen — auch unterhalb von public/ oder auf static/, dessen Inhalt Hugo
     * unverändert nach public/ kopiert. Der Name wird deshalb als PFADSEGMENT in
     * beliebiger Tiefe ausgeschlossen, nicht als Präfix (siehe inTrash).
     */
    private const string TRASH_DIR = '.trash';

    /** Obergrenze für die Anzahl Funde (Schutz vor Speicherüberlauf). */
    private const int MAX_ISSUES = 20000;

    /**
     * Rückabgleich URL → Quelldatei. Liegt in einer eigenen Klasse, weil die
     * Hyperlink-Suche dieselbe Zuordnung braucht (siehe {@see SourceGuesser}).
     * Wird beim ersten Bedarf gebaut, da sie den Content-Ordner kennen muss.
     */
    private ?SourceGuesser $guesser = null;

    /**
     * Fest verdrahtete plus konfigurierte Ausschluss-Präfixe (public-relativ,
     * mit Schrägstrich am Ende). Reihenfolge/Duplikate sind unerheblich, da nur
     * per str_starts_with geprüft wird.
     *
     * @var list<string>
     */
    private readonly array $excludedPrefixes;

    /**
     * Einzelne ausgeschlossene Dateien (public-relativ) als Set: exakter Pfad →
     * true. Ergänzt die Präfixe um Dateien, die nicht per Verzeichnis erfasst
     * werden (z. B. Suchmaschinen-Bestätigungsdateien wie google….html).
     *
     * @var array<string, true>
     */
    private readonly array $excludedFiles;

    /**
     * @param list<string> $extraExcludedPrefixes Zusätzliche public-relative
     *        Präfixe aus der [seo_report]-Sektion (bereits normalisiert).
     * @param list<string> $excludedFiles Einzelne public-relative Dateien aus der
     *        [seo_report]-Sektion (bereits normalisiert).
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly string $sourceDir,
        private readonly string $contentDir = 'content',
        array $extraExcludedPrefixes = [],
        array $excludedFiles = [],
    ) {
        $this->excludedPrefixes = array_values(array_unique(
            [...self::EXCLUDED_PREFIXES, ...$extraExcludedPrefixes],
        ));
        $this->excludedFiles = array_fill_keys(array_values($excludedFiles), true);
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

        // Prüfbare Seiten: HTML, nicht ausgeschlossen. rel → Request-Pfad.
        /** @var array<string, string> $htmlFiles */
        $htmlFiles = [];
        foreach ($allFiles as $rel) {
            if (self::isHtml($rel) && !$this->isExcluded($rel)) {
                $htmlFiles[$rel] = SourceGuesser::urlForFile($rel);
            }
        }

        $reachable = $this->reachableFiles($htmlFiles, $map);

        /** @var list<PageRecord> $pages */
        $pages = [];
        /** @var list<Issue> $issues */
        $issues = [];
        $truncated = false;

        // Nur erreichbare Seiten prüfen — in sortierter Dateireihenfolge, damit
        // seitenübergreifende Prüfungen (Duplikate) stabil bleiben.
        foreach ($allFiles as $rel) {
            if (!isset($reachable[$rel])) {
                continue;
            }
            $page = $reachable[$rel];
            $pages[] = $page;

            foreach (Checks::page($page, $map) as $issue) {
                $issues[] = $issue;
                if (count($issues) >= self::MAX_ISSUES) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        // Nicht erreichbare, nicht ausgeschlossene HTML-Seiten: Fehler.
        if (!$truncated) {
            foreach ($allFiles as $rel) {
                if (!isset($htmlFiles[$rel]) || isset($reachable[$rel])) {
                    continue;
                }
                $issues[] = Issue::of('page.unreferenced', $htmlFiles[$rel], $this->guessSource($htmlFiles[$rel]));
                if (count($issues) >= self::MAX_ISSUES) {
                    $truncated = true;
                    break;
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

    /**
     * Bestimmt die erreichbaren Seiten per Breitensuche: Wurzeln sind die in
     * der Sitemap verzeichneten Seiten plus die Startseite; von dort wird jeder
     * interne Link verfolgt. Jede erreichte Seite wird EINMAL geparst und ihr
     * {@see PageRecord} für die spätere Prüfung mitgeliefert (kein zweites
     * Einlesen). Fehlt die Sitemap ganz, sind alle HTML-Dateien Wurzeln.
     *
     * @param array<string, string> $htmlFiles rel → Request-Pfad der prüfbaren Seiten
     * @return array<string, PageRecord> rel → geparste Seite (nur erreichbare)
     */
    private function reachableFiles(array $htmlFiles, UrlMap $map): array
    {
        // Wurzeln (als rel-Dateien) sammeln.
        $queue = [];
        if (!Sitemap::present($this->publicDir)) {
            // Rückfall: keine Sitemap → alle HTML-Dateien sind Wurzeln.
            $queue = array_keys($htmlFiles);
        } else {
            // Startseite immer als Wurzel, dann alle Sitemap-Einträge.
            foreach (['/' , ...Sitemap::paths($this->publicDir)] as $path) {
                $rel = $map->fileFor($path);
                if ($rel !== null && isset($htmlFiles[$rel])) {
                    $queue[] = $rel;
                }
            }
        }

        /** @var array<string, PageRecord> $reached */
        $reached = [];
        $seen = [];
        foreach ($queue as $rel) {
            $seen[$rel] = true;
        }
        while ($queue !== []) {
            $rel = array_shift($queue);
            if (isset($reached[$rel])) {
                continue;
            }
            $url = $htmlFiles[$rel];
            $page = $this->inspectFile($rel, $url);
            $reached[$rel] = $page;

            // Internen Links folgen — neue, prüfbare Ziele einreihen.
            foreach ($page->anchors as $a) {
                $target = UrlMap::internalTarget($url, $a['href']);
                if ($target === null) {
                    continue;
                }
                $next = $map->fileFor($target);
                if ($next !== null && isset($htmlFiles[$next]) && !isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $reached;
    }

    /** Liest und parst eine HTML-Seite zu ihrem {@see PageRecord}. */
    private function inspectFile(string $rel, string $url): PageRecord
    {
        $html = (string) file_get_contents($this->publicDir . '/' . $rel);

        return HtmlInspector::inspect($html, $url, $rel, $this->guessSource($url));
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

    private function isExcluded(string $rel): bool
    {
        if (isset($this->excludedFiles[$rel]) || self::inTrash($rel)) {
            return true;
        }
        foreach ($this->excludedPrefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liegt der Pfad im Papierkorb eines Mounts? Gelöschtes bleibt dort
     * wiederherstellbar liegen, gehört aber nicht mehr zur Webseite und darf
     * keine Funde erzeugen. Gilt für public-relative Pfade wie für Quellpfade
     * (siehe Checks::hugoSource); geprüft wird das Segment in jeder Tiefe.
     */
    public static function inTrash(string $rel): bool
    {
        return str_starts_with($rel, self::TRASH_DIR . '/')
            || str_contains($rel, '/' . self::TRASH_DIR . '/');
    }

    /**
     * Rückabgleich URL → Quelldatei. Die Zuordnung selbst steckt im
     * {@see SourceGuesser}; hier steht nur der gemeinsame Zugriff darauf, damit
     * der Verzeichnis-Cache über den ganzen Lauf hält.
     */
    private function guessSource(string $url): ?string
    {
        $this->guesser ??= new SourceGuesser($this->sourceDir, $this->contentDir);

        return $this->guesser->forUrl($url);
    }
}
