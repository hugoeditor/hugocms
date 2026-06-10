<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Liest die Hauptkonfiguration (hugocms.ini): Authentifizierung, Verzeichnis
 * für die Sitzungsdateien und Logging. INI mit Sektionen; relative Pfade
 * gelten relativ zum Verzeichnis der Konfigurationsdatei.
 *
 *   [auth]
 *   driver = singleuser
 *   username = admin
 *   password_hash = "$2y$10$..."
 *
 *   [session]
 *   path = var/sessions
 *
 *   [log]
 *   file = log/hugocms.log
 *   level = error      ; debug | info | warning | error
 */
final class Config
{
    /**
     * @return array{
     *   auth: array<string, mixed>,
     *   session: array{path: ?string},
     *   log: array{file: ?string, level: ?string}
     * }
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new ApiException("Konfiguration nicht lesbar: {$configPath}", 'ECONFIG', 500);
        }

        $raw = @parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if (!is_array($raw)) {
            throw new ApiException("Konfiguration ist kein gültiges INI: {$configPath}", 'ECONFIG', 500);
        }

        $baseDir = dirname($configPath);

        $auth = (isset($raw['auth']) && is_array($raw['auth'])) ? $raw['auth'] : [];

        return [
            'auth' => $auth,
            'session' => [
                'path' => self::optionalPath($raw['session']['path'] ?? null, $baseDir),
            ],
            'log' => [
                'file' => self::optionalPath($raw['log']['file'] ?? null, $baseDir),
                'level' => isset($raw['log']['level']) ? (string) $raw['log']['level'] : null,
            ],
        ];
    }

    /** Trimmt einen optionalen Pfad und löst ihn relativ zum baseDir auf. */
    private static function optionalPath(mixed $value, string $baseDir): ?string
    {
        $path = trim((string) ($value ?? ''));
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }
}
