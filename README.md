# HugoCMS – Dateimanager

Ein webbasierter Dateimanager als elFinder-Ersatz. Backend in PHP, Frontend
mit Vue 3, Vuetify 3 und Vite. Bearbeiten von Text- und Markdown-Dateien,
mehrere Wurzelverzeichnisse (Mounts) und ein zentraler Papierkorb.

> **Stand: Stufe 1.** Anmeldung, Mounts, Verzeichnisse auflisten sowie
> Textdateien lesen und speichern. Dateioperationen (anlegen, löschen,
> umbenennen, kopieren), Upload, Bildanzeige und Papierkorb folgen in den
> nächsten Stufen.

## Architektur

```
hugocms-2026/
├── index.php                # Beispiel-Bootstrap (selbst geschrieben)
├── backend/
│   ├── autoload.php         # PSR-4-Autoloader (ohne Composer)
│   ├── Connector.php        # Einstiegspunkt: mount(), run()
│   ├── MountResolver.php    # Mounts, ID-Kodierung, Einsperrung
│   ├── Mount.php            # Einhängepunkt (Pfad, Rechte, Endungen)
│   ├── FileService.php      # list, read, write
│   ├── Response.php         # JSON-Antworten
│   ├── Auth/               # AuthInterface + SingleUser
│   └── Exception/
├── daten/                   # Beispiel-Datenverzeichnis (Mounts)
├── frontend/                # Vue 3 + Vuetify 3 + Vite
└── beispiel-konfigurationen/  # Apache- und Nginx-Vorlagen
```

Das Backend kennt keine festen Pfade. Welche Verzeichnisse als Mounts
erreichbar sind, legt eine selbst geschriebene `index.php` programmatisch fest:

```php
require __DIR__ . '/backend/autoload.php';

use HugoCMS\FileManager\Auth\SingleUser;
use HugoCMS\FileManager\Connector;

$connector = new Connector([
    'auth' => new SingleUser('admin', $passwordHash),
]);

$connector->mount('inhalte',  '/pfad/zu/content',  ['label' => 'Inhalte']);
$connector->mount('vorlagen', '/pfad/zu/layouts',  ['label' => 'Vorlagen', 'permissions' => ['read', 'write']]);
$connector->run();
```

Jeder Pfad wird mount-relativ als undurchsichtige ID adressiert; der echte
Serverpfad verlässt das Backend nie. `MountResolver` sperrt jeden aufgelösten
Pfad in seinen Mount ein und wehrt `../`-Angriffe ab.

## Einrichtung

### Voraussetzungen

- PHP 8.1 oder neuer (mit `fileinfo`, später `gd` für Bildvorschauen)
- Node.js 18 oder neuer (für den Frontend-Build)

### 1. Passwort setzen

Hash erzeugen und in `index.php` eintragen:

```bash
php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
```

### 2. Mounts anpassen

In `index.php` die `mount()`-Aufrufe auf die gewünschten Verzeichnisse
ändern. Optionen je Mount: `label`, `permissions`, `accept`, `readonly`.

### 3. Entwicklungsbetrieb

Zwei Prozesse parallel:

```bash
# Terminal 1 – PHP-Backend
php -S 127.0.0.1:8765 index.php

# Terminal 2 – Frontend (Vite-Dev-Server mit Proxy auf das Backend)
cd frontend
npm install
npm run dev
```

Dann <http://localhost:5173> öffnen. Der Vite-Proxy leitet den API-Endpunkt
`/cms-api/` an den PHP-Server weiter und reicht das Session-Cookie durch – kein
CORS nötig.

Beispiel-Anmeldung der mitgelieferten `index.php`: **admin** / **geheim**.

### 4. Produktivbetrieb

```bash
cd frontend
npm run build      # erzeugt frontend/dist/
```

`frontend/dist/` und `index.php` (samt `backend/`) auf den Server legen,
das Datenverzeichnis möglichst **außerhalb** des Web-Wurzelverzeichnisses.
Der API-Endpunkt **`/cms-api/`** muss serverseitig auf die Connector-`index.php`
zeigen (Vorlagen für Apache und Nginx in `beispiel-konfigurationen/`). Der
Standard von `VITE_API_BASE` ist bereits `/cms-api/`; nur bei abweichendem Pfad
in `frontend/.env.production` anpassen.

## Auslieferungs-Repo (hugocms-release)

`scripts/packaging.sh` baut das fertige Produkt — gebautes Frontend (`app/`),
Backend, `bin/` und `index.php` — in ein **eigenes Git-Repo** im Projektwurzel-
verzeichnis: `hugocms-release/`. Dieses Repo wird auf das Produktivsystem
ausgerollt; im Hauptrepo ist es über `.gitignore` ausgeschlossen.

Das Skript klont das Repo **nicht** selbst (die Repo-URL ist je Installation
verschieden). Lege es daher **einmalig** als Klon deines Auslieferungs-Repos an
— der Ordner muss `hugocms-release` heißen:

```bash
git clone <DEINE-RELEASE-REPO-URL> hugocms-release
```

Danach aktualisiert jeder `scripts/packaging.sh`-Lauf den Inhalt von
`hugocms-release/`. Es wird nichts automatisch committet — Commit und Push im
Release-Repo bleiben dir überlassen. Auf dem Produktivsystem richtet dann
`hugocms-release/bin/install.sh <host> <hugo-publish-ordner>` eine Webseite ein.

## API-Befehle (Stufe 1)

| Befehl   | Methode | Parameter            | Zweck                          |
|----------|---------|----------------------|--------------------------------|
| `whoami` | GET     | –                    | Anmeldestatus abfragen         |
| `login`  | POST    | `username`, `password` | Anmelden                     |
| `logout` | POST    | –                    | Abmelden                       |
| `mounts` | GET     | –                    | Mounts auflisten               |
| `list`   | GET     | `target` (ID)        | Verzeichnis auflisten          |
| `read`   | GET     | `target` (ID)        | Textdatei lesen                |
| `write`  | POST    | `target` (ID), `content` | Textdatei speichern        |

Antwortformat:

```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "error": { "code": "EACCES", "message": "..." } }
```

## Sicherheit

- **Einsperrung pro Mount:** Pfade werden mit `realpath()` aufgelöst und
  müssen innerhalb ihres Mounts liegen; `..` ist verboten.
- **Anmeldepflicht:** Alle Datei-Befehle erfordern eine gültige Sitzung.
- **Rechte je Mount:** `permissions` und `readonly` begrenzen Operationen
  pro Mount – Grundlage für spätere Rollen.
- **Auslieferung über PHP:** Geplant für Bilder/Downloads (Stufe 3), damit
  das Datenverzeichnis nicht direkt erreichbar sein muss – serverunabhängig
  für Apache und Nginx.
- **Schreiboperationen nur per POST**, Session-Cookie mit `HttpOnly` und
  `SameSite=Lax`.

> Hinweis: Ein CSRF-Token für Schreibbefehle ist für eine spätere Stufe
> vorgesehen. Bis dahin schützt `SameSite=Lax` die Sitzung.

## Logging und Fehlersuche

Der Connector schreibt in eine konfigurierbare Logdatei. In der `index.php`:

```php
'log' => __DIR__ . '/log/hugocms.log',
'logLevel' => 'error',   // debug | info | warning | error
```

Ohne `log`-Option fällt das Logging auf das PHP-eigene `error_log` zurück.
Das Verzeichnis `log/` ist über `.htaccess` (Apache) bzw. einen `location`-
Block (Nginx) vor direktem Zugriff geschützt.

Erfasst werden:

- **Unerwartete Fehler** (mit Typ, Ort und Stacktrace),
- **Konfigurationsfehler in `mount()`** – etwa ein nicht existierender Pfad.
  Solche Fehler entstehen *vor* `run()` und endeten früher als nackter
  HTTP 500; jetzt stehen sie im Log und kommen als sauberes JSON beim
  Client an.
- **Fatale PHP-Fehler** (über eine Shutdown-Funktion).

### Bei einem HTTP 500

1. `logLevel` vorübergehend auf `debug` setzen.
2. Aktion wiederholen und das Log ansehen: `tail -f log/hugocms.log`.
3. Häufigste Ursache: ein **`mount()`-Pfad existiert auf dem Server nicht**
   oder ist nicht lesbar. Der Log-Eintrag nennt den genauen Pfad.
4. Bleibt das Log leer und es kommt trotzdem ein 500, greift der Fehler noch
   vor dem PHP-Code (z. B. Syntaxfehler in der eigenen `index.php` oder eine
   fehlende PHP-Erweiterung) – dann ins **Server-Log** schauen
   (`error_log` von PHP-FPM bzw. Apache/Nginx).

Im Entwicklungsbetrieb blendet `scripts/dev.sh` die Logzeilen mit dem
Präfix `[LOG]` direkt im Terminal ein.

## Nächste Stufen

- **Stufe 2:** anlegen, umbenennen, kopieren, verschieben, löschen (in den
  Papierkorb), Mehrfachauswahl, Kontextmenü.
- **Stufe 3:** Upload (Drag-and-Drop), Download, Bildvorschauen (GD),
  Bildbetrachter, Kachelansicht.
- **Stufe 4:** TipTap-Markdown-Editor, Papierkorb-Verwaltung
  (Wiederherstellen/Leeren), Mehrbenutzer mit Rollen, CSRF-Token, Suche.
```
