<?php

declare(strict_types=1);

namespace HugoCMS\FileManager\Shop;

use HugoCMS\FileManager\Exception\ApiException;

/**
 * Download-Dateien der Produktseiten (Datenblätter, Anleitungen).
 *
 * Sie liegen wie die Produktbilder auf der Webseite, nicht in OpensourceERP
 * (E6 in dev/shop-hugocms-trennung.md): OpensourceERP kennt nur die
 * Dateinamen und setzt sie in die Links der Produktseiten. Ob die Datei da
 * ist, sieht nur HugoCMS — es prüft auf Anfrage und nennt, was fehlt, damit
 * der Lauf in OpensourceERP tote Links meldet, bevor ein Kunde sie findet.
 *
 * Nur lesen, nichts schreiben: kein Mount, kein FileService — ein
 * is_file() je Name innerhalb der Freigabe ([shop] downloads).
 */
final class ShopDownloads
{
    public const DEFAULT_DOWNLOADS = 'static/downloads';

    /** Höchstzahl der Namen je Aufruf — wie bei den Vorschaubildern. */
    private const MAX_NAMES = 50000;

    /**
     * @param string $source Hugo-Quellverzeichnis der Webseite
     * @param string $downloadsDir Freigabe, relativ zur Quelle
     */
    public function __construct(
        private readonly string $source,
        private readonly string $downloadsDir,
    ) {
    }

    /**
     * Welche der genannten Dateien fehlen.
     *
     * @param mixed $names Dateinamen ohne Verzeichnis
     * @return array{total: int, missing: list<string>, invalid: list<string>}
     */
    public function check(mixed $names): array
    {
        if (!is_array($names) || !array_is_list($names)) {
            throw ApiException::badRequest('SHOP-DOWNLOADS-INVALID');
        }
        if (count($names) > self::MAX_NAMES) {
            throw ApiException::badRequest('SHOP-DOWNLOADS-TOO-MANY', [self::MAX_NAMES]);
        }

        $missing = [];
        $invalid = [];
        foreach ($names as $name) {
            // Nur ein Dateiname: kein Verzeichnis, nichts Verstecktes — die
            // Namen stammen aus der Artikelmaske von OpensourceERP
            if (!is_string($name) || $name === '' || str_contains($name, '/') || str_contains($name, '\\')
                || str_starts_with($name, '.') || str_contains($name, "\0")) {
                $invalid[] = is_string($name) ? $name : '';
                continue;
            }
            if (!is_file($this->source . '/' . $this->downloadsDir . '/' . $name)) {
                $missing[] = $name;
            }
        }

        return ['total' => count($names), 'missing' => $missing, 'invalid' => $invalid];
    }
}
