<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Auth\AuthInterface;
use HugoCMS\FileManager\Auth\MultiUser;
use HugoCMS\FileManager\Auth\SingleUser;
use HugoCMS\FileManager\Auth\UserAdminInterface;
use HugoCMS\FileManager\Auth\UserStore;
use HugoCMS\FileManager\Exception\ApiException;

/**
 * Erzeugt eine AuthInterface-Implementierung anhand der [auth]-Sektion der
 * Konfiguration. Der Schlüssel "driver" wählt das Verfahren.
 *
 * Eingebaut: "singleuser" (Felder: username, password_hash). Weitere
 * Implementierungen lassen sich registrieren — auch über die Connector-Option
 * "authDrivers":
 *
 *   $factory->register('ldap', fn (array $cfg) => new LdapAuth($cfg['host']));
 *
 * Der Treiber erhält die gesamte [auth]-Sektion als Array und liefert ein
 * AuthInterface zurück.
 */
final class AuthFactory
{
    /** @var array<string, callable(array<string, mixed>): AuthInterface> */
    private array $drivers = [];

    public function __construct()
    {
        $this->register('singleuser', static function (array $cfg, ?string $configPath = null, array $options = []): AuthInterface {
            $username = isset($cfg['username']) ? (string) $cfg['username'] : '';
            $hash = isset($cfg['password_hash']) ? (string) $cfg['password_hash'] : '';
            if ($username === '' || $hash === '') {
                throw new ApiException('ECONFIG', 500, 'AUTH-SINGLEUSER-REQUIRED');
            }

            // Sitzungsdauer aus den globalen [user]-Einstellungen (Sekunden);
            // ohne Angabe gilt der Standard im SingleUser-Konstruktor.
            $lifetime = isset($options['sessionLifetime']) ? (int) $options['sessionLifetime'] : null;

            // configPath ermöglicht das Ändern der Anmeldedaten (Persistenz in
            // der hugocms.ini). Fehlt er, ist die Änderung deaktiviert.
            return $lifetime !== null
                ? new SingleUser($username, $hash, $configPath, $lifetime)
                : new SingleUser($username, $hash, $configPath);
        });

        $this->register('multiuser', static function (array $cfg, ?string $configPath = null, array $options = []): AuthInterface {
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

    /**
     * Registriert (oder ersetzt) einen Treiber.
     *
     * @param callable(array<string, mixed>, ?string, array<string, mixed>): AuthInterface $factory
     */
    public function register(string $driver, callable $factory): void
    {
        $this->drivers[strtolower($driver)] = $factory;
    }

    /**
     * Erzeugt die AuthInterface-Instanz für die gegebene [auth]-Sektion.
     * $configPath (Pfad zur hugocms.ini) wird an den Treiber durchgereicht,
     * damit dieser bei Bedarf seine Anmeldedaten dort persistieren kann.
     * $options enthält globale Einstellungen aus [user] (z. B. sessionLifetime).
     *
     * @param array<string, mixed> $authConfig
     * @param array<string, mixed> $options
     */
    public function create(array $authConfig, ?string $configPath = null, array $options = []): AuthInterface
    {
        $driver = strtolower(trim((string) ($authConfig['driver'] ?? 'singleuser')));
        if ($driver === '') {
            $driver = 'singleuser';
        }
        if (!isset($this->drivers[$driver])) {
            throw new ApiException('ECONFIG', 500, 'AUTH-DRIVER-UNKNOWN', [$driver]);
        }

        $auth = ($this->drivers[$driver])($authConfig, $configPath, $options);
        if (!$auth instanceof AuthInterface) {
            throw new ApiException('ECONFIG', 500, 'AUTH-DRIVER-INVALID', [$driver]);
        }

        return $auth;
    }
}
