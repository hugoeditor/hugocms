<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Verwaltet alle Mounts und übersetzt zwischen den nach außen sichtbaren
 * IDs und echten Serverpfaden. Sperrt jeden aufgelösten Pfad in seinen
 * Mount ein (Schutz vor Directory-Traversal).
 *
 * ID-Format: base64url("<mount>:<relativer/pfad>"). Die Wurzel eines
 * Mounts ist "<mount>:".
 */
final class MountResolver
{
    /** @var array<string, Mount> */
    private array $mounts = [];

    public function add(Mount $mount): void
    {
        $name = $mount->name();
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw ApiException::badRequest("Ungültiger Mount-Name: {$name}");
        }
        if (isset($this->mounts[$name])) {
            throw ApiException::badRequest("Mount bereits vergeben: {$name}");
        }
        $this->mounts[$name] = $mount;
    }

    /** @return array<string, Mount> */
    public function all(): array
    {
        return $this->mounts;
    }

    public function get(string $name): Mount
    {
        return $this->mounts[$name]
            ?? throw ApiException::notFound("Unbekannter Mount: {$name}");
    }

    public function encodeId(string $mountName, string $relPath): string
    {
        $raw = $mountName . ':' . $relPath;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array{0: Mount, 1: string} Mount und bereinigter Relativpfad
     */
    public function decodeId(string $id): array
    {
        $raw = base64_decode(strtr($id, '-_', '+/'), true);
        if ($raw === false || !str_contains($raw, ':')) {
            throw ApiException::badRequest('Ungültige ID.');
        }

        [$mountName, $relPath] = explode(':', $raw, 2);

        return [$this->get($mountName), $this->sanitizeRelPath($relPath)];
    }

    /**
     * Löst eine ID in einen absoluten Serverpfad auf und stellt sicher,
     * dass er innerhalb des Mounts liegt.
     *
     * @param bool $mustExist true für vorhandene Ziele (read/list), false
     *                        für neu anzulegende (write/mkdir) — dann wird
     *                        das Elternverzeichnis geprüft.
     * @return array{mount: Mount, abs: string, rel: string}
     */
    public function resolve(string $id, bool $mustExist = true): array
    {
        [$mount, $rel] = $this->decodeId($id);
        $candidate = $rel === '' ? $mount->root() : $mount->root() . '/' . $rel;

        if ($mustExist) {
            $real = realpath($candidate);
            if ($real === false) {
                throw ApiException::notFound('Datei oder Verzeichnis nicht gefunden.');
            }
            $this->assertInside($mount, $real);

            return ['mount' => $mount, 'abs' => $real, 'rel' => $rel];
        }

        // Für neue Ziele: Elternverzeichnis muss existieren und im Mount liegen.
        $parentReal = realpath(dirname($candidate));
        if ($parentReal === false) {
            throw ApiException::notFound('Zielverzeichnis nicht gefunden.');
        }
        $this->assertInside($mount, $parentReal);

        $abs = $parentReal . '/' . basename($candidate);

        return ['mount' => $mount, 'abs' => $abs, 'rel' => $rel];
    }

    /**
     * Erzeugt die ID eines Kindes relativ zu einem aufgelösten Pfad.
     */
    public function childId(Mount $mount, string $parentRel, string $childName): string
    {
        $rel = $parentRel === '' ? $childName : $parentRel . '/' . $childName;

        return $this->encodeId($mount->name(), $rel);
    }

    private function assertInside(Mount $mount, string $real): void
    {
        $root = $mount->root();
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw ApiException::denied('Pfad liegt außerhalb des Mounts.');
        }
    }

    /**
     * Entfernt gefährliche Bestandteile aus einem Relativpfad.
     * Verbietet ".." und Steuerzeichen, normalisiert Trenner.
     */
    private function sanitizeRelPath(string $rel): string
    {
        if (str_contains($rel, "\0")) {
            throw ApiException::badRequest('Ungültiges Zeichen im Pfad.');
        }

        $rel = str_replace('\\', '/', $rel);
        $segments = [];
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw ApiException::denied('Übergeordnete Pfade sind nicht erlaubt.');
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
