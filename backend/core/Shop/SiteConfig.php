<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Hugo-Konfiguration einer Webseite, wie Hugo sie auflöst — für die
 * Konfigurationsprüfung in OpensourceERP (Befehl `shopsiteconfig`).
 *
 * OpensourceERP kennt Kanalkarte und Portaleinstellungen und bewertet, ob die
 * Webseite dazu passt (Mounts des Pakets, Taxonomie und Adressen der
 * Shop-Kategorien, abgeschaltete Seitenarten). HugoCMS nennt hier nur, was
 * ist: einen Auszug aus `hugo config --format json`, die Warnungen, die Hugo
 * dabei ausgibt (abgekündigte Schlüssel), und ob die Übersichtsseiten der
 * Taxonomien vorhanden sind. Die aufgelöste Konfiguration statt der Datei:
 * so stimmt es auch mit `config/_default/`, TOML und den Mounts des Themes.
 */
final class SiteConfig
{
    /**
     * @return array{config: array<string, mixed>, warnings: list<string>, files: array<string, mixed>}
     */
    public static function read(string $bin, string $source): array
    {
        if (!function_exists('proc_open')) {
            throw new ApiException('EHUGO', 500, 'HUGO-CONFIG-FAILED', ['proc_open']);
        }

        $cmd = escapeshellarg($bin) . ' config -s ' . escapeshellarg($source) . ' --format json';
        $pipes = [];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $source);
        if (!is_resource($proc)) {
            throw new ApiException('EHUGO', 500, 'HUGO-CONFIG-FAILED', ['proc_open']);
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $config = json_decode($stdout, true);
        if ($exitCode !== 0 || !is_array($config)) {
            throw new ApiException('EHUGO', 500, 'HUGO-CONFIG-FAILED', [self::firstLine($stderr !== '' ? $stderr : $stdout)]);
        }

        return [
            'config' => self::excerpt($config),
            'warnings' => self::warnings($stderr),
            'files' => self::files($source, $config),
        ];
    }

    /**
     * Was OpensourceERP für seine Prüfungen braucht — nicht die ganze
     * Konfiguration, in der auch Zugangsdaten stehen könnten (services,
     * deployment). Hugo schreibt die Schlüssel klein.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function excerpt(array $config): array
    {
        $mounts = [];
        foreach ((array) ($config['module']['mounts'] ?? []) as $mount) {
            if (is_array($mount) && isset($mount['source'], $mount['target'])) {
                $mounts[] = ['source' => (string) $mount['source'], 'target' => (string) $mount['target']]
                    + (isset($mount['lang']) ? ['lang' => (string) $mount['lang']] : []);
            }
        }
        $params = is_array($config['params'] ?? null) ? $config['params'] : [];

        return [
            'disableKinds' => array_values(array_map('strval', (array) ($config['disablekinds'] ?? []))),
            'taxonomies' => is_array($config['taxonomies'] ?? null) ? $config['taxonomies'] : [],
            // Form je nach Hugo-Version: Liste aus pattern/target (neu) oder
            // Zuordnung nach Seitenart beziehungsweise flach (alt)
            'permalinks' => $config['permalinks'] ?? null,
            'outputs' => is_array($config['outputs'] ?? null) ? $config['outputs'] : [],
            'mounts' => $mounts,
            'contentDir' => (string) ($config['contentdir'] ?? 'content'),
            'dataDir' => (string) ($config['datadir'] ?? 'data'),
            'theme' => array_values(array_map('strval', (array) ($config['theme'] ?? []))),
            'languages' => array_keys(is_array($config['languages'] ?? null) ? $config['languages'] : []),
            'portalui' => is_array($params['portalui'] ?? null) ? $params['portalui'] : null,
        ];
    }

    /**
     * Warnungen von Hugo beim Laden der Konfiguration, ohne das Präfix —
     * etwa „deprecated: project config key languageCode was deprecated …“.
     *
     * @return list<string>
     */
    private static function warnings(string $stderr): array
    {
        $warnings = [];
        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^WARN\s+(.*)$/', $line, $m) === 1 && $m[1] !== '') {
                $warnings[] = $m[1];
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Übersichtsseite jeder Taxonomie (`content/<taxonomie>/_index.md`): ohne
     * sie trägt die Übersicht nur den Namen der Taxonomie als Titel. Gesucht in
     * jedem Verzeichnis, das als `content` eingehängt ist, sonst im contentDir.
     *
     * @param array<string, mixed> $config
     * @return array{taxonomyIndex: array<string, bool>}
     */
    private static function files(string $source, array $config): array
    {
        $roots = [];
        foreach ((array) ($config['module']['mounts'] ?? []) as $mount) {
            if (is_array($mount) && ($mount['target'] ?? '') === 'content' && is_string($mount['source'] ?? null)) {
                $roots[] = $source . '/' . trim($mount['source'], '/');
            }
        }
        if ($roots === []) {
            $roots[] = $source . '/' . trim((string) ($config['contentdir'] ?? 'content'), '/');
        }

        $index = [];
        foreach ((array) ($config['taxonomies'] ?? []) as $plural) {
            if (!is_string($plural) || $plural === '' || preg_match('/^[a-z0-9_-]+$/i', $plural) !== 1) {
                continue;
            }
            $found = false;
            foreach ($roots as $root) {
                if (glob($root . '/' . $plural . '/_index*.md') ?: []) {
                    $found = true;
                    break;
                }
            }
            $index[$plural] = $found;
        }

        return ['taxonomyIndex' => $index];
    }

    private static function firstLine(string $output): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strlen($line) > 400 ? mb_substr($line, 0, 400) . ' …' : $line;
            }
        }

        return 'hugo config';
    }
}
