<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Für Treiber, die die Dateitypen eines Kontos einschränken können.
 *
 * Die Liste SCHRÄNKT NUR EIN: Sie wirkt zusätzlich zu den Editor-Endungen
 * (FileService::DEFAULT_EDITABLE + [editor] extra_editable) und zur
 * accept-Liste des Mounts, gibt aber nie mehr frei als diese. Geprüft wird in
 * FileService beim Öffnen im Editor, Speichern, Neuanlegen, Umbenennen und
 * Hochladen — damit gilt sie auch für den KI-Assistenten.
 *
 * Ohne angemeldeten Benutzer (Cron-Läufe, Shop-Anbindung mit Schlüssel) gibt
 * es keine Einschränkung; die Shop-Anbindung arbeitet ohnehin mit einer
 * eigenen FileService-Instanz.
 *
 * Bekannte Lücke: Die Git-Wiederherstellung schreibt über git direkt ins
 * Dateisystem und umgeht diese Prüfung (wie auch die accept-Liste).
 */
interface FileTypeAwareInterface
{
    /**
     * Endungen (klein, ohne Punkt), die der angemeldete Benutzer bearbeiten
     * darf — oder null für „keine Einschränkung“ (Administratoren, Konten ohne
     * Liste, kein angemeldeter Benutzer).
     *
     * @return ?list<string>
     */
    public function allowedFileTypes(): ?array;
}
