<?php

declare(strict_types=1);

/**
 * CLI-Cron: baut die Webseite mit Hugo (wie der „Veröffentlichen"-Knopf, nur
 * ohne Web-Anmeldung). Zweck bei der gestaffelten Veröffentlichung: Ein
 * regelmäßiger Lauf macht freigegebene Seiten sichtbar, sobald ihr publishDate
 * erreicht ist — Hugo läuft bewusst OHNE --buildFuture/--buildDrafts, künftige
 * und als Entwurf markierte Seiten bleiben also bis zum Termin unveröffentlicht.
 * Die Auflösung der Staffelung entspricht dem Cron-Intervall.
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
 *   --quiet           Bei Erfolg nichts ausgeben (nur Fehler). Für stille Crons.
 *
 * Keine Pro-Lizenz nötig; es wird nur die Hugo-Konfiguration vorausgesetzt.
 * Beendet mit Code 0 (Build erfolgreich), 1 (Hugo-Fehler bzw. Laufzeitfehler),
 * 2 (Aufruffehler: fehlende Konfiguration).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require dirname(__DIR__) . '/core/autoload.php';

use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;

$opts = getopt('', ['mounts::', 'quiet']);
$backendDir = dirname(__DIR__);
$quiet = isset($opts['quiet']);
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

// logLevel=info erzwingen (statt der Voreinstellung aus der hugocms.ini):
// So landen auch erfolgreiche Läufe ("Hugo-Lauf erfolgreich") in hugocms.log,
// nicht nur Fehlschläge — ein stiller Cron bleibt sonst im Log unsichtbar.
$connector = null;
try {
    $connector = new Connector(['config' => $configFile, 'logLevel' => 'info']);
    $connector->mountsFromFile($mountsFile);
    $result = $connector->buildSite();
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

if (empty($result['success'])) {
    fwrite(STDERR, sprintf("Hugo-Lauf fehlgeschlagen (Code %d):\n%s\n", (int) $result['exitCode'], (string) $result['output']));
    exit(1);
}

if (!$quiet) {
    fwrite(STDOUT, sprintf("Hugo-Lauf erfolgreich (%.2fs).\n", (float) $result['seconds']));
}

exit(0);
