<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Statischer Katalog aller Audit-Regeln der Phase 1.
 *
 * Jede Regel hat eine stabile ID ("kategorie.unterpunkt"), eine Kategorie
 * (für die Gruppierung im Bericht) und einen Standard-Schweregrad
 * (error | warning | hint). Die ID ist zugleich der i18n-Schlüssel im
 * Frontend (dort werden Punkte durch Unterstriche ersetzt).
 *
 * Der Katalog ist die EINZIGE Quelle für Regel-Metadaten — die Prüfungen in
 * {@see Checks} erzeugen Funde allein über die Regel-ID, Kategorie und
 * Schweregrad kommen von hier. Es gibt bewusst keinen requestübergreifenden
 * Zustand: reine statische Daten.
 */
final class RuleCatalog
{
    public const string ERROR = 'error';
    public const string WARNING = 'warning';
    public const string HINT = 'hint';

    /**
     * ruleId => [kategorie, schweregrad]. Reihenfolge bestimmt die Anzeige.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array RULES = [
        // Seitentitel
        'title.missing'            => ['title', self::ERROR],
        'title.duplicate'         => ['title', self::WARNING],
        'title.multiple'          => ['title', self::WARNING],
        'title.too_long'          => ['title', self::HINT],
        'title.too_short'         => ['title', self::HINT],
        'title.identical_to_h1'   => ['title', self::HINT],

        // Meta-Description
        'meta.description.missing'   => ['meta', self::WARNING],
        'meta.description.duplicate' => ['meta', self::WARNING],
        'meta.description.multiple'  => ['meta', self::WARNING],
        'meta.description.too_long'  => ['meta', self::HINT],

        // Überschriften
        'heading.h1.missing'      => ['heading', self::ERROR],
        'heading.h1.multiple'     => ['heading', self::WARNING],
        'heading.h1.empty'        => ['heading', self::WARNING],
        'heading.hierarchy_jump'  => ['heading', self::HINT],

        // Bilder
        'img.alt.missing'         => ['img', self::ERROR],
        'img.alt.generic'         => ['img', self::WARNING],
        'img.alt.too_long'        => ['img', self::HINT],
        'img.dimensions.missing'  => ['img', self::HINT],

        // Canonical
        'canonical.missing'        => ['canonical', self::WARNING],
        'canonical.multiple'       => ['canonical', self::ERROR],
        'canonical.self_reference' => ['canonical', self::HINT],

        // Social / Open Graph
        'social.og.title.missing'       => ['social', self::WARNING],
        'social.og.description.missing'  => ['social', self::WARNING],
        'social.og.image.missing'        => ['social', self::WARNING],
        'social.twitter.card.missing'    => ['social', self::HINT],

        // HTML-Grundgerüst
        'html.lang.missing'        => ['html', self::WARNING],
        'html.doctype.missing'     => ['html', self::ERROR],
        'html.charset.missing'     => ['html', self::WARNING],
        'html.viewport.missing'    => ['html', self::WARNING],
        'html.duplicate_ids'       => ['html', self::WARNING],

        // Interne Links
        'link.internal.broken'     => ['link', self::ERROR],
        'link.empty_href'          => ['link', self::HINT],
        'link.orphan_page'         => ['link', self::HINT],
        // Weder in der Sitemap verzeichnet noch intern verlinkt (unerreichbar).
        'page.unreferenced'        => ['link', self::ERROR],

        // URL-Struktur
        'url.uppercase'            => ['url', self::HINT],
        'url.underscore'           => ['url', self::HINT],
        'url.space'                => ['url', self::WARNING],
        'url.too_long'             => ['url', self::HINT],
        'url.non_ascii'            => ['url', self::HINT],

        // Ähnliche URL-Slugs (seitenübergreifend)
        'filename.near_duplicate'  => ['filename', self::ERROR],
        'filename.copy_suspect'    => ['filename', self::ERROR],
        'filename.similar'         => ['filename', self::WARNING],
        'filename.reordered'       => ['filename', self::WARNING],

        // Strukturierte Daten
        'structured_data.jsonld.invalid' => ['structured_data', self::WARNING],

        // Inhalt
        'content.word_count_low'   => ['content', self::HINT],
        'content.placeholder'      => ['content', self::WARNING],

        // Sitemap / robots.txt
        'sitemap.missing'          => ['sitemap', self::WARNING],
        'robots.missing'           => ['robots', self::HINT],
        'robots.no_sitemap_ref'    => ['robots', self::HINT],

        // Hugo-Quellen (content/**/*.md)
        'hugo.frontmatter.required' => ['hugo', self::ERROR],
        'hugo.markdown.dead_link'   => ['hugo', self::WARNING],
    ];

    /**
     * Regeln, die sich AUSSCHLIESSLICH durch Ändern der Hugo-Content-Datei
     * beheben lassen — nur für diese bietet der Client den KI-Micro-Auftrag an
     * (siehe Connector::cmdAssistantFix).
     *
     * Bewusst NICHT enthalten sind zwei Gruppen:
     *
     * - Theme-Sache: `html.*`, `canonical.*`, `structured_data.*`,
     *   `social.twitter.card.missing`. Sie stehen im Layout, und der Assistent
     *   darf Layouts nur LESEN, nie schreiben.
     * - Struktur-Sache: `url.*`, `filename.*`, `page.unreferenced`,
     *   `link.orphan_page`, `sitemap.*`, `robots.*`. Ihre Behebung benennt
     *   Dateien um oder ändert die Verlinkung — das ändert URLs und braucht
     *   Aliase/Weiterleitungen, also eine Entscheidung des Betreibers.
     *
     * @var array<string, true>
     */
    public const array FIXABLE = [
        'title.missing'            => true,
        'title.duplicate'          => true,
        'title.too_long'           => true,
        'title.too_short'          => true,
        'title.identical_to_h1'    => true,

        'meta.description.missing'   => true,
        'meta.description.duplicate' => true,
        'meta.description.too_long'  => true,

        'heading.h1.missing'       => true,
        'heading.h1.multiple'      => true,
        'heading.h1.empty'         => true,
        'heading.hierarchy_jump'   => true,

        'img.alt.missing'          => true,
        'img.alt.generic'          => true,
        'img.alt.too_long'         => true,

        'social.og.title.missing'       => true,
        'social.og.description.missing' => true,

        'content.word_count_low'   => true,
        'content.placeholder'      => true,

        'hugo.frontmatter.required' => true,
        'hugo.markdown.dead_link'   => true,
    ];

    /**
     * Lässt sich die Regel durch Ändern der Content-Datei beheben? Grundlage
     * für den KI-Micro-Auftrag am einzelnen Fund.
     */
    public static function fixable(string $ruleId): bool
    {
        return isset(self::FIXABLE[$ruleId]);
    }

    /**
     * Taugt die Regel für eine KI-Diagnose statt einer Behebung? Das sind genau
     * die bekannten Regeln, die NICHT über die Content-Datei behebbar sind:
     * Theme, Hugo-Konfiguration oder Seitenstruktur. Die Diagnose liest nur und
     * erklärt, was wo zu ändern wäre — es gibt bewusst keine zweite gepflegte
     * Liste, die mit {@see FIXABLE} auseinanderlaufen könnte.
     */
    public static function diagnosable(string $ruleId): bool
    {
        return isset(self::RULES[$ruleId]) && !isset(self::FIXABLE[$ruleId]);
    }

    /** Kategorie einer Regel (oder 'other', falls unbekannt). */
    public static function category(string $ruleId): string
    {
        return self::RULES[$ruleId][0] ?? 'other';
    }

    /** Standard-Schweregrad einer Regel (oder HINT, falls unbekannt). */
    public static function severity(string $ruleId): string
    {
        return self::RULES[$ruleId][1] ?? self::HINT;
    }

    /** Anzahl bekannter Regeln (für „rulesApplied" im Bericht). */
    public static function count(): int
    {
        return count(self::RULES);
    }

    /** Alle Kategorien in Katalogreihenfolge, ohne Dubletten. */
    public static function categories(): array
    {
        $seen = [];
        foreach (self::RULES as [$cat]) {
            $seen[$cat] = true;
        }

        return array_keys($seen);
    }
}
