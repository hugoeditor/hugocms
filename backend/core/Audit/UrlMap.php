<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Audit;

/**
 * Verzeichnis aller im public/-Ordner vorhandenen Request-Pfade — die
 * Grundlage für die Prüfung interner Links. Tolerant gegenüber den üblichen
 * Hugo-Varianten (Pretty-URLs mit/ohne Schrägstrich, index.html).
 */
final class UrlMap
{
    /**
     * Dateinamen, die ein Verzeichnis als Standardseite ausliefern. Neben
     * Hugos index.html zählen dazu die per DirectoryIndex servierten
     * Handler — z. B. ein index.php-Formular unter /anfrage/. Ohne sie würde
     * ein Link auf das Verzeichnis (/anfrage/) fälschlich als ins Leere
     * führend gemeldet, obwohl der Server es korrekt ausliefert.
     */
    private const INDEX_FILES = ['index.html', 'index.htm', 'index.php'];

    /** @var array<string, true> Menge bekannter Pfade (mit führendem /). */
    private array $known = [];

    /**
     * @param list<string> $files Dateipfade relativ zu public/ (Schrägstriche).
     */
    public function __construct(array $files)
    {
        foreach ($files as $rel) {
            $path = '/' . ltrim(str_replace('\\', '/', $rel), '/');
            $this->known[$path] = true;
            // Verzeichnis-Index → das Verzeichnis selbst (Pretty-URL) mit und ohne /.
            foreach (self::INDEX_FILES as $index) {
                if (str_ends_with($path, '/' . $index)) {
                    $dir = substr($path, 0, -strlen($index)); // endet mit /
                    $this->known[$dir] = true;
                    $trimmed = rtrim($dir, '/');
                    $this->known[$trimmed === '' ? '/' : $trimmed] = true;
                    break;
                }
            }
        }
    }

    /** Ist der (bereits normalisierte) Pfad bekannt? Prüft gängige Varianten. */
    public function has(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $candidates = [
            $path,
            rtrim($path, '/'),
            rtrim($path, '/') . '/',
            rtrim($path, '/') . '/index.html',
        ];
        foreach ($candidates as $c) {
            if ($c !== '' && isset($this->known[$c])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalisiert einen href in einen seiteninternen Request-Pfad oder null,
     * falls der Link extern, ein Anker, mailto/tel o. Ä. ist (also nicht zu
     * prüfen).
     */
    public static function internalTarget(string $pageUrl, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        // Fragment und Query abschneiden.
        $href = preg_replace('/[#?].*$/', '', $href) ?? $href;
        if ($href === '') {
            return null;
        }
        // Schema-relative und absolute externe Adressen: nicht intern.
        if (str_starts_with($href, '//') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $href) === 1) {
            return null;
        }

        if ($href[0] === '/') {
            $path = $href;
        } else {
            // Relativ zum Verzeichnis der aktuellen Seite auflösen.
            $base = preg_replace('#/[^/]*$#', '/', $pageUrl) ?? '/';
            if (!str_ends_with($base, '/')) {
                $base .= '/';
            }
            $path = $base . $href;
        }

        return self::collapse($path);
    }

    /** Löst ./ und ../ in einem absoluten Pfad auf. */
    private static function collapse(string $path): string
    {
        $trailing = str_ends_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        $result = '/' . implode('/', $segments);
        if ($trailing && $result !== '/') {
            $result .= '/';
        }

        return rawurldecode($result);
    }
}
