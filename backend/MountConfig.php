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
 */
final class MountConfig
{
    /**
     * @return list<array{name: string, path: string, options: array}>
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new ApiException("Mount-Konfiguration nicht lesbar: {$configPath}", 'ECONFIG', 500);
        }

        $raw = @parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if (!is_array($raw)) {
            throw new ApiException("Mount-Konfiguration ist kein gültiges INI: {$configPath}", 'ECONFIG', 500);
        }

        $baseDir = dirname($configPath);
        $mounts = [];

        foreach ($raw as $name => $section) {
            if (!is_array($section)) {
                throw new ApiException(
                    "Mount-Konfiguration: Eintrag \"{$name}\" steht außerhalb einer [Sektion].",
                    'ECONFIG',
                    500,
                );
            }

            $path = isset($section['path']) ? trim((string) $section['path']) : '';
            if ($path === '') {
                throw new ApiException("Mount \"{$name}\": Pflichtfeld \"path\" fehlt.", 'ECONFIG', 500);
            }
            if (!self::isAbsolute($path)) {
                $path = $baseDir . '/' . $path;
            }

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
            throw new ApiException("Mount-Konfiguration enthält keine [Sektion]: {$configPath}", 'ECONFIG', 500);
        }

        return $mounts;
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
