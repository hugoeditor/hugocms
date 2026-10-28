<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use RuntimeException;

/**
 * Hugo-Lauf über ein Zwischenverzeichnis.
 *
 * Hugo baut nicht mehr direkt in das Zielverzeichnis, sondern in ein frisches
 * Zwischenverzeichnis daneben. Erst nach einem erfolgreichen Lauf tauschen
 * zwei rename()-Aufrufe das bisherige Ziel gegen den neuen Stand. Scheitert
 * der Lauf, bleibt die veröffentlichte Webseite unverändert online.
 *
 * Hintergrund: Mit --cleanDestinationDir gleicht Hugo zuerst static/ mit dem
 * Ziel ab und löscht dabei alle erzeugten Seiten. Bricht der Bau danach ab
 * (etwa an einem fehlenden Shortcode), steht die Webseite ohne eine einzige
 * HTML-Seite da.
 *
 * Der Tausch übernimmt aus dem bisherigen Ziel, was auch ein direkter Lauf
 * dort gelassen hätte:
 *   - ohne clean alles, was der neue Lauf nicht selbst erzeugt hat,
 *   - mit clean nur versteckte Verzeichnisse (etwa .well-known/) — sie lässt
 *     auch Hugos --cleanDestinationDir auf jeder Ebene stehen.
 * Übernommen wird per Hardlink (Kopie als Rückfall). Das alte Ziel bleibt
 * dabei bis zum Tausch unberührt.
 *
 * Zwischenverzeichnis und abgelöster Stand liegen als versteckte Geschwister
 * des Ziels (.<ziel>.staging, .<ziel>.previous). Nur so liegen sie im selben
 * Dateisystem, und rename() tauscht ohne Kopieren. Dafür braucht der
 * Webserver-Benutzer Schreibrechte im übergeordneten Verzeichnis des Ziels;
 * fehlen sie, baut der Aufrufer wie bisher direkt ({@see available()}).
 *
 * Reste eines abgebrochenen Laufs räumt {@see prepare()} weg. Fehlt das Ziel
 * nach einem Abbruch zwischen den beiden rename()-Aufrufen, holt es den
 * abgelösten Stand zurück.
 */
final class StagedBuild
{
    /** Tatsächliches Ziel (Symlinks aufgelöst), ohne abschließenden Schrägstrich. */
    private readonly string $target;

    private readonly string $staging;

    private readonly string $previous;

    /**
     * @param string $destination Zielverzeichnis aus der [hugo]-Konfiguration
     */
    public function __construct(string $destination)
    {
        $destination = rtrim($destination, '/');
        // Ein symbolischer Link als Ziel bleibt bestehen: getauscht wird das
        // Verzeichnis, auf das er zeigt.
        $real = realpath($destination);
        $this->target = $real !== false ? $real : $destination;

        $parent = dirname($this->target);
        $name = basename($this->target);
        $this->staging = $parent . '/.' . $name . '.staging';
        $this->previous = $parent . '/.' . $name . '.previous';
    }

    /**
     * Ob der Lauf über das Zwischenverzeichnis gehen kann: Das übergeordnete
     * Verzeichnis des Ziels muss existieren und beschreibbar sein.
     */
    public function available(): bool
    {
        $parent = dirname($this->target);

        return is_dir($parent) && is_writable($parent);
    }

    /** Verzeichnis, in das Hugo bauen soll (-d). */
    public function stagingDir(): string
    {
        return $this->staging;
    }

    /**
     * Räumt Reste eines abgebrochenen Laufs weg, damit Hugo in ein leeres
     * Zwischenverzeichnis baut.
     *
     * @throws RuntimeException wenn ein Rest nicht entfernt werden kann
     */
    public function prepare(): void
    {
        // Abbruch zwischen den beiden rename(): das Ziel fehlt, der abgelöste
        // Stand liegt noch daneben — er geht zuerst wieder online.
        if (!file_exists($this->target) && is_dir($this->previous)) {
            if (!@rename($this->previous, $this->target)) {
                throw new RuntimeException("Abgelöster Stand {$this->previous} konnte nicht zurückgeholt werden");
            }
        }
        $this->removeTree($this->staging);
        $this->removeTree($this->previous);
        if (file_exists($this->staging) || file_exists($this->previous)) {
            throw new RuntimeException("Reste eines früheren Laufs neben {$this->target} lassen sich nicht entfernen");
        }
    }

    /**
     * Veröffentlicht den gebauten Stand: übernimmt, was ein direkter Lauf im
     * Ziel gelassen hätte, und tauscht dann Ziel und Zwischenverzeichnis.
     *
     * @param bool $clean true = wie --cleanDestinationDir: aus dem alten Ziel
     *                    nur versteckte Verzeichnisse übernehmen
     * @throws RuntimeException wenn Übernahme oder Tausch scheitern — das
     *                          bisherige Ziel ist dann weiter online
     */
    public function publish(bool $clean): void
    {
        if (!is_dir($this->staging)) {
            throw new RuntimeException("Zwischenverzeichnis {$this->staging} fehlt");
        }
        if (!is_dir($this->target)) {
            // Erster Lauf: nichts zu übernehmen, nichts abzulösen
            if (!@rename($this->staging, $this->target)) {
                throw new RuntimeException("{$this->staging} konnte nicht nach {$this->target} verschoben werden");
            }

            return;
        }

        $this->carryOver($this->target, $this->staging, !$clean);

        if (!@rename($this->target, $this->previous)) {
            throw new RuntimeException("{$this->target} konnte nicht nach {$this->previous} verschoben werden");
        }
        if (!@rename($this->staging, $this->target)) {
            // Den alten Stand sofort zurück — lieber veraltet als gar nicht online
            @rename($this->previous, $this->target);
            throw new RuntimeException("{$this->staging} konnte nicht nach {$this->target} verschoben werden");
        }
        $this->removeTree($this->previous);
    }

    /** Entfernt das Zwischenverzeichnis eines gescheiterten Laufs. */
    public function discard(): void
    {
        $this->removeTree($this->staging);
    }

    /**
     * Übernimmt Einträge aus dem alten Ziel ins Zwischenverzeichnis, sofern der
     * neue Lauf sie nicht selbst erzeugt hat. Was der neue Lauf erzeugt hat,
     * hat immer Vorrang.
     *
     * @param bool $keepAll true = alles übernehmen; false = nur versteckte
     *                      Verzeichnisse (samt Inhalt), wie Hugos
     *                      --cleanDestinationDir
     */
    private function carryOver(string $from, string $to, bool $keepAll): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;
            $isDir = is_dir($src) && !is_link($src);
            $exists = file_exists($dst) || is_link($dst);
            $keep = $keepAll || ($isDir && str_starts_with($entry, '.'));

            if ($isDir) {
                if ($exists) {
                    // Gibt es das Verzeichnis auch im neuen Stand, kann darin
                    // noch etwas zu übernehmen sein (mit clean: tiefer liegende
                    // versteckte Verzeichnisse).
                    if (is_dir($dst) && !is_link($dst)) {
                        $this->carryOver($src, $dst, $keep);
                    }
                    continue;
                }
                if (!$keep) {
                    continue;
                }
                $mode = fileperms($src);
                if (!@mkdir($dst, $mode !== false ? $mode & 0o7777 : 0o755) && !is_dir($dst)) {
                    throw new RuntimeException("Verzeichnis {$dst} konnte nicht angelegt werden");
                }
                $this->carryOver($src, $dst, true);
                continue;
            }

            if (!$keep || $exists) {
                continue;
            }
            if (is_link($src)) {
                $link = readlink($src);
                if ($link === false || !@symlink($link, $dst)) {
                    throw new RuntimeException("Symbolischer Link {$dst} konnte nicht angelegt werden");
                }
                continue;
            }
            // Hardlink: kostet keinen Platz und lässt das alte Ziel unberührt.
            // Hugo ist zu diesem Zeitpunkt fertig, schreibt also nicht mehr
            // hinein. Über Dateisystemgrenzen oder ohne Erlaubnis: Kopie.
            if (!@link($src, $dst) && !@copy($src, $dst)) {
                throw new RuntimeException("Datei {$src} konnte nicht übernommen werden");
            }
        }
    }

    /** Entfernt ein Verzeichnis samt Inhalt, ohne symbolischen Links zu folgen. */
    private function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
