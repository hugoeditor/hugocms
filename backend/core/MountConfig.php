<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Liest Mount-Definitionen aus einer INI-Konfigurationsdatei. Format: je
 * [Sektion] ein Mount, der Sektionsname ist die interne ID. Beispiel:
 *
 *   [inhalte]
 *   path = daten/inhalte
 *   label = Inhalte
 *   accept = md, markdown, html, png, jpg
 *
 *   [vorlagen]
 *   path = daten/vorlagen
 *   permissions = read, write
 *
 * Felder je Sektion:
 *   path        (Pflicht) Verzeichnis. Relative Pfade gelten relativ zum
 *               Verzeichnis der Konfigurationsdatei.
 *   label       (optional) Anzeigename, Standard: Sektionsname.
 *   permissions (optional) Kommaliste; fehlt sie, gelten alle Rechte.
 *   accept      (optional) Kommaliste erlaubter Endungen (ohne Punkt).
 *   readonly    (optional) true = nur Lesen.
 *
 * Reservierte Sektion [hugo] (kein Mount): konfiguriert den webseiten-
 * spezifischen Teil des Hugo-Aufrufs (Befehl "build"). Das Hugo-PROGRAMM
 * selbst (bin) steht zentral in der hugocms.ini — es gibt nur eines.
 *   source      (Pflicht) Hugo-Projektverzeichnis.
 *   destination (optional) Zielverzeichnis, Standard: <source>/public.
 *   minify      (optional) true = Hugo mit --minify aufrufen.
 *   clean       (optional) true = --cleanDestinationDir. VORSICHT: entfernt
 *               im Ziel alles Nicht-Generierte — auch eine dort liegende
 *               Installation (edit/, cms-api/). Standard: false.
 *
 * Reservierte Sektion [seo_report] (kein Mount): Ausschlüsse des SEO-Berichts,
 * die NUR für diese Webseite gelten. Sie ERGÄNZEN die fest verdrahteten
 * Ausschlüsse und die globale [seo_report]-Sektion der hugocms.ini (siehe
 * {@see Config}) — nichts wird dadurch wieder eingeschlossen.
 *   exclude_prefixes (optional) Kommaliste public-relativer Verzeichnis-Präfixe.
 *   exclude_files    (optional) Kommaliste einzelner public-relativer Dateien.
 */
final class MountConfig
{
    /** Sektionsnamen, die NICHT als Mount interpretiert werden. */
    private const HUGO_SECTION = 'hugo';
    private const LICENSE_SECTION = 'license';
    private const PAGESPEED_SECTION = 'pagespeed';
    private const LIVE_ANALYSIS_SECTION = 'live_analysis';
    private const SEO_REPORT_SECTION = 'seo_report';

    /**
     * @return array{
     *   mounts: list<array{name: string, path: string, options: array}>,
     *   hugo: ?array{source: string, destination: string, minify: bool, clean: bool},
     *   license: ?string,
     *   pagespeed: ?string,
     *   liveAnalysis: ?string,
     *   seoReport: array{excludePrefixes: list<string>, excludeFiles: list<string>},
     *   warnings: list<array{key: string, params: list<mixed>}>
     * }
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new ApiException('ECONFIG', 500, 'MOUNTS-NOT-READABLE', [$configPath]);
        }

        $raw = @parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if (!is_array($raw)) {
            throw new ApiException('ECONFIG', 500, 'MOUNTS-INVALID-INI', [$configPath]);
        }

        $baseDir = dirname($configPath);
        $mounts = [];
        $hugo = null;
        $license = null;
        $pagespeed = null;
        $liveAnalysis = null;
        $seoReport = ['excludePrefixes' => [], 'excludeFiles' => []];
        $warnings = [];

        foreach ($raw as $name => $section) {
            if (!is_array($section)) {
                throw new ApiException('ECONFIG', 500, 'MOUNTS-ENTRY-OUTSIDE-SECTION', [(string) $name]);
            }

            if (strtolower((string) $name) === self::HUGO_SECTION) {
                $hugo = self::hugoSection($section, $baseDir);
                // Vorhandene, aber unvollständige [hugo]-Sektion bricht die Site
                // NICHT ab — sie ist dann lediglich nicht veröffentlichbar
                // (buildable=false). Ein Hinweis macht den Tippfehler sichtbar.
                if ($hugo === null) {
                    $warnings[] = ['key' => 'HUGO-CONFIG-INCOMPLETE', 'params' => [$configPath]];
                }
                continue;
            }

            // Pro-Lizenz dieser Webseite (optional). Nur der rohe Schlüssel;
            // geprüft (Signatur, Domain-Bindung) wird er von {@see License}.
            if (strtolower((string) $name) === self::LICENSE_SECTION) {
                $key = trim((string) ($section['key'] ?? ''));
                $license = $key === '' ? null : $key;
                continue;
            }

            // PageSpeed-Check dieser Webseite (optional): die zu messende
            // öffentliche Live-Adresse. Pro Projekt, daher hier statt zentral in
            // der hugocms.ini. Wird über das PageSpeed-Panel gesetzt und beim
            // Messstart geschrieben.
            if (strtolower((string) $name) === self::PAGESPEED_SECTION) {
                $url = trim((string) ($section['url'] ?? ''));
                $pagespeed = $url === '' ? null : $url;
                continue;
            }

            // Live-Analyse dieser Webseite (optional): die zu prüfende öffentliche
            // Live-Adresse. Eigene Sektion, unabhängig von [pagespeed] — beide
            // Prüfungen teilen keinen Zustand. Wird über das Live-Analyse-Panel
            // gesetzt und beim Start geschrieben.
            if (strtolower((string) $name) === self::LIVE_ANALYSIS_SECTION) {
                $url = trim((string) ($section['url'] ?? ''));
                $liveAnalysis = $url === '' ? null : $url;
                continue;
            }

            // Zusätzliche Ausschlüsse des SEO-Berichts NUR für diese Webseite
            // (optional). Dieselbe Schreibweise und Normalisierung wie die
            // globale Sektion der hugocms.ini — der Connector legt beide
            // Listen zusammen. Ausgeschlossen bleibt ausgeschlossen: Diese
            // Sektion kann global Ausgeschlossenes nicht zurückholen.
            if (strtolower((string) $name) === self::SEO_REPORT_SECTION) {
                $seoReport = [
                    'excludePrefixes' => Config::normalizeExcludePrefixes((string) ($section['exclude_prefixes'] ?? '')),
                    'excludeFiles' => Config::normalizeExcludeFiles((string) ($section['exclude_files'] ?? '')),
                ];
                continue;
            }

            $path = isset($section['path']) ? trim((string) $section['path']) : '';
            if ($path === '') {
                throw new ApiException('ECONFIG', 500, 'MOUNTS-PATH-REQUIRED', [(string) $name]);
            }
            $path = self::resolve($path, $baseDir);

            $options = [];
            if (isset($section['label'])) {
                $options['label'] = (string) $section['label'];
            }
            if (isset($section['permissions'])) {
                $options['permissions'] = self::toList($section['permissions']);
            }
            if (isset($section['accept'])) {
                $options['accept'] = self::toList($section['accept']);
            }
            if (isset($section['readonly'])) {
                $options['readonly'] = (bool) $section['readonly'];
            }

            $mounts[] = ['name' => (string) $name, 'path' => $path, 'options' => $options];
        }

        if ($mounts === []) {
            throw new ApiException('ECONFIG', 500, 'MOUNTS-NO-SECTION', [$configPath]);
        }

        return [
            'mounts' => $mounts,
            'hugo' => $hugo,
            'license' => $license,
            'pagespeed' => $pagespeed,
            'liveAnalysis' => $liveAnalysis,
            'seoReport' => $seoReport,
            'warnings' => $warnings,
        ];
    }

    /**
     * Prüft die [hugo]-Sektion und löst die Pfade auf. Fehlt das Pflichtfeld
     * „source“, gilt die Sektion als unvollständig: Rückgabe null — die Site
     * bleibt nutzbar, nur „build“ steht nicht zur Verfügung. Das Hugo-Programm
     * (bin) wird hier NICHT gelesen; es steht zentral in der hugocms.ini.
     *
     * @return ?array{source: string, destination: string, minify: bool, clean: bool}
     */
    private static function hugoSection(array $section, string $baseDir): ?array
    {
        $source = trim((string) ($section['source'] ?? ''));
        if ($source === '') {
            return null;
        }
        $destination = trim((string) ($section['destination'] ?? ''));

        $source = self::resolve($source, $baseDir);
        $destination = $destination === ''
            ? $source . '/public'
            : self::resolve($destination, $baseDir);

        return [
            'source' => $source,
            'destination' => $destination,
            'minify' => (bool) ($section['minify'] ?? false),
            'clean' => (bool) ($section['clean'] ?? false),
        ];
    }

    /** Löst einen Pfad relativ zum Verzeichnis der Konfigurationsdatei auf. */
    private static function resolve(string $path, string $baseDir): string
    {
        return self::isAbsolute($path) ? $path : $baseDir . '/' . $path;
    }

    /** Zerlegt eine kommagetrennte Liste in getrimmte, nicht-leere Werte. */
    private static function toList(mixed $value): array
    {
        $parts = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')                      // Unix
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;   // Windows
    }
}
