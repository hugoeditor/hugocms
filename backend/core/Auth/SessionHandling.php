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
    }
}
