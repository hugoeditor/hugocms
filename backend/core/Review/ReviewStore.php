<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Review;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Persistiert offene Entwürfe der gestaffelten Veröffentlichung — je Datei
 * einen. Ein Entwurf hält den vollständigen vorgeschlagenen Dateiinhalt fest,
 * bis ein Benutzer ihn rezensiert (freigibt oder verwirft). Die Live-Datei
 * bleibt bis zur Freigabe unangetastet; der Entwurf berührt den Hugo-Content-
 * Baum nie und wird daher auch nie versehentlich gebaut.
 *
 * Analog zu {@see \HugoCMS\FileManager\Audit\ContentQualityService}: JSON — eine
 * Datei je Entwurf — im Speicherverzeichnis (keine Datenbank), atomar über
 * Tempdatei + rename. Kein langlaufender Zustand, hosting-tauglich.
 *
 * Reiner Speicher: Auflösung der Dateimanager-ID (Mount-Einsperrung) und das
 * Schreiben der freigegebenen Datei macht der Aufrufer über
 * {@see \HugoCMS\FileManager\MountResolver}/{@see \HugoCMS\FileManager\FileService}.
 */
final class ReviewStore
{
    /** Erlaubtes Format eines Eintragsschlüssels (sha1). */
    private const string KEY_PATTERN = '/^[a-f0-9]{40}$/';

    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * Offene Entwürfe, neueste zuerst. Der schwere Feldwert (proposedContent)
     * wird für die Liste weggelassen — er ist nur über {@see get()} nötig.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->files() as $path) {
            $entry = $this->read($path);
            if ($entry === null) {
                continue;
            }
            unset($entry['proposedContent']);
            $out[] = $entry;
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));

        return $out;
    }

    /**
     * Vollständiger Entwurf (inkl. proposedContent).
     *
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $entry = $this->read($this->pathFor($key));
        if ($entry === null) {
            throw ApiException::notFound('REVIEW-NOT-FOUND', [$key]);
        }

        return $entry;
    }

    /** Entwurf zum Schlüssel oder null, ohne Ausnahme. */
    public function forKey(string $key): ?array
    {
        return $this->read($this->pathFor($key));
    }

    /**
     * Legt einen Entwurf ab bzw. überschreibt den vorhandenen offenen Entwurf
     * derselben Datei. Pflichtfeld: 'key' (sha1).
     *
     * @param array<string, mixed> $entry
     */
    public function put(array $entry): void
    {
        $key = (string) ($entry['key'] ?? '');
        $path = $this->pathFor($key);

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new ApiException('EIO', 500, 'REVIEW-STORAGE-FAILED');
        }
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new ApiException('EIO', 500, 'REVIEW-STORAGE-FAILED');
        }
        // Atomar: erst in eine Tempdatei im selben Verzeichnis, dann umbenennen.
        $tmp = @tempnam($this->storageDir, '.rev');
        if ($tmp === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            throw new ApiException('EIO', 500, 'REVIEW-STORAGE-FAILED');
        }
    }

    /** Entfernt einen Entwurf. Fehlender Entwurf ist kein Fehler. */
    public function delete(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Bildet den Eintragsschlüssel aus Mount-Name und relativem Pfad. */
    public static function keyFor(string $mount, string $rel): string
    {
        return sha1($mount . ':' . $rel);
    }

    // --- Speicher -----------------------------------------------------------

    /** @return list<string> */
    private function files(): array
    {
        if (!is_dir($this->storageDir)) {
            return [];
        }

        return array_values(glob($this->storageDir . '/*.json') ?: []);
    }

    private function pathFor(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw ApiException::notFound('REVIEW-NOT-FOUND', [$key]);
        }

        return $this->storageDir . '/' . $key . '.json';
    }

    /** @return array<string, mixed>|null */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
