<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Verzeichnisauswahl für die Orte-Verwaltung (Mount-Pfade).
 *
 * Ein Browser kann kein Verzeichnis auf dem Server auswählen — seine
 * Dateiauswahl meint immer den Rechner des Benutzers. Deshalb listet der
 * Server hier selbst auf, und der Auswahldialog navigiert darin. Nach dem
 * Vorbild von OpensourceERP (backend/api/lib/directory_browser.php).
 *
 * Nur für Administratoren (der Connector prüft config.manage), weil die
 * Auflistung die Struktur des Servers preisgibt. Sichtbar ist nur, was
 * unterhalb eines Einstiegspunkts liegt:
 *
 *   - Steht [system] browse_roots in der hugocms.ini, gilt allein diese Liste
 *     (kommagetrennt) — so lässt sich die Sicht auf einem gemeinsam genutzten
 *     Server beschneiden.
 *   - Sonst werden die Einstiegspunkte abgeleitet: das Release-Verzeichnis und
 *     sein Elternverzeichnis, das Hugo-Projekt der Webseite, die
 *     Elternverzeichnisse der vorhandenen Mounts sowie /srv, /var/www, /mnt
 *     und /media, soweit vorhanden.
 *
 * Eine Wurzel, die unter einer anderen liegt, fällt weg. Versteckte
 * Verzeichnisse (Punkt am Anfang) blendet die Auflistung aus.
 */
final class DirectoryBrowser
{
    /** Mehr Einträge zeigt ein Verzeichnis nicht; darüber meldet die Antwort `truncated`. */
    private const MAX_ENTRIES = 500;

    /** @var list<string> */
    private readonly array $roots;

    /**
     * @param list<string> $configured [system] browse_roots (leer = ableiten)
     * @param list<string> $hints      weitere Kandidaten für die Ableitung
     *                                 (Hugo-Projekt, vorhandene Mounts)
     */
    public function __construct(
        array $configured,
        array $hints,
        private readonly MountResolver $resolver,
    ) {
        $this->roots = self::rootDirs($configured, $hints);
    }

    /** @return list<string> */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * Löst einen Pfad auf und prüft ihn gegen die Einstiegspunkte. realpath()
     * folgt Symlinks; ein Symlink aus dem erlaubten Bereich heraus scheitert
     * deshalb an der Prüfung.
     *
     * @param string $path leer = erste Wurzel
     */
    public function resolve(string $path): string
    {
        if ($this->roots === []) {
            throw ApiException::denied('BROWSE-NO-ROOTS');
        }
        if (trim($path) === '') {
            return $this->roots[0];
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw ApiException::notFound('BROWSE-PATH-NOT-FOUND', [$path]);
        }
        if ($this->rootOf($real) === null) {
            throw ApiException::denied('BROWSE-PATH-DENIED', [$real]);
        }

        return $real;
    }

    /**
     * Unterverzeichnisse eines Verzeichnisses. Dateien werden nur gezählt —
     * sonst hieße ein Verzeichnis ohne Unterverzeichnisse „leer“, obwohl
     * Dateien darin liegen.
     *
     * @return array<string, mixed>
     */
    public function list(string $path): array
    {
        $current = $this->resolve($path);
        $root = $this->rootOf($current) ?? $this->roots[0];

        $names = @scandir($current);
        if ($names === false) {
            throw ApiException::denied('BROWSE-NOT-READABLE', [$current]);
        }
        natcasesort($names);

        $entries = [];
        $fileCount = 0;
        $truncated = false;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $full = $current . '/' . $name;
            if (!is_dir($full)) {
                $fileCount++;
                continue;
            }
            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }
            $entries[] = [
                'name' => $name,
                'path' => $full,
                'writable' => is_writable($full),
            ];
        }

        return [
            'roots' => $this->roots,
            'root' => $root,
            'path' => $current,
            'parent' => $current === $root ? null : dirname($current),
            'writable' => is_writable($current),
            // Das eigene backend/ (und alles, was es enthält) darf kein Ort
            // werden — der Dialog bietet die Übernahme dann nicht an.
            'selectable' => !$this->resolver->isProtected($current),
            'entries' => array_values($entries),
            'fileCount' => $fileCount,
            'truncated' => $truncated,
        ];
    }

    /** Die Wurzel, unter der $real liegt, oder null. */
    private function rootOf(string $real): ?string
    {
        foreach ($this->roots as $root) {
            if ($real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/')) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @param list<string> $configured
     * @param list<string> $hints
     * @return list<string>
     */
    private static function rootDirs(array $configured, array $hints): array
    {
        if ($configured !== []) {
            $candidates = $configured;
        } else {
            // Release-Verzeichnis (über backend/) und sein Elternverzeichnis:
            // damit ist auch ein Hugo-Projekt neben der Installation erreichbar.
            $release = realpath(dirname(__DIR__, 2));
            $candidates = $release !== false ? [$release, dirname($release)] : [];
            foreach ($hints as $hint) {
                $candidates[] = $hint;
                $candidates[] = dirname($hint);
            }
            array_push($candidates, '/srv', '/var/www', '/mnt', '/media');
        }

        // Auflösen, Vorhandene behalten, Doppelte entfernen.
        $roots = [];
        foreach ($candidates as $candidate) {
            $real = trim((string) $candidate) === '' ? false : realpath((string) $candidate);
            if ($real !== false && is_dir($real) && is_readable($real) && !in_array($real, $roots, true)) {
                $roots[] = $real;
            }
        }

        // Verschachtelte entfernen: Liegt eine Wurzel unter einer anderen, ist
        // sie über diese ohnehin erreichbar.
        $result = [];
        foreach ($roots as $root) {
            $nested = false;
            foreach ($roots as $other) {
                if ($other !== $root && str_starts_with($root . '/', rtrim($other, '/') . '/')) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $result[] = $root;
            }
        }
        sort($result);

        return $result;
    }
}
