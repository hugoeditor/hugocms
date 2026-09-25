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
 *
 * Das eigene backend/ (hugocms.ini, Benutzerkonten, Mount-Konfigurationen,
 * PHP-Code) ist für Mounts tabu: Ein Mount darf es weder enthalten noch darin
 * liegen. Sonst könnte ein Redakteur mit Lösch- und Hochladerecht die
 * Konfiguration austauschen und sich so z. B. Administratorrechte oder weitere
 * Editor-Endungen verschaffen.
 */
final class MountResolver
{
    /** @var array<string, Mount> */
    private array $mounts = [];

    /** Geschütztes Verzeichnis (realpath) oder null, falls nicht auflösbar. */
    private readonly ?string $protectedDir;

    /**
     * @param ?string $protectedDir Verzeichnis, das kein Mount berühren darf.
     *                              Standard: das backend/ dieser Installation.
     */
    public function __construct(?string $protectedDir = null)
    {
        $real = realpath($protectedDir ?? dirname(__DIR__));
        $this->protectedDir = $real === false ? null : $real;
    }

    public function add(Mount $mount): void
    {
        $name = $mount->name();
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            throw ApiException::badRequest('MOUNT-NAME-INVALID', [$name]);
        }
        if (isset($this->mounts[$name])) {
            throw ApiException::badRequest('MOUNT-NAME-TAKEN', [$name]);
        }
        // Nur den Mount-Namen melden, nie den Serverpfad.
        if ($this->protectedDir !== null
            && (self::isWithin($this->protectedDir, $mount->root()) || self::isWithin($mount->root(), $this->protectedDir))
        ) {
            throw ApiException::denied('MOUNT-PATH-PROTECTED', [$name]);
        }
        $this->mounts[$name] = $mount;
    }

    /** Liegt $path in $dir oder ist es $dir selbst? Beide als realpath. */
    private static function isWithin(string $path, string $dir): bool
    {
        return $dir === '/' || $path === $dir || str_starts_with($path, $dir . '/');
    }

    /** @return array<string, Mount> */
    public function all(): array
    {
        return $this->mounts;
    }

    public function get(string $name): Mount
    {
        return $this->mounts[$name]
            ?? throw ApiException::notFound('MOUNT-UNKNOWN', [$name]);
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
            throw ApiException::badRequest('ID-INVALID');
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
                throw ApiException::notFound('PATH-NOT-FOUND');
            }
            $this->assertInside($mount, $real);

            return ['mount' => $mount, 'abs' => $real, 'rel' => $rel];
        }

        // Für neue Ziele: Elternverzeichnis muss existieren und im Mount liegen.
        $parentReal = realpath(dirname($candidate));
        if ($parentReal === false) {
            throw ApiException::notFound('TARGET-DIR-NOT-FOUND');
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
            throw ApiException::denied('PATH-OUTSIDE-MOUNT');
        }
    }

    /**
     * Entfernt gefährliche Bestandteile aus einem Relativpfad.
     * Verbietet ".." und Steuerzeichen, normalisiert Trenner.
     */
    private function sanitizeRelPath(string $rel): string
    {
        if (str_contains($rel, "\0")) {
            throw ApiException::badRequest('PATH-INVALID-CHAR');
        }

        $rel = str_replace('\\', '/', $rel);
        $segments = [];
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw ApiException::denied('PARENT-PATH-NOT-ALLOWED');
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
