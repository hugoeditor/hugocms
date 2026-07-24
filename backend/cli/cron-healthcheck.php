<?php

declare(strict_types=1);

/**
 * CLI-Cron: Gesundheitscheck der Webseite (Pro-Funktion). Führt den SEO-Audit
 * über den bereits gebauten public/-Ordner aus und benachrichtigt den Betreiber
 * per E-Mail, sobald der Bericht Fehler ODER Warnungen enthält. Nur über die
 * Kommandozeile aufrufbar.
 *
 * Der Check BAUT NICHT selbst — er prüft den vorhandenen public/-Ordner. Fehlt
 * er, wird ein klarer Fehler gemeldet (AUDIT-NO-BUILD-OUTPUT). Für einen
 * frischen Stand zuerst cron-build.php laufen lassen (getrennte Cron-Einträge).
 *
 * Der E-Mail-Versand läuft über einen eigenen SMTP-Client; SMTP-Zugang, Absender
 * und Empfänger stehen in der [mail]-Sektion der hugocms.ini. Sind dort Probleme
 * zu melden, aber [mail] ist nicht eingerichtet, endet der Lauf mit einem Fehler.
 *
 * Beispiel (Crontab, täglich 6 Uhr):
 *   0 6 * * *  php /pfad/backend/cli/cron-healthcheck.php --host=example.com
 *
 * Optionen:
 *   --host=<domain>   Domäne der Pro-Lizenz (Pflicht — die Lizenz ist
 *                     domänengebunden und wird sonst nicht als gültig erkannt).
 *   --mounts=<datei>  Mount-Konfiguration der Webseite (Standard: backend/mounts.ini;
 *                     bei Mehrfach-Sites mounts/<hash>.ini).
 *   --dry-run         Probelauf: führt den Audit aus, versendet aber KEINE
 *                     E-Mail und setzt keine Pro-Lizenz voraus (dann ist --host
 *                     nicht nötig). Zeigt die Zusammenfassung auf STDOUT.
 *
 * Beendet mit Code 0 (Lauf erfolgreich, ggf. inkl. Mailversand), 1 (Laufzeit-
 * fehler: fehlendes public/, Mailversand/SMTP, [mail] nicht eingerichtet),
 * 2 (Aufruffehler: fehlende Konfiguration/Mounts/Host).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require dirname(__DIR__) . '/core/autoload.php';

use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;

$opts = getopt('', ['host:', 'mounts::', 'dry-run']);
// Diese Datei liegt in backend/cli/ — die Konfiguration liegt eine Ebene höher.
$backendDir = dirname(__DIR__);

$dryRun = isset($opts['dry-run']);
$host = trim((string) ($opts['host'] ?? ''));
// Der Probelauf ruft nichts Lizenzpflichtiges auf → --host nicht nötig.
if (!$dryRun && $host === '') {
    fwrite(STDERR, "Fehler: --host=<domain> ist erforderlich (die Pro-Lizenz ist domänengebunden).\n");
    exit(2);
}
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

// Die Lizenzprüfung liest die Domäne aus $_SERVER['HTTP_HOST'] (SiteKey::host).
// Im CLI gibt es keinen Host — hier den lizenzierten Domainnamen setzen.
if ($host !== '') {
    $_SERVER['HTTP_HOST'] = $host;
}

// logLevel=info erzwingen (statt der Voreinstellung aus der hugocms.ini):
// So landet auch ein erfolgreicher, unauffälliger Lauf in hugocms.log — ein
// stiller Cron bleibt sonst unsichtbar.
$connector = null;
try {
    $connector = new Connector(['config' => $configFile, 'logLevel' => 'info']);
    $connector->mountsFromFile($mountsFile);
    $result = $connector->runHealthCheck($dryRun);
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

// Im Systemstatus für diese Webseite pausiert (siehe [cron] pause_healthcheck).
if (!empty($result['paused'])) {
    fwrite(STDOUT, "Cron-Aufgabe „healthcheck“ ist pausiert — kein Lauf.\n");
    exit(0);
}

$summary = $result['summary'] ?? ['error' => 0, 'warning' => 0, 'hint' => 0];
fwrite(STDOUT, sprintf(
    "Gesundheitscheck: %d Seiten, %d Fehler / %d Warnungen / %d Hinweise.\n",
    (int) ($result['pagesScanned'] ?? 0),
    (int) ($summary['error'] ?? 0),
    (int) ($summary['warning'] ?? 0),
    (int) ($summary['hint'] ?? 0),
));

if ($dryRun) {
    fwrite(STDOUT, "Probelauf — es wird keine E-Mail versendet.\n");
} elseif (!empty($result['mailed'])) {
    fwrite(STDOUT, "Probleme gefunden — Benachrichtigung wurde versendet.\n");
} elseif (empty($result['problems'])) {
    fwrite(STDOUT, "Keine Fehler oder Warnungen — keine Benachrichtigung nötig.\n");
}

exit(0);
