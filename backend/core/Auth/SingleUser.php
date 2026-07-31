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
    use SessionHandling;

    public function __construct(
        private readonly string $username,
        private readonly string $passwordHash,
        private readonly ?string $configPath = null,
        private readonly int $sessionLifetime = self::DEFAULT_SESSION_LIFETIME,
    ) {
        if ($this->startSession($this->sessionLifetime)) {
            $this->enforceIdleTimeout($this->sessionLifetime);
        }
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
        // Der Einzelbenutzer kann alles — auch Konten „verwalten" gibt es hier
        // nicht: Der Connector bietet die Verwaltungsbefehle nur an, wenn der
        // Treiber UserAdminInterface umsetzt.
        return $this->isAuthenticated();
    }

    public function supportsPreferences(): bool
    {
        return $this->configPath !== null;
    }

    public function loadPreferences(): array
    {
        if ($this->configPath === null) {
            return [];
        }

        return array_map('strval', Config::raw($this->configPath)['user'] ?? []);
    }

    public function savePreferences(array $changes): void
    {
        if ($this->configPath === null) {
            throw new ApiException('ECONFIG', 409, 'RECONFIGURE-UNAVAILABLE');
        }
        // updateSections ersetzt die ganze Sektion — deshalb roh lesen,
        // überlagern und vollständig zurückschreiben.
        $user = Config::raw($this->configPath)['user'] ?? [];
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($user[$key]);
            } else {
                $user[$key] = $value;
            }
        }
        Config::updateSections($this->configPath, ['user' => $user]);
    }
}
