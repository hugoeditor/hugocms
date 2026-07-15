<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

use DOMDocument;

/**
 * Liest die LOKALE sitemap.xml (bzw. sitemapindex.xml) aus dem gebauten
 * public/-Ordner und liefert die darin verzeichneten Seiten als interne
 * Request-Pfade. Grundlage für den Erreichbarkeitslauf des Audits
 * ({@see AuditRunner}): geprüft wird nur, was in der Sitemap steht oder von dort
 * aus intern verlinkt ist.
 *
 * Bewusst nur DATEIEN, kein HTTP: Die Sitemap wird aus public/ gelesen, nie
 * über das Netz geholt — das erhält die Hosting-Kompatibilität. Ein
 * sitemapindex verweist auf Kind-Sitemaps, die ihrerseits als lokale Dateien
 * unter public/ aufgelöst und gegen Ausbrüche (..) abgesichert werden. Reine
 * PHP-Bordmittel (DOMDocument/libxml), keine externen Abhängigkeiten.
 */
final class Sitemap
{
    /** Übliche Dateinamen der Sitemap im public/-Wurzelverzeichnis. */
    private const array FILENAMES = ['sitemap.xml', 'sitemapindex.xml'];

    /** Obergrenze der eingelesenen URLs (Schutz vor Speicherüberlauf). */
    private const int MAX_URLS = 50000;

    /** Obergrenze der aus einem sitemapindex verfolgten Kind-Sitemaps. */
    private const int MAX_CHILDREN = 2000;

    /**
     * Liegt im public/-Ordner überhaupt eine Sitemap? Steuert den Rückfall des
     * Audits: fehlt sie, gelten alle HTML-Dateien als Wurzeln (statt einer Flut
     * von „nicht verzeichnet"-Warnungen).
     */
    public static function present(string $publicDir): bool
    {
        return self::firstFile($publicDir) !== null;
    }

    /**
     * Alle in der Sitemap verzeichneten Seiten als normalisierte interne
     * Request-Pfade (mit führendem /). Leer, wenn keine Sitemap existiert oder
     * sie keine (auflösbaren) Einträge enthält.
     *
     * @return list<string>
     */
    public static function paths(string $publicDir): array
    {
        $file = self::firstFile($publicDir);
        if ($file === null) {
            return [];
        }

        $paths = [];
        self::collect($publicDir, $file, $paths, 0);

        return array_keys($paths);
    }

    /**
     * Sammelt die Seitenpfade aus einer Sitemap-Datei. Ist es ein
     * sitemapindex, werden die Kind-Sitemaps (lokale Dateien) einmal verfolgt.
     *
     * @param array<string, true> $paths akkumuliert (Pfad → true, entdoppelt)
     */
    private static function collect(string $publicDir, string $absFile, array &$paths, int $depth): void
    {
        if ($depth > 1 || count($paths) >= self::MAX_URLS) {
            return; // sitemapindex nur eine Ebene tief verfolgen
        }
        $raw = @file_get_contents($absFile);
        if ($raw === false || $raw === '') {
            return;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($raw, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            return;
        }

        $isIndex = strtolower($dom->documentElement?->localName ?? '') === 'sitemapindex';

        $children = 0;
        foreach ($dom->getElementsByTagName('loc') as $loc) {
            $value = trim($loc->textContent);
            if ($value === '') {
                continue;
            }
            if ($isIndex) {
                // Kind-Sitemap: lokale Datei unter public/ auflösen und verfolgen.
                if (++$children > self::MAX_CHILDREN) {
                    break;
                }
                $childAbs = self::localFileFor($publicDir, $value);
                if ($childAbs !== null) {
                    self::collect($publicDir, $childAbs, $paths, $depth + 1);
                }
                continue;
            }
            $path = self::pathOf($value);
            if ($path !== null) {
                $paths[$path] = true;
                if (count($paths) >= self::MAX_URLS) {
                    break;
                }
            }
        }
    }

    /** Erste existierende Sitemap-Datei (absoluter Pfad) oder null. */
    private static function firstFile(string $publicDir): ?string
    {
        foreach (self::FILENAMES as $name) {
            $abs = $publicDir . '/' . $name;
            if (is_file($abs)) {
                return $abs;
            }
        }

        return null;
    }

    /**
     * Reduziert einen (ggf. absoluten) Sitemap-Eintrag auf den internen
     * Request-Pfad mit führendem /. Host und Schema werden verworfen, damit die
     * Prüfung ohne Kenntnis der baseURL auskommt. null bei leerem Pfad.
     */
    private static function pathOf(string $loc): ?string
    {
        $path = parse_url($loc, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $path = rawurldecode($path);

        return $path[0] === '/' ? $path : '/' . $path;
    }

    /**
     * Löst die URL einer Kind-Sitemap in eine lokale Datei unter public/ auf.
     * Nur innerhalb von public/ (kein Ausbruch über .. oder absolute Pfade);
     * andernfalls null.
     */
    private static function localFileFor(string $publicDir, string $loc): ?string
    {
        $path = self::pathOf($loc);
        if ($path === null) {
            return null;
        }
        $rel = ltrim($path, '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        $abs = $publicDir . '/' . $rel;

        return is_file($abs) ? $abs : null;
    }
}
