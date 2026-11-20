<?php
/*
 * HugoCMS Pro — dieser Bestandteil steht NICHT unter der AGPL.
 * Nutzung nur mit gültigem Pro-Schlüssel gemäß LICENSE-PRO im Wurzelverzeichnis.
 * SPDX-License-Identifier: LicenseRef-HugoCMS-Pro
 */

declare(strict_types=1);

namespace HugoCMS\FileManager\Pro\Auth;

use HugoCMS\FileManager\AuthFactory;
use HugoCMS\FileManager\Auth\AuthInterface;
use HugoCMS\FileManager\Auth\UserAdminInterface;
use HugoCMS\FileManager\Exception\ApiException;

/**
 * Meldet das Mehrbenutzer-Verfahren ("driver = multiuser") an der
 * {@see AuthFactory} an. Der Kern kennt nur den Einzelbenutzer; alles, was
 * Konten, Rollen und Webseiten-Zuordnung betrifft, ist Pro-Funktion und liegt
 * hier — die Fabrik ruft lediglich {@see register()}.
 */
final class MultiUserDriver
{
    public static function register(AuthFactory $factory): void
    {
        $factory->register('multiuser', static function (array $cfg, ?string $configPath = null, array $options = []): AuthInterface {
            // Die Konten liegen als eigene INI-Dateien neben der hugocms.ini
            // (Verzeichnis users/, wie mounts/). Ohne Konfigurationsdatei —
            // etwa bei programmatischem Aufbau über custom.php — gibt es keinen
            // Ablageort und damit kein Mehrbenutzer-Verfahren.
            if ($configPath === null) {
                throw new ApiException('ECONFIG', 500, 'AUTH-MULTIUSER-REQUIRES-CONFIG');
            }
            $dir = isset($cfg['users_dir']) && trim((string) $cfg['users_dir']) !== ''
                ? self::resolvePath((string) $cfg['users_dir'], $configPath)
                : dirname($configPath) . '/users';

            // Sitzungsdauer aus den globalen [user]-Einstellungen als Vorgabe;
            // je Konto lässt sie sich in dessen eigener Datei überschreiben.
            $lifetime = isset($options['sessionLifetime']) ? (int) $options['sessionLifetime'] : null;
            $store = new UserStore($dir);
            self::seedFirstAdmin($store, $cfg);

            return $lifetime !== null ? new MultiUser($store, $lifetime) : new MultiUser($store);
        });
    }

    /**
     * Nahtloser Übergang vom Einzel- zum Mehrbenutzer-Verfahren: Gibt es noch
     * kein einziges Konto, wird der bisherige Einzelbenutzer aus der
     * [auth]-Sektion (username + password_hash) zum ersten Administrator. Wer
     * umstellen will, ändert also nur `driver` — und meldet sich unverändert an.
     *
     * Der Hash wird übernommen, nicht neu gebildet; das Passwort selbst kennt
     * HugoCMS nicht. Ohne beide Felder bleibt es beim Fehler: Ein
     * Mehrbenutzer-Verfahren ohne ein einziges Konto sperrt jeden aus.
     *
     * @param array<string, mixed> $cfg [auth]-Sektion
     */
    private static function seedFirstAdmin(UserStore $store, array $cfg): void
    {
        if ($store->all() !== []) {
            return;
        }
        $username = isset($cfg['username']) ? trim((string) $cfg['username']) : '';
        $hash = isset($cfg['password_hash']) ? (string) $cfg['password_hash'] : '';
        if ($username === '' || $hash === '') {
            throw new ApiException('ECONFIG', 500, 'AUTH-MULTIUSER-NO-ACCOUNTS');
        }
        $store->write($username, $hash, UserAdminInterface::ROLE_ADMIN, [UserAdminInterface::ALL_SITES], false);
    }

    /** Relative Pfade gelten relativ zum Verzeichnis der Konfigurationsdatei. */
    private static function resolvePath(string $path, string $configPath): string
    {
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return rtrim($path, '/\\');
        }

        return rtrim(dirname($configPath) . '/' . $path, '/\\');
    }
}
