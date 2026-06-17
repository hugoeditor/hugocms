<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Vertrag für die Anmeldung. In Stufe 1 gibt es genau eine Umsetzung
 * (SingleUser). Mehrbenutzer mit Rollen kommen später als weitere
 * Umsetzung hinzu, ohne dass Connector oder Mounts sich ändern.
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
}
