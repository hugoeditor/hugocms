<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Liest Mount-Definitionen aus einer INI-Konfigurationsdatei. Format: je
 * [Sektion] ein Mount, der Sektionsname ist die interne ID (englisch wie alle
 * INI-Schlüssel; ältere Dateien tragen noch [projekt] — bleibt gültig, der
 * Name wird nirgends ausgewertet). Beispiel:
 *
 *   [content]
 *   path = /pfad/zum/hugo-projekt/content
 *   label = Inhalt
 *   accept = md, markdown, html, png, jpg
 *
 *   [layouts]
 *   path = /pfad/zum/hugo-projekt/layouts
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
 *   changelog_path         (optional) Zielpfad(e) der Protokollseite im
 *                          Content-Mount, kommagetrennt. Mehrere für
 *                          mehrsprachige Projekte (de/changelog.md,
 *                          en/changelog.md). Standard: changelog.md
 *   changelog              (optional) false schaltet das Änderungsprotokoll ab
 *                          (die Seite changelog.md im Content-Mount, die bei
 *                          jedem Versionsstand fortgeschrieben wird).
 *                          Standard: an.
 *   tag_label              (optional) Wort vor der Versionsnummer in der
 *                          Überschrift des Änderungsprotokolls („Ausgabe 12“).
 *                          Sprachabhängiger Text und deshalb konfigurierbar —
 *                          im Dialog kommt er vom Client, beim Cron von hier.
 *                          Leer = nur die Nummer. Standard: siehe unten.
 *
 * Reservierte Sektion [shop] (kein Mount): Shop-Erweiterung (Anbindung an
 * OpensourceERP). Ist sie eingeschaltet und ein Schlüssel hinterlegt, darf
 * OpensourceERP die shop*-Befehle dieser Webseite ohne Sitzung aufrufen
 * ({@see Shop\ShopKey}). Geschrieben wird die Sektion über die
 * Projekteinstellungen (shopsettingsset, shopkeycreate/shopkeydelete,
 * shopsigningkeyset/shopsigningkeydelete), nur von Administratoren.
 *   enabled     Shop-Erweiterung an (true) oder aus. Fehlt der Eintrag, gilt
 *               sie als eingeschaltet, wenn ein Schlüssel hinterlegt ist — so
 *               bleiben Shops aus der Zeit vor dem Schalter am Netz.
 *   key_hash    Hash des Schlüssels (sha256:…). Der Schlüssel selbst steht nirgends.
 *   key_hint    letzte vier Zeichen des Schlüssels, zum Wiedererkennen.
 *   key_created Zeitpunkt der Erzeugung (ISO 8601).
 *   Freigaben, jeweils relativ zur Hugo-Quelle, ohne .. und ohne versteckte
 *   Bestandteile ({@see shopGrantPath()}). Ein unbrauchbarer Eintrag gibt
 *   nichts frei (Hinweis SHOP-GRANT-UNUSABLE) — lieber steht der Shop, als
 *   dass die Anbindung stillschweigend an die Vorgabe schreibt:
 *   content_dir     Produktseiten (nur Markdown). Standard: content/de/produkt.
 *   category_groups Kategorieübersicht, genau eine .json-Datei.
 *                   Standard: data/category_groups.json.
 *   images          Produktbilder, aus denen HugoCMS die Vorschaubilder
 *                   erzeugt. Standard: static/images/products.
 *   thumbnails      Vorschaubilder, die HugoCMS schreibt.
 *                   Standard: static/images/thumbnails.
 *   Das Webseiten-Paket liegt fest in oserp-shop/ ({@see Shop\ShopSync::PACKAGE_DIR}).
 *   areas       wird nicht mehr ausgewertet (abgelöst durch die Freigaben,
 *               Hinweis SHOP-AREAS-OBSOLETE); das Speichern der Freigaben
 *               entfernt den Eintrag.
 *   signing_key (optional) öffentlicher Ed25519-Schlüssel von OpensourceERP,
 *               Base64. Nur damit nimmt die Anbindung die PHP-Einstiegspunkte
 *               des Pakets an, und nur signiert ({@see Shop\ShopSync::SIGNED_PHP}).
 *               Nie über die shop*-Befehle der Anbindung gesetzt.
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
    private const SHOP_SECTION = 'shop';

    /**
     * Freigaben der Shop-Anbindung: Schlüssel in [shop] => [Feld im Ergebnis,
     * eine Datei (true) statt eines Verzeichnisses, Vorgabe].
     */
    public const SHOP_GRANTS = [
        'content_dir' => ['contentDir', false, Shop\ShopSync::DEFAULT_CONTENT_DIR],
        'category_groups' => ['categoryGroups', true, Shop\ShopSync::DEFAULT_CATEGORY_GROUPS],
        'images' => ['images', false, Shop\ShopThumbnails::DEFAULT_IMAGES],
        'thumbnails' => ['thumbnails', false, Shop\ShopThumbnails::DEFAULT_THUMBNAILS],
    ];

    /** Alle reservierten Sektionsnamen — kein Mount darf so heißen. */
    private const RESERVED_SECTIONS = [
        self::HUGO_SECTION, self::LICENSE_SECTION, self::PAGESPEED_SECTION,
        self::LIVE_ANALYSIS_SECTION, self::SEO_REPORT_SECTION, self::IMPROVE_SECTION,
        self::CRON_SECTION, self::GIT_SECTION, self::SHOP_SECTION,
    ];

    /** Ist $name eine reservierte Sektion (kein Mount)? */
    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED_SECTIONS, true);
    }

    /**
     * Sektions-ID für einen neuen Ort, aus dem Verzeichnisnamen abgeleitet
     * (Hugo-Verzeichnisse heißen ohnehin englisch: content, static …). Nur
     * [a-z0-9_-]; reservierte und vergebene Namen bekommen eine Nummer, ein
     * unbrauchbarer Name wird „place“.
     *
     * @param list<string> $taken vorhandene Sektionsnamen
     */
    public static function newMountName(string $dir, array $taken): string
    {
        $base = strtolower(basename($dir));
        $base = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $base), '-_');
        if ($base === '') {
            $base = 'place';
        }
        $taken = array_map('strtolower', $taken);
        $name = $base;
        for ($i = 2; self::isReserved($name) || in_array($name, $taken, true); $i++) {
            $name = $base . '-' . $i;
        }

        return $name;
    }

    /** Vorgeschlagene Commit-Nachricht, wenn keine konfiguriert ist. */
    public const string GIT_COMMIT_MESSAGE_DEFAULT = 'Automatische Veröffentlichung terminierter Freigaben';

    /** Vorgeschlagene Nachricht für den Vorab-Commit offener Änderungen. */
    public const string GIT_COMMIT_MESSAGE_PENDING_DEFAULT = 'Offene Änderungen vor dem Build gesichert';

    /** Wort vor der Versionsnummer im Änderungsprotokoll. */
    public const string GIT_TAG_LABEL_DEFAULT = 'Ausgabe';

    /** Obergrenze dieses Wortes — es steht in einer Überschrift. */
    private const int GIT_TAG_LABEL_MAX = 40;

    /** Zielpfad des Änderungsprotokolls, wenn keiner konfiguriert ist. */
    public const string GIT_CHANGELOG_PATH_DEFAULT = ChangelogService::DEFAULT_FILE;

    /**
     * Höchstzahl der Zielpfade. Jeder Pfad ist eine Datei, die bei JEDEM
     * Versionsstand geschrieben wird — eine Obergrenze hält den Aufwand
     * überschaubar und begrenzt den Schaden eines Vertippers.
     */
    private const int GIT_CHANGELOG_PATHS_MAX = 12;

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
     *   shop: array{enabled: bool, keyHash: ?string, keyHint: ?string, keyCreated: ?string, contentDir: ?string, categoryGroups: ?string, images: ?string, thumbnails: ?string, signingKey: ?string},
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
            'changelogPaths' => [self::GIT_CHANGELOG_PATH_DEFAULT],
            'tagLabel' => self::GIT_TAG_LABEL_DEFAULT,
        ];
        $warnings = [];
        $shop = self::shopSection([], $configPath, $warnings);

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

            // Shop-Erweiterung (optional, pro Webseite).
            if (strtolower((string) $name) === self::SHOP_SECTION) {
                $shop = self::shopSection($section, $configPath, $warnings);
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
                // Zielpfade des Protokolls, relativ zum Content-Mount. Mehrere
                // für mehrsprachige Projekte, in denen jede Sprache ihre eigene
                // Seite braucht (content/de/…, content/en/…).
                $paths = self::changelogPaths($section['changelog_path'] ?? '');
                if ($paths === [] && trim((string) ($section['changelog_path'] ?? '')) !== '') {
                    $warnings[] = ['key' => 'GIT-CHANGELOG-PATH-INVALID', 'params' => [$configPath]];
                }
                $git = [
                    'autoCommit' => filter_var($section['auto_commit'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'commitMessage' => mb_substr($message, 0, self::GIT_MESSAGE_MAX),
                    'commitMessagePending' => mb_substr($pending, 0, self::GIT_MESSAGE_MAX),
                    'changelog' => filter_var($section['changelog'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'changelogPaths' => $paths === [] ? [self::GIT_CHANGELOG_PATH_DEFAULT] : $paths,
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
                // filter_var statt (bool): Ein in Anführungszeichen stehendes
                // "false" (so schreibt Config::updateSections) wäre sonst wahr.
                $options['readonly'] = filter_var($section['readonly'], FILTER_VALIDATE_BOOLEAN);
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
            'shop' => $shop,
            'warnings' => $warnings,
        ];
    }

    /**
     * Liest die [shop]-Sektion. Vom Schlüssel steht nur der Hash da; ein
     * leerer Wert zählt als „kein Schlüssel“.
     *
     * @param array<string, mixed> $section
     * @param list<array{key: string, params: list<mixed>}> $warnings
     * @return array{enabled: bool, keyHash: ?string, keyHint: ?string, keyCreated: ?string, contentDir: ?string, categoryGroups: ?string, images: ?string, thumbnails: ?string, signingKey: ?string}
     */
    private static function shopSection(array $section, string $configPath, array &$warnings): array
    {
        $hash = trim((string) ($section['key_hash'] ?? ''));
        $hint = trim((string) ($section['key_hint'] ?? ''));
        $created = trim((string) ($section['key_created'] ?? ''));
        $shop = [
            // Fehlt der Schalter, hängt er am Schlüssel: Shops aus der Zeit vor
            // dem Schalter laufen weiter, neue Webseiten bleiben aus, bis ein
            // Administrator die Erweiterung einschaltet.
            'enabled' => array_key_exists('enabled', $section)
                ? filter_var($section['enabled'], FILTER_VALIDATE_BOOLEAN)
                : $hash !== '',
            'keyHash' => $hash === '' ? null : $hash,
            'keyHint' => $hash === '' || $hint === '' ? null : $hint,
            'keyCreated' => $hash === '' || $created === '' ? null : $created,
            'signingKey' => null,
        ];
        foreach (self::SHOP_GRANTS as $key => [$field, $file, $default]) {
            $value = trim((string) ($section[$key] ?? ''));
            $shop[$field] = $value === '' ? $default : self::shopGrantPath($value, $file);
            // Vorschaubilder tragen den Namen ihres Produktbilds: im selben
            // Verzeichnis überschrieben sie es
            if ($field === 'thumbnails' && $shop[$field] !== null && $shop[$field] === $shop['images']) {
                $shop[$field] = null;
            }
            if ($shop[$field] === null) {
                // Unbrauchbar eingetragen: lieber nichts freigegeben als
                // stillschweigend die Vorgabe
                $warnings[] = ['key' => 'SHOP-GRANT-UNUSABLE', 'params' => [$key, $configPath]];
            }
        }
        if (array_key_exists('areas', $section)) {
            $warnings[] = ['key' => 'SHOP-AREAS-OBSOLETE', 'params' => [$configPath]];
        }
        $signing = trim((string) ($section['signing_key'] ?? ''));
        if ($signing !== '') {
            $shop['signingKey'] = Shop\ShopSync::normalizeSigningKey($signing);
            if ($shop['signingKey'] === null) {
                // Unbrauchbarer Schlüssel: dann eben kein PHP, aber sichtbar
                $warnings[] = ['key' => 'SHOP-SIGNING-KEY-INVALID', 'params' => [$configPath]];
            }
        }

        return $shop;
    }

    /**
     * Prüft eine Freigabe der Shop-Anbindung und bringt sie in die
     * gespeicherte Form: relativ zur Hugo-Quelle, mit / getrennt, ohne / am
     * Anfang und Ende. Abgewiesen werden absolute Pfade, .., versteckte
     * Bestandteile (.git, .htaccess), die Quelle selbst und Zeichen außer
     * Buchstaben, Ziffern, - _ . und Leerzeichen (die Mount-Datei setzt Werte
     * in Anführungszeichen); eine Datei ($file) muss auf .json enden.
     *
     * @return ?string null, wenn der Wert unbrauchbar ist
     */
    public static function shopGrantPath(string $value, bool $file): ?string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || self::isAbsolute($value) || ($file && str_ends_with($value, '/'))) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $value), static fn (string $s): bool => $s !== ''));
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.') || preg_match('/^[\p{L}\p{N}_\-. ]+$/u', $segment) !== 1) {
                return null;
            }
        }
        if ($segments === [] || ($file && strtolower(pathinfo(end($segments), PATHINFO_EXTENSION)) !== 'json')) {
            return null;
        }

        return implode('/', $segments);
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

    /**
     * Zerlegt `changelog_path` in bereinigte Zielpfade: relativ zur Wurzel des
     * Content-Mounts, ohne führenden Schrägstrich, ohne `..` (das führte aus
     * dem Mount heraus), ohne Doppelte und auf {@see GIT_CHANGELOG_PATHS_MAX}
     * begrenzt. Leerer Wert oder nichts Brauchbares ergibt eine leere Liste —
     * der Aufrufer setzt dann die Vorgabe.
     *
     * @return list<string>
     */
    private static function changelogPaths(mixed $value): array
    {
        $clean = [];
        foreach (self::toList($value) as $entry) {
            $rel = trim(str_replace('\\', '/', $entry), " \t/");
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $clean[$rel] = true;
            if (count($clean) >= self::GIT_CHANGELOG_PATHS_MAX) {
                break;
            }
        }

        return array_keys($clean);
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
