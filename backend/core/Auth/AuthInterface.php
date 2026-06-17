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
