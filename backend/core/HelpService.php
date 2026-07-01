<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Liest die Hilfe-/Wissensdatenbank aus backend/help/. Ein Thema ist eine
 * Markdown-Datei mit YAML-Front-Matter, benannt <id>.<sprache>.md und nach
 * Sektionen gruppiert:
 *
 *   backend/help/audit/title.too_short.de.md
 *   backend/help/audit/title.too_short.en.md
 *
 * Die ID entspricht z. B. der Audit-Regel-ID (issue.ruleId). Der Client rendert
 * den Markdown-Rumpf (marked); hier werden nur Datei aufgelöst, Front-Matter
 * gelesen und die Sprache mit Rückfall auf Englisch bestimmt. Bewusst ohne
 * externe Abhängigkeit — der Front-Matter-Parser deckt eine kleine, für die
 * Hilfe-Dateien dokumentierte YAML-Teilmenge ab (Skalare, "Strings", [Listen]).
 *
 * Sicherheit: Sektion und ID werden streng auf [a-z0-9._-] beschränkt und der
 * aufgelöste Pfad zusätzlich per realpath in backend/help/ eingesperrt — ein
 * Ausbruch über „..“ o. Ä. ist ausgeschlossen.
 */
final class HelpService
{
    /** Unterstützte Sprachen; erste passende gewinnt, sonst Rückfall. */
    private const array LOCALES = ['de', 'en'];
    private const string FALLBACK = 'en';

    public function __construct(private readonly string $helpDir)
    {
    }

    /**
     * Liefert ein Hilfethema (Metadaten + Markdown-Rumpf) in der gewünschten
     * Sprache, mit Rückfall auf Englisch.
     *
     * @return array{section: string, id: string, locale: string, requestedLocale: string, fallback: bool, title: string, summary: ?string, severity: ?string, seeAlso: list<string>, body: string}
     */
    public function topic(string $section, string $id, string $locale): array
    {
        $section = self::sanitize($section);
        $id = self::sanitize($id);
        if ($section === '' || $id === '') {
            throw ApiException::badRequest('HELP-INVALID-ID');
        }
        $requested = in_array($locale, self::LOCALES, true) ? $locale : self::FALLBACK;

        [$resolvedLocale, $file] = $this->resolve($section, $id, $requested);
        if ($file === null) {
            throw ApiException::notFound('HELP-NOT-FOUND', [$section . '/' . $id]);
        }

        $raw = (string) @file_get_contents($file);
        [$meta, $body] = self::splitFrontMatter($raw);

        $seeAlso = $meta['see_also'] ?? [];
        if (!is_array($seeAlso)) {
            $seeAlso = [];
        }

        return [
            'section' => $section,
            'id' => $id,
            'locale' => $resolvedLocale,
            'requestedLocale' => $requested,
            'fallback' => $resolvedLocale !== $requested,
            'title' => is_string($meta['title'] ?? null) && $meta['title'] !== '' ? $meta['title'] : $id,
            'summary' => is_string($meta['summary'] ?? null) ? $meta['summary'] : null,
            'severity' => is_string($meta['severity'] ?? null) ? $meta['severity'] : null,
            'seeAlso' => array_values(array_filter($seeAlso, 'is_string')),
            'body' => $body,
        ];
    }

    /** Beschränkt Sektion/ID auf ungefährliche Zeichen (kein Pfad-Ausbruch). */
    private static function sanitize(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || str_contains($value, '..')) {
            return '';
        }

        return preg_match('/^[a-z0-9._-]+$/', $value) === 1 ? $value : '';
    }

    /**
     * Sucht die Datei in der gewünschten Sprache, dann im Rückfall. Sperrt den
     * aufgelösten Pfad per realpath in helpDir ein.
     *
     * @return array{0: string, 1: ?string} [aufgelöste Sprache, Pfad|null]
     */
    private function resolve(string $section, string $id, string $requested): array
    {
        $base = realpath($this->helpDir);
        if ($base === false) {
            return [$requested, null];
        }
        foreach (array_values(array_unique([$requested, self::FALLBACK])) as $loc) {
            $real = realpath($this->helpDir . '/' . $section . '/' . $id . '.' . $loc . '.md');
            if ($real !== false && is_file($real) && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                return [$loc, $real];
            }
        }

        return [$requested, null];
    }

    /**
     * Trennt ein optionales Front-Matter (--- … ---) am Dateianfang vom Rumpf.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function splitFrontMatter(string $raw): array
    {
        $raw = preg_replace('/^\x{FEFF}/u', '', $raw) ?? $raw; // BOM entfernen
        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $raw, $m) === 1) {
            return [self::parseMeta($m[1]), ltrim($m[2], "\r\n")];
        }

        return [[], $raw];
    }

    /**
     * Parst die kleine unterstützte YAML-Teilmenge: „schlüssel: wert“ je Zeile,
     * Werte als Skalar, "String" oder Inline-Liste [a, b, c].
     *
     * @return array<string, mixed>
     */
    private static function parseMeta(string $frontMatter): array
    {
        $meta = [];
        foreach (preg_split('/\r?\n/', $frontMatter) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+)\s*:\s*(.*)$/', $trimmed, $mm) === 1) {
                $meta[$mm[1]] = self::parseValue(trim($mm[2]));
            }
        }

        return $meta;
    }

    /** @return string|list<string> */
    private static function parseValue(string $value): string|array
    {
        $len = strlen($value);
        if ($len >= 2
            && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
            return substr($value, 1, -1);
        }
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }
            $parts = array_map(static fn (string $p): string => trim($p, " \t\"'"), explode(',', $inner));

            return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        }

        return $value;
    }
}
