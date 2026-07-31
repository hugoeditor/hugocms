<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Kontenverwaltung — nur für Treiber, die mehrere Benutzer führen. Der
 * Connector prüft per instanceof, ob sein Treiber das kann; der Einzelbenutzer
 * bringt es nicht mit, und die zugehörigen Befehle sind dort schlicht nicht
 * verfügbar.
 *
 * ALLE Methoden dieser Schnittstelle setzen voraus, dass der Aufrufer das Recht
 * dazu hat (Rolle „admin"). Die Umsetzungen prüfen das zusätzlich selbst —
 * Rechteentscheidungen gehören in den Treiber, nicht in den Connector.
 */
interface UserAdminInterface
{
    /** Rolle mit allen Rechten: Konten verwalten, Zugriff auf alle Webseiten. */
    public const ROLE_ADMIN = 'admin';

    /** Rolle für redaktionelle Arbeit an den zugewiesenen Webseiten. */
    public const ROLE_EDITOR = 'editor';

    /** Platzhalter in der Webseiten-Zuordnung: Zugriff auf alle Webseiten. */
    public const ALL_SITES = '*';

    /**
     * Alle Konten — ohne Passwort-Hashes.
     *
     * @return list<array{name: string, role: string, sites: list<string>, disabled: bool, self: bool}>
     */
    public function listUsers(): array;

    /**
     * Legt ein Konto an. Der Name muss frei sein.
     *
     * @param list<string> $sites Webseiten-Kennungen oder [ALL_SITES]
     */
    public function createUser(string $username, string $password, string $role, array $sites): void;

    /**
     * Löscht ein Konto. Das eigene Konto und das letzte verbleibende
     * Administratorkonto lassen sich nicht löschen.
     */
    public function deleteUser(string $username): void;

    /**
     * Setzt das Passwort eines fremden Kontos neu („Passwort vergessen"). Das
     * eigene Passwort ändert man über changeCredentials mit Bestätigung.
     */
    public function resetPassword(string $username, string $newPassword): void;

    /**
     * Ändert Rolle, Webseiten-Zuordnung oder Sperre eines Kontos. null lässt
     * den jeweiligen Wert unverändert.
     *
     * @param ?list<string> $sites
     */
    public function updateUser(string $username, ?string $role = null, ?array $sites = null, ?bool $disabled = null): void;
}
