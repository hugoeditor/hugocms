<?php

declare(strict_types=1);

/**
 * CLI-Cron: verbessert automatisch die nächsten verbesserungsbedürftigen
 * Content-Dateien (geprüft, Score < 100, noch nicht verbessert) im Schreibmodus
 * "auto" (ohne Bestätigung). Nur über die Kommandozeile aufrufbar.
 *
 * Beispiel (Crontab, täglich 3 Uhr, 3 Dateien je Lauf):
 *   0 3 * * *  php /pfad/backend/cli/cron-improve.php --host=example.com --limit=3
 *
 * Optionen:
 *   --host=<domain>   Domäne der Pro-Lizenz (Pflicht — die Lizenz ist
 *                     domänengebunden und wird sonst nicht als gültig erkannt).
 *   --mounts=<datei>  Mount-Konfiguration der Webseite (Standard: backend/mounts.ini;
 *                     bei Mehrfach-Sites mounts/<hash>.ini).
 *   --limit=<N>       Anzahl Dateien je Lauf (Standard: 1).
 *   --locale=<de|en>  Sprache der KI-Anweisung (Standard: de).
 *   --dry-run         Probelauf: zeigt nur, welche Dateien verarbeitet würden —
 *                     ohne API-Aufruf, ohne Schreiben, ohne Pro-/KI-Voraussetzung
 *                     (dann ist --host nicht nötig).
 *
 * Prüfungen selbst erzeugt der Cron NICHT — er verbessert nur bereits geprüfte
 * Dateien. Nach der Verbesserung wird die Datei serverseitig als „verbessert"
 * vermerkt und fällt aus der Arbeitsliste; eine automatische Neuprüfung findet
 * bewusst nicht statt.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur über die Kommandozeile aufrufbar.\n");
}

require dirname(__DIR__) . '/core/autoload.php';

use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;

$opts = getopt('', ['host:', 'mounts::', 'limit::', 'locale::', 'dry-run']);
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
$limit = max(1, (int) ($opts['limit'] ?? 1));
$locale = (string) ($opts['locale'] ?? 'de');

$configFile = $backendDir . '/hugocms.ini';
if (!is_file($configFile)) {
    fwrite(STDERR, "Fehler: hugocms.ini nicht gefunden ($configFile).\n");
    exit(2);
}
if (!is_file($mountsFile)) {
    fwrite(STDERR, "Fehler: Mount-Konfiguration nicht gefunden ($mountsFile).\n");
    exit(2);
}

// Die Lizenzprüfung liest die Domäne aus $_SERVER['HTTP_HOST'] (SiteKey::host).
// Im CLI gibt es keinen Host — hier den lizenzierten Domainnamen setzen.
if ($host !== '') {
    $_SERVER['HTTP_HOST'] = $host;
}

// logLevel=info erzwingen (statt der Voreinstellung aus der hugocms.ini):
// So landen auch erfolgreiche Verbesserungen ("Cron-Verbesserung: …") in
// hugocms.log, nicht nur Fehlschläge — ein stiller Cron bleibt sonst unsichtbar.
$connector = null;
try {
    $connector = new Connector(['config' => $configFile, 'logLevel' => 'info']);
    $connector->mountsFromFile($mountsFile);
    $result = $connector->improveNextContent($limit, $locale, $dryRun);
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

$processed = $result['processed'] ?? [];
if ($dryRun) {
    fwrite(STDOUT, sprintf(
        "Probelauf — nichts wird geändert.\nKandidaten: %d, davon bei diesem Limit dran: %d\n",
        (int) ($result['candidates'] ?? 0),
        count($processed),
    ));
    foreach ($processed as $p) {
        fwrite(STDOUT, sprintf("  %s (Score %s)\n", $p['path'] ?? '?', (string) ($p['score'] ?? '?')));
    }
    exit(0);
}

fwrite(STDOUT, sprintf(
    "Kandidaten: %d, verarbeitet: %d\n",
    (int) ($result['candidates'] ?? 0),
    count($processed),
));
foreach ($processed as $p) {
    fwrite(STDOUT, sprintf(
        "  %s — %s\n",
        $p['path'] ?? '?',
        !empty($p['written']) ? 'verbessert' : 'keine Änderung',
    ));
}

exit(0);
