<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Auth\AuthInterface;
use HugoCMS\FileManager\Auth\SingleUser;
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
        $this->register('singleuser', static function (array $cfg, ?string $configPath = null): AuthInterface {
            $username = isset($cfg['username']) ? (string) $cfg['username'] : '';
            $hash = isset($cfg['password_hash']) ? (string) $cfg['password_hash'] : '';
            if ($username === '' || $hash === '') {
                throw new ApiException('ECONFIG', 500, 'AUTH-SINGLEUSER-REQUIRED');
            }

            // configPath ermöglicht das Ändern der Anmeldedaten (Persistenz in
            // der hugocms.ini). Fehlt er, ist die Änderung deaktiviert.
            return new SingleUser($username, $hash, $configPath);
        });
    }

    /**
     * Registriert (oder ersetzt) einen Treiber.
     *
     * @param callable(array<string, mixed>): AuthInterface $factory
     */
    public function register(string $driver, callable $factory): void
    {
        $this->drivers[strtolower($driver)] = $factory;
    }

    /**
     * Erzeugt die AuthInterface-Instanz für die gegebene [auth]-Sektion.
     * $configPath (Pfad zur hugocms.ini) wird an den Treiber durchgereicht,
     * damit dieser bei Bedarf seine Anmeldedaten dort persistieren kann.
     *
     * @param array<string, mixed> $authConfig
     */
    public function create(array $authConfig, ?string $configPath = null): AuthInterface
    {
        $driver = strtolower(trim((string) ($authConfig['driver'] ?? 'singleuser')));
        if ($driver === '') {
            $driver = 'singleuser';
        }
        if (!isset($this->drivers[$driver])) {
            throw new ApiException('ECONFIG', 500, 'AUTH-DRIVER-UNKNOWN', [$driver]);
        }

        $auth = ($this->drivers[$driver])($authConfig, $configPath);
        if (!$auth instanceof AuthInterface) {
            throw new ApiException('ECONFIG', 500, 'AUTH-DRIVER-INVALID', [$driver]);
        }

        return $auth;
    }
}
