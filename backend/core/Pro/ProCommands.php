<?php
/*
 * HugoCMS Pro — dieser Bestandteil steht NICHT unter der AGPL.
 * Nutzung nur mit gültigem Pro-Schlüssel gemäß LICENSE-PRO im Wurzelverzeichnis.
 * SPDX-License-Identifier: LicenseRef-HugoCMS-Pro
 */

declare(strict_types=1);

namespace HugoCMS\FileManager\Pro;
use HugoCMS\FileManager\AnthropicClient;
use HugoCMS\FileManager\AssistantService;
use HugoCMS\FileManager\Auth\UserAdminInterface;
use HugoCMS\FileManager\Config;
use HugoCMS\FileManager\Connector;
use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\FileService;
use HugoCMS\FileManager\HelpService;
use HugoCMS\FileManager\Mount;
use HugoCMS\FileManager\MountConfig;
use HugoCMS\FileManager\Pro\Audit\AuditMailReport;
use HugoCMS\FileManager\Pro\Audit\AuditService;
use HugoCMS\FileManager\Pro\Audit\ContentQualityService;
use HugoCMS\FileManager\Pro\Audit\RuleCatalog;
use HugoCMS\FileManager\Review\ReviewStore;
use HugoCMS\FileManager\Setup;
use HugoCMS\FileManager\SiteKey;
use Throwable;

/**
 * Pro-Befehle des Connectors — alles, was einen gültigen Pro-Schlüssel
 * voraussetzt: SEO-Check, Content-Qualität samt KI-Verbesserung, PageSpeed,
 * Live-Analyse, Sprachausgabe, Git-Versionierung mit Änderungsprotokoll und
 * die Kontenverwaltung des Mehrbenutzer-Verfahrens.
 *
 * Als Trait umgesetzt, damit die Methoden auf den Zustand des Connectors
 * zugreifen können (Mounts, Lizenz, Konfiguration, Hilfsmethoden), die Datei
 * aber eine eigene Lizenzgrenze bildet: Der Kern in Connector.php steht unter
 * der AGPL, dieses Trait unter der Pro-Lizenz. Connector::run() verteilt die
 * Befehle; {@see requirePro()} ist die Schranke jedes schreibenden oder
 * kostenpflichtigen Pro-Befehls. Die Prüfung selbst ({@see \HugoCMS\FileManager\License})
 * bleibt im Kern.
 */
trait ProCommands
{
    /**
     * Wie weit die automatische Terminierung nach einem freien Platz sucht.
     * Bei kleiner Tagesmenge und großem Rückstau wandern Freigaben weit nach
     * vorn; irgendwo muss die Suche enden, sonst liefe sie bei erschöpftem
     * Kontingent endlos.
     */
    private const int AUTO_SCHEDULE_HORIZON_DAYS = 90;

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
            true, // veröffentlichter Stand → Versionsnummer
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
    private function runAutoCommit(
        string $message,
        string $fallback,
        bool $skipWhenClean = true,
        bool $withTag = false,
    ): ?array
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
            // Versionsnummer nur für den Stand, der veröffentlicht wird: Die
            // Vorab-Sicherung offener Änderungen ist eine Zwischenablage, kein
            // Ausgabestand — bekäme sie eine Nummer, verlöre die Zählung ihre
            // Aussage. Das Wort davor steht in der Mount-Konfiguration
            // ([git] tag_label), weil beim Cron kein Client die Sprache kennt.
            $tag = $withTag ? $git->nextTag() : null;
            $res = $git->commit(
                $message,
                $tag,
                $this->changelogHook(false, (string) $this->gitAuto['tagLabel']),
            );
        } catch (Throwable $e) {
            // Etwa GIT-NOT-A-REPO: kein Repository → nichts zu committen.
            $this->logger->info('Auto-Commit übersprungen: ' . $e->getMessage());

            return null;
        }

        if (!empty($res['success'])) {
            $this->logger->info(sprintf(
                'Auto-Commit %s: %s%s',
                substr((string) ($res['sha'] ?? ''), 0, 7),
                $message,
                !empty($res['tagged']) ? ' (Versionsnummer ' . $res['tag'] . ')' : '',
            ));
            // Der Commit steht, nur die Nummer kam nicht zustande — eigener
            // Eintrag, damit die Lücke in der Zählung nicht unbemerkt bleibt.
            if ($tag !== null && empty($res['tagged'])) {
                $this->logger->warning('Versionsnummer nicht vergeben: ' . trim((string) ($res['tagOutput'] ?? '')));
            }
        } else {
            // Häufigster Fall ohne Fehler: „nichts zu committen“. Als Warnung mit
            // Git-Ausgabe, damit ein echtes Problem (fehlende git-Identität)
            // sichtbar wird.
            $this->logger->warning('Auto-Commit nicht angelegt: ' . trim((string) ($res['output'] ?? '')));
        }

        return ['success' => (bool) ($res['success'] ?? false), 'sha' => $res['sha'] ?? null];
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
            return "Improve the existing content file `{$path}`. Steps: (1) call get_file_report for this path and act on BOTH parts — the content-quality verdict AND the SEO findings; (2) read the file; (3) fix the reported issues — prefer replace_in_file per section, and use a SINGLE write_file call only when you rework the page as a whole. If the report contains a non-empty `userInstruction` field, it is an explicit instruction from the site owner and OVERRIDES conflicting findings or suggestions — follow it exactly. Adopt the file's existing front-matter format.\n\n"
                . "The SEO findings are NOT optional — work through `audit.issues` completely:\n"
                . "- Fix EVERY finding marked `fixable: true`. These are fixable from this content file alone.\n"
                . "- `audit.rules` explains what each rule requires — consult it instead of guessing from the rule ID.\n"
                . "- A finding with `duplicateOf` means the SAME title or meta description is used on the pages listed there. Write a text that clearly distinguishes THIS page from them; do not reuse their wording.\n"
                . "- Findings WITHOUT `fixable` live in the theme or in the URL structure. Do not attempt them and do not invent front-matter fields for them — name them briefly in your reply instead.\n"
                . "- If you deliberately leave a fixable finding unfixed, say which one and why.\n\n"
                . "If a SEO finding depends on which front-matter field the theme reads (e.g. og:image), you MAY READ the relevant layout/partial to find the correct field — but WRITE only this content file. Keep the front matter valid and preserve the author's meaning.";
        }

        return "Verbessere die bestehende Content-Datei `{$path}`. Vorgehen: (1) rufe get_file_report für diesen Pfad auf und beachte BEIDE Teile — das Qualitätsurteil UND die SEO-Funde; (2) lies die Datei; (3) behebe die gemeldeten Probleme — bevorzugt je Abschnitt mit replace_in_file; EINEN write_file-Aufruf nur, wenn du die Seite als Ganzes überarbeitest. Enthält der Bericht ein nicht leeres Feld `userInstruction`, ist das eine ausdrückliche Anweisung des Betreibers und hat VORRANG vor widersprechenden Funden oder Vorschlägen — befolge sie genau. Übernimm das vorhandene Front-Matter-Format der Datei.\n\n"
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
     * bearbeiten — der Knopf sitzt an der Fundzeile des Berichts.
     *
     * Zwei Betriebsarten (`mode`):
     * - `fix` (Vorgabe): behebt den Fund in der Content-Datei. Nur für Regeln
     *   aus {@see RuleCatalog::FIXABLE}.
     * - `diagnose`: erklärt, wodurch der Fund entsteht und was wo zu ändern ist
     *   — für Regeln, die im Theme, in der Hugo-Konfiguration oder in der
     *   Seitenstruktur wurzeln. Läuft im Nur-Lese-Modus und schreibt nichts.
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
        // "fix" behebt in der Content-Datei, "diagnose" erklärt nur (Theme,
        // Hugo-Konfiguration, Seitenstruktur) und schreibt nichts.
        $diagnose = (string) ($request['mode'] ?? 'fix') === 'diagnose';

        if ($diagnose && !RuleCatalog::diagnosable($ruleId)) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-RULE-NOT-DIAGNOSABLE', [$ruleId]);
        }
        if (!$diagnose && !RuleCatalog::fixable($ruleId)) {
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
        // Behebung braucht die Datei; die Diagnose kommt auch ohne sie aus
        // (fehlende Sitemap, robots.txt — Funde ganz ohne Seite).
        if ($fileId === null && !$diagnose) {
            throw ApiException::notFound('AUDIT-ISSUE-NO-SOURCE', [$ruleId]);
        }
        $path = null;
        if ($fileId !== null) {
            $r = $this->resolver->resolve($fileId, true);
            $path = $r['mount']->name() . '/' . $r['rel'];
        }

        // Wie beim Verbesserungslauf großzügig bemessen (Lesen, Nachdenken,
        // Schreiben in einem Request), aber ohne Hintergrundprozess. Die
        // Diagnose sucht sich durch das Projekt und braucht das ebenso.
        @set_time_limit(180);

        $instruction = $diagnose
            ? $this->diagnoseInstruction($path, $issue, $report, $locale)
            : $this->fixInstruction((string) $path, $issue, $report, $locale);

        // Der Nur-Lese-Modus ist die harte Zusage der Diagnose: In dieser
        // Betriebsart bietet der Assistent gar keine Schreibwerkzeuge an.
        return $this->assistantService($diagnose ? 'readonly' : null)->run(
            [['role' => 'user', 'content' => $instruction]],
            null,
            $locale,
            $path,
        );
    }

    /**
     * assistantfixmany — behebt MEHRERE Funde EINER Content-Datei in einem
     * einzigen Auftrag.
     *
     * Der Zuschnitt „eine Datei je Auftrag" ist kein Zufall: Das Modell liest
     * die Datei dann einmal statt für jeden Fund erneut, sieht alle Mängel
     * zusammen (ein Titel, der die Description ergänzt, statt zweier isolierter
     * Umformulierungen) und braucht einen Bruchteil der Läufe. Über mehrere
     * Dateien hinweg arbeitet der Client die Gruppen nacheinander ab — ein
     * Request je Datei, passend zum zustandslosen Backend.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function cmdAssistantFixMany(array $request): array
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
        $wanted = $request['issues'] ?? null;
        if (!is_array($wanted) || $wanted === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['issues']);
        }
        if (count($wanted) > self::FIX_MANY_MAX) {
            throw new ApiException('ECONFIG', 409, 'AUDIT-FIX-TOO-MANY', [self::FIX_MANY_MAX]);
        }
        $locale = (string) ($request['locale'] ?? 'de');

        $report = $this->auditStore()->get($runId);
        $issues = [];
        $sourceFile = null;
        foreach ($wanted as $ref) {
            if (!is_array($ref)) {
                throw ApiException::badRequest('PARAM-MISSING', ['issues']);
            }
            $ruleId = (string) ($ref['ruleId'] ?? '');
            $url = isset($ref['url']) ? (string) $ref['url'] : null;
            if ($ruleId === '') {
                throw ApiException::badRequest('PARAM-MISSING', ['ruleId']);
            }
            if (!RuleCatalog::fixable($ruleId)) {
                throw new ApiException('ECONFIG', 409, 'AUDIT-RULE-NOT-FIXABLE', [$ruleId]);
            }
            $issue = self::findIssue($report, $ruleId, $url);
            if ($issue === null) {
                throw ApiException::notFound('AUDIT-ISSUE-NOT-FOUND', [$ruleId]);
            }
            $rel = (string) ($issue['sourceFile'] ?? '');
            if ($rel === '') {
                throw ApiException::notFound('AUDIT-ISSUE-NO-SOURCE', [$ruleId]);
            }
            // Ein Auftrag, eine Datei: Der Client gruppiert vorher danach. Käme
            // hier ein Gemisch an, würde das Modell in einem Auftrag durch
            // mehrere Dateien schreiben — genau das, was der enge Zuschnitt
            // verhindern soll.
            $sourceFile ??= $rel;
            if ($rel !== $sourceFile) {
                throw ApiException::badRequest('AUDIT-ISSUES-MIXED-SOURCE', [$rel, $sourceFile]);
            }
            $issues[] = $issue;
        }

        $fileId = $this->resolveFileId((string) $this->hugo['source'] . '/' . (string) $sourceFile);
        if ($fileId === null) {
            throw ApiException::notFound('AUDIT-ISSUE-NO-SOURCE', [(string) $sourceFile]);
        }
        $r = $this->resolver->resolve($fileId, true);
        $path = $r['mount']->name() . '/' . $r['rel'];

        // Mehr Funde in einem Lauf heißt mehr Werkzeugaufrufe. Großzügiger als
        // der Einzelauftrag bemessen, aber weiterhin ein Request → eine Antwort.
        @set_time_limit(300);

        // Hat der Benutzer die Bestätigungen für diese Reihe vorab erteilt,
        // gilt das auch für die folgenden Dateien — sonst fragte der Sammellauf
        // bei jeder neuen Datei erneut.
        return $this->assistantService(null, null, 'ai', null, null, !empty($request['autoConfirm']))->run(
            [['role' => 'user', 'content' => $this->fixManyInstruction($path, $issues, $report, $locale)]],
            null,
            $locale,
            $path,
        );
    }

    /**
     * Startanweisung für den gebündelten Auftrag. Wie {@see fixInstruction},
     * aber mit einer nummerierten Liste der Funde und dem Regelwissen EINMAL je
     * vorkommender Regel statt je Fund — sonst stünde dieselbe Erklärung bei
     * fünf Funden fünfmal in der Anweisung.
     *
     * @param list<array<string, mixed>> $issues
     * @param array<string, mixed>       $report
     */
    private function fixManyInstruction(string $path, array $issues, array $report, string $locale): string
    {
        $en = str_starts_with(strtolower($locale), 'en');
        $count = count($issues);

        $lines = [];
        $lines[] = $en
            ? "Fix the following {$count} reported SEO issues in the content file `{$path}` — change nothing else."
            : "Behebe in der Content-Datei `{$path}` die folgenden {$count} gemeldeten SEO-Funde — ändere sonst nichts.";
        $lines[] = '';

        $n = 0;
        $seenRules = [];
        foreach ($issues as $issue) {
            $ruleId = (string) ($issue['ruleId'] ?? '');
            $url = (string) ($issue['url'] ?? '');
            $lines[] = ++$n . '. ' . ($en ? 'Rule: ' : 'Regel: ') . $ruleId
                . ($url !== '' ? ($en ? ' — affected page: ' : ' — betroffene Seite: ') . $url : '');
            $seenRules[$ruleId] = true;

            // Duplikat-Funde: die übrigen betroffenen Seiten. Ohne sie schreibt
            // das Modell erneut einen austauschbaren Text.
            $others = self::duplicateSiblings($report, $issue);
            if ($others !== []) {
                $lines[] = '   ' . ($en
                    ? 'The SAME text is used on these other pages — your new text must clearly distinguish this page from them:'
                    : 'Derselbe Text steht auf diesen weiteren Seiten — dein neuer Text muss diese Seite deutlich von ihnen unterscheiden:');
                foreach ($others as $o) {
                    $lines[] = '   - ' . $o;
                }
            }
        }

        foreach (array_keys($seenRules) as $ruleId) {
            $help = $this->ruleHelp($ruleId, $locale);
            if ($help === null) {
                continue;
            }
            $lines[] = '';
            $lines[] = ($en ? '--- What the rule means: ' : '--- Was die Regel bedeutet: ') . $ruleId . ' ---';
            $lines[] = $help;
        }

        $lines[] = '';
        $lines[] = $en
            ? "Steps: (1) read `{$path}` ONCE; (2) fix ALL listed issues together — prefer replace_in_file per affected section, use write_file only if the change really spans the whole file. Keep the existing front-matter format valid, preserve the author's meaning, and leave everything untouched that none of the listed rules concerns. If an issue's cause is not in this file but in the theme, do NOT write for that one — name it at the end and explain what has to change in which layout."
            : "Vorgehen: (1) lies `{$path}` EINMAL; (2) behebe ALLE aufgeführten Funde gemeinsam — bevorzugt mit replace_in_file je betroffenem Abschnitt, write_file nur, wenn die Änderung wirklich die ganze Datei betrifft. Halte das vorhandene Front-Matter-Format gültig, bewahre die Aussage des Autors und lass alles unangetastet, was keine der aufgeführten Regeln betrifft. Liegt die Ursache eines Funds nicht in dieser Datei, sondern im Theme, schreibe dafür NICHT — nenne ihn am Ende und erkläre, was in welchem Layout zu ändern ist.";

        return implode("\n", $lines);
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
            ? "Steps: (1) read `{$path}`; (2) fix this ONE issue with a replace_in_file call on the affected section — use write_file only if the change really spans the whole file. Keep the existing front-matter format valid, preserve the author's meaning, and leave everything unrelated to this rule untouched. If the cause is not in this file but in the theme, do NOT write — explain what has to change in which layout instead."
            : "Vorgehen: (1) lies `{$path}`; (2) behebe DIESEN einen Fund mit einem replace_in_file-Aufruf auf den betroffenen Abschnitt — write_file nur, wenn die Änderung wirklich die ganze Datei betrifft. Halte das vorhandene Front-Matter-Format gültig, bewahre die Aussage des Autors und lass alles unangetastet, was mit dieser Regel nichts zu tun hat. Liegt die Ursache nicht in dieser Datei, sondern im Theme, schreibe NICHT — erkläre stattdessen, was in welchem Layout zu ändern ist.";

        return implode("\n", $lines);
    }

    /**
     * Startanweisung für die Diagnose eines Funds, der sich NICHT über die
     * Content-Datei beheben lässt. Der Assistent läuft dabei im Nur-Lese-Modus:
     * Er darf sich durch das Hugo-Projekt lesen (die erzeugten Mounts enthalten
     * das Projektverzeichnis samt Konfiguration und `layouts/`), aber nichts
     * ändern. Ergebnis ist eine Anleitung für den Betreiber.
     *
     * Wichtig ist die Reichweite: Betrifft dieselbe Regel viele Seiten, liegt
     * die Ursache fast sicher in einem Template und nicht an einer einzelnen
     * Seite. Diese Zahl steht deshalb ausdrücklich in der Anweisung.
     *
     * @param array<string, mixed> $issue
     * @param array<string, mixed> $report
     */
    private function diagnoseInstruction(?string $path, array $issue, array $report, string $locale): string
    {
        $en = str_starts_with(strtolower($locale), 'en');
        $ruleId = (string) ($issue['ruleId'] ?? '');
        $url = (string) ($issue['url'] ?? '');
        [$count, $examples] = self::ruleScope($report, $ruleId, $url);

        $lines = [];
        $lines[] = $en
            ? 'Diagnose ONE finding from the SEO report. You cannot write in this mode — deliver an explanation, not a change.'
            : 'Stelle eine Diagnose zu EINEM Fund des SEO-Berichts. Du kannst in dieser Betriebsart nicht schreiben — liefere eine Erklärung, keine Änderung.';
        $lines[] = '';
        $lines[] = ($en ? 'Rule: ' : 'Regel: ') . $ruleId;
        if ($url !== '') {
            $lines[] = ($en ? 'Affected page: ' : 'Betroffene Seite: ') . $url;
        }
        if ($path !== null) {
            $lines[] = ($en ? 'Content file of that page: ' : 'Content-Datei dieser Seite: ') . '`' . $path . '`';
        }
        $lines[] = $en
            ? "Pages affected by this rule in the same run: {$count}."
            : "Von dieser Regel betroffene Seiten im selben Lauf: {$count}.";
        if ($examples !== []) {
            $lines[] = ($en ? 'Examples: ' : 'Beispiele: ') . implode(', ', $examples);
        }

        $help = $this->ruleHelp($ruleId, $locale);
        if ($help !== null) {
            $lines[] = '';
            $lines[] = $en ? '--- What the rule means ---' : '--- Was die Regel bedeutet ---';
            $lines[] = $help;
        }

        $lines[] = '';
        $lines[] = $en
            ? 'This finding cannot be fixed from a content file — its cause lies in the theme, in the Hugo configuration or in the site structure. The Hugo project is reachable through the mounts (the project directory with its configuration, `layouts/` with templates and partials, possibly a themes/ directory). Use list_dir and read_file to find the responsible place.'
            : 'Dieser Fund lässt sich nicht über eine Content-Datei beheben — seine Ursache liegt im Theme, in der Hugo-Konfiguration oder in der Seitenstruktur. Das Hugo-Projekt ist über die Mounts erreichbar (Projektverzeichnis samt Konfiguration, `layouts/` mit Templates und Partials, gegebenenfalls ein themes/-Verzeichnis). Nutze list_dir und read_file, um die zuständige Stelle zu finden.';
        $lines[] = '';
        $lines[] = $en
            ? "Answer in four short parts: (1) CAUSE — what produces this finding; (2) LOCATION — the exact file and, if possible, the line or block (name the path as `<mount>/<path>`); (3) CHANGE — the concrete edit, with a short code snippet where it helps; (4) SCOPE — whether this fixes all affected pages at once and whether the file belongs to a theme (then say that overriding it in `layouts/` is the update-safe route). If you cannot locate the cause, say what you looked at and what is missing — do not guess."
            : 'Antworte in vier kurzen Teilen: (1) URSACHE — wodurch der Fund entsteht; (2) FUNDSTELLE — die genaue Datei und, wenn möglich, die Zeile oder der Block (nenne den Pfad als `<mount>/<pfad>`); (3) ÄNDERUNG — der konkrete Eingriff, mit kurzem Code-Ausschnitt, wo es hilft; (4) REICHWEITE — ob damit alle betroffenen Seiten auf einmal erledigt sind und ob die Datei zu einem Theme gehört (dann nenne das Überschreiben in `layouts/` als aktualisierungsfesten Weg). Findest du die Ursache nicht, sage, wo du gesucht hast und was fehlt — rate nicht.';

        return implode("\n", $lines);
    }

    /**
     * Reichweite einer Regel im Bericht: wie viele Funde sie insgesamt hat und
     * einige andere betroffene Seiten als Beispiel.
     *
     * @param array<string, mixed> $report
     * @return array{0: int, 1: list<string>}
     */
    private static function ruleScope(array $report, string $ruleId, string $exceptUrl): array
    {
        $count = 0;
        $examples = [];
        foreach ($report['issues'] ?? [] as $other) {
            if (!is_array($other) || ($other['ruleId'] ?? null) !== $ruleId) {
                continue;
            }
            $count++;
            $otherUrl = (string) ($other['url'] ?? '');
            if ($otherUrl !== '' && $otherUrl !== $exceptUrl && count($examples) < self::DIAGNOSE_EXAMPLES_MAX) {
                $examples[] = $otherUrl;
            }
        }

        return [$count, $examples];
    }

    /** Höchstzahl der genannten Beispielseiten in einer Diagnose. */
    private const int DIAGNOSE_EXAMPLES_MAX = 5;

    /** Höchstlänge des Regelwissens in der Anweisung (Zeichen). */
    private const int FIX_HELP_MAX = 1500;

    /** Höchstzahl der genannten Schwester-Seiten einer Duplikat-Gruppe. */
    private const int FIX_SIBLINGS_MAX = 12;

    /**
     * Höchstzahl der Funde in EINEM gebündelten Auftrag ({@see cmdAssistantFixMany}).
     * Die Grenze schützt zwei Enden: die Anweisung bliebe sonst irgendwann
     * länger als der Text, den sie ändern soll, und der Lauf müsste in einem
     * Request mehr Werkzeugaufrufe unterbringen, als das Zeitlimit hergibt.
     */
    private const int FIX_MANY_MAX = 25;

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

    /** Bildendungen, die Hochladen und Bild-Editor verarbeiten (Vorschläge der Benutzerverwaltung). */
    private const IMAGE_TYPES = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * Grenzen für eine über den Konto-Dialog gesetzte Sitzungsdauer (Stunden):
     * eine Viertelstunde bis 30 Tage. Beim LESEN gelten sie nicht — ein von Hand
     * eingetragener Wert bleibt wirksam.
     */
    private const MIN_SESSION_LIFETIME_HOURS = 0.25;
    private const MAX_SESSION_LIFETIME_HOURS = 720;

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
            // Immer geschrieben, damit der Zustand in der Datei ablesbar bleibt
            // — die Vorgabe „an“ gilt nur, solange nichts dasteht.
            'changelog' => !empty($request['changelog']) ? 'true' : 'false',
            // Zielpfade des Protokolls. Leer = Vorgabe, damit ein geleertes Feld
            // nicht in einer Webseite ohne Protokollseite endet.
            'changelog_path' => self::changelogPathValue($request['changelogPath'] ?? ''),
            // Wort vor der Versionsnummer im Protokoll. Ausdrücklich leer heißt
            // „nur die Nummer“ — deshalb wird der Wert übernommen, wie er kommt,
            // und nicht auf die Vorgabe zurückgesetzt.
            'tag_label' => trim((string) ($request['tagLabel'] ?? '')),
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
            // Vorschläge für die Dateityp-Einschränkung: alles, was der Editor
            // öffnet, dazu die Bildformate (Hochladen, Bild-Editor).
            'fileTypes' => $this->availableFileTypes(),
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
            $this->requestFileTypes($request) ?? [],
            $this->requestBool($request, 'hiddenAllowed') ?? false,
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
            $this->requestFileTypes($request),
            $this->requestBool($request, 'hiddenAllowed'),
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

    /** Optionaler Wahrheitswert aus der Anfrage; null, wenn nicht genannt. */
    private function requestBool(array $request, string $key): ?bool
    {
        if (!array_key_exists($key, $request)) {
            return null;
        }
        if (!is_bool($request[$key])) {
            throw ApiException::badRequest('PARAM-INVALID', [$key]);
        }

        return $request[$key];
    }

    /**
     * Dateityp-Einschränkung aus der Anfrage: eine Liste von Endungen (leer =
     * keine Einschränkung), oder null, wenn die Anfrage sie nicht nennt.
     *
     * @return ?list<string>
     */
    private function requestFileTypes(array $request): ?array
    {
        if (!array_key_exists('fileTypes', $request)) {
            return null;
        }
        $types = $request['fileTypes'];
        if (!is_array($types)) {
            throw ApiException::badRequest('PARAM-INVALID', ['fileTypes']);
        }

        return array_values(array_map('strval', $types));
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

    /**
     * Der Haken, den {@see GitService::commit()} unmittelbar vor `git add -A`
     * ruft: Er schreibt den Abschnitt ins Änderungsprotokoll, damit dieser in
     * genau dem Versionsstand liegt, den er beschreibt.
     *
     * Das Schreiben gehört bewusst hierher und nicht in den GitService: Nur der
     * Connector kennt Mounts und FileService, und nur über diese Schicht greifen
     * Einsperrung, Schreibrechte und erlaubte Endungen.
     *
     * $pin ist für die Wiederherstellung: Dort setzt `read-tree` auch die
     * Protokollseite auf den alten Inhalt zurück. Wird ihr Stand vorher
     * festgehalten, überdauern die zwischenzeitlichen Einträge den Vorgang —
     * sonst verlöre das Protokoll genau die Geschichte, die es festhalten soll.
     */
    private function changelogHook(bool $pin = false, string $tagLabel = ''): ?callable
    {
        if (empty($this->gitAuto['changelog'])) {
            return null;
        }
        $changelog = new ChangelogService(
            $this->resolver,
            $this->files,
            $this->logger,
            $this->changelogPaths(),
        );
        if ($pin) {
            $changelog->pin();
        }

        return function (string $message, ?string $tag) use ($changelog, $tagLabel): void {
            $changelog->append($message, $tag, $tagLabel);
        };
    }

    /**
     * Erzeugt das Änderungsprotokoll (changelog.md) neu aus der Historie —
     * berücksichtigt werden ausschließlich Versionsstände MIT Versionsnummer.
     *
     * Geschrieben wird nur die Datei; sie erscheint danach als offene Änderung
     * und wird wie jede andere gesichert. Bewusst kein eigener Commit: Was in
     * den Versionsstand geht, entscheidet der Benutzer.
     */
    private function cmdGitChangelog(array $request): array
    {
        $this->requireMethod('POST');
        $states = $this->git()->taggedHistory();

        $changelog = new ChangelogService(
            $this->resolver,
            $this->files,
            $this->logger,
            $this->changelogPaths(),
        );
        $result = $changelog->rebuild($states, (string) ($request['tagLabel'] ?? ''));
        $this->logger->info(sprintf(
            'Änderungsprotokoll neu erzeugt: %d Abschnitte in %d Datei(en)',
            $result['sections'],
            $result['files'],
        ));

        return $result;
    }

    /**
     * Zielpfade des Änderungsprotokolls aus der Mount-Konfiguration. Eine
     * Bestandsinstallation ohne den Schlüssel bekommt die Vorgabe — dort ändert
     * sich nichts.
     *
     * @return list<string>
     */
    private function changelogPaths(): array
    {
        $paths = $this->gitAuto['changelogPaths'] ?? [];

        return $paths === [] ? [MountConfig::GIT_CHANGELOG_PATH_DEFAULT] : array_values($paths);
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
        // Leere Versionsnummer heißt „ohne Tag“ — das Formular darf das Feld
        // räumen, ohne dass der Commit daran scheitert.
        $tag = trim((string) ($request['tag'] ?? ''));
        $result = $git->commit(
            (string) ($request['message'] ?? ''),
            $tag === '' ? null : $tag,
            // tagLabel: sichtbarer Text im Änderungsprotokoll („Ausgabe 3“),
            // deshalb vom Client — wie die Beschreibung selbst.
            $this->changelogHook(false, (string) ($request['tagLabel'] ?? '')),
        );
        if ($result['success']) {
            $this->logger->info(sprintf(
                'Git-Commit erstellt: %s%s',
                $result['sha'] ?? '?',
                $result['tagged'] ? ' (Versionsnummer ' . $result['tag'] . ')' : '',
            ));
            // Der Commit steht, nur das Tag kam nicht zustande. Eigener Eintrag,
            // damit die fehlende Versionsnummer nicht unbemerkt bleibt.
            if ($tag !== '' && !$result['tagged']) {
                $this->logger->warning('Versionsnummer nicht vergeben: ' . trim($result['tagOutput']));
            }
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

    /** Vorschau: was eine Wiederherstellung dieses Standes ändern würde. */
    private function cmdGitRestorePreview(array $request): array
    {
        return $this->git()->restorePreview($this->requireParam($request, 'sha'));
    }

    /**
     * Kehrt zu einem alten Versionsstand zurück. Die Beschreibungen kommen vom
     * Client, nicht aus dem Backend: Sie sind sichtbarer Text und damit
     * sprachabhängig — hier gilt dieselbe Aufteilung wie bei `gitcommit`.
     */
    private function cmdGitRestore(array $request): array
    {
        $this->requireMethod('POST');
        $tag = trim((string) ($request['tag'] ?? ''));
        $result = $this->git()->restore(
            $this->requireParam($request, 'sha'),
            (string) ($request['message'] ?? ''),
            $tag === '' ? null : $tag,
            (string) ($request['presaveMessage'] ?? ''),
            // pin: Das Protokoll soll die Wiederherstellung überdauern.
            $this->changelogHook(true, (string) ($request['tagLabel'] ?? '')),
        );

        if ($result['success']) {
            $this->logger->info(sprintf(
                'Versionsstand %s wiederhergestellt als %s%s%s',
                substr((string) ($request['sha'] ?? ''), 0, 7),
                $result['sha'] ?? '?',
                $result['tagged'] ? ' (Versionsnummer ' . $result['tag'] . ')' : '',
                $result['presaved'] ? '; offene Änderungen zuvor gesichert' : '',
            ));
        } else {
            $this->logger->warning('Wiederherstellung fehlgeschlagen: ' . $result['output']);
        }

        return $result;
    }

    /** Holt EINE Datei aus einem alten Stand in den Arbeitsbaum (ohne Commit). */
    private function cmdGitRestoreFile(array $request): array
    {
        $this->requireMethod('POST');
        $result = $this->git()->restoreFile(
            $this->requireParam($request, 'sha'),
            $this->requireParam($request, 'path'),
        );

        if ($result['success']) {
            $this->logger->info(sprintf(
                'Datei %s aus Versionsstand %s zurückgeholt.',
                $result['path'],
                substr($result['sha'], 0, 7),
            ));
        } else {
            $this->logger->warning('Datei konnte nicht zurückgeholt werden: ' . $result['output']);
        }

        return $result;
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

    /**
     * auditignore — merkt Funde dauerhaft als ignoriert vor (oder nimmt die
     * Ignorierung zurück). Die Vormerkung gilt JE WEBSEITE, nicht je Lauf: Sie
     * überlebt neue Durchläufe und wirkt rückwirkend auch auf gespeicherte
     * Berichte ({@see AuditService::ignore}).
     *
     * Wird eine Lauf-ID mitgeschickt, kommt der neu gerechnete Bericht gleich
     * zurück — er ist nach der Änderung ohnehin fällig, und ein zweiter Abruf
     * kostete dasselbe noch einmal.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function cmdAuditIgnore(array $request): array
    {
        $service = $this->audit();
        $this->requireMethod('POST');

        $keys = $request['keys'] ?? null;
        if (!is_array($keys) || $keys === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['keys']);
        }
        $list = [];
        foreach ($keys as $key) {
            if (is_string($key)) {
                $list[] = $key;
            }
        }
        if ($list === []) {
            throw ApiException::badRequest('PARAM-MISSING', ['keys']);
        }
        // Fehlendes `ignored` bedeutet setzen — das ist der häufige Fall.
        $ignored = !isset($request['ignored']) || (bool) $request['ignored'];

        $result = $service->ignore($list, $ignored);
        $this->logger->info(sprintf(
            'SEO-Audit: %d Fund(e) %s (insgesamt %d ignoriert).',
            $result['changed'],
            $ignored ? 'ignoriert' : 'wieder aufgenommen',
            $result['ignoredTotal'],
        ));

        $runId = isset($request['runId']) ? (string) $request['runId'] : '';
        if ($runId !== '') {
            $result['report'] = $this->enrichReport($service->get($runId));
        }

        return $result;
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
                // Bewusst ignorierte Funde erreichen auch die Content-Prüfung
                // nicht — sonst arbeitet der Verbesserungslauf gegen eine
                // Entscheidung, die der Benutzer schon getroffen hat.
                if (!empty($issue['ignored'])) {
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
     * Dazu kommen zwei Angebote des KI-Assistenten, sofern er eingerichtet ist:
     * `fixable` — der Fund lässt sich über seine Content-Datei beheben
     * ({@see RuleCatalog::FIXABLE}) und diese Datei ist auflösbar — und
     * `diagnosable` — der Fund wurzelt im Theme, in der Hugo-Konfiguration oder
     * in der Seitenstruktur; dafür gibt es statt einer Behebung eine erklärende
     * Diagnose. Beide führen zu {@see cmdAssistantFix}. Die Diagnose braucht
     * weder eine Quelldatei noch Schreibrechte.
     *
     * Alle drei Angaben entstehen erst beim Ausliefern; der abgelegte Bericht
     * bleibt unangetastet, ältere Läufe bekommen sie dadurch automatisch.
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
        // Beheben heißt schreiben — im Nur-Lese-Modus gibt es das nicht. Die
        // Diagnose liest ausschließlich und genügt sich mit dem API-Schlüssel.
        $hasAi = $this->ai['apiKey'] !== null;
        $canFix = $hasAi && $this->ai['writeMode'] !== 'readonly';
        $cache = [];
        foreach ($report['issues'] as &$issue) {
            if (!is_array($issue)) {
                continue;
            }
            $ruleId = (string) ($issue['ruleId'] ?? '');
            // Diagnose zuerst: Sie hängt an keiner Quelldatei — auch Funde ohne
            // Seite (fehlende Sitemap, robots.txt) sind erklärbar.
            if ($hasAi && RuleCatalog::diagnosable($ruleId)) {
                $issue['diagnosable'] = true;
            }
            $rel = $issue['sourceFile'] ?? null;
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            if (!array_key_exists($rel, $cache)) {
                $cache[$rel] = $this->resolveFileId($source . '/' . $rel);
            }
            if ($cache[$rel] !== null) {
                $issue['fileId'] = $cache[$rel];
                if ($canFix && RuleCatalog::fixable($ruleId)) {
                    $issue['fixable'] = true;
                }
            }
        }
        unset($issue);

        return $report;
    }
}
