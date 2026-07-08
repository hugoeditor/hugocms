<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Führt die eigentlichen Dateioperationen aus. Bekommt ausschließlich
 * bereits aufgelöste und eingesperrte Pfade vom MountResolver.
 *
 * Stufe 1: list, read, write.
 * Stufe 2: makeDir, makeFile, rename, trash (Papierkorb), copy, move.
 * Stufe 3: storeUpload; Auslieferung (download/raw/thumb) macht der Connector.
 *
 * Versteckte Einträge (Punkt-Dateien, inkl. dem Papierkorb .trash) werden in
 * Listen ausgeblendet — wie der Standard von Nemo.
 */
final class FileService
{
    /** Höchstzahl an Suchtreffern (Schutz vor Riesentreffermengen). */
    public const SEARCH_LIMIT = 200;

    /** Obergrenze besuchter Einträge je Suche (Schutz vor Endlos-Bäumen). */
    private const SEARCH_VISIT_LIMIT = 20_000;

    /** @var list<string> Endungen, die der Texteditor öffnen darf. */
    private array $editable;

    public function __construct(
        private readonly MountResolver $resolver,
        array $editable = ['html', 'htm', 'md', 'markdown', 'txt', 'css', 'js', 'json', 'xml', 'yaml', 'yml', 'svg', 'toml'],
        private readonly int $maxEditableBytes = 5_242_880, // 5 MiB
        private readonly int $maxUploadBytes = 52_428_800, // 50 MiB
    ) {
        $this->editable = array_map('strtolower', $editable);
    }

    /**
     * Listet den Inhalt eines Verzeichnisses als Metadaten-Einträge.
     *
     * @return array<int, array>
     */
    public function listDir(Mount $mount, string $rel, string $abs): array
    {
        if (!is_dir($abs)) {
            throw ApiException::badRequest('NOT-A-DIRECTORY');
        }

        $entries = [];
        foreach (scandir($abs) ?: [] as $name) {
            // Punkt-Einträge ausblenden (.,.., Punkt-Dateien und der .trash).
            if ($name[0] === '.') {
                continue;
            }
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            $entries[] = $this->entryInfo($mount, $childRel, $abs . '/' . $name);
        }

        // Verzeichnisse zuerst, dann alphabetisch.
        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    /**
     * Liest eine Textdatei für den Editor.
     *
     * @return array{name: string, content: string, size: int, mtime: int}
     */
    public function readText(Mount $mount, string $abs): array
    {
        if (!is_file($abs)) {
            throw ApiException::notFound('FILE-NOT-FOUND');
        }
        $name = basename($abs);
        if (!$this->isEditable($name)) {
            throw ApiException::denied('FILETYPE-NOT-EDITABLE');
        }
        $size = filesize($abs);
        if ($size !== false && $size > $this->maxEditableBytes) {
            throw ApiException::denied('FILE-TOO-LARGE');
        }

        $content = file_get_contents($abs);
        if ($content === false) {
            throw new ApiException('EIO', 500, 'FILE-READ-FAILED');
        }

        return [
            'name' => $name,
            'content' => $content,
            'size' => (int) ($size ?: 0),
            'mtime' => (int) filemtime($abs),
        ];
    }

    /**
     * Schreibt eine Textdatei (atomar über temporäre Datei + rename).
     *
     * @return array Metadaten der gespeicherten Datei
     */
    public function writeText(Mount $mount, string $rel, string $abs, string $content, ?int $expectedMtime = null): array
    {
        $name = basename($abs);
        if (!$this->isEditable($name)) {
            throw ApiException::denied('FILETYPE-NOT-SAVABLE');
        }
        if (!$mount->accepts($name)) {
            throw ApiException::denied('FILETYPE-NOT-ALLOWED-MOUNT');
        }
        if (strlen($content) > $this->maxEditableBytes) {
            throw ApiException::denied('CONTENT-TOO-LARGE');
        }

        // Konfliktschutz: Erwartet der Client einen bestimmten Stand (mtime
        // beim Öffnen), wird bei zwischenzeitlicher externer Änderung oder
        // gelöschter/verschobener Datei abgelehnt statt überschrieben.
        if ($expectedMtime !== null) {
            clearstatcache(true, $abs);
            $current = @filemtime($abs);
            if ($current === false || $current !== $expectedMtime) {
                throw new ApiException('ECONFLICT', 409, 'CONFLICT-MTIME');
            }
        }

        $dir = dirname($abs);
        $tmp = @tempnam($dir, '.hugofm');
        if ($tmp === false) {
            throw new ApiException('EIO', 500, 'TEMPFILE-FAILED');
        }
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $abs)) {
            @unlink($tmp);
            throw new ApiException('EIO', 500, 'FILE-SAVE-FAILED');
        }
        @chmod($abs, 0644);
        clearstatcache(true, $abs);

        return $this->entryInfo($mount, $rel, $abs);
    }

    // --- Stufe 2: anlegen, umbenennen, kopieren, verschieben, löschen -------

    /** Legt einen Unterordner in einem (aufgelösten) Verzeichnis an. */
    public function makeDir(Mount $mount, string $parentRel, string $parentAbs, string $name): array
    {
        self::assertValidName($name);
        $abs = $parentAbs . '/' . $name;
        if (file_exists($abs)) {
            throw ApiException::badRequest('ALREADY-EXISTS', [$name]);
        }
        if (!@mkdir($abs, 0775)) {
            throw new ApiException('EIO', 500, 'MKDIR-FAILED');
        }
        clearstatcache(true, $abs);

        return $this->entryInfo($mount, self::childRel($parentRel, $name), $abs);
    }

    /** Legt eine leere Datei an (nur von der accept-Liste erlaubte Endungen). */
    public function makeFile(Mount $mount, string $parentRel, string $parentAbs, string $name): array
    {
        self::assertValidName($name);
        if (!$mount->accepts($name)) {
            throw ApiException::denied('FILETYPE-NOT-ALLOWED-MOUNT');
        }
        $abs = $parentAbs . '/' . $name;
        if (file_exists($abs)) {
            throw ApiException::badRequest('ALREADY-EXISTS', [$name]);
        }
        if (@file_put_contents($abs, '') === false) {
            throw new ApiException('EIO', 500, 'CREATE-FAILED');
        }
        @chmod($abs, 0644);
        clearstatcache(true, $abs);

        return $this->entryInfo($mount, self::childRel($parentRel, $name), $abs);
    }

    /** Benennt einen Eintrag im selben Verzeichnis um. */
    public function rename(Mount $mount, string $rel, string $abs, string $newName): array
    {
        self::assertValidName($newName);
        $parentAbs = dirname($abs);
        $newAbs = $parentAbs . '/' . $newName;
        if ($newAbs === $abs) {
            return $this->entryInfo($mount, $rel, $abs); // unverändert
        }
        if (file_exists($newAbs)) {
            throw ApiException::badRequest('ALREADY-EXISTS', [$newName]);
        }
        if (is_file($abs) && !$mount->accepts($newName)) {
            throw ApiException::denied('FILETYPE-NOT-ALLOWED-MOUNT');
        }
        if (!@rename($abs, $newAbs)) {
            throw new ApiException('EIO', 500, 'RENAME-FAILED');
        }
        clearstatcache(true, $newAbs);

        return $this->entryInfo($mount, self::childRel(self::parentRel($rel), $newName), $newAbs);
    }

    /**
     * Verschiebt einen Eintrag in den Papierkorb des Mounts. Aufbau (an die
     * XDG-Trash-Spezifikation angelehnt): die Daten liegen unter
     * .trash/files/<name>, der Ursprung in .trash/info/<name>.json — damit
     * ist das Wiederherstellen am Ursprungsort möglich.
     */
    public function trash(Mount $mount, string $rel, string $abs): void
    {
        $filesDir = $mount->root() . '/.trash/files';
        $infoDir = $mount->root() . '/.trash/info';
        if ((!is_dir($filesDir) && !@mkdir($filesDir, 0775, true))
            || (!is_dir($infoDir) && !@mkdir($infoDir, 0775, true))) {
            throw new ApiException('EIO', 500, 'DELETE-FAILED');
        }

        $name = basename($abs);
        $trashName = $name;
        if (file_exists("{$filesDir}/{$trashName}")) {
            $trashName = $name . '.' . uniqid();
        }
        if (!@rename($abs, "{$filesDir}/{$trashName}")) {
            throw new ApiException('EIO', 500, 'DELETE-FAILED');
        }
        @file_put_contents("{$infoDir}/{$trashName}.json", json_encode([
            'name' => $name,
            'origRel' => $rel,
            'deletedAt' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        clearstatcache();
    }

    /**
     * Listet den Papierkorb eines Mounts (neueste zuerst sortiert der Aufrufer).
     *
     * @return list<array>
     */
    public function listTrash(Mount $mount): array
    {
        $filesDir = $mount->root() . '/.trash/files';
        $infoDir = $mount->root() . '/.trash/info';
        if (!is_dir($filesDir)) {
            return [];
        }

        $out = [];
        foreach (scandir($filesDir) ?: [] as $trashName) {
            if ($trashName === '.' || $trashName === '..') {
                continue;
            }
            $abs = "{$filesDir}/{$trashName}";
            $meta = json_decode((string) @file_get_contents("{$infoDir}/{$trashName}.json"), true);
            $meta = is_array($meta) ? $meta : [];
            $out[] = [
                'mount' => $mount->name(),
                'trashName' => $trashName,
                'name' => (string) ($meta['name'] ?? $trashName),
                'origRel' => (string) ($meta['origRel'] ?? ''),
                'deletedAt' => (int) ($meta['deletedAt'] ?? filemtime($abs) ?: 0),
                'type' => is_dir($abs) ? 'dir' : 'file',
                'size' => is_dir($abs) ? 0 : (int) (filesize($abs) ?: 0),
            ];
        }

        return $out;
    }

    /** Stellt einen Papierkorb-Eintrag am Ursprungsort wieder her. */
    public function restoreFromTrash(Mount $mount, string $trashName): array
    {
        if ($trashName === '' || basename($trashName) !== $trashName || $trashName[0] === '.') {
            throw ApiException::badRequest('INVALID-NAME', [$trashName]);
        }
        $src = $mount->root() . '/.trash/files/' . $trashName;
        $infoFile = $mount->root() . '/.trash/info/' . $trashName . '.json';
        if (!file_exists($src)) {
            throw ApiException::notFound('TRASH-NOT-FOUND');
        }

        $meta = json_decode((string) @file_get_contents($infoFile), true);
        $meta = is_array($meta) ? $meta : [];
        $name = basename((string) ($meta['name'] ?? $trashName));
        $origRel = (string) ($meta['origRel'] ?? '');

        // Ursprungs-Elternpfad bereinigen (stammt aus unserer info-Datei,
        // wird aber trotzdem nicht blind übernommen).
        $parentSegments = [];
        foreach (explode('/', self::parentRel($origRel)) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            $parentSegments[] = $seg;
        }
        $parentRel = implode('/', $parentSegments);
        $parentAbs = $parentRel === '' ? $mount->root() : $mount->root() . '/' . $parentRel;

        if (!is_dir($parentAbs) && !@mkdir($parentAbs, 0775, true)) {
            throw new ApiException('EIO', 500, 'RESTORE-FAILED');
        }
        // Containment nach dem Anlegen prüfen.
        $parentReal = realpath($parentAbs);
        if ($parentReal === false
            || ($parentReal !== $mount->root() && !str_starts_with($parentReal, $mount->root() . DIRECTORY_SEPARATOR))) {
            throw ApiException::denied('PATH-OUTSIDE-MOUNT');
        }

        $dest = $this->uniqueTarget($parentReal, $name, numbered: true);
        if (!@rename($src, $dest)) {
            throw new ApiException('EIO', 500, 'RESTORE-FAILED');
        }
        @unlink($infoFile);
        clearstatcache(true, $dest);

        return $this->entryInfo($mount, self::childRel($parentRel, basename($dest)), $dest);
    }

    /** Leert den Papierkorb eines Mounts endgültig; liefert die Anzahl der Einträge. */
    public function emptyTrash(Mount $mount): int
    {
        $base = $mount->root() . '/.trash';
        $count = 0;
        foreach (scandir("{$base}/files") ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->recursiveDelete("{$base}/files/{$item}");
            $count++;
        }
        if (is_dir("{$base}/info")) {
            foreach (scandir("{$base}/info") ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink("{$base}/info/{$item}");
            }
        }
        clearstatcache();

        return $count;
    }

    /** Kopiert einen Eintrag in ein Zielverzeichnis (Name ggf. „(Kopie)“). */
    public function copy(Mount $mount, string $srcAbs, string $destParentRel, string $destParentAbs): array
    {
        self::assertNotInsideSelf($srcAbs, $destParentAbs);
        $targetAbs = $this->uniqueTarget($destParentAbs, basename($srcAbs));
        if (!$this->recursiveCopy($srcAbs, $targetAbs)) {
            // Halb kopierte Reste entfernen, dann melden.
            $this->recursiveDelete($targetAbs);
            throw new ApiException('EIO', 500, 'COPY-FAILED');
        }
        clearstatcache(true, $targetAbs);

        return $this->entryInfo($mount, self::childRel($destParentRel, basename($targetAbs)), $targetAbs);
    }

    /** Verschiebt einen Eintrag in ein Zielverzeichnis (gleicher Mount). */
    public function move(Mount $mount, string $srcRel, string $srcAbs, string $destParentRel, string $destParentAbs): array
    {
        self::assertNotInsideSelf($srcAbs, $destParentAbs);
        $name = basename($srcAbs);
        if (dirname($srcAbs) === $destParentAbs) {
            return $this->entryInfo($mount, $srcRel, $srcAbs); // schon am Ziel
        }
        $targetAbs = $destParentAbs . '/' . $name;
        if (file_exists($targetAbs)) {
            throw ApiException::badRequest('ALREADY-EXISTS', [$name]);
        }
        if (!@rename($srcAbs, $targetAbs)) {
            throw new ApiException('EIO', 500, 'MOVE-FAILED');
        }
        clearstatcache(true, $targetAbs);

        return $this->entryInfo($mount, self::childRel($destParentRel, $name), $targetAbs);
    }

    // --- Stufe 3: Upload -----------------------------------------------------

    /**
     * Übernimmt eine hochgeladene Datei (ein Eintrag aus $_FILES) in ein
     * (aufgelöstes) Zielverzeichnis. Bei Namenskollision wird nummeriert
     * („name (2).ext“) — es wird nie stillschweigend überschrieben.
     *
     * @param array{name?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file
     */
    public function storeUpload(Mount $mount, string $parentRel, string $parentAbs, array $file): array
    {
        $name = basename((string) ($file['name'] ?? ''));
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ApiException::badRequest('UPLOAD-TOO-LARGE', [$name, self::humanBytes($this->maxUploadBytes)]);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ApiException('EIO', 500, 'UPLOAD-FAILED', [$name]);
        }
        self::assertValidName($name);
        if (!$mount->accepts($name)) {
            throw ApiException::denied('FILETYPE-NOT-ALLOWED-MOUNT');
        }
        if ((int) ($file['size'] ?? 0) > $this->maxUploadBytes) {
            throw ApiException::badRequest('UPLOAD-TOO-LARGE', [$name, self::humanBytes($this->maxUploadBytes)]);
        }

        $dest = $this->uniqueTarget($parentAbs, $name, numbered: true);
        if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $dest)) {
            throw new ApiException('EIO', 500, 'UPLOAD-FAILED', [$name]);
        }
        @chmod($dest, 0644);
        clearstatcache(true, $dest);

        return $this->entryInfo($mount, self::childRel($parentRel, basename($dest)), $dest);
    }

    /** MIME-Typ einer Datei (für die Auslieferung durch den Connector). */
    public function mimeOf(string $abs): string
    {
        return $this->detectMime($abs);
    }

    /**
     * Erlaubte Rasterbild-Typen für den Bild-Editor. SVG bleibt bewusst außen
     * vor — es wird als Text über read/write bearbeitet, nicht neu kodiert.
     *
     * @var list<string>
     */
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Speichert im Bild-Editor bearbeitete Rasterbild-Rohdaten. Bewusst getrennt
     * von writeText(): keine isEditable-Prüfung (Bilder sind keine Editortypen),
     * dafür eine MIME-Whitelist auf den TATSÄCHLICHEN Bytes und die Upload-
     * Größengrenze. Geschrieben wird atomar (tempnam + rename).
     *
     * @param string $mode 'overwrite' ersetzt die Zieldatei; 'copy' legt im
     *                      selben Verzeichnis eine neue Datei an (Kollision wird
     *                      nummeriert). Bei 'copy' bestimmt $copyName den Namen.
     * @return array Metadaten der gespeicherten Datei
     */
    public function writeImage(
        Mount $mount,
        string $rel,
        string $abs,
        string $binary,
        string $mode = 'overwrite',
        ?string $copyName = null,
    ): array {
        if (!is_file($abs)) {
            throw ApiException::notFound('FILE-NOT-FOUND');
        }
        if (strlen($binary) > $this->maxUploadBytes) {
            throw ApiException::denied('CONTENT-TOO-LARGE');
        }
        // MIME aus den Rohdaten prüfen (nicht aus der Endung) — so lässt sich
        // kein beliebiger Inhalt unter einer Bildendung einschleusen.
        $mime = $this->detectMimeString($binary);
        if (!in_array($mime, self::IMAGE_MIME, true)) {
            throw ApiException::denied('FILETYPE-NOT-IMAGE');
        }

        if ($mode === 'copy') {
            $parentAbs = dirname($abs);
            $name = $copyName !== null && $copyName !== '' ? basename($copyName) : basename($abs);
            self::assertValidName($name);
            if (!$mount->accepts($name)) {
                throw ApiException::denied('FILETYPE-NOT-ALLOWED-MOUNT');
            }
            $destAbs = $this->uniqueTarget($parentAbs, $name, numbered: true);
            $destRel = self::childRel(self::parentRel($rel), basename($destAbs));
        } elseif ($mode === 'overwrite') {
            if (!$mount->accepts(basename($abs))) {
                throw ApiException::denied('FILETYPE-NOT-ALLOWED-MOUNT');
            }
            $destAbs = $abs;
            $destRel = $rel;
        } else {
            throw ApiException::badRequest('PARAM-INVALID', ['mode']);
        }

        $tmp = @tempnam(dirname($destAbs), '.hugofm');
        if ($tmp === false) {
            throw new ApiException('EIO', 500, 'TEMPFILE-FAILED');
        }
        if (@file_put_contents($tmp, $binary) === false || !@rename($tmp, $destAbs)) {
            @unlink($tmp);
            throw new ApiException('EIO', 500, 'FILE-SAVE-FAILED');
        }
        @chmod($destAbs, 0644);
        clearstatcache(true, $destAbs);

        return $this->entryInfo($mount, $destRel, $destAbs);
    }

    // --- Stufe 4: Suche ------------------------------------------------------

    /**
     * Rekursive Namenssuche (Teilstring, ohne Groß-/Kleinschreibung) ab einem
     * Verzeichnis. Versteckte Einträge (inkl. .trash) bleiben außen vor. Die
     * Treffer tragen zusätzlich "path" — den Pfad relativ zur Suchwurzel.
     *
     * @return list<array>
     */
    public function search(Mount $mount, string $rootRel, string $rootAbs, string $query): array
    {
        $results = [];
        $visited = 0;
        // Iterativ mit Stapel: [relativ zur Suchwurzel, absolut]
        $stack = [['', $rootAbs]];

        while ($stack !== [] && count($results) < self::SEARCH_LIMIT && $visited < self::SEARCH_VISIT_LIMIT) {
            [$dirPath, $dirAbs] = array_pop($stack);
            foreach (scandir($dirAbs) ?: [] as $name) {
                if ($name === '' || $name[0] === '.') {
                    continue;
                }
                $visited++;
                $childPath = $dirPath === '' ? $name : $dirPath . '/' . $name;
                $childAbs = $dirAbs . '/' . $name;

                if (mb_stripos($name, $query) !== false) {
                    $rel = $rootRel === '' ? $childPath : $rootRel . '/' . $childPath;
                    $entry = $this->entryInfo($mount, $rel, $childAbs);
                    $entry['path'] = $childPath;
                    $results[] = $entry;
                    if (count($results) >= self::SEARCH_LIMIT) {
                        break;
                    }
                }
                if (is_dir($childAbs) && !is_link($childAbs)) {
                    $stack[] = [$childPath, $childAbs];
                }
            }
        }

        return $results;
    }

    // --- Hilfen ------------------------------------------------------------

    /** Lesbare Größenangabe für Fehlermeldungen (sprachneutral). */
    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }

    /** Prüft einen neuen Datei-/Ordnernamen (keine Trenner, Punkt-Dateien, …). */
    private static function assertValidName(string $name): void
    {
        if (
            $name === '' || $name === '.' || $name === '..'
            || strlen($name) > 255
            || $name[0] === '.' // versteckte Einträge nicht anlegbar
            || preg_match('#[/\\\\\x00-\x1f]#', $name) === 1
        ) {
            throw ApiException::badRequest('INVALID-NAME', [$name]);
        }
    }

    private static function childRel(string $parentRel, string $name): string
    {
        return $parentRel === '' ? $name : $parentRel . '/' . $name;
    }

    private static function parentRel(string $rel): string
    {
        $pos = strrpos($rel, '/');

        return $pos === false ? '' : substr($rel, 0, $pos);
    }

    /** Verhindert, ein Verzeichnis in sich selbst zu kopieren/verschieben. */
    private static function assertNotInsideSelf(string $srcAbs, string $destParentAbs): void
    {
        if ($destParentAbs === $srcAbs || str_starts_with($destParentAbs . '/', $srcAbs . '/')) {
            throw ApiException::badRequest('CANNOT-MOVE-INTO-SELF');
        }
    }

    /**
     * Liefert einen freien Zielpfad (Endung bleibt erhalten). Bei Kollision:
     * Kopien erhalten „ (Kopie)“/„ (Kopie 2)“, Uploads (numbered) „ (2)“/„ (3)“.
     */
    private function uniqueTarget(string $destParentAbs, string $name, bool $numbered = false): string
    {
        $candidate = $destParentAbs . '/' . $name;
        if (!file_exists($candidate)) {
            return $candidate;
        }
        $dot = strrpos($name, '.');
        $hasExt = $dot !== false && $dot > 0;
        $stem = $hasExt ? substr($name, 0, $dot) : $name;
        $ext = $hasExt ? substr($name, $dot) : '';
        for ($i = 1; $i < 1000; $i++) {
            $suffix = $numbered
                ? ' (' . ($i + 1) . ')'
                : ($i === 1 ? ' (Kopie)' : " (Kopie {$i})");
            $candidate = $destParentAbs . '/' . $stem . $suffix . $ext;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
        throw new ApiException('EIO', 500, 'COPY-FAILED');
    }

    private function recursiveCopy(string $src, string $dst): bool
    {
        if (is_dir($src)) {
            if (!@mkdir($dst, 0775)) {
                return false;
            }
            foreach (scandir($src) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (!$this->recursiveCopy($src . '/' . $item, $dst . '/' . $item)) {
                    return false;
                }
            }
            return true;
        }

        return @copy($src, $dst);
    }

    private function recursiveDelete(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->recursiveDelete($path . '/' . $item);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }

    /**
     * Einheitliche Metadaten eines Eintrags für das Frontend.
     */
    public function entryInfo(Mount $mount, string $rel, string $abs): array
    {
        $isDir = is_dir($abs);
        $name = basename($abs);
        $mime = $isDir ? 'directory' : $this->detectMime($abs);

        return [
            'id' => $this->resolver->encodeId($mount->name(), $rel),
            'name' => $name,
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? 0 : (int) (filesize($abs) ?: 0),
            'mtime' => (int) (filemtime($abs) ?: 0),
            'ctime' => (int) (filectime($abs) ?: 0),
            'mime' => $mime,
            'editable' => !$isDir && $this->isEditable($name),
            'image' => !$isDir && str_starts_with($mime, 'image/'),
        ];
    }

    private function isEditable(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->editable, true);
    }

    private function detectMime(string $abs): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_file($finfo, $abs);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }

    /** Wie detectMime(), aber auf einem Bytepuffer (Bild-Rohdaten im Speicher). */
    private function detectMimeString(string $data): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_buffer($finfo, $data);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }
}
