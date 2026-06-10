<?php
/**
 * Beispiel-Bootstrap für den HugoCMS-Dateimanager.
 *
 * Diese Datei schreibt der Anwender selbst. Sie legt fest, welche
 * Verzeichnisse als Mounts erreichbar sind und wie die Anmeldung erfolgt.
 * Das Backend kennt keine festen Pfade — alles wird hier konfiguriert.
 */

declare(strict_types=1);

require __DIR__ . '/backend/autoload.php';

use HugoCMS\FileManager\Connector;

// Anmeldung, Sitzungsverzeichnis und Logging stammen aus hugocms.ini
// (Vorlage: hugocms.ini.beispiel). Der Connector liest die Datei, setzt das
// Sitzungsverzeichnis und erzeugt die Authentifizierung (driver-abhängig).
$connector = new Connector([
    'config' => __DIR__ . '/hugocms.ini',
    // 'cors' => 'http://localhost:5173',  // nur nötig, wenn das Frontend getrennt läuft (Vite-Dev-Server)
    //
    // Eigene AuthInterface-Implementierungen registrieren und in hugocms.ini
    // per [auth] driver = ... auswählen:
    // 'authDrivers' => [
    //     'ldap' => fn (array $cfg) => new \Meine\LdapAuth($cfg['host']),
    // ],
]);

// Mounts festlegen — zwei Wege, beliebig kombinierbar:
//
// A) Aus einer Konfigurationsdatei (gut lesbar, ohne Code-Änderung pflegbar;
//    Format siehe mounts.ini.beispiel):
//
//        $connector->mountsFromFile(__DIR__ . '/mounts.ini');
//
// B) Programmatisch als benannte Mounts:
$connector->mount('inhalte', __DIR__ . '/daten/inhalte', [
    'label' => 'Inhalte',
    'accept' => ['md', 'markdown', 'html', 'htm', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
]);

$connector->mount('vorlagen', __DIR__ . '/daten/vorlagen', [
    'label' => 'Vorlagen',
    'permissions' => ['read', 'write'], // bearbeiten erlaubt, aber kein Löschen/Hochladen
]);

$connector->mount('medien', __DIR__ . '/daten/medien', [
    'label' => 'Medien',
]);

$connector->run();
