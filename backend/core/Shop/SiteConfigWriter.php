<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Ergänzt die Hugo-Konfiguration einer Webseite um das, was die
 * Konfigurationsprüfung von OpensourceERP beanstandet (Befehl
 * `shopsiteconfigapply`, seit 2026-11-12).
 *
 * Die Grenze: OpensourceERP schickt keine Konfiguration, sondern benannte
 * Korrekturen (`op`). Welche Schlüssel dabei entstehen, legt diese Klasse
 * fest, und jeden Wert prüft sie selbst — ein Mount nur für die vier
 * Verzeichnisse des Kundenportal-Pakets und nur auf sein festes Ziel, Namen
 * und Adressen nur aus einem engen Zeichenvorrat. Ein entwendeter Schlüssel
 * der Anbindung kann damit weder fremde Verzeichnisse einhängen noch
 * beliebige Schlüssel setzen.
 *
 * Geschrieben wird nur eine einzelne JSON-Datei im Wurzelverzeichnis der
 * Hugo-Quelle (`hugo.json` oder `config.json`). TOML, YAML und das
 * Verzeichnis `config/` bleiben unberührt: ohne Bibliothek ließen sie sich
 * nicht ändern, ohne Kommentare und Aufbau zu gefährden — dort bleibt es bei
 * den Hinweisen der Prüfung.
 *
 * Ablauf: Sicherungskopie im Laufzeitverzeichnis der Anbindung, Datei
 * atomar ersetzen, `hugo config` zur Kontrolle. Lädt Hugo die neue Datei
 * nicht, kommt die alte zurück.
 */
final class SiteConfigWriter
{
    /** Verzeichnisse des Pakets und ihr Ziel in Hugos Dateisystem; content hat ein wählbares Unterverzeichnis */
    private const PACKAGE_MOUNTS = [
        'static' => 'static',
        'layouts' => 'layouts',
        'assets/portal-ui' => 'assets/portal-ui',
        'content' => null,
    ];

    /** Hugos Vorgabe-Mounts: der erste eigene Eintrag in module.mounts schaltet sie ab */
    private const DEFAULT_MOUNTS = ['content', 'data', 'layouts', 'i18n', 'archetypes', 'assets', 'static'];

    /** Mögliche Konfigurationsdateien im Wurzelverzeichnis, in Hugos Reihenfolge */
    private const ROOT_FILES = ['hugo.toml', 'hugo.yaml', 'hugo.yml', 'hugo.json',
                                'config.toml', 'config.yaml', 'config.yml', 'config.json'];

    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * Lässt sich die Konfiguration dieser Webseite ergänzen?
     *
     * @return array{supported: bool, file: ?string, reason: ?string} reason: DIRECTORY (config/),
     *         FORMAT (TOML oder YAML), MISSING, READONLY, INVALID (kein lesbares JSON-Objekt)
     */
    public static function target(string $source): array
    {
        if (is_dir($source . '/config')) {
            return ['supported' => false, 'file' => 'config/', 'reason' => 'DIRECTORY'];
        }
        $file = null;
        foreach (self::ROOT_FILES as $name) {
            if (is_file($source . '/' . $name)) {
                $file = $name;
                break;
            }
        }
        if ($file === null) {
            return ['supported' => false, 'file' => null, 'reason' => 'MISSING'];
        }
        if (!str_ends_with($file, '.json')) {
            return ['supported' => false, 'file' => $file, 'reason' => 'FORMAT'];
        }
        if (!is_writable($source . '/' . $file)) {
            return ['supported' => false, 'file' => $file, 'reason' => 'READONLY'];
        }
        if (!(json_decode((string) file_get_contents($source . '/' . $file)) instanceof \stdClass)) {
            return ['supported' => false, 'file' => $file, 'reason' => 'INVALID'];
        }

        return ['supported' => true, 'file' => $file, 'reason' => null];
    }

    /**
     * Wendet die Korrekturen an.
     *
     * @param list<mixed> $fixes Korrekturen aus der Anfrage
     * @param string $package Paketverzeichnis relativ zur Hugo-Quelle ({@see ShopSync::PACKAGE_DIR})
     * @return array{file: string, applied: list<string>, changed: bool, backup: ?string}
     */
    public static function apply(string $bin, string $source, string $varDir, string $package, array $fixes): array
    {
        $target = self::target($source);
        if (!$target['supported']) {
            throw new ApiException('ECONFLICT', 409, 'SHOP-SITECONFIG-UNSUPPORTED', [(string) $target['reason'], (string) $target['file']]);
        }
        if ($fixes === [] || count($fixes) > 32) {
            throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', ['fixes']);
        }

        $path = $source . '/' . $target['file'];
        $original = (string) file_get_contents($path);
        $config = json_decode($original);
        if (!$config instanceof \stdClass) {
            throw new ApiException('ECONFLICT', 409, 'SHOP-SITECONFIG-UNSUPPORTED', ['INVALID', (string) $target['file']]);
        }

        $applied = [];
        foreach ($fixes as $fix) {
            if (!is_array($fix) || !is_string($fix['op'] ?? null)) {
                throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', ['op']);
            }
            match ($fix['op']) {
                'mount' => self::fixMount($config, $package, $fix),
                'taxonomy' => self::fixTaxonomy($config, $fix),
                'permalinks' => self::fixPermalinks($config, $fix),
                'enableKinds' => self::fixKinds($config),
                'portaluiTaxonomy' => self::fixPortalUi($config, $fix),
                default => throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', [$fix['op']]),
            };
            $applied[] = $fix['op'];
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new ApiException('EINTERNAL', 500, 'SHOP-SITECONFIG-WRITE-FAILED', [(string) $target['file']]);
        }
        $json = self::indentLike($json, $original) . "\n";
        if (json_decode($json) == json_decode($original)) {
            return ['file' => (string) $target['file'], 'applied' => $applied, 'changed' => false, 'backup' => null];
        }

        // Sicherung außerhalb der Hugo-Quelle: dort sähe Hugo sie nicht, und
        // kein Bau trägt sie auf die Webseite
        if (!is_dir($varDir) && !@mkdir($varDir, 0775, true) && !is_dir($varDir)) {
            throw new ApiException('EINTERNAL', 500, 'SHOP-SITECONFIG-WRITE-FAILED', ['backup']);
        }
        $backup = $varDir . '/siteconfig-' . gmdate('Ymd-His') . '-' . $target['file'];
        if (@file_put_contents($backup, $original) === false) {
            throw new ApiException('EINTERNAL', 500, 'SHOP-SITECONFIG-WRITE-FAILED', ['backup']);
        }

        self::replace($path, $json);
        try {
            SiteConfig::read($bin, $source);
        } catch (ApiException $e) {
            // Hugo lädt die neue Datei nicht: die alte gilt wieder
            self::replace($path, $original);
            throw new ApiException('ECONFLICT', 409, 'SHOP-SITECONFIG-REJECTED', $e->params());
        }

        return ['file' => (string) $target['file'], 'applied' => $applied, 'changed' => true, 'backup' => basename($backup)];
    }

    /**
     * Mount eines Paketverzeichnisses: ergänzen oder auf das richtige Ziel
     * stellen. Hatte die Datei bisher keine Mounts, kommen Hugos Vorgaben
     * dazu — der erste eigene Eintrag schaltete sie sonst ab, und die
     * Webseite verlöre ihre eigenen Inhalte.
     *
     * @param array<string, mixed> $fix source, target
     */
    private static function fixMount(\stdClass $config, string $package, array $fix): void
    {
        $from = trim((string) ($fix['source'] ?? ''), '/');
        $to = trim((string) ($fix['target'] ?? ''), '/');
        $package = trim($package, '/');
        if (!str_starts_with($from, $package . '/')) {
            throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', ['mount.source']);
        }
        $part = substr($from, strlen($package) + 1);
        if (!array_key_exists($part, self::PACKAGE_MOUNTS)) {
            throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', ['mount.source']);
        }
        $fixed = self::PACKAGE_MOUNTS[$part];
        $valid = $fixed !== null
            ? $to === $fixed
            : preg_match('#^content(/[A-Za-z0-9][A-Za-z0-9_-]{0,63}){1,4}$#', $to) === 1;
        if (!$valid) {
            throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', ['mount.target']);
        }

        $module = self::child($config, 'module');
        $key = self::key($module, 'mounts');
        $mounts = is_array($module->$key ?? null) ? $module->$key : [];
        if ($mounts === []) {
            // Alle sieben, auch wenn das Verzeichnis noch fehlt: Hugo übergeht
            // fehlende Quellen, und ein später angelegtes data/ gälte sonst nicht
            foreach (self::DEFAULT_MOUNTS as $dir) {
                $mounts[] = (object) ['source' => $dir, 'target' => $dir];
            }
        }
        $found = false;
        foreach ($mounts as $mount) {
            if ($mount instanceof \stdClass && trim((string) ($mount->source ?? ''), '/') === $from) {
                $mount->target = $to;
                $found = true;
            }
        }
        if (!$found) {
            $mounts[] = (object) ['source' => $from, 'target' => $to];
        }
        $module->$key = array_values($mounts);
    }

    /**
     * Taxonomie anmelden. Ein taxonomies-Block ersetzt Hugos Vorgaben —
     * hatte die Datei keinen, bleiben category und tag erhalten.
     *
     * @param array<string, mixed> $fix singular, plural
     */
    private static function fixTaxonomy(\stdClass $config, array $fix): void
    {
        $singular = self::name($fix['singular'] ?? null, 'taxonomy.singular');
        $plural = self::name($fix['plural'] ?? null, 'taxonomy.plural');

        $key = self::key($config, 'taxonomies');
        if (!($config->$key ?? null) instanceof \stdClass) {
            $config->$key = (object) ['category' => 'categories', 'tag' => 'tags'];
        }
        foreach (get_object_vars($config->$key) as $value) {
            if (is_string($value) && strtolower($value) === $plural) {
                return;
            }
        }
        $config->$key->{self::key($config->$key, $singular)} = $plural;
    }

    /**
     * Adressen der Übersicht und der Begriffsseiten einer Taxonomie.
     *
     * @param array<string, mixed> $fix taxonomy, index, term
     */
    private static function fixPermalinks(\stdClass $config, array $fix): void
    {
        $taxonomy = self::name($fix['taxonomy'] ?? null, 'permalinks.taxonomy');
        $paths = [];
        foreach (['taxonomy' => 'index', 'term' => 'term'] as $kind => $field) {
            $value = $fix[$field] ?? null;
            // Ein Pfad aus Buchstaben, Ziffern und Hugos Platzhaltern (:slug, :title …)
            if (!is_string($value) || preg_match('#^/(?:[A-Za-z0-9_.:-]+/)*[A-Za-z0-9_.:-]*$#', $value) !== 1 || str_contains($value, '..')) {
                throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', ['permalinks.' . $field]);
            }
            $paths[$kind] = $value;
        }

        $permalinks = self::child($config, 'permalinks');
        foreach ($paths as $kind => $value) {
            $map = self::child($permalinks, $kind);
            $map->{self::key($map, $taxonomy)} = $value;
        }
    }

    /** Seitenarten taxonomy und term wieder bauen lassen. */
    private static function fixKinds(\stdClass $config): void
    {
        $key = self::key($config, 'disableKinds');
        if (!is_array($config->$key ?? null)) {
            return;
        }
        $config->$key = array_values(array_filter(
            $config->$key,
            static fn($kind): bool => !is_string($kind) || !in_array(strtolower($kind), ['taxonomy', 'term'], true)
        ));
    }

    /**
     * params.portalui.taxonomy: daran erkennt das Theme die Shop-Kategorien.
     *
     * @param array<string, mixed> $fix taxonomy
     */
    private static function fixPortalUi(\stdClass $config, array $fix): void
    {
        $taxonomy = self::name($fix['taxonomy'] ?? null, 'portaluiTaxonomy.taxonomy');
        $portalui = self::child(self::child($config, 'params'), 'portalui');
        $portalui->{self::key($portalui, 'taxonomy')} = $taxonomy;
    }

    /** Ein Name aus Kleinbuchstaben, Ziffern, Binde- und Unterstrich. */
    private static function name(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match(self::NAME, $value) !== 1) {
            throw ApiException::badRequest('SHOP-SITECONFIG-FIX-INVALID', [$field]);
        }

        return $value;
    }

    /**
     * Der Schlüssel, wie er in der Datei steht: Hugo unterscheidet Groß- und
     * Kleinschreibung nicht, ein zweiter Eintrag in anderer Schreibweise wäre
     * ein Widerspruch.
     */
    private static function key(\stdClass $object, string $name): string
    {
        foreach (array_keys(get_object_vars($object)) as $existing) {
            if (strtolower((string) $existing) === strtolower($name)) {
                return (string) $existing;
            }
        }

        return $name;
    }

    /** Das Objekt unter einem Schlüssel; fehlt es oder ist es keines, entsteht ein leeres. */
    private static function child(\stdClass $object, string $name): \stdClass
    {
        $key = self::key($object, $name);
        if (!($object->$key ?? null) instanceof \stdClass) {
            $object->$key = new \stdClass();
        }

        return $object->$key;
    }

    /** Einrückung wie in der bisherigen Datei (PHP schreibt immer vier Leerzeichen). */
    private static function indentLike(string $json, string $original): string
    {
        if (preg_match('/^\{\R([ \t]+)\S/', $original, $m) !== 1 || $m[1] === '    ') {
            return $json;
        }
        $indent = $m[1];

        return (string) preg_replace_callback(
            '/^((?: {4})+)/m',
            static fn(array $hit): string => str_repeat($indent, intdiv(strlen($hit[1]), 4)),
            $json
        );
    }

    /** Ersetzt die Datei in einem Zug: ein Bau mittendrin liest nie eine halbe Konfiguration. */
    private static function replace(string $path, string $content): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $mode = @fileperms($path);
        if (@file_put_contents($tmp, $content) === false) {
            throw new ApiException('EINTERNAL', 500, 'SHOP-SITECONFIG-WRITE-FAILED', [basename($path)]);
        }
        if ($mode !== false) {
            @chmod($tmp, $mode & 0777);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new ApiException('EINTERNAL', 500, 'SHOP-SITECONFIG-WRITE-FAILED', [basename($path)]);
        }
    }
}
