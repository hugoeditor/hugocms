<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

use HugoCMS\FileManager\Config;
use HugoCMS\FileManager\Exception\ApiException;

/**
 * Anmeldung mit genau einem Benutzer. Das Passwort wird als Hash
 * (password_hash) erwartet, niemals im Klartext.
 *
 *   $auth = new SingleUser('admin', password_hash('geheim', PASSWORD_DEFAULT));
 *
 * Der Einzelbenutzer besitzt alle globalen Rechte; die tatsächliche
 * Begrenzung ergibt sich aus den Berechtigungen je Mount.
 *
 * Ist der Pfad zur hugocms.ini bekannt ($configPath), kann der Benutzer seine
 * Anmeldedaten ändern; die Persistenz erfolgt über die [auth]-Sektion dieser
 * Datei. Ohne Pfad (z. B. programmatisch via custom.php) ist das nicht möglich.
 */
final class SingleUser implements AuthInterface
{
    private const SESSION_KEY = 'hugocms_fm_user';

    /** Zeitstempel des letzten Zugriffs — Grundlage des Inaktivitäts-Limits. */
    private const LAST_SEEN_KEY = 'hugocms_fm_last_seen';

    /** Name des Sitzungs-Cookies (statt des Standards PHPSESSID). */
    private const SESSION_NAME = 'HUGOCMS';

    /** Standard-Sitzungsdauer in Sekunden, falls keine konfiguriert ist (8 Stunden). */
    private const DEFAULT_SESSION_LIFETIME = 28800;

    public function __construct(
        private readonly string $username,
        private readonly string $passwordHash,
        private readonly ?string $configPath = null,
        private readonly int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME,
    ) {
        $this->ensureSession();
    }

    public function attemptLogin(string $username, string $password): bool
    {
        if (!hash_equals($this->username, $username)) {
            return false;
        }
        if (!password_verify($password, $this->passwordHash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $this->username;

        return true;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    public function supportsCredentialChange(): bool
    {
        return $this->configPath !== null;
    }

    public function changeCredentials(?string $newUsername, ?string $newPassword): void
    {
        if ($this->configPath === null) {
            throw new ApiException('ECONFIG', 409, 'ACCOUNT-NOT-SUPPORTED');
        }
        $username = $newUsername ?? $this->username;
        $hash = $newPassword !== null
            ? password_hash($newPassword, PASSWORD_DEFAULT)
            : $this->passwordHash;

        // Nur die [auth]-Sektion neu schreiben; session/log/hugo bleiben über
        // Config::updateSections wörtlich erhalten.
        Config::updateSections($this->configPath, [
            'auth' => [
                'driver' => 'singleuser',
                'username' => $username,
                'password_hash' => $hash,
            ],
        ]);
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public function isAuthenticated(): bool
    {
        return ($_SESSION[self::SESSION_KEY] ?? null) === $this->username;
    }

    public function currentUser(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return [
            'name' => $this->username,
            'roles' => ['admin'],
            'permissions' => ['*'],
        ];
    }

    public function can(string $permission): bool
    {
        return $this->isAuthenticated();
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_name(self::SESSION_NAME);
            // Serverseitige Lebensdauer der Sitzungsdaten an die konfigurierte
            // Dauer koppeln (Best Effort). Die eigentliche Durchsetzung erfolgt
            // über den Inaktivitäts-Zeitstempel unten — unabhängig von der
            // Garbage Collection, die bei eigenem Sitzungsverzeichnis nicht
            // zuverlässig läuft. Das Cookie bleibt ein Sitzungs-Cookie.
            @ini_set('session.gc_maxlifetime', (string) $this->sessionLifetime);
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
            $this->enforceIdleTimeout();
        }
    }

    /**
     * Setzt die Sitzungsdauer als gleitendes Inaktivitäts-Limit durch: Liegt
     * der letzte Zugriff länger als die konfigurierte Dauer zurück, wird die
     * Sitzung verworfen (Abmeldung). Andernfalls wird der Zeitstempel auf den
     * aktuellen Zugriff aufgefrischt, sodass aktive Nutzung angemeldet bleibt.
     */
    private function enforceIdleTimeout(): void
    {
        $now = time();
        $last = $_SESSION[self::LAST_SEEN_KEY] ?? null;
        if (is_int($last) && $now - $last > $this->sessionLifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION[self::LAST_SEEN_KEY] = $now;
    }
}
