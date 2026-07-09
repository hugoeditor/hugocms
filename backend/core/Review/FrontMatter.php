<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Review;

/**
 * Setzt einen booleschen Front-Matter-Schlüssel (aktuell `draft`) in einer
 * Hugo-Content-Datei, ohne einen vollwertigen YAML/TOML/JSON-Parser einzuführen
 * (das Backend arbeitet bewusst ohne Composer). Das Format wird an den
 * Begrenzern erkannt — `---` YAML, `+++` TOML, führendes `{ }` JSON — und der
 * vorhandene Stil beibehalten:
 *
 *  - YAML/TOML: der Schlüssel wird zeilenweise ersetzt oder, falls nicht
 *    vorhanden, an den Front-Matter-Block angehängt.
 *  - JSON: der Block wird dekodiert, der Schlüssel gesetzt und neu kodiert.
 *
 * Fehlt ein Front-Matter-Block ganz, wird ein minimaler YAML-Block vorangestellt
 * (der Regelfall für Hugo-Content).
 */
final class FrontMatter
{
    /** Setzt `draft` auf true/false. */
    public static function setDraft(string $raw, bool $value): string
    {
        return self::set($raw, 'draft', $value);
    }

    /** Setzt einen booleschen Schlüssel im führenden Front-Matter-Block. */
    public static function set(string $raw, string $key, bool $value): string
    {
        $rendered = $value ? 'true' : 'false';
        $bom = '';
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $bom = "\xEF\xBB\xBF";
            $raw = substr($raw, 3);
        }

        // YAML (---) oder TOML (+++).
        if (preg_match('/^(---|\+\+\+)\R(.*?)\R(\1)[ \t]*(?:\R(.*))?$/s', $raw, $m) === 1) {
            $delim = $m[1];
            $format = $delim === '---' ? 'yaml' : 'toml';
            $inner = self::upsertLine($m[2], $key, $rendered, $format);
            $body = $m[4] ?? '';

            return $bom . $delim . "\n" . $inner . "\n" . $delim . "\n" . $body;
        }

        // JSON-Front-Matter: führendes { … }-Objekt.
        $json = self::extractJsonObject($raw);
        if ($json !== null) {
            [$object, $rest] = $json;
            $data = json_decode($object, true);
            if (is_array($data)) {
                $data[$key] = $value; // echter bool — Hugo liest JSON-Daten strukturiert
                $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    return $bom . $encoded . "\n" . $rest;
                }
            }
        }

        // Kein Front Matter: minimalen YAML-Block voranstellen.
        $line = self::upsertLine('', $key, $rendered, 'yaml');

        return $bom . "---\n" . $line . "\n---\n" . $raw;
    }

    /**
     * Ersetzt die Zeile des Schlüssels im Front-Matter-Rumpf oder hängt sie an.
     * YAML nutzt `key: wert`, TOML `key = wert`; vorhandene Anführungszeichen um
     * den Schlüssel und die Einrückung bleiben erhalten.
     */
    private static function upsertLine(string $inner, string $key, string $value, string $format): string
    {
        $sep = $format === 'toml' ? ' = ' : ': ';
        $quotedKey = preg_quote($key, '/');
        // Nur echte Top-Level-Zeilen (keine Einrückung) ansprechen — verschachtelte
        // Schlüssel gleichen Namens in Unterstrukturen bleiben unberührt.
        $pattern = '/^["\']?' . $quotedKey . '["\']?[ \t]*' . ($format === 'toml' ? '=' : ':') . '.*$/m';
        $replacement = $key . $sep . $value;

        if (preg_match($pattern, $inner) === 1) {
            return (string) preg_replace($pattern, self::escapeReplacement($replacement), $inner, 1);
        }

        if ($inner === '') {
            return $replacement;
        }

        return rtrim($inner, "\r\n") . "\n" . $replacement;
    }

    /**
     * Löst ein führendes JSON-Objekt heraus (klammer-balanciert, Strings
     * berücksichtigt). Liefert [objekt, rumpf] oder null, wenn der Text nicht mit
     * einem JSON-Objekt beginnt.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function extractJsonObject(string $raw): ?array
    {
        $i = 0;
        $len = strlen($raw);
        while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t" || $raw[$i] === "\n" || $raw[$i] === "\r")) {
            $i++;
        }
        if ($i >= $len || $raw[$i] !== '{') {
            return null;
        }

        $depth = 0;
        $inStr = false;
        $esc = false;
        for ($j = $i; $j < $len; $j++) {
            $ch = $raw[$j];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $object = substr($raw, $i, $j - $i + 1);
                    $rest = ltrim(substr($raw, $j + 1), "\r\n");

                    return [$object, $rest];
                }
            }
        }

        return null;
    }

    /** Maskiert `$`- und `\`-Zeichen für die preg_replace-Ersetzung. */
    private static function escapeReplacement(string $s): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $s);
    }
}
