<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Rückabgleich einer gebauten Seite auf ihre Hugo-Quelldatei — best effort.
 *
 * Hugo kennt den Weg Quelle → URL, den umgekehrten gibt es nicht: Dateinamen
 * werden für die Adresse normalisiert (Kommas fallen weg, „1,9l" wird zu „19l"),
 * Ordner-Bundles verstecken ihre index.md, und Sektionen haben _index.md. Diese
 * Klasse rät deshalb in zwei Stufen: erst die direkten Kandidaten, dann ein
 * unscharfer Abgleich über den normalisierten Dateinamen im Sektionsverzeichnis.
 *
 * Herausgelöst aus dem {@see AuditRunner}, damit die Hyperlink-Suche
 * ({@see \HugoCMS\FileManager\LinkScanner}) zu einer Fundstelle im gebauten
 * Ordner dieselbe Quelldatei anbietet wie ein SEO-Fund — eine Logik, ein
 * Ergebnis.
 */
final class SourceGuesser
{
    /**
     * Cache je Content-Verzeichnis: normalisierter Dateiname → Quellpfad.
     *
     * @var array<string, array<string, string>>
     */
    private array $dirIndex = [];

    /**
     * @param string $sourceDir  absoluter Pfad der Projektwurzel
     * @param string $contentDir Name des Content-Ordners darin (aus der
     *                           Hugo-Konfiguration, Standard „content")
     */
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $contentDir = 'content',
    ) {
    }

    /** Leitet aus einem public-relativen Dateipfad den Request-Pfad ab. */
    public static function urlForFile(string $rel): string
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
     * Quelldatei (relativ zum Projekt) einer gebauten Datei, oder null. Der
     * Pfad ist public-relativ, also OHNE den Namen des gebauten Ordners.
     */
    public function forFile(string $relInPublic): ?string
    {
        return $this->forUrl(self::urlForFile($relInPublic));
    }

    /**
     * Best-effort-Zuordnung einer URL zur Hugo-Quelldatei (relativ zum Projekt).
     * Liefert den ersten existierenden Kandidaten oder null.
     */
    public function forUrl(string $url): ?string
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
