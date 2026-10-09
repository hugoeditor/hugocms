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
 * die ganze Webseite schreibbar. Innerhalb des Mounts darf die Anbindung nur,
 * was ein Administrator in den Projekteinstellungen freigegeben hat ([shop],
 * {@see allowedPath()}):
 *
 *   - Produktseiten ([shop] content_dir): nur Markdown.
 *   - Kategorieübersicht ([shop] category_groups): genau diese eine Datei.
 *   - Webseiten-Paket ({@see PACKAGE_DIR}, fest): die Endungen aus
 *     {@see ACCEPT}, nur Text — kein PHP. Der Texteditor von HugoCMS schreibt
 *     ebenfalls kein PHP; die Anbindung soll nicht mehr dürfen als ein
 *     Redakteur ohne Dateityp-Einschränkung.
 *
 * Eine Einschränkung je Konto (file_types) gilt hier nicht: Die Anbindung
 * meldet sich mit Schlüssel an, nicht als Benutzer, und nutzt eine eigene
 * FileService-Instanz.
 *
 * Einzige Ausnahme sind die PHP-Einstiegspunkte des Webseiten-Pakets
 * ({@see SIGNED_PHP}: Weiterleiter und 404-Seite). Sie nimmt die Anbindung nur
 * signiert an: Ein Administrator hinterlegt den öffentlichen Ed25519-Schlüssel
 * von OpensourceERP ([shop] signing_key), und jede dieser Dateien braucht im
 * Abgleich eine Signatur über Pfad und Prüfsumme ({@see signatureMessage()}).
 * Der Schlüssel der Anbindung allein reicht so nicht, um PHP auf den
 * Webserver zu bringen. Ohne hinterlegten Schlüssel oder ohne die
 * Sodium-Erweiterung von PHP bleibt es beim Verbot.
 *
 * Gelöscht wird nur, was OpensourceERP bei der VORIGEN Übernahme selbst
 * geliefert hat (last-manifest.json). Von Hand angelegte Dateien in einer
 * Freigabe — etwa content/de/produkt/_index.md — bleiben dadurch unberührt.
 * Das gilt auch, nachdem ein Administrator eine Freigabe verlegt hat: Die
 * Seiten am alten Ort stammen aus einer Lieferung und gehen deshalb, statt
 * verwaist mit alten Preisen veröffentlicht zu bleiben
 * ({@see deletablePath()}). Die signierten PHP-Einstiegspunkte
 * ({@see SIGNED_PHP}) löscht die Anbindung nie: Sie werden ersetzt, entfernt
 * nur von Hand.
 */
final class ShopSync
{
    /** Endungen, die die Anbindung schreiben darf. */
    public const ACCEPT = ['md', 'json', 'html', 'js', 'css'];

    /**
     * Verzeichnis des Webseiten-Pakets (Vorlagen, Skripte, Konfiguration),
     * relativ zur Hugo-Quelle. Fest, nicht wählbar: Die Vorlagen von
     * OpensourceERP binden es unter diesem Namen ein.
     */
    public const PACKAGE_DIR = 'oserp-shop';

    /** Freigaben, solange in [shop] nichts anderes steht: der Aufbau, den OpensourceERP erzeugt. */
    public const DEFAULT_CONTENT_DIR = 'content/de/produkt';
    public const DEFAULT_CATEGORY_GROUPS = 'data/category_groups.json';

    /**
     * Die einzigen PHP-Dateien, die die Anbindung schreiben darf — und nur
     * signiert. Fest im Code, nicht in der Konfiguration: welche PHP-Dateien
     * es überhaupt geben darf, entscheidet HugoCMS, nicht die Lieferung.
     */
    public const SIGNED_PHP = ['oserp-shop/static/shop-api/index.php', 'oserp-shop/static/not_found.php'];

    /** Zweck der Signatur — sie taugt für nichts anderes. */
    private const SIGNATURE_CONTEXT = "hugocms-shop-php\n";

    /** Höchstzahl der Dateien in einem Abgleich. */
    private const MAX_FILES = 20000;

    /** Größte Einzeldatei — dieselbe Grenze wie im Texteditor. */
    private const MAX_FILE_BYTES = 5_242_880;

    /** Nach dieser Zeit gilt eine nicht übernommene Lieferung als aufgegeben. */
    private const SYNC_MAX_AGE = 86400;

    private readonly Mount $mount;
    private readonly MountResolver $resolver;
    private readonly FileService $files;

    /** Nimmt die Anbindung signierte PHP-Dateien an? */
    private readonly bool $signedPhp;

    /**
     * @param string $source Hugo-Quellverzeichnis der Webseite
     * @param string $varDir Laufzeitverzeichnis, etwa var/shop/<sha1(Quelle)>
     * @param ?string $contentDir Freigabe der Produktseiten, relativ zur Quelle
     *                            (null = unbrauchbar eingetragen, nichts freigegeben)
     * @param ?string $categoryGroups Freigabe der Kategorieübersicht (eine Datei),
     *                                relativ zur Quelle, null wie oben
     * @param ?string $signingKey öffentlicher Ed25519-Schlüssel von OpensourceERP
     *                            (Base64, aus [shop] signing_key), null = kein PHP
     */
    public function __construct(
        string $source,
        private readonly string $varDir,
        private readonly ?string $contentDir,
        private readonly ?string $categoryGroups,
        private readonly ?string $signingKey = null,
    ) {
        $this->signedPhp = self::signedPhpReady($signingKey);
        // php nur mit Schlüssel — welche Pfade, entscheidet allowedPath()
        $accept = $this->signedPhp ? [...self::ACCEPT, 'php'] : self::ACCEPT;
        $this->mount = new Mount('shop', $source, 'Shop', ['read', 'write', 'delete', 'mkdir'], $accept);
        $this->resolver = new MountResolver();
        $this->resolver->add($this->mount);
        // Eigene Endungsliste statt der Editor-Vorgabe: Die Anbindung schreibt
        // auch .js (oserp-shop/), das der Texteditor nur mit extra_editable öffnet.
        $this->files = new FileService($this->resolver, $accept);
    }

    // --- Signierte PHP-Dateien -------------------------------------------------

    /** Kann diese Installation Signaturen prüfen (Sodium-Erweiterung von PHP)? */
    public static function signingAvailable(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /** Nimmt die Anbindung mit diesem Schlüssel signierte PHP-Dateien an? */
    public static function signedPhpReady(?string $signingKey): bool
    {
        return $signingKey !== null && self::signingAvailable();
    }

    /**
     * Prüft einen öffentlichen Ed25519-Schlüssel und bringt ihn in die
     * gespeicherte Form (Base64 ohne Leerraum).
     *
     * @return ?string null, wenn es kein gültiger Schlüssel ist
     */
    public static function normalizeSigningKey(string $value): ?string
    {
        $bytes = base64_decode(preg_replace('/\s+/', '', $value) ?? '', true);
        if ($bytes === false || strlen($bytes) !== 32) {
            return null;
        }

        return base64_encode($bytes);
    }

    /**
     * Was OpensourceERP signiert: Zweck, Pfad und Prüfsumme. Die Signatur
     * taugt damit weder für eine andere Datei noch für einen anderen Ort.
     */
    public static function signatureMessage(string $path, string $sha256): string
    {
        return self::SIGNATURE_CONTEXT . $path . "\n" . strtolower($sha256);
    }

    private function signatureValid(string $path, string $sha256, mixed $signature): bool
    {
        if (!$this->signedPhp || !is_string($signature)) {
            return false;
        }
        $bytes = base64_decode($signature, true);
        $key = base64_decode((string) $this->signingKey, true);
        if ($bytes === false || $key === false || strlen($bytes) !== 64 || strlen($key) !== 32) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($bytes, self::signatureMessage($path, $sha256), $key);
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
     * @param mixed $entries Liste aus {path, sha256}, bei {@see SIGNED_PHP}
     *                      zusätzlich signature (Base64)
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
            // PHP nur signiert: Die Prüfsumme deckt danach in upload() den Inhalt
            if (in_array($path, self::SIGNED_PHP, true)
                && !$this->signatureValid($path, $hash, $entry['signature'] ?? null)) {
                throw ApiException::denied('SHOP-SIGNATURE-INVALID', [$path]);
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
            // Die PHP-Einstiegspunkte nie löschen, nur ersetzen: Fehlen sie in
            // einer Lieferung — etwa weil OpensourceERP seinen Schlüssel
            // gerade nicht lesen kann —, legte ein Löschen den ganzen Shop
            // still (ohne Weiterleiter kein Warenkorb, keine Kasse)
            if (in_array($path, self::SIGNED_PHP, true)) {
                continue;
            }
            try {
                $path = $this->deletablePath($path);
                $target = $this->resolver->resolve($this->resolver->encodeId('shop', $path), true);
            } catch (ApiException) {
                // Gibt es nicht mehr
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
     * Prüft einen Pfad der Lieferung: gültig ({@see validPath()}), innerhalb
     * einer Freigabe und mit der Endung, die diese Freigabe erlaubt.
     */
    private function allowedPath(string $path): string
    {
        $path = $this->validPath($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($this->categoryGroups !== null && $path === $this->categoryGroups) {
            // Kategorieübersicht: genau diese Datei, ihre Endung prüft schon
            // die Freigabe (json)
        } elseif (str_starts_with($path, self::PACKAGE_DIR . '/')) {
            // PHP nur an den festen Pfaden und nur mit hinterlegtem Schlüssel —
            // der Mount nimmt php dann zwar an, aber nicht an beliebiger Stelle
            if ($extension === 'php' && (!$this->signedPhp || !in_array($path, self::SIGNED_PHP, true))) {
                throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
            }
        } elseif ($this->contentDir !== null && str_starts_with($path, $this->contentDir . '/')) {
            // Produktseiten: nur Markdown
            if ($extension !== 'md') {
                throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
            }
        } else {
            throw ApiException::denied('SHOP-PATH-NOT-ALLOWED', [$path]);
        }
        if (!$this->mount->accepts(basename($path))) {
            throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
        }

        return $path;
    }

    /**
     * Darf die Übernahme einen Pfad der vorigen Lieferung löschen? Bewusst
     * NICHT gegen die heutigen Freigaben geprüft: Hat ein Administrator eine
     * Freigabe verlegt, liegen die alten Seiten außerhalb — sie stammen aber
     * aus einer Lieferung, die damals geprüft wurde, und sollen nicht verwaist
     * veröffentlicht bleiben. Was in last-manifest.json steht, hat nur die
     * Übernahme selbst geschrieben. Gelöscht wird trotzdem nur Gültiges mit
     * einer Endung der Anbindung, nie PHP.
     */
    private function deletablePath(string $path): string
    {
        $path = $this->validPath($path);
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::ACCEPT, true)) {
            throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
        }

        return $path;
    }

    /** Relativ, ohne .. und ohne versteckte Bestandteile. */
    private function validPath(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw ApiException::badRequest('SHOP-PATH-INVALID', [$path]);
        }
        foreach (explode('/', $path) as $segment) {
            // leer (//, führendes /), . und .. sowie versteckte Namen wie .htaccess
            if ($segment === '' || str_starts_with($segment, '.')) {
                throw ApiException::badRequest('SHOP-PATH-INVALID', [$path]);
            }
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
