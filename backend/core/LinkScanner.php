<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

/**
 * Suche nach Hyperlinks in den Hugo-Quellen (content/) und im gebauten
 * Ergebnis (public/). Zweck ist das Aufspüren FALSCH GESCHRIEBENER Links:
 * Gesucht wird nicht nur die eingegebene Adresse selbst, sondern auch, was ihr
 * ähnlich sieht — abweichende Schreibweise und Tippfehler.
 *
 * Segmentiert statt am Stück: {@see scan()} arbeitet ab einem Cursor eine feste
 * Zahl Dateien ab und liefert den nächsten Cursor zurück. Der Client ruft den
 * Befehl so lange erneut auf, bis `done` gesetzt ist. Damit bleibt das Backend
 * zustandslos (der Cursor liegt beim Client), es gibt keinen Hintergrundlauf und
 * kein Request-Zeitlimit sprengt den Lauf — nur das jeweilige Segment.
 *
 * Der Cursor ist ein reiner INDEX in die sortierte Dateiliste, kein Pfad: Aus
 * dem Client kommt nie ein Pfadbestandteil, der Verzeichnisse verlassen könnte.
 * Gelesen wird nur innerhalb der beiden konfigurierten Ordner — wie im
 * {@see Audit\AuditRunner}, dem der gebaute Ordner ebenso direkt vorliegt.
 */
final class LinkScanner
{
    /** Dateien je Segment. Klein genug für ein knappes Zeitlimit im Shared Hosting. */
    public const int BATCH_FILES = 250;

    /** Treffer je Segment; darüber bricht das Segment ab und meldet `truncated`. */
    public const int MAX_MATCHES = 400;

    /** Dateien größer als das werden übersprungen (Datenblobs im public/). */
    private const int MAX_FILE_BYTES = 4194304; // 4 MiB

    /** Endungen, in denen gesucht wird — je Bereich. */
    private const array CONTENT_EXT = ['md', 'markdown', 'html', 'htm'];
    private const array PUBLIC_EXT = ['html', 'htm'];

    /**
     * Obergrenze für die Editierdistanz, gestaffelt nach Länge der Suchadresse.
     * Kurze Adressen dulden weniger Abweichung, sonst gälte „/de" als ähnlich
     * zu „/en".
     */
    private const int SIMILAR_SHORT = 8;
    private const int SIMILAR_MEDIUM = 20;

    /**
     * PHP-levenshtein() arbeitet byteweise und liefert ab dieser Länge -1.
     * Längere Adressen werden nur exakt/normalisiert verglichen.
     */
    private const int LEVENSHTEIN_MAX = 255;

    /**
     * @param string $contentDirAbs absoluter Pfad des Hugo-Content-Ordners
     * @param string $contentName   sein Name relativ zur Projektwurzel (content)
     * @param string $publicDirAbs  absoluter Pfad des gebauten Ordners
     * @param string $publicName    sein Name relativ zur Projektwurzel (public)
     * @param ?Audit\SourceGuesser $guesser Ordnet einer gebauten Seite ihre
     *        Quelldatei zu — dieselbe Zuordnung, die der SEO-Check für seine
     *        Funde nutzt. Ohne ihn tragen Treffer im gebauten Ordner keine
     *        Quellangabe.
     */
    public function __construct(
        private readonly string $contentDirAbs,
        private readonly string $contentName,
        private readonly string $publicDirAbs,
        private readonly string $publicName,
        private readonly ?Audit\SourceGuesser $guesser = null,
    ) {
    }

    /**
     * Durchsucht ein Segment ab $cursor und liefert die Treffer samt Fortschritt.
     *
     * @param string $query  gesuchte Adresse (roh, wie eingegeben)
     * @param int    $cursor Index in die Dateiliste, 0 beim ersten Aufruf
     * @return array{
     *     total: int, cursor: int, done: bool, truncated: bool,
     *     matches: list<array<string, mixed>>
     * }
     */
    public function scan(string $query, int $cursor = 0): array
    {
        $files = $this->files();
        $total = count($files);
        $needle = self::forms($query) + ['scheme' => self::schemePrefix($query)];
        $start = max(0, $cursor);
        $end = min($total, $start + self::BATCH_FILES);

        $matches = [];
        $truncated = false;
        for ($i = $start; $i < $end; $i++) {
            [$abs, $rel, $area] = $files[$i];
            foreach ($this->scanFile($abs, $rel, $area, $query, $needle) as $match) {
                $matches[] = $match;
                if (count($matches) >= self::MAX_MATCHES) {
                    // Segment hier beenden. Der nächste Aufruf setzt bei der
                    // FOLGENDEN Datei auf: Der Rest dieser Datei geht verloren,
                    // was `truncated` dem Benutzer auch so meldet.
                    $truncated = true;
                    $end = $i + 1;
                    break 2;
                }
            }
        }

        return [
            'total' => $total,
            'cursor' => $end,
            'done' => $end >= $total,
            'truncated' => $truncated,
            'matches' => $matches,
        ];
    }

    /**
     * Die zu durchsuchenden Dateien als [absolut, projektrelativ, Bereich],
     * stabil sortiert — der Cursor indiziert genau diese Reihenfolge, sie muss
     * über die Aufrufe eines Laufs hinweg gleich bleiben.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function files(): array
    {
        $out = [
            ...$this->collect($this->contentDirAbs, $this->contentName, self::CONTENT_EXT, 'content'),
            ...$this->collect($this->publicDirAbs, $this->publicName, self::PUBLIC_EXT, 'public'),
        ];

        return $out;
    }

    /**
     * @param list<string> $extensions
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function collect(string $dirAbs, string $name, array $extensions, string $area): array
    {
        if (!is_dir($dirAbs)) {
            return [];
        }
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirAbs, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $rel = $name . '/' . ltrim(
                str_replace('\\', '/', substr($file->getPathname(), strlen($dirAbs))),
                '/',
            );
            // Papierkorb eines Mounts, der auf den Ordner zeigt: Hugo baut daraus
            // nichts, seine Links interessieren hier ebenso wenig.
            if (Audit\AuditRunner::inTrash($rel)) {
                continue;
            }
            $out[] = [$file->getPathname(), $rel, $area];
        }
        sort($out);

        return $out;
    }

    /**
     * Alle Links EINER Datei gegen die Suchadresse halten.
     *
     * @param array{full: string, path: string, scheme: string} $needle Formen
     *        der Suchadresse
     * @return list<array<string, mixed>>
     */
    private function scanFile(string $abs, string $rel, string $area, string $query, array $needle): array
    {
        $size = @filesize($abs);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return [];
        }
        $raw = @file_get_contents($abs);
        if ($raw === false || $raw === '') {
            return [];
        }

        $out = [];
        // Quelldatei einer gebauten Seite: einmal je Datei raten, nicht je
        // Treffer — die Zuordnung sieht im Dateisystem nach.
        $sourceFile = null;
        $sourceResolved = false;
        foreach (self::extractLinks($raw) as $link => $offset) {
            $kind = $this->compare((string) $link, $query, $needle);
            if ($kind === null) {
                continue;
            }
            if (!$sourceResolved) {
                $sourceResolved = true;
                $sourceFile = $area === 'public' && $this->guesser !== null
                    ? $this->guesser->forFile(ltrim(substr($rel, strlen($this->publicName)), '/'))
                    : null;
            }
            $out[] = [
                'file' => $rel,
                'area' => $area,
                'line' => self::lineAt($raw, $offset),
                'link' => (string) $link,
                'kind' => $kind[0],
                'distance' => $kind[1],
                // Nur bei Treffern im gebauten Ordner gesetzt: die Hugo-Datei,
                // aus der die Seite entstanden ist. Im Content-Bereich IST die
                // Fundstelle bereits die Quelle.
                'sourceFile' => $sourceFile,
            ];
        }

        return $out;
    }

    /**
     * Hyperlinks eines Dokuments als link => Byte-Offset des ersten Vorkommens.
     * Erfasst wird, was in Hugo-Projekten tatsächlich verlinkt:
     *   - HTML-Attribute href/src (auch in Markdown eingebettetes HTML)
     *   - Markdown-Ziele `](…)` und Referenzdefinitionen `[id]: …`
     *   - Hugo-Shortcodes {{< ref "…" >}} / {{< relref "…" >}}
     * Mehrfach vorkommende Links behalten das erste Vorkommen — eine Zeilenzahl
     * je Datei und Link genügt zum Auffinden.
     *
     * @return array<string, int>
     */
    private static function extractLinks(string $raw): array
    {
        $out = [];
        $patterns = [
            '/\b(?:href|src)\s*=\s*"([^"]*)"/i',
            "/\\b(?:href|src)\\s*=\\s*'([^']*)'/i",
            '/\]\(\s*<?([^)\s>]+)>?(?:\s+["\'][^)]*["\'])?\s*\)/',
            '/^\s*\[[^\]]+\]:\s*<?([^\s>]+)>?/m',
            '/\{\{[<%]\s*(?:relref|ref)\s+["\']?([^"\'\s>%}]+)["\']?\s*[>%]\}\}/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $raw, $m, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($m[1] as $hit) {
                $link = trim((string) $hit[0]);
                if ($link === '' || isset($out[$link])) {
                    continue;
                }
                $out[$link] = (int) $hit[1];
            }
        }

        return $out;
    }

    /**
     * Vergleicht einen gefundenen Link mit der Suchadresse. Geprüft wird jede
     * Vergleichsform gegen jede (siehe {@see forms()}), die beste Bewertung
     * gewinnt: gleich vor darunterliegend vor ähnlich.
     *
     * @param array{full: string, path: string, scheme: string} $needle Formen
     *        der Suchadresse; `scheme` nur bei einer Protokoll-Suche belegt
     * @return array{0: string, 1: int}|null [Art, Editierdistanz] oder null
     */
    private function compare(string $link, string $query, array $needle): ?array
    {
        // Protokoll-Suche: Es zählt allein der Anfang des Links. Ähnlichkeit
        // wäre hier sinnlos — „https:" ist kein Vertipper von „http:", sondern
        // gerade das Gegenteil.
        if ($needle['scheme'] !== '') {
            return str_starts_with(mb_strtolower(trim($link)), $needle['scheme'])
                ? ['scheme', 0]
                : null;
        }

        if ($link === $query) {
            return ['exact', 0];
        }
        $candidate = self::forms($link);

        // Paare aus je einer nicht leeren Form beider Seiten.
        $pairs = [];
        foreach ([$needle['full'], $needle['path']] as $n) {
            foreach ([$candidate['full'], $candidate['path']] as $c) {
                if ($n !== '' && $c !== '') {
                    $pairs[$n . "\0" . $c] = [$n, $c];
                }
            }
        }
        if ($pairs === []) {
            return null;
        }

        foreach ($pairs as [$n, $c]) {
            if ($n === $c) {
                return ['normalized', 0];
            }
        }
        // Adressen unterhalb der gesuchten: Wer eine Domain oder einen Abschnitt
        // sucht, will auch die Links darauf sehen. Nur an Segmentgrenzen, sonst
        // gälte „/produkte" auch für „/produktebilder".
        foreach ($pairs as [$n, $c]) {
            if (str_starts_with($c, $n . '/')) {
                return ['under', 0];
            }
        }

        $best = null;
        foreach ($pairs as [$n, $c]) {
            if (strlen($n) > self::LEVENSHTEIN_MAX || strlen($c) > self::LEVENSHTEIN_MAX) {
                continue;
            }
            $distance = levenshtein($c, $n);
            if ($distance > 0 && $distance <= self::threshold($n) && ($best === null || $distance < $best)) {
                $best = $distance;
            }
        }

        return $best === null ? null : ['similar', $best];
    }

    /** Erlaubte Editierdistanz für eine Suchadresse dieser Länge. */
    private static function threshold(string $needle): int
    {
        $len = strlen($needle);
        if ($len <= self::SIMILAR_SHORT) {
            return 1;
        }

        return $len <= self::SIMILAR_MEDIUM ? 2 : 3;
    }

    /**
     * Erkennt eine Suche nach einem PROTOKOLL: „http:", „http://", „https://",
     * „mailto:" und so fort. Solche Eingaben sind keine Adresse, die man
     * vergleichen könnte — sie fragen nach allem, was so beginnt. Häufigster
     * Fall: unverschlüsselte externe Links aufspüren, die auf https gehören.
     *
     * Der Doppelpunkt ist Pflicht, sonst wäre „http" nicht von einem Pfad zu
     * unterscheiden. Ein angehängtes „//" bleibt erhalten, damit „http://" nur
     * echte Web-Adressen trifft.
     *
     * @return string Präfix in Kleinschreibung, oder '' wenn es keine
     *                Protokoll-Suche ist
     */
    private static function schemePrefix(string $query): string
    {
        $s = mb_strtolower(trim($query));

        return preg_match('#^[a-z][a-z0-9+.-]*:(//)?$#', $s) === 1 ? $s : '';
    }

    /**
     * Die beiden Vergleichsformen einer Adresse — ohne Schema, Query und
     * Fragment, ohne Schrägstriche am Rand, kleingeschrieben, Umlaute und
     * Akzente ausgeschrieben:
     *
     *   `full` mit Rechnername: „www.example.org/ueber-uns"
     *   `path` nur der Pfad:    „ueber-uns"
     *
     * Zwei Formen, weil beide Suchweisen vorkommen. Wer „/ueber-uns" sucht, meint
     * auch das absolut geschriebene „https://example.org/ueber-uns" — dafür
     * braucht es `path`. Wer eine Domain sucht („www.example.org"), meint gerade
     * den Rechnernamen — dafür braucht es `full`. Verglichen wird jede Form der
     * Suchadresse gegen jede des Links; leere Formen bleiben außen vor, sonst
     * gälten zwei verschiedene Domains ohne Pfad als dieselbe Adresse.
     *
     * @return array{full: string, path: string}
     */
    private static function forms(string $url): array
    {
        $s = preg_replace('/[#?].*$/', '', trim($url)) ?? trim($url);

        // Rechnername abtrennen, wenn einer da ist — auch bei protokollrelativen
        // Adressen („//example.org/x").
        $host = '';
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//([^/]*)#i', $s, $m) === 1) {
            $host = $m[1];
            $s = substr($s, strlen($m[0]));
        }
        $path = self::fold(trim($s, '/'));
        $full = $host === '' ? $path : self::fold($host) . ($path === '' ? '' : '/' . $path);

        return ['full' => $full, 'path' => $path];
    }

    /** Kleinschreibung plus Transliteration gängiger Diakritika. */
    private static function fold(string $s): string
    {
        $s = mb_strtolower($s);

        return strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ū' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /** Zeilennummer (1-basiert) zu einem Byte-Offset. */
    private static function lineAt(string $raw, int $offset): int
    {
        return substr_count($raw, "\n", 0, max(0, min($offset, strlen($raw)))) + 1;
    }
}
