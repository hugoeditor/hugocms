# Entwicklungsstand

Was in HugoCMS bereits fertig ist und was noch aussteht. Die README beschreibt
die Funktionsweise, diese Datei den Fortschritt.

HugoCMS erscheint als **Rolling Release**, ohne feste Versionssprünge: Die
jeweils getestete Fassung liegt fertig gebaut im Repo
[hugocms-release](https://github.com/hugoeditor/hugocms-release).

*Stand: Juli 2026.*

## Fertig und im Einsatz

### Grundlage

- Anmeldung (Einzelbenutzer-Treiber), Sitzungsverwaltung, CSRF-Schutz für alle
  Schreibbefehle.
- Einrichtungs-Setup im Browser: Ohne `hugocms.ini` erzeugt ein Formular die
  Konfiguration samt Passwort-Hash; danach ist der Benutzer angemeldet.
- Mandantenfähigkeit: Eine Installation bedient mehrere Webseiten, die Mounts
  werden über den aufgerufenen Host aufgelöst (`mounts/<hash>.ini`).
- Mount-Sicherheit: Jeder Pfad wird als undurchsichtige ID adressiert, in seinen
  Mount eingesperrt und gegen Rechte, `readonly` und erlaubte Endungen geprüft.
- Zweisprachige Oberfläche (Deutsch/Englisch); Backend-Fehler tragen nur Codes,
  der Client übersetzt.
- Auslieferungsweg: `scripts/packaging.sh` (Release-Repo bauen, committen,
  pushen), `bin/install.sh` (Webseite einrichten, ohne Symlinks),
  `bin/get-hugo.sh` (Hugo beschaffen).

### Inhalte verwalten

- Verzeichnisse auflisten, Dateien lesen und speichern (mit Konfliktschutz gegen
  zwischenzeitliche Änderungen).
- Anlegen, umbenennen, kopieren, verschieben, löschen (Papierkorb mit
  Wiederherstellen und Leeren), Mehrfachauswahl, Kontextmenü.
- Hochladen per Ziehen-und-Ablegen, Herunterladen, Bildvorschauen (GD),
  Bildbetrachter, Symbolansicht, rekursive Namenssuche.
- Bild-Editor: Rasterbilder zuschneiden und bearbeiten, Ergebnis überschreibt das
  Original oder wird als Kopie abgelegt.

### Bearbeiten

- Code-Editor (CodeMirror) mit Syntaxhervorhebung für Markdown, HTML, CSS,
  JavaScript, JSON, TOML/YAML und Hugo-Vorlagen, Suche, Faltung und
  Kommentar-Umschaltung.
- Visueller Markdown-Editor (TipTap) samt Dialogen für interne und externe Links.
- Front Matter wird geschützt und in einem eigenen Bereich bearbeitet;
  `lastmod` wird auf Wunsch beim Speichern gesetzt.
- Editor für die Hugo-Konfiguration einschließlich Menüpflege.

### Veröffentlichen

- **Veröffentlichen-Knopf**: ruft Hugo für die aufgerufene Webseite auf; die
  Ausgabe erscheint im Dialog. Ungespeicherte Änderungen werden vorher gesichert.
- **Freigabe-Warteschlange**: Änderungen können als Entwurf abgelegt, mit
  zeilenweisem Vergleich geprüft und sofort oder terminiert freigegeben werden.
  Bei einer terminierten Freigabe bleibt die bisherige Fassung bis zum Termin
  veröffentlicht und wird dann ersetzt.

### KI-Assistent (optional, Anthropic-Schlüssel nötig)

- Chat-Assistent mit Hugo-Wissen, der über dieselbe Dateischicht wie die
  Oberfläche arbeitet — also unter denselben Grenzen.
- Drei Schreibmodi (`readonly`, `confirm`, `auto`); im Bestätigungsmodus zeigt
  eine Vorschau den Unterschied vor dem Schreiben.
- Kontext aus Editor und Dateiansicht; projektweite Anweisungen über
  `.hugocms-assistant.md`.
- Bereitschaftsprüfung des Schlüssels und Abruf der verfügbaren Modelle aus der
  API statt fest verdrahteter Liste.

### Pro-Funktionen (Lizenzschlüssel je Webseite)

- **SEO-Check**: regelbasierte Prüfung der gebauten Webseite ohne externe
  Dienste; Berichte werden als Verlauf vorgehalten, gefiltert und verglichen.
  Jeder Fund verweist auf eine Regel-Erklärung und die betroffene Quelldatei.
- **Content-Qualität**: KI-Urteil je Inhaltsdatei (Punktzahl, Befunde,
  Vorschläge), Gesamt-Bericht aus Qualitätsurteil und SEO-Funden, direkte
  KI-Verbesserung der Datei, Arbeitsliste „Zu verbessern".
- **Git-Versionierung**: Status, Verlauf, Diff, Commit, Push, Zurücksetzen.
- **PageSpeed**: Messung der Live-Adresse über Google PageSpeed Insights
  (Kategorie-Scores und Kern-Web-Vitalwerte, jüngster Lauf).
- **Live-Analyse**: Crawl der Produktionsseite über den externen Dienst
  seo-success mit Note, Verlaufskurve, Befunden und Export.
- **Spracheingabe**: Diktat über denselben Dienst; der Dienstschlüssel bleibt
  serverseitig.

### Betrieb

- Konfiguration im laufenden Betrieb änderbar: Sitzung, Log, Hugo-Programm,
  KI-Einstellungen, Projekteinstellungen (unter anderem Ausschlüsse für den
  SEO-Check) sowie Anmeldename und Passwort.
- Lizenz-Dialog: Schlüssel aktivieren, Edition und gebundene Domain ansehen.
- Hilfe: eingebaute Wissensdatenbank (Markdown) für die ganze Anwendung,
  einschließlich Erklärungen zu allen SEO-Regeln.
- Kommandozeilen-Werkzeuge für Cron:
  - `cron-build.php` — bauen und fällige terminierte Freigaben anwenden,
  - `cron-improve.php` — Inhalte automatisch per KI verbessern,
  - `cron-healthcheck.php` — SEO-Check des gebauten Standes und Benachrichtigung
    per E-Mail bei Fehlern oder Warnungen (eigener SMTP-Versand).
- Einrichtungs-Hinweise und Fehler (fehlendes oder nicht beschreibbares
  Sitzungsverzeichnis, Rückfall auf `mounts.ini`) meldet der Client vor dem Login
  im Klartext.

## Offen

- **Mehrbenutzer mit Rollen.** Die Anmeldung gilt bislang installationsweit; der
  Auth-Treiber ist dafür bereits abstrahiert (`AuthInterface`), Rechte je Mount
  (`permissions`, `readonly`) sind die vorhandene Grundlage.
