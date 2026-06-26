<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Sämtliche Audit-Prüfungen der Phase 1 als reine statische Funktionen —
 * ohne jeden requestübergreifenden Zustand. Drei Ebenen:
 *   - {@see page()}       je Seite (DOM-abgeleitet)
 *   - {@see site()}       über alle Seiten (Duplikate, verwaiste Seiten)
 *   - {@see files()}      Datei-Existenz im public/ (sitemap, robots)
 *   - {@see hugoSource()} Hugo-Quellen in content/ (Frontmatter, tote Links)
 *
 * Jede Prüfung erzeugt {@see Issue}-Objekte ausschließlich über die Regel-ID;
 * Kategorie und Schweregrad liefert der {@see RuleCatalog}.
 */
final class Checks
{
    private const int TITLE_MAX = 60;
    private const int TITLE_MIN = 30;
    private const int DESC_MAX = 155;
    private const int ALT_MAX = 125;
    private const int URL_MAX = 115;
    private const int WORDS_MIN = 200;

    /**
     * Prüfungen für EINE Seite.
     *
     * @return list<Issue>
     */
    public static function page(PageRecord $p, UrlMap $map): array
    {
        $out = [];
        $src = $p->sourceFile;
        $url = $p->url;

        // --- Titel ---------------------------------------------------------
        $title = $p->title();
        if ($title === null) {
            $out[] = Issue::of('title.missing', $url, $src);
        } else {
            if (count($p->titles) > 1) {
                $out[] = Issue::of('title.multiple', $url, $src, [count($p->titles)]);
            }
            $len = mb_strlen($title);
            if ($len > self::TITLE_MAX) {
                $out[] = Issue::of('title.too_long', $url, $src, [$len], ['title' => $title]);
            } elseif ($len < self::TITLE_MIN) {
                $out[] = Issue::of('title.too_short', $url, $src, [$len], ['title' => $title]);
            }
            $h1 = $p->h1();
            if ($h1 !== null && mb_strtolower($title) === mb_strtolower($h1)) {
                $out[] = Issue::of('title.identical_to_h1', $url, $src);
            }
        }

        // --- Meta-Description ----------------------------------------------
        $desc = self::firstNonEmpty($p->descriptions);
        if ($desc === null) {
            $out[] = Issue::of('meta.description.missing', $url, $src);
        } else {
            if (count($p->descriptions) > 1) {
                $out[] = Issue::of('meta.description.multiple', $url, $src, [count($p->descriptions)]);
            }
            $len = mb_strlen($desc);
            if ($len > self::DESC_MAX) {
                $out[] = Issue::of('meta.description.too_long', $url, $src, [$len]);
            }
        }

        // --- Überschriften -------------------------------------------------
        $h1Count = $p->h1Count();
        if ($h1Count === 0) {
            $out[] = Issue::of('heading.h1.missing', $url, $src);
        } elseif ($h1Count > 1) {
            $out[] = Issue::of('heading.h1.multiple', $url, $src, [$h1Count]);
        }
        foreach ($p->headings as $h) {
            if ($h['level'] === 1 && trim($h['text']) === '') {
                $out[] = Issue::of('heading.h1.empty', $url, $src);
                break;
            }
        }
        $prev = 0;
        foreach ($p->headings as $h) {
            if ($prev !== 0 && $h['level'] > $prev + 1) {
                $out[] = Issue::of('heading.hierarchy_jump', $url, $src, [$prev, $h['level']]);
                break;
            }
            $prev = $h['level'];
        }

        // --- Bilder --------------------------------------------------------
        foreach ($p->images as $img) {
            if (!$img['hasAlt']) {
                $out[] = Issue::of('img.alt.missing', $url, $src, [$img['src']], ['src' => $img['src']]);
            } elseif ($img['alt'] !== '') {
                if (self::imageAltGeneric($img['alt'])) {
                    $out[] = Issue::of('img.alt.generic', $url, $src, [$img['alt']], ['src' => $img['src']]);
                }
                if (mb_strlen($img['alt']) > self::ALT_MAX) {
                    $out[] = Issue::of('img.alt.too_long', $url, $src, [mb_strlen($img['alt'])], ['src' => $img['src']]);
                }
            }
            if (!$img['hasDim']) {
                $out[] = Issue::of('img.dimensions.missing', $url, $src, [$img['src']], ['src' => $img['src']]);
            }
        }

        // --- Canonical -----------------------------------------------------
        $canon = array_values(array_filter($p->canonicals, static fn (string $c): bool => trim($c) !== ''));
        if (count($canon) === 0) {
            $out[] = Issue::of('canonical.missing', $url, $src);
        } elseif (count($canon) > 1) {
            $out[] = Issue::of('canonical.multiple', $url, $src, [count($canon)]);
        } else {
            $path = self::pathOf($canon[0]);
            if ($path !== null && self::normalizeUrl($path) !== self::normalizeUrl($url)) {
                $out[] = Issue::of('canonical.self_reference', $url, $src, [$canon[0]]);
            }
        }

        // --- Social / Open Graph ------------------------------------------
        if (($p->og['title'] ?? '') === '') {
            $out[] = Issue::of('social.og.title.missing', $url, $src);
        }
        if (($p->og['description'] ?? '') === '') {
            $out[] = Issue::of('social.og.description.missing', $url, $src);
        }
        if (($p->og['image'] ?? '') === '') {
            $out[] = Issue::of('social.og.image.missing', $url, $src);
        }
        if ($p->twitterCard === null) {
            $out[] = Issue::of('social.twitter.card.missing', $url, $src);
        }

        // --- HTML-Grundgerüst ----------------------------------------------
        if (!$p->hasDoctype) {
            $out[] = Issue::of('html.doctype.missing', $url, $src);
        }
        if ($p->lang === null) {
            $out[] = Issue::of('html.lang.missing', $url, $src);
        }
        if ($p->charset === null) {
            $out[] = Issue::of('html.charset.missing', $url, $src);
        }
        if ($p->viewport === null) {
            $out[] = Issue::of('html.viewport.missing', $url, $src);
        }
        foreach (self::duplicates($p->ids) as $id) {
            $out[] = Issue::of('html.duplicate_ids', $url, $src, [$id], ['id' => $id]);
        }

        // --- Links ---------------------------------------------------------
        foreach ($p->anchors as $a) {
            $href = $a['href'];
            if ($href === '' || $href === '#') {
                $out[] = Issue::of('link.empty_href', $url, $src);
                continue;
            }
            $target = UrlMap::internalTarget($url, $href);
            if ($target !== null && !$map->has($target)) {
                $out[] = Issue::of('link.internal.broken', $url, $src, [$href], ['target' => $target]);
            }
        }

        // --- URL-Struktur --------------------------------------------------
        if (preg_match('/[A-Z]/', $url) === 1) {
            $out[] = Issue::of('url.uppercase', $url, $src);
        }
        if (str_contains($url, '_')) {
            $out[] = Issue::of('url.underscore', $url, $src);
        }
        if (str_contains($url, ' ') || stripos($url, '%20') !== false) {
            $out[] = Issue::of('url.space', $url, $src);
        }
        if (strlen($url) > self::URL_MAX) {
            $out[] = Issue::of('url.too_long', $url, $src, [strlen($url)]);
        }
        if (preg_match('/[^\x00-\x7F]/', $url) === 1) {
            $out[] = Issue::of('url.non_ascii', $url, $src);
        }

        // --- Strukturierte Daten ------------------------------------------
        foreach ($p->jsonLd as $i => $block) {
            if (trim($block) === '') {
                continue;
            }
            json_decode($block);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $out[] = Issue::of('structured_data.jsonld.invalid', $url, $src, [$i + 1], ['error' => json_last_error_msg()]);
            }
        }

        // --- Inhalt --------------------------------------------------------
        if ($p->wordCount < self::WORDS_MIN) {
            $out[] = Issue::of('content.word_count_low', $url, $src, [$p->wordCount]);
        }
        if ($p->hasPlaceholder) {
            $out[] = Issue::of('content.placeholder', $url, $src);
        }

        return $out;
    }

    /**
     * Seitenübergreifende Prüfungen: doppelte Titel/Descriptions, verwaiste
     * Seiten (keine eingehenden internen Links).
     *
     * @param list<PageRecord> $pages
     * @return list<Issue>
     */
    public static function site(array $pages, UrlMap $map): array
    {
        $out = [];

        // Doppelte Titel.
        foreach (self::groupBy($pages, static fn (PageRecord $p): ?string => $p->title()) as $group) {
            if (count($group) > 1) {
                foreach ($group as $p) {
                    $out[] = Issue::of('title.duplicate', $p->url, $p->sourceFile, [count($group), (string) $p->title()]);
                }
            }
        }

        // Doppelte Meta-Descriptions.
        foreach (self::groupBy($pages, static fn (PageRecord $p): ?string => self::firstNonEmpty($p->descriptions)) as $group) {
            if (count($group) > 1) {
                foreach ($group as $p) {
                    $out[] = Issue::of('meta.description.duplicate', $p->url, $p->sourceFile, [count($group)]);
                }
            }
        }

        // Verwaiste Seiten: kein eingehender interner Link.
        $linked = [];
        foreach ($pages as $p) {
            foreach ($p->anchors as $a) {
                $target = UrlMap::internalTarget($p->url, $a['href']);
                if ($target !== null) {
                    $linked[self::normalizeUrl($target)] = true;
                }
            }
        }
        foreach ($pages as $p) {
            $norm = self::normalizeUrl($p->url);
            if ($norm !== '/' && !isset($linked[$norm])) {
                $out[] = Issue::of('link.orphan_page', $p->url, $p->sourceFile);
            }
        }

        return $out;
    }

    /**
     * Datei-Prüfungen im public/-Ordner: sitemap.xml und robots.txt.
     *
     * @return list<Issue>
     */
    public static function files(string $publicDir): array
    {
        $out = [];

        if (!is_file($publicDir . '/sitemap.xml') && !is_file($publicDir . '/sitemapindex.xml')) {
            $out[] = Issue::of('sitemap.missing', '/sitemap.xml', null);
        }

        $robots = $publicDir . '/robots.txt';
        if (!is_file($robots)) {
            $out[] = Issue::of('robots.missing', '/robots.txt', null);
        } else {
            $content = (string) file_get_contents($robots);
            if (stripos($content, 'sitemap:') === false) {
                $out[] = Issue::of('robots.no_sitemap_ref', '/robots.txt', null);
            }
        }

        return $out;
    }

    /**
     * Hugo-Quellprüfungen im Content-Verzeichnis: fehlende Frontmatter-Pflicht-
     * felder und tote relative Markdown-Links/Bilder.
     *
     * @param string $contentDirAbs absoluter Pfad des Content-Verzeichnisses
     * @param string $contentName    sein Name relativ zur Projektwurzel (für sourceFile)
     * @return list<Issue>
     */
    public static function hugoSource(string $contentDirAbs, string $contentName = 'content'): array
    {
        if (!is_dir($contentDirAbs)) {
            return [];
        }

        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentDirAbs, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $abs = $file->getPathname();
            $rel = $contentName . '/' . ltrim(str_replace('\\', '/', substr($abs, strlen($contentDirAbs))), '/');
            $raw = (string) file_get_contents($abs);
            [$frontMatter, $body] = self::splitFrontMatter($raw);

            // Pflichtfelder im Frontmatter.
            if ($frontMatter !== null) {
                $missing = [];
                if (preg_match('/(^|\n)\s*["\']?title["\']?\s*[:=]/i', $frontMatter) !== 1) {
                    $missing[] = 'title';
                }
                if (preg_match('/(^|\n)\s*["\']?date["\']?\s*[:=]/i', $frontMatter) !== 1) {
                    $missing[] = 'date';
                }
                if ($missing !== []) {
                    $out[] = Issue::of('hugo.frontmatter.required', '', $rel, [implode(', ', $missing)], ['missing' => $missing]);
                }
            }

            // Tote relative Markdown-Links/Bilder.
            foreach (self::deadMarkdownLinks($body, dirname($abs)) as $target) {
                $out[] = Issue::of('hugo.markdown.dead_link', '', $rel, [$target], ['target' => $target]);
            }
        }

        return $out;
    }

    // --- Hilfsfunktionen ---------------------------------------------------

    public static function imageAltGeneric(string $alt): bool
    {
        return HtmlInspector::isGenericAlt($alt);
    }

    /** @param list<string> $values */
    private static function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $v) {
            if (trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /**
     * @param list<PageRecord> $pages
     * @param callable(PageRecord): ?string $keyFn
     * @return array<string, list<PageRecord>>
     */
    private static function groupBy(array $pages, callable $keyFn): array
    {
        $groups = [];
        foreach ($pages as $p) {
            $key = $keyFn($p);
            if ($key === null || trim($key) === '') {
                continue;
            }
            $groups[mb_strtolower(trim($key))][] = $p;
        }

        return $groups;
    }

    /**
     * @param list<string> $values
     * @return list<string> mehrfach vorkommende Werte (je einmal)
     */
    private static function duplicates(array $values): array
    {
        $counts = array_count_values($values);

        return array_values(array_keys(array_filter($counts, static fn (int $n): bool => $n > 1)));
    }

    /** Normalisiert eine URL für Vergleiche (Schrägstrich am Ende ignorieren). */
    private static function normalizeUrl(string $url): string
    {
        $url = preg_replace('/[#?].*$/', '', $url) ?? $url;

        return $url === '/' ? '/' : rtrim($url, '/');
    }

    /** Pfadanteil einer (auch absoluten) URL oder null. */
    private static function pathOf(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url) === 1 || str_starts_with($url, '//')) {
            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) ? $path : '/';
        }

        return $url;
    }

    /**
     * Teilt eine Markdown-Datei in Frontmatter (oder null) und Rumpf.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function splitFrontMatter(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw; // BOM entfernen
        // YAML (---) oder TOML (+++).
        if (preg_match('/^(---|\+\+\+)\R(.*?)\R\1\R?(.*)$/s', $raw, $m) === 1) {
            return [$m[2], $m[3]];
        }
        // JSON-Frontmatter ({ ... }) am Dateianfang.
        if (preg_match('/^\s*\{.*?\}\s*\R(.*)$/s', $raw, $m) === 1) {
            return [$raw, $m[1]];
        }

        return [null, $raw];
    }

    /**
     * Findet relative Markdown-Link-/Bildziele mit Dateiendung, die relativ zum
     * Verzeichnis der Quelldatei nicht existieren. Bewusst konservativ: externe
     * Adressen, Anker und endungslose Ziele (mögliche Hugo-Refs) werden
     * ausgelassen, um Fehlalarme zu vermeiden.
     *
     * @return list<string>
     */
    private static function deadMarkdownLinks(string $body, string $dir): array
    {
        if (preg_match_all('/!?\[[^\]]*\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/', $body, $m) === false) {
            return [];
        }
        $dead = [];
        foreach ($m[1] as $target) {
            $t = trim($target);
            if ($t === '' || $t[0] === '#' || $t[0] === '/') {
                continue;
            }
            if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $t) === 1 || str_starts_with($t, '//')) {
                continue; // extern / mailto / tel
            }
            $clean = preg_replace('/[#?].*$/', '', $t) ?? $t;
            // Nur Ziele mit Dateiendung prüfen (sonst evtl. Hugo-Pretty-Ref).
            if ($clean === '' || !preg_match('/\.[A-Za-z0-9]{1,8}$/', basename($clean))) {
                continue;
            }
            if (!is_file($dir . '/' . rawurldecode($clean))) {
                $dead[] = $t;
            }
        }

        return array_values(array_unique($dead));
    }
}
