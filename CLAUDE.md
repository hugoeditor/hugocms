# CLAUDE.md — Arbeitshinweise für HugoCMS

Webbasierter Dateimanager für Hugo-Websites. Backend in PHP, Frontend Vue 3 +
Vuetify 3 + Vite. Diese Datei fasst zusammen, was beim Arbeiten *im Code* nicht
offensichtlich ist — die ausführliche Funktionsübersicht steht in der README.

## Architektur-Prinzipien (nicht verletzen ohne Rückfrage)

- **PHP ohne Composer.** Es gibt bewusst keine `composer.json`. Keine externen
  PHP-Abhängigkeiten einführen; Eigenes über den PSR-4-Autoloader in
  `backend/core/autoload.php`. HTTP nach außen (z. B. Claude-API) über **cURL**,
  nicht über ein SDK (siehe `AnthropicClient.php`).
- **Schlankes, zustandsloses Backend.** Ein Request → eine JSON-Antwort
  (`Connector::run()` → `match` über `cmd`). Kein langlaufender Zustand, keine
  SSE/Streaming-Sonderwege — das würde die Hosting-Kompatibilität (Shared
  Hosting, Worker-/Puffergrenzen) brechen.
- **Breite Hosting-Kompatibilität.** Läuft auf einfachem PHP; `bin/install.sh`
  nutzt das Kopierverfahren **ohne Symlinks**. Nichts einbauen, das eine
  kontrollierte Serverumgebung voraussetzt.
- **Mount-Sicherheit ist die Grenze.** Jeder Pfad wird über `MountResolver` als
  undurchsichtige ID adressiert und in seinen Mount eingesperrt (`..` verboten).
  Neue Datei-Operationen IMMER über `FileService`/`MountResolver` führen, damit
  Einsperrung, `permissions`/`readonly` und erlaubte Endungen greifen. Der
  KI-Assistent (`AssistantService`) nutzt genau diese Schicht.

## Zentrale Bausteine

- **`Config`** liest UND schreibt die `hugocms.ini` (zentrale Kapsel:
  `updateSections` erhält nicht genannte Sektionen wörtlich, schreibt atomar).
  Setup, Reconfigure und Account-Änderung gehen alle über `Config` — INI nie an
  anderer Stelle von Hand zusammenbauen.
- **Auth ist treiberabstrahiert.** `AuthInterface` (Impl. `SingleUser`); die
  Persistenz der Anmeldedaten macht der Treiber selbst (`changeCredentials`),
  nicht der Connector. Für Mehrbenutzer einen neuen Treiber bauen, nicht den
  Connector erweitern.
- **Geheimnisse** (Passwort-Hash, `[ai] api_key`) werden nie an den Client
  zurückgegeben; in Formularen heißt „Feld leer = unverändert".
- **KI-Assistent**: `AssistantService` (Tool-Loop, 3 Schreibmodi
  readonly/confirm/auto), `AnthropicClient` (cURL). Modell-Default
  `claude-opus-5`. Tools sind dünne Hüllen um `FileService`.

## Verifikation

- Backend: `php -l backend/core/<Datei>.php` nach jeder Änderung.
- Frontend: `npm run --prefix frontend build` (oder `scripts/build.sh`).
- Release: `scripts/packaging.sh` (committet + pusht das `hugocms-release`-Repo;
  `--no-push` / `--no-commit` zum Einschränken).
- Es gibt kein PHP-Test-Framework; Logik bei Bedarf mit kleinen Wegwerf-Skripten
  gegen den Autoloader prüfen (siehe bisherige Round-Trip-Tests).

## Konventionen

- **Sprache**: Kommentare, Commit-Messages und UI-Texte auf klarem Hochdeutsch
  (siehe globale `~/.claude/CLAUDE.md`). Fachbegriffe (Mount, Commit, Cache)
  bleiben.
- **Shell-Skripte auf Englisch**: Skripte unter `bin/` (und generell alle
  Shell-Skripte) sind für Profi-Admins gedacht — ihr Kommentar- und Terminal-
  Ausgabe-Text ist **englisch**.
- **Erzeugte Mount-Datei mehrsprachig**: Der lesbare Text der von `install.sh`
  erzeugten `mounts/<hash>.ini` (die `label`-Werte im CMS-Dateibaum, die
  Kopfzeilen und der `[hugo]`-Kommentar) folgt `install.sh --lang=en|de`
  (**Default `en`**) über EINE Übersetzungstabelle — die Block-Vorlagen liegen
  nur einmal vor. Die Sektions-IDs (`[content]` usw.) sind intern und bleiben
  sprachunabhängig. Die Host-Kopfzeile `; HugoCMS – Mounts f(ür|or) <HOST> (…)`
  ist ein **geparster Vertrag**; die Parser in `bin/sites.sh` und
  `bin/crontab-entries.sh` erkennen sie in beiden Sprachen.
- **i18n**: jede nutzersichtbare Zeichenkette in `frontend/src/i18n/de.js` UND
  `en.js`. Backend-Fehler tragen nur Codes/Schlüssel; der Client übersetzt.
- **`*.ini`** ist gitignored (instanzspezifisch); nur `*.ini.beispiel` gehört
  ins Repo.

## Verhalten

- **Niemals eigenständig committen oder pushen** (`git add`/`commit`/`push`) —
  nur auf ausdrückliche Aufforderung.
- Bei Architekturentscheidungen, die die obigen Prinzipien berühren, vorher
  Rücksprache halten statt Annahmen zu treffen.
