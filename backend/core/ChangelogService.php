<?php

declare(strict_types=1);

namespace HugoCMS\FileManager;

use HugoCMS\FileManager\Exception\ApiException;
use Throwable;

/**
 * Schreibt das Änderungsprotokoll der Webseite fort — eine Seite im
 * Content-Mount (`changelog.md`), die bei jedem Versionsstand einen Abschnitt
 * dazubekommt.
 *
 * Mehrsprachige Hugo-Projekte trennen ihre Sprachen entweder in Verzeichnisse
 * (`content/de`, `content/en`) oder über den Dateinamen (`changelog.de.md`).
 * Eine Seite im Wurzelverzeichnis des Content-Mounts wäre dort entweder gar
 * nicht Teil einer Sprache — Hugo baut sie dann nicht — oder nur Teil der
 * Standardsprache. Deshalb nimmt der Dienst eine LISTE von Zielen entgegen
 * (`[git] changelog_path`), die er alle mit demselben Inhalt fortschreibt: In
 * jeder Sprache steht dann eine Seite, die ein Theme verlinken kann.
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

    /** Zielpfad, solange keiner konfiguriert ist: Wurzel des Content-Mounts. */
    public const string DEFAULT_FILE = 'changelog.md';

    /**
     * Schlüssel im Front Matter neu angelegter Seiten. Hugo führt Seiten mit
     * gleichem `translationKey` als Übersetzungen voneinander — unabhängig von
     * Dateiname und Ablageort. Erst dadurch findet ein Theme aus jeder Sprache
     * die passende Fassung (`.Translations`), statt auf eine feste Adresse zu
     * verweisen, die es in anderen Sprachen nicht gibt.
     */
    private const string TRANSLATION_KEY = 'changelog';

    /** Titel der Seite, wenn sie neu angelegt wird. */
    private const string DEFAULT_TITLE = 'Änderungen';

    /**
     * Zielpfade im Content-Mount, relativ zu dessen Wurzel. Alle bekommen
     * denselben Inhalt.
     *
     * @var list<string>
     */
    private readonly array $targets;

    /**
     * Zuletzt bekannter Stand JE ZIEL, unabhängig davon, was gerade auf der
     * Platte liegt. Nötig für die Wiederherstellung: `read-tree` setzt auch
     * diese Seiten auf den alten Inhalt zurück, wodurch die zwischenzeitlichen
     * Einträge verschwänden. Ein Protokoll, das Einträge verliert, ist keines —
     * und es widerspräche der Zusage der Wiederherstellung, dass die späteren
     * Stände erhalten bleiben. Über {@see pin()} wird der Stand vorher
     * festgehalten und dient danach als Grundlage.
     *
     * Je Ziel ein eigener Eintrag: Die Sprachfassungen können auseinander
     * liegen (eine erst später angelegt, eine von Hand ergänzt), und ein
     * gemeinsamer Stand würde sie beim Schreiben gegenseitig überschreiben.
     *
     * @var array<string, string>
     */
    private array $carry = [];

    /**
     * @param list<string> $targets Zielpfade relativ zur Wurzel des
     *                              Content-Mounts. Leer = {@see DEFAULT_FILE}.
     */
    public function __construct(
        private readonly MountResolver $mounts,
        private readonly FileService $files,
        private readonly Logger $logger,
        array $targets = [],
    ) {
        $this->targets = self::normalizeTargets($targets);
    }

    /**
     * Bereinigt die konfigurierten Zielpfade: getrimmt, ohne führenden Schrägstrich,
     * ohne Doppelte, ohne `..` (das führte aus dem Mount heraus — der
     * MountResolver wiese es ohnehin ab, aber ein stiller Fehlschlag bei jedem
     * Versionsstand hilft niemandem). Bleibt nichts übrig, gilt die Vorgabe.
     *
     * @param list<string> $targets
     * @return list<string>
     */
    private static function normalizeTargets(array $targets): array
    {
        $clean = [];
        foreach ($targets as $target) {
            $rel = trim(str_replace('\\', '/', (string) $target), " \t/");
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $clean[$rel] = true;
        }

        return $clean === [] ? [self::DEFAULT_FILE] : array_keys($clean);
    }

    /**
     * Zielpfade, die dieser Dienst fortschreibt — für Protokollausgaben.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * Hält den derzeitigen Inhalt jeder Seite fest. Vor einer Wiederherstellung
     * aufzurufen, damit die Einträge sie überdauern.
     */
    public function pin(): void
    {
        $this->carry = [];
        foreach ($this->targets as $rel) {
            try {
                $target = $this->mounts->resolve($this->mounts->encodeId(self::MOUNT, $rel), false);
                if (is_file($target['abs'])) {
                    $this->carry[$rel] = (string) $this->files->readText($target['mount'], $target['abs'])['content'];
                }
            } catch (Throwable) {
                // Keine Seite, kein Mount — dann gibt es auch nichts zu bewahren.
            }
        }
    }

    /**
     * Hängt einen Abschnitt für einen Versionsstand an — oben, direkt hinter dem
     * Front Matter: Der neueste Stand interessiert zuerst, und so muss niemand
     * an das Ende einer wachsenden Seite scrollen.
     *
     * @param string  $message  Vollständige Beschreibung des Standes (erste Zeile
     *                          als Überschrift, Rest als Rumpf des Abschnitts).
     * @param ?string $tag      Versionsnummer, wenn eine vergeben wurde.
     * @param string  $tagLabel Wort vor der Nummer in der Überschrift
     *                          („Ausgabe“ / „Edition“). Sichtbarer Text und
     *                          damit sprachabhängig — er kommt deshalb vom
     *                          Client, wie die Beschreibung selbst.
     * @return bool true, wenn mindestens eine Seite geschrieben wurde.
     */
    public function append(string $message, ?string $tag = null, string $tagLabel = ''): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        $written = 0;
        foreach ($this->targets as $rel) {
            try {
                $id = $this->mounts->encodeId(self::MOUNT, $rel);
                // mustExist=false: Beim ersten Mal gibt es die Seite noch nicht;
                // geprüft wird dann das Elternverzeichnis (bei einem Sprachpfad
                // also content/<sprache>).
                $target = $this->mounts->resolve($id, false);

                // Der festgehaltene Stand hat Vorrang vor dem, was gerade auf der
                // Platte liegt — siehe $carry.
                $existing = $this->carry[$rel] ?? (is_file($target['abs'])
                    ? (string) $this->files->readText($target['mount'], $target['abs'])['content']
                    : '');

                $merged = $this->merge($existing, $message, $tag, $tagLabel);
                $this->files->writeText($target['mount'], $target['rel'], $target['abs'], $merged);
                // Für einen zweiten Eintrag im selben Vorgang (Vorab-Sicherung und
                // Wiederherstellung) ist ab jetzt dieser Stand die Grundlage.
                $this->carry[$rel] = $merged;
                ++$written;
            } catch (ApiException | Throwable $e) {
                // Etwa MOUNT-UNKNOWN (kein Content-Mount konfiguriert), ein
                // schreibgeschützter Mount oder ein Sprachverzeichnis, das es
                // (noch) nicht gibt. Die übrigen Ziele werden trotzdem bedient,
                // und der Versionsstand bleibt davon unberührt.
                $this->logger->warning(sprintf(
                    'Änderungsprotokoll %s nicht geschrieben: %s',
                    $rel,
                    $e->getMessage(),
                ));
            }
        }

        return $written > 0;
    }

    /**
     * Erzeugt die Seite komplett NEU aus den übergebenen Versionsständen —
     * neueste zuerst, ein Abschnitt je Stand. Der bisherige Inhalt wird dabei
     * ersetzt; von Hand ergänzter Text geht verloren. Deshalb fragt der Client
     * vorher nach.
     *
     * Anders als {@see append()} steht in jeder Überschrift das Datum des
     * Standes, nicht die aktuelle Zeit — die Seite gibt die Historie wieder.
     *
     * @param list<array{tag: string, date: string, message: string}> $states
     * @return array{sections: int, files: int} Abschnitte je Seite und Anzahl
     *                                          geschriebener Seiten
     */
    public function rebuild(array $states, string $tagLabel = ''): array
    {
        $sections = [];
        foreach ($states as $state) {
            $message = trim((string) ($state['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $sections[] = $this->section(
                $message,
                (string) ($state['tag'] ?? ''),
                $tagLabel,
                (string) ($state['date'] ?? ''),
            );
        }

        $page = $this->header() . "\n" . implode("\n", $sections);

        $written = 0;
        $lastError = null;
        foreach ($this->targets as $rel) {
            try {
                $target = $this->mounts->resolve($this->mounts->encodeId(self::MOUNT, $rel), false);
                $this->files->writeText($target['mount'], $target['rel'], $target['abs'], $page);
                $this->carry[$rel] = $page;
                ++$written;
            } catch (ApiException | Throwable $e) {
                $this->logger->warning(sprintf(
                    'Änderungsprotokoll %s nicht erneuert: %s',
                    $rel,
                    $e->getMessage(),
                ));
                $lastError = $e;
            }
        }

        // Nur wenn KEIN einziges Ziel beschrieben werden konnte, ist der Aufruf
        // gescheitert — sonst bekäme der Benutzer eine Fehlermeldung, obwohl
        // die Seite in den übrigen Sprachen neu steht. Die Zahl der
        // geschriebenen Seiten geht mit an den Client, damit ein
        // Teil-Fehlschlag dort sichtbar wird.
        if ($written === 0 && $lastError !== null) {
            throw $lastError;
        }

        return ['sections' => count($sections), 'files' => $written];
    }

    /**
     * Fügt den neuen Abschnitt in die vorhandene Seite ein — oder legt sie an.
     * Der bestehende Rumpf bleibt wörtlich erhalten; angefasst wird nur das
     * `lastmod`-Datum im Front Matter, damit Hugo die Seite als aktualisiert
     * führt.
     */
    private function merge(string $existing, string $message, ?string $tag, string $tagLabel): string
    {
        $section = $this->section($message, $tag, $tagLabel);

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
    private function section(string $message, ?string $tag, string $tagLabel, string $date = ''): string
    {
        $lines = explode("\n", $message);
        $subject = trim(array_shift($lines));
        $rest = trim(implode("\n", $lines));

        // Die Überschrift trägt die Versionsnummer, wo es eine gibt — sie ist
        // das, was der Benutzer selbst vergeben hat und wiedererkennt. Sonst
        // muss das Datum sie allein tragen. Vor der Nummer steht das Wort aus
        // $tagLabel („Ausgabe 3 — …“); fehlt es, bleibt die Nummer für sich.
        $label = trim($tagLabel);
        $number = $tag !== null ? trim($tag) : '';
        $version = $number === '' ? '' : ($label === '' ? $number : $label . ' ' . $number);
        // Beim Fortschreiben zählt der Augenblick, beim Neuerzeugen das Datum
        // des Standes (ISO-8601 aus der Historie).
        $stamp = $date === '' ? date('d.m.Y H:i') : self::formatDate($date);
        $heading = $version !== ''
            ? sprintf('## %s — %s', $version, $stamp)
            : sprintf('## %s', $stamp);

        $section = $heading . "\n\n" . $subject . "\n";
        if ($rest !== '') {
            $section .= "\n" . $rest . "\n";
        }

        return $section;
    }

    /** ISO-8601-Zeitstempel in die Schreibweise der Überschriften bringen. */
    private static function formatDate(string $iso): string
    {
        $ts = strtotime($iso);

        return $ts === false ? date('d.m.Y H:i') : date('d.m.Y H:i', $ts);
    }

    /**
     * Front Matter einer neu angelegten Seite. Der `translationKey` verbindet
     * die Sprachfassungen miteinander (siehe {@see TRANSLATION_KEY}) — er steht
     * auch bei nur einem Ziel darin, damit eine später hinzukommende Sprache
     * ohne Nacharbeit an der bestehenden Seite dazupasst.
     *
     * Eine BESTEHENDE Seite bekommt ihn nicht nachträglich: Von ihrem Kopf wird
     * ausschließlich `lastmod` angefasst ({@see touchLastmod()}), alles andere
     * gehört dem Benutzer.
     */
    private function header(): string
    {
        $now = date('c');

        return sprintf(
            "---\ntitle: \"%s\"\ntranslationKey: %s\ndate: %s\nlastmod: %s\n---\n",
            self::DEFAULT_TITLE,
            self::TRANSLATION_KEY,
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
