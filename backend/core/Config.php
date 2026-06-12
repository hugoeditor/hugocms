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
     *   session: array{path: string},
     *   log: array{file: string, level: string}
     * }
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-NOT-READABLE', [$configPath]);
        }

        $raw = @parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if (!is_array($raw)) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-INVALID-INI', [$configPath]);
        }

        $baseDir = dirname($configPath);

        $auth = (isset($raw['auth']) && is_array($raw['auth'])) ? $raw['auth'] : [];

        // Vollständigkeit prüfen: Alle Pflichtfelder müssen gesetzt und nicht
        // leer sein. Die treiberabhängigen Auth-Felder (etwa username,
        // password_hash bei singleuser) prüft die AuthFactory. Fehlende Felder
        // werden gesammelt und gemeinsam gemeldet, damit nicht bei jedem Start
        // nur das nächste fehlende Feld auftaucht.
        $missing = [];
        if (self::isBlank($auth['driver'] ?? null)) {
            $missing[] = '[auth] driver';
        }
        if (self::isBlank($raw['session']['path'] ?? null)) {
            $missing[] = '[session] path';
        }
        if (self::isBlank($raw['log']['file'] ?? null)) {
            $missing[] = '[log] file';
        }
        if (self::isBlank($raw['log']['level'] ?? null)) {
            $missing[] = '[log] level';
        }
        if ($missing !== []) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-INCOMPLETE', [$configPath, implode(', ', $missing)]);
        }

        return [
            'auth' => $auth,
            'session' => [
                'path' => self::resolvePath($raw['session']['path'], $baseDir),
            ],
            'log' => [
                'file' => self::resolvePath($raw['log']['file'], $baseDir),
                'level' => trim((string) $raw['log']['level']),
            ],
        ];
    }

    /** Prüft, ob ein Wert fehlt oder nach dem Trimmen leer ist. */
    private static function isBlank(mixed $value): bool
    {
        return trim((string) ($value ?? '')) === '';
    }

    /** Trimmt einen (geprüft nicht leeren) Pfad und löst ihn relativ zum baseDir auf. */
    private static function resolvePath(mixed $value, string $baseDir): string
    {
        $path = trim((string) $value);
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }
}
