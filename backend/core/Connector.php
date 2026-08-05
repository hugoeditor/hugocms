<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Audit\AuditMailReport;
use HugoCMS\FileManager\Audit\AuditService;
use HugoCMS\FileManager\Audit\ContentQualityService;
use HugoCMS\FileManager\Audit\RuleCatalog;
use HugoCMS\FileManager\Audit\SourceGuesser;
use HugoCMS\FileManager\Auth\AuthInterface;
use HugoCMS\FileManager\Auth\SiteAwareInterface;
use HugoCMS\FileManager\Auth\UserAdminInterface;
use HugoCMS\FileManager\Auth\UserStore;
use HugoCMS\FileManager\Cron\Heartbeat;
use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\Review\FrontMatter;
use HugoCMS\FileManager\Review\ReviewStore;
use Throwable;

/**
 * Einstiegspunkt des Backends. Wird in einer selbst geschriebenen
 * index.php konfiguriert:
 *
 *   $connector = new Connector(['auth' => new SingleUser(...)]);
 *   $connector->mount('seiten', '/pfad/zu/content', ['label' => 'Inhalte']);
 *   $connector->run();
 */
final class Connector
{
    private readonly AuthInterface $auth;
    private readonly MountResolver $resolver;
    private readonly FileService $files;
    private readonly ?string $cors;
    private readonly Logger $logger;
    /** Konfiguriertes Sitzungsverzeichnis (für die Rechteprüfung in whoami). */
    private readonly ?string $sessionDir;

    /**
     * Pfad zur hugocms.ini, sofern der Connector daraus aufgebaut wurde.
     * null bei programmatischer Konfiguration (custom.php). Nur wenn gesetzt,
     * lässt sich die Konfiguration im laufenden Betrieb ändern (reconfigure).
     */
    private readonly ?string $configPath;

    /** Hinweise zur Einrichtung (z. B. fehlende Verzeichnisse), an den Client gemeldet. */
    private array $setupWarnings = [];

    /**
     * Webseiten-spezifischer Teil des Hugo-Aufrufs (source, destination,
     * minify, clean) — aus der [hugo]-Sektion der Mount-Konfiguration oder der
     * Option "hugo". null = diese Webseite hat keine Hugo-Konfiguration.
     */
    private ?array $hugo = null;

    /**
     * Zentraler Pfad zum Hugo-Programm — aus der [hugo]-Sektion der
     * hugocms.ini oder der Option "hugoBin". Installationsweit nur einer.
     * null = kein Hugo konfiguriert. Build ist nur möglich, wenn sowohl
     * $hugoBin als auch der Webseiten-Teil $hugo gesetzt sind.
     */
    private ?string $hugoBin = null;

    /**
     * Global aktiviertes --cleanDestinationDir ([hugo] clean in der hugocms.ini).
     * Ergänzt das webseitenbezogene clean-Flag aus der Mount-Konfiguration: Beim
     * Build genügt eine der beiden Quellen, damit das Zielverzeichnis geleert wird.
     */
    private bool $hugoClean = false;

    /**
     * KI-Assistent-Konfiguration aus der [ai]-Sektion der hugocms.ini.
     * apiKey=null → Assistent deaktiviert. writeMode: readonly|confirm|auto.
     *
     * modelCron/modelAudit: getrennte Modelle für Cron-Verbesserer bzw.
     * Content-Qualitätsprüfung (fallen ohne Angabe auf `model` zurück).
     *
     * models: in der INI hinterlegte Auswahlliste der Oberfläche (leer = die
     * fest verdrahtete Liste des Clients gilt).
     *
     * @var array{apiKey: ?string, model: string, modelCron: string, modelAudit: string, writeMode: string, forceThinking: bool, forceThinkingCron: bool, models: list<string>}
     */
    private array $ai = [
        'apiKey' => null,
        'model' => 'claude-opus-4-8',
        'modelCron' => 'claude-opus-4-8',
        'modelAudit' => 'claude-opus-4-8',
        'writeMode' => 'confirm',
        'forceThinking' => false,
        'forceThinkingCron' => false,
        'models' => [],
    ];

    /**
     * Externe Pro-Dienste aus der [services]-Sektion der hugocms.ini:
     * der seo-success-Dienst (ein Schlüssel für Spracheingabe UND Live-Analyse)
     * sowie der (globale) Google-Schlüssel für den PageSpeed-Check.
     * serviceKey/serviceUrl = null → seo-success-Funktionen aus.
     * pagespeedKey = null → PageSpeed ohne eigenen Schlüssel (kleines Kontingent).
     *
     * @var array{serviceKey: ?string, serviceUrl: ?string, pagespeedKey: ?string}
     */
    private array $services = ['serviceKey' => null, 'serviceUrl' => null, 'pagespeedKey' => null];

    /**
     * Zu messende Live-Adresse des PageSpeed-Checks — PRO WEBSEITE, daher aus der
     * Mount-Konfiguration ([pagespeed] url), nicht aus der zentralen hugocms.ini.
     * null → noch keine gespeichert; das Panel schlägt dann die aus der
     * Hugo-baseURL erkannte Adresse vor. Beim Messstart wird der Wert geschrieben.
     */
    private ?string $pagespeedUrl = null;

    /**
     * Zu prüfende Live-Adresse der Live-Analyse (seo-success) — PRO WEBSEITE, aus
     * der Mount-Konfiguration ([live_analysis] url). Eigene Sektion, unabhängig von
     * [pagespeed]. null → noch keine gespeichert; das Panel schlägt dann die aus
     * der Hugo-baseURL erkannte Adresse vor. Beim Start wird der Wert geschrieben.
     */
    private ?string $liveAnalysisUrl = null;

    /**
     * Globale [user]-Vorgaben aus der hugocms.ini (Sitzungsdauer, Inhaltsbreite,
     * Werkzeugleiste). Sie gelten vor der Anmeldung und beim Einzelbenutzer;
     * beim Mehrbenutzer bringt jedes Konto eigene Werte mit — die liefert der
     * Treiber (siehe {@see userPrefs()}).
     */
    private array $user = [
        'sessionLifetime' => 28800,
        'contentWidth' => 1200,
        'toolbarCollapsed' => false,
        'updateLastmod' => null,
    ];

    /** Zwischenspeicher der wirksamen Einstellungen (einmal je Request). */
    private ?array $userPrefs = null;

    /**
     * E-Mail-Versand aus der [mail]-Sektion der hugocms.ini (Gesundheitscheck-
     * Benachrichtigung). configured=false → kein Versand möglich.
     *
     * @var array{configured: bool, host: ?string, port: int, security: string, user: ?string, pass: ?string, from: ?string, to: ?string}
     */
    private array $mail = ['configured' => false, 'host' => null, 'port' => 587, 'security' => 'tls', 'user' => null, 'pass' => null, 'from' => null, 'to' => null];

    /**
     * SEO-Bericht aus der [seo_report]-Sektion der hugocms.ini — GLOBAL, also
     * für alle Webseiten dieser Installation. Ergänzt die fest verdrahteten
     * Ausschlüsse des Audits um weitere public-relative Pfad-Präfixe und
     * einzelne Dateien. Ziel des Konfigurationsformulars (cmdReconfigure).
     *
     * @var array{excludePrefixes: list<string>, excludeFiles: list<string>}
     */
    private array $seoReport = ['excludePrefixes' => [], 'excludeFiles' => []];

    /**
     * Automatikmodus des Cron-Verbesserers aus der [improve]-Sektion der
     * Mount-Konfiguration (pro Webseite). Ist `auto` an, terminiert der Cron
     * jeden erzeugten Entwurf gleich selbst — zufällig verteilt im Tagesfenster,
     * höchstens `perDay` Stück je Tag.
     *
     * @var array{auto: bool, windowStart: string, windowEnd: string, perDay: int, skipWeekends: bool}
     */
    private array $improve = ['auto' => false, 'windowStart' => '07:00', 'windowEnd' => '16:00', 'perDay' => 3, 'skipWeekends' => true];

    /**
     * Pausenschalter der drei Cron-Skripte aus der [cron]-Sektion der
     * Mount-Konfiguration (pro Webseite). Ist ein Schalter an, tut das
     * zugehörige CLI-Skript beim Start nichts — so lässt sich ein Cron-Job
     * aussetzen, ohne die Crontab des Hosters zu ändern.
     *
     * @var array{pauseBuild: bool, pauseImprove: bool, pauseHealthcheck: bool}
     */
    private array $cronPause = ['pauseBuild' => false, 'pauseImprove' => false, 'pauseHealthcheck' => false];

    /**
     * Automatischer Commit rund um die zeitgesteuerte Veröffentlichung, aus der
     * [git]-Sektion der Mount-Konfiguration (pro Webseite). Ist `autoCommit` an
     * und das Quellverzeichnis ein Repository, committet der Cron nach dem
     * Einspielen fälliger Freigaben mit `commitMessage` (+ Datum) und sichert
     * VOR dem Build offene Änderungen mit `commitMessagePending` (+ Datum).
     *
     * @var array{autoCommit: bool, commitMessage: string, commitMessagePending: string}
     */
    private array $gitAuto = [
        'autoCommit' => false,
        'commitMessage' => MountConfig::GIT_COMMIT_MESSAGE_DEFAULT,
        'commitMessagePending' => MountConfig::GIT_COMMIT_MESSAGE_PENDING_DEFAULT,
    ];

    /**
     * Wie weit die automatische Terminierung nach einem freien Platz sucht.
     * Bei kleiner Tagesmenge und großem Rückstau wandern Freigaben weit nach
     * vorn; irgendwo muss die Suche enden, sonst liefe sie bei erschöpftem
     * Kontingent endlos.
     */
    private const int AUTO_SCHEDULE_HORIZON_DAYS = 90;

    /**
     * Dasselbe aus der [seo_report]-Sektion der Mount-Konfiguration — nur für
     * DIESE Webseite. Bewusst getrennt gehalten statt beim Laden vermischt: Die
     * Aufrufreihenfolge von Config und mountsFromFile ist nicht festgelegt, und
     * das Konfigurationsformular darf ausschließlich die globale Sektion
     * überschreiben. Zusammengelegt wird erst in {@see auditStore}.
     *
     * @var array{excludePrefixes: list<string>, excludeFiles: list<string>}
     */
    private array $seoReportSite = ['excludePrefixes' => [], 'excludeFiles' => []];

    /**
     * Roher Pro-Lizenzschlüssel dieser WEBSEITE (aus der [license]-Sektion der
     * Mount-Konfiguration) oder null. Schaltet die Pro-Funktionen frei (derzeit
     * Git). Da die Lizenz pro Webseite gilt, steht sie in mounts/<hash>.ini —
     * nicht installationsweit. Lazy zu einer License-Instanz aufgelöst.
     */
    private ?string $licenseKey = null;

    /** Zwischengespeicherte License-Instanz (an die Domain der Anfrage gebunden). */
    private ?License $licenseObj = null;

    /**
     * Pfad der geladenen Mount-Konfiguration (mounts/<hash>.ini bzw. Rückfall
     * mounts.ini). Ziel der Lizenz-Aktivierung. null bei programmatischer
     * Konfiguration (custom.php) — dann ist keine Aktivierung möglich.
     */
    private ?string $mountsPath = null;

    /**
     * Verzeichnis der Hilfe-/Wissensdatenbank (Markdown je Sektion/Sprache).
     * Standard: backend/help (relativ zu backend/core). Frei, nicht Pro.
     */
    private readonly string $helpDir;

    public function __construct(array $options)
    {
        // Hauptkonfiguration (hugocms.ini) ggf. zuerst einlesen — daraus
        // stammen Log-Ziel, Sitzungsverzeichnis und Authentifizierung. Das
        // reine Einlesen hat keine Seiteneffekte; Fehler hier (Datei fehlt /
        // ungültig) werden direkt als JSON beantwortet, da der Logger und die
        // Fehler-Handler noch nicht stehen.
        $authConfig = null;
        $authOptions = [];
        $sessionPath = null;
        $this->configPath = isset($options['config']) ? (string) $options['config'] : null;
        if (isset($options['config'])) {
            $cfg = Config::load((string) $options['config']);

            // Sitzungsverzeichnis VOR dem ersten session_start() setzen
            // (dieses erfolgt bei der Auth-Erzeugung weiter unten). Fehlt das
            // Verzeichnis, bleibt es bei PHP-Voreinstellung — mit Hinweis.
            $sessionPath = $cfg['session']['path'];
            if ($sessionPath !== null) {
                if (is_dir($sessionPath)) {
                    session_save_path($sessionPath);
                } else {
                    $this->setupWarnings[] = ['key' => 'SESSION-DIR-MISSING', 'params' => [$sessionPath]];
                }
            }

            // Log nur aus der Datei übernehmen, wenn nicht explizit gesetzt.
            $options['log'] ??= $cfg['log']['file'];
            $options['logLevel'] ??= $cfg['log']['level'] ?? 'error';
            $options['logMaxBytes'] ??= $cfg['log']['maxBytes'] ?? 1_048_576;
            $options['logKeep'] ??= $cfg['log']['keep'] ?? 3;
            $options['hugoBin'] ??= $cfg['hugoBin'];
            $this->hugoClean = $cfg['hugoClean'];
            $this->ai = $cfg['ai'];
            $this->services = $cfg['services'];
            $this->user = $cfg['user'];
            $this->mail = $cfg['mail'];
            $this->seoReport = $cfg['seoReport'];
            $authConfig = $cfg['auth'];
            // Globale [user]-Einstellungen an den Auth-Treiber durchreichen
            // (z. B. Sitzungsdauer für SingleUser).
            $authOptions = ['sessionLifetime' => $cfg['user']['sessionLifetime']];
        }
        $this->sessionDir = $sessionPath;

        // Logger und Fehler-Handler: danach werden auch Fehler in der Auth-
        // Erzeugung, in mount() und im weiteren Konstruktor erfasst.
        $this->logger = new Logger(
            $options['log'] ?? null,
            $options['logLevel'] ?? 'error',
            (int) ($options['logMaxBytes'] ?? 1_048_576),
            (int) ($options['logKeep'] ?? 3),
        );
        $this->registerErrorHandlers();

        // Fehlt das Log-Verzeichnis, schreibt der Logger ins Server-Log — Hinweis.
        $logFile = $options['log'] ?? null;
        if ($logFile !== null && !is_dir(dirname($logFile))) {
            $this->setupWarnings[] = ['key' => 'LOG-DIR-MISSING', 'params' => [dirname($logFile)]];
        }

        // Authentifizierung: entweder direkt übergeben oder aus der
        // Konfiguration über die AuthFactory erzeugen (driver-abhängig).
        if (!isset($options['auth']) && $authConfig !== null) {
            $factory = new AuthFactory();
            foreach ((array) ($options['authDrivers'] ?? []) as $name => $driverFactory) {
                $factory->register((string) $name, $driverFactory);
            }
            $options['auth'] = $factory->create($authConfig, $this->configPath, $authOptions);
        }

        if (!isset($options['auth']) || !$options['auth'] instanceof AuthInterface) {
            throw new ApiException('ECONFIG', 500, 'AUTH-MISSING');
        }

        $this->auth = $options['auth'];

        // Treiber, deren Entscheidungen an der aufgerufenen Webseite hängen
        // (Mehrbenutzer: Zuordnung und Pro-Schranke), bekommen den Kontext hier.
        // Der Lizenzstatus kommt als Rückruf, weil die Mount-Konfiguration erst
        // nach dem Konstruktor geladen wird (mountsFromFile).
        // Bezugsgröße ist der HOST, nicht der volle SiteKey: Daran hängt schon
        // die Lizenz, und ein Umzug des Endpunkts von /cms-api nach /hugocms-api
        // soll keine Zuordnung entwerten.
        if ($this->auth instanceof SiteAwareInterface) {
            $this->auth->bindSite(
                SiteKey::host($_SERVER),
                fn (): bool => $this->license()->isPro(),
            );
        }

        $this->resolver = new MountResolver();
        $this->files = new FileService(
            $this->resolver,
            $options['editable'] ?? ['html', 'htm', 'md', 'markdown', 'txt', 'css', 'js', 'json', 'xml', 'yaml', 'yml', 'svg', 'toml'],
            $options['maxEditableBytes'] ?? 5_242_880,
            $options['maxUploadBytes'] ?? 52_428_800,
        );
        $this->cors = $options['cors'] ?? null;

        // Programmatische Hugo-Konfiguration (custom.php); die [hugo]-Sektion
        // einer später geladenen Mount-Datei hat Vorrang.
        if (isset($options['hugo']) && is_array($options['hugo'])) {
            $this->hugo = $options['hugo'];
            // Abwärtskompatibel: trug die programmatische Konfiguration den
            // Programmpfad noch im Webseiten-Teil, als zentralen bin übernehmen.
            if (!isset($options['hugoBin']) && isset($options['hugo']['bin'])) {
                $options['hugoBin'] = (string) $options['hugo']['bin'];
            }
        }
        $this->hugoBin = isset($options['hugoBin']) ? (string) $options['hugoBin'] : null;

        // Programmatisch gesetzter Lizenzschlüssel (custom.php). Im INI-Betrieb
        // stammt er aus der Mount-Konfiguration (siehe mountsFromFile).
        if (isset($options['licenseKey'])) {
            $this->licenseKey = (string) $options['licenseKey'];
        }

        // Hilfe-Verzeichnis: Option "help" oder Standard backend/help.
        $this->helpDir = isset($options['help'])
            ? (string) $options['help']
            : dirname(__DIR__) . '/help';
    }

    /**
     * Registriert einen Mount. Gibt $this für Verkettung zurück.
     */
    public function mount(string $name, string $path, array $options = []): self
    {
        $this->resolver->add(new Mount(
            name: $name,
            path: $path,
            label: $options['label'] ?? $name,
            permissions: $options['permissions'] ?? null,
            accept: $options['accept'] ?? null,
            readonly: $options['readonly'] ?? false,
        ));

        return $this;
    }

    /**
     * Registriert Mounts aus einer INI-Konfigurationsdatei. Jede [Sektion] ist
     * ein Mount (Sektionsname = ID); relative Pfade gelten relativ zur Datei.
     * Format und Felder siehe MountConfig. Lässt sich mit mount() kombinieren.
     * Gibt $this für Verkettung zurück.
     */
    public function mountsFromFile(string $configPath): self
    {
        $config = MountConfig::load($configPath);
        foreach ($config['mounts'] as $spec) {
            $this->mount($spec['name'], $spec['path'], $spec['options']);
        }
        // Pro-Lizenz dieser Webseite und das Ziel künftiger Aktivierungen.
        $this->mountsPath = $configPath;
        if ($config['license'] !== null) {
            $this->licenseKey = $config['license'];
        }
        if ($config['hugo'] !== null) {
            $this->hugo = $config['hugo'];
            // Die Webseite ist auf Veröffentlichen ausgelegt (source gesetzt),
            // aber das zentrale Hugo-Programm fehlt in der hugocms.ini —
            // sichtbar machen, sonst verschwindet der Knopf kommentarlos.
            if ($this->hugoBin === null) {
                $this->addSetupWarning('HUGO-BIN-NOT-CONFIGURED');
            }
        }
        // Gespeicherte PageSpeed-Adresse dieser Webseite (falls schon gesetzt).
        $this->pagespeedUrl = $config['pagespeed'];
        // Gespeicherte Live-Analyse-Adresse dieser Webseite (falls schon gesetzt).
        $this->liveAnalysisUrl = $config['liveAnalysis'];
        // Ausschlüsse des SEO-Berichts NUR für diese Webseite; sie ergänzen die
        // globalen aus der hugocms.ini (siehe auditStore).
        $this->seoReportSite = $config['seoReport'];
        // Automatikmodus des Cron-Verbesserers (Fenster + Tagesmenge).
        $this->improve = $config['improve'];
        // Pausenschalter der drei Cron-Skripte.
        $this->cronPause = $config['cron'];
        // Automatischer Commit nach der Veröffentlichung.
        $this->gitAuto = $config['git'];
        foreach ($config['warnings'] as $warning) {
            $this->addSetupWarning($warning['key'], $warning['params']);
        }

        return $this;
    }

    /**
     * Fügt einen Einrichtungs-Hinweis hinzu, der über whoami an den Client
     * gemeldet wird (z. B. Rückfall auf die Standard-mounts.ini). Für Hinweise
     * aus der Boot-Schicht, die nicht im Konstruktor entstehen.
     */
    public function addSetupWarning(string $key, array $params = []): self
    {
        $this->setupWarnings[] = ['key' => $key, 'params' => array_values($params)];

        return $this;
    }

    /**
     * Schreibt eine Warnung ins Log — etwa den ausführlichen Kontext zu einem
     * an den Client gemeldeten Kurzhinweis. Für Meldungen aus der Boot-Schicht.
     */
    public function logWarning(string $message): self
    {
        $this->logger->warning($message);

        return $this;
    }

    /**
     * Schreibt eine Meldung auf Stufe „debug" ins Log — für erwartbare,
     * betrieblich unkritische Vorgänge (z. B. Rückfall auf die Standard-
     * mounts.ini), die pro Request anfallen und das Log sonst zumüllen.
     */
    public function logDebug(string $message): self
    {
        $this->logger->debug($message);

        return $this;
    }

    /**
     * Liest die Anfrage, führt den Befehl aus und gibt JSON zurück.
     */
    public function run(): void
    {
        $this->applyCors();

        try {
            $request = $this->parseRequest();
            $cmd = (string) ($request['cmd'] ?? '');

            $data = match ($cmd) {
                'whoami' => $this->cmdWhoami(),
                'login' => $this->cmdLogin($request),
                'logout' => $this->cmdLogout(),
                'mounts' => $this->cmdMounts(),
                'list' => $this->cmdList($request),
                'read' => $this->cmdRead($request),
                'write' => $this->cmdWrite($request),
                'mkdir' => $this->cmdMkdir($request),
                'newfile' => $this->cmdNewFile($request),
                'rename' => $this->cmdRename($request),
                'delete' => $this->cmdDelete($request),
                'copy' => $this->cmdCopy($request),
                'move' => $this->cmdMove($request),
                'upload' => $this->cmdUpload($request),
                'writeimage' => $this->cmdWriteImage($request),
                'download' => $this->cmdDownload($request),
                'raw' => $this->cmdRaw($request),
                'thumb' => $this->cmdThumb($request),
                'search' => $this->cmdSearch($request),
                'linkscan' => $this->cmdLinkScan($request),
                'trashlist' => $this->cmdTrashList(),
                'restore' => $this->cmdRestore($request),
                'emptytrash' => $this->cmdEmptyTrash($request),
                'build' => $this->cmdBuild(),
                'assistant' => $this->cmdAssistant($request),
                'assistantping' => $this->cmdAssistantPing(),
                'assistantimprove' => $this->cmdAssistantImprove($request),
                'assistantfix' => $this->cmdAssistantFix($request),
                'speech' => $this->cmdSpeech($request),
                'serviceverify' => $this->cmdServiceVerify($request),
                'pagespeed' => $this->cmdPageSpeed($request),
                'pagespeedlatest' => $this->cmdPageSpeedLatest(),
                'liveanalyze' => $this->cmdLiveAnalyze($request),
                'liveanalyzestatus' => $this->cmdLiveAnalyzeStatus($request),
                'liveanalyzecancel' => $this->cmdLiveAnalyzeCancel($request),
                'liveanalyzelatest' => $this->cmdLiveAnalyzeLatest(),
                'liveanalyzehistory' => $this->cmdLiveAnalyzeHistory($request),
                'liveanalyzeexport' => $this->cmdLiveAnalyzeExport($request),
                'config' => $this->cmdConfig(),
                'reconfigure' => $this->cmdReconfigure($request),
                'aimodels' => $this->cmdAiModels(),
                'projectconfig' => $this->cmdProjectConfig(),
                'projectreconfigure' => $this->cmdProjectReconfigure($request),
                'improveauto' => $this->cmdImproveAuto($request),
                'setuserprefs' => $this->cmdSetUserPrefs($request),
                'users' => $this->cmdUsers(),
                'usercreate' => $this->cmdUserCreate($request),
                'userupdate' => $this->cmdUserUpdate($request),
                'userpassword' => $this->cmdUserPassword($request),
                'userdelete' => $this->cmdUserDelete($request),
                'account' => $this->cmdAccount($request),
                'license' => $this->cmdLicense(),
                'activate' => $this->cmdActivate($request),
                'status' => $this->cmdStatus(),
                'statuscheck' => $this->cmdStatusCheck(),
                'statuslog' => $this->cmdStatusLog($request),
                'statuslogrotate' => $this->cmdStatusLogRotate(),
                'help' => $this->cmdHelp($request),
                'gitstatus' => $this->cmdGitStatus(),
                'gitlog' => $this->cmdGitLog($request),
                'gitdiff' => $this->cmdGitDiff($request),
                'gitcommit' => $this->cmdGitCommit($request),
                'gitpush' => $this->cmdGitPush(),
                'gitreset' => $this->cmdGitReset($request),
                'audit' => $this->cmdAudit(),
                'auditlist' => $this->cmdAuditList(),
                'auditget' => $this->cmdAuditGet($request),
                'auditdelete' => $this->cmdAuditDelete($request),
                'auditdeleteothers' => $this->cmdAuditDeleteOthers(),
                'auditcontent' => $this->cmdAuditContent($request),
                'auditcontentlist' => $this->cmdAuditContentList(),
                'auditcontentget' => $this->cmdAuditContentGet($request),
                'auditcontentreport' => $this->cmdAuditContentReport($request),
                'auditcontentrequeue' => $this->cmdAuditContentRequeue($request),
                'auditcontentqueue' => $this->cmdAuditContentQueue($request),
                'auditcontentupdate' => $this->cmdAuditContentUpdate($request),
                'auditcontentdelete' => $this->cmdAuditContentDelete($request),
                'reviewsave' => $this->cmdReviewSave($request),
                'reviewlist' => $this->cmdReviewList(),
                'reviewget' => $this->cmdReviewGet($request),
                'reviewapprove' => $this->cmdReviewApprove($request),
                'reviewdiscard' => $this->cmdReviewDiscard($request),
                default => throw ApiException::badRequest('UNKNOWN-COMMAND', [$cmd]),
            };

            Response::ok($data);
        } catch (ApiException $e) {
            $this->logger->warning($e->getMessage(), ['code' => $e->errorCode(), 'cmd' => $cmd ?? null]);
            Response::fromException($e);
        } catch (Throwable $e) {
            $this->logger->exception($e);
            Response::error('EINTERNAL', null, 500);
        }
    }

    /**
     * Fängt Fehler ab, die AUSSERHALB von run() entstehen — vor allem
     * Konfigurationsfehler in mount() (etwa ein nicht existierender Pfad) und
     * fatale PHP-Fehler. Ohne diese Handler endeten solche Fälle als nackter
     * HTTP 500 ohne jede Spur; jetzt stehen sie im Log und kommen als
     * sauberes JSON beim Client an.
     */
    private function registerErrorHandlers(): void
    {
        set_exception_handler(function (Throwable $e): void {
            if ($e instanceof ApiException) {
                $this->logger->error('Unbehandelt: ' . $e->getMessage(), [
                    'code' => $e->errorCode(),
                    'at' => $e->getFile() . ':' . $e->getLine(),
                ]);
                Response::fromException($e);
            }
            $this->logger->exception($e);
            Response::error('EINTERNAL', null, 500);
        });

        // Warnungen/Notices protokollieren, aber PHP normal weiterarbeiten lassen.
        set_error_handler(function (int $no, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false; // mit @ unterdrückt
            }
            $this->logger->warning($message, ['at' => $file . ':' . $line]);
            return false;
        });

        // Fatale Fehler (z. B. fehlende Erweiterung) beim Shutdown festhalten.
        register_shutdown_function(function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logger->error('Fataler Fehler: ' . $err['message'], ['at' => $err['file'] . ':' . $err['line']]);
                if (!headers_sent()) {
                    Response::error('EFATAL', null, 500);
                }
            }
        });
    }

    /**
     * Protokolliert eine Ausnahme im Anwendungslog (immer auf Stufe „error",
     * also unabhängig von der konfigurierten Log-Stufe sichtbar). Gedacht für
     * CLI-Einstiegspunkte (Cron), die Ausnahmen selbst abfangen und dadurch den
     * globalen set_exception_handler NICHT auslösen — ohne diesen Aufruf stünde
     * der Fehler nur auf STDERR (Cron-Mail), nicht in hugocms.log.
     */
    public function logException(Throwable $e): void
    {
        if ($e instanceof ApiException) {
            $this->logger->error('Cron abgebrochen: ' . $e->getMessage(), [
                'code' => $e->errorCode(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return;
        }
        $this->logger->exception($e);
    }

    // --- Befehle -----------------------------------------------------------

    private function cmdWhoami(): array
    {
        // Kritische Rechte VOR dem Login melden: Ist das Sitzungsverzeichnis
        // nicht les-/beschreibbar, kann PHP die Anmelde-Session nicht
        // speichern. Der Login scheint zu klappen, die Folgeanfrage hat aber
        // keine Session mehr und endet mit einem irreführenden 401. Hier wird
        // stattdessen die wahre Ursache gemeldet.
        if ($this->sessionDir !== null && is_dir($this->sessionDir)
            && (!is_readable($this->sessionDir) || !is_writable($this->sessionDir))) {
            throw new ApiException('ESESSION', 500, null, [$this->sessionDir]);
        }

        // Die Hugo-baseURL einmal ermitteln (liest die Projekt-Konfiguration von
        // der Platte) und für beide Felder unten nutzen.
        $baseUrl = $this->hugo !== null
            ? AuditService::detectBaseUrl((string) $this->hugo['source'])
            : null;

        return [
            'authenticated' => $this->auth->isAuthenticated(),
            'user' => $this->auth->currentUser(),
            'warnings' => $this->setupWarnings,
            // CSRF-Token für alle Schreibbefehle (Header X-CSRF-Token).
            'csrf' => $this->csrfToken(),
            // Ist ein Hugo-Aufruf möglich? Nötig sind das zentrale Programm
            // (hugocms.ini) UND der Webseiten-Teil (source) der Mount-Konfig.
            'buildable' => $this->hugo !== null && $this->hugoBin !== null,
            // Lässt sich die Konfiguration im laufenden Betrieb EINSEHEN? Das
            // setzt nur voraus, dass der Connector aus einer hugocms.ini
            // aufgebaut wurde. Ob das Konto auch SPEICHERN darf, sagt
            // `manageConfig` — der Dialog sperrt seine Felder danach.
            'reconfigurable' => $this->configPath !== null,
            // Die Einstellungen DIESER Webseite (SEO-Ausschlüsse, Verbesserer,
            // Cron-Pausen, Auto-Commit, Analyse-Adressen) stehen JEDEM
            // angemeldeten Konto offen — sie gehören zur redaktionellen Arbeit
            // an der Webseite, nicht zur Verwaltung der Installation.
            // Voraussetzung ist nur, dass die Mounts aus einer Datei stammen
            // (bei programmatischer Konfiguration über custom.php gibt es keine
            // Datei zum Schreiben).
            'projectConfigurable' => $this->mountsPath !== null,
            // KI-Assistent: aktiv, wenn ein API-Schlüssel konfiguriert ist.
            'ai' => [
                'enabled' => $this->ai['apiKey'] !== null,
                'model' => $this->ai['model'],
                'writeMode' => $this->ai['writeMode'],
                // Auswahlliste des Assistenten-Panels. Leer = der Client nutzt
                // seine eigene Liste (siehe util/aiModels.js).
                'models' => $this->ai['models'],
            ],
            // Einstellungen aus [user]: die des angemeldeten Kontos, sonst die
            // globalen Vorgaben. Der Client merkt darin selbst nach, was der
            // Benutzer mit der Maus einstellt (Befehl setuserprefs).
            'ui' => $this->uiState(),
            // Kontenverwaltung: nur beim Mehrbenutzer-Treiber und nur für
            // Administratoren. Der Client blendet den Menüpunkt danach ein.
            'manageUsers' => $this->auth instanceof UserAdminInterface
                && $this->auth->can('users.manage'),
            // Darf dieses Konto konfigurieren? Bewusst getrennt von
            // `reconfigurable`, das zusätzlich eine schreibbare hugocms.ini
            // verlangt: Der Konfigurationsdialog sperrt darüber seine Felder,
            // auch wenn er auf anderem Weg geöffnet wird.
            'manageConfig' => $this->auth->can('config.manage'),
            // Pro-Edition (Git u. Ä.). 'configured' meldet einen hinterlegten,
            // ggf. ungültigen Schlüssel (falsche Domain) — für einen Hinweis im
            // Client. Der Schlüssel selbst wird nie zurückgegeben.
            'license' => $this->license()->info(),
            // Lässt sich eine Lizenz aktivieren? Nur, wenn eine Mount-Datei
            // geladen wurde (mounts/<hash>.ini bzw. mounts.ini) — dorthin wird
            // geschrieben. Bei custom.php (programmatisch) nicht möglich, und
            // nur für Konten, die konfigurieren dürfen.
            'licensable' => $this->mountsPath !== null && $this->auth->can('config.manage'),
            // Git ist nur nutzbar, wenn die Webseite ein Hugo-Projekt hat
            // (dort liegt das Repository) UND eine gültige Pro-Lizenz vorliegt.
            'git' => $this->hugo !== null && $this->license()->isPro(),
            // Das SEO-Audit hat dieselbe Voraussetzung wie Git: Pro-Lizenz und
            // ein konfiguriertes Hugo-Projekt (für public/ und content/).
            'audit' => $this->hugo !== null && $this->license()->isPro(),
            // Die LLM-Content-Prüfung braucht zusätzlich einen konfigurierten
            // KI-Schlüssel ([ai] api_key).
            'auditContent' => $this->hugo !== null && $this->license()->isPro() && $this->ai['apiKey'] !== null,
            // Spracheingabe (Pro): der seo-success-Dienst muss konfiguriert sein
            // ([services] service_key/service_url, Rückfall speech_*) UND eine
            // gültige Pro-Lizenz vorliegen. Unabhängig vom Hugo-Projekt.
            'speech' => $this->license()->isPro()
                && $this->services['serviceKey'] !== null
                && $this->services['serviceUrl'] !== null,
            // PageSpeed-Check (Pro): dieselbe Voraussetzung wie das SEO-Audit —
            // Pro-Lizenz und ein Hugo-Projekt (für die baseURL-Vorbelegung). Die
            // zu messende Adresse wird IM Panel eingegeben und dort gespeichert,
            // ist also KEIN Freischalt-Merkmal (das Panel ist immer sichtbar).
            'pagespeed' => $this->hugo !== null && $this->license()->isPro(),
            // Gespeicherte bzw. aus der Hugo-baseURL erkannte Live-Adresse für die
            // Vorbelegung des PageSpeed-Eingabefeldes.
            'pagespeedUrl' => $this->pagespeedUrl ?? '',
            'pagespeedUrlDetected' => $baseUrl ?? '',
            // Live-Analyse (Pro): braucht — anders als PageSpeed — zusätzlich den
            // konfigurierten seo-success-Dienst (ein Schlüssel für Sprache und
            // Analyse). Unabhängig vom PageSpeed-Reiter; der Benutzer wählt.
            'liveAnalysis' => $this->hugo !== null && $this->license()->isPro()
                && $this->services['serviceKey'] !== null
                && $this->services['serviceUrl'] !== null,
            // Gespeicherte Live-Analyse-Adresse dieser Webseite (eigene Sektion).
            'liveAnalysisUrl' => $this->liveAnalysisUrl ?? '',
            // Aus der Hugo-baseURL erkannte Adresse — gemeinsame Vorbelegung für
            // PageSpeed UND Live-Analyse (Eigenschaft der Webseite, nicht der Prüfung).
            'siteUrlDetected' => $baseUrl ?? '',
            // Der Rechnername der baseURL (z. B. dev.opensourceerp.dev) benennt
            // die Webseite im Browser-Tab. Leer, wenn das Projekt keine baseURL
            // führt — der Client bleibt dann beim allgemeinen Titel.
            'siteHost' => $baseUrl !== null ? (parse_url($baseUrl, PHP_URL_HOST) ?: '') : '',
            // Gestaffelte Veröffentlichung: Entwürfe zur Freigabe setzen
            // Hugos draft/publishDate voraus, also ein konfiguriertes Hugo-
            // Projekt. Keine Pro-Bindung — der Entwurf-Modus ist eine allgemeine
            // Sicherheitsfunktion (auch der Editor-Button nutzt ihn).
            'review' => $this->hugo !== null,
            // Hyperlink-Suche: durchsucht den Content- und den gebauten Ordner
            // dieser Webseite, setzt also ein Hugo-Projekt voraus. Ein Hugo-
            // Programm braucht sie nicht (sie baut nichts), daher nicht an
            // `buildable` gebunden. Keine Pro-Bindung — sie läuft rein lokal.
            'linkScan' => $this->hugo !== null,
            // Automatikmodus des Cron-Verbesserers dieser Webseite. Der Client
            // zeigt ihn als Schalter in der Liste „zu verbessern“ und in den
            // Projekteinstellungen. `effectivePerDay` ist die Menge, die im
            // gewählten Fenster tatsächlich Platz hat — sie kann kleiner sein
            // als der eingestellte Wert, und die Oberfläche soll die wahre Zahl
            // nennen, nicht die gewünschte.
            'improve' => $this->improve + ['effectivePerDay' => $this->improveSlotPlan()['perDay']],
            // Pausenzustand der drei Cron-Skripte. Die Views (Freigabe-
            // Warteschlange, „zu verbessern“) zeigen daraus einen Hinweis, wenn
            // die zuständige Aufgabe ausgesetzt ist.
            'cronPause' => $this->cronPause,
            // Warum eine Funktion (noch) nicht nutzbar ist. Die Flags oben sagen
            // nur ob — für den Pro-Hinweis muss der Client aber wissen, ob die
            // Lizenz fehlt oder eine andere Voraussetzung. Siehe featureMatrix().
            'features' => $this->featureMatrix(),
        ];
    }

    /**
     * Zustand jeder gesperrten Funktion: nutzbar ja/nein und, falls nein, WAS
     * fehlt. `blockers` listet alle offenen Voraussetzungen (nicht nur die
     * erste), damit der Client ehrlich anzeigen kann, was eine Aktivierung der
     * Pro-Lizenz allein noch nicht löst.
     *
     * Werte in `blockers`: 'pro' (keine gültige Lizenz für diese Domain),
     * 'project' (kein Hugo-Projekt konfiguriert), 'aiKey' (kein [ai] api_key),
     * 'service' (Dienst für Live-Analyse/Spracheingabe nicht konfiguriert).
     *
     * @return array<string, array{available: bool, blockers: list<string>}>
     */
    private function featureMatrix(): array
    {
        $pro = $this->license()->isPro();
        $project = $this->hugo !== null;
        $aiKey = $this->ai['apiKey'] !== null;
        $service = $this->services['serviceKey'] !== null && $this->services['serviceUrl'] !== null;

        // Voraussetzungen je Funktion, in der Reihenfolge, in der sie dem
        // Benutzer genannt werden — die Lizenz zuerst, denn sie ist der Grund,
        // aus dem die Funktion überhaupt beworben wird.
        $needs = [
            'git' => ['pro' => $pro, 'project' => $project],
            'audit' => ['pro' => $pro, 'project' => $project],
            'auditContent' => ['pro' => $pro, 'project' => $project, 'aiKey' => $aiKey],
            'pagespeed' => ['pro' => $pro, 'project' => $project],
            'liveAnalysis' => ['pro' => $pro, 'project' => $project, 'service' => $service],
            'speech' => ['pro' => $pro, 'service' => $service],
        ];

        $out = [];
        foreach ($needs as $feature => $conditions) {
            $blockers = array_keys(array_filter($conditions, static fn (bool $met): bool => !$met));
            $out[$feature] = ['available' => $blockers === [], 'blockers' => array_values($blockers)];
        }

        return $out;
    }

    private function cmdLogin(array $request): array
    {
        // Login ohne CSRF-Prüfung (siehe requireMethod): Vor der Anmeldung hat
        // der Client kein sitzungsgebundenes Token. Geschützt ist der Login
        // durch Benutzername + Passwort. Das frische Token der angemeldeten
        // Sitzung wird zurückgegeben, damit nachfolgende Schreibbefehle sofort
        // ein gültiges Token besitzen — auch nach einem Sitzungsablauf.
        $this->requireMethod('POST', false);
        $ok = $this->auth->attemptLogin(
            (string) ($request['username'] ?? ''),
            (string) ($request['password'] ?? ''),
        );
        if (!$ok) {
            throw ApiException::unauthorized('LOGIN-FAILED');
        }

        // Der Client holt nach dem Login KEIN whoami nach — die erste Anfrage
        // danach kann noch das alte Sitzungs-Cookie tragen und käme als „nicht
        // angemeldet" zurück. Deshalb liefert der Login denselben vollständigen
        // Zustand: Alles, was vom angemeldeten Konto abhängt (Einstellungen,
        // Verwaltungsrechte, welche Dialoge offenstehen), ist damit auf einen
        // Schlag richtig. Einzelne Felder hier nachzupflegen ginge schief,
        // sobald whoami um eines erweitert wird.
        $this->userPrefs = null;

        return $this->cmdWhoami();
    }

    private function cmdLogout(): array
    {
        $this->requireMethod('POST');
        $this->auth->logout();

        return ['authenticated' => false];
    }

    private function cmdMounts(): array
    {
        $this->requireAuth();

        $mounts = [];
        foreach ($this->resolver->all() as $mount) {
            $mounts[] = [
                ...$mount->describe(),
                'id' => $this->resolver->encodeId($mount->name(), ''),
            ];
        }

        return ['mounts' => $mounts];
    }

    private function cmdList(array $request): array
    {
        $this->requireAuth();
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'read');

        $cwd = $this->files->entryInfo($target['mount'], $target['rel'], $target['abs']);
        // Lesbarer Pfad (mount/rel) — als Kontext für den KI-Assistenten.
        $cwd['path'] = $target['rel'] === ''
            ? $target['mount']->name()
            : $target['mount']->name() . '/' . $target['rel'];

        return [
            'cwd' => $cwd,
            'entries' => $this->files->listDir($target['mount'], $target['rel'], $target['abs']),
        ];
    }

    private function cmdRead(array $request): array
    {
        $this->requireAuth();
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'read');

        $data = $this->files->readText($target['mount'], $target['abs']);
        // Lesbarer Pfad (mount/rel) — der KI-Assistent referenziert Dateien so.
        $data['path'] = $target['rel'] === ''
            ? $target['mount']->name()
            : $target['mount']->name() . '/' . $target['rel'];
        // Liegt die Datei im Hugo-Content-Ordner? Dann ergänzt der Editor beim
        // Öffnen fehlendes Front Matter.
        $data['contentFile'] = $this->isHugoContentPath($target['abs']);

        return $data;
    }

    private function cmdWrite(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $target = $this->resolver->resolve($this->requireParam($request, 'target'), false);
        $this->requirePermission($target['mount'], 'write');

        $content = $request['content'] ?? null;
        if (!is_string($content)) {
            throw ApiException::badRequest('PARAM-MISSING', ['content']);
        }

        // Optionaler Konfliktschutz: mtime des Standes, den der Client geladen
        // hat. Fehlt der Parameter, wird ohne Prüfung geschrieben.
        $mtime = $request['mtime'] ?? null;
        if ($mtime !== null && !is_int($mtime)) {
            throw ApiException::badRequest('PARAM-INVALID', ['mtime']);
        }

        return $this->files->writeText($target['mount'], $target['rel'], $target['abs'], $content, $mtime);
    }

    private function cmdMkdir(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $parent = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($parent['mount'], 'mkdir');

        return $this->files->makeDir(
            $parent['mount'],
            $parent['rel'],
            $parent['abs'],
            $this->requireParam($request, 'name'),
        );
    }

    private function cmdNewFile(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $parent = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($parent['mount'], 'write');

        $info = $this->files->makeFile(
            $parent['mount'],
            $parent['rel'],
            $parent['abs'],
            $this->requireParam($request, 'name'),
        );
        // Entsteht die Datei im Hugo-Content-Ordner? Dann schreibt der Client
        // ihr das Front-Matter-Template (der Zielordner entscheidet).
        $info['contentFile'] = $this->isHugoContentPath($parent['abs']);

        return $info;
    }

    private function cmdRename(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'rename');

        return $this->files->rename(
            $target['mount'],
            $target['rel'],
            $target['abs'],
            $this->requireParam($request, 'name'),
        );
    }

    private function cmdDelete(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');

        $count = 0;
        foreach ($this->requireIdList($request, 'targets') as $id) {
            $target = $this->resolver->resolve($id);
            $this->requirePermission($target['mount'], 'delete');
            $this->files->trash($target['mount'], $target['rel'], $target['abs']);
            $count++;
        }

        return ['deleted' => $count];
    }

    private function cmdCopy(array $request): array
    {
        return $this->transfer($request, 'copy');
    }

    private function cmdMove(array $request): array
    {
        return $this->transfer($request, 'move');
    }

    /** Gemeinsamer Kern von copy und move (Zielordner + Quellenliste). */
    private function transfer(array $request, string $op): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');

        $dest = $this->resolver->resolve($this->requireParam($request, 'dest'));
        if (!is_dir($dest['abs'])) {
            throw ApiException::denied('DEST-NOT-DIRECTORY');
        }
        $this->requirePermission($dest['mount'], $op);

        $entries = [];
        foreach ($this->requireIdList($request, 'sources') as $id) {
            $src = $this->resolver->resolve($id);
            // Über Mounts hinweg nicht erlaubt (eigene Wurzeln/Rechte).
            if ($src['mount']->name() !== $dest['mount']->name()) {
                throw ApiException::badRequest('CROSS-MOUNT-NOT-ALLOWED');
            }
            $this->requirePermission($src['mount'], $op);

            $entries[] = $op === 'copy'
                ? $this->files->copy($dest['mount'], $src['abs'], $dest['rel'], $dest['abs'])
                : $this->files->move($dest['mount'], $src['rel'], $src['abs'], $dest['rel'], $dest['abs']);
        }

        return ['count' => count($entries), 'entries' => $entries];
    }

    // --- Stufe 3: Upload und Auslieferung -----------------------------------

    /** Nimmt multipart-Uploads entgegen (Feld files[], Ziel = Verzeichnis-ID). */
    private function cmdUpload(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'upload');
        if (!is_dir($target['abs'])) {
            throw ApiException::badRequest('NOT-A-DIRECTORY');
        }

        $entries = [];
        foreach ($this->uploadedFiles() as $file) {
            $entries[] = $this->files->storeUpload($target['mount'], $target['rel'], $target['abs'], $file);
        }

        return ['count' => count($entries), 'entries' => $entries];
    }

    /**
     * Speichert ein im Bild-Editor bearbeitetes Rasterbild zurück. Nimmt die
     * Bilddaten als base64 (optional mit data-URL-Präfix) entgegen; $mode
     * entscheidet über Überschreiben der Quelldatei oder Anlegen einer Kopie.
     */
    private function cmdWriteImage(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'write');

        $data = $request['data'] ?? null;
        if (!is_string($data) || $data === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['data']);
        }
        // data-URL-Präfix (data:image/png;base64,…) abtrennen, falls vorhanden.
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') {
            throw ApiException::badRequest('PARAM-INVALID', ['data']);
        }

        $mode = (string) ($request['mode'] ?? 'overwrite');
        $copyName = isset($request['name']) ? (string) $request['name'] : null;

        return $this->files->writeImage(
            $target['mount'],
            $target['rel'],
            $target['abs'],
            $binary,
            $mode,
            $copyName,
        );
    }

    /** Liefert eine Datei als Download (attachment) aus. */
    private function cmdDownload(array $request): never
    {
        $abs = $this->resolveReadableFile($request);
        $this->streamFile($abs, $this->files->mimeOf($abs), 'attachment');
    }

    /**
     * Liefert eine Datei inline aus — für den Bildbetrachter. Nur Bilder
     * werden inline gesendet; alles andere als attachment, damit kein im
     * Mount liegendes HTML/JS im Admin-Kontext gerendert wird.
     */
    private function cmdRaw(array $request): never
    {
        $abs = $this->resolveReadableFile($request);
        $mime = $this->files->mimeOf($abs);
        $disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';
        $this->streamFile($abs, $mime, $disposition);
    }

    /**
     * Liefert eine verkleinerte Bildvorschau (GD). Ohne GD, bei SVG oder bei
     * sehr großen Bildern (Dekomprimierungs-Schutz) wird das Original inline
     * ausgeliefert — die Vorschau ist dann nur nicht verkleinert.
     */
    private function cmdThumb(array $request): never
    {
        $abs = $this->resolveReadableFile($request);
        $mime = $this->files->mimeOf($abs);
        if (!str_starts_with($mime, 'image/')) {
            throw ApiException::badRequest('NOT-AN-IMAGE');
        }

        $size = max(16, min(1024, (int) ($request['size'] ?? 256)));
        $info = @getimagesize($abs);
        $tooBig = $info !== false && ((int) $info[0] * (int) $info[1]) > 40_000_000;
        if ($mime === 'image/svg+xml' || !function_exists('imagecreatefromstring') || $tooBig) {
            $this->streamFile($abs, $mime, 'inline');
        }

        $etag = '"' . sha1($abs . filemtime($abs) . filesize($abs) . 't' . $size) . '"';
        $this->maybeNotModified($etag);

        $data = @file_get_contents($abs);
        $src = $data === false ? false : @imagecreatefromstring($data);
        if ($src === false) {
            $this->streamFile($abs, $mime, 'inline'); // unbekanntes Format: Original
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $size / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('ETag: ' . $etag);
        imagepng($dst);
        exit;
    }

    // --- Stufe 4: Suche und Papierkorb ---------------------------------------

    /** Rekursive Namenssuche ab einem Verzeichnis (Teilstring, max. 200 Treffer). */
    private function cmdSearch(array $request): array
    {
        $this->requireAuth();
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'read');
        if (!is_dir($target['abs'])) {
            throw ApiException::badRequest('NOT-A-DIRECTORY');
        }
        $q = trim($this->requireParam($request, 'q'));
        if (mb_strlen($q) < 2) {
            throw ApiException::badRequest('PARAM-INVALID', ['q']);
        }

        $results = $this->files->search($target['mount'], $target['rel'], $target['abs'], $q);

        return ['query' => $q, 'entries' => $results, 'truncated' => count($results) >= FileService::SEARCH_LIMIT];
    }

    /**
     * Hyperlink-Suche in den Hugo-Quellen (content/) und im gebauten Ergebnis
     * (public/) — ein SEGMENT je Aufruf. Der Client ruft den Befehl mit dem
     * zurückgegebenen `cursor` erneut auf, bis `done` gesetzt ist; so bleibt das
     * Backend zustandslos und kein einzelner Request läuft in ein Zeitlimit.
     *
     * Gefunden wird nicht nur die eingegebene Adresse, sondern auch, was ihr
     * ähnlich sieht — darum geht es: falsch geschriebene Links aufspüren.
     * Jeder Treffer trägt seine Dateimanager-ID, sofern die Datei in einem Mount
     * liegt; Serverpfade verlassen das Backend nie.
     */
    private function cmdLinkScan(array $request): array
    {
        $this->requireAuth();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'LINKSCAN-NO-PROJECT');
        }
        $q = trim($this->requireParam($request, 'url'));
        if (mb_strlen($q) < 2) {
            throw ApiException::badRequest('PARAM-INVALID', ['url']);
        }
        $cursor = max(0, (int) ($request['cursor'] ?? 0));

        $source = (string) $this->hugo['source'];
        $public = (string) ($this->hugo['destination'] ?? $source . '/public');
        $contentName = AuditService::detectContentDir($source);
        $publicName = self::projectRelativeName($source, $public);

        // Ein Segment ist klein bemessen; das Zeitlimit deckt einen langsamen
        // Datenträger ab, nicht den ganzen Lauf (der verteilt sich auf viele
        // Aufrufe).
        @set_time_limit(60);

        $result = (new LinkScanner(
            $source . '/' . $contentName,
            $contentName,
            $public,
            $publicName,
            new SourceGuesser($source, $contentName),
        ))->scan($q, $cursor);

        // Treffer um die Dateimanager-IDs ergänzen (je Datei einmal aufgelöst):
        // `fileId` für die Fundstelle selbst, `sourceFileId` für die Hugo-Quelle
        // einer gebauten Seite — wie beim SEO-Check, wo ein Fund ebenfalls zur
        // bearbeitbaren Quelle führt statt nur zum Erzeugnis.
        $ids = [];
        foreach ($result['matches'] as &$match) {
            $rel = (string) $match['file'];
            if (!array_key_exists($rel, $ids)) {
                $abs = $match['area'] === 'public'
                    ? $public . '/' . ltrim(substr($rel, strlen($publicName)), '/')
                    : $source . '/' . $rel;
                $ids[$rel] = $this->resolveFileId($abs);
            }
            $match['fileId'] = $ids[$rel];

            $src = $match['sourceFile'] ?? null;
            if (is_string($src) && $src !== '') {
                if (!array_key_exists($src, $ids)) {
                    $ids[$src] = $this->resolveFileId($source . '/' . $src);
                }
                $match['sourceFileId'] = $ids[$src];
            } else {
                $match['sourceFileId'] = null;
            }
        }
        unset($match);

        return ['query' => $q, ...$result];
    }

    /**
     * Name eines Ordners relativ zur Projektwurzel, für die Anzeige im Client.
     * Liegt er außerhalb des Projekts (eigenes destination), bleibt sein
     * Basisname übrig — ein absoluter Serverpfad wird nie ausgegeben.
     */
    private static function projectRelativeName(string $source, string $dir): string
    {
        $sourceReal = realpath($source);
        $dirReal = realpath($dir);
        if ($sourceReal !== false && $dirReal !== false && str_starts_with($dirReal, $sourceReal . '/')) {
            return trim(str_replace('\\', '/', substr($dirReal, strlen($sourceReal))), '/');
        }

        return basename($dir);
    }

    /** Papierkörbe aller Mounts mit Löschrecht (zusammengeführt). */
    private function cmdTrashList(): array
    {
        $this->requireAuth();

        $entries = [];
        foreach ($this->resolver->all() as $mount) {
            if (!$mount->allows('delete') || !$this->auth->can('file.delete')) {
                continue;
            }
            $entries = [...$entries, ...$this->files->listTrash($mount)];
        }
        usort($entries, static fn (array $a, array $b): int => $b['deletedAt'] <=> $a['deletedAt']);

        return ['entries' => $entries];
    }

    /** Stellt Papierkorb-Einträge an ihrem Ursprungsort wieder her. */
    private function cmdRestore(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $mount = $this->resolver->get($this->requireParam($request, 'mount'));
        // Wiederherstellen schreibt in den Mount.
        $this->requirePermission($mount, 'write');

        $entries = [];
        foreach ($this->requireIdList($request, 'names') as $trashName) {
            $entries[] = $this->files->restoreFromTrash($mount, $trashName);
        }

        return ['count' => count($entries), 'entries' => $entries];
    }

    /** Leert die Papierkörbe (optional nur einen Mount) — endgültig. */
    private function cmdEmptyTrash(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');

        $mounts = isset($request['mount']) && is_string($request['mount']) && $request['mount'] !== ''
            ? [$this->resolver->get($request['mount'])]
            : array_values($this->resolver->all());

        $count = 0;
        foreach ($mounts as $mount) {
            if (!$mount->allows('delete') || !$this->auth->can('file.delete')) {
                continue;
            }
            $count += $this->files->emptyTrash($mount);
        }

        return ['removed' => $count];
    }

    // --- Hugo-Aufruf (Veröffentlichen) ---------------------------------------

    /**
     * Ruft Hugo für die konfigurierte Webseite auf ([hugo]-Sektion der
     * Mount-Konfiguration bzw. Option "hugo"). Ein fehlgeschlagener Lauf ist
     * KEIN API-Fehler: Die Antwort trägt success=false samt Hugo-Ausgabe,
     * damit der Client sie vollständig anzeigen kann.
     */
    private function cmdBuild(): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        if (!$this->auth->can('build')) {
            throw ApiException::denied('OPERATION-NOT-ALLOWED', ['build']);
        }

        return $this->runHugoBuild();
    }

    /**
     * CLI-Einstieg (Cron): baut die Webseite ohne Web-Authentifizierung. Zweck
     * ist die zeitgesteuerte Veröffentlichung der gestaffelten Freigabe.
     *
     * Standardmäßig wird NUR gebaut, wenn tatsächlich fällige terminierte
     * Freigaben anfielen — läuft der Cron alle paar Minuten, spart das den
     * Hugo-Lauf, solange nichts zu veröffentlichen ist. Mit $force=true (CLI:
     * --force) wird immer gebaut; das braucht, wer sich auf Hugos eigenes
     * Front-Matter-`publishDate` verlässt, dessen Fälligkeit ein Build erst
     * sichtbar macht (Hugo läuft ohne --buildFuture).
     *
     * Keine Pro-Lizenz nötig; setzt nur die Hugo-Konfiguration voraus.
     *
     * @return array<string, mixed>
     */
    public function buildSite(bool $force = false): array
    {
        if (!empty($this->cronPause['pauseBuild'])) {
            return $this->cronPausedResult('build');
        }

        return $this->withCronHeartbeat(
            'build',
            function () use ($force): array {
                // Vor allem anderen: offene (noch unversionierte) Änderungen im
                // Quellverzeichnis sichern, damit sie NICHT im späteren
                // Veröffentlichungs-Commit landen. Läuft bei jedem Cron-Lauf,
                // auch ohne fällige Freigabe (dann bleibt es beim Vorab-Commit).
                $committedPending = $this->maybeCommitPending();

                // Fällige Freigaben anwenden und daran messen, ob der Build sich
                // lohnt. Ein Fehler beim Anwenden darf einen erzwungenen Build
                // nicht verhindern (dann wird trotzdem gebaut).
                $applied = [];
                try {
                    $applied = $this->applyDueDrafts()['applied'] ?? [];
                } catch (Throwable $e) {
                    $this->logger->warning('Fällige Austausche nicht angewendet: ' . $e->getMessage());
                }

                if (!$force && $applied === []) {
                    $this->logger->info('Cron-Build übersprungen — keine fälligen Freigaben.');

                    return ['skipped' => true, 'applied' => 0, 'committedPending' => $committedPending];
                }

                // Freigaben sind bereits angewendet — nicht erneut anwenden.
                $result = $this->runHugoBuild(false);
                $result['applied'] = count($applied);
                $result['committedPending'] = $committedPending;

                // Optionaler Commit nach der Veröffentlichung: nur wenn wirklich
                // Freigaben eingespielt wurden (ein reiner --force-Build ohne
                // fällige Freigaben löst keinen Commit aus).
                if ($applied !== []) {
                    $result['committed'] = $this->maybeAutoCommit();
                }

                return $result;
            },
            // Übersprungen gilt als erfolgreicher Lauf (der Cron hat geprüft und
            // nichts zu tun gefunden). Ein Hugo-Lauf mit exitCode != 0 wirft
            // nicht, ist für den Cron aber ein Fehlschlag — sonst meldete der
            // Status „erfolgreich“, während die Webseite nicht mehr gebaut wird.
            static function (array $r): array {
                // Ein Commit, der lief, aber nicht zustande kam (z. B. fehlende
                // git-Identität), macht den Lauf NICHT zum Fehlschlag — der
                // Hugo-Build ist ja gelungen. Er wird aber im Statustext
                // sichtbar gemacht, nicht nur im Log. Voraussetzung ist ein
                // ausdrückliches success=false; ein nicht versuchter Commit
                // (sauberer Arbeitsbaum, kein Repo, Schalter aus) liefert null.
                $commitNote = '';
                if (isset($r['committedPending']['success']) && $r['committedPending']['success'] === false) {
                    $commitNote .= '; Vorab-Commit fehlgeschlagen (siehe Log)';
                }
                if (isset($r['committed']['success']) && $r['committed']['success'] === false) {
                    $commitNote .= '; Veröffentlichungs-Commit fehlgeschlagen (siehe Log)';
                }

                if (!empty($r['skipped'])) {
                    $note = 'Übersprungen — keine fälligen Freigaben';
                    if (!empty($r['committedPending']['success'])) {
                        $note .= '; offene Änderungen committet';
                    }

                    return [true, $note . $commitNote];
                }

                return [
                    (bool) ($r['success'] ?? false),
                    sprintf(
                        '%d Freigabe(n), Hugo Code %d',
                        (int) ($r['applied'] ?? 0),
                        (int) ($r['exitCode'] ?? 0),
                    ) . $commitNote,
                ];
            },
        );
    }

    /**
     * Legt nach der zeitgesteuerten Veröffentlichung optional einen Commit an
     * (Schalter [git] auto_commit). Ein reiner Build ohne fällige Freigaben
     * ruft dies nicht auf.
     *
     * @return ?array{success: bool, sha: ?string}
     */
    private function maybeAutoCommit(): ?array
    {
        // Nach der Veröffentlichung wird ein Commit erwartet — „nichts zu
        // committen“ deshalb NICHT stillschweigend überspringen (skipWhenClean
        // = false), damit ein echtes Problem sichtbar wird.
        return $this->runAutoCommit(
            (string) $this->gitAuto['commitMessage'],
            MountConfig::GIT_COMMIT_MESSAGE_DEFAULT,
            false,
        );
    }

    /**
     * Sichert VOR dem Build offene (noch unversionierte) Änderungen im
     * Quellverzeichnis mit eigener Nachricht — nur wenn welche vorliegen, sonst
     * still. So bleibt der spätere Veröffentlichungs-Commit auf die publizierten
     * Dateien beschränkt und verstreute Direktbearbeitungen werden zeitnah
     * versioniert. Hängt am selben Schalter [git] auto_commit.
     *
     * @return ?array{success: bool, sha: ?string}
     */
    private function maybeCommitPending(): ?array
    {
        // Läuft bei jedem Cron-Build (alle 15 Min.) — bei sauberem Arbeitsbaum
        // still überspringen (skipWhenClean = true), sonst würde das Log mit
        // „nichts zu committen“ zulaufen.
        return $this->runAutoCommit(
            (string) $this->gitAuto['commitMessagePending'],
            MountConfig::GIT_COMMIT_MESSAGE_PENDING_DEFAULT,
            true,
        );
    }

    /**
     * Gemeinsamer Auto-Commit. Voraussetzung: Schalter an, gültige Pro-Lizenz
     * (Git ist eine Pro-Funktion) und ein Git-Repository im Quellverzeichnis.
     * Fehlt eine Voraussetzung oder scheitert der Commit, wird das nur
     * protokolliert — der Build darf daran nie scheitern.
     *
     * `git add -A` übernimmt ALLE offenen Änderungen im Arbeitsbaum (wie der
     * manuelle Commit). An die Nachricht wird das Datum (Serverzeit) angehängt.
     * $skipWhenClean = true committet nur, wenn der Arbeitsbaum überhaupt
     * offene Änderungen hat (sonst still ohne Log).
     *
     * @return ?array{success: bool, sha: ?string}
     */
    private function runAutoCommit(string $message, string $fallback, bool $skipWhenClean): ?array
    {
        if (empty($this->gitAuto['autoCommit']) || $this->hugo === null) {
            return null;
        }
        if (!$this->license()->isPro()) {
            $this->logger->warning('Auto-Commit übersprungen — Git ist eine Pro-Funktion, aber keine gültige Lizenz vorhanden.');

            return null;
        }

        $source = (string) $this->hugo['source'];
        $message = trim($message);
        if ($message === '') {
            $message = $fallback;
        }
        // Datum anhängen (Serverzeit, wie die übrigen Cron-Zeiten).
        $message .= ' — ' . date('Y-m-d H:i');

        try {
            $git = new GitService($source);
            // Vorab-Commit nur bei wirklich offenen Änderungen — ein sauberer
            // Arbeitsbaum ist hier der Normalfall und kein Ereignis fürs Log.
            if ($skipWhenClean && !empty($git->status()['clean'])) {
                return null;
            }
            $res = $git->commit($message);
        } catch (Throwable $e) {
            // Etwa GIT-NOT-A-REPO: kein Repository → nichts zu committen.
            $this->logger->info('Auto-Commit übersprungen: ' . $e->getMessage());

            return null;
        }

        if (!empty($res['success'])) {
            $this->logger->info(sprintf('Auto-Commit %s: %s', substr((string) ($res['sha'] ?? ''), 0, 7), $message));
        } else {
            // Häufigster Fall ohne Fehler: „nichts zu committen“. Als Warnung mit
            // Git-Ausgabe, damit ein echtes Problem (fehlende git-Identität)
            // sichtbar wird.
            $this->logger->warning('Auto-Commit nicht angelegt: ' . trim((string) ($res['output'] ?? '')));
        }

        return ['success' => (bool) ($res['success'] ?? false), 'sha' => $res['sha'] ?? null];
    }

    /**
     * Einheitliche Antwort eines pausierten Cron-Laufs. Es wird KEIN Herzschlag
     * vermerkt — der Lauf hat ja nichts getan, und im Systemstatus soll er nicht
     * als „erfolgreich gelaufen“ erscheinen. Ein Log-Eintrag hält die Pause
     * fest, damit ein stiller Cron nicht rätselhaft wirkt.
     *
     * @return array{paused: true}
     */
    private function cronPausedResult(string $job): array
    {
        $this->logger->info(sprintf('Cron-Aufgabe „%s“ ist pausiert — kein Lauf.', $job));

        return ['paused' => true];
    }

    /**
     * Speicher der Cron-Herzschläge dieser Webseite. null ohne Hugo-Projekt —
     * dann gibt es für diese Webseite auch keine Cron-Aufgaben.
     */
    private function cronHeartbeat(): ?Heartbeat
    {
        if ($this->hugo === null) {
            return null;
        }

        return new Heartbeat(__DIR__ . '/../var/cron/' . sha1((string) $this->hugo['source']));
    }

    /**
     * Führt einen Cron-Einstieg aus und vermerkt den Lauf im Herzschlag — auch
     * dann, wenn er mit einer Ausnahme endet (der Fehler gehört gerade in den
     * Status). Die Ausnahme wird unverändert weitergereicht, das CLI-Skript
     * behandelt sie wie bisher. Probeläufe rufen diese Hülle NICHT auf: sie
     * verändern nichts und dürfen den Takt nicht verfälschen.
     *
     * @param \Closure(): array<string, mixed>          $run       der eigentliche Lauf
     * @param \Closure(array<string, mixed>): array{0: bool, 1: string} $summarize Erfolg + Kurztext
     * @return array<string, mixed>
     */
    private function withCronHeartbeat(string $job, \Closure $run, \Closure $summarize): array
    {
        $startedAt = gmdate('c');
        $start = hrtime(true);
        try {
            $result = $run();
        } catch (Throwable $e) {
            $this->cronHeartbeat()?->record(
                $job,
                false,
                '',
                $e instanceof ApiException ? $e->errorCode() . ' – ' . $e->getMessage() : $e->getMessage(),
                (hrtime(true) - $start) / 1e9,
                $startedAt,
            );

            throw $e;
        }

        [$success, $summary] = $summarize($result);
        $this->cronHeartbeat()?->record(
            $job,
            $success,
            $summary,
            null,
            (hrtime(true) - $start) / 1e9,
            $startedAt,
        );

        return $result;
    }

    /**
     * Führt den eigentlichen Hugo-Lauf aus ([hugo]-Sektion bzw. Option "hugo").
     * Ein fehlgeschlagener Lauf ist KEIN API-Fehler: Die Antwort trägt
     * success=false samt Hugo-Ausgabe, damit sie vollständig angezeigt werden
     * kann. Nur die Vorbedingungen (fehlende Konfiguration/Programm/Quelle)
     * werfen eine Ausnahme.
     *
     * $applyDrafts=false überspringt das Anwenden fälliger Freigaben — für
     * Aufrufer, die das bereits selbst erledigt haben ({@see buildSite()}, das
     * daraus erst entscheidet, ob überhaupt gebaut wird).
     *
     * @return array{success: bool, exitCode: int, output: string, seconds: float}
     */
    private function runHugoBuild(bool $applyDrafts = true): array
    {
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 500, 'HUGO-NOT-CONFIGURED');
        }

        // Fällige terminierte Austausche zuerst anwenden (verzögerter Austausch
        // der gestaffelten Veröffentlichung), damit der Build die neuen Fassungen
        // sieht. Ein Fehler hier darf den Build nicht verhindern.
        if ($applyDrafts) {
            try {
                $this->applyDueDrafts();
            } catch (Throwable $e) {
                $this->logger->warning('Fällige Austausche nicht angewendet: ' . $e->getMessage());
            }
        }

        if ($this->hugoBin === null) {
            throw new ApiException('ECONFIG', 500, 'HUGO-BIN-NOT-CONFIGURED');
        }
        $bin = $this->hugoBin;
        $source = (string) $this->hugo['source'];
        $dest = (string) ($this->hugo['destination'] ?? $source . '/public');
        if (!is_file($bin) || !is_executable($bin)) {
            throw new ApiException('ECONFIG', 500, 'HUGO-BIN-MISSING', [$bin]);
        }
        if (!is_dir($source)) {
            throw new ApiException('ECONFIG', 500, 'HUGO-SOURCE-MISSING', [$source]);
        }

        // --cleanDestinationDir nur auf Wunsch: global über [hugo] clean in der
        // hugocms.ini oder je Webseite über die Mount-Konfiguration. Es entfernt
        // im Ziel ALLES, was Hugo nicht selbst erzeugt — auch die im Publish-
        // Ordner liegende Installation (edit/, cms-api/). Standard: aus.
        // Bewusst OHNE --buildFuture/--buildDrafts: künftige publishDate und
        // draft:true bleiben so unveröffentlicht — genau die Staffelung.
        $clean = $this->hugoClean || !empty($this->hugo['clean']);
        $cmd = escapeshellarg($bin)
            . ($clean ? ' --cleanDestinationDir' : '')
            . ' -s ' . escapeshellarg($source)
            . ' -d ' . escapeshellarg($dest)
            . (!empty($this->hugo['minify']) ? ' --minify' : '')
            . ' 2>&1';

        $start = hrtime(true);
        $lines = [];
        $exitCode = 1;
        exec($cmd, $lines, $exitCode);
        $seconds = round((hrtime(true) - $start) / 1e9, 2);

        // Ausgabe begrenzen (Logs können lang werden): die letzten 200 Zeilen.
        $output = implode("\n", array_slice($lines, -200));
        if ($exitCode === 0) {
            $this->logger->info("Hugo-Lauf erfolgreich ({$seconds}s): {$source} -> {$dest}");
        } else {
            $this->logger->warning("Hugo-Lauf fehlgeschlagen (Code {$exitCode}): {$output}");
        }

        return [
            'success' => $exitCode === 0,
            'exitCode' => $exitCode,
            'output' => $output,
            'seconds' => $seconds,
        ];
    }

    /**
     * KI-Assistent: führt einen Gesprächszug mit Claude aus (Werkzeugaufrufe
     * laufen über FileService/MountResolver, also mit denselben Rechten und
     * derselben Einsperrung wie alle Datei-Befehle).
     */
    private function cmdAssistant(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        if ($this->ai['apiKey'] === null) {
            throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
        }

        $messages = $request['messages'] ?? null;
        if (!is_array($messages) || $messages === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['messages']);
        }
        $locale = (string) ($request['locale'] ?? 'de');
        $confirm = $request['confirm'] ?? null;
        if ($confirm !== null && !in_array($confirm, ['allow', 'reject'], true)) {
            throw ApiException::badRequest('PARAM-INVALID', ['confirm']);
        }
        // Optionaler Kontext: im Editor geöffnete Datei und im Dateimanager
        // angezeigtes Verzeichnis (jeweils lesbarer Pfad).
        $openFilePath = trim((string) ($request['openFilePath'] ?? '')) ?: null;
        $openDirPath = trim((string) ($request['openDirPath'] ?? '')) ?: null;

        // Sitzungsbezogene Auswahl aus dem Panel: übersteuert Modell und
        // Schreibmodus nur für diesen Lauf (die INI bleibt unberührt). Ein
        // ungültiger Schreibmodus fällt auf den konfigurierten zurück; ein
        // leeres Modell ebenso.
        $writeModeReq = strtolower(trim((string) ($request['writeMode'] ?? '')));
        $writeModeOverride = in_array($writeModeReq, self::AI_WRITE_MODES, true) ? $writeModeReq : null;
        $modelOverride = trim((string) ($request['model'] ?? '')) ?: null;

        // Der Werkzeug-Loop kann mehrere API-Aufrufe nacheinander machen.
        @set_time_limit(180);

        return $this->assistantService($writeModeOverride, $modelOverride)->run(
            $messages,
            $confirm === null ? null : (string) $confirm,
            $locale,
            $openFilePath,
            $openDirPath,
        );
    }

    /**
     * assistantping — Bereitschaftsprüfung des KI-Assistenten. Ein GET auf
     * /v1/models (ohne Token-Verbrauch) verifiziert, dass die Claude-API
     * erreichbar und der hinterlegte Schlüssel gültig ist. Erfolg → ready:true;
     * bei Problemen wirft ping() den passenden Fehler (Erreichbarkeit/Schlüssel).
     *
     * @return array{ready: bool}
     */
    private function cmdAssistantPing(): array
    {
        $this->requireAuth();
        if ($this->ai['apiKey'] === null) {
            throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
        }

        (new AnthropicClient((string) $this->ai['apiKey']))->ping();

        return ['ready' => true];
    }

    /**
     * Baut den KI-Assistenten mit der aktuellen [ai]-Konfiguration. Nur bei
     * vorhandenem API-Schlüssel aufrufen (der Client verlangt einen). Das
     * Werkzeug get_file_report wird nur eingehängt, wenn Pro-Lizenz und
     * Hugo-Projekt vorliegen (sonst gibt es keinen Audit-/Content-Bericht).
     */
    private function assistantService(?string $writeModeOverride = null, ?string $modelOverride = null, string $draftOrigin = 'ai', ?bool $forceThinkingOverride = null): AssistantService
    {
        // Interaktiver Assistent nutzt `model`; der Cron-Verbesserer reicht sein
        // eigenes Modell (`model_cron`) als Override durch.
        $model = $modelOverride ?? $this->ai['model'];
        // Thinking-Erzwingung folgt demselben Slot: interaktiv `forceThinking`,
        // der Cron reicht `forceThinkingCron` als Override durch.
        $forceThinking = $forceThinkingOverride ?? (bool) $this->ai['forceThinking'];
        // get_file_report und der Bearbeitungs-Vermerk brauchen beide das
        // Content-Qualitäts-Feature (Pro-Lizenz + Hugo-Projekt).
        $contentAware = $this->hugo !== null && $this->license()->isPro();
        $fileReport = $contentAware
            ? fn (string $fileId): array => $this->buildFileReportById($fileId)
            : null;
        // Der Vermerk hält fest, welches Modell die Verbesserung geschrieben hat.
        $onWrite = $contentAware
            ? fn (string $fileId) => $this->markFileImproved($fileId, $model)
            : null;

        // Gestaffelte Veröffentlichung: Im Modus auto (Cron oder so konfigurierter
        // interaktiver Assistent) und mit Hugo-Projekt geht ein Schreibvorgang
        // nicht live, sondern als Entwurf zur Freigabe. Ohne Hugo-Projekt gibt es
        // kein draft/publishDate — dann schreibt auto wie bisher direkt.
        $mode = $writeModeOverride ?? $this->ai['writeMode'];
        $draftSink = ($mode === 'auto' && $this->hugo !== null)
            ? fn (Mount $m, string $rel, string $abs, string $content) => $this->stashDraft($m, $rel, $abs, $content, $draftOrigin, $model)
            : null;

        return new AssistantService(
            new AnthropicClient((string) $this->ai['apiKey']),
            $model,
            $mode,
            $this->resolver,
            $this->files,
            $fileReport,
            $onWrite,
            $draftSink,
            $forceThinking,
        );
    }

    /**
     * Speicherverzeichnis der Freigabe-Entwürfe (je Webseite getrennt).
     * Setzt ein Hugo-Projekt voraus (der Schlüssel leitet sich aus source ab).
     */
    private function reviewStore(): ReviewStore
    {
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'REVIEW-NO-PROJECT');
        }

        return new ReviewStore(__DIR__ . '/../var/review/' . sha1((string) $this->hugo['source']));
    }

    /**
     * Legt einen Freigabe-Entwurf für die Datei (mount/rel) ab: hält den
     * vollständigen Vorschlag fest, ohne die Live-Datei zu berühren. Ein bereits
     * offener Entwurf derselben Datei wird ersetzt.
     *
     * `author` hält fest, wer den Entwurf ausgelöst hat — beim manuellen Entwurf
     * und beim interaktiven Assistenten der angemeldete Benutzer, im Cron null
     * (dort gibt es keine Session). Der Client beschriftet damit die Herkunft.
     */
    private function stashDraft(Mount $mount, string $rel, string $abs, string $content, string $origin, ?string $model = null): void
    {
        $exists = is_file($abs);
        $user = $this->auth->currentUser();
        $this->reviewStore()->put([
            'key' => ReviewStore::keyFor($mount->name(), $rel),
            'mount' => $mount->name(),
            'rel' => $rel,
            'fileId' => $this->resolver->encodeId($mount->name(), $rel),
            'origin' => $origin,
            'author' => is_string($user['name'] ?? null) ? (string) $user['name'] : null,
            'isNew' => !$exists,
            'proposedContent' => $content,
            'baseMtime' => $exists ? (int) (filemtime($abs) ?: 0) : null,
            'createdAt' => gmdate('c'),
            'model' => $model,
        ]);
    }

    /**
     * reviewsave — legt den übergebenen Inhalt manuell als Freigabe-Entwurf ab
     * (der Entwurf-Button neben „Speichern"). Schreibt NICHT in die Live-Datei.
     */
    private function cmdReviewSave(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $r = $this->resolver->resolve($this->requireParam($request, 'target'), false);
        $this->requirePermission($r['mount'], 'write');

        $content = $request['content'] ?? null;
        if (!is_string($content)) {
            throw ApiException::badRequest('PARAM-MISSING', ['content']);
        }
        $this->stashDraft($r['mount'], $r['rel'], $r['abs'], $content, 'user');

        return ['saved' => true, 'key' => ReviewStore::keyFor($r['mount']->name(), $r['rel'])];
    }

    /** reviewlist — offene Entwürfe (ohne Inhalt) für die Freigabe-Warteschlange. */
    private function cmdReviewList(): array
    {
        $this->requireAuth();

        return ['drafts' => $this->reviewStore()->list()];
    }

    /**
     * reviewget — vollständiger Entwurf samt aktuellem Live-Inhalt (für die
     * Diff-Ansicht). Bei neuen Seiten fehlt das Original (original = null).
     */
    private function cmdReviewGet(array $request): array
    {
        $this->requireAuth();
        $draft = $this->reviewStore()->get($this->requireParam($request, 'key'));

        $original = null;
        try {
            $r = $this->resolver->resolve((string) ($draft['fileId'] ?? ''), true);
            $original = (string) $this->files->readText($r['mount'], $r['abs'])['content'];
        } catch (Throwable) {
            $original = null; // Quelle fehlt/nicht lesbar (neue Seite o. Ä.)
        }
        $draft['original'] = $original;

        return $draft;
    }

    /**
     * reviewapprove — gibt einen Entwurf frei. Zwei Fälle:
     *
     *  - OHNE (bzw. mit vergangenem) `publishDate`: sofort — der Entwurf wird in
     *    die Live-Datei geschrieben (draft:false) und entfernt.
     *  - MIT künftigem `publishDate` (ISO 8601): terminiert — der Entwurf bleibt
     *    mit dem Feld `publishAt` im Speicher, die Live-Datei bleibt UNVERÄNDERT.
     *    Die alte Fassung bleibt so bis zum Termin veröffentlicht; ein Build
     *    tauscht die Datei erst, wenn der Zeitpunkt erreicht ist (verzögerter
     *    Austausch, {@see applyDueDrafts()}). Kein `publishDate` im Front Matter.
     *
     * Mit force=true entfällt die Konfliktprüfung gegen den Live-Stand (nur beim
     * sofortigen Schreiben relevant).
     */
    private function cmdReviewApprove(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $draft = $this->reviewStore()->get($this->requireParam($request, 'key'));

        $r = $this->resolver->resolve((string) ($draft['fileId'] ?? ''), false);
        $this->requirePermission($r['mount'], 'write');

        // Künftiger Termin? Dann nur vormerken (verzögerter Austausch), nicht schreiben.
        $publishDate = $request['publishDate'] ?? null;
        $swapTs = is_string($publishDate) && trim($publishDate) !== '' ? strtotime(trim($publishDate)) : false;
        if ($swapTs !== false && $swapTs > time()) {
            $draft['publishAt'] = gmdate('c', $swapTs);
            $this->reviewStore()->put($draft);
            $this->logger->info(sprintf(
                'Freigabe terminiert: %s/%s (Austausch %s)',
                (string) ($draft['mount'] ?? ''),
                (string) ($draft['rel'] ?? ''),
                $draft['publishAt'],
            ));

            return ['scheduled' => true, 'key' => (string) $draft['key'], 'publishAt' => $draft['publishAt']];
        }

        // Sofort: Konfliktschutz gegen den zwischenzeitlichen Live-Stand.
        $force = (bool) ($request['force'] ?? false);
        $expected = (!$force && is_int($draft['baseMtime'] ?? null)) ? (int) $draft['baseMtime'] : null;
        $meta = $this->applyDraftLive($draft, $r, $expected);

        $this->logger->info(sprintf(
            'Entwurf freigegeben: %s/%s',
            (string) ($draft['mount'] ?? ''),
            (string) ($draft['rel'] ?? ''),
        ));

        return ['approved' => true, 'key' => (string) $draft['key'], 'file' => $meta];
    }

    /**
     * Schreibt den Inhalt eines Entwurfs in seine Live-Datei (draft:false, KEIN
     * publishDate), vermerkt KI-/Cron-Bearbeitungen und entfernt den Entwurf.
     * Gemeinsame Logik der sofortigen Freigabe und des terminierten Austauschs.
     *
     * @param array<string, mixed>                       $draft
     * @param array{mount: Mount, abs: string, rel: string} $r
     */
    private function applyDraftLive(array $draft, array $r, ?int $expectedMtime = null): array
    {
        $content = FrontMatter::setDraft((string) ($draft['proposedContent'] ?? ''), false);
        $meta = $this->files->writeText($r['mount'], $r['rel'], $r['abs'], $content, $expectedMtime);

        if (in_array((string) ($draft['origin'] ?? ''), ['ai', 'cron'], true)) {
            $model = is_string($draft['model'] ?? null) ? (string) $draft['model'] : null;
            $this->markFileImproved((string) ($draft['fileId'] ?? ''), $model);
        }

        $this->reviewStore()->delete((string) $draft['key']);

        return $meta;
    }

    /**
     * Verzögerter Austausch der gestaffelten Veröffentlichung: schreibt alle
     * terminierten Entwürfe, deren `publishAt` erreicht ist, in ihre Live-Datei
     * und entfernt sie. Bis dahin bleibt die alte Fassung unverändert und wird
     * weiter gebaut. Wird vor jedem Build ausgeführt ({@see runHugoBuild()}), im
     * Web wie im Cron. Ein Fehler an einer Datei bricht die übrigen nicht ab.
     *
     * @return array{applied: list<array{key: string, rel: string}>}
     */
    public function applyDueDrafts(): array
    {
        if ($this->hugo === null) {
            return ['applied' => []];
        }
        $store = $this->reviewStore();
        $now = time();
        $applied = [];
        foreach ($store->list() as $meta) {
            $publishAt = $meta['publishAt'] ?? null;
            if (!is_string($publishAt) || $publishAt === '') {
                continue; // noch offen zur Freigabe (kein Termin)
            }
            $ts = strtotime($publishAt);
            if ($ts === false || $ts > $now) {
                continue; // Termin noch nicht erreicht
            }
            $key = (string) ($meta['key'] ?? '');
            try {
                $draft = $store->get($key); // vollständig inkl. proposedContent
                $r = $this->resolver->resolve((string) ($draft['fileId'] ?? ''), false);
                if (!$r['mount']->allows('write')) {
                    $this->logger->warning("Terminierter Austausch übersprungen (Mount schreibgeschützt): {$key}");
                    continue;
                }
                // Cron kann keine Konflikte bestätigen — der terminierte Stand
                // gewinnt; eine zwischenzeitliche Live-Änderung wird nur vermerkt.
                if (is_int($draft['baseMtime'] ?? null) && is_file($r['abs']) && (int) @filemtime($r['abs']) !== (int) $draft['baseMtime']) {
                    $this->logger->warning(sprintf('Terminierter Austausch überschreibt zwischenzeitliche Änderung: %s/%s', (string) ($draft['mount'] ?? ''), (string) ($draft['rel'] ?? '')));
                }
                $this->applyDraftLive($draft, $r);
                $applied[] = ['key' => $key, 'rel' => (string) ($draft['rel'] ?? '')];
                $this->logger->info(sprintf('Terminierter Austausch: %s/%s', (string) ($draft['mount'] ?? ''), (string) ($draft['rel'] ?? '')));
            } catch (Throwable $e) {
                $this->logger->warning("Terminierter Austausch fehlgeschlagen ({$key}): " . $e->getMessage());
            }
        }

        return ['applied' => $applied];
    }

    /** reviewdiscard — verwirft einen Entwurf; die Live-Datei bleibt unberührt. */
    private function cmdReviewDiscard(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $key = $this->requireParam($request, 'key');
        $this->reviewStore()->get($key); // 404, falls unbekannt
        $this->reviewStore()->delete($key);

        return ['discarded' => true, 'key' => $key];
    }

    /**
     * speech — Spracheingabe (Pro). Nimmt eine hochgeladene Audiodatei (Feld
     * „audio") entgegen und reicht sie an den externen Transkriptionsdienst
     * (seo-success) weiter; liefert den erkannten Text. Der Dienst-Schlüssel
     * bleibt serverseitig und wird nie an den Client gegeben.
     *
     * @return array{text: string, duration: float}
     */
    private function cmdSpeech(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $this->requirePro();
        if ($this->services['serviceKey'] === null || $this->services['serviceUrl'] === null) {
            throw new ApiException('ECONFIG', 409, 'SPEECH-NOT-CONFIGURED');
        }

        // Genau eine Audiodatei erwartet (kein files[]-Array wie beim Upload).
        $file = $_FILES['audio'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw ApiException::badRequest('PARAM-MISSING', ['audio']);
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ApiException::badRequest('UPLOAD-FAILED', ['audio']);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw ApiException::badRequest('PARAM-MISSING', ['audio']);
        }
        // Diktate sind klein; Obergrenze (25 MB) schützt vor Missbrauch.
        if ((int) ($file['size'] ?? 0) > 26214400) {
            throw new ApiException('EINVAL', 413, 'AUDIO-TOO-LARGE');
        }

        // Sprache aus dem Locale (de/en) als Hinweis an die Erkennung.
        $locale = strtolower(trim((string) ($request['locale'] ?? '')));
        $lang = preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : null;

        @set_time_limit(180);

        // service_url ist die Basis-Adresse; der SeoSuccessClient bildet den Endpunkt.
        $client = new SeoSuccessClient(
            (string) $this->services['serviceUrl'],
            (string) $this->services['serviceKey'],
        );
        $result = $client->transcribe(
            $tmp,
            (string) ($file['name'] ?? 'audio'),
            (string) ($file['type'] ?? 'application/octet-stream'),
            $lang,
        );

        return [
            'text' => (string) ($result['text'] ?? ''),
            'duration' => (float) ($result['duration'] ?? 0.0),
        ];
    }

    /**
     * serviceverify — prüft den seo-success-Schlüssel gegen den Dienst, ohne
     * etwas zu speichern oder zu transkribieren. Bedient den Konfigurationsdialog
     * UND die Kontingentanzeige der Live-Analyse. Der Schlüssel des Formulars
     * (evtl. noch ungespeichert) hat Vorrang; ist das Feld leer, wird der
     * hinterlegte geprüft. Ebenso die URL. Die Feldnamen tragen aus
     * Kompatibilität noch speech*, mit Rückfall auf service*.
     *
     * @return array{valid: bool, name: string, quotaLimit: ?int, quotaUsed: ?float, quotaRemaining: ?float, quotaExceeded: bool}
     */
    private function cmdServiceVerify(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $this->requirePro();

        $url = trim((string) ($request['serviceUrl'] ?? $request['speechUrl'] ?? ''));
        if ($url === '') {
            $url = (string) ($this->services['serviceUrl'] ?? '');
        }
        if ($url === '') {
            $url = 'https://api.hugocms.com/';
        }

        $keyNew = trim((string) ($request['serviceKey'] ?? $request['speechKey'] ?? ''));
        $key = $keyNew !== '' ? $keyNew : (string) ($this->services['serviceKey'] ?? '');
        if ($key === '') {
            throw new ApiException('ECONFIG', 409, 'SPEECH-NO-KEY');
        }

        return self::normalizeServiceInfo((new SeoSuccessClient($url, $key))->verify());
    }

    /**
     * Bringt die Antwort von /v1/verify in die Form, die der Client erwartet.
     * Gemeinsam genutzt von {@see cmdServiceVerify()} (Konfigurationsdialog,
     * Kontingentanzeige der Live-Analyse) und {@see cmdStatusCheck()}
     * (Systemstatus) — beide sollen dieselben Zahlen zeigen.
     *
     * @param array<string, mixed> $info
     * @return array{valid: bool, name: string, quotaLimit: ?int, quotaUsed: ?float, quotaRemaining: ?float, quotaExceeded: bool, features: array<string, bool>}
     */
    private static function normalizeServiceInfo(array $info): array
    {
        return [
            'valid' => true,
            'name' => (string) ($info['name'] ?? ''),
            // quotaLimit === null bedeutet beim Dienst „unbegrenzt“ — nicht 0.
            'quotaLimit' => isset($info['quotaLimit']) ? (int) $info['quotaLimit'] : null,
            'quotaUsed' => isset($info['quotaUsed']) ? (float) $info['quotaUsed'] : null,
            'quotaRemaining' => isset($info['quotaRemaining']) ? (float) $info['quotaRemaining'] : null,
            'quotaExceeded' => (bool) ($info['quotaExceeded'] ?? false),
            // Je Schlüssel freigeschaltete Dienst-Funktionen (z. B. transcribe,
            // crux). Der Systemstatus listet die aktiven auf.
            'features' => self::serviceFeatures($info['features'] ?? null),
        ];
    }

    /**
     * Reduziert die `features`-Map aus /v1/verify auf boolesche Flags. Der Dienst
     * meldet sie als key→bool; wir übernehmen jeden bekannten wie unbekannten
     * Namen, aber nur echte Boolean-Werte. So erscheint eine künftige Funktion
     * (etwa `crux`) automatisch im Status, sobald der Dienst sie meldet — ohne
     * hier nachzuziehen.
     *
     * @param mixed $features
     * @return array<string, bool>
     */
    private static function serviceFeatures(mixed $features): array
    {
        if (!is_array($features)) {
            return [];
        }
        $out = [];
        foreach ($features as $name => $enabled) {
            if (is_string($name) && is_bool($enabled)) {
                $out[$name] = $enabled;
            }
        }

        return $out;
    }

    /**
     * pagespeed — misst die im Panel eingegebene Live-Adresse über Google
     * PageSpeed Insights und liefert die reduzierten Kennzahlen (Scores + Kern-
     * Web-Vitalwerte). Pro-Funktion; der Google-Schlüssel ist global und bleibt
     * serverseitig, die zu messende Adresse gehört PRO WEBSEITE in die
     * Mount-Konfiguration ([pagespeed] url) und wird hier beim Messstart
     * gespeichert. Der Lauf misst live und kann einige Sekunden dauern.
     *
     * @return array<string, mixed>
     */
    private function cmdPageSpeed(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $this->requirePro();

        // Eingegebene Adresse prüfen: nur absolute http(s)-URLs sind messbar
        // (Google ruft sie selbst ab).
        $url = trim((string) ($request['url'] ?? ''));
        if ($url === '' || !self::isPublicHttpUrl($url)) {
            throw ApiException::badRequest('PAGESPEED-URL-INVALID');
        }

        // Adresse dieser Webseite dauerhaft merken (Mount-Konfiguration). Im
        // programmatischen Betrieb (custom.php) gibt es keine schreibbare
        // Mount-INI — dann wird nur gemessen, nicht gespeichert.
        if ($this->mountsPath !== null && $url !== $this->pagespeedUrl) {
            Config::updateSections($this->mountsPath, ['pagespeed' => ['url' => $url]]);
            $this->pagespeedUrl = $url;
        }

        // Strategie aus dem Request; alles außer "desktop" gilt als "mobile".
        $strategy = ((string) ($request['strategy'] ?? 'mobile')) === 'desktop' ? 'desktop' : 'mobile';

        // Oberflächensprache für die Texte der Optimierungs-Chancen (nur die
        // zweistellige Kennung, z. B. "de"/"en").
        $locale = strtolower(substr((string) ($request['locale'] ?? ''), 0, 2));
        $locale = preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : null;

        $result = (new PageSpeedClient($this->services['pagespeedKey']))
            ->run($url, $strategy, PageSpeedClient::CATEGORIES, $locale);

        // Zeitpunkt der Messung (Serverzeit, UTC) für die Anzeige „zuletzt
        // gemessen" und das jüngste Ergebnis je Webseite merken.
        $result['measuredAt'] = gmdate('c');
        $this->persistPageSpeed($result);

        $this->logger->info(sprintf('PageSpeed-Check (%s) für %s.', $strategy, $url));

        return $result;
    }

    /**
     * pagespeedlatest — liefert die zuletzt gespeicherten PageSpeed-Ergebnisse
     * dieser Webseite je Strategie (mobil/desktop; jeweils null, wenn noch keine
     * Messung vorliegt), damit das Panel sie nach dem Öffnen anzeigen und der
     * Nutzer per Umschalter zwischen beiden wechseln kann. Kein Verlauf: je
     * Strategie wird nur das jüngste Ergebnis vorgehalten.
     *
     * @return array{mobile: ?array<string, mixed>, desktop: ?array<string, mixed>}
     */
    private function cmdPageSpeedLatest(): array
    {
        $this->requireAuth();
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }

        return [
            'mobile' => $this->readPageSpeed('mobile'),
            'desktop' => $this->readPageSpeed('desktop'),
        ];
    }

    /**
     * Liest das gespeicherte PageSpeed-Ergebnis einer Strategie oder null.
     *
     * @return ?array<string, mixed>
     */
    private function readPageSpeed(string $strategy): ?array
    {
        $path = $this->pagespeedStorePath($strategy);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /** Prüft, ob $url eine absolute http(s)-Adresse mit Host ist. */
    private static function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    /**
     * Speicherpfad des jüngsten PageSpeed-Ergebnisses dieser Webseite JE
     * STRATEGIE — je Projekt getrennt unter
     * var/pagespeed/<hash(source)>-<strategy>.json (wie das Audit seine
     * Berichte). So bleiben Mobil- und Desktop-Bericht nebeneinander erhalten.
     * null, wenn kein Hugo-Projekt vorliegt.
     */
    private function pagespeedStorePath(string $strategy): ?string
    {
        if ($this->hugo === null) {
            return null;
        }
        $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';

        return __DIR__ . '/../var/pagespeed/' . sha1((string) $this->hugo['source']) . '-' . $strategy . '.json';
    }

    /**
     * Legt das jüngste PageSpeed-Ergebnis der jeweiligen Strategie ab
     * (überschreibt das vorherige derselben Strategie). Best effort: ein
     * fehlgeschlagener Schreibvorgang bricht die Messung NICHT ab — der Benutzer
     * hat sein Ergebnis bereits; nur das Merken misslingt.
     *
     * @param array<string, mixed> $result
     */
    private function persistPageSpeed(array $result): void
    {
        $path = $this->pagespeedStorePath((string) ($result['strategy'] ?? 'mobile'));
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->warning('PageSpeed-Ergebnis konnte nicht abgelegt werden (Verzeichnis).');
            return;
        }
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
            $this->logger->warning('PageSpeed-Ergebnis konnte nicht abgelegt werden (Schreiben).');
        }
    }

    // ------------------------------------------------------------------
    // Live-Analyse (seo-success): Proxy zum externen Dienst. Anders als der
    // lokale SEO-Bericht crawlt der Dienst die laufende Produktionssite. Job-
    // basiert: anstoßen → pollen → Ergebnis. Strikt getrennt von PageSpeed.
    // ------------------------------------------------------------------

    /**
     * liveanalyze — stößt eine Live-Analyse der eingegebenen Adresse an. Merkt
     * die Adresse pro Webseite (Mount-Konfig, [live_analysis] url) und den offenen
     * Job (für Weiterpollen nach Neuladen). Liefert {jobId, status}.
     *
     * @return array{jobId: string, status: string}
     */
    private function cmdLiveAnalyze(array $request): array
    {
        $this->requireMethod('POST');
        $client = $this->liveAnalysisClient();

        // Nur absolute http(s)-URLs; die verbindliche SSRF-Prüfung macht der Dienst.
        $url = trim((string) ($request['url'] ?? ''));
        if ($url === '' || !self::isPublicHttpUrl($url)) {
            throw ApiException::badRequest('ANALYZE-URL-INVALID');
        }

        // Adresse dieser Webseite dauerhaft merken (Mount-Konfiguration). Im
        // programmatischen Betrieb (custom.php) ohne schreibbare Mount-INI wird
        // nur analysiert, nicht gespeichert.
        if ($this->mountsPath !== null && $url !== $this->liveAnalysisUrl) {
            Config::updateSections($this->mountsPath, ['live_analysis' => ['url' => $url]]);
            $this->liveAnalysisUrl = $url;
        }

        $res = $client->analyzeStart($url, self::analyzeLang($request));
        $jobId = (string) ($res['job_id'] ?? '');

        // Offenen Job ablegen, damit das Panel nach dem Öffnen/Neuladen weiterpollt.
        $state = $this->readLiveAnalysis();
        $state['job'] = ['id' => $jobId, 'startedAt' => gmdate('c'), 'url' => $url];
        $this->persistLiveAnalysis($state);

        $this->logger->info(sprintf('Live-Analyse gestartet für %s (Job %s).', $url, $jobId));

        return ['jobId' => $jobId, 'status' => (string) ($res['status'] ?? 'queued')];
    }

    /**
     * liveanalyzestatus — fragt den Status eines Auftrags ab und reicht ihn samt
     * `stale`-Flag durch. Bei `done` wird das Ergebnis als jüngstes abgelegt und
     * der offene Job gelöscht; bei `error`/`cancelled` nur aufgeräumt.
     *
     * @return array<string, mixed>
     */
    private function cmdLiveAnalyzeStatus(array $request): array
    {
        $client = $this->liveAnalysisClient();
        $jobId = trim((string) ($request['jobId'] ?? ''));
        if ($jobId === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['jobId']);
        }

        $res = $client->analyzeStatus($jobId, self::analyzeLang($request));
        $status = (string) ($res['status'] ?? '');

        $out = ['jobId' => $jobId, 'status' => $status];
        if (isset($res['stale'])) {
            $out['stale'] = (bool) $res['stale'];
        }

        if ($status === 'done' && is_array($res['result'] ?? null)) {
            $state = $this->readLiveAnalysis();
            // Prüfzeitpunkt nur bei einem NEUEN Auftrag setzen. Wird derselbe
            // Auftrag nur erneut geholt (z. B. nach einem Sprachwechsel), bleibt
            // „zuletzt geprüft" stehen — geprüft wurde ja nichts neu.
            $isNewRun = ($state['jobId'] ?? null) !== $jobId || ($state['analyzedAt'] ?? null) === null;
            $state['job'] = null;
            $state['jobId'] = $jobId;
            $state['analyzedAt'] = $isNewRun ? gmdate('c') : $state['analyzedAt'];
            $state['result'] = $res['result'];
            $this->persistLiveAnalysis($state);
            $out['result'] = $res['result'];
            $out['analyzedAt'] = $state['analyzedAt'];
        } elseif ($status === 'error') {
            $this->clearLiveAnalysisJob();
            $out['error'] = (string) ($res['error'] ?? 'unbekannt');
        } elseif ($status === 'cancelled') {
            $this->clearLiveAnalysisJob();
            // Teil-Ergebnis nur durchreichen, NICHT als jüngstes ablegen (unvollständig).
            if (is_array($res['result'] ?? null)) {
                $out['result'] = $res['result'];
            }
        }

        return $out;
    }

    /**
     * liveanalyzecancel — bricht einen laufenden/wartenden Auftrag serverseitig
     * ab und räumt den offenen Job auf. Ist der Auftrag zwischenzeitlich fertig
     * geworden (Rennen mit dem Worker), liefert stattdessen der Statusabruf das
     * Ergebnis — kein harter Fehler.
     *
     * @return array<string, mixed>
     */
    private function cmdLiveAnalyzeCancel(array $request): array
    {
        $this->requireMethod('POST');
        $client = $this->liveAnalysisClient();
        $jobId = trim((string) ($request['jobId'] ?? ''));
        if ($jobId === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['jobId']);
        }

        try {
            $res = $client->analyzeCancel($jobId);
        } catch (ApiException $e) {
            if ($e->messageKey() === 'ANALYZE-JOB-NOT-CANCELABLE') {
                // Gerade abgeschlossen: den finalen Status holen (legt bei `done`
                // das Ergebnis ab) statt einen Fehler nach außen zu geben.
                return $this->cmdLiveAnalyzeStatus(['jobId' => $jobId]);
            }
            throw $e;
        }

        $this->clearLiveAnalysisJob();

        return ['jobId' => $jobId, 'status' => (string) ($res['status'] ?? 'cancelled')];
    }

    /**
     * liveanalyzelatest — liefert das zuletzt abgelegte Ergebnis dieser Webseite
     * samt Zeitpunkt und einen etwaigen offenen Job (damit das Panel nach dem
     * Öffnen/Neuladen sofort anzeigt bzw. weiterpollt).
     *
     * @return array{result: ?array<string, mixed>, analyzedAt: ?string, job: ?array<string, mixed>}
     */
    private function cmdLiveAnalyzeLatest(): array
    {
        $this->requireAuth();
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }

        $state = $this->readLiveAnalysis();

        return [
            'result' => $state['result'],
            'analyzedAt' => $state['analyzedAt'],
            'jobId' => $state['jobId'], // Auftrags-ID des angezeigten Ergebnisses (für den Export)
            'job' => $state['job'],
        ];
    }

    /**
     * liveanalyzehistory — Trend-Historie (Score-Verlauf) für den Host der
     * gespeicherten Adresse, neueste zuerst. Ohne gespeicherte Adresse leer.
     *
     * @return array{host?: string, runs: list<array<string, mixed>>}
     */
    private function cmdLiveAnalyzeHistory(array $request): array
    {
        $client = $this->liveAnalysisClient();

        $url = (string) ($this->liveAnalysisUrl ?? '');
        $host = $url !== '' ? (string) (parse_url($url, PHP_URL_HOST) ?: '') : '';
        if ($host === '') {
            return ['runs' => []];
        }

        $limit = max(1, min(100, (int) ($request['limit'] ?? 20)));
        $res = $client->analyzeHistory($host, $limit);

        return [
            'host' => $host,
            'runs' => is_array($res['runs'] ?? null) ? $res['runs'] : [],
        ];
    }

    /**
     * liveanalyzeexport — liefert den HTML- bzw. CSV-Bericht eines abgeschlossenen
     * Auftrags am JSON-Umschlag vorbei an den Browser (HTML inline für „als PDF
     * drucken", CSV als Download). Der Bericht stammt aus unserer eigenen API und
     * ist eigenständiges HTML+CSS ohne Skript; eine strenge CSP unterbindet
     * dennoch jede aktive Ausführung (XSS-Schutz analog {@see cmdRaw}).
     */
    private function cmdLiveAnalyzeExport(array $request): never
    {
        $client = $this->liveAnalysisClient();
        $jobId = trim((string) ($request['jobId'] ?? ''));
        if ($jobId === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['jobId']);
        }
        $format = ((string) ($request['format'] ?? 'html')) === 'csv' ? 'csv' : 'html';

        $export = $client->analyzeExport($jobId, $format, self::analyzeLang($request));

        $name = 'seo-live-analyse-' . preg_replace('/[^A-Za-z0-9_-]/', '', $jobId) . '.' . $format;
        $encoded = rawurlencode($name);
        $disposition = $format === 'csv' ? 'attachment' : 'inline';

        header('Content-Type: ' . $export['contentType']);
        header('Content-Length: ' . (string) strlen($export['body']));
        header("Content-Disposition: {$disposition}; filename=\"{$encoded}\"; filename*=UTF-8''{$encoded}");
        header('X-Content-Type-Options: nosniff');
        // Reiner Anzeige-Bericht: keine Skripte, keine externen Ressourcen zulassen.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:;");
        echo $export['body'];
        exit;
    }

    /**
     * Berichtssprache für den Dienst aus der Oberflächensprache des Requests —
     * nur die zweistellige Kennung (z. B. „de"/„en"), nie roh durchgereicht.
     * null → der Dienst nutzt seinen Standard (de).
     *
     * Der Dienst lokalisiert die Befunde über den sprachneutralen `type` erst
     * BEIM ABRUF. Deshalb genügt es, die Sprache mitzusenden; ein Sprachwechsel
     * im CMS kostet nur einen erneuten Abruf, keinen neuen Lauf.
     *
     * @param array<string, mixed> $request
     */
    private static function analyzeLang(array $request): ?string
    {
        $locale = strtolower(substr((string) ($request['locale'] ?? ''), 0, 2));

        return preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : null;
    }

    /**
     * Baut den seo-success-Client für die Live-Analyse und prüft die
     * Voraussetzungen (Anmeldung, Pro-Lizenz, Hugo-Projekt, konfigurierter
     * Dienst). requireMethod('POST') macht der jeweilige Schreibbefehl selbst.
     */
    private function liveAnalysisClient(): SeoSuccessClient
    {
        $this->requireAuth();
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }
        if ($this->services['serviceKey'] === null || $this->services['serviceUrl'] === null) {
            throw new ApiException('ECONFIG', 409, 'SERVICE-NOT-CONFIGURED');
        }

        return new SeoSuccessClient(
            (string) $this->services['serviceUrl'],
            (string) $this->services['serviceKey'],
        );
    }

    /**
     * Speicherpfad des Live-Analyse-Zustands dieser Webseite — je Projekt getrennt
     * unter var/analyze/<hash(source)>.json. null, wenn kein Hugo-Projekt vorliegt.
     */
    private function liveAnalysisStorePath(): ?string
    {
        if ($this->hugo === null) {
            return null;
        }

        return __DIR__ . '/../var/analyze/' . sha1((string) $this->hugo['source']) . '.json';
    }

    /**
     * Liest den abgelegten Live-Analyse-Zustand oder liefert die Vorgaben
     * (nichts gespeichert): {job, jobId, analyzedAt, result}.
     *
     * @return array{job: ?array<string, mixed>, jobId: ?string, analyzedAt: ?string, result: ?array<string, mixed>}
     */
    private function readLiveAnalysis(): array
    {
        $default = ['job' => null, 'jobId' => null, 'analyzedAt' => null, 'result' => null];
        $path = $this->liveAnalysisStorePath();
        if ($path === null || !is_file($path)) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? array_merge($default, $data) : $default;
    }

    /**
     * Legt den Live-Analyse-Zustand ab (überschreibt den vorherigen). Best effort:
     * ein fehlgeschlagener Schreibvorgang bricht nichts ab — der Benutzer hat sein
     * Ergebnis bereits; nur das Merken misslingt.
     *
     * @param array<string, mixed> $state
     */
    private function persistLiveAnalysis(array $state): void
    {
        $path = $this->liveAnalysisStorePath();
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->warning('Live-Analyse-Zustand konnte nicht abgelegt werden (Verzeichnis).');
            return;
        }
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
            $this->logger->warning('Live-Analyse-Zustand konnte nicht abgelegt werden (Schreiben).');
        }
    }

    /** Löscht nur den offenen Job aus dem abgelegten Zustand (Ergebnis bleibt). */
    private function clearLiveAnalysisJob(): void
    {
        $state = $this->readLiveAnalysis();
        if ($state['job'] === null) {
            return;
        }
        $state['job'] = null;
        $this->persistLiveAnalysis($state);
    }

    /**
     * CLI-Einstieg (Cron): verbessert die nächsten bis zu $limit noch nicht
     * verbesserten, geprüften Content-Dateien mit Score < 100 — im Schreibmodus
     * "auto" (ohne Bestätigung). KEINE Web-Authentifizierung; der Aufruf erfolgt
     * lokal auf dem Server. Voraussetzungen: Pro-Lizenz (die Domäne muss über
     * $_SERVER['HTTP_HOST'] gesetzt sein), Hugo-Projekt und KI-Schlüssel.
     *
     * Mit $dryRun = true werden nur die Kandidaten ermittelt und zurückgegeben —
     * ohne API-Aufruf, ohne Schreiben und ohne Pro-/KI-Voraussetzung (zum Testen).
     *
     * @return array<string, mixed> Zusammenfassung (Kandidatenzahl, verarbeitete Dateien)
     */
    public function improveNextContent(int $limit = 1, ?string $locale = null, bool $dryRun = false): array
    {
        if ($dryRun) {
            // Der Probelauf ändert nichts und bleibt zum Testen auch bei Pause
            // erlaubt — nur der echte Lauf setzt aus.
            return $this->runImproveBatch($limit, $locale, true);
        }
        if (!empty($this->cronPause['pauseImprove'])) {
            return $this->cronPausedResult('improve');
        }

        return $this->withCronHeartbeat(
            'improve',
            fn (): array => $this->runImproveBatch($limit, $locale, false),
            static fn (array $r): array => [true, sprintf(
                '%d Kandidaten, %d verarbeitet',
                (int) ($r['candidates'] ?? 0),
                count($r['processed'] ?? []),
            )],
        );
    }

    /**
     * Der eigentliche Verbesserungslauf hinter {@see improveNextContent()} —
     * ohne Herzschlag, damit der Probelauf den Cron-Takt nicht verfälscht.
     *
     * @return array<string, mixed>
     */
    private function runImproveBatch(int $limit, ?string $locale, bool $dryRun): array
    {
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }
        if (!$dryRun) {
            if ($this->ai['apiKey'] === null) {
                throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
            }
            $this->requirePro();
        }

        $locale ??= 'de';
        $limit = max(1, $limit);
        $work = $this->pendingImproveList();

        // Probelauf: nur zeigen, was verarbeitet würde.
        if ($dryRun) {
            $preview = array_map(
                static fn (array $e): array => [
                    'path' => (string) ($e['mount'] ?? '') . '/' . (string) ($e['rel'] ?? ''),
                    'score' => $e['score'] ?? null,
                    // Ohne Prüfung vorgemerkt (kein Score) — für die Anzeige.
                    'queued' => (bool) ($e['queued'] ?? false),
                    'written' => false,
                ],
                array_slice($work, 0, $limit),
            );

            return ['candidates' => count($work), 'dryRun' => true, 'processed' => $preview];
        }

        $service = $this->assistantService('auto', $this->ai['modelCron'], 'cron', (bool) $this->ai['forceThinkingCron']);
        $processed = [];
        foreach (array_slice($work, 0, $limit) as $entry) {
            $mount = (string) ($entry['mount'] ?? '');
            $rel = (string) ($entry['rel'] ?? '');
            if ($mount === '' || $rel === '' || !isset($this->resolver->all()[$mount])) {
                continue;
            }
            $path = $mount . '/' . $rel;
            $wrote = $this->runImprove($service, $path, $locale);

            // Automatikmodus: den eben abgelegten Entwurf gleich terminieren,
            // statt ihn auf die Freigabe warten zu lassen. Ohne Automatik bleibt
            // er offen — die bisherige Voreinstellung.
            $publishAt = null;
            if ($wrote && !empty($this->improve['auto'])) {
                $publishAt = $this->autoScheduleDraft($mount, $rel);
            }

            $this->logger->info(sprintf(
                'Cron-Verbesserung: %s (%s)',
                $path,
                $wrote
                    ? ($publishAt !== null ? 'geschrieben, terminiert auf ' . $publishAt : 'geschrieben')
                    : 'keine Änderung',
            ));
            $processed[] = ['path' => $path, 'written' => $wrote, 'publishAt' => $publishAt];
        }

        return ['candidates' => count($work), 'processed' => $processed, 'auto' => !empty($this->improve['auto'])];
    }

    /**
     * Gesundheitscheck der Webseite (Pro-Funktion, CLI-/Cron-Einstieg). Führt den
     * SEO-Audit über den vorhandenen public/-Ordner aus (baut NICHT selbst) und
     * benachrichtigt bei Fehlern oder Warnungen per E-Mail (siehe [mail] in der
     * hugocms.ini). Spiegelt {@see improveNextContent()}: kein requireAuth (im CLI
     * gibt es keine Session), aber requirePro — außer beim Probelauf.
     *
     * @return array{summary: array<string,int>, pagesScanned: int, problems: bool, mailed: bool, dryRun: bool, reportId: ?string}
     */
    public function runHealthCheck(bool $dryRun = false): array
    {
        if (!$dryRun && !empty($this->cronPause['pauseHealthcheck'])) {
            return $this->cronPausedResult('healthcheck');
        }
        if ($dryRun) {
            return $this->runHealthCheckBatch(true);
        }

        return $this->withCronHeartbeat(
            'healthcheck',
            fn (): array => $this->runHealthCheckBatch(false),
            static fn (array $r): array => [true, sprintf(
                '%d Seiten, %d Fehler / %d Warnungen',
                (int) ($r['pagesScanned'] ?? 0),
                (int) ($r['summary']['error'] ?? 0),
                (int) ($r['summary']['warning'] ?? 0),
            )],
        );
    }

    /**
     * Der eigentliche Gesundheitscheck hinter {@see runHealthCheck()} — ohne
     * Herzschlag, damit der Probelauf den Cron-Takt nicht verfälscht.
     *
     * @return array{summary: array<string,int>, pagesScanned: int, problems: bool, mailed: bool, dryRun: bool, reportId: ?string}
     */
    private function runHealthCheckBatch(bool $dryRun): array
    {
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }
        if (!$dryRun) {
            $this->requirePro();
        }
        // Der Lauf parst alle gebauten Seiten — wie cmdAudit großzügig bemessen.
        @set_time_limit(120);

        // Wirft AUDIT-NO-BUILD-OUTPUT, wenn public/ fehlt — der geforderte klare
        // Fehler statt eines leeren Berichts.
        $report = $this->auditStore()->run();
        $summary = is_array($report['summary'] ?? null)
            ? $report['summary']
            : ['error' => 0, 'warning' => 0, 'hint' => 0];
        $problems = ((int) ($summary['error'] ?? 0) + (int) ($summary['warning'] ?? 0)) > 0;

        $this->logger->info(sprintf(
            'Gesundheitscheck: %d Seiten, %d Fehler / %d Warnungen / %d Hinweise (%ss).',
            (int) ($report['pagesScanned'] ?? 0),
            (int) ($summary['error'] ?? 0),
            (int) ($summary['warning'] ?? 0),
            (int) ($summary['hint'] ?? 0),
            (string) ($report['seconds'] ?? '0'),
        ));

        $mailed = false;
        if ($problems && !$dryRun) {
            if (empty($this->mail['configured'])) {
                // Nicht still schlucken: Es gibt zu meldende Probleme, aber keinen
                // Versandweg — als Konfigurationsfehler melden.
                $this->logger->warning('Gesundheitscheck: Probleme gefunden, aber [mail] ist nicht konfiguriert — keine Benachrichtigung möglich.');
                throw new ApiException('ECONFIG', 409, 'MAIL-NOT-CONFIGURED');
            }
            [$subject, $body] = AuditMailReport::format(
                $report,
                $this->helpDir,
                'de',
                SiteKey::host($_SERVER),
            );
            $this->buildMailer()->send((string) $this->mail['to'], $subject, $body);
            $this->logger->info(sprintf('Gesundheitscheck: Benachrichtigung an %s gesendet.', (string) $this->mail['to']));
            $mailed = true;
        }

        return [
            'summary' => $summary,
            'pagesScanned' => (int) ($report['pagesScanned'] ?? 0),
            'problems' => $problems,
            'mailed' => $mailed,
            'dryRun' => $dryRun,
            'reportId' => $report['id'] ?? null,
        ];
    }

    /** Baut den SMTP-Mailer aus der [mail]-Konfiguration (setzt configured voraus). */
    private function buildMailer(): Mailer
    {
        return new Mailer(
            (string) $this->mail['host'],
            (int) $this->mail['port'],
            (string) $this->mail['security'],
            $this->mail['user'],
            $this->mail['pass'],
            (string) $this->mail['from'],
        );
    }

    /**
     * Abgeleitete Arbeitsliste für die KI-Verbesserung: geprüfte Content-Dateien
     * mit Score < 100, die noch nicht verbessert wurden und deren Quelle
     * vorhanden ist. Dateien mit einem offenen Freigabe-Entwurf sind
     * ausgeschlossen — sie warten auf Freigabe und werden nicht erneut bearbeitet
     * (sonst überschriebe jeder Lauf den offenen Entwurf). Reihenfolge wie
     * {@see ContentQualityService::list()} (neueste Prüfung zuerst).
     *
     * @return list<array<string, mixed>>
     */
    private function pendingImproveList(): array
    {
        // Schlüssel aller offenen Entwürfe (sha1(mount:rel)) für den Ausschluss.
        $pendingKeys = [];
        foreach ($this->reviewStore()->list() as $draft) {
            if (isset($draft['key'])) {
                $pendingKeys[(string) $draft['key']] = true;
            }
        }

        return array_values(array_filter(
            $this->contentQualityStore()->list(),
            static fn (array $e): bool => empty($e['improvedAt'])
                // Vorgemerkt (ohne Prüfung eingereiht) ODER geprüft mit Score < 100.
                && (!empty($e['queued']) || (is_numeric($e['score'] ?? null) && $e['score'] < 100))
                && ($e['sourceMissing'] ?? false) === false
                && !isset($pendingKeys[ReviewStore::keyFor((string) ($e['mount'] ?? ''), (string) ($e['rel'] ?? ''))]),
        ));
    }

    /**
     * Automatikmodus: terminiert den frisch erzeugten Entwurf einer Datei auf
     * einen zufälligen Zeitpunkt, statt ihn offen auf die Freigabe warten zu
     * lassen. Liefert den gesetzten Zeitpunkt (ISO 8601) oder null, wenn es
     * keinen Entwurf gibt oder er bereits terminiert war.
     *
     * Verteilung: Das Tagesfenster wird in `perDay` gleich große Abschnitte
     * geteilt; der Entwurf bekommt den ersten noch freien Abschnitt (ab heute
     * vorwärts) und darin eine zufällige Minute. Dadurch stehen die Freigaben
     * nie dicht beieinander, die Tagesmenge wird eingehalten, und trotzdem ist
     * kein Zeitpunkt vorhersagbar.
     */
    private function autoScheduleDraft(string $mount, string $rel): ?string
    {
        $store = $this->reviewStore();
        $key = ReviewStore::keyFor($mount, $rel);
        $draft = $store->forKey($key);
        if ($draft === null || !empty($draft['publishAt'])) {
            return null;
        }

        $slot = $this->nextFreeSlot();
        if ($slot === null) {
            // Kein freier Platz im Suchzeitraum: Das Fenster ist zu eng oder die
            // Tagesmenge zu klein für den Rückstau. Der Entwurf bleibt offen zur
            // Freigabe — das ist der richtige Rückfall, darf aber nicht
            // unbemerkt bleiben, sonst wundert sich niemand über die wachsende
            // Warteschlange.
            $this->logger->warning(sprintf(
                'Automatische Terminierung: kein freier Platz in den nächsten %d Tagen für %s/%s '
                . '(Fenster %s–%s, %d/Tag). Der Entwurf bleibt offen zur Freigabe.',
                self::AUTO_SCHEDULE_HORIZON_DAYS,
                $mount,
                $rel,
                (string) $this->improve['windowStart'],
                (string) $this->improve['windowEnd'],
                $this->improveSlotPlan()['perDay'],
            ));

            return null;
        }
        $draft['publishAt'] = gmdate('c', $slot);
        $store->put($draft);

        return $draft['publishAt'];
    }

    /**
     * Teilt das Veröffentlichungsfenster in Abschnitte auf — die eine Stelle,
     * an der diese Rechnung steht. {@see nextFreeSlot()} vergibt danach die
     * Termine, whoami und die Projekteinstellungen melden `perDay` daraus an
     * den Client, damit die Oberfläche die TATSÄCHLICHE Tagesmenge nennt.
     *
     * Passen weniger Minuten ins Fenster als Freigaben gewünscht sind, wird die
     * Tagesmenge auf die Zahl der Minuten gekürzt (mehr als eine Freigabe je
     * Minute ergibt keinen Sinn). Der Überhang wandert auf die Folgetage.
     *
     * @return array{windowStart: int, windowEnd: int, perDay: int, slotLength: int, inset: int}
     */
    private function improveSlotPlan(): array
    {
        [$startH, $startM] = array_map('intval', explode(':', (string) $this->improve['windowStart']));
        [$endH, $endM] = array_map('intval', explode(':', (string) $this->improve['windowEnd']));
        $windowStart = $startH * 60 + $startM;
        $windowEnd = $endH * 60 + $endM;
        $minutes = max(1, $windowEnd - $windowStart);

        $perDay = max(1, (int) $this->improve['perDay']);
        $slotLength = intdiv($minutes, $perDay);
        if ($slotLength < 1) {
            // Mehr Plätze als Minuten im Fenster — dann eben minutengenau.
            $slotLength = 1;
            $perDay = min($perDay, $minutes);
        }

        return [
            'windowStart' => $windowStart,
            'windowEnd' => $windowEnd,
            'perDay' => $perDay,
            'slotLength' => $slotLength,
            // Viertel der Abschnittslänge an jedem Rand → die Zufallsminute
            // stammt aus der mittleren Hälfte (siehe nextFreeSlot).
            'inset' => intdiv($slotLength, 4),
        ];
    }

    /**
     * Sucht den nächsten freien Veröffentlichungsplatz für den Automatikmodus.
     * Belegt sind Plätze durch bereits terminierte Entwürfe; „frei“ heißt: an
     * diesem Tag ist dieser Abschnitt des Fensters noch unbesetzt und liegt
     * nicht in der Vergangenheit.
     *
     * Sucht bis zu {@see AUTO_SCHEDULE_HORIZON_DAYS} Tage voraus — reicht selbst
     * für einen großen Rückstand bei kleiner Tagesmenge und verhindert eine
     * Endlosschleife.
     *
     * @return ?int Unix-Zeitstempel oder null, wenn im Suchfenster nichts frei ist
     */
    private function nextFreeSlot(): ?int
    {
        ['windowStart' => $windowStart, 'perDay' => $perDay, 'slotLength' => $slotLength, 'inset' => $inset]
            = $this->improveSlotPlan();

        // Bereits vergebene Plätze: Tag (Y-m-d, Serverzeit) → belegte Abschnitte.
        $taken = [];
        foreach ($this->reviewStore()->list() as $entry) {
            $at = $entry['publishAt'] ?? null;
            if (!is_string($at) || $at === '') {
                continue;
            }
            $ts = strtotime($at);
            if ($ts === false) {
                continue;
            }
            $minutes = (int) date('G', $ts) * 60 + (int) date('i', $ts);
            $index = $slotLength > 0 ? intdiv($minutes - $windowStart, $slotLength) : 0;
            $taken[date('Y-m-d', $ts)][$index] = true;
        }

        $skipWeekends = !empty($this->improve['skipWeekends']);
        $now = time();
        for ($day = 0; $day < self::AUTO_SCHEDULE_HORIZON_DAYS; $day++) {
            $dayStart = strtotime("+{$day} day", $now);
            // Samstag (6) und Sonntag (0) auf Wunsch überspringen (Serverzeit).
            if ($skipWeekends && in_array((int) date('w', $dayStart), [0, 6], true)) {
                continue;
            }
            $key = date('Y-m-d', $dayStart);
            for ($slot = 0; $slot < $perDay; $slot++) {
                if (isset($taken[$key][$slot])) {
                    continue;
                }
                // Randabstand: Die Zufallsminute kommt aus der MITTLEREN HÄLFTE
                // des Abschnitts. Ohne ihn könnten zwei Freigaben an der Grenze
                // zweier Abschnitte fast zusammenfallen (etwa 12:59 und 13:01);
                // so liegen sie immer mindestens eine halbe Abschnittslänge
                // auseinander.
                $from = $windowStart + $slot * $slotLength + $inset;
                $to = $windowStart + ($slot + 1) * $slotLength - 1 - $inset;
                $base = mktime(0, 0, 0, (int) date('n', $dayStart), (int) date('j', $dayStart), (int) date('Y', $dayStart));
                if ($base === false) {
                    continue;
                }
                $earliest = max($base + $from * 60, $now + 60);
                $latest = $base + $to * 60;
                if ($earliest > $latest) {
                    continue; // Abschnitt liegt (heute) bereits in der Vergangenheit
                }

                return random_int($earliest, $latest);
            }
        }

        return null;
    }

    /**
     * Führt einen Verbesserungslauf (auto) für eine Datei aus, inkl. begrenztem
     * Fortsetzen, falls die Schrittgrenze eines Zugs erreicht wird. Liefert true,
     * wenn (mindestens) ein Schreibvorgang stattgefunden hat.
     */
    private function runImprove(AssistantService $service, string $path, string $locale): bool
    {
        $messages = [['role' => 'user', 'content' => $this->improveInstruction($path, $locale)]];
        $wrote = false;
        for ($turn = 0; $turn < 4; $turn++) {
            @set_time_limit(300);
            $run = $service->run($messages, null, $locale, $path);
            $messages = is_array($run['messages'] ?? null) ? $run['messages'] : $messages;
            if (!empty($run['actions'])) {
                $wrote = true;
            }
            if (empty($run['aborted'])) {
                break;
            }
            $messages[] = [
                'role' => 'user',
                'content' => str_starts_with(strtolower($locale), 'en') ? 'Continue.' : 'Mach weiter.',
            ];
        }

        return $wrote;
    }

    /**
     * Vermerkt eine KI-Bearbeitung am Content-Qualitäts-Eintrag der Datei (jede
     * vom Assistenten geschriebene Datei gilt als „verbessert"). Ohne
     * Hugo-Projekt oder ohne vorhandenen Eintrag ein No-op. Das Modell ist das
     * des schreibenden Assistenten (interaktiv `model`, Cron `model_cron`);
     * ohne Angabe der interaktive Standard.
     */
    private function markFileImproved(string $fileId, ?string $model = null): void
    {
        if ($this->hugo === null) {
            return;
        }
        $this->contentQualityStore()->markImproved($fileId, $model ?? $this->ai['model']);
    }

    /**
     * Startet einen Assistenten-Zug, der eine einzelne Content-Datei anhand
     * ihres Gesamt-Berichts verbessert. Auslösung durch den Benutzer (beliebige
     * Datei); der spätere Cron nutzt denselben Weg (erste Datei der Liste). Der
     * Schreibmodus ist der konfigurierte — bei "confirm" pausiert der Lauf vor
     * dem Schreiben und der Client lässt bestätigen (wie beim normalen Assistenten).
     */
    private function cmdAssistantImprove(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }
        if ($this->ai['apiKey'] === null) {
            throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
        }
        $id = $this->requireParam($request, 'id');
        $locale = (string) ($request['locale'] ?? 'de');
        $r = $this->resolver->resolve($id, true);
        $path = $r['mount']->name() . '/' . $r['rel'];

        @set_time_limit(180);

        return $this->assistantService()->run(
            [['role' => 'user', 'content' => $this->improveInstruction($path, $locale)]],
            null,
            $locale,
            $path,
        );
    }

    /**
     * Startanweisung für den Verbesserungslauf (in der Sprache des Benutzers).
     * Denselben Text bekommt der Cron-Verbesserer ({@see runImprove}) — der
     * automatische Lauf arbeitet also nach exakt denselben Vorgaben wie der von
     * Hand ausgelöste.
     *
     * Der Abschnitt zu den SEO-Funden ist bewusst ausführlich: Der Bericht aus
     * `get_file_report` liefert zu jedem Fund `fixable` (über diese Datei
     * behebbar), bei Duplikaten `duplicateOf` (die übrigen betroffenen Seiten)
     * und im Glossar `rules` die Bedeutung jeder vorkommenden Regel. Ohne
     * ausdrückliche Anweisung nutzt das Modell diese Felder nicht zuverlässig
     * und lässt SEO-Funde liegen.
     */
    private function improveInstruction(string $path, string $locale): string
    {
        if (str_starts_with(strtolower($locale), 'en')) {
            return "Improve the existing content file `{$path}`. Steps: (1) call get_file_report for this path and act on BOTH parts — the content-quality verdict AND the SEO findings; (2) read the file; (3) fix the reported issues and write the improved version in a SINGLE write_file call. If the report contains a non-empty `userInstruction` field, it is an explicit instruction from the site owner and OVERRIDES conflicting findings or suggestions — follow it exactly. Adopt the file's existing front-matter format.\n\n"
                . "The SEO findings are NOT optional — work through `audit.issues` completely:\n"
                . "- Fix EVERY finding marked `fixable: true`. These are fixable from this content file alone.\n"
                . "- `audit.rules` explains what each rule requires — consult it instead of guessing from the rule ID.\n"
                . "- A finding with `duplicateOf` means the SAME title or meta description is used on the pages listed there. Write a text that clearly distinguishes THIS page from them; do not reuse their wording.\n"
                . "- Findings WITHOUT `fixable` live in the theme or in the URL structure. Do not attempt them and do not invent front-matter fields for them — name them briefly in your reply instead.\n"
                . "- If you deliberately leave a fixable finding unfixed, say which one and why.\n\n"
                . "If a SEO finding depends on which front-matter field the theme reads (e.g. og:image), you MAY READ the relevant layout/partial to find the correct field — but WRITE only this content file. Keep the front matter valid and preserve the author's meaning.";
        }

        return "Verbessere die bestehende Content-Datei `{$path}`. Vorgehen: (1) rufe get_file_report für diesen Pfad auf und beachte BEIDE Teile — das Qualitätsurteil UND die SEO-Funde; (2) lies die Datei; (3) behebe die gemeldeten Probleme und schreibe die verbesserte Fassung in EINEM write_file-Aufruf. Enthält der Bericht ein nicht leeres Feld `userInstruction`, ist das eine ausdrückliche Anweisung des Betreibers und hat VORRANG vor widersprechenden Funden oder Vorschlägen — befolge sie genau. Übernimm das vorhandene Front-Matter-Format der Datei.\n\n"
            . "Die SEO-Funde sind NICHT optional — arbeite `audit.issues` vollständig ab:\n"
            . "- Behebe JEDEN Fund mit `fixable: true`. Diese Funde lassen sich allein über diese Content-Datei beheben.\n"
            . "- `audit.rules` erklärt, was die jeweilige Regel verlangt — sieh dort nach, statt aus der Regel-ID zu raten.\n"
            . "- Ein Fund mit `duplicateOf` bedeutet: Derselbe Titel bzw. dieselbe Meta-Description steht auf den dort genannten Seiten. Schreibe einen Text, der DIESE Seite deutlich von ihnen unterscheidet; übernimm ihre Formulierung nicht.\n"
            . "- Funde OHNE `fixable` wurzeln im Theme oder in der URL-Struktur. Versuche sie nicht zu beheben und erfinde dafür keine Front-Matter-Felder — nenne sie stattdessen kurz in deiner Antwort.\n"
            . "- Lässt du einen behebbaren Fund bewusst liegen, sage welchen und warum.\n\n"
            . "Hängt ein SEO-Fund davon ab, welches Front-Matter-Feld das Theme auswertet (etwa og:image), darfst du das betreffende Layout/Partial NUR LESEN, um das richtige Feld zu finden — GESCHRIEBEN wird ausschließlich diese Content-Datei. Halte das Front-Matter gültig und bewahre die Aussage des Autors.";
    }

    /**
     * Micro-Auftrag: lässt den Assistenten GENAU EINEN Fund des SEO-Berichts
     * beheben — der Knopf sitzt an der Fundzeile des Berichts.
     *
     * Der Fund wird NICHT vom Client übernommen, sondern anhand von Lauf-ID,
     * Regel-ID und URL im gespeicherten Bericht nachgeschlagen. So bestimmt
     * allein der Server, was in der Anweisung an das Modell steht, und der
     * Client kann keinen Text einschleusen.
     *
     * Schreibmodus, Bestätigungspause und Entwurfs-Schiene sind die des
     * normalen Assistenten — es ist derselbe Werkzeug-Loop.
     */
    private function cmdAssistantFix(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }
        if ($this->ai['apiKey'] === null) {
            throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
        }
        $runId = $this->requireParam($request, 'runId');
        $ruleId = $this->requireParam($request, 'ruleId');
        $url = isset($request['url']) ? (string) $request['url'] : null;
        $locale = (string) ($request['locale'] ?? 'de');

        // Nur Regeln, die sich über die Content-Datei beheben lassen. Alles
        // andere gehört ins Theme oder in eine Struktur-Entscheidung.
        if (!RuleCatalog::fixable($ruleId)) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-RULE-NOT-FIXABLE', [$ruleId]);
        }

        $report = $this->auditStore()->get($runId);
        $issue = self::findIssue($report, $ruleId, $url);
        if ($issue === null) {
            throw ApiException::notFound('AUDIT-ISSUE-NOT-FOUND', [$ruleId]);
        }

        $rel = (string) ($issue['sourceFile'] ?? '');
        $fileId = $rel === ''
            ? null
            : $this->resolveFileId((string) $this->hugo['source'] . '/' . $rel);
        if ($fileId === null) {
            throw ApiException::notFound('AUDIT-ISSUE-NO-SOURCE', [$ruleId]);
        }
        $r = $this->resolver->resolve($fileId, true);
        $path = $r['mount']->name() . '/' . $r['rel'];

        // Wie beim Verbesserungslauf großzügig bemessen (Lesen, Nachdenken,
        // Schreiben in einem Request), aber ohne Hintergrundprozess.
        @set_time_limit(180);

        return $this->assistantService()->run(
            [['role' => 'user', 'content' => $this->fixInstruction($path, $issue, $report, $locale)]],
            null,
            $locale,
            $path,
        );
    }

    /**
     * Sucht einen Fund im Bericht: erst Regel-ID UND URL, sonst — falls keine
     * URL übergeben wurde — den ersten Fund dieser Regel.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>|null
     */
    private static function findIssue(array $report, string $ruleId, ?string $url): ?array
    {
        $fallback = null;
        foreach ($report['issues'] ?? [] as $issue) {
            if (!is_array($issue) || ($issue['ruleId'] ?? null) !== $ruleId) {
                continue;
            }
            if ($url === null || $url === '') {
                return $issue;
            }
            if ((string) ($issue['url'] ?? '') === $url) {
                return $issue;
            }
            $fallback ??= $issue;
        }

        return $url === null ? $fallback : null;
    }

    /**
     * Startanweisung für den Micro-Auftrag. Sie besteht aus drei Teilen: dem
     * eng gefassten Auftrag, dem Regelwissen aus der Hilfe-Datenbank (dieselben
     * Texte, die der Benutzer im Bericht aufruft) und — bei Duplikat-Funden —
     * den übrigen betroffenen Seiten. Der letzte Teil ist wesentlich: Ohne ihn
     * schreibt das Modell der Seite erneut einen austauschbaren Text, mit ihm
     * einen unterscheidenden.
     *
     * @param array<string, mixed> $issue
     * @param array<string, mixed> $report
     */
    private function fixInstruction(string $path, array $issue, array $report, string $locale): string
    {
        $en = str_starts_with(strtolower($locale), 'en');
        $ruleId = (string) ($issue['ruleId'] ?? '');
        $url = (string) ($issue['url'] ?? '');

        $lines = [];
        $lines[] = $en
            ? "Fix EXACTLY ONE reported SEO issue in the content file `{$path}` — change nothing else."
            : "Behebe in der Content-Datei `{$path}` GENAU EINEN gemeldeten SEO-Fund — ändere sonst nichts.";
        $lines[] = '';
        $lines[] = ($en ? 'Rule: ' : 'Regel: ') . $ruleId;
        if ($url !== '') {
            $lines[] = ($en ? 'Affected page: ' : 'Betroffene Seite: ') . $url;
        }

        $help = $this->ruleHelp($ruleId, $locale);
        if ($help !== null) {
            $lines[] = '';
            $lines[] = $en ? '--- What the rule means ---' : '--- Was die Regel bedeutet ---';
            $lines[] = $help;
        }

        $others = self::duplicateSiblings($report, $issue);
        if ($others !== []) {
            $lines[] = '';
            $lines[] = $en
                ? 'The SAME text is used on these other pages. Your new text must clearly distinguish this page from them — do not reuse their wording:'
                : 'Derselbe Text steht auf diesen weiteren Seiten. Dein neuer Text muss diese Seite deutlich von ihnen unterscheiden — übernimm ihre Formulierung nicht:';
            foreach ($others as $o) {
                $lines[] = '- ' . $o;
            }
        }

        $lines[] = '';
        $lines[] = $en
            ? "Steps: (1) read `{$path}`; (2) fix this ONE issue and write the file back in a SINGLE write_file call. Keep the existing front-matter format valid, preserve the author's meaning, and leave everything unrelated to this rule untouched. If the cause is not in this file but in the theme, do NOT write — explain what has to change in which layout instead."
            : "Vorgehen: (1) lies `{$path}`; (2) behebe DIESEN einen Fund und schreibe die Datei in EINEM write_file-Aufruf zurück. Halte das vorhandene Front-Matter-Format gültig, bewahre die Aussage des Autors und lass alles unangetastet, was mit dieser Regel nichts zu tun hat. Liegt die Ursache nicht in dieser Datei, sondern im Theme, schreibe NICHT — erkläre stattdessen, was in welchem Layout zu ändern ist.";

        return implode("\n", $lines);
    }

    /** Höchstlänge des Regelwissens in der Anweisung (Zeichen). */
    private const int FIX_HELP_MAX = 1500;

    /** Höchstzahl der genannten Schwester-Seiten einer Duplikat-Gruppe. */
    private const int FIX_SIBLINGS_MAX = 12;

    /**
     * Erklärtext einer Regel aus der Hilfe-Datenbank (Titel + Rumpf, gekürzt)
     * oder null, wenn es keinen gibt. Fehler sind unerheblich — der Auftrag
     * läuft auch ohne Regelwissen.
     */
    private function ruleHelp(string $ruleId, string $locale): ?string
    {
        try {
            $topic = (new HelpService($this->helpDir))->topic('audit', $ruleId, $locale);
        } catch (Throwable) {
            return null;
        }
        $text = trim((string) ($topic['title'] ?? '') . "\n\n" . (string) ($topic['body'] ?? ''));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::FIX_HELP_MAX
            ? mb_substr($text, 0, self::FIX_HELP_MAX) . ' …'
            : $text;
    }

    /**
     * Die übrigen Seiten einer Duplikat-Gruppe (gleiche Regel, gleiche
     * `context.group`-Kennung) als lesbare Zeilen. Leer, wenn der Fund zu
     * keiner Gruppe gehört — etwa bei Berichten aus der Zeit vor der
     * Gruppierung.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $issue
     * @return list<string>
     */
    private static function duplicateSiblings(array $report, array $issue): array
    {
        $group = $issue['context']['group'] ?? null;
        if (!is_string($group) || $group === '') {
            return [];
        }
        $ruleId = $issue['ruleId'] ?? null;
        $url = $issue['url'] ?? null;

        $out = [];
        foreach ($report['issues'] ?? [] as $other) {
            if (!is_array($other)
                || ($other['ruleId'] ?? null) !== $ruleId
                || ($other['context']['group'] ?? null) !== $group
                || ($other['url'] ?? null) === $url) {
                continue;
            }
            $line = (string) ($other['url'] ?? '');
            $src = $other['sourceFile'] ?? null;
            if (is_string($src) && $src !== '') {
                $line .= ' (' . $src . ')';
            }
            $out[] = $line;
            if (count($out) >= self::FIX_SIBLINGS_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * Gesamt-Bericht einer Datei anhand ihrer Dateimanager-ID (für das
     * Assistenten-Werkzeug get_file_report). Qualitätsurteil optional (null,
     * wenn die Datei nie geprüft wurde), SEO-Funde aus dem jüngsten Lauf.
     *
     * Die Sprache betrifft allein das Regel-Glossar der SEO-Funde — es ist
     * Arbeitsmaterial für das Modell, kein nutzersichtbarer Text. Deshalb steht
     * hier ein fester Vorgabewert, statt die Sprache durch die Werkzeug-Kette
     * zu fädeln; die Sprache der Antwort steuert der Systemprompt.
     *
     * @return array<string, mixed>
     */
    private function buildFileReportById(string $fileId, string $locale = 'de'): array
    {
        $r = $this->resolver->resolve($fileId, true);
        $mount = $r['mount']->name();
        $entry = $this->contentQualityStore()->forFile($fileId); // null, falls nie geprüft
        $base = is_array($entry) ? $entry : ['mount' => $mount, 'rel' => $r['rel'], 'title' => basename($r['rel'])];

        return [
            'file' => $this->withContentFileId([
                'mount' => $mount,
                'rel' => $r['rel'],
                'title' => $base['title'] ?? basename($r['rel']),
            ]),
            'contentQuality' => $entry,
            'audit' => $this->auditIssuesForEntry($base, $locale),
        ];
    }

    /** Erlaubte Log-Stufen — gemeinsam für Lesen (config) und Schreiben. */
    private const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    /**
     * Über den Konfigurationsdialog umschaltbare Anmeldeverfahren. Weitere
     * Treiber lassen sich programmatisch registrieren (Connector-Option
     * „authDrivers"); die stehen dann nicht im Dialog, bleiben aber gültig.
     */
    private const AUTH_DRIVERS = ['singleuser', 'multiuser'];

    /** Erlaubte Schreibmodi des KI-Assistenten. */
    private const AI_WRITE_MODES = ['readonly', 'confirm', 'auto'];

    /** Erlaubte SMTP-Sicherheitsstufen für den E-Mail-Versand. */
    private const MAIL_SECURITIES = ['tls', 'ssl', 'none'];

    /** Mindestlänge für ein neu gesetztes Passwort (wie im Erst-Setup). */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Grenzen für eine über den Konto-Dialog gesetzte Sitzungsdauer (Stunden):
     * eine Viertelstunde bis 30 Tage. Beim LESEN gelten sie nicht — ein von Hand
     * eingetragener Wert bleibt wirksam.
     */
    private const MIN_SESSION_LIFETIME_HOURS = 0.25;
    private const MAX_SESSION_LIFETIME_HOURS = 720;

    /**
     * Liefert die aktuellen, ROHEN (nicht aufgelösten) Konfigurationswerte aus
     * der hugocms.ini zum Vorbefüllen des Umkonfigurations-Formulars. Die
     * Anmeldedaten ([auth]) werden bewusst NICHT zurückgegeben.
     */
    private function cmdConfig(): array
    {
        // Nur LESEN — das darf jedes angemeldete Konto. Die Antwort enthält
        // keine Geheimnisse (Schlüssel und Passwörter erscheinen ausschließlich
        // als „…Configured"-Flag). Geschrieben wird über cmdReconfigure, und das
        // verlangt config.manage.
        $this->requireAuth();
        if ($this->configPath === null) {
            throw new ApiException('ECONFIG', 409, 'RECONFIGURE-UNAVAILABLE');
        }
        $raw = Config::raw($this->configPath);

        return [
            // Anmeldeverfahren. `authUserCount` sagt dem Dialog, ob beim Wechsel
            // auf multiuser noch ein Konto aus dem Einzelbenutzer entsteht oder
            // ob bereits Konten liegen (dann gelten die).
            'authDriver'    => strtolower(trim((string) ($raw['auth']['driver'] ?? 'singleuser'))),
            'authDrivers'   => self::AUTH_DRIVERS,
            'authUserCount' => count((new UserStore(dirname($this->configPath) . '/users'))->all()),
            'sessionPath' => $raw['session']['path'] ?? '',
            'logFile'     => $raw['log']['file'] ?? '',
            'logLevel'    => $raw['log']['level'] ?? 'warning',
            'hugoBin'     => $raw['hugo']['bin'] ?? '',
            'hugoClean'   => filter_var($raw['hugo']['clean'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'logLevels'   => self::LOG_LEVELS,
            // KI-Assistent: Status + Modus/Modelle, aber NIE der API-Schlüssel.
            // Cron-/Audit-Modell leer = „wie Assistenten-Modell" (Fallback).
            'aiConfigured' => trim((string) ($raw['ai']['api_key'] ?? '')) !== '',
            'aiModel'      => $raw['ai']['model'] ?? '',
            'aiModelCron'  => $raw['ai']['model_cron'] ?? '',
            'aiModelAudit' => $raw['ai']['model_audit'] ?? '',
            'aiWriteMode'  => $raw['ai']['write_mode'] ?? 'confirm',
            'aiWriteModes' => self::AI_WRITE_MODES,
            // Adaptives Thinking erzwingen (sonst entscheidet die Positivliste) —
            // getrennt für Assistent und Cron-Verbesserer, für neu eingetragene,
            // noch unbekannte Modelle.
            'aiForceThinking' => filter_var($raw['ai']['force_thinking'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'aiForceThinkingCron' => filter_var($raw['ai']['force_thinking_cron'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Hinterlegte Modell-Auswahl (leer = der Client nutzt seine eigene
            // Liste). Der Aktualisieren-Knopf im Dialog füllt sie über /aimodels.
            'aiModels'     => Config::normalizeModels($raw['ai']['models'] ?? ''),
            // seo-success-Dienst (Pro): Status + Basis-URL, aber NIE der Schlüssel.
            // Leere URL → der Client füllt den Standard vor. Neue Namen (service_*)
            // mit Rückfall auf die alten (speech_*).
            'speechConfigured' => trim((string) ($raw['services']['service_key'] ?? $raw['services']['speech_key'] ?? '')) !== '',
            'speechUrl'        => $raw['services']['service_url'] ?? $raw['services']['speech_url'] ?? '',
            // PageSpeed-Check: Status des optionalen (globalen) Google-Schlüssels
            // — nie der Schlüssel selbst. Die zu messende Live-Adresse steht
            // dagegen pro Webseite in der Mount-Konfiguration und wird im Panel
            // gesetzt, nicht hier.
            'pagespeedConfigured' => trim((string) ($raw['services']['pagespeed_key'] ?? '')) !== '',
            // E-Mail-Versand (Gesundheitscheck): alle Werte AUSSER dem Passwort.
            // mailPassConfigured zeigt nur an, ob bereits eines hinterlegt ist.
            'mailHost'           => $raw['mail']['smtp_host'] ?? '',
            'mailPort'           => $raw['mail']['smtp_port'] ?? '',
            'mailSecurity'       => $raw['mail']['smtp_security'] ?? '',
            'mailSecurities'     => self::MAIL_SECURITIES,
            'mailUser'           => $raw['mail']['smtp_user'] ?? '',
            'mailFrom'           => $raw['mail']['from'] ?? '',
            'mailTo'             => $raw['mail']['to'] ?? '',
            'mailPassConfigured' => trim((string) ($raw['mail']['smtp_pass'] ?? '')) !== '',
            // SEO-Bericht: zusätzliche Ausschluss-Präfixe (eine je Zeile fürs
            // Formular). Die fest verdrahteten Ausschlüsse stehen NICHT darin.
            'seoExcludePrefixes' => implode("\n", Config::normalizeExcludePrefixes(
                (string) ($raw['seo_report']['exclude_prefixes'] ?? ''),
            )),
            // SEO-Bericht: einzelne ausgeschlossene Dateien (eine je Zeile).
            'seoExcludeFiles' => implode("\n", Config::normalizeExcludeFiles(
                (string) ($raw['seo_report']['exclude_files'] ?? ''),
            )),
        ];
    }

    /**
     * Schreibt die hugocms.ini im laufenden Betrieb neu — Sitzungsverzeichnis,
     * Logdatei, Log-Stufe und Hugo-Programm. Die [auth]-Sektion bleibt über
     * Config::updateSections wörtlich erhalten. Pfadänderungen (Session/Log)
     * greifen erst beim nächsten Request.
     */
    private function cmdReconfigure(array $request): array
    {
        $this->requireConfigAdmin();
        $this->requireMethod('POST');
        if ($this->configPath === null) {
            throw new ApiException('ECONFIG', 409, 'RECONFIGURE-UNAVAILABLE');
        }

        $sessionPath = self::cleanConfigValue($request['sessionPath'] ?? '', 'sessionPath', true);
        $logFile     = self::cleanConfigValue($request['logFile'] ?? '', 'logFile', true);
        $logLevel    = strtolower(trim((string) ($request['logLevel'] ?? '')));
        if (!in_array($logLevel, self::LOG_LEVELS, true)) {
            throw ApiException::badRequest('SETUP-LOG-LEVEL-INVALID', [implode(', ', self::LOG_LEVELS)]);
        }
        $hugoBin = self::cleanConfigValue($request['hugoBin'] ?? '', 'hugoBin', false);
        $hugoClean = filter_var($request['hugoClean'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Anmeldeverfahren (optional; fehlt das Feld, bleibt alles wie es ist).
        $authSection = $this->authSectionForDriver($request['authDriver'] ?? null);

        // KI-Assistent. Der API-Schlüssel ist ein Geheimnis: ein leeres Feld
        // lässt den bestehenden unverändert; nur eine Eingabe ersetzt ihn.
        $aiWriteMode = strtolower(trim((string) ($request['aiWriteMode'] ?? 'confirm')));
        if (!in_array($aiWriteMode, self::AI_WRITE_MODES, true)) {
            $aiWriteMode = 'confirm';
        }
        $aiModel = self::cleanConfigValue($request['aiModel'] ?? '', 'aiModel', false);
        $aiModelCron = self::cleanConfigValue($request['aiModelCron'] ?? '', 'aiModelCron', false);
        $aiModelAudit = self::cleanConfigValue($request['aiModelAudit'] ?? '', 'aiModelAudit', false);
        $aiKeyNew = self::cleanConfigValue($request['aiApiKey'] ?? '', 'aiApiKey', false);
        $existingAi = Config::raw($this->configPath)['ai'] ?? [];
        $apiKey = $aiKeyNew !== '' ? $aiKeyNew : trim((string) ($existingAi['api_key'] ?? ''));
        $model = $aiModel !== '' ? $aiModel : (trim((string) ($existingAi['model'] ?? '')) ?: 'claude-opus-4-8');

        // Cron-/Audit-Modell nur schreiben, wenn ausdrücklich gewählt; leer
        // bedeutet „wie Assistenten-Modell" — dann bleibt der Schlüssel weg und
        // Config::aiSection() fällt auf `model` zurück.
        $aiSection = ['api_key' => $apiKey, 'model' => $model];
        if ($aiModelCron !== '') {
            $aiSection['model_cron'] = $aiModelCron;
        }
        if ($aiModelAudit !== '') {
            $aiSection['model_audit'] = $aiModelAudit;
        }
        $aiSection['write_mode'] = $aiWriteMode;
        // Adaptives Thinking erzwingen — je Slot, nur schreiben, wenn an (INI
        // schlank halten; Config::aiSection() liest fehlend als aus).
        if (filter_var($request['aiForceThinking'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $aiSection['force_thinking'] = 'true';
        }
        if (filter_var($request['aiForceThinkingCron'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $aiSection['force_thinking_cron'] = 'true';
        }
        // Die abgerufene Modell-Liste gehört nicht ins Formular; sie wird hier
        // aus dem Bestand übernommen, sonst löschte jedes Speichern sie.
        $existingModels = Config::normalizeModels($existingAi['models'] ?? '');
        if ($existingModels !== []) {
            $aiSection['models'] = implode(',', $existingModels);
        }

        // Spracheingabe (Pro-Dienst). Der Schlüssel ist ein Geheimnis: ein leeres
        // Feld lässt den bestehenden unverändert. Die URL ist die Basis-Adresse
        // des Dienstes (der Endpunkt wird beim Aufruf angehängt); fehlt sie,
        // gilt der Standard.
        $speechUrl = self::cleanConfigValue($request['speechUrl'] ?? '', 'speechUrl', false);
        if ($speechUrl === '') {
            $speechUrl = 'https://api.hugocms.com/';
        }
        $speechKeyNew = self::cleanConfigValue($request['speechKey'] ?? '', 'speechKey', false);
        $existingServices = Config::raw($this->configPath)['services'] ?? [];
        // Bestand mit Rückfall lesen (service_* neu, speech_* alt), damit
        // „leer = unverändert" den vorhandenen Schlüssel unter beiden Namen erhält.
        $speechKey = $speechKeyNew !== '' ? $speechKeyNew : trim((string) ($existingServices['service_key'] ?? $existingServices['speech_key'] ?? ''));

        // PageSpeed-Check: der (globale) Google-Schlüssel ist ein Geheimnis
        // (leeres Feld = unverändert) UND optional. Die zu messende Adresse steht
        // pro Webseite in der Mount-Konfiguration und wird im Panel gesetzt, nicht
        // hier.
        $pagespeedKeyNew = self::cleanConfigValue($request['pagespeedKey'] ?? '', 'pagespeedKey', false);
        $pagespeedKey = $pagespeedKeyNew !== '' ? $pagespeedKeyNew : trim((string) ($existingServices['pagespeed_key'] ?? ''));

        // [services] aus beiden Diensten zusammensetzen — jeder Schlüssel nur bei
        // Bedarf, damit keine leeren Einträge in der INI landen. Ohne jeglichen
        // Wert entfällt die Sektion.
        $servicesSection = [];
        if ($speechKey !== '') {
            // Neutrale Namen schreiben; ein etwaiger alter speech_*-Eintrag
            // verschwindet, weil updateSections die Sektion vollständig ersetzt.
            $servicesSection['service_key'] = $speechKey;
            $servicesSection['service_url'] = $speechUrl;
        }
        if ($pagespeedKey !== '') {
            $servicesSection['pagespeed_key'] = $pagespeedKey;
        }

        // E-Mail-Versand (Gesundheitscheck). Das SMTP-Passwort ist ein Geheimnis:
        // ein leeres Feld lässt das bestehende unverändert. Die Sektion wird nur
        // geschrieben, wenn Server, Absender und Empfänger gesetzt sind — sonst
        // ist der Versand aus. Nutzer/Passwort nur bei gesetztem Nutzer (sonst
        // keine Authentifizierung, offenes Relay).
        $mailHost = self::cleanConfigValue($request['mailHost'] ?? '', 'mailHost', false);
        $mailFrom = self::cleanConfigValue($request['mailFrom'] ?? '', 'mailFrom', false);
        $mailTo   = self::cleanConfigValue($request['mailTo'] ?? '', 'mailTo', false);
        $mailUser = self::cleanConfigValue($request['mailUser'] ?? '', 'mailUser', false);
        $mailSecurity = strtolower(trim((string) ($request['mailSecurity'] ?? 'tls')));
        if (!in_array($mailSecurity, self::MAIL_SECURITIES, true)) {
            $mailSecurity = 'tls';
        }
        $mailPort = (int) ($request['mailPort'] ?? 0);
        $mailPassNew = self::cleanConfigValue($request['mailPass'] ?? '', 'mailPass', false);
        $existingMail = Config::raw($this->configPath)['mail'] ?? [];
        $mailPass = $mailPassNew !== '' ? $mailPassNew : trim((string) ($existingMail['smtp_pass'] ?? ''));

        $mailSection = null;
        if ($mailHost !== '' && $mailFrom !== '' && $mailTo !== '') {
            $mailSection = [
                'smtp_host'     => $mailHost,
                'smtp_security' => $mailSecurity,
                'from'          => $mailFrom,
                'to'            => $mailTo,
            ];
            if ($mailPort > 0) {
                $mailSection['smtp_port'] = (string) $mailPort;
            }
            if ($mailUser !== '') {
                $mailSection['smtp_user'] = $mailUser;
                if ($mailPass !== '') {
                    $mailSection['smtp_pass'] = $mailPass;
                }
            }
        }

        // SEO-Bericht: zusätzliche Ausschlüsse (global). Dieselbe Sektion gibt es
        // webseitenspezifisch in der Mount-Konfiguration (cmdProjectReconfigure).
        $seoSection = self::seoReportSection($request);

        // [hugo]: Programmpfad und optional clean = true (--cleanDestinationDir).
        // Ohne Programm keine Sektion (kein Build); clean nur schreiben, wenn
        // gesetzt, damit die INI ohne Bedarf schlank bleibt.
        $hugoSection = null;
        if ($hugoBin !== '') {
            $hugoSection = ['bin' => $hugoBin];
            if ($hugoClean) {
                $hugoSection['clean'] = 'true';
            }
        }

        $sections = [
            'session' => ['path' => $sessionPath],
            'log'     => ['file' => $logFile, 'level' => $logLevel],
            'hugo'    => $hugoSection,
            // Ohne API-Schlüssel keine [ai]-Sektion (Assistent aus).
            'ai'      => $apiKey === '' ? null : $aiSection,
            // Ohne konfigurierten Dienst keine [services]-Sektion.
            'services' => $servicesSection === [] ? null : $servicesSection,
            // Ohne Server/Absender/Empfänger keine [mail]-Sektion (Versand aus).
            'mail'    => $mailSection,
            // Ohne zusätzliche Präfixe/Dateien keine [seo_report]-Sektion.
            'seo_report' => $seoSection,
        ];
        // [auth] NUR bei einem Treiberwechsel mitgeben. Den Schlüssel immer zu
        // setzen wäre gefährlich: updateSections deutet null als „Sektion
        // entfernen" — die Anmeldedaten wären damit weg.
        if ($authSection !== null) {
            $sections = ['auth' => $authSection] + $sections;
        }
        Config::updateSections($this->configPath, $sections);
        $this->logger->info('Konfiguration aktualisiert (reconfigure).');

        return ['ok' => true];
    }

    /**
     * Baut die [seo_report]-Sektion aus den Formularfeldern seoExcludePrefixes /
     * seoExcludeFiles. Das Formular liefert sie frei (komma-/zeilengetrennt);
     * abgelegt wird normalisiert und kommagetrennt. Jeder Schlüssel nur bei
     * Bedarf, damit keine leeren Einträge in der INI landen; ohne jeden Eintrag
     * null — {@see Config::updateSections} entfernt die Sektion dann.
     *
     * Gemeinsam genutzt von der globalen hugocms.ini ({@see cmdReconfigure}) und
     * der webseitenspezifischen Mount-Konfiguration ({@see cmdProjectReconfigure}):
     * Beide Ebenen sollen dieselbe Schreibweise erzeugen. Die fest verdrahteten
     * Ausschlüsse bleiben im Code ({@see AuditRunner}) — hier nur die Zusätze.
     *
     * @param array<string, mixed> $request
     * @return ?array<string, string>
     */
    private static function seoReportSection(array $request): ?array
    {
        $prefixes = Config::normalizeExcludePrefixes((string) ($request['seoExcludePrefixes'] ?? ''));
        $files = Config::normalizeExcludeFiles((string) ($request['seoExcludeFiles'] ?? ''));

        $section = [];
        if ($prefixes !== []) {
            $section['exclude_prefixes'] = implode(', ', $prefixes);
        }
        if ($files !== []) {
            $section['exclude_files'] = implode(', ', $files);
        }

        return $section === [] ? null : $section;
    }

    /**
     * Fragt die verfügbaren Modelle bei der Anthropic-API ab (/v1/models) und
     * hinterlegt sie als `models` in der [ai]-Sektion der hugocms.ini. Danach
     * bestückt diese Liste die Auswahlfelder; ohne Eintrag gilt die fest
     * verdrahtete Liste des Clients.
     *
     * Gearbeitet wird mit dem GESPEICHERTEN Schlüssel: ein im Dialog frisch
     * eingetippter, noch nicht gespeicherter Schlüssel ist hier nicht bekannt.
     * Die übrige [ai]-Sektion bleibt wörtlich erhalten.
     *
     * @return array{models: list<string>}
     */
    private function cmdAiModels(): array
    {
        $this->requireConfigAdmin();
        $this->requireMethod('POST');
        if ($this->configPath === null) {
            throw new ApiException('ECONFIG', 409, 'RECONFIGURE-UNAVAILABLE');
        }
        if ($this->ai['apiKey'] === null) {
            throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
        }

        $models = (new AnthropicClient((string) $this->ai['apiKey']))->listModels();

        // Bestand der Sektion übernehmen und nur `models` setzen —
        // updateSections ersetzt die Sektion als Ganzes.
        $aiSection = Config::raw($this->configPath)['ai'] ?? [];
        $aiSection = is_array($aiSection) ? $aiSection : [];
        $aiSection['models'] = implode(',', $models);
        Config::updateSections($this->configPath, ['ai' => $aiSection]);

        return ['models' => $models];
    }

    /**
     * Aktuelle Projekteinstellungen dieser WEBSEITE aus ihrer Mount-Konfiguration
     * — zum Vorbefüllen des Dialogs „Projekteinstellungen". Gegenstück zu
     * {@see cmdConfig}, das die globale hugocms.ini liest. Geliefert werden nur
     * Felder, die der Dialog auch schreiben darf: Die Mount-Sektionen selbst,
     * [hugo] und die Lizenz bleiben außen vor.
     *
     * @return array<string, mixed>
     */
    private function cmdProjectConfig(): array
    {
        $this->requireAuth();
        if ($this->mountsPath === null) {
            throw new ApiException('ECONFIG', 409, 'PROJECT-CONFIG-UNAVAILABLE');
        }
        $raw = Config::raw($this->mountsPath);

        return [
            // Eine Zeile je Eintrag fürs Formular (wie im globalen Dialog).
            'seoExcludePrefixes' => implode("\n", Config::normalizeExcludePrefixes(
                (string) ($raw['seo_report']['exclude_prefixes'] ?? ''),
            )),
            'seoExcludeFiles' => implode("\n", Config::normalizeExcludeFiles(
                (string) ($raw['seo_report']['exclude_files'] ?? ''),
            )),
            // Automatikmodus des Cron-Verbesserers: bereits geprüft und
            // normalisiert aus der geladenen Konfiguration, nicht roh aus der
            // INI — so zeigt das Formular genau die Werte, die auch gelten.
            'improveAuto' => (bool) $this->improve['auto'],
            'improveWindowStart' => (string) $this->improve['windowStart'],
            'improveWindowEnd' => (string) $this->improve['windowEnd'],
            'improvePerDay' => (int) $this->improve['perDay'],
            'improveSkipWeekends' => (bool) $this->improve['skipWeekends'],
            // Was im Fenster tatsächlich Platz hat (siehe improveSlotPlan).
            'improveEffectivePerDay' => $this->improveSlotPlan()['perDay'],
            // Pausenschalter der drei Cron-Skripte.
            'pauseBuild' => (bool) $this->cronPause['pauseBuild'],
            'pauseImprove' => (bool) $this->cronPause['pauseImprove'],
            'pauseHealthcheck' => (bool) $this->cronPause['pauseHealthcheck'],
            // Automatischer Commit nach der Veröffentlichung.
            'autoCommit' => (bool) $this->gitAuto['autoCommit'],
            'commitMessage' => (string) $this->gitAuto['commitMessage'],
            'commitMessagePending' => (string) $this->gitAuto['commitMessagePending'],
            // Ist die Quelle ein Git-Repository? Für den Hinweis im Formular.
            'gitRepo' => $this->sourceIsGitRepo(),
        ];
    }

    /** true, wenn das Hugo-Quellverzeichnis ein Git-Arbeitsbaum ist. */
    private function sourceIsGitRepo(): bool
    {
        if ($this->hugo === null) {
            return false;
        }
        try {
            return (new GitService((string) $this->hugo['source']))->isRepository();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Schreibt die [seo_report]-Sektion der Mount-Konfiguration dieser Webseite.
     * Die Mount-Sektionen, [hugo], [license] und [pagespeed] bleiben über
     * Config::updateSections wörtlich erhalten — hier wird ausschließlich die
     * eine Sektion ersetzt oder (wenn leer) entfernt.
     *
     * Die geschriebenen Ausschlüsse ERGÄNZEN die globalen aus der hugocms.ini und
     * die fest verdrahteten; sie können nichts davon zurückholen (siehe
     * {@see auditStore}).
     */
    private function cmdProjectReconfigure(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        if ($this->mountsPath === null) {
            throw new ApiException('ECONFIG', 409, 'PROJECT-CONFIG-UNAVAILABLE');
        }

        $seoSection = self::seoReportSection($request);
        $improveSection = $this->improveSectionFrom($request);
        $cronSection = $this->cronSectionFrom($request);
        $gitSection = $this->gitSectionFrom($request);
        Config::updateSections($this->mountsPath, [
            'seo_report' => $seoSection,
            'improve' => $improveSection,
            'cron' => $cronSection,
            'git' => $gitSection,
        ]);
        // Für den weiteren Verlauf DIESES Requests sofort wirksam (der Audit
        // liest die Ausschlüsse aus dem Connector, nicht erneut aus der Datei).
        $this->seoReportSite = [
            'excludePrefixes' => Config::normalizeExcludePrefixes($seoSection['exclude_prefixes'] ?? ''),
            'excludeFiles' => Config::normalizeExcludeFiles($seoSection['exclude_files'] ?? ''),
        ];
        $this->reloadImprove();
        // Pausenzustand und Auto-Commit für DIESEN Request nachziehen, sonst
        // meldete ein direkt folgendes projectconfig/whoami noch die alten Werte.
        $reloaded = MountConfig::load($this->mountsPath);
        $this->cronPause = $reloaded['cron'];
        $this->gitAuto = $reloaded['git'];
        $this->logger->info('Projekteinstellungen aktualisiert (projectreconfigure).');

        return ['ok' => true];
    }

    /**
     * Baut die [cron]-Sektion aus den Formularfeldern der Projekteinstellungen.
     * Immer vollständig (nie null), damit der Pausenzustand in der Datei
     * ablesbar bleibt. Der Pausenzustand wird ausschließlich hierüber geändert
     * (das Kontrollkästchen im Projektformular); der Systemstatus zeigt ihn nur
     * an und verweist zum Umstellen hierher.
     *
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    private function cronSectionFrom(array $request): array
    {
        return [
            'pause_build' => !empty($request['pauseBuild']) ? 'true' : 'false',
            'pause_improve' => !empty($request['pauseImprove']) ? 'true' : 'false',
            'pause_healthcheck' => !empty($request['pauseHealthcheck']) ? 'true' : 'false',
        ];
    }

    /**
     * Baut die [git]-Sektion aus den Formularfeldern (Auto-Commit nach der
     * Veröffentlichung). Leere Nachricht → Vorgabe, damit die Datei ablesbar
     * bleibt. Prüfung und Kürzung übernimmt MountConfig beim Lesen.
     *
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    private function gitSectionFrom(array $request): array
    {
        $message = trim((string) ($request['commitMessage'] ?? ''));
        $pending = trim((string) ($request['commitMessagePending'] ?? ''));

        return [
            'auto_commit' => !empty($request['autoCommit']) ? 'true' : 'false',
            'commit_message' => $message === '' ? MountConfig::GIT_COMMIT_MESSAGE_DEFAULT : $message,
            'commit_message_pending' => $pending === '' ? MountConfig::GIT_COMMIT_MESSAGE_PENDING_DEFAULT : $pending,
        ];
    }

    /**
     * improveauto — schaltet allein den Automatikmodus um (Schalter in der
     * Liste „zu verbessern“). Fenster und Tagesmenge bleiben unverändert; sie
     * gehören in die Projekteinstellungen. Ein eigener Befehl, damit der
     * Schalter nicht das ganze Formular mitschreiben muss.
     */
    private function cmdImproveAuto(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        // Bewusst OHNE requirePro: Hier wird nur eine Einstellung gespeichert,
        // genau wie über die Projekteinstellungen (cmdProjectReconfigure, die
        // ebenfalls keine Lizenz verlangen). Wirksam wird sie erst im
        // Cron-Verbesserer — und DER prüft die Pro-Lizenz.
        if ($this->mountsPath === null) {
            throw new ApiException('ECONFIG', 409, 'PROJECT-CONFIG-UNAVAILABLE');
        }

        $enabled = (bool) ($request['enabled'] ?? false);
        // Nur `auto` ändern; die übrigen Felder wörtlich aus dem aktuellen Stand
        // übernehmen, damit der Schalter Fenster, Menge und Wochenend-Ausnahme
        // nicht versehentlich zurücksetzt.
        Config::updateSections($this->mountsPath, [
            'improve' => [
                'auto' => $enabled ? 'true' : 'false',
                'window_start' => (string) $this->improve['windowStart'],
                'window_end' => (string) $this->improve['windowEnd'],
                'per_day' => (string) (int) $this->improve['perDay'],
                'skip_weekends' => $this->improve['skipWeekends'] ? 'true' : 'false',
            ],
        ]);
        $this->reloadImprove();
        $this->logger->info('Automatische Terminierung ' . ($enabled ? 'eingeschaltet' : 'ausgeschaltet') . '.');

        return ['improve' => $this->improve + ['effectivePerDay' => $this->improveSlotPlan()['perDay']]];
    }

    /**
     * Baut die [improve]-Sektion aus den Formularfeldern. Immer vollständig
     * geschrieben (nie null): Der Automatikmodus soll in der Datei ablesbar
     * sein, auch wenn er aus ist — sonst wirkte eine fehlende Sektion wie „nie
     * eingerichtet“, obwohl der Benutzer sie bewusst abgeschaltet hat.
     *
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    private function improveSectionFrom(array $request): array
    {
        return [
            'auto' => !empty($request['improveAuto']) ? 'true' : 'false',
            // Prüfung und Rückfall auf gültige Werte macht MountConfig beim
            // Lesen — hier wird nur roh durchgereicht, damit beide Wege
            // (Formular und INI von Hand) dieselbe Prüfung durchlaufen.
            'window_start' => trim((string) ($request['improveWindowStart'] ?? '')),
            'window_end' => trim((string) ($request['improveWindowEnd'] ?? '')),
            'per_day' => (string) (int) ($request['improvePerDay'] ?? 3),
            'skip_weekends' => !empty($request['improveSkipWeekends']) ? 'true' : 'false',
        ];
    }

    /**
     * Liest die [improve]-Sektion nach dem Schreiben neu ein, damit die Antwort
     * dieses Requests bereits die geprüften Werte trägt (MountConfig normalisiert
     * Uhrzeiten und deckelt die Tagesmenge).
     */
    private function reloadImprove(): void
    {
        if ($this->mountsPath === null) {
            return;
        }
        $this->improve = MountConfig::load($this->mountsPath)['improve'];
    }

    /**
     * Die WIRKSAMEN [user]-Einstellungen: die des angemeldeten Benutzers, sonst
     * die globalen Vorgaben aus der hugocms.ini.
     *
     * Wo die Einstellungen liegen, weiß allein der Auth-Treiber — beim
     * Einzelbenutzer in der hugocms.ini, beim Mehrbenutzer in der Datei des
     * jeweiligen Kontos. Einmal je Request ermittelt.
     *
     * @return array{sessionLifetime: int, contentWidth: int, toolbarCollapsed: bool, updateLastmod: ?bool}
     */
    private function userPrefs(): array
    {
        if ($this->userPrefs !== null) {
            return $this->userPrefs;
        }
        if ($this->auth->isAuthenticated() && $this->auth->supportsPreferences()) {
            // Was die Kontodatei nicht führt, fällt auf die globalen Vorgaben
            // der hugocms.ini zurück.
            return $this->userPrefs = Config::userSection($this->auth->loadPreferences(), $this->user);
        }

        return $this->userPrefs = $this->user;
    }

    /**
     * Schreibt die Einstellungen der [user]-Sektion — beim Einzelbenutzer in die
     * hugocms.ini, beim Mehrbenutzer in die Datei des angemeldeten Kontos:
     *
     *   contentWidth     Breite des Hauptfensters in px (content_width)
     *   toolbarCollapsed Werkzeugleiste eingeklappt (toolbar_collapsed)
     *   sessionLifetime  Sitzungsdauer in STUNDEN (session_lifetime)
     *   updateLastmod    lastmod beim Speichern setzen (update_lastmod);
     *                    null entfernt den Schlüssel = im Editor nachfragen
     *
     * Jedes Feld ist einzeln optional; nur mitgegebene Felder werden
     * geschrieben, die übrigen [user]-Werte bleiben unberührt. Die ersten
     * beiden schickt die Oberfläche selbsttätig (Ende eines Greifrand-Zugs,
     * Umschalten der Werkzeugleiste), die letzten beiden stammen aus dem
     * Konto-Dialog.
     */
    private function cmdSetUserPrefs(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');

        $changes = [];

        if (array_key_exists('contentWidth', $request)) {
            $width = $request['contentWidth'];
            if (!is_int($width) && !(is_string($width) && ctype_digit($width))) {
                throw ApiException::badRequest('PARAM-INVALID', ['contentWidth']);
            }
            $width = (int) $width;
            if ($width < Config::MIN_CONTENT_WIDTH || $width > Config::MAX_CONTENT_WIDTH) {
                throw ApiException::badRequest('PARAM-INVALID', ['contentWidth']);
            }
            $changes['content_width'] = (string) $width;
        }

        if (array_key_exists('toolbarCollapsed', $request)) {
            $collapsed = $request['toolbarCollapsed'];
            if (!is_bool($collapsed)) {
                throw ApiException::badRequest('PARAM-INVALID', ['toolbarCollapsed']);
            }
            $changes['toolbar_collapsed'] = $collapsed ? 'true' : 'false';
        }

        if (array_key_exists('sessionLifetime', $request)) {
            $hours = $request['sessionLifetime'];
            if (!is_int($hours) && !is_float($hours) && !(is_string($hours) && is_numeric($hours))) {
                throw ApiException::badRequest('PARAM-INVALID', ['sessionLifetime']);
            }
            $hours = (float) $hours;
            if ($hours < self::MIN_SESSION_LIFETIME_HOURS || $hours > self::MAX_SESSION_LIFETIME_HOURS) {
                throw ApiException::badRequest('PARAM-INVALID', ['sessionLifetime']);
            }
            // Ganze Stunden ohne Nachkommastellen schreiben — die INI bleibt so
            // lesbar wie von Hand gepflegt.
            $changes['session_lifetime'] = $hours == (int) $hours
                ? (string) (int) $hours
                : rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
        }

        if (array_key_exists('updateLastmod', $request)) {
            $lastmod = $request['updateLastmod'];
            if ($lastmod !== null && !is_bool($lastmod)) {
                throw ApiException::badRequest('PARAM-INVALID', ['updateLastmod']);
            }
            // null entfernt den Schlüssel: Der Editor fragt dann wieder nach.
            $changes['update_lastmod'] = $lastmod === null ? null : ($lastmod ? 'true' : 'false');
        }

        if ($changes === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['contentWidth']);
        }

        if (!$this->auth->supportsPreferences()) {
            throw new ApiException('ECONFIG', 409, 'RECONFIGURE-UNAVAILABLE');
        }
        $this->auth->savePreferences($changes);
        $this->userPrefs = null; // beim nächsten Zugriff frisch vom Treiber
        $this->logger->info('Benutzereinstellungen gespeichert: ' . implode(', ', array_keys($changes)));

        return ['ok' => true, 'ui' => $this->uiState()];
    }

    /**
     * Die für den Client sichtbaren [user]-Werte. Genau dieser Block steckt auch
     * in der whoami-Antwort, damit beide Wege denselben Zustand melden.
     *
     * @return array<string, mixed>
     */
    private function uiState(): array
    {
        $prefs = $this->userPrefs();

        return [
            'contentWidth' => $prefs['contentWidth'],
            'toolbarCollapsed' => $prefs['toolbarCollapsed'],
            // Sitzungsdauer in STUNDEN — so steht sie in der INI und so zeigt
            // der Konto-Dialog sie an (intern rechnet der Connector in Sekunden).
            'sessionLifetimeHours' => round($prefs['sessionLifetime'] / 3600, 2),
            // Dreiwertig: null = beim Speichern nach lastmod-Aktualisierung
            // fragen; true/false = ohne Nachfrage anwenden.
            'updateLastmod' => $prefs['updateLastmod'],
        ];
    }

    // ---- Kontenverwaltung (nur Mehrbenutzer, nur Rolle admin) --------------
    //
    // Der Connector reicht die Befehle nur durch: WER was darf, entscheidet der
    // Treiber (UserAdminInterface). Bringt der Treiber die Schnittstelle nicht
    // mit — etwa der Einzelbenutzer —, gibt es diese Befehle schlicht nicht.

    /**
     * Bereitet den Wechsel des Anmeldeverfahrens vor und liefert die zu
     * schreibende [auth]-Sektion — oder null, wenn sich nichts ändert (dann
     * bleibt die Sektion wörtlich stehen).
     *
     * Zum Mehrbenutzer hin bleiben `username` und `password_hash` erhalten:
     * Daraus baut die AuthFactory beim nächsten Aufruf das erste
     * Administratorkonto, sofern users/ noch leer ist.
     *
     * Zurück zum Einzelbenutzer wandern die Anmeldedaten des GERADE
     * angemeldeten Kontos in die [auth]-Sektion. Ohne diesen Schritt gälte
     * wieder der alte Stand aus der Zeit vor der Umstellung — wer sein Passwort
     * seither geändert hat, käme nicht mehr herein. Die Kontodateien bleiben
     * liegen; ein erneuter Wechsel zum Mehrbenutzer findet sie unverändert vor.
     *
     * @return ?array<string, string>
     */
    private function authSectionForDriver(mixed $requested): ?array
    {
        if ($requested === null) {
            return null; // Feld nicht mitgeschickt — Verfahren unverändert
        }
        $driver = strtolower(trim((string) $requested));
        if (!in_array($driver, self::AUTH_DRIVERS, true)) {
            throw ApiException::badRequest('AUTH-DRIVER-UNKNOWN', [$driver]);
        }
        $raw = Config::raw((string) $this->configPath)['auth'] ?? [];
        $current = strtolower(trim((string) ($raw['driver'] ?? 'singleuser')));
        if ($driver === $current) {
            return null;
        }

        if ($driver === 'multiuser') {
            return array_merge($raw, ['driver' => 'multiuser']);
        }

        // → singleuser: Das eigene Konto wird zum Einzelbenutzer.
        $name = (string) ($this->auth->currentUser()['name'] ?? '');
        $account = (new UserStore(dirname((string) $this->configPath) . '/users'))->load($name);
        if ($account === null) {
            throw new ApiException('ECONFIG', 409, 'AUTH-SINGLEUSER-NO-ACCOUNT');
        }

        return array_merge($raw, [
            'driver' => 'singleuser',
            'username' => $account['name'],
            'password_hash' => $account['hash'],
        ]);
    }

    /**
     * Verlangt die Befugnis, die INSTALLATION zu konfigurieren: hugocms.ini
     * (Anmeldeverfahren, Verzeichnisse, Log, Hugo, Schlüssel) und die Lizenz.
     * Beim Einzelbenutzer trifft das immer zu — es gibt nur ein Konto. Beim
     * Mehrbenutzer ist es der Rolle „admin" vorbehalten: Ein Redakteur soll
     * Inhalte pflegen, nicht das Anmeldeverfahren umstellen oder Schlüssel
     * austauschen.
     *
     * NICHT betroffen sind die Einstellungen einer einzelnen WEBSEITE
     * (projectconfig/projectreconfigure) — die gehören zur redaktionellen
     * Arbeit und stehen jedem angemeldeten Konto offen.
     */
    private function requireConfigAdmin(): void
    {
        $this->requireAuth();
        if (!$this->auth->can('config.manage')) {
            throw ApiException::denied('CONFIG-ADMIN-REQUIRED');
        }
    }

    /**
     * Der Treiber als Kontenverwaltung, oder ein klarer Fehler.
     */
    private function userAdmin(): UserAdminInterface
    {
        $this->requireAuth();
        if (!$this->auth instanceof UserAdminInterface) {
            throw new ApiException('ECONFIG', 409, 'USERS-NOT-SUPPORTED');
        }

        return $this->auth;
    }

    /**
     * users — alle Konten samt der Webseiten, die diese Installation kennt
     * (Auswahlliste für die Zuordnung).
     */
    private function cmdUsers(): array
    {
        $admin = $this->userAdmin();

        return [
            'users' => $admin->listUsers(),
            'sites' => $this->knownSites(),
            'roles' => [UserAdminInterface::ROLE_ADMIN, UserAdminInterface::ROLE_EDITOR],
        ];
    }

    private function cmdUserCreate(array $request): array
    {
        $admin = $this->userAdmin();
        $this->requireMethod('POST');

        $username = (string) ($request['username'] ?? '');
        $password = (string) ($request['password'] ?? '');
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw ApiException::badRequest('SETUP-PASSWORD-TOO-SHORT', [self::MIN_PASSWORD_LENGTH]);
        }
        $admin->createUser(
            $username,
            $password,
            (string) ($request['role'] ?? UserAdminInterface::ROLE_EDITOR),
            $this->requestSites($request),
        );
        $this->logger->info('Benutzerkonto angelegt: ' . $username);

        return ['ok' => true, 'users' => $admin->listUsers()];
    }

    private function cmdUserUpdate(array $request): array
    {
        $admin = $this->userAdmin();
        $this->requireMethod('POST');

        $username = (string) ($request['username'] ?? '');
        $disabled = array_key_exists('disabled', $request) ? $request['disabled'] : null;
        if ($disabled !== null && !is_bool($disabled)) {
            throw ApiException::badRequest('PARAM-INVALID', ['disabled']);
        }
        $admin->updateUser(
            $username,
            array_key_exists('role', $request) ? (string) $request['role'] : null,
            array_key_exists('sites', $request) ? $this->requestSites($request) : null,
            $disabled,
        );
        $this->logger->info('Benutzerkonto geändert: ' . $username);

        return ['ok' => true, 'users' => $admin->listUsers()];
    }

    /** Setzt das Passwort eines FREMDEN Kontos neu („Passwort vergessen"). */
    private function cmdUserPassword(array $request): array
    {
        $admin = $this->userAdmin();
        $this->requireMethod('POST');

        $username = (string) ($request['username'] ?? '');
        $password = (string) ($request['password'] ?? '');
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw ApiException::badRequest('SETUP-PASSWORD-TOO-SHORT', [self::MIN_PASSWORD_LENGTH]);
        }
        $admin->resetPassword($username, $password);
        $this->logger->info('Passwort zurückgesetzt für: ' . $username);

        return ['ok' => true];
    }

    private function cmdUserDelete(array $request): array
    {
        $admin = $this->userAdmin();
        $this->requireMethod('POST');

        $username = (string) ($request['username'] ?? '');
        $admin->deleteUser($username);
        $this->logger->info('Benutzerkonto gelöscht: ' . $username);

        return ['ok' => true, 'users' => $admin->listUsers()];
    }

    /**
     * Webseiten-Zuordnung aus der Anfrage: eine Liste von Hosts oder ["*"].
     *
     * @return list<string>
     */
    private function requestSites(array $request): array
    {
        $sites = $request['sites'] ?? [];
        if (!is_array($sites)) {
            throw ApiException::badRequest('PARAM-INVALID', ['sites']);
        }

        return array_values(array_map('strval', $sites));
    }

    /**
     * Hosts aller Webseiten dieser Installation — aus den Kopfzeilen der
     * Mount-Konfigurationen. Der eigene Host ist immer dabei, auch wenn die
     * Installation (noch) über den Rückfall mounts.ini läuft.
     *
     * @return list<string>
     */
    private function knownSites(): array
    {
        $sites = $this->configPath !== null
            ? SiteKey::knownHosts(dirname($this->configPath) . '/mounts')
            : [];
        $own = SiteKey::host($_SERVER);
        if ($own !== '' && !in_array($own, $sites, true)) {
            $sites[] = $own;
            sort($sites, SORT_NATURAL);
        }

        return $sites;
    }

    /**
     * Ändert die Anmeldedaten (Name, optional Passwort). Das aktuelle Passwort
     * muss zur Bestätigung mitgegeben werden. Die eigentliche Persistenz
     * übernimmt der Auth-Treiber (changeCredentials) — der Connector kennt
     * weder das Speicherformat noch den Treibertyp. Nach Erfolg wird die
     * Sitzung beendet; die Anmeldung erfolgt mit den neuen Daten erneut.
     */
    private function cmdAccount(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        if (!$this->auth->supportsCredentialChange()) {
            throw new ApiException('ECONFIG', 409, 'ACCOUNT-NOT-SUPPORTED');
        }

        // Identität bestätigen: das BISHERIGE Passwort muss stimmen.
        $currentPassword = (string) ($request['currentPassword'] ?? '');
        if ($currentPassword === '' || !$this->auth->verifyPassword($currentPassword)) {
            throw new ApiException('EAUTH', 403, 'CURRENT-PASSWORD-WRONG');
        }

        $username = self::cleanConfigValue($request['username'] ?? '', 'username', true);
        $newPassword = (string) ($request['password'] ?? '');
        if ($newPassword !== '' && strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw ApiException::badRequest('SETUP-PASSWORD-TOO-SHORT', [self::MIN_PASSWORD_LENGTH]);
        }

        // Persistenz dem Treiber überlassen (leeres Passwort = unverändert).
        $this->auth->changeCredentials($username, $newPassword !== '' ? $newPassword : null);
        $this->logger->info('Anmeldedaten geändert (account).');

        // Anmeldedaten geändert → Sitzung beenden; Neuanmeldung mit neuen Daten.
        $this->auth->logout();

        return ['ok' => true, 'reauth' => true];
    }

    // --- Hilfe / Wissensdatenbank (frei) -----------------------------------

    /**
     * Liefert ein Hilfethema (Markdown + Metadaten) aus backend/help. Frei
     * verfügbar (nur Anmeldung nötig), damit die Hilfe die ganze App abdecken
     * kann. Sprache aus "locale" (Standard de) mit Rückfall auf Englisch.
     */
    private function cmdHelp(array $request): array
    {
        $this->requireAuth();
        $section = $this->requireParam($request, 'section');
        $id = $this->requireParam($request, 'id');
        $locale = (string) ($request['locale'] ?? 'de');

        return (new HelpService($this->helpDir))->topic($section, $id, $locale);
    }

    // --- Pro-Lizenz --------------------------------------------------------

    /**
     * Lazy aufgelöste License-Instanz dieser Webseite, an die Domain der Anfrage
     * gebunden ({@see SiteKey::host}). Der Schlüssel stammt aus der Mount-Konfig.
     */
    private function license(): License
    {
        return $this->licenseObj ??= new License($this->licenseKey, SiteKey::host($_SERVER));
    }

    /** Liefert den Lizenzstatus (Edition, Lizenznehmer, Domain) — ohne Schlüssel. */
    private function cmdLicense(): array
    {
        $this->requireAuth();

        return $this->license()->info();
    }

    /**
     * status — Systemstatus dieser Webseite: welche Schlüssel hinterlegt sind,
     * welche Lizenz gilt, welche Cron-Aufgaben laufen und was diese demnächst
     * abzuarbeiten haben. Rein lokal: kein Aufruf nach außen, damit die Ansicht
     * sofort steht. Die Schlüssel selbst verlassen den Server nie — nur, ob
     * einer hinterlegt ist (siehe {@see cmdStatusCheck()} für die Netzprüfung).
     */
    private function cmdStatus(): array
    {
        $this->requireAuth();

        return [
            'keys' => [
                // Der KI-Schlüssel trägt beide Modelle: das interaktive und das
                // des Cron-Verbesserers (sie dürfen sich unterscheiden).
                'ai' => [
                    'configured' => $this->ai['apiKey'] !== null,
                    'model' => $this->ai['model'],
                    'modelCron' => $this->ai['modelCron'],
                ],
                'service' => [
                    'configured' => $this->services['serviceKey'] !== null,
                    'url' => $this->services['serviceUrl'],
                ],
                // PageSpeed lässt sich nicht folgenlos prüfen — jeder Testaufruf
                // wäre ein echter Lauf gegen das Kontingent. Nur „hinterlegt“.
                'pagespeed' => [
                    'configured' => $this->services['pagespeedKey'] !== null,
                    'verifiable' => false,
                ],
                'mail' => [
                    'configured' => (bool) $this->mail['configured'],
                    'host' => $this->mail['host'],
                    'to' => $this->mail['to'],
                ],
            ],
            'license' => $this->license()->info(),
            'cron' => $this->cronStatusList(),
            'tasks' => $this->pendingCronTasks(),
        ];
    }

    /**
     * Cron-Aufgaben für den Systemstatus: der Herzschlag jeder Aufgabe, ergänzt
     * um ihren Pausenzustand (für das Kontrollkästchen und die Ampel).
     *
     * @return list<array<string, mixed>>
     */
    private function cronStatusList(): array
    {
        $pauseField = [
            'build' => 'pauseBuild',
            'improve' => 'pauseImprove',
            'healthcheck' => 'pauseHealthcheck',
        ];

        return array_map(function (array $entry) use ($pauseField): array {
            $field = $pauseField[$entry['job']] ?? null;
            $entry['paused'] = $field !== null && !empty($this->cronPause[$field]);

            return $entry;
        }, $this->cronHeartbeat()?->all() ?? []);
    }

    /**
     * Was die Cron-Aufgaben demnächst abzuarbeiten haben. Zwei getrennte
     * Warteschlangen: terminierte Freigaben (der nächste Build tauscht sie ein,
     * sobald ihr Zeitpunkt erreicht ist) und der Arbeitsvorrat des
     * KI-Verbesserers. Ohne Hugo-Projekt gibt es beides nicht.
     *
     * @return array{scheduled: list<array<string, mixed>>, improve: list<array<string, mixed>>}
     */
    private function pendingCronTasks(): array
    {
        if ($this->hugo === null) {
            return ['scheduled' => [], 'improve' => []];
        }

        // Terminierte Freigaben, nächster Termin zuerst — das ist die
        // Reihenfolge, in der sie live gehen.
        $scheduled = [];
        foreach ($this->reviewStore()->list() as $draft) {
            $publishAt = $draft['publishAt'] ?? null;
            if (!is_string($publishAt) || $publishAt === '') {
                continue; // wartet auf Freigabe, nicht auf den Cron
            }
            $scheduled[] = [
                'key' => (string) ($draft['key'] ?? ''),
                'mount' => $draft['mount'] ?? '',
                'rel' => $draft['rel'] ?? '',
                'publishAt' => $publishAt,
                'origin' => $draft['origin'] ?? 'user',
                'author' => $draft['author'] ?? null,
                'due' => strtotime($publishAt) !== false && strtotime($publishAt) <= time(),
            ];
        }
        usort($scheduled, static fn (array $a, array $b): int => strcmp((string) $a['publishAt'], (string) $b['publishAt']));

        // Arbeitsvorrat des Verbesserers in genau der Reihenfolge, in der der
        // Cron ihn abarbeitet (pendingImproveList), damit die Anzeige der
        // tatsächlichen Bearbeitung entspricht.
        $improve = array_map(
            static fn (array $e): array => [
                'mount' => $e['mount'] ?? '',
                'rel' => $e['rel'] ?? '',
                'score' => $e['score'] ?? null,
                'checkedAt' => $e['checkedAt'] ?? null,
            ],
            $this->pendingImproveList(),
        );

        return ['scheduled' => $scheduled, 'improve' => $improve];
    }

    /**
     * statuscheck — prüft die hinterlegten Zugänge tatsächlich gegen ihren
     * Dienst (Knopf „Schlüssel prüfen“ im Systemstatus). Bewusst getrennt von
     * {@see cmdStatus()}: Jede Prüfung ist ein Aufruf nach außen, den niemand
     * ungefragt bei jedem Öffnen auslösen soll.
     *
     * Ein fehlgeschlagener Einzelcheck ist KEIN Fehler des Befehls — genau das
     * ist ja das Ergebnis. Jeder Eintrag trägt deshalb seinen eigenen Status.
     */
    private function cmdStatusCheck(): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');

        return [
            'ai' => $this->probe(
                $this->ai['apiKey'] !== null,
                fn () => (new AnthropicClient((string) $this->ai['apiKey']))->ping(),
            ),
            // Ohne Dienst-Adresse gibt es nichts zu fragen — dann gilt der
            // Zugang als nicht konfiguriert, nicht als fehlerhaft. Die Antwort
            // trägt Kontingent und Verbrauch, die der Status mit anzeigt.
            'service' => $this->probe(
                $this->services['serviceKey'] !== null && $this->services['serviceUrl'] !== null,
                fn (): array => self::normalizeServiceInfo((new SeoSuccessClient(
                    (string) $this->services['serviceUrl'],
                    (string) $this->services['serviceKey'],
                ))->verify()),
            ),
            'mail' => $this->probe(
                (bool) $this->mail['configured'],
                fn () => $this->buildMailer()->verify(),
            ),
        ];
    }

    /**
     * statuslog — die letzten Zeilen eines Logstands für die Protokollansicht
     * im Systemstatus. Der Basispfad kommt ausschließlich aus der Konfiguration
     * ([log] file); der Client bestimmt nur, WIE VIELE Zeilen (`lines`) und
     * WELCHEN Stand (`index`) er sehen will — 0 die aktuelle Datei, N > 0 den
     * rotierten Stand „.N“. Freie Pfade sind bewusst ausgeschlossen.
     *
     * Der Antwort liegt unter `archives` die Liste der vorhandenen Stände bei,
     * damit das Auswahlfeld ohne zweiten Aufruf gefüllt ist.
     */
    private function cmdStatusLog(array $request): array
    {
        $this->requireAuth();

        $lines = (int) ($request['lines'] ?? 200);
        $index = (int) ($request['index'] ?? 0);

        $out = $this->logger->tail($lines, $index);
        $out['archives'] = $this->logger->archives();

        return $out;
    }

    /**
     * statuslogrotate — rotiert die Logdatei auf ausdrücklichen Wunsch sofort,
     * unabhängig von der Größenschwelle. Der laufende Stand wandert zur .1, die
     * älteren rücken nach, der älteste fällt weg (siehe Logger::rotate).
     */
    private function cmdStatusLogRotate(): array
    {
        $this->requireAuth();

        return ['rotated' => $this->logger->rotate()];
    }

    /**
     * Führt eine einzelne Zugangsprüfung aus und übersetzt sie in einen
     * Statuseintrag. Nicht konfiguriert → 'skipped'; Ausnahme → 'error' samt
     * Fehlerschlüssel, den der Client übersetzt.
     *
     * Liefert die Prüfung ein Array, reist es als `info` mit — so kann ein
     * Dienst über das bloße „erreichbar“ hinaus etwas mitteilen (der
     * seo-success-Dienst etwa Kontingent und Verbrauch).
     *
     * @return array{status: string, key: ?string, message: ?string, info: ?array<string, mixed>}
     */
    private function probe(bool $configured, \Closure $check): array
    {
        if (!$configured) {
            return ['status' => 'skipped', 'key' => null, 'message' => null, 'info' => null];
        }
        try {
            $info = $check();

            return [
                'status' => 'ok',
                'key' => null,
                'message' => null,
                'info' => is_array($info) ? $info : null,
            ];
        } catch (ApiException $e) {
            return ['status' => 'error', 'key' => $e->messageKey(), 'message' => $e->getMessage(), 'info' => null];
        } catch (Throwable $e) {
            return ['status' => 'error', 'key' => null, 'message' => $e->getMessage(), 'info' => null];
        }
    }

    /**
     * Aktiviert eine Pro-Lizenz für DIESE Webseite: prüft den Schlüssel gegen
     * die aktuelle Domain und schreibt ihn in die [license]-Sektion der
     * geladenen Mount-Konfiguration (mounts/<hash>.ini). Die übrigen Sektionen
     * (Mounts, [hugo]) bleiben wörtlich erhalten. Ein ungültiger Schlüssel
     * (falsche Domain, Signatur) wird abgelehnt, bevor etwas persistiert wird.
     * Die Edition greift ab dem nächsten Request.
     */
    private function cmdActivate(array $request): array
    {
        $this->requireConfigAdmin();
        $this->requireMethod('POST');
        if ($this->mountsPath === null) {
            // Programmatische Konfiguration (custom.php): keine Datei zum Schreiben.
            throw new ApiException('ECONFIG', 409, 'ACTIVATION-UNAVAILABLE');
        }

        $key = trim((string) ($request['key'] ?? ''));
        if ($key === '') {
            throw ApiException::badRequest('PARAM-MISSING', ['key']);
        }

        $domain = SiteKey::host($_SERVER);
        $decoded = License::decode($key, $domain);
        if ($decoded === null) {
            // Bewusst keine Unterscheidung Signatur/Domain nach außen.
            throw new ApiException('ELICENSE', 422, 'LICENSE-INVALID');
        }

        Config::updateSections($this->mountsPath, ['license' => ['key' => $key]]);
        $this->logger->info("Pro-Lizenz aktiviert für {$decoded['licensee']} @ {$decoded['domain']}.");

        // Status mit dem frisch eingetragenen Schlüssel zurückgeben.
        return (new License($key, $domain))->info();
    }

    /** Wirft 403, wenn keine gültige Pro-Lizenz für diese Domain vorliegt. */
    private function requirePro(): void
    {
        if (!$this->license()->isPro()) {
            throw new ApiException('ELICENSE', 403, 'PRO-REQUIRED');
        }
    }

    // --- Git (Pro-Funktion) ------------------------------------------------

    /**
     * Gemeinsamer Einstieg aller Git-Befehle: Anmeldung, Pro-Lizenz und ein
     * konfiguriertes Hugo-Projekt (dessen source-Verzeichnis das Repository
     * ist). Liefert den auf dieses Verzeichnis eingesperrten GitService.
     */
    private function git(): GitService
    {
        $this->requireAuth();
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'GIT-NO-PROJECT');
        }
        $source = (string) $this->hugo['source'];
        if (!is_dir($source)) {
            throw new ApiException('ECONFIG', 500, 'HUGO-SOURCE-MISSING', [$source]);
        }

        return new GitService($source);
    }

    private function cmdGitStatus(): array
    {
        return $this->git()->status();
    }

    private function cmdGitLog(array $request): array
    {
        $page = (int) ($request['page'] ?? 1);
        $perPage = (int) ($request['perPage'] ?? 20);

        return $this->git()->log($page, $perPage);
    }

    private function cmdGitDiff(array $request): array
    {
        $sha = $this->requireParam($request, 'sha');

        return $this->git()->diff($sha);
    }

    private function cmdGitCommit(array $request): array
    {
        $this->requireMethod('POST');
        $git = $this->git();
        $result = $git->commit((string) ($request['message'] ?? ''));
        if ($result['success']) {
            $this->logger->info('Git-Commit erstellt: ' . ($result['sha'] ?? '?'));
        } else {
            $this->logger->warning('Git-Commit fehlgeschlagen: ' . $result['output']);
        }

        return $result;
    }

    private function cmdGitPush(): array
    {
        $this->requireMethod('POST');
        $result = $this->git()->push();
        if (!$result['success']) {
            $this->logger->warning('Git-Push fehlgeschlagen: ' . $result['output']);
        }

        return $result;
    }

    private function cmdGitReset(array $request): array
    {
        $this->requireMethod('POST');
        $ref = trim((string) ($request['ref'] ?? 'HEAD'));

        return $this->git()->reset($ref);
    }

    // --- SEO-Audit (Pro-Funktion) -----------------------------------------

    /**
     * Gemeinsamer Einstieg aller Audit-Befehle: Anmeldung, Pro-Lizenz und ein
     * konfiguriertes Hugo-Projekt. Liefert den auf public/ (Build) und content/
     * (Quellen) dieser Webseite eingestellten AuditService. Die Berichte liegen
     * je Webseite getrennt unter var/audit/<hash(source)>/.
     */
    private function audit(): AuditService
    {
        $this->requireAuth();
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }

        return $this->auditStore();
    }

    /**
     * Baut den Audit-Dienst OHNE Freischalt-Prüfung (setzt ein Hugo-Projekt
     * voraus). Für interne Aufrufe und den CLI-Einstieg, die eigene Vorbedingungen
     * prüfen. Die Web-Befehle nutzen das gegatete {@see audit()}.
     *
     * Die Ausschlüsse stammen aus zwei Ebenen, die sich ERGÄNZEN: global aus der
     * hugocms.ini, webseitenspezifisch aus der Mount-Konfiguration. Hinzu kommen
     * die fest verdrahteten des {@see AuditRunner}.
     */
    private function auditStore(): AuditService
    {
        $source = (string) $this->hugo['source'];
        $public = (string) ($this->hugo['destination'] ?? $source . '/public');
        $storage = __DIR__ . '/../var/audit/' . sha1($source);

        return new AuditService(
            $public,
            $source,
            $storage,
            self::mergeExcludes($this->seoReport['excludePrefixes'], $this->seoReportSite['excludePrefixes']),
            self::mergeExcludes($this->seoReport['excludeFiles'], $this->seoReportSite['excludeFiles']),
        );
    }

    /**
     * Legt globale und webseitenspezifische Ausschlussliste zusammen (beide
     * bereits von {@see Config} normalisiert). Rein additiv und entdoppelt —
     * eine Ebene kann die andere nicht aufheben, nur erweitern.
     *
     * @param list<string> $global
     * @param list<string> $site
     * @return list<string>
     */
    private static function mergeExcludes(array $global, array $site): array
    {
        return array_values(array_unique([...$global, ...$site]));
    }

    /** Führt einen neuen Audit-Lauf aus (synchron) und liefert den Bericht. */
    private function cmdAudit(): array
    {
        $service = $this->audit();
        $this->requireMethod('POST');
        if (!$this->auth->can('build')) {
            throw ApiException::denied('OPERATION-NOT-ALLOWED', ['build']);
        }
        // Der Lauf parst alle gebauten Seiten — wie der Hugo-Lauf großzügig
        // bemessen, aber ohne Hintergrundprozess (ein Request → eine Antwort).
        @set_time_limit(120);

        $report = $service->run();
        $this->logger->info(sprintf(
            'SEO-Audit: %d Seiten, %d Fehler / %d Warnungen / %d Hinweise (%ss).',
            (int) ($report['pagesScanned'] ?? 0),
            (int) ($report['summary']['error'] ?? 0),
            (int) ($report['summary']['warning'] ?? 0),
            (int) ($report['summary']['hint'] ?? 0),
            (string) ($report['seconds'] ?? '0'),
        ));

        return $this->enrichReport($report);
    }

    /** Metadaten der gespeicherten Läufe (neueste zuerst). */
    private function cmdAuditList(): array
    {
        return ['runs' => $this->audit()->list()];
    }

    /** Vollständiger Bericht eines gespeicherten Laufs. */
    private function cmdAuditGet(array $request): array
    {
        $id = $this->requireParam($request, 'id');

        return $this->enrichReport($this->audit()->get($id));
    }

    /** Löscht einen gespeicherten Lauf. */
    private function cmdAuditDelete(array $request): array
    {
        $this->requireMethod('POST');
        $id = $this->requireParam($request, 'id');

        return $this->audit()->delete($id);
    }

    /** Löscht alle gespeicherten Läufe bis auf den zuletzt erzeugten. */
    private function cmdAuditDeleteOthers(): array
    {
        $this->requireMethod('POST');

        return $this->audit()->deleteAllButLatest();
    }

    // --- LLM-Content-Qualität (Pro-Funktion, braucht KI-Schlüssel) ----------

    /**
     * Gemeinsamer Einstieg der Content-Prüfbefehle: wie das Audit (Anmeldung,
     * Pro-Lizenz, Hugo-Projekt) plus ein konfigurierter KI-Schlüssel. Die
     * Ergebnisse liegen je Webseite getrennt unter var/audit-content/<hash(source)>/.
     */
    private function contentQuality(): ContentQualityService
    {
        $this->requireAuth();
        $this->requirePro();
        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-NO-PROJECT');
        }
        if ($this->ai['apiKey'] === null) {
            throw new ApiException('ECONFIG', 409, 'AI-NOT-CONFIGURED');
        }

        return $this->contentQualityStore();
    }

    /**
     * Baut den Content-Qualitäts-Dienst OHNE Freischalt-Prüfung (setzt Hugo-
     * Projekt und KI-Schlüssel voraus). Für interne Aufrufe (Schreibhaken,
     * Berichtsaufbau) und den CLI-Einstieg. Web-Befehle nutzen das gegatete
     * {@see contentQuality()}.
     */
    private function contentQualityStore(): ContentQualityService
    {
        $storage = __DIR__ . '/../var/audit-content/' . sha1((string) $this->hugo['source']);

        return new ContentQualityService(
            new AnthropicClient((string) $this->ai['apiKey']),
            $this->ai['modelAudit'],
            $this->resolver,
            $this->files,
            $storage,
        );
    }

    /** Prüft EINE Content-Datei per LLM (synchron) und speichert das Ergebnis. */
    private function cmdAuditContent(array $request): array
    {
        $service = $this->contentQuality();
        $this->requireMethod('POST');
        $id = $this->requireParam($request, 'id');
        $locale = (string) ($request['locale'] ?? 'de');
        // Ein einzelner LLM-Aufruf — wie beim Assistenten großzügig bemessen.
        @set_time_limit(180);

        $entry = $service->analyze($id, $locale);
        $this->logger->info(sprintf(
            'Content-Qualität geprüft: %s (Score %d).',
            (string) ($entry['rel'] ?? ''),
            (int) ($entry['verdict']['score'] ?? 0),
        ));

        return $this->withContentFileId($entry);
    }

    /** Metadaten aller geprüften Seiten (neueste zuerst). */
    private function cmdAuditContentList(): array
    {
        $pages = array_map(
            fn (array $entry): array => $this->withContentFileId($entry),
            $this->contentQuality()->list(),
        );

        return ['pages' => $pages];
    }

    /** Vollständiges Ergebnis einer geprüften Seite. */
    private function cmdAuditContentGet(array $request): array
    {
        $key = $this->requireParam($request, 'key');

        return $this->withContentFileId($this->contentQuality()->get($key));
    }

    /** Löscht das Ergebnis einer geprüften Seite. */
    private function cmdAuditContentDelete(array $request): array
    {
        $this->requireMethod('POST');
        $key = $this->requireParam($request, 'key');

        return $this->contentQuality()->delete($key);
    }

    /**
     * Nimmt eine bereits verbesserte Seite wieder in die Arbeitsliste auf
     * (löscht den Verbesserungs-Vermerk, ohne neu zu prüfen).
     */
    private function cmdAuditContentRequeue(array $request): array
    {
        $service = $this->contentQuality();
        $this->requireMethod('POST');
        $key = $this->requireParam($request, 'key');

        return $this->withContentFileId($service->requeue($key));
    }

    /**
     * Merkt eine oder mehrere Content-Dateien zur KI-Verbesserung vor — OHNE den
     * kostenpflichtigen Qualitäts-Check. Optionale Freitext-Anweisung an die KI
     * wird jeder Datei mitgegeben. Danach stehen die Dateien unter „zu
     * verbessern" und werden vom Cron-Verbesserer (oder auf Knopfdruck)
     * bearbeitet. Nicht auflösbare/lesbare Dateien werden einzeln gemeldet, ohne
     * den ganzen Aufruf scheitern zu lassen.
     *
     * @return array{queued: int, failed: list<array{id: string, error: ?string}>, pages: list<array<string, mixed>>}
     */
    private function cmdAuditContentQueue(array $request): array
    {
        $service = $this->contentQuality();
        $this->requireMethod('POST');

        $ids = $request['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['ids']);
        }
        $instruction = is_string($request['instruction'] ?? null) ? $request['instruction'] : null;

        $queued = 0;
        $failed = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            try {
                $service->queueForImprovement($id, $instruction);
                $queued++;
            } catch (ApiException $e) {
                $failed[] = ['id' => $id, 'error' => $e->messageKey() ?? $e->errorCode()];
            }
        }
        $this->logger->info(sprintf('Zur KI-Verbesserung vorgemerkt: %d Datei(en), %d fehlgeschlagen.', $queued, count($failed)));

        return [
            'queued' => $queued,
            'failed' => $failed,
            'pages' => array_map(fn (array $e): array => $this->withContentFileId($e), $service->list()),
        ];
    }

    /**
     * Gesamt-Bericht über EINE Content-Datei: das gespeicherte Qualitätsurteil
     * plus die SEO-Funde derselben Datei aus dem JÜNGSTEN Audit-Lauf. Der
     * Audit-Teil ist null, wenn (noch) kein Lauf vorliegt. Dieselbe Struktur
     * nutzen später sowohl die Ansicht als auch der KI-Assistent, um gezielt
     * eine Datei zu verbessern.
     */
    private function cmdAuditContentReport(array $request): array
    {
        $service = $this->contentQuality();
        $key = $this->requireParam($request, 'key');
        $entry = $service->get($key); // wirft AUDIT-CONTENT-NOT-FOUND, falls unbekannt

        return [
            'file' => $this->withContentFileId([
                'mount' => $entry['mount'] ?? null,
                'rel' => $entry['rel'] ?? null,
                'title' => $entry['title'] ?? null,
            ]),
            'contentQuality' => $entry,
            'audit' => $this->auditIssuesForEntry($entry, (string) ($request['locale'] ?? 'de')),
        ];
    }

    /**
     * Speichert die vom Benutzer bearbeiteten Teile eines Berichts: die
     * Vorschlagsliste und ein optionales Freitext-Feld (Anweisung an die KI).
     * Die KI-Befunde bleiben unverändert. Liefert denselben Gesamt-Bericht wie
     * {@see cmdAuditContentReport} zurück, damit die Ansicht direkt aktualisiert.
     */
    private function cmdAuditContentUpdate(array $request): array
    {
        $service = $this->contentQuality();
        $this->requireMethod('POST');
        $key = $this->requireParam($request, 'key');

        $suggestions = $request['suggestions'] ?? [];
        $suggestions = is_array($suggestions) ? array_values($suggestions) : [];
        $instruction = $request['instruction'] ?? null;
        $instruction = is_string($instruction) ? $instruction : null;

        $entry = $service->updateEditable($key, $suggestions, $instruction);

        return [
            'file' => $this->withContentFileId([
                'mount' => $entry['mount'] ?? null,
                'rel' => $entry['rel'] ?? null,
                'title' => $entry['title'] ?? null,
            ]),
            'contentQuality' => $entry,
            'audit' => $this->auditIssuesForEntry($entry),
        ];
    }

    /**
     * SEO-Funde des jüngsten Audit-Laufs, die zur Quelldatei eines Content-
     * Eintrags gehören. null, wenn kein Lauf vorliegt. Jeder Fund erhält die
     * fileId der Datei, damit das Frontend zur Quelle springen kann.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private function auditIssuesForEntry(array $entry, string $locale = 'de'): ?array
    {
        if ($this->hugo === null) {
            return null;
        }
        $report = $this->auditStore()->latest();
        if ($report === null) {
            return null;
        }
        $summary = ['error' => 0, 'warning' => 0, 'hint' => 0];
        $issues = [];
        $rules = [];
        $rel = $this->sourceRelForEntry($entry);
        if ($rel !== null) {
            $fileId = $this->withContentFileId($entry)['fileId'] ?? null;
            foreach ($report['issues'] ?? [] as $issue) {
                if (!is_array($issue) || ($issue['sourceFile'] ?? null) !== $rel) {
                    continue;
                }
                if ($fileId !== null) {
                    $issue['fileId'] = $fileId;
                }
                $ruleId = (string) ($issue['ruleId'] ?? '');
                // Über diese Datei behebbar? Trennt die Arbeit von dem, was im
                // Theme oder in der URL-Struktur wurzelt (siehe RuleCatalog).
                if (RuleCatalog::fixable($ruleId)) {
                    $issue['fixable'] = true;
                }
                // Duplikat-Funde: die übrigen betroffenen Seiten. Ohne sie kann
                // die KI nichts Unterscheidendes schreiben — sie wüsste nicht,
                // wovon der neue Text sich abheben soll.
                $siblings = self::duplicateSiblings($report, $issue);
                if ($siblings !== []) {
                    $issue['duplicateOf'] = $siblings;
                }
                if ($ruleId !== '' && !array_key_exists($ruleId, $rules)) {
                    $rules[$ruleId] = $this->ruleSummary($ruleId, $locale);
                }
                $issues[] = $issue;
                $sev = (string) ($issue['severity'] ?? '');
                if (isset($summary[$sev])) {
                    $summary[$sev]++;
                }
            }
        }

        return [
            'runId' => $report['id'] ?? null,
            'startedAt' => $report['startedAt'] ?? null,
            'issues' => $issues,
            // Regel-Glossar: je vorkommender Regel EINE Erklärung, statt sie an
            // jedem Fund zu wiederholen. Damit weiß das Modell, was eine Regel
            // überhaupt verlangt, statt aus der Regel-ID zu raten. Leer als
            // Objekt, nicht als Liste — es ist eine Abbildung (wie byCategory).
            'rules' => self::mapOrObject(array_filter($rules, static fn (?array $r): bool => $r !== null)),
            'summary' => $summary,
        ];
    }

    /**
     * Gibt eine Abbildung als solche aus: leer als JSON-Objekt statt als leere
     * Liste (wie `byCategory` im Audit-Bericht).
     *
     * @param array<string, mixed> $map
     */
    private static function mapOrObject(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }

    /**
     * Kurzerklärung einer Regel aus der Hilfe-Datenbank (Titel + Zusammenfassung
     * aus dem Front-Matter) oder null. Bewusst nur die Zusammenfassung, nicht der
     * ganze Artikel: Der Dateibericht wird bei jedem Werkzeugaufruf mitgeschickt.
     *
     * @return array{title: string, summary: ?string}|null
     */
    private function ruleSummary(string $ruleId, string $locale): ?array
    {
        try {
            $topic = (new HelpService($this->helpDir))->topic('audit', $ruleId, $locale);
        } catch (Throwable) {
            return null;
        }

        return [
            'title' => (string) ($topic['title'] ?? $ruleId),
            'summary' => is_string($topic['summary'] ?? null) ? $topic['summary'] : null,
        ];
    }

    /**
     * Quellpfad relativ zum Hugo-Projekt (wie das sourceFile der Audit-Funde)
     * für einen Content-Eintrag, oder null, wenn die Datei nicht mehr auflösbar
     * ist oder außerhalb des Projekts liegt.
     *
     * @param array<string, mixed> $entry
     */
    private function sourceRelForEntry(array $entry): ?string
    {
        $mount = $entry['mount'] ?? null;
        $rel = $entry['rel'] ?? null;
        if ($this->hugo === null || !is_string($mount) || !is_string($rel)
            || !isset($this->resolver->all()[$mount])) {
            return null;
        }
        try {
            $r = $this->resolver->resolve($this->resolver->encodeId($mount, $rel), true);
        } catch (Throwable) {
            return null;
        }
        $sourceReal = realpath((string) $this->hugo['source']);
        $abs = $r['abs']; // von resolve(mustExist) bereits als realpath geliefert
        if ($sourceReal === false) {
            return null;
        }

        return str_starts_with($abs, $sourceReal . '/') ? substr($abs, strlen($sourceReal) + 1) : null;
    }

    /** Realpfad des Hugo-Content-Ordners, einmal je Request aufgelöst. */
    private ?string $contentDirReal = null;
    private bool $contentDirResolved = false;

    /**
     * Realpfad des Hugo-Content-Ordners (source/<contentDir>) oder null, wenn
     * diese Webseite kein Hugo-Projekt ist bzw. der Ordner (noch) nicht
     * existiert. Der contentDir-Name kommt aus der Hugo-Konfiguration
     * ({@see AuditService::detectContentDir}), Standard "content".
     */
    private function hugoContentDirReal(): ?string
    {
        if ($this->contentDirResolved) {
            return $this->contentDirReal;
        }
        $this->contentDirResolved = true;
        if ($this->hugo === null) {
            return $this->contentDirReal = null;
        }
        $source = realpath((string) $this->hugo['source']);
        if ($source === false) {
            return $this->contentDirReal = null;
        }
        $real = realpath($source . '/' . AuditService::detectContentDir($source));

        return $this->contentDirReal = ($real === false ? null : $real);
    }

    /**
     * Liegt der absolute Pfad im Hugo-Content-Ordner (der Ordner selbst oder
     * darunter)? Grundlage dafür, dass der Editor nur echten Content-Dateien
     * das Front-Matter-Template voranstellt bzw. ergänzt.
     */
    private function isHugoContentPath(string $abs): bool
    {
        $content = $this->hugoContentDirReal();
        if ($content === null) {
            return false;
        }

        return $abs === $content || str_starts_with($abs, $content . '/');
    }

    /**
     * Reichert einen Content-Eintrag um seine Dateimanager-ID (fileId) an, damit
     * das Frontend zur Quelle springen kann. Rekonstruiert aus Mount + Relativ-
     * pfad; nur, solange der Mount noch existiert. Nie ein roher Serverpfad.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function withContentFileId(array $entry): array
    {
        $mount = $entry['mount'] ?? null;
        $rel = $entry['rel'] ?? null;
        if (is_string($mount) && $mount !== '' && is_string($rel)
            && isset($this->resolver->all()[$mount])) {
            $entry['fileId'] = $this->resolver->encodeId($mount, $rel);
        }

        return $entry;
    }

    /**
     * Reichert die Funde eines Berichts um eine Dateimanager-ID (fileId) an,
     * sofern die Quelldatei in einem Mount liegt — damit das Frontend direkt
     * zur editierbaren Quelle springen kann. Es wird NIE ein Serverpfad
     * ausgegeben, nur die undurchsichtige ID.
     *
     * Dazu kommt `fixable`: Der Fund lässt sich über seine Content-Datei
     * beheben ({@see RuleCatalog::FIXABLE}), es gibt eine Datei dazu und der
     * KI-Assistent ist einsatzbereit — dann bietet der Client den Micro-Auftrag
     * an ({@see cmdAssistantFix}). Beides entsteht erst beim Ausliefern; der
     * abgelegte Bericht bleibt unangetastet, ältere Läufe bekommen die Angaben
     * dadurch automatisch.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function enrichReport(array $report): array
    {
        if ($this->hugo === null || !isset($report['issues']) || !is_array($report['issues'])) {
            return $report;
        }
        $source = (string) $this->hugo['source'];
        // Ohne API-Schlüssel oder im Nur-Lese-Modus kann der Assistent nichts
        // beheben — dann den Knopf gar nicht erst anbieten.
        $canFix = $this->ai['apiKey'] !== null && $this->ai['writeMode'] !== 'readonly';
        $cache = [];
        foreach ($report['issues'] as &$issue) {
            $rel = is_array($issue) ? ($issue['sourceFile'] ?? null) : null;
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            if (!array_key_exists($rel, $cache)) {
                $cache[$rel] = $this->resolveFileId($source . '/' . $rel);
            }
            if ($cache[$rel] !== null) {
                $issue['fileId'] = $cache[$rel];
                if ($canFix && RuleCatalog::fixable((string) ($issue['ruleId'] ?? ''))) {
                    $issue['fixable'] = true;
                }
            }
        }
        unset($issue);

        return $report;
    }

    /**
     * Übersetzt einen absoluten Serverpfad in eine Dateimanager-ID, falls er
     * innerhalb eines Mounts liegt; sonst null. Nutzt dieselbe ID-Kodierung wie
     * der Dateimanager ({@see MountResolver::encodeId}).
     */
    private function resolveFileId(string $absPath): ?string
    {
        $real = realpath($absPath);
        if ($real === false) {
            return null;
        }
        foreach ($this->resolver->all() as $mount) {
            $root = $mount->root();
            if ($real === $root) {
                return $this->resolver->encodeId($mount->name(), '');
            }
            if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $rel = str_replace('\\', '/', substr($real, strlen($root) + 1));

                return $this->resolver->encodeId($mount->name(), $rel);
            }
        }

        return null;
    }

    /**
     * Prüft einen Konfigurationswert: trimmt ihn, lehnt INI-sprengende Zeichen
     * (Anführungszeichen, Zeilenumbruch) ab. Pflichtfelder dürfen nicht leer
     * sein; optionale Felder geben '' zurück.
     */
    private static function cleanConfigValue(mixed $value, string $fieldKey, bool $required): string
    {
        $v = trim((string) $value);
        if ($v === '') {
            if ($required) {
                throw ApiException::badRequest('SETUP-FIELD-REQUIRED', [['t' => 'fields.' . $fieldKey]]);
            }
            return '';
        }
        if (preg_match('/["\r\n]/', $v) === 1) {
            throw ApiException::badRequest('SETUP-FIELD-INVALID-CHARS', [['t' => 'fields.' . $fieldKey]]);
        }

        return $v;
    }

    /** Gemeinsam für download/raw/thumb: Ziel auflösen, Leserecht, Datei. */
    private function resolveReadableFile(array $request): string
    {
        $this->requireAuth();
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'read');
        if (!is_file($target['abs'])) {
            throw ApiException::notFound('FILE-NOT-FOUND');
        }

        return $target['abs'];
    }

    /** Streamt eine Datei mit Cache-Validierung (ETag/304) und beendet. */
    private function streamFile(string $abs, string $mime, string $disposition): never
    {
        $etag = '"' . sha1($abs . filemtime($abs) . filesize($abs)) . '"';
        $this->maybeNotModified($etag);

        $encoded = rawurlencode(basename($abs));
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($abs));
        header("Content-Disposition: {$disposition}; filename=\"{$encoded}\"; filename*=UTF-8''{$encoded}");
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('ETag: ' . $etag);
        readfile($abs);
        exit;
    }

    /** Beantwortet die Anfrage mit 304, wenn der Client den Stand schon hat. */
    private function maybeNotModified(string $etag): void
    {
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }
    }

    /**
     * Normalisiert $_FILES['files'] (Einzeldatei oder files[]-Array) zu einer
     * Liste von Einzeleinträgen.
     *
     * @return list<array{name: mixed, tmp_name: mixed, error: mixed, size: mixed}>
     */
    private function uploadedFiles(): array
    {
        $raw = $_FILES['files'] ?? null;
        if ($raw === null) {
            throw ApiException::badRequest('PARAM-MISSING', ['files']);
        }

        if (!is_array($raw['name'] ?? null)) {
            return [$raw];
        }

        $list = [];
        foreach (array_keys($raw['name']) as $i) {
            $list[] = [
                'name' => $raw['name'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $raw['size'][$i] ?? 0,
            ];
        }
        if ($list === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['files']);
        }

        return $list;
    }

    // --- Hilfen ------------------------------------------------------------

    private function requireAuth(): void
    {
        if (!$this->auth->isAuthenticated()) {
            throw ApiException::unauthorized();
        }
    }

    private function requirePermission(Mount $mount, string $permission): void
    {
        if (!$mount->allows($permission) || !$this->auth->can('file.' . $permission)) {
            throw ApiException::denied('OPERATION-NOT-ALLOWED', [$permission]);
        }
    }

    private function requireMethod(string $method, bool $checkCsrf = true): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            throw new ApiException('EMETHOD', 405, 'METHOD-REQUIRED', [$method]);
        }
        // Alle Schreibbefehle laufen hier durch — CSRF zentral prüfen. Der Login
        // ist die einzige Ausnahme ($checkCsrf=false): Vor der Anmeldung kann der
        // Client kein gültiges Token der (ggf. neuen) Sitzung besitzen.
        if ($method === 'POST' && $checkCsrf) {
            $this->requireCsrf();
        }
    }

    /**
     * Liefert das sitzungsgebundene CSRF-Token (erzeugt es beim ersten Zugriff).
     * Ohne aktive Session (auth-Implementierung ohne PHP-Session) gibt es
     * keines — dann entfällt auch die Prüfung.
     */
    private function csrfToken(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (!isset($_SESSION['hugocms_csrf']) || !is_string($_SESSION['hugocms_csrf'])) {
            $_SESSION['hugocms_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['hugocms_csrf'];
    }

    /** Verlangt bei Schreibbefehlen das Token aus whoami (Header X-CSRF-Token). */
    private function requireCsrf(): void
    {
        $expected = $this->csrfToken();
        if ($expected === null) {
            return; // keine Session — kein sitzungsgebundenes Token möglich
        }
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals($expected, $sent)) {
            throw new ApiException('ECSRF', 403);
        }
    }

    private function requireParam(array $request, string $name): string
    {
        $value = $request[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw ApiException::badRequest('PARAM-MISSING', [$name]);
        }

        return $value;
    }

    /**
     * Liest eine Liste von IDs (akzeptiert auch eine einzelne ID als String).
     *
     * @return list<string>
     */
    private function requireIdList(array $request, string $name): array
    {
        $value = $request[$name] ?? null;
        if (is_string($value) && $value !== '') {
            $value = [$value];
        }
        $ids = [];
        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_string($v) && $v !== '') {
                    $ids[] = $v;
                }
            }
        }
        if ($ids === []) {
            throw ApiException::badRequest('PARAM-MISSING', [$name]);
        }

        return $ids;
    }

    /**
     * Vereinheitlicht GET-, Formular- und JSON-Eingaben zu einem Array.
     */
    private function parseRequest(): array
    {
        $request = array_merge($_GET, $_POST);

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = file_get_contents('php://input');
            if ($body !== false && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $request = array_merge($request, $decoded);
                }
            }
        }

        return $request;
    }

    private function applyCors(): void
    {
        if ($this->cors === null || headers_sent()) {
            return;
        }
        header('Access-Control-Allow-Origin: ' . $this->cors);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
