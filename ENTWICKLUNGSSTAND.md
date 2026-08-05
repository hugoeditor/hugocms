# Entwicklungsstand

Was in HugoCMS bereits fertig ist und was noch aussteht. Die README beschreibt
die Funktionsweise, diese Datei den Fortschritt.

HugoCMS erscheint als **Rolling Release**, ohne feste Versionssprünge: Die
jeweils getestete Fassung liegt fertig gebaut im Repo
[hugocms-release](https://github.com/hugoeditor/hugocms-release).

*Stand: August 2026.*

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
- Hyperlink-Suche über die Hugo-Quellen und den gebauten Ordner: findet neben der
  eingegebenen Adresse auch abweichende Schreibweisen, mögliche Tippfehler und
  Adressen darunter; die Protokoll-Suche (`http://`) spürt unverschlüsselte
  externe Links auf. Jeder Treffer im gebauten Ordner verweist auf die
  Hugo-Quelle, in der der Link zu korrigieren ist. Der Lauf ist segmentiert und
  braucht dadurch keinen Hintergrundprozess auf dem Server.
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
- **Mehrbenutzer mit Rollen** (`driver = multiuser`): mehrere Konten mit den
  Rollen `admin` und `editor`, je Konto eine INI-Datei, Zuordnung zu Webseiten
  über den Host. Benutzerverwaltung in der Oberfläche (anlegen, Rolle und
  Zuordnung ändern, sperren, fremdes Passwort setzen, löschen); Umschalten
  zwischen Einzel- und Mehrbenutzerbetrieb in beide Richtungen. Was auf einem
  Mount erlaubt ist, entscheiden weiterhin `permissions`/`readonly`.
- **PageSpeed**: Messung der Live-Adresse über Google PageSpeed Insights
  (Kategorie-Scores, Kern-Web-Vitalwerte und CrUX-Felddaten, jüngster Lauf).
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

  Jede Aufgabe vermerkt ihren Lauf selbst und lässt sich einzeln pausieren, ohne
  die Crontab des Hosters zu ändern.
- Systemstatus-Ansicht: Lizenz, hinterlegte Zugänge samt Kontingenten, Zustand
  und geschätzter Takt der Cron-Aufgaben, Warteschlangen (terminierte Freigaben,
  zu verbessernde Dateien) sowie ein Protokoll-Betrachter mit Filter auf
  Warnungen und Fehler und Rotation auf Knopfdruck.
- Einrichtungs-Hinweise und Fehler (fehlendes oder nicht beschreibbares
  Sitzungsverzeichnis, Rückfall auf `mounts.ini`) meldet der Client vor dem Login
  im Klartext.

## Offen

- **Visuelle Bearbeitung von HTML-Dateien.** Der visuelle Editor gilt bisher nur
  für Markdown (und die Hugo-Konfiguration); HTML wird im Code-Editor
  bearbeitet.
