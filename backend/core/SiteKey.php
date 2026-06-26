<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

/**
 * Ermittelt eine stabile Kennung der aufgerufenen Webseite aus dem
 * Server-Umfeld — Grundlage für die host-spezifische Mount-Konfiguration
 * (mounts/<hash>.ini).
 *
 * Die Kennung MUSS für jede Anfrage derselben Webseite identisch sein, sonst
 * würden whoami, login, list … unterschiedliche Mounts laden. Daher fließen
 * nur die stabilen Bestandteile der URL ein:
 *
 *   • Host (klein geschrieben, ohne Port, ohne FQDN-Punkt)
 *   • Verzeichnis des Endpunkts (dirname von SCRIPT_NAME) — trennt mehrere
 *     Installationen unter EINER Domain per Pfad, z. B. example.com/site-a.
 *
 * Bewusst NICHT enthalten: Schema (http/https = dieselbe Seite), Port und die
 * Anfrage selbst (?cmd=…), die je Aufruf wechselt. Ergebnis z. B.:
 *
 *   kunde-a.example.com/cms-api
 */
final class SiteKey
{
    /**
     * Kanonische Kennung aus einem $_SERVER-artigen Array.
     */
    public static function fromServer(array $server): string
    {
        return self::host($server) . self::endpointPath((string) ($server['SCRIPT_NAME'] ?? ''));
    }

    /**
     * Nur der normalisierte Host (Domain): klein geschrieben, ohne Port und
     * ohne FQDN-Punkt — z. B. „kunde-a.example.com". Bezugsgröße der
     * Lizenz-Bindung (eine Lizenz je Domain), bewusst OHNE den Endpunkt-Pfad:
     * ein Umzug von /cms-api nach /hugocms-api lässt die Lizenz gültig.
     */
    public static function host(array $server): string
    {
        $host = strtolower((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        $host = (string) strtok($host, ':'); // Port abtrennen

        return rtrim($host, '.');             // FQDN-Punkt entfernen
    }

    /**
     * Deterministischer Hash der Kennung — dient als sicherer Dateiname
     * (nur [0-9a-f], kein Pfad-Ausbruch über manipulierte Host-Header möglich).
     */
    public static function hash(string $siteKey): string
    {
        return hash('sha256', $siteKey);
    }

    /** Verzeichnis des Endpunkts, normalisiert ('' für das Wurzelverzeichnis). */
    private static function endpointPath(string $scriptName): string
    {
        if ($scriptName === '') {
            return '';
        }
        $path = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        return $path === '.' ? '' : $path;
    }
}
