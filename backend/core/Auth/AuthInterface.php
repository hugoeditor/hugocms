<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Vertrag für die Anmeldung. Umsetzungen: SingleUser (ein Konto, Daten in der
 * hugocms.ini) und MultiUser (mehrere Konten mit Rollen, je Konto eine Datei
 * unter users/). Connector und Mounts bleiben davon unberührt.
 *
 * Was NUR der Mehrbenutzer kann, steht in eigenen Schnittstellen, damit dieser
 * Vertrag schlank bleibt: {@see UserAdminInterface} (Konten verwalten) und
 * {@see SiteAwareInterface} (Bindung an die aufgerufene Webseite).
 */
interface AuthInterface
{
    /**
     * Prüft Anmeldedaten und merkt den Benutzer in der Sitzung.
     */
    public function attemptLogin(string $username, string $password): bool;

    /**
     * Prüft das Passwort des aktuell angemeldeten Benutzers OHNE Seiteneffekte
     * (keine Sitzungsänderung). Für sicherheitsrelevante Bestätigungen, etwa
     * vor dem Ändern der Anmeldedaten.
     */
    public function verifyPassword(string $password): bool;

    /**
     * Kann der Treiber die eigenen Anmeldedaten (Name/Passwort) ändern und
     * persistieren? Nicht jeder kann das (z. B. LDAP, oder eine programmatisch
     * ohne Persistenzort erzeugte Instanz).
     */
    public function supportsCredentialChange(): bool;

    /**
     * Ändert die Anmeldedaten des aktuell angemeldeten Benutzers und speichert
     * sie dauerhaft (treiberspezifisch). null lässt den jeweiligen Wert
     * unverändert. Der Aufrufer hat die Identität zuvor zu bestätigen
     * (verifyPassword). Wird nur aufgerufen, wenn supportsCredentialChange().
     */
    public function changeCredentials(?string $newUsername, ?string $newPassword): void;

    public function logout(): void;

    public function isAuthenticated(): bool;

    /**
     * Aktueller Benutzer als Array (name, roles, permissions) oder null.
     */
    public function currentUser(): ?array;

    /**
     * Globale Rechteprüfung. Mount-bezogene Rechte prüft der Connector
     * zusätzlich gegen die Mount-Konfiguration.
     */
    public function can(string $permission): bool;

    /**
     * Kann der Treiber die Einstellungen des angemeldeten Benutzers dauerhaft
     * speichern? Ohne Persistenzort (z. B. programmatisch über custom.php)
     * nicht.
     */
    public function supportsPreferences(): bool;

    /**
     * ROHE [user]-Einstellungen des angemeldeten Benutzers, so wie sie in der
     * INI stehen (Schlüssel/Wert als Zeichenketten). Wo sie liegen, weiß nur
     * der Treiber: beim Einzelbenutzer in der hugocms.ini, beim Mehrbenutzer in
     * der Datei des jeweiligen Kontos.
     *
     * @return array<string, string>
     */
    public function loadPreferences(): array;

    /**
     * Schreibt einzelne [user]-Schlüssel des angemeldeten Benutzers; nicht
     * genannte bleiben unberührt, der Wert null entfernt einen Schlüssel.
     * Wird nur aufgerufen, wenn supportsPreferences().
     *
     * @param array<string, string|null> $changes
     */
    public function savePreferences(array $changes): void;
}
