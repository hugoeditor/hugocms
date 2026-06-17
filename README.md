# HugoCMS – Dateimanager

Ein webbasierter Dateimanager zum Pflegen von Hugo-Webseiten. Backend in PHP
(ohne Composer), Frontend mit Vue 3, Vuetify 3 und Vite. Eine Installation
verwaltet **mehrere Webseiten** (Mandantenfähigkeit); welche Verzeichnisse als
Mounts erreichbar sind, wird je Webseite über INI-Dateien festgelegt.

> **Stand: Stufe 4.** Anmeldung, Mounts, Verzeichnisse auflisten, Textdateien
> lesen/speichern (mit Konfliktschutz); anlegen, umbenennen, kopieren,
> verschieben, löschen, Mehrfachauswahl, Kontextmenü; Upload (Drag-and-Drop),
> Download, Bildvorschauen, Bildbetrachter, Symbolansicht; visueller
> Markdown-Editor (TipTap), Papierkorb-Verwaltung (Wiederherstellen/Leeren),
> CSRF-Schutz, rekursive Suche. Konfiguration und Anmeldedaten lassen sich im
> laufenden Betrieb über die Oberfläche ändern. Mehrbenutzer mit Rollen folgt
> (Stufe 5).
> Mandantenfähigkeit und der Auslieferungsweg (`packaging.sh` / `install.sh`)
> stehen bereits.

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
│   │   ├── Config.php            # hugocms.ini lesen & schreiben (zentrale Kapsel)
│   │   ├── MountConfig.php       # mounts.ini einlesen
│   │   ├── MountResolver.php     # Mounts, ID-Kodierung, Einsperrung
│   │   ├── Mount.php             # Einhängepunkt (Pfad, Rechte, Endungen)
│   │   ├── FileService.php       # Dateioperationen (list, read, write, anlegen, …)
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
3. Fehlt sowohl `custom.php` als auch `hugocms.ini`, übernimmt das
   **Einrichtungs-Setup** (`ESETUP`/`setupRequired`): Der Client blendet ein
   Formular ein, aus dem die `hugocms.ini` erzeugt wird; danach ist der
   Benutzer direkt angemeldet.

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
; Pflichtfeld; muss für den Webserver-Benutzer beschreibbar sein.
path = var/sessions

[log]
file = log/hugocms.log
level = warning      ; debug | info | warning | error

[hugo]
; Zentraler Pfad zum Hugo-PROGRAMM (optional). Installationsweit gibt es nur
; eine Hugo-Binärdatei, daher steht sie hier — nicht in den Mounts. Fehlt sie,
; wird der Veröffentlichen-Knopf nicht angeboten.
bin = ../bin/hugo/hugo
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
  (mit Hinweis an den Client; Details im Log) — **sofern** vorhanden. Das
  Auslieferungs-Repo liefert **keine** fertige `mounts.ini` mit, nur die
  Vorlage `mounts.ini.beispiel`; im Produktivbetrieb legt `bin/install.sh`
  ohnehin je Webseite die host-eigene `mounts/<hash>.ini` an.
- Fehlt host-eigene Datei **und** Rückfall, meldet das Backend die Webseite als
  unbekannt (`ESITE`, HTTP 404).

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

Mitgeliefert werden ausschließlich die Vorlagen `hugocms.ini.beispiel` und
`mounts.ini.beispiel` — **niemals** eine instanzspezifische `hugocms.ini`
(enthält den Passwort-Hash) oder `mounts.ini`. Das Packaging entfernt solche
Dateien vor dem Commit, und die `.gitignore` des Release-Repos schließt
`*.ini`/`*.bak` aus. Eine frische Installation läuft daher zuerst ins
**Einrichtungs-Setup** (erzeugt die `hugocms.ini`); die Mounts kommen über
`bin/install.sh` (host-eigene `mounts/<hash>.ini`).

Das Skript klont das Repo **nicht** selbst (die Repo-URL ist je Installation
verschieden). Lege es daher **einmalig** als Klon deines Auslieferungs-Repos an
— der Ordner muss `hugocms-release` heißen:

```bash
git clone <DEINE-RELEASE-REPO-URL> hugocms-release
```

Danach aktualisiert jeder `scripts/packaging.sh`-Lauf den Inhalt von
`hugocms-release/`. Das Pflegen des Release-Repos lässt sich dabei mitnehmen —
du fasst es nie von Hand an:

```bash
scripts/packaging.sh              # bauen, committen UND pushen (Standard)
scripts/packaging.sh --no-push    # bauen und committen, aber nicht pushen
scripts/packaging.sh --no-commit  # nur bauen; danach 'git status' des Release-Repos
```

Die Commit-Message wird aus dem Quell-Commit abgeleitet (z. B. `Release aus
a1b2c3d: <Betreff>`); ist der Quell-Arbeitsbaum nicht sauber, wird der Stand
als `-dev` markiert und vor dem Commit nachgefragt. Standardmäßig committet und
pusht das Skript — `--no-push` unterdrückt den Push, `--no-commit` beides.

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
   bestehende Datei bleibt erhalten; fehlende Standard-Sektionen (auch `[hugo]`)
   werden ergänzt.
3. **Hugo-Programm zentral eintragen** – existiert die `hugocms.ini` bereits und
   hat noch keine `[hugo]`-Sektion, wird `bin` ergänzt; sonst ein Hinweis (die
   Datei entsteht erst beim Einrichtungs-Setup).
4. **App einrichten** (ohne Symlinks):
   - `edit/` → **Kopie** von `app/` (Editor-Oberfläche, URL `/edit/`)
   - `cms-api/index.php` → erzeugt, bindet das Release-`backend/` per absolutem
     `require` ein (API-Endpunkt, URL `/cms-api/`).

   Beides wird **zweimal** abgelegt: direkt im Publish-Ordner (sofort
   erreichbar) **und** im `static/`-Verzeichnis. Da Hugo `static/` bei jedem
   Build spiegelt, übersteht die App so ein `hugo --cleanDestinationDir`.

So bleibt der PHP-Code im Release; nur das Frontend wird kopiert. Mehrere
Webseiten teilen sich dasselbe `backend/` (gemeinsame Anmeldung); nur die
`mounts/<hash>.ini` unterscheiden sich je Host.

Das Verfahren kommt **ohne Symlinks** aus und funktioniert daher auch auf
Hostings, deren Webserver Symlinks nicht folgt (z. B. Hetzner Webhosting); das
Release-Repo bleibt außerhalb des Webroots. Nach einem Update (`git pull` im
Release-Repo) das Skript erneut ausführen, damit die App neu kopiert wird —
das Backend ist ohne diesen Schritt bereits aktuell.

### Veröffentlichen: Hugo aufrufen

Der Client zeigt in der Titelleiste einen **Veröffentlichen**-Knopf, der Hugo
für die aufgerufene Webseite startet (API-Befehl `build`). Die Konfiguration ist
auf zwei Dateien verteilt:

- Das **Hugo-Programm** steht zentral in `hugocms.ini` (`[hugo] bin`) — es gibt
  installationsweit nur eine Binärdatei.
- Die **je Webseite** unterschiedlichen Pfade stehen in der reservierten Sektion
  `[hugo]` der Mount-Konfiguration (`install.sh` trägt sie automatisch ein):

```ini
[hugo]
source = /var/www/kunde-a               ; Hugo-Projektverzeichnis
destination = /var/www/kunde-a/public   ; optional, Standard: <source>/public
minify = true                            ; optional: hugo --minify
```

Aufgerufen wird `hugo -s <source> -d <destination>` (optional mit `--minify`;
`clean = true` ergänzt `--cleanDestinationDir`). Veröffentlichbar ist eine
Webseite nur, wenn **beides** vorliegt — das zentrale Programm **und** die
Mount-`[hugo]`-Sektion; sonst erscheint der Knopf nicht. Ist eine offene Datei
ungespeichert, wird sie vor dem Build automatisch gesichert. Die Hugo-Ausgabe
(Statistik bzw. Fehlermeldungen) zeigt der Client in einem Dialog an. Der
Webserver-Benutzer braucht Ausführrechte auf das Binary und Schreibrechte im
Ziel.

> **Zu `--cleanDestinationDir`:** Der Schalter entfernt im Ziel alles, was Hugo
> nicht selbst erzeugt. Damit die Installation (`edit/`, `cms-api/`) das
> übersteht, legt `install.sh` sie zusätzlich im `static/`-Verzeichnis ab —
> Hugo spiegelt `static/` bei jedem Build in den Publish-Ordner.

### Konfiguration im laufenden Betrieb ändern

Die `hugocms.ini` lässt sich auch nach der Einrichtung über die Oberfläche
anpassen (nur bei INI-basierter Installation, nicht bei `custom.php`):

- **Zahnrad in der Titelleiste** (`reconfigure`): Sitzungsverzeichnis, Logdatei,
  Log-Stufe und Hugo-Programm. Die Anmeldedaten bleiben unberührt; Pfadänderungen
  greifen beim nächsten Laden.
- **Klick auf den Benutzernamen** (`account`): Anmeldename und Passwort ändern.
  Zur Bestätigung ist das aktuelle Passwort nötig; danach wird zur erneuten
  Anmeldung mit den neuen Daten ausgeloggt.

Das Lesen und Schreiben der `hugocms.ini` ist in `Config` gekapselt; die
Persistenz der Anmeldedaten übernimmt der Auth-Treiber selbst
(`AuthInterface::changeCredentials`), sodass ein künftiger Mehrbenutzer-Treiber
sie anders ablegen kann, ohne dass der Connector sich ändert.

## API-Befehle

| Befehl     | Methode | Parameter                            | Zweck                                  |
|------------|---------|--------------------------------------|----------------------------------------|
| `whoami`   | GET     | –                                    | Anmeldestatus abfragen                 |
| `login`    | POST    | `username`, `password`               | Anmelden                               |
| `logout`   | POST    | –                                    | Abmelden                               |
| `mounts`   | GET     | –                                    | Mounts auflisten                       |
| `list`     | GET     | `target` (ID)                        | Verzeichnis auflisten                  |
| `read`     | GET     | `target` (ID)                        | Textdatei lesen                        |
| `write`    | POST    | `target` (ID), `content`, `mtime`?   | Textdatei speichern (Konfliktschutz)   |
| `mkdir`    | POST    | `target` (Ordner-ID), `name`         | Unterordner anlegen                    |
| `newfile`  | POST    | `target` (Ordner-ID), `name`         | Leere Datei anlegen                    |
| `rename`   | POST    | `target` (ID), `name`                | Umbenennen                             |
| `delete`   | POST    | `targets` (ID-Liste)                 | In den Papierkorb (`.trash`) löschen   |
| `copy`     | POST    | `sources` (ID-Liste), `dest` (ID)    | Kopieren (gleicher Mount)              |
| `move`     | POST    | `sources` (ID-Liste), `dest` (ID)    | Verschieben (gleicher Mount)           |
| `upload`   | POST    | multipart: `target` (ID), `files[]`  | Dateien hochladen (Kollision: „ (2)“)  |
| `download` | GET     | `target` (ID)                        | Datei herunterladen (attachment)       |
| `raw`      | GET     | `target` (ID)                        | Bild inline ausliefern (Betrachter)    |
| `thumb`    | GET     | `target` (ID), `size`?               | Bildvorschau (GD; ohne GD das Original)|
| `search`   | GET     | `target` (Ordner-ID), `q` (≥ 2 Z.)   | Rekursive Namenssuche (max. 200)       |
| `trashlist`| GET     | –                                    | Papierkörbe aller Mounts auflisten     |
| `restore`  | POST    | `mount`, `names` (Liste)             | Aus dem Papierkorb wiederherstellen    |
| `emptytrash`| POST   | `mount`?                             | Papierkorb endgültig leeren            |
| `build`    | POST    | –                                    | Hugo aufrufen (Webseite erzeugen)      |
| `config`   | GET     | –                                    | Aktuelle Konfigurationswerte (ohne Anmeldedaten) |
| `reconfigure`| POST  | `sessionPath`, `logFile`, `logLevel`, `hugoBin`? | hugocms.ini ändern (Verzeichnisse/Log/Hugo) |
| `account`  | POST    | `currentPassword`, `username`, `password`? | Anmeldedaten ändern (danach Neuanmeldung) |

Alle POST-Befehle verlangen das **CSRF-Token** aus `whoami` (Feld `csrf`) im
Header `X-CSRF-Token`; sonst antwortet das Backend mit `ECSRF` (403).

Antwortformat:

```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "error": { "code": "EINVAL", "key": "PARAM-MISSING", "params": ["content"] } }
{ "ok": false, "error": { "code": "ESITE", "params": ["kunde-a.example.com/cms-api", "<hash>"] } }
```

Fehler tragen **keinen** übersetzten Text, sondern nur Codes und Parameter:
`code` ist die Fehlerklasse, `key` der genauere Übersetzungsschlüssel (entfällt,
wenn der `code` die Meldung schon eindeutig bestimmt), `params` die einzu-
setzenden Werte (Hostname, Pfad …). Der Client übersetzt darüber (vue-i18n,
de/en) und setzt die Meldung zusammen. Auch die Warnungen aus `whoami` sind so
aufgebaut (`{ "key": …, "params": […] }`).

`whoami` liefert zusätzlich ein `warnings`-Feld mit Einrichtungs-Hinweisen
(siehe unten).

## Logging, Hinweise und Fehlersuche

Der Connector schreibt in die in `hugocms.ini` konfigurierte Logdatei
(`[log] file` / `level`, beide Pflichtfelder). Das Verzeichnis `backend/log/`
ist über `.htaccess` (Apache) bzw. einen `location`-Block (Nginx) vor direktem
Zugriff geschützt.

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
- **CSRF-Token:** Alle Schreibbefehle verlangen das sitzungsgebundene Token
  aus `whoami` im Header `X-CSRF-Token` (zweite Schicht neben `SameSite=Lax`).
  Das einmalige Einrichtungs-Setup (vor Existenz der `hugocms.ini`) läuft ohne
  Token, da dort noch keine schützenswerte Sitzung besteht.

## Nächste Stufen

- ~~**Stufe 2:** anlegen, umbenennen, kopieren, verschieben, löschen (in den
  Papierkorb), Mehrfachauswahl, Kontextmenü.~~ ✓ umgesetzt
- ~~**Stufe 3:** Upload (Drag-and-Drop), Download, Bildvorschauen (GD),
  Bildbetrachter, Kachelansicht.~~ ✓ umgesetzt (Bildvorschauen brauchen die
  PHP-Erweiterung GD; ohne GD wird das Originalbild unverkleinert geliefert)
- ~~**Stufe 4:** TipTap-Markdown-Editor (visuell, mit Front-Matter-Schutz),
  Papierkorb-Verwaltung (Wiederherstellen/Leeren), CSRF-Token, Suche.~~
  ✓ umgesetzt
- **Stufe 5:** Mehrbenutzer mit Rollen.
