# Mitwirken an HugoCMS

Beiträge sind willkommen: Fehlerberichte, Verbesserungsvorschläge,
Übersetzungen, Dokumentation und Code. Diese Seite beschreibt, was dabei zu
beachten ist.

## Lizenz und Contributor License Agreement

HugoCMS besteht aus zwei Teilen (siehe `LICENSE`): Der Kern steht unter der
GNU Affero General Public License v3 oder später, die Pro-Bestandteile in
`backend/core/Pro/` und `frontend/src/pro/` unter der HugoCMS Pro-Lizenz
(`LICENSE-PRO`). Inter-Data bietet HugoCMS zusätzlich unter kommerziellen
Lizenzen an. Damit das möglich bleibt, nehmen wir Beiträge nur von Personen
und Organisationen an, die das [Contributor License Agreement](CLA.md)
abgeschlossen haben.

Der Ablauf ist einfach: Beim ersten Pull Request bittet der CLA-Assistent per
Kommentar um die Unterschrift. Sie antworten mit dem dort genannten Satz, die
Unterschrift wird in `.github/cla-signatures.json` festgehalten und gilt für
alle weiteren Beiträge. Pull Requests ohne Unterschrift werden nicht gemergt.

Sie behalten alle Rechte an Ihrem Beitrag. Das CLA räumt Inter-Data nur das
Recht ein, den Beitrag auch unter anderen Lizenzen weiterzugeben.

## Lizenzgrenze beachten

Code, der nur mit gültigem Pro-Schlüssel läuft, gehört ausschließlich in die
Pro-Verzeichnisse; jede Datei dort trägt den Kopf
`SPDX-License-Identifier: LicenseRef-HugoCMS-Pro`. Der Kern darf Pro-Klassen
aufrufen (etwa `Connector::run()` oder `AuthFactory`), die Pro-Logik selbst
bleibt aber in `backend/core/Pro/` bzw. `frontend/src/pro/`. Umgekehrt darf
Code im Kern nicht von Pro-Klassen abhängen, um zu funktionieren — Pro-Klassen
bieten Zusatzfunktionen an, sie ersetzen keine Kernfunktion.

## Material Dritter

Code, Bibliotheken, Schriftarten oder Grafiken, die nicht von Ihnen stammen,
kennzeichnen Sie im Pull Request mit Herkunft und Lizenz. Zulässig sind nur
Lizenzen, die mit der AGPL-3.0 verträglich sind und einer späteren
kommerziellen Lizenzierung nicht entgegenstehen, also vorrangig MIT, BSD,
Apache-2.0 und ISC. Neue Copyleft-Abhängigkeiten (GPL, AGPL, LGPL) sprechen
Sie vorher in einem Issue ab. Das PHP-Backend bleibt bewusst ohne Composer und
ohne externe PHP-Abhängigkeiten.

Bei jeder neuen oder geänderten Frontend-Abhängigkeit:

1. `docs/drittkomponenten.md` ergänzen (Paket, Version, Lizenz, Zweck).
2. `npm run licenses` ausführen und die aktualisierte `THIRD_PARTY_LICENSES.md`
   mit einchecken. Voraussetzung: `npm ci` in `frontend/`.

## Entwicklungsumgebung

Einrichtung, Start und Werkzeuge beschreibt die `README.md` (Abschnitt
„Einrichtung & Entwicklung“); `scripts/dev.sh` startet PHP-Server und Vite.

## Richtlinien

Verbindlich für alle Beiträge ist `CLAUDE.md` (Architektur-Prinzipien und
Konventionen). Die wichtigsten Punkte:

- Jede Dateioperation läuft über `FileService`/`MountResolver`; kein Pfad
  verlässt seinen Mount.
- Ein Request, eine JSON-Antwort: kein langlaufender Zustand, kein Streaming.
- Keine externen PHP-Abhängigkeiten; HTTP nach außen über cURL.
- Konfiguration nur über die Klasse `Config`; INI-Schlüssel englisch.
- Jede nutzersichtbare Zeichenkette in `frontend/src/i18n/de.js` UND `en.js`;
  Backend-Fehler tragen nur Codes.
- Kommentare, Commit-Nachrichten und UI-Texte auf klarem Hochdeutsch;
  ausgelieferte Shell-Skripte unter `bin/` auf Englisch.

## Commits und Pull Requests

- Kleine, in sich abgeschlossene Commits mit aussagekräftiger Nachricht auf
  Deutsch.
- Ein Pull Request behandelt ein Thema. Beschreiben Sie, was sich ändert und
  warum, und wie es geprüft wurde.
- Prüfungen vor dem Einreichen: `php -l` für jede geänderte PHP-Datei und
  `npm run --prefix frontend build`.

## Fehler melden

Nutzen Sie die Issues auf GitHub. Hilfreich sind: Buildnummer (Versionsdialog),
Browser, Schritte zum Nachstellen, erwartetes und tatsächliches Verhalten sowie
Auszüge aus `backend/log/hugocms.log`. Sicherheitsrelevante Fehler melden Sie
bitte nicht öffentlich, sondern an die in der README genannte Kontaktadresse.
