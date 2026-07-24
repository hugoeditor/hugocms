<?php

declare(strict_types=1);

/**
 * CLI-Cron: baut die Webseite mit Hugo (wie der „Veröffentlichen"-Knopf, nur
 * ohne Web-Anmeldung). Zweck bei der gestaffelten Veröffentlichung: Ein
 * regelmäßiger Lauf macht freigegebene Seiten sichtbar, sobald ihr Termin
 * erreicht ist.
 *
 * Standardmäßig wird NUR gebaut, wenn tatsächlich fällige terminierte Freigaben
 * anfielen — läuft der Cron alle paar Minuten, spart das den Hugo-Lauf, solange
 * nichts zu veröffentlichen ist. Fiel nichts an, endet der Lauf mit Code 0 und
 * der Meldung „übersprungen".
 *
 * Mit --force wird immer gebaut. Das braucht, wer sich auf Hugos eigenes
 * Front-Matter-`publishDate` verlässt: Dessen Fälligkeit macht erst ein Build
 * sichtbar (Hugo läuft OHNE --buildFuture/--buildDrafts), und ein solcher Termin
 * erzeugt keine „fällige Freigabe" im Sinne der Warteschlange.
 *
 * Minify, Ziel und --cleanDestinationDir stammen aus der [hugo]-Konfiguration —
 * hier werden keine Pfade oder Optionen doppelt gepflegt.
 *
 * Beispiel (Crontab, alle 15 Minuten):
 *   *\/15 * * * *  php /pfad/backend/cli/cron-build.php --mounts=/pfad/backend/mounts.ini
 *
 * Optionen:
 *   --mounts=<datei>  Mount-Konfiguration der Webseite (Standard: backend/mounts.ini;
 *                     bei Mehrfach-Sites mounts/<hash>.ini). Dort liegt die
 *                     [hugo]-Sektion.
 *   --force           Immer bauen, auch ohne fällige Freigaben (für Front-Matter-
 *                     publishDate).
 *   --quiet           Bei Erfolg nichts ausgeben (nur Fehler). Für stille Crons.
 *
 * Keine Pro-Lizenz nötig; es wird nur die Hugo-Konfiguration vorausgesetzt.
 * Beendet mit Code 0 (Build erfolgreich ODER übersprungen), 1 (Hugo-Fehler bzw.
 * Laufzeitfehler), 2 (Aufruffehler: fehlende Konfiguration).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require dirname(__DIR__) . '/core/autoload.php';

use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;

$opts = getopt('', ['mounts::', 'quiet', 'force']);
$backendDir = dirname(__DIR__);
$quiet = isset($opts['quiet']);
$force = isset($opts['force']);
$mountsFile = (string) ($opts['mounts'] ?? ($backendDir . '/mounts.ini'));

$configFile = $backendDir . '/hugocms.ini';
if (!is_file($configFile)) {
    fwrite(STDERR, "Fehler: hugocms.ini nicht gefunden ($configFile).\n");
    exit(2);
}
if (!is_file($mountsFile)) {
    fwrite(STDERR, "Fehler: Mount-Konfiguration nicht gefunden ($mountsFile).\n");
    exit(2);
}
// Mount-Pfad kanonisieren: Das Speicherverzeichnis der Berichte/Entwürfe leitet
// sich aus dem Hugo-Quellpfad ab (sha1), und der hängt am Verzeichnis der
// Mount-Datei. Ein relativer --mounts ergäbe sonst einen anderen String als der
// Web-Zugang (absoluter Pfad) — der Cron fände die dort angelegten Einträge
// nicht. realpath macht relativen und absoluten Aufruf deckungsgleich.
$mountsFile = realpath($mountsFile) ?: $mountsFile;

// logLevel=info erzwingen (statt der Voreinstellung aus der hugocms.ini):
// So landen auch erfolgreiche Läufe ("Hugo-Lauf erfolgreich") in hugocms.log,
// nicht nur Fehlschläge — ein stiller Cron bleibt sonst im Log unsichtbar.
$connector = null;
try {
    $connector = new Connector(['config' => $configFile, 'logLevel' => 'info']);
    $connector->mountsFromFile($mountsFile);
    $result = $connector->buildSite($force);
} catch (ApiException $e) {
    // Selbst abgefangene Ausnahmen lösen den globalen Exception-Handler nicht
    // aus — daher hier ausdrücklich ins Log schreiben, nicht nur auf STDERR.
    $connector?->logException($e);
    fwrite(STDERR, 'Fehler: ' . $e->errorCode() . ' – ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    $connector?->logException($e);
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

// Im Systemstatus für diese Webseite pausiert (siehe [cron] pause_build) — der
// Lauf hat bewusst nichts getan. Kein Fehler.
if (!empty($result['paused'])) {
    if (!$quiet) {
        fwrite(STDOUT, "Cron-Aufgabe „build“ ist pausiert — kein Lauf.\n");
    }
    exit(0);
}

// Keine fälligen Freigaben — nicht gebaut (ohne --force der Normalfall). Kein Fehler.
if (!empty($result['skipped'])) {
    if (!$quiet) {
        fwrite(STDOUT, "Keine fälligen Freigaben — kein Build.\n");
    }
    exit(0);
}

if (empty($result['success'])) {
    fwrite(STDERR, sprintf("Hugo-Lauf fehlgeschlagen (Code %d):\n%s\n", (int) $result['exitCode'], (string) $result['output']));
    exit(1);
}

if (!$quiet) {
    fwrite(STDOUT, sprintf(
        "Hugo-Lauf erfolgreich (%.2fs, %d fällige Freigabe(n)).\n",
        (float) $result['seconds'],
        (int) ($result['applied'] ?? 0),
    ));
}

exit(0);
