<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Anmeldung mit mehreren Konten (Pro-Funktion). Jedes Konto liegt als eigene
 * INI-Datei unter backend/users/ (siehe {@see UserStore}); die hugocms.ini
 * enthält für diesen Treiber nur noch `driver = multiuser`.
 *
 * Es gibt genau zwei Rollen — bewusst kein feingranulares Rechtesystem:
 *
 *   admin   darf alle Webseiten bedienen, Konten anlegen, löschen, sperren und
 *           fremde Passwörter neu setzen („Passwort vergessen").
 *   editor  arbeitet an den ihm zugewiesenen Webseiten (oder an allen, wenn die
 *           Zuordnung „*" lautet) und kann keine Konten verwalten.
 *
 * Was ein Konto auf einem Mount tun darf, entscheidet weiterhin ALLEIN die
 * Mount-Konfiguration (permissions/readonly) — die Rolle regelt nur, WELCHE
 * Webseiten sichtbar sind und wer Konten verwalten darf.
 *
 * Pro-Schranke: Ohne gültige Lizenz für die aufgerufene Webseite melden sich
 * nur Administratoren an. So sperrt eine abgelaufene oder fehlende Lizenz
 * niemanden endgültig aus — der Administrator kommt herein und kann die Lizenz
 * eintragen —, aber die eigentliche Mehrbenutzer-Nutzung ruht.
 */
final class MultiUser implements AuthInterface, UserAdminInterface, SiteAwareInterface
{
    use SessionHandling;

    /**
     * Vergleichshash für Anmeldeversuche mit unbekanntem Namen. Ohne ihn wäre an
     * der Antwortzeit ablesbar, welche Konten es gibt (password_verify kostet
     * spürbar Zeit, ein fehlender Datensatz nicht).
     */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore.HicjxvVw0FVKnKWCXqQEKGjKPRfW';

    /** Kennung der aufgerufenen Webseite; wird über bindSite nachgereicht. */
    private string $siteKey = '';

    /** @var ?callable():bool */
    private $isProProvider = null;

    /** Zwischengespeichertes Konto des angemeldeten Benutzers. */
    private ?array $current = null;

    private bool $currentLoaded = false;

    public function __construct(
        private readonly UserStore $store,
        private readonly int $defaultSessionLifetime = self::DEFAULT_SESSION_LIFETIME,
    ) {
        // Die Sitzung startet mit der globalen Dauer; sobald feststeht, WER
        // angemeldet ist, gilt dessen eigene Einstellung.
        if ($this->startSession($this->defaultSessionLifetime)) {
            $this->enforceIdleTimeout($this->sessionLifetimeOfSessionUser());
        }
    }

    public function bindSite(string $siteKey, callable $isPro): void
    {
        $this->siteKey = $siteKey;
        $this->isProProvider = $isPro;
    }

    public function attemptLogin(string $username, string $password): bool
    {
        $user = $this->store->load($username);
        if ($user === null) {
            // Gleiche Antwortzeit wie bei einem vorhandenen Konto.
            password_verify($password, self::DUMMY_HASH);

            return false;
        }
        if (!password_verify($password, $user['hash'])) {
            return false;
        }

        // Ab hier ist die Identität bewiesen. Wer scheitert, hat ein Recht auf
        // eine klare Auskunft — verraten wird dadurch nichts mehr.
        if ($user['disabled']) {
            throw new ApiException('EAUTH', 403, 'USER-DISABLED');
        }
        if (!UserStore::mayAccessSite($user, $this->siteKey)) {
            throw new ApiException('EAUTH', 403, 'USER-SITE-DENIED', [$this->siteKey]);
        }
        if ($user['role'] !== self::ROLE_ADMIN && !$this->isPro()) {
            throw new ApiException('ELICENSE', 403, 'MULTIUSER-REQUIRES-PRO');
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $user['name'];
        $this->current = $user;
        $this->currentLoaded = true;

        return true;
    }

    public function verifyPassword(string $password): bool
    {
        $user = $this->currentAccount();

        return $user !== null && password_verify($password, $user['hash']);
    }

    public function supportsCredentialChange(): bool
    {
        return true;
    }

    public function changeCredentials(?string $newUsername, ?string $newPassword): void
    {
        $user = $this->currentAccount();
        if ($user === null) {
            throw ApiException::unauthorized();
        }
        $name = $user['name'];

        if ($newPassword !== null) {
            $this->store->updateAccount($name, [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ]);
        }

        if ($newUsername !== null && UserStore::key($newUsername) !== UserStore::key($name)) {
            $newUsername = UserStore::normalizeName($newUsername);
            if ($this->store->exists($newUsername)) {
                throw new ApiException('ECONFLICT', 409, 'USER-EXISTS', [$newUsername]);
            }
            $this->store->rename($name, $newUsername);
            $_SESSION[self::SESSION_KEY] = $newUsername;
            $name = $newUsername;
        } elseif ($newUsername !== null) {
            // Nur die Schreibweise ändert sich.
            $this->store->rename($name, $newUsername);
            $_SESSION[self::SESSION_KEY] = $newUsername;
            $name = $newUsername;
        }

        $this->current = $this->store->load($name);
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
        $this->current = null;
        $this->currentLoaded = true;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentAccount() !== null;
    }

    public function currentUser(): ?array
    {
        $user = $this->currentAccount();
        if ($user === null) {
            return null;
        }

        return [
            'name' => $user['name'],
            'roles' => [$user['role']],
            // Der Mount entscheidet über Datei-Operationen; die Rolle nur über
            // die Kontenverwaltung.
            'permissions' => ['*'],
            'manageUsers' => $user['role'] === self::ROLE_ADMIN,
            'sites' => $user['sites'],
        ];
    }

    /**
     * Verwaltende Befugnisse — sie unterscheiden Administrator von Redakteur.
     * Alles Übrige (Dateien lesen, schreiben, veröffentlichen) steht jedem
     * angemeldeten Konto offen und wird über die Mount-Konfiguration begrenzt.
     */
    private const ADMIN_PERMISSIONS = [
        'users.manage',  // Konten anlegen, ändern, löschen, Passwörter setzen
        'config.manage', // hugocms.ini, Projekteinstellungen, Lizenz aktivieren
    ];

    public function can(string $permission): bool
    {
        $user = $this->currentAccount();
        if ($user === null) {
            return false;
        }
        if (in_array($permission, self::ADMIN_PERMISSIONS, true)) {
            return $user['role'] === self::ROLE_ADMIN;
        }

        return true;
    }

    public function supportsPreferences(): bool
    {
        return $this->currentAccount() !== null;
    }

    public function loadPreferences(): array
    {
        return $this->currentAccount()['prefs'] ?? [];
    }

    public function savePreferences(array $changes): void
    {
        $user = $this->currentAccount();
        if ($user === null) {
            throw ApiException::unauthorized();
        }
        $this->store->savePreferences($user['name'], $changes);
        $this->current = $this->store->load($user['name']);
    }

    // ---- Kontenverwaltung (nur Rolle admin) --------------------------------

    public function listUsers(): array
    {
        $this->requireAdmin();
        $self = UserStore::key($this->currentAccount()['name'] ?? '');

        return array_map(static fn (array $u) => [
            'name' => $u['name'],
            'role' => $u['role'],
            'sites' => $u['sites'],
            'disabled' => $u['disabled'],
            'self' => UserStore::key($u['name']) === $self,
        ], $this->store->all());
    }

    public function createUser(string $username, string $password, string $role, array $sites): void
    {
        $this->requireAdmin();
        $username = UserStore::normalizeName($username);
        if ($this->store->exists($username)) {
            throw new ApiException('ECONFLICT', 409, 'USER-EXISTS', [$username]);
        }
        $this->store->write(
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            UserStore::normalizeSites($sites),
            false,
        );
    }

    public function deleteUser(string $username): void
    {
        $this->requireAdmin();
        $user = $this->requireOther($username);
        if ($user['role'] === self::ROLE_ADMIN && $this->store->activeAdminCount() <= 1) {
            throw new ApiException('ECONFLICT', 409, 'USER-LAST-ADMIN');
        }
        $this->store->delete($user['name']);
    }

    public function resetPassword(string $username, string $newPassword): void
    {
        $this->requireAdmin();
        $user = $this->requireOther($username);
        $this->store->updateAccount($user['name'], [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
    }

    public function updateUser(string $username, ?string $role = null, ?array $sites = null, ?bool $disabled = null): void
    {
        $this->requireAdmin();
        $user = $this->store->load($username);
        if ($user === null) {
            throw new ApiException('ENOENT', 404, 'USER-NOT-FOUND', [$username]);
        }
        $isSelf = UserStore::key($user['name']) === UserStore::key($this->currentAccount()['name'] ?? '');

        // Das eigene Konto darf sich nicht die Verwaltungsrechte oder den
        // Zugang entziehen — sonst steht die Installation ohne Administrator da.
        if ($isSelf && ($role === self::ROLE_EDITOR || $disabled === true)) {
            throw new ApiException('ECONFLICT', 409, 'USER-SELF-DEMOTE');
        }
        // Ebenso wenig darf der letzte verbleibende Administrator wegfallen.
        $losesAdmin = $user['role'] === self::ROLE_ADMIN
            && ($role === self::ROLE_EDITOR || $disabled === true);
        if ($losesAdmin && $this->store->activeAdminCount() <= 1) {
            throw new ApiException('ECONFLICT', 409, 'USER-LAST-ADMIN');
        }

        $changes = [];
        if ($role !== null) {
            $changes['role'] = UserStore::normalizeRole($role);
        }
        if ($sites !== null) {
            $changes['sites'] = implode(', ', UserStore::normalizeSites($sites));
        }
        if ($disabled !== null) {
            $changes['disabled'] = $disabled ? 'true' : 'false';
        }
        if ($changes === []) {
            return;
        }
        $this->store->updateAccount($user['name'], $changes);
    }

    // ---- Innere Hilfen -----------------------------------------------------

    /** Konto des angemeldeten Benutzers (einmal je Request geladen). */
    private function currentAccount(): ?array
    {
        if ($this->currentLoaded) {
            return $this->current;
        }
        $this->currentLoaded = true;
        $name = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->current = null;
        }
        $user = $this->store->load($name);
        // Ein zwischenzeitlich gelöschtes, gesperrtes oder für diese Webseite
        // nicht mehr freigegebenes Konto verliert seine laufende Sitzung.
        if ($user === null || $user['disabled'] || !UserStore::mayAccessSite($user, $this->siteKey)) {
            return $this->current = null;
        }

        return $this->current = $user;
    }

    /**
     * Sitzungsdauer des Benutzers, der laut Sitzung angemeldet ist. Läuft VOR
     * dem üblichen Laden (currentAccount) und geht deshalb direkt an den Store:
     * Zu diesem Zeitpunkt ist die Webseiten-Bindung noch nicht gesetzt, und ein
     * abgelaufenes Zeitfenster soll unabhängig davon greifen.
     */
    private function sessionLifetimeOfSessionUser(): int
    {
        $name = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->defaultSessionLifetime;
        }
        $user = $this->store->load($name);
        $hours = isset($user['prefs']['session_lifetime']) ? (float) $user['prefs']['session_lifetime'] : 0.0;

        return $hours > 0 ? (int) round($hours * 3600) : $this->defaultSessionLifetime;
    }

    private function isPro(): bool
    {
        return $this->isProProvider !== null && ($this->isProProvider)();
    }

    private function requireAdmin(): void
    {
        if (!$this->can('users.manage')) {
            throw ApiException::denied('USER-ADMIN-REQUIRED');
        }
    }

    /**
     * Lädt ein FREMDES Konto. Eingriffe ins eigene Konto laufen über den
     * Konto-Dialog (mit Passwortbestätigung), nicht über die Verwaltung.
     */
    private function requireOther(string $username): array
    {
        $user = $this->store->load($username);
        if ($user === null) {
            throw new ApiException('ENOENT', 404, 'USER-NOT-FOUND', [$username]);
        }
        if (UserStore::key($user['name']) === UserStore::key($this->currentAccount()['name'] ?? '')) {
            throw new ApiException('ECONFLICT', 409, 'USER-SELF-FORBIDDEN');
        }

        return $user;
    }
}
