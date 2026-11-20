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

    /** Namensraum des Sitemap-Protokolls (sitemaps.org, Version 0.9). */
    private const string XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

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
     * Prüft die Sitemap (und bei einem sitemapindex dessen lokale
     * Kind-Sitemaps) auf XML-Gültigkeit. Drei Mängel werden erkannt:
     *
     * - `sitemap.xml.malformed`: nicht wohlgeformtes XML (auch leere oder
     *   unlesbare Datei) — Parameter ist die Zeile des ersten Fehlers.
     * - `sitemap.xml.root_invalid`: Wurzelelement ist weder `<urlset>` noch
     *   `<sitemapindex>` im Namensraum des Sitemap-Protokolls.
     * - `sitemap.xml.loc_missing`: Einträge ohne (gefülltes) `<loc>`.
     *
     * Bewusst KEINE Validierung gegen das XSD: Hugo ergänzt mehrsprachige
     * Sitemaps um `xhtml:link`-Elemente, deren Schema nachgeladen werden
     * müsste — das wäre ein Netzzugriff.
     *
     * @return list<array{rule: string, url: string, params: list<scalar>, context: array<string, mixed>}>
     */
    public static function defects(string $publicDir): array
    {
        $file = self::firstFile($publicDir);
        if ($file === null) {
            return [];
        }

        $out = [];
        $seen = [];
        $pending = [$file];
        $isRootFile = true;
        while ($pending !== []) {
            $abs = array_shift($pending);
            if (isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $url = '/' . ltrim(substr($abs, strlen($publicDir)), '/');

            [$dom, $line, $message] = self::parse($abs);
            if ($dom === null) {
                $out[] = [
                    'rule' => 'sitemap.xml.malformed',
                    'url' => $url,
                    'params' => [$line],
                    'context' => $message === '' ? [] : ['message' => $message],
                ];
                $isRootFile = false;
                continue;
            }

            $root = $dom->documentElement;
            $kind = strtolower($root?->localName ?? '');
            if ($root === null || !in_array($kind, ['urlset', 'sitemapindex'], true) || $root->namespaceURI !== self::XMLNS) {
                $out[] = [
                    'rule' => 'sitemap.xml.root_invalid',
                    'url' => $url,
                    'params' => [$root?->nodeName ?? ''],
                    'context' => ['namespace' => $root?->namespaceURI ?? ''],
                ];
                $isRootFile = false;
                continue;
            }

            // Einträge: <url> im urlset, <sitemap> im sitemapindex.
            $entryName = $kind === 'urlset' ? 'url' : 'sitemap';
            $missing = 0;
            $children = 0;
            foreach ($root->childNodes as $entry) {
                if ($entry->nodeType !== XML_ELEMENT_NODE || $entry->localName !== $entryName) {
                    continue;
                }
                $loc = '';
                foreach ($entry->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'loc') {
                        $loc = trim($child->textContent);
                        break;
                    }
                }
                if ($loc === '') {
                    $missing++;
                    continue;
                }
                // Kind-Sitemaps nur von der obersten Datei aus verfolgen (eine Ebene).
                if ($kind === 'sitemapindex' && $isRootFile && ++$children <= self::MAX_CHILDREN) {
                    $childAbs = self::localFileFor($publicDir, $loc);
                    if ($childAbs !== null) {
                        $pending[] = $childAbs;
                    }
                }
            }
            if ($missing > 0) {
                $out[] = [
                    'rule' => 'sitemap.xml.loc_missing',
                    'url' => $url,
                    'params' => [$missing],
                    'context' => [],
                ];
            }
            $isRootFile = false;
        }

        return $out;
    }

    /**
     * Liest eine Sitemap-Datei als XML ein — ohne Netzzugriff (LIBXML_NONET).
     * Liefert das Dokument oder, wenn es nicht wohlgeformt ist, Zeile und
     * Meldung des ersten Fehlers (Meldung von libxml, nur zur Diagnose).
     *
     * @return array{0: ?DOMDocument, 1: int, 2: string}
     */
    private static function parse(string $absFile): array
    {
        $raw = @file_get_contents($absFile);
        if ($raw === false || trim($raw) === '') {
            return [null, 1, ''];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->loadXML($raw, LIBXML_NONET);
        $line = 1;
        $message = '';
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                $line = max(1, (int) $error->line);
                $message = trim($error->message);
                break;
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? [$dom, 0, ''] : [null, $line, $message];
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
        [$dom] = self::parse($absFile);
        if ($dom === null) {
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
