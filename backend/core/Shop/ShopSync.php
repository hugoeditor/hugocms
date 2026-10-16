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
    /** Endungen, die die Anbindung im Webseiten-Paket schreiben darf. */
    public const ACCEPT = ['md', 'json', 'html', 'js', 'css'];

    /**
     * Medien aus der Bibliothek von OpensourceERP (dort
     * dev/shop-medienbibliothek.md, seit 2026-10-15): Produkt- und
     * Vorschaubilder in den Freigaben „Produktbilder" und „Vorschaubilder",
     * Datenblätter und Anleitungen in „Downloads" — jede Freigabe mit ihrer
     * Endungsliste, nur direkt im Verzeichnis, nie PHP. Sie kommen als
     * Binärdaten in Stücken ({@see uploadMedia()}), nicht Base64 in JSON.
     */
    public const MEDIA_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const MEDIA_DOWNLOAD_EXT = ['pdf', 'zip', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Größte Mediendatei — die Grenze der Bibliothek für Downloads. */
    public const MAX_MEDIA_BYTES = 104_857_600;

    /** Größtes Stück einer Medienübertragung je Anfrage, vor den Grenzen von php.ini. */
    public const MAX_CHUNK_BYTES = 8_388_608;

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

    /** Höchstzahl der Dateien in einem Abgleich — Seiten, Paket und Medien zusammen. */
    private const MAX_FILES = 60000;

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
     * Freigaben der Medien: images, thumbnails, downloads => Verzeichnis
     * relativ zur Quelle oder null (unbrauchbar eingetragen).
     *
     * @var array<string, ?string>
     */
    private readonly array $mediaGrants;

    /**
     * @param string $source Hugo-Quellverzeichnis der Webseite
     * @param string $varDir Laufzeitverzeichnis, etwa var/shop/<sha1(Quelle)>
     * @param ?string $contentDir Freigabe der Produktseiten, relativ zur Quelle
     *                            (null = unbrauchbar eingetragen, nichts freigegeben)
     * @param ?string $categoryGroups Freigabe der Kategorieübersicht (eine Datei),
     *                                relativ zur Quelle, null wie oben
     * @param ?string $signingKey öffentlicher Ed25519-Schlüssel von OpensourceERP
     *                            (Base64, aus [shop] signing_key), null = kein PHP
     * @param ?string $images Freigabe der Produktbilder, relativ zur Quelle, null wie oben
     * @param ?string $thumbnails Freigabe der Vorschaubilder
     * @param ?string $downloads Freigabe der Downloads
     */
    public function __construct(
        string $source,
        private readonly string $varDir,
        private readonly ?string $contentDir,
        private readonly ?string $categoryGroups,
        private readonly ?string $signingKey = null,
        ?string $images = null,
        ?string $thumbnails = null,
        ?string $downloads = null,
    ) {
        $this->signedPhp = self::signedPhpReady($signingKey);
        $this->mediaGrants = ['images' => $images, 'thumbnails' => $thumbnails, 'downloads' => $downloads];
        // php nur mit Schlüssel — welche Pfade, entscheidet allowedPath(); die
        // Medienendungen nimmt der Mount an, allowedPath() lässt sie nur in
        // den Medienfreigaben zu
        $accept = array_values(array_unique([...self::ACCEPT, ...self::MEDIA_DOWNLOAD_EXT, ...($this->signedPhp ? ['php'] : [])]));
        $this->mount = new Mount('shop', $source, 'Shop', ['read', 'write', 'delete', 'mkdir'], $accept);
        $this->resolver = new MountResolver();
        $this->resolver->add($this->mount);
        // Eigene Endungsliste statt der Editor-Vorgabe: Die Anbindung schreibt
        // auch .js (oserp-shop/), das der Texteditor nur mit extra_editable
        // öffnet. Die Grenze für Uploads ist die der Medien (Downloads).
        $this->files = new FileService($this->resolver, $accept, 5_242_880, self::MAX_MEDIA_BYTES);
    }

    /** Freigaben der Medien, wie dem Konstruktor übergeben. */
    public function mediaGrants(): array
    {
        return $this->mediaGrants;
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
     * Medien (Pfade in den Medienfreigaben) tragen zusätzlich ihre Größe. Ob
     * eines schon da ist, entscheidet nicht jedes Mal eine neue Prüfsumme über
     * Gigabytes: Die Übernahme merkt sich je Medium Prüfsumme, Größe und Zeit
     * (last-manifest.json), und solange Größe und Zeit stimmen, gilt die
     * gemerkte Prüfsumme.
     *
     * @param mixed $entries Liste aus {path, sha256}, bei {@see SIGNED_PHP}
     *                      zusätzlich signature (Base64), bei Medien size
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
        $sizes = [];
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
            if ($this->mediaArea($path) !== null) {
                // Medien: die Größe gehört dazu — die Stücke werden daran gemessen
                $size = $entry['size'] ?? null;
                if (!is_int($size) && !(is_string($size) && ctype_digit($size))) {
                    throw ApiException::badRequest('SHOP-MANIFEST-INVALID');
                }
                if ((int) $size > self::MAX_MEDIA_BYTES) {
                    throw ApiException::badRequest('SHOP-FILE-TOO-LARGE', [$path]);
                }
                $sizes[$path] = (int) $size;
            }
            $files[$path] = $hash;
        }

        $previous = $this->readJson($this->varDir . '/last-manifest.json');
        $known = is_array($previous['files'] ?? null) ? $previous['files'] : [];
        $stat = is_array($previous['stat'] ?? null) ? $previous['stat'] : [];

        $needed = [];
        foreach ($files as $path => $hash) {
            $abs = $this->mount->root() . '/' . $path;
            if (!is_file($abs)) {
                $needed[] = $path;
                continue;
            }
            if (isset($sizes[$path]) && is_array($stat[$path] ?? null) && is_string($known[$path] ?? null)) {
                clearstatcache(true, $abs);
                if ((int) ($stat[$path][0] ?? -1) === filesize($abs) && (int) ($stat[$path][1] ?? -1) === filemtime($abs)) {
                    // Unverändert seit der letzten Übernahme: Die gemerkte
                    // Prüfsumme ist die der Datei
                    if ($known[$path] !== $hash) {
                        $needed[] = $path;
                    }
                    continue;
                }
            }
            if (hash_file('sha256', $abs) !== $hash) {
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
            'sizes' => $sizes,
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
            if (isset($manifest['sizes'][$path])) {
                throw ApiException::badRequest('SHOP-MEDIA-USE-CHUNKS', [$path]);
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

    // --- 2b. Medien in Stücken ------------------------------------------------

    /**
     * Nimmt ein Stück einer Mediendatei an. Die Stücke kommen der Reihe nach
     * ($offset = bisher angekommene Bytes) und sammeln sich in der
     * Bereitstellung; mit dem letzten Stück wird die Prüfsumme geprüft und die
     * Datei sofort in ihre Freigabe gelegt — nicht erst mit der Übernahme:
     * Eine abgebrochene erste Übertragung (Gigabytes) macht beim nächsten
     * Abgleich dort weiter, wo sie stand, statt von vorn. Ein Stück mit
     * $offset 0 beginnt die Datei neu.
     *
     * @param mixed $path Pfad aus dem Abgleich
     * @param mixed $offset bisher angekommene Bytes
     * @param mixed $chunk Eintrag aus $_FILES (tmp_name, error, size)
     * @return array{path: string, received: int, complete: bool}
     */
    public function uploadMedia(string $syncId, mixed $path, mixed $offset, mixed $chunk): array
    {
        $manifest = $this->loadManifest($syncId);
        if (!is_string($path) || !is_numeric($offset) || !is_array($chunk)) {
            throw ApiException::badRequest('SHOP-MEDIA-INVALID');
        }
        $path = $this->allowedPath($path);
        if (!isset($manifest['sizes'][$path]) || !in_array($path, $manifest['needed'], true)) {
            throw ApiException::badRequest('SHOP-PATH-NOT-EXPECTED', [$path]);
        }
        $size = (int) $manifest['sizes'][$path];
        $offset = (int) $offset;

        $error = (int) ($chunk['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ApiException::badRequest('SHOP-CHUNK-TOO-LARGE', [self::chunkBytes()]);
        }
        $source = (string) ($chunk['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $source === '' || !is_file($source)) {
            throw ApiException::badRequest('SHOP-MEDIA-INVALID');
        }
        clearstatcache(true, $source);
        $length = (int) filesize($source);
        if ($length > self::MAX_CHUNK_BYTES) {
            throw ApiException::badRequest('SHOP-CHUNK-TOO-LARGE', [self::chunkBytes()]);
        }

        $part = $this->syncDir($syncId) . '/media/' . md5($path) . '.part';
        if (!is_dir(dirname($part)) && !@mkdir(dirname($part), 0775, true)) {
            throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
        }
        clearstatcache(true, $part);
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($offset === 0 && $have > 0) {
            @unlink($part);
            $have = 0;
        }
        if ($offset !== $have) {
            throw new ApiException('ECONFLICT', 409, 'SHOP-MEDIA-OFFSET', [$path, $have]);
        }
        if ($have + $length > $size) {
            @unlink($part);
            throw ApiException::badRequest('SHOP-MEDIA-SIZE-MISMATCH', [$path]);
        }

        $in = @fopen($source, 'rb');
        $out = @fopen($part, 'ab');
        $copied = $in !== false && $out !== false ? stream_copy_to_stream($in, $out) : false;
        if ($in !== false) {
            fclose($in);
        }
        if ($out !== false) {
            fclose($out);
        }
        if ($copied !== $length) {
            @unlink($part);
            throw new ApiException('EIO', 500, 'SHOP-SYNC-STORE-FAILED');
        }
        $have += $length;
        if ($have < $size) {
            return ['path' => $path, 'received' => $have, 'complete' => false];
        }

        // Vollständig: Prüfsumme, dann an den Ort der Freigabe
        if (hash_file('sha256', $part) !== $manifest['files'][$path]) {
            @unlink($part);
            throw ApiException::badRequest('SHOP-HASH-MISMATCH', [$path]);
        }
        $this->ensureDir(dirname($path));
        $target = $this->resolver->resolve($this->resolver->encodeId('shop', $path), false);
        $this->files->putFile($this->mount, $target['rel'], $target['abs'], $part);

        return ['path' => $path, 'received' => $have, 'complete' => true];
    }

    /**
     * Größtes Stück, das diese Installation annimmt: die eigene Grenze, soweit
     * php.ini (upload_max_filesize, post_max_size abzüglich der Felder) sie
     * zulässt. OpensourceERP richtet seine Stücke danach.
     */
    public static function chunkBytes(): int
    {
        $limit = self::MAX_CHUNK_BYTES;
        foreach (['upload_max_filesize' => 0, 'post_max_size' => 65536] as $key => $reserve) {
            $bytes = self::iniBytes((string) ini_get($key));
            if ($bytes > 0) {
                $limit = min($limit, max(0, $bytes - $reserve));
            }
        }

        return $limit;
    }

    /** Kurzschreibweise aus php.ini (8M, 512K, 1G) in Bytes; 0 = unbegrenzt oder unlesbar. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $m) !== 1) {
            return 0;
        }
        $factor = match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1_048_576,
            'g' => 1_073_741_824,
            default => 1,
        };

        return (int) $m[1] * $factor;
    }

    // --- 3. Übernahme ----------------------------------------------------------

    /**
     * Schreibt die Lieferung in die Webseite und räumt nicht mehr Geliefertes weg.
     *
     * Medien liegen zu diesem Zeitpunkt schon in ihrer Freigabe
     * ({@see uploadMedia()}); hier wird nur geprüft, dass jedes angeforderte
     * vollständig angekommen ist, und ihr Stand (Größe, Zeit) für den
     * nächsten Abgleich gemerkt.
     *
     * @return array{written: int, deleted: int, unchanged: int, buildPending: bool}
     */
    public function commit(string $syncId): array
    {
        $manifest = $this->loadManifest($syncId);
        $staging = $this->syncDir($syncId) . '/files';
        $sizes = is_array($manifest['sizes'] ?? null) ? $manifest['sizes'] : [];

        // Erst vollständig prüfen, dann schreiben: fehlt eine Datei, bleibt die
        // Webseite unberührt
        $missing = [];
        foreach ($manifest['needed'] as $path) {
            if (isset($sizes[$path])) {
                $abs = $this->mount->root() . '/' . $path;
                clearstatcache(true, $abs);
                if (!is_file($abs) || filesize($abs) !== (int) $sizes[$path]) {
                    $missing[] = $path;
                }
            } elseif (!is_file($staging . '/' . $path)) {
                $missing[] = $path;
            }
        }
        if ($missing !== []) {
            throw new ApiException('ECONFLICT', 409, 'SHOP-SYNC-INCOMPLETE', [count($missing), $missing[0]]);
        }

        $written = 0;
        foreach ($manifest['needed'] as $path) {
            if (isset($sizes[$path])) {
                // Medium: liegt schon an seinem Ort
                $written++;
                continue;
            }
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

        // Stand der Medien für den nächsten Abgleich (Größe, Zeit je Datei)
        $stat = [];
        foreach (array_keys($sizes) as $path) {
            $abs = $this->mount->root() . '/' . $path;
            clearstatcache(true, $abs);
            if (is_file($abs)) {
                $stat[$path] = [(int) filesize($abs), (int) filemtime($abs)];
            }
        }
        $this->writeJson($this->varDir . '/last-manifest.json', [
            'committed' => time(),
            'files' => $manifest['files'],
            'stat' => $stat,
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
        } elseif ($this->mediaArea($path) !== null) {
            // Medien: direkt in ihrer Freigabe, mit der Endung der Medienart —
            // geprüft in mediaArea(); vor dem Paket und den Produktseiten,
            // weil eine Medienfreigabe darin liegen darf
        } elseif (str_starts_with($path, self::PACKAGE_DIR . '/')) {
            // PHP nur an den festen Pfaden und nur mit hinterlegtem Schlüssel —
            // der Mount nimmt php dann zwar an, aber nicht an beliebiger Stelle.
            // Die Medienendungen nimmt der Mount ebenfalls an; im Paket gilt
            // trotzdem nur ACCEPT
            if ($extension === 'php') {
                if (!$this->signedPhp || !in_array($path, self::SIGNED_PHP, true)) {
                    throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
                }
            } elseif (!in_array($extension, self::ACCEPT, true)) {
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
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ACCEPT, true) && !in_array($extension, self::MEDIA_DOWNLOAD_EXT, true)) {
            throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
        }

        return $path;
    }

    /**
     * In welcher Medienfreigabe liegt ein Pfad? Nur direkt im Verzeichnis der
     * Freigabe (keine Unterverzeichnisse — die Artikel nennen nur Dateinamen)
     * und nur mit einer Endung der Medienart; eine andere Endung im
     * Verzeichnis einer Freigabe ist ein Fehler, kein „woanders".
     *
     * @return ?string images, thumbnails, downloads oder null (keine Medienfreigabe)
     */
    private function mediaArea(string $path): ?string
    {
        $dir = dirname($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inGrant = false;
        foreach (['images' => self::MEDIA_IMAGE_EXT, 'thumbnails' => self::MEDIA_IMAGE_EXT, 'downloads' => self::MEDIA_DOWNLOAD_EXT] as $area => $allowed) {
            if ($this->mediaGrants[$area] === null || $dir !== $this->mediaGrants[$area]) {
                continue;
            }
            if (in_array($extension, $allowed, true)) {
                return $area;
            }
            $inGrant = true;
        }
        if ($inGrant) {
            throw ApiException::denied('SHOP-FILETYPE-NOT-ALLOWED', [$path]);
        }

        return null;
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
