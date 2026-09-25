<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

use HugoCMS\FileManager\Exception\ApiException;
use HugoCMS\FileManager\FileService;
use HugoCMS\FileManager\Mount;
use HugoCMS\FileManager\MountResolver;

/**
 * Übertragung der Inhaltsdateien aus OpensourceERP.
 *
 * OpensourceERP erzeugt Produktseiten, Kategorieübersicht und das
 * Webseiten-Paket und schickt sie in drei Schritten:
 *
 *   1. Abgleich ({@see manifest()}): das Verzeichnis aller Dateien mit
 *      Prüfsumme. Die Antwort nennt, was fehlt oder abweicht.
 *   2. Übertragung ({@see upload()}): nur diese Dateien, in Portionen. Sie
 *      landen zunächst im Bereitstellungsverzeichnis unter var/, nicht in der
 *      Webseite.
 *   3. Übernahme ({@see commit()}): alles in die Webseite schreiben, nicht mehr
 *      Geliefertes löschen, die Bau-Markierung setzen. Vorher wird nie gebaut —
 *      eine halbe Lieferung landet nicht auf der Webseite.
 *
 * Geschrieben wird ausschließlich über FileService und einen eigenen
 * MountResolver mit genau einem Mount auf das Hugo-Quellverzeichnis. Der Mount
 * ist bewusst NICHT im Resolver der Redakteure registriert: Über ihn wäre sonst
 * die ganze Webseite schreibbar. Innerhalb des Mounts begrenzen zwei Listen,
 * was die Anbindung anfassen darf:
 *
 *   - Bereiche ([shop] areas): Verzeichnisse (mit / am Ende) und einzelne
 *     Dateien. Vorgabe ist der Aufbau, den OpensourceERP erzeugt.
 *   - Endungen ({@see ACCEPT}): nur Text — kein PHP. Der Texteditor von
 *     HugoCMS schreibt ebenfalls kein PHP; die Anbindung soll nicht mehr
 *     dürfen als ein Redakteur ohne Dateityp-Einschränkung. Eine
 *     Einschränkung je Konto (file_types) gilt hier nicht: Die Anbindung
 *     meldet sich mit Schlüssel an, nicht als Benutzer, und nutzt eine eigene
 *     FileService-Instanz.
 *
 * Gelöscht wird nur, was OpensourceERP bei der VORIGEN Übernahme selbst
 * geliefert hat (last-manifest.json). Von Hand angelegte Dateien in einem
 * Bereich — etwa content/de/produkt/_index.md — bleiben dadurch unberührt.
 */
final class ShopSync
{
    /** Endungen, die die Anbindung schreiben darf. */
    public const ACCEPT = ['md', 'json', 'html', 'js', 'css'];

    /** Bereiche, wenn [shop] areas fehlt: der Aufbau, den OpensourceERP erzeugt. */
    public const DEFAULT_AREAS = ['content/de/produkt/', 'data/category_groups.json', 'oserp-shop/'];

    /** Höchstzahl der Dateien in einem Abgleich. */
    private const MAX_FILES = 20000;

    /** Größte Einzeldatei — dieselbe Grenze wie im Texteditor. */
    private const MAX_FILE_BYTES = 5_242_880;

    /** Nach dieser Zeit gilt eine nicht übernommene Lieferung als aufgegeben. */
    private const SYNC_MAX_AGE = 86400;

    private readonly Mount $mount;
    private readonly MountResolver $resolver;
    private readonly FileService $files;

    /**
     * @param string $source Hugo-Quellverzeichnis der Webseite
     * @param string $varDir Laufzeitverzeichnis, etwa var/shop/<sha1(Quelle)>
     * @param list<string> $areas erlaubte Bereiche, relativ zur Quelle
     */
    public function __construct(
        string $source,
        private readonly string $varDir,
        private readonly array $areas,
    ) {
        $this->mount = new Mount('shop', $source, 'Shop', ['read', 'write', 'delete', 'mkdir'], self::ACCEPT);
        $this->resolver = new MountResolver();
        $this->resolver->add($this->mount);
        $this->files = new FileService($this->resolver);
    }

    // --- Bau-Markierung -------------------------------------------------------

    /** Wartet eine übernommene Lieferung auf ihren Bau? */
    public static function buildPending(string $varDir): bool
    {
        return is_file($varDir . '/build-pending');
    }

    /**
     * Nimmt die Markierung zurück — zu Beginn eines Baus, innerhalb der
     * Bausperre. Kommt währenddessen eine neue Lieferung, setzt sie die
     * Markierung erneut, und der nächste Lauf baut sie.
     */
    public static function clearBuildPending(string $varDir): void
    {
        @unlink($varDir . '/build-pending');
    }

    /**
     * Setzt die Markierung wieder — nach einem gescheiterten Bau. Was
     * übernommen wurde, ist dann noch nicht veröffentlicht; der nächste Lauf
     * (Cron oder Anstoß) versucht es erneut.
     */
    public static function markBuildPending(string $varDir): void
    {
        if (!is_dir($varDir)) {
            @mkdir($varDir, 0775, true);
        }
        @touch($varDir . '/build-pending');
    }

    // --- 1. Abgleich -----------------------------------------------------------

    /**
     * Nimmt das Verzeichnis der Lieferung entgegen und nennt, was fehlt.
     *
     * @param mixed $entries Liste aus {path, sha256}
     * @return array{syncId: string, needed: list<string>, unchanged: int, total: int}
     */
    public function manifest(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw ApiException::badRequest('SHOP-MANIFEST-INVALID');
        }
        if (count($entries) > self::MAX_FILES) {
            throw ApiException::badRequest('SHOP-MANIFEST-TOO-LARGE', [self::MAX_FILES]);
        }

        $files = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['sha256'] ?? null)) {
                throw ApiException::badRequest('SHOP-MANIFEST-INVALID');
            }
            $path = $this->allowedPath($entry['path']);
            $hash = strtolower($entry['sha256']);
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw ApiException::badRequest('SHOP-MANIFEST-INVALID');
            }
            if (isset($files[$path])) {
                throw ApiException::badRequest('SHOP-PATH-DUPLICATE', [$path]);
            }
            $files[$path] = $hash;
        }

        $needed = [];
        foreach ($files as $path => $hash) {
            $abs = $this->mount->root() . '/' . $path;
            if (!is_file($abs) || hash_file('sha256', $abs) !== $hash) {
                $needed[] = $path;
            }
        }

        $this->removeStaleSyncs();
        $syncId = bin2hex(random_bytes(16));
        $dir = $this->syncDir($syncId);
        if (!@mkdir($dir . '/files', 0775, true)) {
            throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
        }
        $this->writeJson($dir . '/manifest.json', [
            'created' => time(),
            'files' => $files,
            'needed' => $needed,
        ]);

        return [
            'syncId' => $syncId,
            'needed' => $needed,
            'unchanged' => count($files) - count($needed),
            'total' => count($files),
        ];
    }

    // --- 2. Übertragung --------------------------------------------------------

    /**
     * Legt eine Portion Dateien bereit. Jede muss im Abgleich als fehlend
     * genannt worden sein und ihre angekündigte Prüfsumme haben.
     *
     * @param mixed $entries Liste aus {path, content (Base64)}
     * @return array{stored: int}
     */
    public function upload(string $syncId, mixed $entries): array
    {
        $manifest = $this->loadManifest($syncId);
        if (!is_array($entries) || !array_is_list($entries)) {
            throw ApiException::badRequest('SHOP-UPLOAD-INVALID');
        }
        $needed = array_flip($manifest['needed']);

        $stored = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['content'] ?? null)) {
                throw ApiException::badRequest('SHOP-UPLOAD-INVALID');
            }
            $path = $this->allowedPath($entry['path']);
            if (!isset($needed[$path])) {
                throw ApiException::badRequest('SHOP-PATH-NOT-EXPECTED', [$path]);
            }
            $content = base64_decode($entry['content'], true);
            if ($content === false) {
                throw ApiException::badRequest('SHOP-UPLOAD-INVALID');
            }
            if (strlen($content) > self::MAX_FILE_BYTES) {
                throw ApiException::badRequest('SHOP-FILE-TOO-LARGE', [$path]);
            }
            if (hash('sha256', $content) !== $manifest['files'][$path]) {
                throw ApiException::badRequest('SHOP-HASH-MISMATCH', [$path]);
            }

            $target = $this->syncDir($syncId) . '/files/' . $path;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
                throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
            }
            if (@file_put_contents($target, $content) === false) {
                throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
            }
            $stored++;
        }

        return ['stored' => $stored];
    }

    // --- 3. Übernahme ----------------------------------------------------------

    /**
     * Schreibt die Lieferung in die Webseite und räumt nicht mehr Geliefertes weg.
     *
     * @return array{written: int, deleted: int, unchanged: int, buildPending: bool}
     */
    public function commit(string $syncId): array
    {
        $manifest = $this->loadManifest($syncId);
        $staging = $this->syncDir($syncId) . '/files';

        // Erst vollständig prüfen, dann schreiben: fehlt eine Datei, bleibt die
        // Webseite unberührt
        $missing = array_values(array_filter(
            $manifest['needed'],
            static fn (string $path): bool => !is_file($staging . '/' . $path),
        ));
        if ($missing !== []) {
            throw new ApiException('ECONFLICT', 409, 'SHOP-SYNC-INCOMPLETE', [count($missing), $missing[0]]);
        }

        $written = 0;
        foreach ($manifest['needed'] as $path) {
            $this->ensureDir(dirname($path));
            $target = $this->resolver->resolve($this->resolver->encodeId('shop', $path), false);
            $this->files->writeText($this->mount, $target['rel'], $target['abs'], (string) file_get_contents($staging . '/' . $path));
            $written++;
        }

        // Löschen nur, was die vorige Lieferung enthielt und diese nicht mehr
        $previous = $this->readJson($this->varDir . '/last-manifest.json')['files'] ?? [];
        $deleted = 0;
        foreach (array_keys($previous) as $path) {
            if (isset($manifest['files'][$path]) || !is_string($path)) {
                continue;
            }
            try {
                $path = $this->allowedPath($path);
                $target = $this->resolver->resolve($this->resolver->encodeId('shop', $path), true);
            } catch (ApiException) {
                // Gibt es nicht mehr oder liegt inzwischen außerhalb der Bereiche
                continue;
            }
            $this->files->remove($this->mount, $target['abs']);
            $deleted++;
        }

        $this->writeJson($this->varDir . '/last-manifest.json', [
            'committed' => time(),
            'files' => $manifest['files'],
        ]);

        $pending = $written + $deleted > 0;
        if ($pending) {
            @touch($this->varDir . '/build-pending');
        }

        $this->removeDir($this->syncDir($syncId));

        return [
            'written' => $written,
            'deleted' => $deleted,
            'unchanged' => count($manifest['files']) - $written,
            'buildPending' => $pending || self::buildPending($this->varDir),
        ];
    }

    // --- Hilfen ----------------------------------------------------------------

    /**
     * Prüft einen Pfad der Lieferung: relativ, ohne .. und ohne versteckte
     * Bestandteile, innerhalb eines Bereichs und mit erlaubter Endung.
     */
    private function allowedPath(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw ApiException::badRequest('SHOP-PATH-INVALID', [$path]);
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            // leer (//, führendes /), . und .. sowie versteckte Namen wie .htaccess
            if ($segment === '' || str_starts_with($segment, '.')) {
                throw ApiException::badRequest('SHOP-PATH-INVALID', [$path]);
            }
        }

        $inside = false;
        foreach ($this->areas as $area) {
            if (str_ends_with($area, '/') ? str_starts_with($path, $area) : $path === $area) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            throw ApiException::denied('SHOP-PATH-NOT-ALLOWED', [$path]);
        }
        if (!$this->mount->accepts(basename($path))) {
            throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
        }

        return $path;
    }

    /** Legt fehlende Verzeichnisse Ebene für Ebene über FileService an. */
    private function ensureDir(string $rel): void
    {
        if ($rel === '.' || $rel === '') {
            return;
        }
        $current = '';
        foreach (explode('/', $rel) as $segment) {
            $next = $current === '' ? $segment : $current . '/' . $segment;
            if (!is_dir($this->mount->root() . '/' . $next)) {
                $parent = $this->resolver->resolve($this->resolver->encodeId('shop', $current), true);
                $this->files->makeDir($this->mount, $parent['rel'], $parent['abs'], $segment);
            }
            $current = $next;
        }
    }

    /** @return array{created: int, files: array<string, string>, needed: list<string>} */
    private function loadManifest(string $syncId): array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $syncId) !== 1) {
            throw ApiException::badRequest('SHOP-SYNC-UNKNOWN');
        }
        $manifest = $this->readJson($this->syncDir($syncId) . '/manifest.json');
        if (!isset($manifest['files'], $manifest['needed']) || !is_array($manifest['files'])) {
            throw ApiException::notFound('SHOP-SYNC-UNKNOWN');
        }

        return $manifest;
    }

    private function syncDir(string $syncId): string
    {
        return $this->varDir . '/sync/' . $syncId;
    }

    /** Räumt Lieferungen weg, die nie übernommen wurden. */
    private function removeStaleSyncs(): void
    {
        foreach (glob($this->varDir . '/sync/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $created = (int) ($this->readJson($dir . '/manifest.json')['created'] ?? 0);
            if ($created < time() - self::SYNC_MAX_AGE) {
                $this->removeDir($dir);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true)) {
            throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
        }
    }
}
