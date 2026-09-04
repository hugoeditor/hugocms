<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Persistiert die vom Benutzer ignorierten Audit-Funde — je Webseite eine
 * Datei, NICHT je Lauf.
 *
 * Der Grund für den eigenen Speicher: Ein Bericht ist der Schnappschuss eines
 * Laufs, und {@see AuditService} hält nur die jüngsten Läufe vor. Eine
 * Ignorierung, die am Bericht hinge, wäre nach dem nächsten Durchlauf wieder
 * verschwunden — also genau dann, wenn sie gebraucht wird. Deshalb liegt sie
 * neben den Läufen und wirkt auf alle: den nächsten ebenso wie die
 * gespeicherten.
 *
 * Ein Fund wird über `ruleId|url` angesprochen (ersatzweise `ruleId|sourceFile`,
 * siehe {@see keyFor}) — dieselbe Zusammensetzung, mit der der Client den Fund
 * kennzeichnet und der Connector ihn im Bericht wiederfindet.
 *
 * Speicherform wie {@see \HugoCMS\FileManager\Review\ReviewStore}: eine
 * JSON-Datei, atomar über Tempdatei + rename geschrieben, keine Datenbank,
 * kein requestübergreifender Zustand.
 */
final class IgnoreStore
{
    /** Dateiname im Speicherverzeichnis der Webseite (neben den Läufen). */
    private const string FILE = 'ignored.json';

    /**
     * Obergrenze der gemerkten Schlüssel. Ignorieren ist eine Einzelfall-
     * Entscheidung; wer tausende Funde wegdrückt, will in Wahrheit eine Regel
     * oder ein Verzeichnis ausschließen ([seo_report]-Sektion). Die Grenze hält
     * die Datei klein und den Abgleich schnell.
     */
    private const int MAX_KEYS = 5000;

    /** Höchstlänge eines Schlüssels (Regel-ID + lange URL sind darunter). */
    private const int MAX_KEY_LENGTH = 512;

    /**
     * Zwischenspeicher der gelesenen Schlüssel (Schlüssel → true), damit ein
     * Bericht mit tausenden Funden die Datei nur einmal liest.
     *
     * @var array<string, true>|null
     */
    private ?array $keys = null;

    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * Kennung eines Funds. Die URL identifiziert ihn genauer als die Quelldatei
     * (eine Datei kann mehrere Seiten erzeugen); fehlt sie — etwa bei Funden
     * ganz ohne Seite —, tritt die Quelldatei an ihre Stelle.
     *
     * @param array<string, mixed> $issue
     */
    public static function keyFor(array $issue): string
    {
        $ruleId = (string) ($issue['ruleId'] ?? '');
        $where = (string) ($issue['url'] ?? '');
        if ($where === '') {
            $where = (string) ($issue['sourceFile'] ?? '');
        }

        return $ruleId . '|' . $where;
    }

    /** Ist dieser Fund ignoriert? */
    public function has(string $key): bool
    {
        return isset($this->load()[$key]);
    }

    /** Wie viele Funde insgesamt ignoriert sind (auch solche aus alten Läufen). */
    public function count(): int
    {
        return count($this->load());
    }

    /**
     * Ignorierte Schlüssel, sortiert.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->load());
    }

    /**
     * Setzt oder löst die Ignorierung für mehrere Funde und liefert die Zahl der
     * tatsächlich geänderten Einträge. Bereits gesetzte Schlüssel erneut zu
     * setzen ist kein Fehler — die Aktion ist idempotent.
     *
     * @param list<string> $keys
     */
    public function set(array $keys, bool $ignored): int
    {
        $current = $this->load();
        $changed = 0;
        foreach ($keys as $raw) {
            $key = self::normalize($raw);
            if ($ignored) {
                if (isset($current[$key])) {
                    continue;
                }
                if (count($current) >= self::MAX_KEYS) {
                    throw new ApiException('ECONFIG', 409, 'AUDIT-IGNORE-LIMIT', [self::MAX_KEYS]);
                }
                $current[$key] = true;
                ++$changed;
                continue;
            }
            if (isset($current[$key])) {
                unset($current[$key]);
                ++$changed;
            }
        }
        if ($changed > 0) {
            $this->persist($current);
        }

        return $changed;
    }

    // --- Intern ------------------------------------------------------------

    /** Prüft und säubert einen von außen kommenden Schlüssel. */
    private static function normalize(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH || !str_contains($key, '|')) {
            throw ApiException::badRequest('AUDIT-IGNORE-KEY-INVALID', [$key]);
        }
        // Steuerzeichen und Zeilenumbrüche haben in einer Regel-ID/URL nichts
        // verloren und würden die Datei unlesbar machen.
        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw ApiException::badRequest('AUDIT-IGNORE-KEY-INVALID', [$key]);
        }

        return $key;
    }

    /**
     * Liest die Datei einmal je Request.
     *
     * @return array<string, true>
     */
    private function load(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $path = $this->storageDir . '/' . self::FILE;
        if (!is_file($path)) {
            return $this->keys = [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        $list = is_array($data) && is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $out = [];
        foreach ($list as $key) {
            if (is_string($key) && $key !== '') {
                $out[$key] = true;
            }
        }

        return $this->keys = $out;
    }

    /**
     * Schreibt die Datei atomar (Tempdatei + rename), damit ein abgebrochener
     * Request keine halbe Liste hinterlässt.
     *
     * @param array<string, true> $keys
     */
    private function persist(array $keys): void
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new ApiException('EIO', 500, 'AUDIT-STORAGE-FAILED');
        }
        $sorted = array_keys($keys);
        sort($sorted); // stabile Dateireihenfolge
        $json = json_encode(
            ['version' => 1, 'keys' => $sorted],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        $path = $this->storageDir . '/' . self::FILE;
        $tmp = $path . '.tmp';
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new ApiException('EIO', 500, 'AUDIT-STORAGE-FAILED');
        }
        $this->keys = $keys;
    }
}
