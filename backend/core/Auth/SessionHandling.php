<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Gemeinsamer Sitzungsteil der Auth-Treiber: eigener Cookie-Name, gleitendes
 * Inaktivitäts-Limit, Wechsel der Sitzungs-ID bei An- und Abmeldung.
 *
 * Die Durchsetzung der Sitzungsdauer hängt NICHT an der Garbage Collection von
 * PHP — die läuft bei einem eigenen Sitzungsverzeichnis nicht zuverlässig.
 * Maßgeblich ist der Zeitstempel des letzten Zugriffs in der Sitzung selbst.
 */
trait SessionHandling
{
    private const SESSION_KEY = 'hugocms_fm_user';

    /** Zeitstempel des letzten Zugriffs — Grundlage des Inaktivitäts-Limits. */
    private const LAST_SEEN_KEY = 'hugocms_fm_last_seen';

    /**
     * Zeitpunkt, zu dem DIESE Sitzung ungültig wird. Steht in der Sitzung
     * selbst, weil die Dauer je Konto verschieden sein kann (Mehrbenutzer:
     * prefs.session_lifetime). Nur so kann {@see SessionCleaner} eine fremde
     * Sitzungsdatei beurteilen, ohne die Einstellungen aller Konten zu kennen.
     */
    private const EXPIRES_KEY = 'hugocms_fm_expires';

    /** Name des Sitzungs-Cookies (statt des Standards PHPSESSID). */
    private const SESSION_NAME = 'HUGOCMS';

    /** Standard-Sitzungsdauer in Sekunden, falls keine konfiguriert ist (8 Stunden). */
    private const DEFAULT_SESSION_LIFETIME = 28800;

    /**
     * Startet die Sitzung, sofern noch keine läuft. Liefert true, wenn sie in
     * diesem Aufruf gestartet wurde — nur dann ist das Inaktivitäts-Limit zu
     * prüfen.
     */
    private function startSession(int $lifetime): bool
    {
        // Auf der Kommandozeile gibt es keine Sitzung: Die Cron-Skripte melden
        // sich nicht an, PHP legte aber trotzdem bei JEDEM Lauf eine Datei im
        // Sitzungsverzeichnis an — bei einem Build-Cron alle 15 Minuten sind das
        // fast hundert Dateien am Tag, die nie jemand abholt.
        if (PHP_SAPI === 'cli') {
            return false;
        }
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return false;
        }
        session_name(self::SESSION_NAME);
        // Serverseitige Lebensdauer der Sitzungsdaten mitziehen (Best Effort);
        // durchgesetzt wird die Dauer über den Zeitstempel unten. Das Cookie
        // bleibt ein Sitzungs-Cookie.
        @ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Abgelaufene Sitzungsdateien wegräumen — siehe SessionCleaner: PHPs
        // Müllabfuhr greift im eigenen Verzeichnis nicht. purgeDue() begrenzt
        // sich selbst auf einen Lauf je Stunde; Fehler bleiben folgenlos.
        $dir = session_save_path();
        if ($dir !== '' && is_dir($dir)) {
            SessionCleaner::purgeDue($dir);
        }

        return true;
    }

    /**
     * Setzt die Sitzungsdauer als gleitendes Inaktivitäts-Limit durch: Liegt der
     * letzte Zugriff länger als $lifetime zurück, wird die Sitzung verworfen
     * (Abmeldung). Sonst wird der Zeitstempel aufgefrischt, sodass aktive
     * Nutzung angemeldet bleibt.
     */
    private function enforceIdleTimeout(int $lifetime): void
    {
        $now = time();
        $last = $_SESSION[self::LAST_SEEN_KEY] ?? null;
        if (is_int($last) && $now - $last > $lifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION[self::LAST_SEEN_KEY] = $now;
        // Verfallszeitpunkt mitschreiben: Er wandert in die Sitzungsdatei und
        // macht sie für den Aufräumer selbstauskunftsfähig.
        $_SESSION[self::EXPIRES_KEY] = $now + $lifetime;
    }
}
