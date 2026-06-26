<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Liest eine gebaute HTML-Seite EINMAL aus und liefert die für die Prüfungen
 * nötigen Felder als {@see PageRecord}. Reine PHP-Bordmittel (DOMDocument +
 * DOMXPath), keine externen Abhängigkeiten.
 */
final class HtmlInspector
{
    /** Generische, nichtssagende alt-Texte (kleingeschrieben). */
    private const array GENERIC_ALT = [
        'image', 'images', 'img', 'photo', 'picture', 'bild', 'foto', 'grafik',
        'icon', 'logo', 'banner', 'spacer', 'placeholder', 'platzhalter',
    ];

    /**
     * Parst HTML und extrahiert alle Felder.
     */
    public static function inspect(string $html, string $url, string $file, ?string $sourceFile): PageRecord
    {
        $hasDoctype = preg_match('/^\s*<!doctype\s/i', $html) === 1;

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Das vorangestellte XML-Processing-Instruction zwingt libxml zu UTF-8.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xp = new DOMXPath($dom);

        // --- Kopfdaten -----------------------------------------------------
        $titles = self::texts($xp, '//title');
        $descriptions = self::attrs($xp, '//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content');
        $canonicals = self::attrs($xp, '//link[translate(@rel,"CANONICAL","canonical")="canonical"]', 'href');

        $htmlEl = $xp->query('//html')->item(0);
        $lang = $htmlEl instanceof DOMElement && $htmlEl->hasAttribute('lang')
            ? trim($htmlEl->getAttribute('lang'))
            : null;
        $lang = ($lang === '') ? null : $lang;

        $charset = self::charset($xp);
        $viewport = self::firstAttr($xp, '//meta[translate(@name,"VIEWPORT","viewport")="viewport"]', 'content');
        $twitterCard = self::firstAttr($xp, '//meta[@name="twitter:card"]', 'content');
        $og = self::openGraph($xp);

        // ld+json VOR dem Entfernen der <script>-Knoten einsammeln.
        $jsonLd = [];
        foreach ($xp->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') as $s) {
            $jsonLd[] = $s->textContent;
        }

        // --- Überschriften, Bilder, Anker, IDs -----------------------------
        $headings = [];
        for ($lvl = 1; $lvl <= 6; $lvl++) {
            foreach ($xp->query('//h' . $lvl) as $h) {
                $headings[] = ['level' => $lvl, 'text' => trim($h->textContent)];
            }
        }

        $images = [];
        foreach ($xp->query('//img') as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }
            $images[] = [
                'src' => trim($img->getAttribute('src')),
                'hasAlt' => $img->hasAttribute('alt'),
                'alt' => trim($img->getAttribute('alt')),
                'hasDim' => $img->hasAttribute('width') && $img->hasAttribute('height'),
            ];
        }

        $anchors = [];
        foreach ($xp->query('//a[@href]') as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $anchors[] = [
                'href' => trim($a->getAttribute('href')),
                'text' => trim($a->textContent),
            ];
        }

        $ids = [];
        foreach ($xp->query('//*[@id]') as $el) {
            if ($el instanceof DOMElement) {
                $ids[] = $el->getAttribute('id');
            }
        }

        // --- Sichtbarer Text: Skript/Stil entfernen, dann auswerten --------
        foreach (iterator_to_array($xp->query('//script | //style | //noscript')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $xp->query('//body')->item(0);
        $text = $body !== null ? $body->textContent : $dom->textContent;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $wordCount = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
        $hasPlaceholder = stripos($text, 'lorem ipsum') !== false
            || preg_match('/\bTODO\b/', $text) === 1;

        return new PageRecord(
            $url,
            $file,
            $sourceFile,
            $titles,
            $descriptions,
            $canonicals,
            $headings,
            $images,
            $anchors,
            $ids,
            $og,
            $jsonLd,
            $lang,
            $hasDoctype,
            $charset,
            $viewport,
            $twitterCard,
            $wordCount,
            $hasPlaceholder,
        );
    }

    /** True, falls ein alt-Text generisch ist. */
    public static function isGenericAlt(string $alt): bool
    {
        return in_array(mb_strtolower(trim($alt)), self::GENERIC_ALT, true);
    }

    // --- Hilfsfunktionen ---------------------------------------------------

    /** @return list<string> textContent aller Treffer (ungetrimmt). */
    private static function texts(DOMXPath $xp, string $query): array
    {
        $out = [];
        foreach ($xp->query($query) as $n) {
            $out[] = $n->textContent;
        }

        return $out;
    }

    /** @return list<string> Attributwerte aller Treffer (getrimmt). */
    private static function attrs(DOMXPath $xp, string $query, string $attr): array
    {
        $out = [];
        foreach ($xp->query($query) as $n) {
            if ($n instanceof DOMElement) {
                $out[] = trim($n->getAttribute($attr));
            }
        }

        return $out;
    }

    private static function firstAttr(DOMXPath $xp, string $query, string $attr): ?string
    {
        $n = $xp->query($query)->item(0);
        if (!$n instanceof DOMElement) {
            return null;
        }
        $v = trim($n->getAttribute($attr));

        return $v === '' ? null : $v;
    }

    /** charset aus <meta charset> oder <meta http-equiv="Content-Type">. */
    private static function charset(DOMXPath $xp): ?string
    {
        $n = $xp->query('//meta[@charset]')->item(0);
        if ($n instanceof DOMElement) {
            $v = trim($n->getAttribute('charset'));
            if ($v !== '') {
                return $v;
            }
        }
        foreach ($xp->query('//meta[@http-equiv]') as $m) {
            if ($m instanceof DOMElement
                && strcasecmp(trim($m->getAttribute('http-equiv')), 'Content-Type') === 0
                && preg_match('/charset=([\w-]+)/i', $m->getAttribute('content'), $hit) === 1) {
                return $hit[1];
            }
        }

        return null;
    }

    /**
     * Open-Graph-Eigenschaften ohne "og:"-Präfix (erstes Vorkommen je Schlüssel).
     *
     * @return array<string, string>
     */
    private static function openGraph(DOMXPath $xp): array
    {
        $og = [];
        foreach ($xp->query('//meta[@property or @name]') as $m) {
            if (!$m instanceof DOMElement) {
                continue;
            }
            $key = strtolower(trim($m->getAttribute('property') ?: $m->getAttribute('name')));
            if (!str_starts_with($key, 'og:')) {
                continue;
            }
            $sub = substr($key, 3);
            if (!isset($og[$sub])) {
                $og[$sub] = trim($m->getAttribute('content'));
            }
        }

        return $og;
    }
}
