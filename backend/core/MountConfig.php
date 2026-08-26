<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Liest Mount-Definitionen aus einer INI-Konfigurationsdatei. Format: je
 * [Sektion] ein Mount, der Sektionsname ist die interne ID. Beispiel:
 *
 *   [inhalte]
 *   path = daten/inhalte
 *   label = Inhalte
 *   accept = md, markdown, html, png, jpg
 *
 *   [vorlagen]
 *   path = daten/vorlagen
 *   permissions = read, write
 *
 * Felder je Sektion:
 *   path        (Pflicht) Verzeichnis. Relative Pfade gelten relativ zum
 *               Verzeichnis der Konfigurationsdatei.
 *   label       (optional) Anzeigename, Standard: Sektionsname.
 *   permissions (optional) Kommaliste; fehlt sie, gelten alle Rechte.
 *   accept      (optional) Kommaliste erlaubter Endungen (ohne Punkt).
 *   readonly    (optional) true = nur Lesen.
 *
 * Reservierte Sektion [hugo] (kein Mount): konfiguriert den webseiten-
 * spezifischen Teil des Hugo-Aufrufs (Befehl "build"). Das Hugo-PROGRAMM
 * selbst (bin) steht zentral in der hugocms.ini — es gibt nur eines.
 *   source      (Pflicht) Hugo-Projektverzeichnis.
 *   destination (optional) Zielverzeichnis, Standard: <source>/public.
 *   minify      (optional) true = Hugo mit --minify aufrufen.
 *   clean       (optional) true = --cleanDestinationDir. VORSICHT: entfernt
 *               im Ziel alles Nicht-Generierte — auch eine dort liegende
 *               Installation (edit/, cms-api/). Standard: false.
 *
 * Reservierte Sektion [seo_report] (kein Mount): Ausschlüsse des SEO-Berichts,
 * die NUR für diese Webseite gelten. Sie ERGÄNZEN die fest verdrahteten
 * Ausschlüsse und die globale [seo_report]-Sektion der hugocms.ini (siehe
 * {@see Config}) — nichts wird dadurch wieder eingeschlossen.
 *   exclude_prefixes (optional) Kommaliste public-relativer Verzeichnis-Präfixe.
 *   exclude_files    (optional) Kommaliste einzelner public-relativer Dateien.
 *
 * Reservierte Sektion [improve] (kein Mount): Automatikmodus des Cron-
 * Verbesserers (cron-improve.php). Ist er an, wird jeder erzeugte Entwurf
 * gleich terminiert — zu einem zufälligen Zeitpunkt im angegebenen Tagesfenster
 * und höchstens per_day Stück je Tag. So gehen verbesserte Seiten verteilt live
 * statt alle auf einmal.
 *   auto         (optional) true schaltet den Automatikmodus ein. Standard: aus.
 *   window_start (optional) Beginn des Fensters, „HH:MM“ Serverzeit. Standard 07:00.
 *   window_end   (optional) Ende des Fensters, „HH:MM“. Standard 16:00.
 *   per_day       (optional) Höchstzahl Freigaben je Tag (1–50). Standard 3.
 *   skip_weekends (optional) Samstag und Sonntag von der Terminierung ausnehmen
 *                 (Serverzeit). Standard: an — zum Abschalten ausdrücklich false.
 *
 * Reservierte Sektion [cron] (kein Mount): Pausenschalter der drei Cron-Skripte
 * dieser Webseite. Ist ein Schalter an, prüft das zugehörige CLI-Skript das beim
 * Start und tut nichts — so lässt sich ein Cron-Job aussetzen, ohne die Crontab
 * des Hosters anzufassen.
 *   pause_build       (optional) true pausiert cron-build.php. Standard: aus.
 *   pause_improve     (optional) true pausiert cron-improve.php. Standard: aus.
 *   pause_healthcheck (optional) true pausiert cron-healthcheck.php. Standard: aus.
 *
 * Reservierte Sektion [git] (kein Mount): automatischer Commit nach der
 * zeitgesteuerten Veröffentlichung (cron-build.php). Ist auto_commit an und das
 * Quellverzeichnis ein Git-Repository, legt der Cron nach dem Einspielen fälliger
 * Freigaben einen Commit an; an die Nachricht wird das Datum angehängt. Setzt die
 * Pro-Lizenz voraus (Git ist eine Pro-Funktion).
 * Zusätzlich sichert der Cron VOR dem Build offene (noch unversionierte)
 * Änderungen im Quellverzeichnis mit einer eigenen Nachricht, sofern welche
 * vorliegen — so bleibt der Veröffentlichungs-Commit auf die publizierten
 * Dateien beschränkt. Beides hängt am selben Schalter auto_commit.
 *   auto_commit            (optional) true schaltet den Auto-Commit ein. Standard: aus.
 *   commit_message         (optional) Nachricht nach der Veröffentlichung (ohne Datum). Standard: siehe unten.
 *   commit_message_pending (optional) Nachricht für offene Änderungen vor dem Build (ohne Datum). Standard: siehe unten.
 *   changelog              (optional) false schaltet das Änderungsprotokoll ab
 *                          (die Seite changelog.md im Content-Mount, die bei
 *                          jedem Versionsstand fortgeschrieben wird).
 *                          Standard: an.
 *   tag_label              (optional) Wort vor der Versionsnummer in der
 *                          Überschrift des Änderungsprotokolls („Ausgabe 12“).
 *                          Sprachabhängiger Text und deshalb konfigurierbar —
 *                          im Dialog kommt er vom Client, beim Cron von hier.
 *                          Leer = nur die Nummer. Standard: siehe unten.
 */
final class MountConfig
{
    /** Sektionsnamen, die NICHT als Mount interpretiert werden. */
    private const HUGO_SECTION = 'hugo';
    private const LICENSE_SECTION = 'license';
    private const PAGESPEED_SECTION = 'pagespeed';
    private const LIVE_ANALYSIS_SECTION = 'live_analysis';
    private const SEO_REPORT_SECTION = 'seo_report';
    private const IMPROVE_SECTION = 'improve';
    private const CRON_SECTION = 'cron';
    private const GIT_SECTION = 'git';

    /** Vorgeschlagene Commit-Nachricht, wenn keine konfiguriert ist. */
    public const string GIT_COMMIT_MESSAGE_DEFAULT = 'Automatische Veröffentlichung terminierter Freigaben';

    /** Vorgeschlagene Nachricht für den Vorab-Commit offener Änderungen. */
    public const string GIT_COMMIT_MESSAGE_PENDING_DEFAULT = 'Offene Änderungen vor dem Build gesichert';

    /** Wort vor der Versionsnummer im Änderungsprotokoll. */
    public const string GIT_TAG_LABEL_DEFAULT = 'Ausgabe';

    /** Obergrenze dieses Wortes — es steht in einer Überschrift. */
    private const int GIT_TAG_LABEL_MAX = 40;

    /** Obergrenze der Commit-Nachricht (vor dem Datum), damit sie handhabbar bleibt. */
    private const int GIT_MESSAGE_MAX = 200;

    /** Vorgaben des Automatikmodus, wenn die [improve]-Sektion fehlt. */
    private const IMPROVE_DEFAULTS = [
        'auto' => false,
        'windowStart' => '07:00',
        'windowEnd' => '16:00',
        'perDay' => 3,
        // Vorgabe an, solange nichts in der INI steht — Freigaben am Wochenende
        // sind meist unerwünscht, das soll ohne Zutun gelten.
        'skipWeekends' => true,
    ];

    /**
     * @return array{
     *   mounts: list<array{name: string, path: string, options: array}>,
     *   hugo: ?array{source: string, destination: string, minify: bool, clean: bool},
     *   license: ?string,
     *   pagespeed: ?string,
     *   liveAnalysis: ?string,
     *   seoReport: array{excludePrefixes: list<string>, excludeFiles: list<string>},
     *   improve: array{auto: bool, windowStart: string, windowEnd: string, perDay: int, skipWeekends: bool},
     *   cron: array{pauseBuild: bool, pauseImprove: bool, pauseHealthcheck: bool},
     *   git: array{autoCommit: bool, commitMessage: string, commitMessagePending: string},
     *   warnings: list<array{key: string, params: list<mixed>}>
     * }
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new ApiException('ECONFIG', 500, 'MOUNTS-NOT-READABLE', [$configPath]);
        }

        $raw = @parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if (!is_array($raw)) {
            throw new ApiException('ECONFIG', 500, 'MOUNTS-INVALID-INI', [$configPath]);
        }

        $baseDir = dirname($configPath);
        $mounts = [];
        $hugo = null;
        $license = null;
        $pagespeed = null;
        $liveAnalysis = null;
        $seoReport = ['excludePrefixes' => [], 'excludeFiles' => []];
        $improve = self::IMPROVE_DEFAULTS;
        $cron = ['pauseBuild' => false, 'pauseImprove' => false, 'pauseHealthcheck' => false];
        $git = [
            'autoCommit' => false,
            'commitMessage' => self::GIT_COMMIT_MESSAGE_DEFAULT,
            'commitMessagePending' => self::GIT_COMMIT_MESSAGE_PENDING_DEFAULT,
            // Vorgabe an: Der Schalter dient zum Abschalten des Protokolls,
            // nicht zum Einschalten — fehlt er, wird es geschrieben.
            'changelog' => true,
        ];
        $warnings = [];

        foreach ($raw as $name => $section) {
            if (!is_array($section)) {
                throw new ApiException('ECONFIG', 500, 'MOUNTS-ENTRY-OUTSIDE-SECTION', [(string) $name]);
            }

            if (strtolower((string) $name) === self::HUGO_SECTION) {
                $hugo = self::hugoSection($section, $baseDir);
                // Vorhandene, aber unvollständige [hugo]-Sektion bricht die Site
                // NICHT ab — sie ist dann lediglich nicht veröffentlichbar
                // (buildable=false). Ein Hinweis macht den Tippfehler sichtbar.
                if ($hugo === null) {
                    $warnings[] = ['key' => 'HUGO-CONFIG-INCOMPLETE', 'params' => [$configPath]];
                }
                continue;
            }

            // Pro-Lizenz dieser Webseite (optional). Nur der rohe Schlüssel;
            // geprüft (Signatur, Domain-Bindung) wird er von {@see License}.
            if (strtolower((string) $name) === self::LICENSE_SECTION) {
                $key = trim((string) ($section['key'] ?? ''));
                $license = $key === '' ? null : $key;
                continue;
            }

            // PageSpeed-Check dieser Webseite (optional): die zu messende
            // öffentliche Live-Adresse. Pro Projekt, daher hier statt zentral in
            // der hugocms.ini. Wird über das PageSpeed-Panel gesetzt und beim
            // Messstart geschrieben.
            if (strtolower((string) $name) === self::PAGESPEED_SECTION) {
                $url = trim((string) ($section['url'] ?? ''));
                $pagespeed = $url === '' ? null : $url;
                continue;
            }

            // Live-Analyse dieser Webseite (optional): die zu prüfende öffentliche
            // Live-Adresse. Eigene Sektion, unabhängig von [pagespeed] — beide
            // Prüfungen teilen keinen Zustand. Wird über das Live-Analyse-Panel
            // gesetzt und beim Start geschrieben.
            if (strtolower((string) $name) === self::LIVE_ANALYSIS_SECTION) {
                $url = trim((string) ($section['url'] ?? ''));
                $liveAnalysis = $url === '' ? null : $url;
                continue;
            }

            // Zusätzliche Ausschlüsse des SEO-Berichts NUR für diese Webseite
            // (optional). Dieselbe Schreibweise und Normalisierung wie die
            // globale Sektion der hugocms.ini — der Connector legt beide
            // Listen zusammen. Ausgeschlossen bleibt ausgeschlossen: Diese
            // Sektion kann global Ausgeschlossenes nicht zurückholen.
            if (strtolower((string) $name) === self::SEO_REPORT_SECTION) {
                $seoReport = [
                    'excludePrefixes' => Config::normalizeExcludePrefixes((string) ($section['exclude_prefixes'] ?? '')),
                    'excludeFiles' => Config::normalizeExcludeFiles((string) ($section['exclude_files'] ?? '')),
                ];
                continue;
            }

            // Automatikmodus des Cron-Verbesserers (optional, pro Webseite).
            if (strtolower((string) $name) === self::IMPROVE_SECTION) {
                $improve = self::improveSection($section);
                continue;
            }

            // Pausenschalter der Cron-Skripte (optional, pro Webseite).
            if (strtolower((string) $name) === self::CRON_SECTION) {
                $cron = [
                    'pauseBuild' => filter_var($section['pause_build'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'pauseImprove' => filter_var($section['pause_improve'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'pauseHealthcheck' => filter_var($section['pause_healthcheck'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
                continue;
            }

            // Automatischer Commit nach der Veröffentlichung (optional, pro Webseite)
            // sowie der Vorab-Commit offener Änderungen — beide am selben Schalter.
            if (strtolower((string) $name) === self::GIT_SECTION) {
                $message = trim((string) ($section['commit_message'] ?? ''));
                if ($message === '') {
                    $message = self::GIT_COMMIT_MESSAGE_DEFAULT;
                }
                $pending = trim((string) ($section['commit_message_pending'] ?? ''));
                if ($pending === '') {
                    $pending = self::GIT_COMMIT_MESSAGE_PENDING_DEFAULT;
                }
                // Nicht gesetzt = Standard; ausdrücklich leer = ohne Wort
                // vor der Nummer. array_key_exists trennt beides.
                $label = array_key_exists('tag_label', $section)
                    ? trim((string) $section['tag_label'])
                    : self::GIT_TAG_LABEL_DEFAULT;
                $git = [
                    'autoCommit' => filter_var($section['auto_commit'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'commitMessage' => mb_substr($message, 0, self::GIT_MESSAGE_MAX),
                    'commitMessagePending' => mb_substr($pending, 0, self::GIT_MESSAGE_MAX),
                    'changelog' => filter_var($section['changelog'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'tagLabel' => mb_substr($label, 0, self::GIT_TAG_LABEL_MAX),
                ];
                continue;
            }

            $path = isset($section['path']) ? trim((string) $section['path']) : '';
            if ($path === '') {
                throw new ApiException('ECONFIG', 500, 'MOUNTS-PATH-REQUIRED', [(string) $name]);
            }
            $path = self::resolve($path, $baseDir);

            $options = [];
            if (isset($section['label'])) {
                $options['label'] = (string) $section['label'];
            }
            if (isset($section['permissions'])) {
                $options['permissions'] = self::toList($section['permissions']);
            }
            if (isset($section['accept'])) {
                $options['accept'] = self::toList($section['accept']);
            }
            if (isset($section['readonly'])) {
                $options['readonly'] = (bool) $section['readonly'];
            }

            $mounts[] = ['name' => (string) $name, 'path' => $path, 'options' => $options];
        }

        if ($mounts === []) {
            throw new ApiException('ECONFIG', 500, 'MOUNTS-NO-SECTION', [$configPath]);
        }

        return [
            'mounts' => $mounts,
            'hugo' => $hugo,
            'license' => $license,
            'pagespeed' => $pagespeed,
            'liveAnalysis' => $liveAnalysis,
            'seoReport' => $seoReport,
            'improve' => $improve,
            'cron' => $cron,
            'git' => $git,
            'warnings' => $warnings,
        ];
    }

    /**
     * Liest die [improve]-Sektion: Automatikmodus des Cron-Verbesserers samt
     * Veröffentlichungsfenster und Tagesmenge. Fehlerhafte Werte fallen still
     * auf die Vorgabe zurück — eine unbrauchbare Uhrzeit darf die Webseite nicht
     * unbenutzbar machen.
     *
     * @param array<string, mixed> $section
     * @return array{auto: bool, windowStart: string, windowEnd: string, perDay: int, skipWeekends: bool}
     */
    private static function improveSection(array $section): array
    {
        $start = self::normalizeTime((string) ($section['window_start'] ?? ''), self::IMPROVE_DEFAULTS['windowStart']);
        $end = self::normalizeTime((string) ($section['window_end'] ?? ''), self::IMPROVE_DEFAULTS['windowEnd']);
        // Ein Fenster, das nicht vorwärts läuft, ergibt keinen Sinn — dann die
        // Vorgabe, statt später eine leere Auswahl zu erzeugen.
        if (self::minutesOf($end) <= self::minutesOf($start)) {
            $start = self::IMPROVE_DEFAULTS['windowStart'];
            $end = self::IMPROVE_DEFAULTS['windowEnd'];
        }

        $perDay = (int) ($section['per_day'] ?? self::IMPROVE_DEFAULTS['perDay']);

        return [
            // NICHT (bool) casten: Der Wert kommt als Zeichenkette aus der INI
            // („false“, „0“, „off“), und jede nicht leere Zeichenkette wäre
            // true — der Schalter ließe sich nie ausschalten. FILTER_VALIDATE_
            // BOOLEAN versteht alle üblichen Schreibweisen, auch von Hand
            // eingetragene.
            'auto' => filter_var($section['auto'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'windowStart' => $start,
            'windowEnd' => $end,
            // Obergrenze als Schutz vor Vertippern (300 Freigaben am Tag wären
            // kein „natürliches Wachstum“ mehr, sondern eine Flut).
            'perDay' => max(1, min(50, $perDay)),
            // Samstag und Sonntag von der Terminierung ausnehmen. Fehlt der
            // Schlüssel (auch bei sonst vorhandener [improve]-Sektion), gilt die
            // Vorgabe „an“ — abschalten nur mit einem ausdrücklichen false.
            'skipWeekends' => filter_var($section['skip_weekends'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** „7:5“ → „07:05“; ungültige Angaben ergeben $fallback. */
    private static function normalizeTime(string $value, string $fallback): string
    {
        if (preg_match('/^\s*(\d{1,2})\s*:\s*(\d{1,2})\s*$/', $value, $m) !== 1) {
            return $fallback;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return $fallback;
        }

        return sprintf('%02d:%02d', $h, $i);
    }

    /** Minuten seit Mitternacht einer bereits normalisierten „HH:MM“-Angabe. */
    private static function minutesOf(string $time): int
    {
        [$h, $i] = array_map('intval', explode(':', $time));

        return $h * 60 + $i;
    }

    /**
     * Prüft die [hugo]-Sektion und löst die Pfade auf. Fehlt das Pflichtfeld
     * „source“, gilt die Sektion als unvollständig: Rückgabe null — die Site
     * bleibt nutzbar, nur „build“ steht nicht zur Verfügung. Das Hugo-Programm
     * (bin) wird hier NICHT gelesen; es steht zentral in der hugocms.ini.
     *
     * @return ?array{source: string, destination: string, minify: bool, clean: bool}
     */
    private static function hugoSection(array $section, string $baseDir): ?array
    {
        $source = trim((string) ($section['source'] ?? ''));
        if ($source === '') {
            return null;
        }
        $destination = trim((string) ($section['destination'] ?? ''));

        $source = self::resolve($source, $baseDir);
        $destination = $destination === ''
            ? $source . '/public'
            : self::resolve($destination, $baseDir);

        return [
            'source' => $source,
            'destination' => $destination,
            'minify' => (bool) ($section['minify'] ?? false),
            'clean' => (bool) ($section['clean'] ?? false),
        ];
    }

    /** Löst einen Pfad relativ zum Verzeichnis der Konfigurationsdatei auf. */
    private static function resolve(string $path, string $baseDir): string
    {
        return self::isAbsolute($path) ? $path : $baseDir . '/' . $path;
    }

    /** Zerlegt eine kommagetrennte Liste in getrimmte, nicht-leere Werte. */
    private static function toList(mixed $value): array
    {
        $parts = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')                      // Unix
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;   // Windows
    }
}
