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
 *        - hugocms.ini  (Anmeldung, Session, Logging)  — erforderlich
 *        - mounts.ini   (Mount-Punkte)                 — optional
 *   3. Fehlt beides, wird ein Einrichtungsfehler an den Client gemeldet.
 *      (Ein Einrichtungs-Setup, das die INI-Dateien erzeugt, folgt später.)
 */

declare(strict_types=1);

require __DIR__ . '/autoload.php';

use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\Response;

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
        Response::error($e->errorCode(), $e->getMessage(), $e->httpStatus());
    }
    if (is_file($mountsFile)) {
        $connector->mountsFromFile($mountsFile);
    }
    $connector->run();
    return;
}

// 3. Keine Konfiguration vorhanden — die Einrichtung steht noch aus.
Response::error(
    'ESETUP',
    'HugoCMS ist noch nicht eingerichtet: weder custom.php noch hugocms.ini gefunden. '
    . 'Das Einrichtungs-Setup folgt.',
    503,
);
