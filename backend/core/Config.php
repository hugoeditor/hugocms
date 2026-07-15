<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Liest die Hauptkonfiguration (hugocms.ini): Authentifizierung, Verzeichnis
 * für die Sitzungsdateien und Logging. INI mit Sektionen; relative Pfade
 * gelten relativ zum Verzeichnis der Konfigurationsdatei.
 *
 *   [auth]
 *   driver = singleuser
 *   username = admin
 *   password_hash = "$2y$10$..."
 *
 *   [user]
 *   session_lifetime = 8   ; Sitzungsdauer in Stunden (optional; Standard 8)
 *   update_lastmod = false ; lastmod beim Speichern setzen (optional; fehlt =
 *                          ; im Editor nachfragen)
 *
 *   [session]
 *   path = var/sessions
 *
 *   [log]
 *   file = log/hugocms.log
 *   level = error      ; debug | info | warning | error
 *
 *   [hugo]
 *   bin = ../bin/hugo/hugo   ; zentraler Pfad zum Hugo-Programm (optional)
 *
 * Die Sektion [hugo] enthält hier NUR das Programm (bin) — es gibt installa-
 * tionsweit nur eine Hugo-Binärdatei. Die je Webseite unterschiedlichen Pfade
 * (source/destination) stehen in der jeweiligen Mount-Konfiguration.
 */
final class Config
{
    /** Standard-Sitzungsdauer in Sekunden, falls [user] session_lifetime fehlt (8 Stunden). */
    private const DEFAULT_SESSION_LIFETIME = 28800;

    /** Standard-Inhaltsbreite in px, falls [user] content_width fehlt. */
    private const DEFAULT_CONTENT_WIDTH = 1200;

    /** Untergrenze für content_width; kleinere Werte fallen auf den Standard zurück. */
    private const MIN_CONTENT_WIDTH = 320;

    /**
     * @return array{
     *   auth: array<string, mixed>,
     *   user: array{sessionLifetime: int, contentWidth: int, updateLastmod: ?bool},
     *   session: array{path: string},
     *   log: array{file: string, level: string, maxBytes: int, keep: int},
     *   hugoBin: ?string,
     *   hugoClean: bool
     * }
     */
    public static function load(string $configPath): array
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-NOT-READABLE', [$configPath]);
        }

        $raw = @parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if (!is_array($raw)) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-INVALID-INI', [$configPath]);
        }

        $baseDir = dirname($configPath);

        $auth = (isset($raw['auth']) && is_array($raw['auth'])) ? $raw['auth'] : [];

        // Vollständigkeit prüfen: Alle Pflichtfelder müssen gesetzt und nicht
        // leer sein. Die treiberabhängigen Auth-Felder (etwa username,
        // password_hash bei singleuser) prüft die AuthFactory. Fehlende Felder
        // werden gesammelt und gemeinsam gemeldet, damit nicht bei jedem Start
        // nur das nächste fehlende Feld auftaucht.
        $missing = [];
        if (self::isBlank($auth['driver'] ?? null)) {
            $missing[] = '[auth] driver';
        }
        if (self::isBlank($raw['session']['path'] ?? null)) {
            $missing[] = '[session] path';
        }
        if (self::isBlank($raw['log']['file'] ?? null)) {
            $missing[] = '[log] file';
        }
        if (self::isBlank($raw['log']['level'] ?? null)) {
            $missing[] = '[log] level';
        }
        if ($missing !== []) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-INCOMPLETE', [$configPath, implode(', ', $missing)]);
        }

        // Zentraler Hugo-Programmpfad (optional). Fehlt er, ist kein Build
        // möglich — der Connector meldet das je Webseite (buildable=false).
        $hugoBin = trim((string) ($raw['hugo']['bin'] ?? ''));

        // --cleanDestinationDir global aktivieren ([hugo] clean). updateSections
        // schreibt den Wert gequotet ("true"); filter_var liest beide Formen.
        $hugoClean = filter_var($raw['hugo']['clean'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'auth' => $auth,
            'user' => self::userSection($raw['user'] ?? null),
            'session' => [
                'path' => self::resolvePath($raw['session']['path'], $baseDir),
            ],
            'log' => [
                'file' => self::resolvePath($raw['log']['file'], $baseDir),
                'level' => trim((string) $raw['log']['level']),
                // Rotation ist optional; leer/fehlend → Vorgabewerte (1 MB, 3 Stände).
                'maxBytes' => self::isBlank($raw['log']['max_bytes'] ?? null)
                    ? 1048576
                    : max(0, (int) $raw['log']['max_bytes']),
                'keep' => self::isBlank($raw['log']['keep'] ?? null)
                    ? 3
                    : max(1, (int) $raw['log']['keep']),
            ],
            'hugoBin' => $hugoBin === '' ? null : self::resolvePath($hugoBin, $baseDir),
            'hugoClean' => $hugoClean,
            'ai' => self::aiSection($raw['ai'] ?? null),
            'services' => self::servicesSection($raw['services'] ?? null),
            'mail' => self::mailSection($raw['mail'] ?? null),
            'seoReport' => self::seoReportSection($raw['seo_report'] ?? null),
        ];
    }

    /**
     * SEO-Bericht ([seo_report]-Sektion, optional). exclude_prefixes ergänzt die
     * fest verdrahteten Ausschlüsse des Audits ({@see \HugoCMS\FileManager\Audit\AuditRunner})
     * um WEITERE public-relative Pfad-Präfixe, die nicht geprüft werden sollen
     * (z. B. "test/", "error/"). exclude_files schließt EINZELNE Dateien aus
     * (exakter public-relativer Pfad, z. B. Suchmaschinen-Bestätigungsdateien wie
     * "google….html", die nicht verändert oder gelöscht werden dürfen). Beide
     * kommagetrennt in der INI.
     *
     * @return array{excludePrefixes: list<string>, excludeFiles: list<string>}
     */
    private static function seoReportSection(mixed $section): array
    {
        $section = is_array($section) ? $section : [];

        return [
            'excludePrefixes' => self::normalizeExcludePrefixes((string) ($section['exclude_prefixes'] ?? '')),
            'excludeFiles' => self::normalizeExcludeFiles((string) ($section['exclude_files'] ?? '')),
        ];
    }

    /**
     * Normalisiert eine kommagetrennte (oder zeilengetrennte) Liste von
     * Ausschluss-Präfixen für den SEO-Bericht in public-relative Präfixe mit
     * Schrägstrich am Ende: trimmt, entfernt führende Schrägstriche, verwirft
     * leere Einträge und solche mit ".." und entdoppelt. Gemeinsam genutzt vom
     * Einlesen (load) und vom Schreiben (Connector::cmdReconfigure), damit INI
     * und Laufzeit dieselbe Form sehen.
     *
     * @return list<string>
     */
    public static function normalizeExcludePrefixes(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\r\n]+/', $raw) ?: [] as $entry) {
            $prefix = trim((string) $entry);
            $prefix = ltrim($prefix, '/');
            if ($prefix === '' || str_contains($prefix, '..')) {
                continue;
            }
            if (!str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            $out[$prefix] = true;
        }

        return array_keys($out);
    }

    /**
     * Normalisiert eine kommagetrennte (oder zeilengetrennte) Liste EINZELNER
     * public-relativer Dateien, die der SEO-Bericht überspringt: trimmt, entfernt
     * führende Schrägstriche, verwirft leere Einträge und solche mit ".." und
     * entdoppelt. Anders als bei den Präfixen wird KEIN Schrägstrich angehängt —
     * verglichen wird der exakte Dateipfad. Gemeinsam genutzt vom Einlesen (load)
     * und vom Schreiben (Connector::cmdReconfigure).
     *
     * @return list<string>
     */
    public static function normalizeExcludeFiles(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\r\n]+/', $raw) ?: [] as $entry) {
            $file = trim((string) $entry);
            $file = ltrim($file, '/');
            if ($file === '' || str_contains($file, '..')) {
                continue;
            }
            $out[$file] = true;
        }

        return array_keys($out);
    }

    /**
     * E-Mail-Versand ([mail]-Sektion), genutzt vom Gesundheitscheck-Cron. Der
     * Versand läuft über einen eigenen SMTP-Client ({@see \HugoCMS\FileManager\Mailer});
     * ohne vollständige Angabe (host/from/to) ist er aus (configured=false).
     * Das Passwort ist ein Geheimnis und bleibt serverseitig.
     *
     * security: 'tls' (STARTTLS) | 'ssl' (implizit) | 'none'. Der Port fällt bei
     * fehlender Angabe je nach Sicherheit auf 465 (ssl) bzw. 587 (sonst) zurück.
     *
     * @return array{configured: bool, host: ?string, port: int, security: string, user: ?string, pass: ?string, from: ?string, to: ?string}
     */
    private static function mailSection(mixed $section): array
    {
        $section = is_array($section) ? $section : [];
        $host = trim((string) ($section['smtp_host'] ?? ''));
        // parse_ini_file(INI_SCANNER_TYPED) wandelt das INI-Schlüsselwort `none`
        // (wie off/no/false) in bool(false) bzw. "" um. Ein solcher Wert meint
        // ausdrücklich unverschlüsselten Versand — nicht den TLS-Standard, der
        // nur bei ganz fehlendem Schlüssel greift.
        if (!array_key_exists('smtp_security', $section)) {
            $security = 'tls';
        } else {
            $value = $section['smtp_security'];
            $security = ($value === false || $value === '' || $value === null)
                ? 'none'
                : strtolower(trim((string) $value));
            if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
                $security = 'tls';
            }
        }
        $port = (int) ($section['smtp_port'] ?? 0);
        if ($port <= 0) {
            $port = $security === 'ssl' ? 465 : 587;
        }
        $user = trim((string) ($section['smtp_user'] ?? ''));
        $pass = (string) ($section['smtp_pass'] ?? '');
        $from = trim((string) ($section['from'] ?? ''));
        $to = trim((string) ($section['to'] ?? ''));

        return [
            'configured' => $host !== '' && $from !== '' && $to !== '',
            'host' => $host === '' ? null : $host,
            'port' => $port,
            'security' => $security,
            'user' => $user === '' ? null : $user,
            'pass' => $pass === '' ? null : $pass,
            'from' => $from === '' ? null : $from,
            'to' => $to === '' ? null : $to,
        ];
    }

    /**
     * Globale, für alle Benutzer geltende Einstellungen ([user]-Sektion).
     * Solange driver=singleuser verwendet wird, ist die hugocms.ini der einzige
     * Speicherort für solche Einstellungen.
     *
     * session_lifetime: Sitzungsdauer in STUNDEN — so lange bleibt eine
     * Anmeldung bei Inaktivität gültig. Fehlt der Wert oder ist er ungültig
     * (≤ 0), gilt der Standard von 8 Stunden.
     *
     * content_width: Breite des zentrierten Hauptfensters in PIXELN auf großen
     * Bildschirmen. Fehlt der Wert oder ist er zu klein, gilt der Standard 1200.
     * Im Einzelbenutzer-Modus ist dies der Startwert nach jedem Neuladen.
     *
     * @return array{sessionLifetime: int, contentWidth: int, updateLastmod: ?bool}  sessionLifetime in Sekunden
     */
    private static function userSection(mixed $section): array
    {
        $section = is_array($section) ? $section : [];
        $hours = isset($section['session_lifetime']) ? (float) $section['session_lifetime'] : 0.0;
        $seconds = $hours > 0 ? (int) round($hours * 3600) : self::DEFAULT_SESSION_LIFETIME;

        $width = isset($section['content_width']) ? (int) $section['content_width'] : 0;
        if ($width < self::MIN_CONTENT_WIDTH) {
            $width = self::DEFAULT_CONTENT_WIDTH;
        }

        // update_lastmod ist dreiwertig: fehlt der Schlüssel (null), fragt der
        // Editor beim Speichern nach; true/false wenden das ohne Nachfrage an.
        $updateLastmod = isset($section['update_lastmod'])
            ? filter_var($section['update_lastmod'], FILTER_VALIDATE_BOOLEAN)
            : null;

        return ['sessionLifetime' => $seconds, 'contentWidth' => $width, 'updateLastmod' => $updateLastmod];
    }

    /**
     * KI-Assistent (optional). Ohne API-Schlüssel ist der Assistent aus.
     * writeMode: 'readonly' (nur lesen) | 'confirm' (vor dem Schreiben
     * bestätigen) | 'auto' (Schreibaktionen direkt ausführen).
     *
     * Drei Aufgaben können getrennte Modelle nutzen: der interaktive Assistent
     * (`model`), der Cron-Verbesserer (`model_cron`) und die
     * Content-Qualitätsprüfung (`model_audit`). `model_cron`/`model_audit`
     * fallen bei leerem Wert auf `model` zurück, `model` auf claude-opus-4-8 —
     * so laufen bestehende Installationen mit nur `model` unverändert weiter.
     *
     * @return array{apiKey: ?string, model: string, modelCron: string, modelAudit: string, writeMode: string}
     */
    private static function aiSection(mixed $section): array
    {
        $section = is_array($section) ? $section : [];
        $apiKey = trim((string) ($section['api_key'] ?? ''));
        $model = trim((string) ($section['model'] ?? '')) ?: 'claude-opus-4-8';
        $modelCron = trim((string) ($section['model_cron'] ?? '')) ?: $model;
        $modelAudit = trim((string) ($section['model_audit'] ?? '')) ?: $model;
        $writeMode = strtolower(trim((string) ($section['write_mode'] ?? 'confirm')));
        if (!in_array($writeMode, ['readonly', 'confirm', 'auto'], true)) {
            $writeMode = 'confirm';
        }

        return [
            'apiKey' => $apiKey === '' ? null : $apiKey,
            'model' => $model,
            'modelCron' => $modelCron,
            'modelAudit' => $modelAudit,
            'writeMode' => $writeMode,
        ];
    }

    /**
     * Externe Pro-Dienste (optional) aus der [services]-Sektion:
     *   speech_key         API-Schlüssel des Transkriptionsdienstes (Geheimnis).
     *   speech_url         Voller Endpunkt, z. B. https://…/v1/transcribe.
     *                      Fehlt eines von beiden, ist die Spracheingabe aus.
     *   pagespeed_key      Google-API-Schlüssel für PageSpeed Insights (Geheimnis,
     *                      OPTIONAL — ohne Schlüssel gilt ein kleineres Kontingent).
     *   pagespeed_base_url Zu prüfende öffentliche Live-Adresse der Webseite.
     *                      Ohne sie ist der PageSpeed-Check aus (nichts zu messen).
     *
     * @return array{speechKey: ?string, speechUrl: ?string, pagespeedKey: ?string, pagespeedBaseUrl: ?string}
     */
    private static function servicesSection(mixed $section): array
    {
        $section = is_array($section) ? $section : [];
        $speechKey = trim((string) ($section['speech_key'] ?? ''));
        $speechUrl = trim((string) ($section['speech_url'] ?? ''));
        $pagespeedKey = trim((string) ($section['pagespeed_key'] ?? ''));
        $pagespeedBaseUrl = trim((string) ($section['pagespeed_base_url'] ?? ''));

        return [
            'speechKey' => $speechKey === '' ? null : $speechKey,
            'speechUrl' => $speechUrl === '' ? null : $speechUrl,
            'pagespeedKey' => $pagespeedKey === '' ? null : $pagespeedKey,
            'pagespeedBaseUrl' => $pagespeedBaseUrl === '' ? null : $pagespeedBaseUrl,
        ];
    }

    /**
     * Liefert die rohen (NICHT pfad-aufgelösten) Werte der hugocms.ini, je
     * Sektion als Schlüssel/Wert-Array von Strings. Für das Vorbefüllen von
     * Formularen und das Erhalten unveränderter Werte. Fehlt die Datei, wird
     * ein leeres Array geliefert.
     *
     * @return array<string, array<string, string>>
     */
    public static function raw(string $configPath): array
    {
        if (!is_file($configPath)) {
            return [];
        }
        // RAW: keine Typumwandlung (username "on"/"123" bleibt String) und keine
        // $-Interpolation — wichtig für den bcrypt-Hash.
        $raw = @parse_ini_file($configPath, true, INI_SCANNER_RAW);
        if (!is_array($raw)) {
            throw new ApiException('ECONFIG', 500, 'CONFIG-INVALID-INI', [$configPath]);
        }
        $out = [];
        foreach ($raw as $section => $values) {
            if (!is_array($values)) {
                continue;
            }
            $clean = [];
            foreach ($values as $key => $value) {
                // RAW behält umschließende Anführungszeichen — entfernen.
                $clean[(string) $key] = self::unquote((string) $value);
            }
            $out[(string) $section] = $clean;
        }

        return $out;
    }

    /**
     * Schreibt die hugocms.ini atomar. Die in $changes genannten Sektionen
     * werden neu serialisiert (Werte gequotet); Wert null entfernt die Sektion.
     * Nicht genannte Sektionen bleiben WÖRTLICH erhalten (inkl. Kommentaren,
     * Quoting des bcrypt-Hashes). Existiert die Datei nicht, wird sie mit dem
     * optionalen Kopf-Kommentar und in der Schlüssel-Reihenfolge von $changes
     * angelegt.
     *
     * @param array<string, array<string, string>|null> $changes
     */
    public static function updateSections(string $configPath, array $changes, ?string $header = null): void
    {
        $existing = is_file($configPath) ? (string) @file_get_contents($configPath) : '';

        // Sektionsreihenfolge: vorhandene zuerst (Position bleibt), neue ans Ende.
        $order = [];
        foreach (preg_split('/\r\n|\r|\n/', $existing) ?: [] as $line) {
            if (preg_match('/^\s*\[(.+?)\]\s*$/', $line, $m) === 1) {
                $order[] = strtolower(trim($m[1]));
            }
        }
        foreach (array_keys($changes) as $name) {
            if (!in_array($name, $order, true)) {
                $order[] = $name;
            }
        }

        $blocks = [];
        foreach ($order as $name) {
            if (array_key_exists($name, $changes)) {
                if ($changes[$name] === null) {
                    continue; // Sektion entfernen
                }
                $blocks[] = self::serializeSection($name, $changes[$name]);
            } else {
                $block = self::extractSection($existing, $name);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        $head = self::extractHeader($existing) ?? $header;
        $content = ($head !== null && trim($head) !== '' ? rtrim($head) . "\n\n" : '')
            . implode("\n\n", $blocks) . "\n";

        self::atomicWrite($configPath, $content);
    }

    /** Serialisiert eine Sektion; alle Werte werden in "..." gesetzt. */
    private static function serializeSection(string $name, array $values): string
    {
        $lines = ['[' . $name . ']'];
        foreach ($values as $key => $value) {
            $value = (string) $value;
            // Doppelte Anführungszeichen würden die INI sprengen — der bcrypt-
            // Hash und validierte Eingaben enthalten keine; defensiv absichern.
            if (str_contains($value, '"') || preg_match('/[\r\n]/', $value) === 1) {
                throw new ApiException('ECONFIG', 500, 'CONFIG-WRITE-FAILED');
            }
            $lines[] = $key . ' = "' . $value . '"';
        }

        return implode("\n", $lines);
    }

    /**
     * Extrahiert eine Sektion [name] samt Inhalt WÖRTLICH (von der
     * Sektionszeile bis zur nächsten Sektion bzw. Dateiende, ohne
     * abschließende Leerzeilen). null, wenn die Sektion fehlt.
     *
     * Der Abgleich ist ohne Belang der Groß-/Kleinschreibung ($name ist klein),
     * die Kopfzeile wird aber ORIGINALGETREU übernommen — so behält ein
     * Mount-Name wie [Inhalte] seine Schreibweise (und damit seine ID), auch
     * wenn eine andere Sektion derselben Datei geschrieben wird.
     */
    private static function extractSection(string $ini, string $name): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $ini) ?: [];
        $out = [];
        $inSection = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[(.+?)\]\s*$/', $line, $m) === 1) {
                $isTarget = strtolower(trim($m[1])) === $name;
                if ($inSection && !$isTarget) {
                    break;
                }
                $inSection = $isTarget;
                if ($inSection) {
                    // Originalschreibweise des Sektionsnamens erhalten.
                    $out[] = '[' . trim($m[1]) . ']';
                }
                continue;
            }
            if ($inSection) {
                $out[] = $line;
            }
        }
        if ($out === []) {
            return null;
        }
        while ($out !== [] && trim((string) end($out)) === '') {
            array_pop($out);
        }

        return implode("\n", $out);
    }

    /** Liefert den Kopf-Kommentar (Zeilen vor der ersten [Sektion]) oder null. */
    private static function extractHeader(string $ini): ?string
    {
        $head = [];
        foreach (preg_split('/\r\n|\r|\n/', $ini) ?: [] as $line) {
            if (preg_match('/^\s*\[(.+?)\]\s*$/', $line) === 1) {
                break;
            }
            $head[] = $line;
        }
        while ($head !== [] && trim((string) end($head)) === '') {
            array_pop($head);
        }

        return $head === [] ? null : implode("\n", $head);
    }

    /** Entfernt ein einzelnes Paar umschließender Anführungszeichen. */
    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /** Schreibt Inhalt atomar (temporäre Datei + rename), Rechte 0640. */
    private static function atomicWrite(string $configPath, string $content): void
    {
        $tmp = @tempnam(dirname($configPath), '.hugocfg');
        if ($tmp === false || @file_put_contents($tmp, $content) === false || !@rename($tmp, $configPath)) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new ApiException('EIO', 500, 'CONFIG-WRITE-FAILED');
        }
        @chmod($configPath, 0640);
    }

    /** Prüft, ob ein Wert fehlt oder nach dem Trimmen leer ist. */
    private static function isBlank(mixed $value): bool
    {
        return trim((string) ($value ?? '')) === '';
    }

    /** Trimmt einen (geprüft nicht leeren) Pfad und löst ihn relativ zum baseDir auf. */
    private static function resolvePath(mixed $value, string $baseDir): string
    {
        $path = trim((string) $value);
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }
}
