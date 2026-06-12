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
 *
 * Versteckte Einträge (Punkt-Dateien, inkl. dem Papierkorb .trash) werden in
 * Listen ausgeblendet — wie der Standard von Nemo.
 */
final class FileService
{
    /** @var list<string> Endungen, die der Texteditor öffnen darf. */
    private array $editable;

    public function __construct(
        private readonly MountResolver $resolver,
        array $editable = ['html', 'htm', 'md', 'markdown', 'txt', 'css', 'js', 'json', 'xml', 'yaml', 'yml', 'svg', 'toml'],
        private readonly int $maxEditableBytes = 5_242_880, // 5 MiB
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

    /** Verschiebt einen Eintrag in den Papierkorb (.trash) des Mounts. */
    public function trash(Mount $mount, string $abs): void
    {
        $trashDir = $mount->root() . '/.trash';
        if (!is_dir($trashDir) && !@mkdir($trashDir, 0775)) {
            throw new ApiException('EIO', 500, 'DELETE-FAILED');
        }
        $dest = $trashDir . '/' . basename($abs);
        if (file_exists($dest)) {
            $dest .= '.' . uniqid();
        }
        if (!@rename($abs, $dest)) {
            throw new ApiException('EIO', 500, 'DELETE-FAILED');
        }
        clearstatcache();
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

    // --- Hilfen ------------------------------------------------------------

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

    /** Liefert einen freien Zielpfad; bei Kollision „(Kopie)“ anfügen (Endung erhalten). */
    private function uniqueTarget(string $destParentAbs, string $name): string
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
            $suffix = $i === 1 ? ' (Kopie)' : " (Kopie {$i})";
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
}
