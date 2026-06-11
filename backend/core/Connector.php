<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

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

    /** Hinweise zur Einrichtung (z. B. fehlende Verzeichnisse), an den Client gemeldet. */
    private array $setupWarnings = [];

    public function __construct(array $options)
    {
        // Hauptkonfiguration (hugocms.ini) ggf. zuerst einlesen — daraus
        // stammen Log-Ziel, Sitzungsverzeichnis und Authentifizierung. Das
        // reine Einlesen hat keine Seiteneffekte; Fehler hier (Datei fehlt /
        // ungültig) werden direkt als JSON beantwortet, da der Logger und die
        // Fehler-Handler noch nicht stehen.
        $authConfig = null;
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
                    $this->setupWarnings[] = sprintf(
                        'Sitzungsverzeichnis fehlt: %s — Anmeldungen sind mglw. nicht von Dauer.',
                        $sessionPath,
                    );
                }
            }

            // Log nur aus der Datei übernehmen, wenn nicht explizit gesetzt.
            $options['log'] ??= $cfg['log']['file'];
            $options['logLevel'] ??= $cfg['log']['level'] ?? 'error';
            $authConfig = $cfg['auth'];
        }

        // Logger und Fehler-Handler: danach werden auch Fehler in der Auth-
        // Erzeugung, in mount() und im weiteren Konstruktor erfasst.
        $this->logger = new Logger($options['log'] ?? null, $options['logLevel'] ?? 'error');
        $this->registerErrorHandlers();

        // Fehlt das Log-Verzeichnis, schreibt der Logger ins Server-Log — Hinweis.
        $logFile = $options['log'] ?? null;
        if ($logFile !== null && !is_dir(dirname($logFile))) {
            $this->setupWarnings[] = sprintf(
                'Log-Verzeichnis fehlt: %s — Meldungen gehen ins Server-Log.',
                dirname($logFile),
            );
        }

        // Authentifizierung: entweder direkt übergeben oder aus der
        // Konfiguration über die AuthFactory erzeugen (driver-abhängig).
        if (!isset($options['auth']) && $authConfig !== null) {
            $factory = new AuthFactory();
            foreach ((array) ($options['authDrivers'] ?? []) as $name => $driverFactory) {
                $factory->register((string) $name, $driverFactory);
            }
            $options['auth'] = $factory->create($authConfig);
        }

        if (!isset($options['auth']) || !$options['auth'] instanceof AuthInterface) {
            throw new ApiException(
                'Authentifizierung fehlt: Option "auth" (AuthInterface) übergeben oder über "config" konfigurieren.',
                'ECONFIG',
                500,
            );
        }

        $this->auth = $options['auth'];
        $this->resolver = new MountResolver();
        $this->files = new FileService(
            $this->resolver,
            $options['editable'] ?? ['html', 'htm', 'md', 'markdown', 'txt', 'css', 'js', 'json', 'xml', 'yaml', 'yml', 'svg', 'toml'],
            $options['maxEditableBytes'] ?? 5_242_880,
        );
        $this->cors = $options['cors'] ?? null;
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
        foreach (MountConfig::load($configPath) as $spec) {
            $this->mount($spec['name'], $spec['path'], $spec['options']);
        }

        return $this;
    }

    /**
     * Fügt einen Einrichtungs-Hinweis hinzu, der über whoami an den Client
     * gemeldet wird (z. B. Rückfall auf die Standard-mounts.ini). Für Hinweise
     * aus der Boot-Schicht, die nicht im Konstruktor entstehen.
     */
    public function addSetupWarning(string $message): self
    {
        $this->setupWarnings[] = $message;

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
                default => throw ApiException::badRequest("Unbekannter Befehl: {$cmd}"),
            };

            Response::ok($data);
        } catch (ApiException $e) {
            $this->logger->warning($e->getMessage(), ['code' => $e->errorCode(), 'cmd' => $cmd ?? null]);
            Response::error($e->errorCode(), $e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            $this->logger->exception($e);
            Response::error('EINTERNAL', 'Interner Fehler.', 500);
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
                Response::error($e->errorCode(), $e->getMessage(), $e->httpStatus());
            }
            $this->logger->exception($e);
            Response::error('EINTERNAL', 'Interner Fehler.', 500);
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
                    Response::error('EFATAL', 'Interner Fehler.', 500);
                }
            }
        });
    }

    // --- Befehle -----------------------------------------------------------

    private function cmdWhoami(): array
    {
        return [
            'authenticated' => $this->auth->isAuthenticated(),
            'user' => $this->auth->currentUser(),
            'warnings' => $this->setupWarnings,
        ];
    }

    private function cmdLogin(array $request): array
    {
        $this->requireMethod('POST');
        $ok = $this->auth->attemptLogin(
            (string) ($request['username'] ?? ''),
            (string) ($request['password'] ?? ''),
        );
        if (!$ok) {
            throw ApiException::unauthorized('Benutzername oder Passwort falsch.');
        }

        return ['authenticated' => true, 'user' => $this->auth->currentUser()];
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

        return [
            'cwd' => $this->files->entryInfo($target['mount'], $target['rel'], $target['abs']),
            'entries' => $this->files->listDir($target['mount'], $target['rel'], $target['abs']),
        ];
    }

    private function cmdRead(array $request): array
    {
        $this->requireAuth();
        $target = $this->resolver->resolve($this->requireParam($request, 'target'));
        $this->requirePermission($target['mount'], 'read');

        return $this->files->readText($target['mount'], $target['abs']);
    }

    private function cmdWrite(array $request): array
    {
        $this->requireAuth();
        $this->requireMethod('POST');
        $target = $this->resolver->resolve($this->requireParam($request, 'target'), false);
        $this->requirePermission($target['mount'], 'write');

        $content = $request['content'] ?? null;
        if (!is_string($content)) {
            throw ApiException::badRequest('Parameter "content" fehlt.');
        }

        return $this->files->writeText($target['mount'], $target['rel'], $target['abs'], $content);
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
            throw ApiException::denied("Operation '{$permission}' auf diesem Mount nicht erlaubt.");
        }
    }

    private function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            throw new ApiException("Diese Operation erfordert {$method}.", 'EMETHOD', 405);
        }
    }

    private function requireParam(array $request, string $name): string
    {
        $value = $request[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw ApiException::badRequest("Parameter \"{$name}\" fehlt.");
        }

        return $value;
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
