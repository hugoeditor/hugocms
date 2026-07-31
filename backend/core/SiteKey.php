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

    /**
     * Die Hosts aller Webseiten, die diese Installation kennt — gelesen aus den
     * Kopfzeilen der Mount-Konfigurationen unter mounts/:
     *
     *   ; HugoCMS – Mounts für kunde-a.example.com (von install.sh erzeugt).
     *
     * Diese Zeile ist ein Vertrag: bin/sites.sh und bin/crontab-entries.sh
     * lesen sie ebenso, in beiden Sprachen. Grundlage der Auswahlliste, mit der
     * ein Administrator Konten einzelnen Webseiten zuordnet.
     *
     * @return list<string> alphabetisch, ohne Doppelte
     */
    public static function knownHosts(string $mountsDir): array
    {
        $hosts = [];
        foreach (glob(rtrim($mountsDir, '/') . '/*.ini') ?: [] as $path) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            // Nur den Kopf lesen — die Zeile steht immer ganz oben.
            for ($i = 0; $i < 5; $i++) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                if (preg_match('/Mounts f(?:ür|or)\s+(.+?)\s+\(/u', $line, $m) === 1) {
                    $host = strtolower(trim($m[1]));
                    if ($host !== '') {
                        $hosts[$host] = true;
                    }
                    break;
                }
            }
            fclose($handle);
        }
        $list = array_keys($hosts);
        sort($list, SORT_NATURAL);

        return $list;
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
