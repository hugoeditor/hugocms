<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Ein einzelner Audit-Fund — unveränderlich.
 *
 * Der Fund trägt KEINEN übersetzten Text: {@see $ruleId} ist zugleich der
 * i18n-Schlüssel, {@see $params} sind die dynamischen Bestandteile, die der
 * Client in die übersetzte Meldung einsetzt (wie bei API-Fehlern). Kategorie
 * und Schweregrad stammen aus dem {@see RuleCatalog}.
 */
final class Issue
{
    /**
     * @param string       $ruleId     z. B. "title.missing"
     * @param string       $severity   error | warning | hint
     * @param string       $category   Gruppen-ID (aus dem Katalog)
     * @param string       $url        betroffene Seiten-URL (z. B. "/about/")
     * @param ?string      $sourceFile Quellpfad relativ zum Hugo-Projekt
     *                                 (z. B. "content/about.md"), best effort
     * @param list<scalar> $params     dynamische Teile für die i18n-Meldung
     * @param array<string, mixed> $context zusätzliche Diagnosedaten
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $severity,
        public readonly string $category,
        public readonly string $url,
        public readonly ?string $sourceFile,
        public readonly array $params = [],
        public readonly array $context = [],
    ) {
    }

    /**
     * Baut einen Fund allein aus der Regel-ID — Kategorie und Schweregrad
     * kommen aus dem Katalog. Das ist der Standardweg in {@see Checks}.
     *
     * @param list<scalar> $params
     * @param array<string, mixed> $context
     */
    public static function of(
        string $ruleId,
        string $url,
        ?string $sourceFile = null,
        array $params = [],
        array $context = [],
    ): self {
        return new self(
            $ruleId,
            RuleCatalog::severity($ruleId),
            RuleCatalog::category($ruleId),
            $url,
            $sourceFile,
            $params,
            $context,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'severity' => $this->severity,
            'category' => $this->category,
            'url' => $this->url,
            'sourceFile' => $this->sourceFile,
            'params' => $this->params,
            'context' => $this->context === [] ? new \stdClass() : $this->context,
        ];
    }
}
