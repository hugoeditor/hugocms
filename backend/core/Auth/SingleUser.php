<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Anmeldung mit genau einem Benutzer. Das Passwort wird als Hash
 * (password_hash) erwartet, niemals im Klartext.
 *
 *   $auth = new SingleUser('admin', password_hash('geheim', PASSWORD_DEFAULT));
 *
 * Der Einzelbenutzer besitzt alle globalen Rechte; die tatsächliche
 * Begrenzung ergibt sich aus den Berechtigungen je Mount.
 */
final class SingleUser implements AuthInterface
{
    private const SESSION_KEY = 'hugocms_fm_user';

    /** Name des Sitzungs-Cookies (statt des Standards PHPSESSID). */
    private const SESSION_NAME = 'HUGOCMS';

    public function __construct(
        private readonly string $username,
        private readonly string $passwordHash,
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
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }
}
