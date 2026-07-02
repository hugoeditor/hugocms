<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Audit\AuditService;
use HugoCMS\FileManager\Audit\ContentQualityService;
use HugoCMS\FileManager\Auth\AuthInterface;
use HugoCMS\FileManager\Exception\ApiException;
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
     * KI-Assistent-Konfiguration aus der [ai]-Sektion der hugocms.ini.
     * apiKey=null → Assistent deaktiviert. writeMode: readonly|confirm|auto.
     *
     * @var array{apiKey: ?string, model: string, writeMode: string}
     */
    private array $ai = ['apiKey' => null, 'model' => 'claude-opus-4-8', 'writeMode' => 'confirm'];

    /** Globale [user]-Einstellungen (Sitzungsdauer, Inhaltsbreite). */
    private array $user = ['sessionLifetime' => 28800, 'contentWidth' => 1200];

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
            $options['hugoBin'] ??= $cfg['hugoBin'];
            $this->ai = $cfg['ai'];
            $this->user = $cfg['user'];
            $authConfig = $cfg['auth'];
            // Globale [user]-Einstellungen an den Auth-Treiber durchreichen
            // (z. B. Sitzungsdauer für SingleUser).
            $authOptions = ['sessionLifetime' => $cfg['user']['sessionLifetime']];
        }
        $this->sessionDir = $sessionPath;

        // Logger und Fehler-Handler: danach werden auch Fehler in der Auth-
        // Erzeugung, in mount() und im weiteren Konstruktor erfasst.
        $this->logger = new Logger($options['log'] ?? null, $options['logLevel'] ?? 'error');
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
                'download' => $this->cmdDownload($request),
                'raw' => $this->cmdRaw($request),
                'thumb' => $this->cmdThumb($request),
                'search' => $this->cmdSearch($request),
                'trashlist' => $this->cmdTrashList(),
                'restore' => $this->cmdRestore($request),
                'emptytrash' => $this->cmdEmptyTrash($request),
                'build' => $this->cmdBuild(),
                'assistant' => $this->cmdAssistant($request),
                'assistantimprove' => $this->cmdAssistantImprove($request),
                'config' => $this->cmdConfig(),
                'reconfigure' => $this->cmdReconfigure($request),
                'account' => $this->cmdAccount($request),
                'license' => $this->cmdLicense(),
                'activate' => $this->cmdActivate($request),
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
                'auditcontent' => $this->cmdAuditContent($request),
                'auditcontentlist' => $this->cmdAuditContentList(),
                'auditcontentget' => $this->cmdAuditContentGet($request),
                'auditcontentreport' => $this->cmdAuditContentReport($request),
                'auditcontentrequeue' => $this->cmdAuditContentRequeue($request),
                'auditcontentdelete' => $this->cmdAuditContentDelete($request),
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

        return [
            'authenticated' => $this->auth->isAuthenticated(),
            'user' => $this->auth->currentUser(),
            'warnings' => $this->setupWarnings,
            // CSRF-Token für alle Schreibbefehle (Header X-CSRF-Token).
            'csrf' => $this->csrfToken(),
            // Ist ein Hugo-Aufruf möglich? Nötig sind das zentrale Programm
            // (hugocms.ini) UND der Webseiten-Teil (source) der Mount-Konfig.
            'buildable' => $this->hugo !== null && $this->hugoBin !== null,
            // Lässt sich die Konfiguration im laufenden Betrieb ändern? Nur,
            // wenn der Connector aus einer hugocms.ini aufgebaut wurde.
            'reconfigurable' => $this->configPath !== null,
            // KI-Assistent: aktiv, wenn ein API-Schlüssel konfiguriert ist.
            'ai' => [
                'enabled' => $this->ai['apiKey'] !== null,
                'writeMode' => $this->ai['writeMode'],
            ],
            // Globale UI-Vorgaben aus [user]. contentWidth ist im Einzelbenutzer-
            // Modus der Startwert für die Fensterbreite nach jedem Neuladen.
            'ui' => [
                'contentWidth' => $this->user['contentWidth'],
            ],
            // Pro-Edition (Git u. Ä.). 'configured' meldet einen hinterlegten,
            // ggf. ungültigen Schlüssel (falsche Domain) — für einen Hinweis im
            // Client. Der Schlüssel selbst wird nie zurückgegeben.
            'license' => $this->license()->info(),
            // Lässt sich eine Lizenz aktivieren? Nur, wenn eine Mount-Datei
            // geladen wurde (mounts/<hash>.ini bzw. mounts.ini) — dorthin wird
            // geschrieben. Bei custom.php (programmatisch) nicht möglich.
            'licensable' => $this->mountsPath !== null,
            // Git ist nur nutzbar, wenn die Webseite ein Hugo-Projekt hat
            // (dort liegt das Repository) UND eine gültige Pro-Lizenz vorliegt.
            'git' => $this->hugo !== null && $this->license()->isPro(),
            // Das SEO-Audit hat dieselbe Voraussetzung wie Git: Pro-Lizenz und
            // ein konfiguriertes Hugo-Projekt (für public/ und content/).
            'audit' => $this->hugo !== null && $this->license()->isPro(),
            // Die LLM-Content-Prüfung braucht zusätzlich einen konfigurierten
            // KI-Schlüssel ([ai] api_key).
            'auditContent' => $this->hugo !== null && $this->license()->isPro() && $this->ai['apiKey'] !== null,
        ];
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

        return [
            'authenticated' => true,
            'user' => $this->auth->currentUser(),
            'csrf' => $this->csrfToken(),
        ];
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

        return $this->files->makeFile(
            $parent['mount'],
            $parent['rel'],
            $parent['abs'],
            $this->requireParam($request, 'name'),
        );
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

        if ($this->hugo === null) {
            throw new ApiException('ECONFIG', 500, 'HUGO-NOT-CONFIGURED');
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

        // --cleanDestinationDir nur auf Wunsch (clean = true): Es entfernt im
        // Ziel ALLES, was Hugo nicht selbst erzeugt — auch die im Publish-
        // Ordner liegende Installation (edit/, cms-api/). Standard: aus.
        $cmd = escapeshellarg($bin)
            . (!empty($this->hugo['clean']) ? ' --cleanDestinationDir' : '')
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

        // Der Werkzeug-Loop kann mehrere API-Aufrufe nacheinander machen.
        @set_time_limit(180);

        return $this->assistantService()->run(
            $messages,
            $confirm === null ? null : (string) $confirm,
            $locale,
            $openFilePath,
            $openDirPath,
        );
    }

    /**
     * Baut den KI-Assistenten mit der aktuellen [ai]-Konfiguration. Nur bei
     * vorhandenem API-Schlüssel aufrufen (der Client verlangt einen). Das
     * Werkzeug get_file_report wird nur eingehängt, wenn Pro-Lizenz und
     * Hugo-Projekt vorliegen (sonst gibt es keinen Audit-/Content-Bericht).
     */
    private function assistantService(): AssistantService
    {
        // get_file_report und der Bearbeitungs-Vermerk brauchen beide das
        // Content-Qualitäts-Feature (Pro-Lizenz + Hugo-Projekt).
        $contentAware = $this->hugo !== null && $this->license()->isPro();
        $fileReport = $contentAware
            ? fn (string $fileId): array => $this->buildFileReportById($fileId)
            : null;
        $onWrite = $contentAware
            ? fn (string $fileId) => $this->markFileImproved($fileId)
            : null;

        return new AssistantService(
            new AnthropicClient((string) $this->ai['apiKey']),
            $this->ai['model'],
            $this->ai['writeMode'],
            $this->resolver,
            $this->files,
            $fileReport,
            $onWrite,
        );
    }

    /**
     * Vermerkt eine KI-Bearbeitung am Content-Qualitäts-Eintrag der Datei (jede
     * vom Assistenten geschriebene Datei gilt als „verbessert"). Ohne
     * Hugo-Projekt oder ohne vorhandenen Eintrag ein No-op. Das Modell ist das
     * konfigurierte Assistenten-Modell.
     */
    private function markFileImproved(string $fileId): void
    {
        if ($this->hugo === null) {
            return;
        }
        $storage = __DIR__ . '/../var/audit-content/' . sha1((string) $this->hugo['source']);
        (new ContentQualityService(
            new AnthropicClient((string) $this->ai['apiKey']),
            $this->ai['model'],
            $this->resolver,
            $this->files,
            $storage,
        ))->markImproved($fileId, $this->ai['model']);
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

    /** Startanweisung für den Verbesserungslauf (in der Sprache des Benutzers). */
    private function improveInstruction(string $path, string $locale): string
    {
        if (str_starts_with(strtolower($locale), 'en')) {
            return "Improve the existing content file `{$path}`. Steps: (1) call get_file_report for this path and act on BOTH parts — the content-quality verdict AND the SEO findings; (2) read the file; (3) fix the reported issues and write the improved version in a SINGLE write_file call. Adopt the file's existing front-matter format. Address the SEO findings as far as this file allows (e.g. title/H1 duplication, missing meta description, Open Graph / Twitter fields). If a SEO finding depends on which front-matter field the theme reads (e.g. og:image), you MAY READ the relevant layout/partial to find the correct field — but WRITE only this content file. Keep the front matter valid and preserve the author's meaning.";
        }

        return "Verbessere die bestehende Content-Datei `{$path}`. Vorgehen: (1) rufe get_file_report für diesen Pfad auf und beachte BEIDE Teile — das Qualitätsurteil UND die SEO-Funde; (2) lies die Datei; (3) behebe die gemeldeten Probleme und schreibe die verbesserte Fassung in EINEM write_file-Aufruf. Übernimm das vorhandene Front-Matter-Format der Datei. Behebe die SEO-Funde, soweit über diese Datei möglich (z. B. Titel/H1-Dopplung, fehlende Meta-Description, Open-Graph-/Twitter-Felder). Hängt ein SEO-Fund davon ab, welches Front-Matter-Feld das Theme auswertet (etwa og:image), darfst du das betreffende Layout/Partial NUR LESEN, um das richtige Feld zu finden — GESCHRIEBEN wird ausschließlich diese Content-Datei. Halte das Front-Matter gültig und bewahre die Aussage des Autors.";
    }

    /**
     * Gesamt-Bericht einer Datei anhand ihrer Dateimanager-ID (für das
     * Assistenten-Werkzeug get_file_report). Qualitätsurteil optional (null,
     * wenn die Datei nie geprüft wurde), SEO-Funde aus dem jüngsten Lauf.
     *
     * @return array<string, mixed>
     */
    private function buildFileReportById(string $fileId): array
    {
        $r = $this->resolver->resolve($fileId, true);
        $mount = $r['mount']->name();
        $entry = $this->contentQuality()->forFile($fileId); // null, falls nie geprüft
        $base = is_array($entry) ? $entry : ['mount' => $mount, 'rel' => $r['rel'], 'title' => basename($r['rel'])];

        return [
            'file' => $this->withContentFileId([
                'mount' => $mount,
                'rel' => $r['rel'],
                'title' => $base['title'] ?? basename($r['rel']),
            ]),
            'contentQuality' => $entry,
            'audit' => $this->auditIssuesForEntry($base),
        ];
    }

    /** Erlaubte Log-Stufen — gemeinsam für Lesen (config) und Schreiben. */
    private const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    /** Erlaubte Schreibmodi des KI-Assistenten. */
    private const AI_WRITE_MODES = ['readonly', 'confirm', 'auto'];

    /** Mindestlänge für ein neu gesetztes Passwort (wie im Erst-Setup). */
    private const MIN_PASSWORD_LENGTH = 8;

    /**
     * Liefert die aktuellen, ROHEN (nicht aufgelösten) Konfigurationswerte aus
     * der hugocms.ini zum Vorbefüllen des Umkonfigurations-Formulars. Die
     * Anmeldedaten ([auth]) werden bewusst NICHT zurückgegeben.
     */
    private function cmdConfig(): array
    {
        $this->requireAuth();
        if ($this->configPath === null) {
            throw new ApiException('ECONFIG', 409, 'RECONFIGURE-UNAVAILABLE');
        }
        $raw = Config::raw($this->configPath);

        return [
            'sessionPath' => $raw['session']['path'] ?? '',
            'logFile'     => $raw['log']['file'] ?? '',
            'logLevel'    => $raw['log']['level'] ?? 'warning',
            'hugoBin'     => $raw['hugo']['bin'] ?? '',
            'logLevels'   => self::LOG_LEVELS,
            // KI-Assistent: Status + Modus/Modell, aber NIE der API-Schlüssel.
            'aiConfigured' => trim((string) ($raw['ai']['api_key'] ?? '')) !== '',
            'aiModel'      => $raw['ai']['model'] ?? '',
            'aiWriteMode'  => $raw['ai']['write_mode'] ?? 'confirm',
            'aiWriteModes' => self::AI_WRITE_MODES,
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
        $this->requireAuth();
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

        // KI-Assistent. Der API-Schlüssel ist ein Geheimnis: ein leeres Feld
        // lässt den bestehenden unverändert; nur eine Eingabe ersetzt ihn.
        $aiWriteMode = strtolower(trim((string) ($request['aiWriteMode'] ?? 'confirm')));
        if (!in_array($aiWriteMode, self::AI_WRITE_MODES, true)) {
            $aiWriteMode = 'confirm';
        }
        $aiModel = self::cleanConfigValue($request['aiModel'] ?? '', 'aiModel', false);
        $aiKeyNew = self::cleanConfigValue($request['aiApiKey'] ?? '', 'aiApiKey', false);
        $existingAi = Config::raw($this->configPath)['ai'] ?? [];
        $apiKey = $aiKeyNew !== '' ? $aiKeyNew : trim((string) ($existingAi['api_key'] ?? ''));
        $model = $aiModel !== '' ? $aiModel : (trim((string) ($existingAi['model'] ?? '')) ?: 'claude-opus-4-8');

        Config::updateSections($this->configPath, [
            'session' => ['path' => $sessionPath],
            'log'     => ['file' => $logFile, 'level' => $logLevel],
            // Leeres Hugo-Programm entfernt die Sektion (kein Build).
            'hugo'    => $hugoBin === '' ? null : ['bin' => $hugoBin],
            // Ohne API-Schlüssel keine [ai]-Sektion (Assistent aus).
            'ai'      => $apiKey === ''
                ? null
                : ['api_key' => $apiKey, 'model' => $model, 'write_mode' => $aiWriteMode],
        ]);
        $this->logger->info('Konfiguration aktualisiert (reconfigure).');

        return ['ok' => true];
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
     * Aktiviert eine Pro-Lizenz für DIESE Webseite: prüft den Schlüssel gegen
     * die aktuelle Domain und schreibt ihn in die [license]-Sektion der
     * geladenen Mount-Konfiguration (mounts/<hash>.ini). Die übrigen Sektionen
     * (Mounts, [hugo]) bleiben wörtlich erhalten. Ein ungültiger Schlüssel
     * (falsche Domain, Signatur) wird abgelehnt, bevor etwas persistiert wird.
     * Die Edition greift ab dem nächsten Request.
     */
    private function cmdActivate(array $request): array
    {
        $this->requireAuth();
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
        $source = (string) $this->hugo['source'];
        $public = (string) ($this->hugo['destination'] ?? $source . '/public');
        $storage = __DIR__ . '/../var/audit/' . sha1($source);

        return new AuditService($public, $source, $storage);
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
        $source = (string) $this->hugo['source'];
        $storage = __DIR__ . '/../var/audit-content/' . sha1($source);

        return new ContentQualityService(
            new AnthropicClient($this->ai['apiKey']),
            $this->ai['model'],
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
    private function auditIssuesForEntry(array $entry): ?array
    {
        $report = $this->audit()->latest();
        if ($report === null) {
            return null;
        }
        $summary = ['error' => 0, 'warning' => 0, 'hint' => 0];
        $issues = [];
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
            'summary' => $summary,
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
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function enrichReport(array $report): array
    {
        if ($this->hugo === null || !isset($report['issues']) || !is_array($report['issues'])) {
            return $report;
        }
        $source = (string) $this->hugo['source'];
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
