<?php
/**
 * HugoCMS – fester Einstiegspunkt (Endpunkt /cms-api/).
 *
 * Liegt in backend/core/ und wird von der dünnen index.php im Wurzel-
 * verzeichnis eingebunden. Die Konfiguration wird im übergeordneten
 * backend/-Verzeichnis gesucht: custom.php unter backend/custom/,
 * hugocms.ini und mounts.ini direkt in backend/.
 *
 * Diese Datei gehört zum Backend und wird NICHT vom Anwender bearbeitet.
 * Sie bestimmt, wie der Connector aufgebaut wird — in dieser Reihenfolge:
 *
 *   1. Gibt es eine custom.php, übernimmt diese die gesamte Konfiguration
 *      (Connector instanzieren, Mounts festlegen, run() aufrufen). Der
 *      Autoloader ist dann bereits geladen. Vorlage: custom.php.beispiel.
 *   2. Sonst wird der Connector aus den INI-Dateien erzeugt:
 *        - hugocms.ini       (Anmeldung, Session, Logging)  — erforderlich
 *        - mounts/<hash>.ini (Mounts der aufgerufenen Webseite, host-spezifisch)
 *          mit Rückfall auf mounts.ini (mit Hinweis). Den Hash bildet SiteKey
 *          aus Host + Endpunkt-Pfad — so verwaltet eine Installation mehrere
 *          Webseiten. Fehlt beides, wird die Seite als unbekannt gemeldet (404).
 *   3. Fehlt die hugocms.ini, übernimmt das Einrichtungs-Setup (Setup): Es
 *      zeigt dem Anwender ein Setup-Formular und erzeugt daraus die
 *      hugocms.ini.
 */

declare(strict_types=1);

require __DIR__ . '/autoload.php';

use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\Response;
use HugoCMS\FileManager\Setup;
use HugoCMS\FileManager\SiteKey;

// Konfiguration liegt im übergeordneten backend/-Verzeichnis.
$backendDir = dirname(__DIR__);

// 1. Anwenderspezifischer Bootstrap hat Vorrang (backend/custom/custom.php).
$customBootstrap = $backendDir . '/custom/custom.php';
if (is_file($customBootstrap)) {
    require $customBootstrap;
    return;
}

// 2. Aus den INI-Dateien aufbauen, sofern die Hauptkonfiguration vorliegt.
$configFile = $backendDir . '/hugocms.ini';
$mountsFile = $backendDir . '/mounts.ini';

if (is_file($configFile)) {
    try {
        $connector = new Connector(['config' => $configFile]);
    } catch (ApiException $e) {
        // Konfigurationsfehler vor dem Aufbau des Connectors sauber melden.
        Response::fromException($e);
    }

    // Mounts host-spezifisch laden: Die aufgerufene Webseite bestimmt über
    // einen stabilen Hash die Datei mounts/<hash>.ini. Fehlt sie, gilt die
    // Standard-mounts.ini als Rückfall (mit Hinweis). Fehlt auch die, ist die
    // Webseite nicht eingerichtet (404; ein Setup folgt).
    $siteKey    = SiteKey::fromServer($_SERVER);
    $hash       = SiteKey::hash($siteKey);
    $hostMounts = $backendDir . '/mounts/' . $hash . '.ini';

    if (is_file($hostMounts)) {
        $connector->mountsFromFile($hostMounts);
    } elseif (is_file($mountsFile)) {
        // Kurzer Hinweis an den Client (übersetzbar), ausführlicher Kontext ins Log.
        $connector->addSetupWarning('MOUNT-CONFIG-MISSING', [$siteKey]);
        $connector->logWarning(sprintf(
            'Keine eigene Mount-Konfiguration für „%s" (erwartet: mounts/%s.ini). '
            . 'Es gilt der Rückfall mounts.ini.',
            $siteKey,
            $hash,
        ));
        $connector->mountsFromFile($mountsFile);
    } else {
        Response::error('ESITE', null, 404, [$siteKey, $hash]);
    }

    $connector->run();
    return;
}

// 3. Keine Konfiguration vorhanden — das Einrichtungs-Setup übernimmt. Es
//    beantwortet whoami (setupRequired) und den Befehl setup, der die
//    hugocms.ini erzeugt und den Benutzer direkt anmeldet. Danach greift beim
//    nächsten Aufruf der normale Pfad (Schritt 2).
Setup::handle($backendDir);
