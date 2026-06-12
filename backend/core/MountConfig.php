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
 * Reservierte Sektion [hugo] (kein Mount): konfiguriert den Hugo-Aufruf
 * (Befehl "build") für diese Webseite:
 *   bin         (Pflicht) Pfad zum Hugo-Programm.
 *   source      (Pflicht) Hugo-Projektverzeichnis.
 *   destination (optional) Zielverzeichnis, Standard: <source>/public.
 *   minify      (optional) true = Hugo mit --minify aufrufen.
 *   clean       (optional) true = --cleanDestinationDir. VORSICHT: entfernt
 *               im Ziel alles Nicht-Generierte — auch eine dort liegende
 *               Installation (edit/, cms-api/). Standard: false.
 */
final class MountConfig
{
    /** Sektionsname, der NICHT als Mount interpretiert wird. */
    private const HUGO_SECTION = 'hugo';

    /**
     * @return array{
     *   mounts: list<array{name: string, path: string, options: array}>,
     *   hugo: ?array{bin: string, source: string, destination: string, minify: bool}
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

        foreach ($raw as $name => $section) {
            if (!is_array($section)) {
                throw new ApiException('ECONFIG', 500, 'MOUNTS-ENTRY-OUTSIDE-SECTION', [(string) $name]);
            }

            if (strtolower((string) $name) === self::HUGO_SECTION) {
                $hugo = self::hugoSection($section, $baseDir);
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

        return ['mounts' => $mounts, 'hugo' => $hugo];
    }

    /**
     * Prüft die [hugo]-Sektion und löst die Pfade auf.
     *
     * @return array{bin: string, source: string, destination: string, minify: bool}
     */
    private static function hugoSection(array $section, string $baseDir): array
    {
        $bin = trim((string) ($section['bin'] ?? ''));
        $source = trim((string) ($section['source'] ?? ''));
        if ($bin === '' || $source === '') {
            throw new ApiException('ECONFIG', 500, 'HUGO-CONFIG-INCOMPLETE');
        }
        $destination = trim((string) ($section['destination'] ?? ''));

        $bin = self::resolve($bin, $baseDir);
        $source = self::resolve($source, $baseDir);
        $destination = $destination === ''
            ? $source . '/public'
            : self::resolve($destination, $baseDir);

        return [
            'bin' => $bin,
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
