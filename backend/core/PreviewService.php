<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;
use Throwable;

/**
 * Vorschau EINER Content-Seite — auch mit noch nicht gespeichertem Text.
 *
 * Hugo kann keine einzelne Seite bauen; es liest immer das ganze Projekt. Seit
 * Hugo 0.124 lässt sich aber steuern, was davon GERENDERT wird („Segments"),
 * und über einen zusätzlichen Mount lässt sich eine einzelne Datei überlagern,
 * ohne das Projekt anzufassen. Beides zusammen ergibt die Vorschau:
 *
 *   1. `hugo list all` liefert die Zuordnung Quelldatei → fertige Adresse.
 *      Selbst zu raten wäre falsch: slug, url im Front Matter und die
 *      permalinks-Konfiguration bestimmen sie, samt Umlaut-Kodierung.
 *   2. Der zu zeigende Text landet in einem Arbeitsverzeichnis, das dem
 *      content-Ordner VORANGESTELLT wird — der erste Mount gewinnt, die echte
 *      Datei bleibt unberührt.
 *   3. Hugo baut mit diesem Overlay in ein temporäres Ziel und rendert dank
 *      Segment nur die eine Seite.
 *   4. Übrig bleibt eine HTML-Datei. Sie wird unter einem Einmal-Token
 *      abgelegt und beim Abruf gelöscht.
 *
 * Das Ergebnis liegt NIE im Web-Wurzelverzeichnis: Es gibt nichts, das ein
 * Crawler finden, das in der Sitemap landen oder das ein späterer Hugo-Lauf
 * mit --cleanDestinationDir wegräumen könnte. Ausgeliefert wird es nur an eine
 * angemeldete Sitzung (siehe Connector).
 *
 * Die Seite lädt CSS, Schriften und Bilder von der veröffentlichten Webseite —
 * Hugo schreibt dafür wurzelrelative Adressen (/css/…). Deshalb genügt die
 * eine HTML-Datei, und der static-Mount der Webseite bleibt beim Bauen außen
 * vor.
 */
final class PreviewService
{
    /** Ab dieser Hugo-Fassung gibt es Segments; darunter wird alles gerendert. */
    private const SEGMENTS_SINCE = [0, 124, 0];

    /** Lebensdauer einer abgelegten Vorschau in Sekunden. */
    private const TTL = 600;

    /** Obergrenze der Hugo-Ausgabe in Fehlermeldungen. */
    private const MAX_OUTPUT_LINES = 40;

    public function __construct(
        private readonly string $hugoBin,
        private readonly string $source,
        private readonly string $storageDir,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Baut die Vorschau und gibt das Token zurück, unter dem sie abrufbar ist.
     *
     * @param string  $contentAbs      absoluter Pfad der Content-Datei im Projekt
     * @param ?string $overrideContent Text, der statt des Dateiinhalts gelten
     *                                 soll (ungespeicherter Editor-Stand oder
     *                                 ein Freigabe-Entwurf); null = Datei wie
     *                                 gespeichert
     */
    public function build(string $contentAbs, ?string $overrideContent = null): string
    {
        $this->purgeExpired();

        $config = $this->hugoConfig();
        $relInContent = $this->relativeToContentDir($contentAbs, $config);
        $urlPath = $this->urlPathFor($contentAbs);

        $token = bin2hex(random_bytes(16));
        $work = $this->storageDir . '/' . $token . '.work';
        $out = $work . '/out';
        $this->makeDir($out);

        try {
            // Overlay: nur diese eine Datei, im selben Zuschnitt wie unter
            // content/ — alles andere (Bilder eines Page Bundles, Nachbar-
            // seiten) kommt weiterhin aus dem echten Projekt.
            $overlayFile = $work . '/content/' . $relInContent;
            $this->makeDir(dirname($overlayFile));
            $text = $overrideContent ?? (string) @file_get_contents($contentAbs);
            if (@file_put_contents($overlayFile, $text) === false) {
                throw new ApiException('EIO', 500, 'PREVIEW-STORAGE-FAILED');
            }

            $configFile = $work . '/preview.json';
            if (@file_put_contents(
                $configFile,
                json_encode($this->previewConfig($config, $work . '/content', $urlPath), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ) === false) {
                throw new ApiException('EIO', 500, 'PREVIEW-STORAGE-FAILED');
            }

            $this->runHugo($configFile, $out);

            $html = $this->readRendered($out, $urlPath);
            $target = $this->storageDir . '/' . $token . '.html';
            if (@file_put_contents($target, $this->decorate($html)) === false) {
                throw new ApiException('EIO', 500, 'PREVIEW-STORAGE-FAILED');
            }
        } finally {
            $this->removeTree($work);
        }

        return $token;
    }

    /**
     * Holt eine gebaute Vorschau ab und entfernt sie dabei — jedes Token gilt
     * genau einmal.
     */
    public function fetch(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw ApiException::badRequest('PREVIEW-EXPIRED');
        }
        $path = $this->storageDir . '/' . $token . '.html';
        $html = @file_get_contents($path);
        @unlink($path);
        if ($html === false) {
            throw ApiException::notFound('PREVIEW-EXPIRED');
        }

        return $html;
    }

    /**
     * Aufgelöste Hugo-Konfiguration des Projekts (JSON). Daraus stammen die
     * bestehenden Mounts und der content-Ordner — beides muss die Vorschau
     * übernehmen, statt Standardwerte anzunehmen.
     *
     * @return array<string, mixed>
     */
    private function hugoConfig(): array
    {
        $cmd = escapeshellarg($this->hugoBin) . ' config -s ' . escapeshellarg($this->source) . ' --format json 2>/dev/null';
        $raw = @shell_exec($cmd);
        $config = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($config)) {
            throw new ApiException('EHUGO', 500, 'PREVIEW-BUILD-FAILED', ['hugo config']);
        }

        return $config;
    }

    /**
     * Pfad der Datei relativ zum content-Ordner (z. B. "blog/beitrag.md").
     *
     * @param array<string, mixed> $config
     */
    private function relativeToContentDir(string $contentAbs, array $config): string
    {
        $contentDir = trim((string) ($config['contentDir'] ?? 'content'), '/');
        $root = realpath($this->source . '/' . $contentDir);
        $file = realpath($contentAbs);
        if ($root === false || $file === false || !str_starts_with($file, $root . '/')) {
            throw ApiException::badRequest('PREVIEW-NOT-CONTENT');
        }

        return substr($file, strlen($root) + 1);
    }

    /**
     * Fragt Hugo nach der Adresse, unter der die Datei erscheint, und gibt den
     * Pfad-Anteil zurück ("/blog/beitrag/"). `hugo list all` liefert je Seite
     * `path,…,permalink`; die Zuordnung stammt damit von Hugo selbst.
     */
    private function urlPathFor(string $contentAbs): string
    {
        $sourceReal = realpath($this->source);
        $fileReal = realpath($contentAbs);
        if ($sourceReal === false || $fileReal === false || !str_starts_with($fileReal, $sourceReal . '/')) {
            throw ApiException::badRequest('PREVIEW-NOT-CONTENT');
        }
        $relToProject = substr($fileReal, strlen($sourceReal) + 1);

        // Entwürfe und künftige Termine müssen mitgelistet werden, sonst fehlt
        // genau die Seite, die man sehen will.
        $cmd = escapeshellarg($this->hugoBin) . ' list all -s ' . escapeshellarg($this->source) . ' 2>/dev/null';
        $raw = @shell_exec($cmd);
        if (!is_string($raw) || $raw === '') {
            throw new ApiException('EHUGO', 500, 'PREVIEW-BUILD-FAILED', ['hugo list all']);
        }

        $header = null;
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if ($header === null) {
                $header = array_flip(array_map('strval', $cols));
                continue;
            }
            $pathCol = $header['path'] ?? 0;
            $linkCol = $header['permalink'] ?? (count($cols) - 1);
            if (($cols[$pathCol] ?? '') !== $relToProject) {
                continue;
            }
            $path = parse_url((string) ($cols[$linkCol] ?? ''), PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                break;
            }

            return $path;
        }

        // Die Datei erzeugt keine eigene Seite: headless, build.render=false
        // oder eine reine Ressource in einem Page Bundle.
        throw ApiException::badRequest('PREVIEW-NO-URL');
    }

    /**
     * Vorschau-Konfiguration: Overlay-Mount voran, static-Mount der Webseite
     * weglassen, und das Segment auf die eine Adresse begrenzen.
     *
     * Sobald Mounts gesetzt werden, gelten NUR noch die aufgeführten — die
     * bestehenden werden deshalb übernommen, nicht neu erfunden.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function previewConfig(array $config, string $overlayDir, string $urlPath): array
    {
        $mounts = [['source' => $overlayDir, 'target' => 'content']];
        $existing = $config['module']['mounts'] ?? null;
        foreach (is_array($existing) ? $existing : [] as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            // Statische Dateien der Webseite bleiben außen vor: Die Vorschau
            // lädt sie von der veröffentlichten Seite, das Kopieren wäre reine
            // Arbeit (im Testprojekt 446 Dateien je Vorschau).
            if (($mount['target'] ?? '') === 'static') {
                continue;
            }
            // Relative Quellen beziehen sich auf das Projekt und bleiben so.
            $mounts[] = $mount;
        }

        $trimmed = rtrim($urlPath, '/');
        // Startseite: kein "/**", das würde die ganze Webseite einschließen.
        $pattern = $trimmed === '' ? '/' : '{' . $trimmed . ',' . $trimmed . '/**}';

        return [
            'module' => ['mounts' => $mounts],
            'segments' => ['preview' => ['includes' => [['path' => $pattern]]]],
        ];
    }

    /** Führt den Vorschau-Bau aus; wirft bei Misserfolg mit Hugos Ausgabe. */
    private function runHugo(string $configFile, string $out): void
    {
        $configs = $this->configFileName() . ',' . $configFile;

        $cmd = escapeshellarg($this->hugoBin)
            . ' -s ' . escapeshellarg($this->source)
            . ' -d ' . escapeshellarg($out)
            . ' --config ' . escapeshellarg($configs)
            // Ohne diese drei fehlt in der Vorschau genau das, was noch nicht
            // veröffentlicht ist — der Hauptzweck der Funktion.
            . ' --buildDrafts --buildFuture --buildExpired'
            // Ein gleichzeitig laufender Cron-Build hält sonst die Sperre.
            . ' --noBuildLock'
            . ' --cacheDir ' . escapeshellarg($this->storageDir . '/cache')
            . ($this->supportsSegments() ? ' --renderSegments preview' : '')
            . ' 2>&1';

        $lines = [];
        $exitCode = 1;
        $start = hrtime(true);
        exec($cmd, $lines, $exitCode);
        $seconds = round((hrtime(true) - $start) / 1e9, 2);

        if ($exitCode !== 0) {
            $output = implode("\n", array_slice($lines, -self::MAX_OUTPUT_LINES));
            $this->logger?->warning("Vorschau-Bau fehlgeschlagen (Code {$exitCode}): {$output}");
            throw new ApiException('EHUGO', 500, 'PREVIEW-BUILD-FAILED', [$output]);
        }
        $this->logger?->info("Vorschau gebaut ({$seconds}s): {$this->source}");
    }

    /**
     * Name der Konfigurationsdatei des Projekts. Sie MUSS der eigenen
     * vorangestellt werden: --config ersetzt die automatische Auflösung, statt
     * sie zu ergänzen — sonst fehlten Theme und Parameter.
     */
    private function configFileName(): string
    {
        foreach (['hugo.toml', 'hugo.yaml', 'hugo.yml', 'hugo.json', 'config.toml', 'config.yaml', 'config.yml', 'config.json'] as $name) {
            if (is_file($this->source . '/' . $name)) {
                return $name;
            }
        }

        // Projekte mit config/_default/… haben keine einzelne Datei; dort
        // genügt der Verzeichnisstandard, den Hugo selbst auflöst.
        throw new ApiException('EHUGO', 500, 'PREVIEW-BUILD-FAILED', ['keine Konfigurationsdatei gefunden']);
    }

    /** Kennt die vorhandene Hugo-Fassung schon Segments (ab 0.124)? */
    private function supportsSegments(): bool
    {
        $raw = @shell_exec(escapeshellarg($this->hugoBin) . ' version 2>/dev/null');
        if (!is_string($raw) || preg_match('/v(\d+)\.(\d+)\.(\d+)/', $raw, $m) !== 1) {
            return false; // im Zweifel alles rendern statt zu scheitern
        }
        $version = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $version >= self::SEGMENTS_SINCE;
    }

    /**
     * Liest die gerenderte Seite. Je nach uglyURLs-Einstellung liegt sie als
     * Verzeichnis mit index.html oder als einzelne .html-Datei vor.
     */
    private function readRendered(string $out, string $urlPath): string
    {
        $trimmed = trim($urlPath, '/');
        $candidates = [
            $out . '/' . ($trimmed === '' ? '' : $trimmed . '/') . 'index.html',
            $out . '/' . $trimmed . '.html',
        ];
        foreach ($candidates as $candidate) {
            $html = @file_get_contents($candidate);
            if ($html !== false && $html !== '') {
                return $html;
            }
        }

        throw ApiException::badRequest('PREVIEW-NO-URL');
    }

    /**
     * Ergänzt die Seite um eine Suchmaschinen-Sperre und ein Hinweisband. Die
     * Sperre ist die zweite Absicherung: Ausgeliefert wird ohnehin nur an eine
     * angemeldete Sitzung und mit X-Robots-Tag — aber eine gespeicherte oder
     * weitergereichte Datei trägt den Hinweis dann bei sich.
     */
    private function decorate(string $html): string
    {
        $meta = '<meta name="robots" content="noindex,nofollow,noarchive">';
        $html = preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $meta, $html, 1) ?? $html;

        // Bewusst schlicht und mit eigenen Farben: Das Band soll sich nicht auf
        // das Aussehen der Seite verlassen und nichts überdecken.
        $banner = '<div style="position:sticky;top:0;z-index:2147483647;background:#3c8527;color:#fff;'
            . 'font:14px/1.4 system-ui,sans-serif;padding:6px 12px;text-align:center">'
            . 'Vorschau — dieser Stand ist nicht veröffentlicht. Verweise führen auf die Live-Webseite.'
            . '</div>';

        return preg_replace('/<body\b[^>]*>/i', '$0' . "\n" . $banner, $html, 1) ?? $html;
    }

    /** Entfernt abgelaufene Vorschauen und liegengebliebene Arbeitsordner. */
    private function purgeExpired(): void
    {
        $limit = time() - self::TTL;
        foreach (glob($this->storageDir . '/*') ?: [] as $path) {
            if (str_ends_with($path, '/cache')) {
                continue; // Hugos Zwischenspeicher bleibt bestehen
            }
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > $limit) {
                continue;
            }
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
    }

    private function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException('EIO', 500, 'PREVIEW-STORAGE-FAILED');
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        try {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
            }
            @rmdir($dir);
        } catch (Throwable) {
            // Aufräumen ist best effort — beim nächsten Lauf greift purgeExpired.
        }
    }
}
