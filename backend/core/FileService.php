<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Führt die eigentlichen Dateioperationen aus. Bekommt ausschließlich
 * bereits aufgelöste und eingesperrte Pfade vom MountResolver.
 *
 * Stufe 1: list, read, write. Weitere Operationen folgen in Stufe 2.
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
            if ($name === '.' || $name === '..') {
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
    public function writeText(Mount $mount, string $rel, string $abs, string $content): array
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
