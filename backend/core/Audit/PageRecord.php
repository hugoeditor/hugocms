<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Die für die Prüfungen relevanten Daten EINER gebauten HTML-Seite, einmal
 * aus dem DOM extrahiert (siehe {@see HtmlInspector}). Es wird bewusst kein
 * DOM gehalten — nur die ausgewerteten Felder, damit auch große Sites mit
 * vertretbarem Speicher auskommen.
 */
final class PageRecord
{
    /**
     * @param string                                            $url        Request-Pfad, z. B. "/about/"
     * @param string                                            $file       Pfad relativ zu public/, z. B. "about/index.html"
     * @param ?string                                           $sourceFile best-effort Quellpfad relativ zum Projekt
     * @param list<string>                                      $titles     Inhalte aller <title>
     * @param list<string>                                      $descriptions Inhalte aller meta[name=description]
     * @param list<string>                                      $canonicals href aller link[rel=canonical]
     * @param list<array{level: int, text: string}>             $headings   H1–H6 in Dokumentreihenfolge
     * @param list<array{src: string, hasAlt: bool, alt: string, hasDim: bool}> $images
     * @param list<array{href: string, text: string}>          $anchors    alle <a href>
     * @param list<string>                                      $ids        alle id-Attribute
     * @param array<string, string>                             $og         og:* (ohne Präfix), erstes Vorkommen
     * @param list<string>                                      $jsonLd     Rohtext der ld+json-Blöcke
     */
    public function __construct(
        public readonly string $url,
        public readonly string $file,
        public readonly ?string $sourceFile,
        public readonly array $titles,
        public readonly array $descriptions,
        public readonly array $canonicals,
        public readonly array $headings,
        public readonly array $images,
        public readonly array $anchors,
        public readonly array $ids,
        public readonly array $og,
        public readonly array $jsonLd,
        public readonly ?string $lang,
        public readonly bool $hasDoctype,
        public readonly ?string $charset,
        public readonly ?string $viewport,
        public readonly ?string $twitterCard,
        public readonly int $wordCount,
        public readonly bool $hasPlaceholder,
    ) {
    }

    /** Erster Titel (getrimmt) oder null, falls keiner vorhanden/leer. */
    public function title(): ?string
    {
        $t = isset($this->titles[0]) ? trim($this->titles[0]) : '';

        return $t === '' ? null : $t;
    }

    /** Text der ersten H1 (getrimmt) oder null. */
    public function h1(): ?string
    {
        foreach ($this->headings as $h) {
            if ($h['level'] === 1) {
                $t = trim($h['text']);

                return $t === '' ? null : $t;
            }
        }

        return null;
    }

    /** Anzahl der H1 auf der Seite. */
    public function h1Count(): int
    {
        $n = 0;
        foreach ($this->headings as $h) {
            if ($h['level'] === 1) {
                $n++;
            }
        }

        return $n;
    }
}
