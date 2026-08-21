<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;
use Throwable;

/**
 * Schreibt das Änderungsprotokoll der Webseite fort — eine einzelne Seite im
 * Content-Mount (`changelog.md`), die bei jedem Versionsstand einen Abschnitt
 * dazubekommt.
 *
 * Die Datei liegt im Content-Verzeichnis des Hugo-Projekts und damit im selben
 * Git-Repository. Geschrieben wird deshalb IMMER vor `git add -A`, sodass der
 * neue Abschnitt in genau dem Versionsstand liegt, den er beschreibt — sonst
 * bliebe er nach jedem Sichern als offene Änderung liegen und der Arbeitsbaum
 * würde nie sauber.
 *
 * Alle Dateizugriffe laufen über {@see FileService} und {@see MountResolver}:
 * Damit greifen Einsperrung, `permissions`/`readonly` und die erlaubten
 * Endungen des Mounts genauso wie bei jeder anderen Bearbeitung.
 *
 * Ein Fehlschlag ist NIE ein Fehler des Versionsstands. Fehlt der Content-Mount,
 * ist er schreibgeschützt oder scheitert das Schreiben, wird das protokolliert
 * und der Commit läuft weiter — das Protokoll ist Beiwerk, der Versionsstand
 * ist die Hauptsache.
 */
final class ChangelogService
{
    /** Name des Content-Mounts. Konvention der von install.sh erzeugten Datei. */
    public const string MOUNT = 'content';

    /** Dateiname im Wurzelverzeichnis des Content-Mounts. */
    public const string FILE = 'changelog.md';

    /** Titel der Seite, wenn sie neu angelegt wird. */
    private const string DEFAULT_TITLE = 'Änderungen';

    /**
     * Zuletzt bekannter Stand der Seite, unabhängig davon, was gerade auf der
     * Platte liegt. Nötig für die Wiederherstellung: `read-tree` setzt auch
     * diese Seite auf den alten Inhalt zurück, wodurch die zwischenzeitlichen
     * Einträge verschwänden. Ein Protokoll, das Einträge verliert, ist keines —
     * und es widerspräche der Zusage der Wiederherstellung, dass die späteren
     * Stände erhalten bleiben. Über {@see pin()} wird der Stand vorher
     * festgehalten und dient danach als Grundlage.
     */
    private ?string $carry = null;

    public function __construct(
        private readonly MountResolver $mounts,
        private readonly FileService $files,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Hält den derzeitigen Inhalt der Seite fest. Vor einer Wiederherstellung
     * aufzurufen, damit die Einträge sie überdauern.
     */
    public function pin(): void
    {
        try {
            $target = $this->mounts->resolve($this->mounts->encodeId(self::MOUNT, self::FILE), false);
            if (is_file($target['abs'])) {
                $this->carry = (string) $this->files->readText($target['mount'], $target['abs'])['content'];
            }
        } catch (Throwable) {
            // Keine Seite, kein Mount — dann gibt es auch nichts zu bewahren.
            $this->carry = null;
        }
    }

    /**
     * Hängt einen Abschnitt für einen Versionsstand an — oben, direkt hinter dem
     * Front Matter: Der neueste Stand interessiert zuerst, und so muss niemand
     * an das Ende einer wachsenden Seite scrollen.
     *
     * @param string  $message Vollständige Beschreibung des Standes (erste Zeile
     *                         als Überschrift, Rest als Rumpf des Abschnitts).
     * @param ?string $tag     Versionsnummer, wenn eine vergeben wurde.
     * @return bool true, wenn die Seite geschrieben wurde.
     */
    public function append(string $message, ?string $tag = null): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        try {
            $id = $this->mounts->encodeId(self::MOUNT, self::FILE);
            // mustExist=false: Beim ersten Mal gibt es die Seite noch nicht;
            // geprüft wird dann das Elternverzeichnis (das Mount-Wurzelverzeichnis).
            $target = $this->mounts->resolve($id, false);

            // Der festgehaltene Stand hat Vorrang vor dem, was gerade auf der
            // Platte liegt — siehe $carry.
            $existing = $this->carry ?? (is_file($target['abs'])
                ? (string) $this->files->readText($target['mount'], $target['abs'])['content']
                : '');

            $merged = $this->merge($existing, $message, $tag);
            $this->files->writeText($target['mount'], $target['rel'], $target['abs'], $merged);
            // Für einen zweiten Eintrag im selben Vorgang (Vorab-Sicherung und
            // Wiederherstellung) ist ab jetzt dieser Stand die Grundlage.
            $this->carry = $merged;

            return true;
        } catch (ApiException | Throwable $e) {
            // Etwa MOUNT-UNKNOWN (kein Content-Mount konfiguriert) oder ein
            // schreibgeschützter Mount. Der Versionsstand bleibt davon unberührt.
            $this->logger->warning('Änderungsprotokoll nicht geschrieben: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Fügt den neuen Abschnitt in die vorhandene Seite ein — oder legt sie an.
     * Der bestehende Rumpf bleibt wörtlich erhalten; angefasst wird nur das
     * `lastmod`-Datum im Front Matter, damit Hugo die Seite als aktualisiert
     * führt.
     */
    private function merge(string $existing, string $message, ?string $tag): string
    {
        $section = $this->section($message, $tag);

        if (trim($existing) === '') {
            return $this->header() . "\n" . $section;
        }

        [$frontMatter, $body] = $this->split($existing);
        if ($frontMatter === null) {
            // Seite ohne Front Matter (von Hand angelegt): Kopf ergänzen, den
            // vorhandenen Text als Rumpf behalten.
            return $this->header() . "\n" . $section . "\n" . ltrim($body, "\n");
        }

        return $this->touchLastmod($frontMatter) . "\n" . $section . "\n" . ltrim($body, "\n");
    }

    /** Ein Abschnitt: Überschrift aus Versionsnummer und Datum, darunter der Text. */
    private function section(string $message, ?string $tag): string
    {
        $lines = explode("\n", $message);
        $subject = trim(array_shift($lines));
        $rest = trim(implode("\n", $lines));

        // Die Überschrift trägt die Versionsnummer, wo es eine gibt — sie ist
        // das, was der Benutzer selbst vergeben hat und wiedererkennt. Sonst
        // muss das Datum sie allein tragen.
        $heading = $tag !== null && $tag !== ''
            ? sprintf('## %s — %s', $tag, date('d.m.Y H:i'))
            : sprintf('## %s', date('d.m.Y H:i'));

        $section = $heading . "\n\n" . $subject . "\n";
        if ($rest !== '') {
            $section .= "\n" . $rest . "\n";
        }

        return $section;
    }

    /** Front Matter einer neu angelegten Seite. */
    private function header(): string
    {
        $now = date('c');

        return sprintf(
            "---\ntitle: \"%s\"\ndate: %s\nlastmod: %s\n---\n",
            self::DEFAULT_TITLE,
            $now,
            $now,
        );
    }

    /**
     * Trennt den führenden Front-Matter-Block vom Rumpf. Erkannt werden YAML
     * (`---`) und TOML (`+++`) wie in {@see Review\FrontMatter}; ein
     * JSON-Front-Matter wird bewusst NICHT angefasst, sondern wie „kein Front
     * Matter“ behandelt — dann bleibt die Datei unversehrt, statt dass ein
     * halbverstandener Kopf umgeschrieben wird.
     *
     * @return array{0: ?string, 1: string} Front-Matter-Block (mit Begrenzern) und Rumpf
     */
    private function split(string $raw): array
    {
        if (preg_match('/^(---|\+\+\+)\R.*?\R\1[ \t]*(?:\R|$)/s', $raw, $m) === 1) {
            return [$m[0], substr($raw, strlen($m[0]))];
        }

        return [null, $raw];
    }

    /**
     * Setzt `lastmod` im Front-Matter-Block auf jetzt (oder ergänzt es). Der
     * übrige Kopf — Titel, eigene Felder, Reihenfolge — bleibt unangetastet.
     */
    private function touchLastmod(string $frontMatter): string
    {
        $now = date('c');
        $isToml = str_starts_with($frontMatter, '+++');
        $line = $isToml ? 'lastmod = ' . $now : 'lastmod: ' . $now;

        $replaced = preg_replace(
            '/^[ \t]*lastmod[ \t]*[:=].*$/mi',
            $line,
            $frontMatter,
            1,
            $count,
        );
        if ($replaced !== null && $count > 0) {
            return $replaced;
        }

        // Nicht vorhanden: vor dem schließenden Begrenzer einfügen.
        $delim = $isToml ? '+++' : '---';
        $pos = strrpos($frontMatter, $delim);
        if ($pos === false) {
            return $frontMatter;
        }

        return substr($frontMatter, 0, $pos) . $line . "\n" . substr($frontMatter, $pos);
    }
}
