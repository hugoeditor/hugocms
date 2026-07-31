<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Auth;

/**
 * Für Treiber, deren Entscheidungen von der AUFGERUFENEN Webseite abhängen.
 *
 * Der Auth-Treiber entsteht im Connector-Konstruktor; welche Webseite bedient
 * wird (und ob für sie eine Pro-Lizenz gilt), steht erst danach fest — die
 * Mount-Konfiguration wird über mountsFromFile() nachgereicht. Deshalb bindet
 * der Connector den Site-Kontext nachträglich an.
 *
 * Der Lizenzstatus kommt als Rückruf, nicht als Wert: Die Prüfung liest die
 * Mount-Konfiguration und soll nur laufen, wenn der Treiber sie wirklich
 * braucht.
 */
interface SiteAwareInterface
{
    /**
     * @param string          $siteKey Host der Webseite (SiteKey::host), z. B.
     *                                 „kunde-a.example.com". Bewusst OHNE
     *                                 Endpunkt-Pfad — wie bei der Lizenz, damit
     *                                 ein Umzug von /cms-api nach /hugocms-api
     *                                 keine Zuordnung entwertet.
     * @param callable():bool $isPro   Liefert, ob für diese Webseite eine
     *                                 gültige Pro-Lizenz vorliegt
     */
    public function bindSite(string $siteKey, callable $isPro): void;
}
