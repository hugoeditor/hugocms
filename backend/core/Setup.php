<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;
use Throwable;

/**
 * Erstinbetriebnahme: Wird vom Einstiegspunkt (hugocms.php) aufgerufen, wenn
 * weder custom.php noch hugocms.ini vorhanden sind. Solange das so ist, ist
 * HugoCMS nicht konfiguriert und der Connector lässt sich nicht aufbauen —
 * darum übernimmt dieser schlanke Handler die Anfragen direkt.
 *
 * Zwei Befehle:
 *   - whoami: meldet setupRequired=true samt Vorgaben für das Setup-Formular.
 *   - setup:  prüft die Eingaben, erzeugt die hugocms.ini, legt die benötigten
 *             Verzeichnisse an und meldet den neuen Benutzer direkt an.
 *
 * Sobald die hugocms.ini geschrieben ist, greift im Einstiegspunkt der normale
 * Pfad (Connector) und dieser Handler wird nicht mehr erreicht. Es entsteht
 * also kein dauerhaft offener, unauthentifizierter Schreibendpunkt.
 */
final class Setup
{
    private const DEFAULT_SESSION_PATH = 'var/sessions';
    private const DEFAULT_LOG_FILE     = 'log/hugocms.log';
    private const DEFAULT_LOG_LEVEL    = 'warning';
    private const DEFAULT_HUGO_BIN     = '../bin/hugo/hugo';
    private const LOG_LEVELS           = ['debug', 'info', 'warning', 'error'];
    private const AI_WRITE_MODES       = ['readonly', 'confirm', 'auto'];
    private const MIN_PASSWORD_LENGTH  = 8;

    /** Beantwortet die Anfrage im Einrichtungszustand und beendet das Skript. */
    public static function handle(string $backendDir): never
    {
        try {
            $request = self::readRequest();
            $cmd = (string) ($request['cmd'] ?? 'whoami');

            match ($cmd) {
                'whoami' => self::status(),
                'setup'  => self::create($backendDir, $request),
                default  => throw new ApiException('ESETUP', 503, 'SETUP-REQUIRED'),
            };
        } catch (ApiException $e) {
            Response::fromException($e);
        } catch (Throwable $e) {
            Response::error('EINTERNAL', null, 500);
        }
    }

    /** Signalisiert dem Frontend, dass die Einrichtung aussteht (samt Vorgaben). */
    private static function status(): never
    {
        Response::ok([
            'authenticated' => false,
            'user'          => null,
            'warnings'      => [],
            'setupRequired' => true,
            'defaults'      => [
                'username'    => 'admin',
                'sessionPath' => self::DEFAULT_SESSION_PATH,
                'logFile'     => self::DEFAULT_LOG_FILE,
                'logLevel'    => self::DEFAULT_LOG_LEVEL,
                'logLevels'   => self::LOG_LEVELS,
                'hugoBin'     => self::DEFAULT_HUGO_BIN,
                'aiModel'     => 'claude-opus-4-8',
                'aiModelCron' => '',
                'aiModelAudit' => '',
                'aiWriteMode' => 'confirm',
                'aiWriteModes' => self::AI_WRITE_MODES,
            ],
        ]);
    }

    /** Erzeugt die hugocms.ini, legt Verzeichnisse an und meldet den Benutzer an. */
    private static function create(string $backendDir, array $request): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new ApiException('EMETHOD', 405, 'SETUP-METHOD-POST');
        }

        $configFile = $backendDir . '/hugocms.ini';
        if (is_file($configFile)) {
            // Im Setup-Zweig dürfte das nicht vorkommen; schützt vor einem
            // doppelten Absenden bzw. einem parallelen zweiten Setup-Aufruf.
            throw new ApiException('ESETUP', 409, 'SETUP-ALREADY-CONFIGURED');
        }
        if (!is_writable($backendDir)) {
            throw new ApiException('ESETUP', 500, 'SETUP-DIR-NOT-WRITABLE', [$backendDir]);
        }

        $username    = self::requireField($request, 'username');
        $password    = self::requirePassword($request);
        $sessionPath = self::requireField($request, 'sessionPath');
        $logFile     = self::requireField($request, 'logFile');
        $logLevel    = self::requireLevel($request);
        $hugoBin     = self::optionalField($request, 'hugoBin');
        $aiApiKey    = self::optionalField($request, 'aiApiKey');
        $aiModel     = self::optionalField($request, 'aiModel');
        $aiModelCron  = self::optionalField($request, 'aiModelCron');
        $aiModelAudit = self::optionalField($request, 'aiModelAudit');
        $aiWriteMode = strtolower(trim((string) ($request['aiWriteMode'] ?? 'confirm')));
        if (!in_array($aiWriteMode, self::AI_WRITE_MODES, true)) {
            $aiWriteMode = 'confirm';
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        // hugocms.ini über die zentrale Schreib-API anlegen (gleiche Logik wie
        // beim späteren Umkonfigurieren). [hugo]/[ai] nur bei gesetztem Wert.
        // Das Nicht-Überschreiben einer bestehenden Datei sichert der
        // is_file()-Check oben ab.
        $sections = [
            'auth'    => ['driver' => 'singleuser', 'username' => $username, 'password_hash' => $hash],
            'session' => ['path' => $sessionPath],
            'log'     => ['file' => $logFile, 'level' => $logLevel],
        ];
        if ($hugoBin !== '') {
            $sections['hugo'] = ['bin' => $hugoBin];
        }
        if ($aiApiKey !== '') {
            // Cron-/Audit-Modell nur ablegen, wenn abweichend gewählt; leer
            // bedeutet „wie Assistenten-Modell" (Fallback in Config::aiSection).
            $ai = [
                'api_key' => $aiApiKey,
                'model' => $aiModel !== '' ? $aiModel : 'claude-opus-4-8',
            ];
            if ($aiModelCron !== '') {
                $ai['model_cron'] = $aiModelCron;
            }
            if ($aiModelAudit !== '') {
                $ai['model_audit'] = $aiModelAudit;
            }
            $ai['write_mode'] = $aiWriteMode;
            $sections['ai'] = $ai;
        }
        Config::updateSections(
            $configFile,
            $sections,
            "; HugoCMS – Hauptkonfiguration (vom Einrichtungs-Setup erzeugt)\n"
                . '; Dokumentation der Felder: hugocms.ini.beispiel',
        );

        // Konfiguration laden (prüft erneut alle Pflichtfelder) und die nun
        // erwarteten Verzeichnisse anlegen, damit Session und Log sofort greifen.
        $cfg = Config::load($configFile);
        self::ensureDir($cfg['session']['path']);
        self::ensureDir(dirname($cfg['log']['file']));

        // Automatische Anmeldung über den regulären Auth-Pfad: Session-Ort
        // setzen, Auth bauen (startet die Session) und mit dem soeben gesetzten
        // Klartextpasswort anmelden — der Hash stammt aus demselben Passwort.
        if (is_dir($cfg['session']['path'])) {
            session_save_path($cfg['session']['path']);
        }
        $auth = (new AuthFactory())->create($cfg['auth']);
        if (!$auth->attemptLogin($username, $password)) {
            throw new ApiException('ESETUP', 500, 'SETUP-AUTOLOGIN-FAILED');
        }

        Response::ok([
            'authenticated' => true,
            'user'          => $auth->currentUser(),
            'warnings'      => [],
        ]);
    }

    /** Liest GET-, Formular- und JSON-Eingaben zu einem Array zusammen. */
    private static function readRequest(): array
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

    /**
     * Pflichtfeld: nicht leer und ohne Zeichen, die die INI sprengen würden.
     * Der Feldname wandert als übersetzbarer Parameter ({t: ...}) in die
     * Meldung, damit der Client ihn in der gewählten Sprache einsetzt.
     */
    private static function requireField(array $request, string $key): string
    {
        $value = trim((string) ($request[$key] ?? ''));
        if ($value === '') {
            throw new ApiException('EINVAL', 400, 'SETUP-FIELD-REQUIRED', [['t' => 'fields.' . $key]]);
        }
        if (preg_match('/["\r\n]/', $value) === 1) {
            throw new ApiException('EINVAL', 400, 'SETUP-FIELD-INVALID-CHARS', [['t' => 'fields.' . $key]]);
        }

        return $value;
    }

    /**
     * Optionales Feld: leer erlaubt, sonst dieselbe Zeichenprüfung wie bei
     * requireField (keine Anführungszeichen/Zeilenumbrüche).
     */
    private static function optionalField(array $request, string $key): string
    {
        $value = trim((string) ($request[$key] ?? ''));
        if ($value !== '' && preg_match('/["\r\n]/', $value) === 1) {
            throw new ApiException('EINVAL', 400, 'SETUP-FIELD-INVALID-CHARS', [['t' => 'fields.' . $key]]);
        }

        return $value;
    }

    /** Das Passwort wird nicht in die INI geschrieben (nur sein Hash) — nur Länge prüfen. */
    private static function requirePassword(array $request): string
    {
        $password = (string) ($request['password'] ?? '');
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ApiException('EINVAL', 400, 'SETUP-PASSWORD-TOO-SHORT', [self::MIN_PASSWORD_LENGTH]);
        }

        return $password;
    }

    /** Log-Stufe gegen die erlaubten Werte prüfen. */
    private static function requireLevel(array $request): string
    {
        $level = strtolower(trim((string) ($request['logLevel'] ?? '')));
        if (!in_array($level, self::LOG_LEVELS, true)) {
            throw new ApiException('EINVAL', 400, 'SETUP-LOG-LEVEL-INVALID', [implode(', ', self::LOG_LEVELS)]);
        }

        return $level;
    }

    /** Erzeugt fehlende Verzeichnisse (rekursiv). */
    private static function ensureDir(string $path): void
    {
        if ($path !== '' && !is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}
