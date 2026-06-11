# HugoCMS – Dateimanager

Ein webbasierter Dateimanager zum Pflegen von Hugo-Webseiten. Backend in PHP
(ohne Composer), Frontend mit Vue 3, Vuetify 3 und Vite. Eine Installation
verwaltet **mehrere Webseiten** (Mandantenfähigkeit); welche Verzeichnisse als
Mounts erreichbar sind, wird je Webseite über INI-Dateien festgelegt.

> **Stand: Stufe 1.** Anmeldung, Mounts, Verzeichnisse auflisten sowie
> Textdateien lesen und speichern. Dateioperationen (anlegen, löschen,
> umbenennen, kopieren), Upload, Bildanzeige und Papierkorb folgen in den
> nächsten Stufen. Mandantenfähigkeit und der Auslieferungsweg
> (`packaging.sh` / `install.sh`) stehen bereits.

## Architektur

```
hugocms-2026/                     # Quell-Repo (Entwicklung)
├── index.php                     # dünner Einstiegspunkt → backend/core/hugocms.php
├── backend/
│   ├── core/                     # Kern-Bibliothek (ohne Composer nutzbar)
│   │   ├── autoload.php          # PSR-4-Autoloader
│   │   ├── hugocms.php           # fester Einstiegspunkt (Bootstrap, nicht ändern)
│   │   ├── Connector.php         # Befehlsverarbeitung, Fehler-Handler
│   │   ├── SiteKey.php           # Host → Site-Kennung/Hash (Mandantenfähigkeit)
│   │   ├── Config.php            # hugocms.ini einlesen
│   │   ├── MountConfig.php       # mounts.ini einlesen
│   │   ├── MountResolver.php     # Mounts, ID-Kodierung, Einsperrung
│   │   ├── Mount.php             # Einhängepunkt (Pfad, Rechte, Endungen)
│   │   ├── FileService.php       # list, read, write
│   │   ├── AuthFactory.php       # Auth-Treiber (singleuser)
│   │   ├── Logger.php            # Datei-Logging mit Stufen
│   │   ├── Response.php          # einheitliche JSON-Antworten
│   │   ├── Auth/                 # AuthInterface + SingleUser
│   │   └── Exception/            # ApiException
│   ├── custom/                   # custom.php.beispiel (programmatischer Bootstrap)
│   ├── mounts/                   # host-spezifische mounts/<hash>.ini (je Webseite)
│   ├── hugocms.ini(.beispiel)    # Hauptkonfiguration: Anmeldung, Session, Log
│   ├── mounts.ini(.beispiel)     # Rückfall-Mounts (greift ohne host-eigene Datei)
│   ├── log/                      # Logdatei (.htaccess-geschützt)
│   └── var/sessions/             # PHP-Sitzungsdateien
├── bin/
│   ├── install.sh                # richtet eine Webseite ein (Produktivsystem)
│   ├── get-hugo.sh               # lädt das Hugo-Binary nach bin/hugo/
│   └── hugo/                     # Hugo-Static-Site-Generator (nicht versioniert)
├── scripts/
│   ├── dev.sh                    # Entwicklungsumgebung starten
│   ├── build.sh                  # Frontend bauen (frontend/dist)
│   ├── packaging.sh              # Auslieferungspaket erzeugen
│   └── site-hash.sh              # Hash/Dateiname einer Webseite berechnen
├── frontend/                     # Vue 3 + Vuetify 3 + Vite (Client, base=/edit/)
├── daten/                        # Beispiel-Datenverzeichnis
├── beispiel-konfigurationen/     # Apache- und Nginx-Vorlagen
└── hugocms-release/              # Auslieferungs-Repo (eigenes Repo, gitignored)
```

Das Backend kennt keine festen Pfade. Jeder Pfad wird mount-relativ als
undurchsichtige ID adressiert; der echte Serverpfad verlässt das Backend nie.
`MountResolver` sperrt jeden aufgelösten Pfad in seinen Mount ein und wehrt
`../`-Angriffe ab.

### Einstiegspunkt und Bootstrap

Die Wurzel-`index.php` ist bewusst minimal und bindet nur den festen
Einstiegspunkt ein:

```php
require __DIR__ . '/backend/core/hugocms.php';
```

`backend/core/hugocms.php` baut den Connector auf — in dieser Reihenfolge:

1. Existiert `backend/custom/custom.php`, übernimmt diese die **gesamte**
   Konfiguration (Connector instanzieren, Mounts setzen, `run()`). Das ist die
   flexible, programmatische Alternative (Vorlage: `custom.php.beispiel`).
2. Sonst wird der Connector aus `backend/hugocms.ini` erzeugt und die Mounts
   **host-spezifisch** geladen (siehe Mandantenfähigkeit).
3. Fehlt die `hugocms.ini`, meldet das Backend einen Einrichtungsfehler
   (`ESETUP`).

## Konfiguration

### Hauptkonfiguration: `backend/hugocms.ini`

Aus der Vorlage `hugocms.ini.beispiel` kopieren und anpassen. Relative Pfade
gelten relativ zur Datei (also zu `backend/`).

```ini
[auth]
driver = singleuser
username = admin
; Passwort-HASH (nie Klartext), erzeugen mit:
;   php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
password_hash = "$2y$10$..."

[session]
; Muss für den Webserver-Benutzer beschreibbar sein. Leer = PHP-Voreinstellung.
path = var/sessions

[log]
file = log/hugocms.log
level = warning      ; debug | info | warning | error
```

### Mounts

Jede `[Sektion]` ist ein Mount (Sektionsname = interne ID). Felder:
`path` (Pflicht), `label`, `permissions`, `accept`, `readonly`. Relative Pfade
gelten relativ zur Mount-Datei.

```ini
[content]
path = /pfad/zum/hugo-projekt/content
label = Inhalt
accept = md, markdown, html, htm, png, jpg, jpeg, gif, webp, svg

[layouts]
path = /pfad/zum/hugo-projekt/layouts
label = Vorlagen
permissions = read, write
```

### Programmatische Alternative: `backend/custom/custom.php`

Für dynamische Mounts oder eigene `AuthInterface`-Implementierungen. Die Datei
übernimmt dann die komplette Konfiguration; der Autoloader ist bereits geladen
(Vorlage: `backend/custom/custom.php.beispiel`).

## Mandantenfähigkeit (mehrere Webseiten)

Eine Installation bedient mehrere Webseiten. Welche Mounts gelten, entscheidet
die **aufgerufene URL**: Aus Host und Endpunkt-Pfad bildet `SiteKey` eine
stabile Kennung (z. B. `kunde-a.example.com/cms-api`) und daraus einen
SHA-256-Hash. Geladen wird dann `backend/mounts/<hash>.ini`.

- Fehlt die host-eigene Datei, gilt `backend/mounts.ini` als **Rückfall**
  (mit Hinweis an den Client; Details im Log).
- Fehlt auch der Rückfall, meldet das Backend die Webseite als unbekannt
  (`ESITE`, HTTP 404).

Der Hash als Dateiname ist zugleich ein Sicherheitsmerkmal: Er besteht nur aus
`[0-9a-f]`, sodass ein manipulierter Host-Header keinen Pfad-Ausbruch erzeugen
kann. Den Dateinamen für eine Webseite liefert:

```bash
scripts/site-hash.sh kunde-a.example.com/cms-api
```

Im Normalfall legt aber `bin/install.sh` diese Datei automatisch an
(siehe Auslieferung).

## Einrichtung & Entwicklung

### Voraussetzungen

- PHP 8.1 oder neuer (mit `fileinfo`, später `gd` für Bildvorschauen)
- Node.js 18 oder neuer (für den Frontend-Build)

### Schnellstart (Entwicklung)

```bash
# hugocms.ini aus der Vorlage anlegen und Passwort-Hash eintragen
cp backend/hugocms.ini.beispiel backend/hugocms.ini
php -r "echo password_hash('geheim', PASSWORD_DEFAULT), PHP_EOL;"

# Entwicklungsumgebung starten (PHP-Connector + Vite-Dev-Server)
scripts/dev.sh
```

`scripts/dev.sh` prüft PHP/Node, startet den PHP-Connector
(`php -S 127.0.0.1:8765 index.php`) und den Vite-Dev-Server und blendet
Log-Zeilen mit dem Präfix `[LOG]` im Terminal ein. Danach
<http://localhost:5173> öffnen. Der Vite-Proxy leitet den API-Endpunkt
`/cms-api/` an das Backend weiter und reicht das Session-Cookie durch – kein
CORS nötig.

Ohne `dev.sh` lassen sich beide Prozesse auch von Hand starten (`php -S
127.0.0.1:8765 index.php` und `cd frontend && npm install && npm run dev`).

## Auslieferung & Produktivbetrieb

### Auslieferungs-Repo (hugocms-release)

`scripts/packaging.sh` baut das fertige Produkt — gebautes Frontend (`app/`),
Backend, `bin/` und `index.php` — in ein **eigenes Git-Repo** im
Projektwurzelverzeichnis: `hugocms-release/`. Dieses Repo wird auf das
Produktivsystem ausgerollt; im Hauptrepo ist es über `.gitignore`
ausgeschlossen.

Das Skript klont das Repo **nicht** selbst (die Repo-URL ist je Installation
verschieden). Lege es daher **einmalig** als Klon deines Auslieferungs-Repos an
— der Ordner muss `hugocms-release` heißen:

```bash
git clone <DEINE-RELEASE-REPO-URL> hugocms-release
```

Danach aktualisiert jeder `scripts/packaging.sh`-Lauf den Inhalt von
`hugocms-release/`. Es wird nichts automatisch committet — Commit und Push im
Release-Repo bleiben dir überlassen.

### Webseite einrichten: `bin/install.sh`

Auf dem Produktivsystem liegt das `hugocms-release`-Repo an beliebiger Stelle.
Eine Webseite richtet man so ein:

```bash
hugocms-release/bin/install.sh <host> <hugo-publish-ordner>
#   z. B. … kunde-a.example.com /var/www/kunde-a/public
```

Das Skript:

1. **Hugo bereitstellen** – fehlt `bin/hugo/`, lädt `get-hugo.sh` den
   Static-Site-Generator (Variante *extended*, gepinnte Version) und prüft die
   Prüfsumme. Das Binary ist nicht Teil des Repos.
2. **Mount-Datei erzeugen** – `backend/mounts/<hash>.ini` mit Hugo-Struktur:
   `content` → `content/`, `layouts` → `layouts/`, `static` → `static/`, jeweils
   im Hugo-Projektverzeichnis (Elternverzeichnis des Publish-Ordners). Eine
   bestehende Datei bleibt unverändert.
3. **Publish-Ordner verlinken**:
   - `edit/` → Symlink auf `app/` (Editor-Oberfläche, URL `/edit/`)
   - `cms-api/` → echtes Verzeichnis mit einer Kopie der Release-`index.php`
     und einem Symlink `backend` auf das Release-`backend/` (API-Endpunkt,
     URL `/cms-api/`).

So bleibt der Code im Release; nur die kleine `index.php` wird kopiert. Mehrere
Webseiten teilen sich dasselbe `backend/` (gemeinsame Anmeldung); nur die
`mounts/<hash>.ini` unterscheiden sich je Host.

Der Webserver muss **Symlinks folgen** dürfen (Apache: `Options
+FollowSymLinks`; Nginx tut es standardmäßig). Vorlagen für beide liegen in
`beispiel-konfigurationen/`.

## API-Befehle (Stufe 1)

| Befehl   | Methode | Parameter                | Zweck                  |
|----------|---------|--------------------------|------------------------|
| `whoami` | GET     | –                        | Anmeldestatus abfragen |
| `login`  | POST    | `username`, `password`   | Anmelden               |
| `logout` | POST    | –                        | Abmelden               |
| `mounts` | GET     | –                        | Mounts auflisten       |
| `list`   | GET     | `target` (ID)            | Verzeichnis auflisten  |
| `read`   | GET     | `target` (ID)            | Textdatei lesen        |
| `write`  | POST    | `target` (ID), `content` | Textdatei speichern    |

Antwortformat:

```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "error": { "code": "EACCES", "message": "..." } }
```

`whoami` liefert zusätzlich ein `warnings`-Feld mit Einrichtungs-Hinweisen
(siehe unten).

## Logging, Hinweise und Fehlersuche

Der Connector schreibt in die in `hugocms.ini` konfigurierte Logdatei
(`[log] file` / `level`). Ohne `file` fällt das Logging auf das PHP-eigene
`error_log` zurück. Das Verzeichnis `backend/log/` ist über `.htaccess`
(Apache) bzw. einen `location`-Block (Nginx) vor direktem Zugriff geschützt.

### Einrichtungs-Hinweise im Browser

`whoami` läuft beim Start (vor dem Login) und meldet Setup-Probleme direkt im
Client:

- **Warnungen** (gelber Banner, schließbar): fehlendes Sitzungs- oder
  Log-Verzeichnis, Rückfall auf `mounts.ini` u. Ä. Der Betrieb läuft weiter.
- **Fehler** (dauerhaft, bis behoben): etwa ein **nicht beschreibbares
  Sitzungsverzeichnis** (`ESESSION`). Ohne beschreibbares Verzeichnis kann die
  Anmelde-Session nicht gespeichert werden — das Backend meldet das vor dem
  Login als Klartextfehler statt eines irreführenden `401`.

### Bei einem HTTP 500

1. `level` in `hugocms.ini` vorübergehend auf `debug` setzen.
2. Aktion wiederholen und das Log ansehen: `tail -f backend/log/hugocms.log`.
3. Häufigste Ursache: ein **Mount-Pfad existiert auf dem Server nicht** oder ist
   nicht lesbar. Der Log-Eintrag nennt den genauen Pfad.
4. Bleibt das Log leer und kommt trotzdem ein 500, greift der Fehler noch vor
   dem PHP-Code (Syntaxfehler, fehlende PHP-Erweiterung) – dann ins
   **Server-Log** schauen (`error_log` von PHP-FPM bzw. Apache/Nginx).

## Sicherheit

- **Einsperrung pro Mount:** Pfade werden mit `realpath()` aufgelöst und müssen
  innerhalb ihres Mounts liegen; `..` ist verboten.
- **Anmeldepflicht:** Alle Datei-Befehle erfordern eine gültige Sitzung.
- **Rechte je Mount:** `permissions` und `readonly` begrenzen Operationen pro
  Mount – Grundlage für spätere Rollen.
- **Host-sicherer Mount-Pfad:** Die host-spezifische Mount-Datei wird über einen
  SHA-256-Hash adressiert; ein manipulierter Host-Header kann keinen
  Pfad-Ausbruch erzeugen.
- **Schreiboperationen nur per POST**, Session-Cookie mit `HttpOnly` und
  `SameSite=Lax`.

> Hinweis: Ein CSRF-Token für Schreibbefehle ist für eine spätere Stufe
> vorgesehen. Bis dahin schützt `SameSite=Lax` die Sitzung.

## Nächste Stufen

- **Stufe 2:** anlegen, umbenennen, kopieren, verschieben, löschen (in den
  Papierkorb), Mehrfachauswahl, Kontextmenü.
- **Stufe 3:** Upload (Drag-and-Drop), Download, Bildvorschauen (GD),
  Bildbetrachter, Kachelansicht.
- **Stufe 4:** TipTap-Markdown-Editor, Papierkorb-Verwaltung
  (Wiederherstellen/Leeren), Mehrbenutzer mit Rollen, CSRF-Token, Suche.
