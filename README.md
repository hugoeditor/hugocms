# HugoCMS – Management für statisch generierte Webseiten

Ein webbasiertes Managementsystem, in dem Inhalte einer Webseite erstellt,
geprüft und veröffentlicht werden. Der Schwerpunkt liegt auf
**Suchmaschinen-Optimierung**: ein regelbasierter SEO-Check untersucht das
gebaute Projekt und meldet jeden Fund samt Regel-Erklärung, und die **eingebaute
KI** bewertet einzelne Inhalte, verbessert sie auf Wunsch direkt und arbeitet als
Assistent im gesamten Projekt.

Technisch basiert das System auf [Hugo](https://gohugo.io), einem Webseitengenerator, der aus Markdown-Dateien statische Webseiten erzeugt. Das Backend ist in PHP geschrieben (ohne Composer), das Frontend mit Vue 3, Vuetify 3 und Vite. Eine Installation verwaltet **mehrere Webseiten** pro Hosting (Mandantenfähigkeit); welche Verzeichnisse als Mounts erreichbar sind, wird je Webseite flexibel über INI-Dateien festgelegt.

> **Was bereits fertig ist und was noch aussteht**, steht in
> [ENTWICKLUNGSSTAND.md](ENTWICKLUNGSSTAND.md) — diese README beschreibt die
> Funktionsweise, nicht den Fortschritt.

> **Hinweis: Rolling Release.** HugoCMS erscheint fortlaufend, ohne feste
> Versionssprünge. Die jeweils getestete und freigegebene Fassung liegt
> **fertig gebaut** im Repo
> [hugocms-release](https://github.com/hugoeditor/hugocms-release) und ist von
> dort direkt einsatzfähig. Das vorliegende Repo enthält alles, was zum
> Entwickeln der App und des Backends von HugoCMS gebraucht wird.

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
│   │   ├── AssistantService.php  # KI-Assistent: Tool-Schleife über FileService
│   │   ├── AnthropicClient.php   # Claude-API über cURL (ohne SDK)
│   │   ├── License.php           # Pro-Lizenz prüfen (Ed25519, domaingebunden)
│   │   ├── GitService.php        # Pro: Git-Versionierung
│   │   ├── ChangelogService.php  # Änderungsprotokoll (content/changelog.md)
│   │   ├── Audit/                # Pro: SEO-Check & Content-Qualität
│   │   │   ├── AuditService.php      # SEO-Check-Läufe (Bericht je Webseite)
│   │   │   ├── AuditRunner.php       # gebautes public/ parsen & Regeln prüfen
│   │   │   ├── IgnoreStore.php       # dauerhaft ignorierte Funde je Webseite
│   │   │   ├── Checks.php / RuleCatalog.php / HtmlInspector.php / …
│   │   │   └── ContentQualityService.php # LLM-Qualitätsprüfung je Inhaltsdatei
│   │   ├── Review/               # gestaffelte Veröffentlichung (Freigabe)
│   │   │   ├── ReviewStore.php        # Entwurfsspeicher je Webseite (var/review/)
│   │   │   └── FrontMatter.php        # draft/publishDate im Front Matter setzen
│   │   ├── AuthFactory.php       # Auth-Treiber (singleuser, multiuser)
│   │   ├── Logger.php            # Datei-Logging mit Stufen
│   │   ├── Response.php          # einheitliche JSON-Antworten
│   │   ├── Auth/                 # Anmeldeverfahren
│   │   │   ├── AuthInterface.php     # Vertrag (Anmeldung + Einstellungen je Benutzer)
│   │   │   ├── SingleUser.php        # ein Konto, Daten in der hugocms.ini
│   │   │   ├── MultiUser.php         # Pro: mehrere Konten mit Rollen
│   │   │   ├── UserStore.php         # Pro: Kontodateien users/<hash>.ini
│   │   │   ├── UserAdminInterface.php / SiteAwareInterface.php
│   │   │   └── SessionHandling.php   # gemeinsamer Sitzungsteil beider Treiber
│   │   └── Exception/            # ApiException
│   ├── cli/                      # Kommandozeilen-Werkzeuge
│   │   ├── cron-improve.php      # Cron: nächste Dateien per KI verbessern (auto)
│   │   └── cron-build.php        # Cron: bei fälligen Freigaben bauen (--force: immer)
│   ├── custom/                   # custom.php.beispiel (programmatischer Bootstrap)
│   ├── mounts/                   # host-spezifische mounts/<hash>.ini (je Webseite)
│   ├── users/                    # Pro: eine INI je Benutzerkonto (multiuser)
│   ├── help/                     # Wissensdatenbank (Markdown), u. a. SEO-Regel-Hilfen
│   ├── hugocms.ini(.beispiel)    # Hauptkonfiguration: Anmeldung, Session, Log
│   ├── mounts.ini(.beispiel)     # Rückfall-Mounts (greift ohne host-eigene Datei)
│   ├── log/                      # Logdatei (.htaccess-geschützt)
│   └── var/                      # Sitzungen, SEO-Check- & Content-Qualitäts-Berichte
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
; singleuser (Standard) | multiuser (Pro, siehe „Mehrbenutzer“)
driver = singleuser
username = admin
; Passwort-HASH (nie Klartext), erzeugen mit:
;   php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
password_hash = "$2y$10$..."

[user]
; Installationsweite Vorgaben für die Oberfläche. Beim Einzelbenutzer sind es
; zugleich SEINE Einstellungen; beim Mehrbenutzer die Vorgabe, auf die jedes
; Konto zurückfällt, das den Schlüssel nicht selbst führt.
session_lifetime  = 8      ; Stunden Inaktivität bis zur Abmeldung (Standard 8)
content_width     = 1200   ; Breite des Hauptfensters in px (Standard 1200)
toolbar_collapsed = false  ; Werkzeugleiste eingeklappt starten
update_lastmod    = false  ; lastmod beim Speichern setzen; fehlt = Editor fragt

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

[ai]
; KI-Assistent (Claude), optional. Ohne api_key ist der Assistent aus.
api_key    = "sk-ant-..."     ; Anthropic-Schlüssel (Geheimnis; Datei mit 0640 schützen)
model      = claude-opus-5    ; Claude-Modell (Standard: claude-opus-5)
write_mode = confirm          ; readonly | confirm | auto (Standard: confirm)
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

### Git-Hooks aktivieren (einmal je Arbeitskopie)

```bash
git config core.hooksPath bin/hooks
```

Git sucht seine Hooks danach in `bin/hooks/` statt im nicht versionierten
`.git/hooks/`. Vorhanden ist bislang einer:

- **`pre-commit`** weist Quelldateien ab, die Git als **binär** einstufen würde.
  Ein einziges NUL-Byte (0x00) genügt dafür — die Datei übersetzt und
  funktioniert weiterhin, aber `diff`, `blame`, `merge` und jede Suche gehen an
  ihr vorbei, was lange unbemerkt bleibt. Geprüft wird nur, was zum Commit
  vorgemerkt ist, und nur bei Endungen, die Text enthalten müssen; echte
  Binärdateien (Bilder, Schriften) passieren ungehindert. Im Ausnahmefall:
  `git commit --no-verify`.

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
2. **Mount-Datei erzeugen** – `backend/mounts/<hash>.ini`. Erster Mount ist
   `projekt` → das gesamte Hugo-Projektverzeichnis (ohne Endungsfilter, also
   Zugriff auf alle Dateien inkl. `config.*` und Theme-Ordner); dazu die
   bequemen Direktzugänge `content` → `content/`, `layouts` → `layouts/`,
   `static` → `static/` (Elternverzeichnis des Publish-Ordners). Eine bestehende
   Datei bleibt erhalten; fehlende Standard-Sektionen (auch `[hugo]`) werden
   ergänzt.
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

Der Client zeigt in der linken Werkzeugleiste einen **Veröffentlichen**-Knopf,
der Hugo für die aufgerufene Webseite startet (API-Befehl `build`). Die
Konfiguration ist auf zwei Dateien verteilt:

- Das **Hugo-Programm** steht zentral in `hugocms.ini` (`[hugo] bin`) — es gibt
  installationsweit nur eine Binärdatei. Dieselbe Sektion kennt `clean = true`;
  das schaltet `--cleanDestinationDir` für alle Webseiten ein (im Zahnrad-Dialog
  als Schalter, siehe unten).
- Die **je Webseite** unterschiedlichen Pfade stehen in der reservierten Sektion
  `[hugo]` der Mount-Konfiguration (`install.sh` trägt `source` und
  `destination` automatisch ein):

```ini
[hugo]
source = /var/www/kunde-a               ; Hugo-Projektverzeichnis
destination = /var/www/kunde-a/public   ; optional, Standard: <source>/public
minify = true                           ; optional: hugo --minify
clean = true                            ; optional: --cleanDestinationDir (Vorsicht, siehe unten)
```

Aufgerufen wird `hugo -s <source> -d <destination>`, optional ergänzt um
`--minify` sowie um `--cleanDestinationDir`, sobald `clean = true` **entweder**
zentral in der `hugocms.ini` **oder** in der Mount-Sektion gesetzt ist. Bewusst
**ohne** `--buildFuture`/`--buildDrafts`: künftige `publishDate` und
`draft: true` bleiben so unveröffentlicht — genau das trägt die gestaffelte
Freigabe (siehe „Gestaffelte Veröffentlichung (Freigabe)").

Veröffentlichbar ist eine Webseite nur, wenn **beides** vorliegt — das zentrale
Programm **und** die Mount-`[hugo]`-Sektion; sonst erscheint der Knopf nicht.
Ist eine offene Datei ungespeichert, wird sie vor dem Build automatisch
gesichert; scheitert das Speichern, unterbleibt der Build. Anschließend wendet
das Backend fällige terminierte Austausche an, damit Hugo den neuen Stand sieht.
Ein erfolgreicher Lauf meldet sich mit einer kurzen Einblendung samt
**Details**-Knopf; ein fehlgeschlagener öffnet sofort den Dialog mit der
Hugo-Ausgabe (die letzten 200 Zeilen). Der Webserver-Benutzer braucht
Ausführrechte auf das Binary und Schreibrechte im Ziel.

> **Zu `--cleanDestinationDir`:** Der Schalter entfernt im Ziel alles, was Hugo
> nicht selbst erzeugt. Damit die Installation (`edit/`, `cms-api/`) das
> übersteht, legt `install.sh` sie zusätzlich im `static/`-Verzeichnis ab —
> Hugo spiegelt `static/` bei jedem Build in den Publish-Ordner.

### Seitenvorschau

Im Editor gibt es für Dateien im Content-Ordner einen **Seitenvorschau**-Knopf
(`mdi-eye-outline`) — im Quelltext-Modus in der Werkzeugleiste, im visuellen
Modus in der Formatleiste. Er zeigt die Seite so, wie das Theme sie später
darstellt, einschließlich des **ungespeicherten** Editor-Stands. Die Datei auf
der Platte und der Publish-Ordner bleiben dabei unangetastet
(`PreviewService`).

Im **Dateimanager** steht derselbe Weg im Kontextmenü einer Content-Datei
(„Seitenvorschau"). Der Eintrag erscheint nur, wenn das angezeigte Verzeichnis
im Content-Ordner liegt — dafür liefert der `list`-Befehl das Flag
`cwd.contentDir` — und die Datei eine Content-Endung trägt. Gezeigt wird dort
der gespeicherte Stand; einen ungespeicherten gibt es außerhalb des Editors
nicht.

Denselben Knopf trägt die **Freigabe-Warteschlange**: Dort zeigt er den
vorgeschlagenen Stand eines Entwurfs, ohne ihn freizugeben. Das gilt auch für
Entwürfe **neuer** Seiten, die es im Projekt noch gar nicht gibt — der Entwurf
bringt Pfad und Inhalt selbst mit, und das Overlay genügt Hugo als Quelle.

Hugo kann keine einzelne Seite bauen; es liest immer das ganze Projekt. Zwei
Werkzeuge machen die Vorschau trotzdem leichtgewichtig:

- **Overlay-Mount**: Der anzuzeigende Text landet in einem Arbeitsverzeichnis
  unter `backend/var/preview/`, das dem `content`-Ordner **vorangestellt** wird.
  Der erste Mount gewinnt — die eine Datei wird überlagert, alles andere kommt
  unverändert aus dem echten Projekt (Theme, Menüs, Nachbarseiten, Bilder eines
  Seitenordners). Die bestehenden Mounts liest der Dienst über `hugo config`
  aus, statt Standardwerte anzunehmen.
- **Segments** (`--renderSegments`, Hugo ab 0.124): Gerendert wird nur die eine
  Adresse. Welche das ist, sagt `hugo list all` (Spalte `permalink`) — `slug`,
  `url` im Front Matter und die `permalinks`-Konfiguration bestimmen sie. Bei
  älteren Hugo-Fassungen entfällt das Segment, gebaut wird dann vollständig.

Am oberen Rand sitzt ein Hinweisband („Vorschau — dieser Stand ist nicht
veröffentlicht"). Unmittelbar rechts am Text schließt ein **Schließen**-Knopf
an — beide zusammen mittig gesetzt, damit der Weg mit der Maus kurz bleibt —,
der das Fenster schließt. Das gelingt nur, weil die App es selbst per `window.open` geöffnet
hat; verdrahtet ist der Knopf über einen kurzen Skriptblock statt über ein
`onclick`-Attribut. Damit eine strenge CSP der Webseite ihn nicht blockiert,
erzeugt `install.sh` für den Endpunkt eine `cms-api/.htaccess`, die den
CSP-Header dort aus beiden Header-Tabellen entfernt (bei Nginx: siehe den
Hinweis im `/cms-api/`-Block der Beispielkonfiguration). Die Sprache des Bands
folgt der Oberfläche.

Gebaut wird mit `--buildDrafts --buildFuture --buildExpired`, denn gerade
Entwürfe und terminierte Fassungen will man vorher sehen. `--noBuildLock`
verhindert die Kollision mit einem parallel laufenden Cron-Build. Der statische
Ordner der Webseite bleibt außen vor: Hugo schreibt wurzelrelative Adressen, die
Vorschau lädt CSS, Schriften und Bilder also von der veröffentlichten Seite.
Verweise in der Vorschau führen ebenfalls dorthin — ein Hinweisband am oberen
Rand sagt das.

**Nicht auffindbar für Suchmaschinen** — der ausdrückliche Zweck dieses Aufbaus:

1. Das Ergebnis liegt nie im Web-Wurzelverzeichnis, sondern unter
   `backend/var/preview/` (per `.htaccess` gesperrt, bei Nginx über den
   `location`-Block). Es steht damit in keiner Sitemap und ist nicht crawlbar.
2. Ausgeliefert wird nur an eine angemeldete Sitzung (`cmd=preview`).
3. Die Antwort trägt `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet,
   noimageindex` und `Cache-Control: no-store`.
4. Zusätzlich steht ein `<meta name="robots" content="noindex,nofollow">` im
   ausgelieferten HTML — für den Fall, dass jemand die Seite speichert.
5. Jedes Token (`random_bytes(16)`) gilt genau einmal und höchstens zehn
   Minuten; abgelaufene Reste räumt der nächste Lauf weg.

### Editor-Werkzeuge

Beide Bearbeitungsarten — Quelltext (CodeMirror) und der visuelle Markdown-Modus
(TipTap) — tragen dieselben Einfüge-Werkzeuge: Link, externer Link und der
**Auszugs-Trenner** `<!--more-->` (`mdi-format-page-break`). Alles vor dem
Trenner bildet Hugos `.Summary`, also den Anreißer in Listen und Teasern.

Im visuellen Modus ist dabei zweierlei zu beachten, beides bereits gelöst: Der
Trenner wird als **Text** eingefügt (`insertText`), weil `insertContent` die
Zeichenkette durch den HTML-Parser schickt, der einen Kommentar verschluckt. Und
beim Serialisieren maskiert tiptap-markdown jedes `<`/`>` zu `&lt;`/`&gt;` —
`restoreHugoMarkup()` in `WysiwygEditor.vue` führt Shortcode-Begrenzer **und**
den Trenner wieder auf ihre kanonische Form zurück.

### Konfiguration im laufenden Betrieb ändern

Die `hugocms.ini` lässt sich auch nach der Einrichtung über die Oberfläche
anpassen (nur bei INI-basierter Installation, nicht bei `custom.php`):

- **Zahnrad in der Titelleiste** (`reconfigure`): Sitzungsverzeichnis, Logdatei,
  Log-Stufe, Hugo-Programm samt Schalter für `--cleanDestinationDir`
  (`[hugo] clean`) sowie der KI-Assistent (API-Schlüssel, Modell, Schreibmodus). Die Anmeldedaten bleiben unberührt; ein leeres
  API-Schlüssel-Feld lässt den vorhandenen Schlüssel unverändert. Pfadänderungen
  greifen beim nächsten Laden.
- **Klick auf den Benutzernamen** (`account`): Anmeldename und Passwort ändern.
  Zur Bestätigung ist das aktuelle Passwort nötig; danach wird zur erneuten
  Anmeldung mit den neuen Daten ausgeloggt.

Das Lesen und Schreiben der `hugocms.ini` ist in `Config` gekapselt; die
Persistenz der Anmeldedaten übernimmt der Auth-Treiber selbst
(`AuthInterface::changeCredentials`), sodass ein künftiger Mehrbenutzer-Treiber
sie anders ablegen kann, ohne dass der Connector sich ändert.

## KI-Assistent

Optionaler Chat-Assistent auf Basis von Claude. Er kennt Hugo (Front Matter,
`config.*`, Themes, Layouts/Partials) und arbeitet direkt auf den Mounts der
aufgerufenen Webseite. Aktiv ist er nur, wenn in der `hugocms.ini` ein
`[ai] api_key` hinterlegt ist; dann erscheint ein Roboter-Knopf in der
Titelleiste, der das Chat-Panel öffnet.

**Architektur.** Der Assistent fügt sich in das schlanke, zustandslose Backend
ein: Der Client hält den Gesprächsverlauf (Anthropic-Nachrichtenformat) und
schickt ihn bei jedem Zug mit; das Backend (`AssistantService`) führt die
Werkzeug-Schleife aus und gibt den fortgeschriebenen Verlauf zurück. Die
Claude-API wird über **cURL** angesprochen (`AnthropicClient`) — kein SDK, kein
Composer. Modell-Standard: `claude-opus-5`.

Die Anfrage an die API läuft im **Streaming-Modus**, die Antwort an den Client
bleibt aber eine gewöhnliche JSON-Antwort (kein SSE, kein Zustand): Ohne
Streaming schickt die API bis zum Ende der Generierung kein Byte, und ein
längerer Schreibvorgang lief in die Zeitüberschreitung. Abgebrochen wird deshalb
nicht nach fester Gesamtdauer, sondern erst bei echtem Stillstand
(`CURLOPT_LOW_SPEED_*`); `assembleStream()` setzt die Teile wieder zur
vollständigen Antwort zusammen.

**Werkzeuge und Sicherheit.** Der Assistent greift ausschließlich über
`FileService`/`MountResolver` zu (`list_dir`, `read_file`, `write_file`,
`replace_in_file`, `create_dir`, `rename`, `delete` → Papierkorb, `move`).
`replace_in_file` tauscht genau ein — eindeutig auffindbares — Textstück aus und
ist der bevorzugte Weg für Änderungen an bestehenden Dateien: Das Modell gibt
nur den betroffenen Abschnitt aus statt der ganzen Seite. Damit gelten für ihn
**dieselben Grenzen** wie für die Oberfläche: Einsperrung pro Mount,
`permissions`/`readonly` und erlaubte Endungen. Der API-Schlüssel verlässt das
Backend nie (wie der Passwort-Hash); im Formular heißt „Feld leer = unverändert".
Bei aktiver Pro-Lizenz kommt das Nur-Lese-Werkzeug **`get_file_report`** hinzu:
Es liefert zu einer Datei den Gesamt-Bericht (Qualitätsurteil + SEO-Funde) —
Grundlage der KI-Verbesserung (siehe „SEO-Check & Content-Qualität").

**Modell und Schreibmodus im Panel.** Zwei Chips im Kopf des Assistenten stellen
beides für die laufende Sitzung um, ohne die `hugocms.ini` anzufassen. Sie zeigen
den *wirksamen* Wert: solange im Panel nichts gewählt ist, den konfigurierten —
eine Änderung in der Konfiguration schlägt also sofort durch. Erst eine Auswahl
im Menü übersteuert ihn, und der Eintrag **„Wie konfiguriert"** nimmt sie wieder
zurück. Ein Neuladen setzt ebenfalls zurück.

**Schreibmodi** (`[ai] write_mode`):

| Modus     | Verhalten                                                        |
|-----------|------------------------------------------------------------------|
| `readonly`| Nur lesen — Schreibwerkzeuge sind gar nicht erst verfügbar.      |
| `confirm` | Jede Schreibaktion wird zur Bestätigung angezeigt (Standard).    |
| `auto`    | Schreibaktionen werden direkt ausgeführt.                        |

Im `confirm`-Modus zeigt das Panel vor dem Schreiben eine Vorschau: bei einer
bestehenden Datei einen zeilenweisen Diff, bei einer neuen Datei den Inhalt.
Bestätigen lässt sich auf drei Arten: **Übernehmen** schreibt sofort in die
Datei, **Später veröffentlichen** legt denselben Vorschlag als Entwurf in die
Freigabe-Warteschlange (die Live-Datei bleibt unverändert; dort wird er
freigegeben oder terminiert), **Termin festlegen** legt ihn gleich *terminiert*
ab — Datum pflicht, Uhrzeit optional, wie in der Warteschlange. Der Entwurf trägt
dann bereits sein `publishAt`; die veröffentlichte Fassung bleibt bis dahin
unverändert online, und der verzögerte Austausch tauscht die Datei beim ersten
Bauen nach dem Zeitpunkt. Ein vergangener Zeitpunkt gilt als „ohne Termin".

**„Nicht mehr fragen"** übernimmt die angezeigte Änderung und lässt den **Rest
des laufenden Auftrags** ohne weitere Rückfragen durchlaufen; danach gilt wieder
der eingestellte Schreibmodus. Ein Band über dem Eingabefeld zeigt an, solange
die Freigabe gilt, und nimmt sie auf Klick sofort zurück. Technisch ist das
bewusst **kein** Wechsel auf `auto`: Der Modus `auto` zieht die Entwurfspflicht
der gestaffelten Veröffentlichung nach sich, jede Änderung ginge also in die
Freigabe-Warteschlange statt in die Datei — das Gegenteil dessen, was jemand
erwartet, der eben noch „Übernehmen" drücken wollte. Stattdessen schickt der
Client den Schalter `autoConfirm` mit, der allein die Bestätigungspause
aussetzt; wohin geschrieben wird, bleibt unverändert. Wie `writeMode` geht er
bei jedem Zug mit und wird nicht serverseitig gemerkt.

Beide Entwurfswege erscheinen nur beim Schreiben einer Datei und nur bei
konfiguriertem Hugo-Projekt — ohne eines gibt es kein `draft`/`publishDate` und
damit keine Warteschlange. Umbenennen, Verschieben und Löschen kennen keinen
Entwurf und bleiben bei Übernehmen/Ablehnen.

**Editor- und Dateiverwaltungs-Anbindung.** Der Assistent erhält bei jedem Zug den
Kontext der Oberfläche: die im **Editor** geöffnete Datei (für „diese Datei")
und das in der **Dateiverwaltung** angezeigte Verzeichnis (Zielort für „eine neue
Datei" ohne Pfadangabe). Ungespeicherte Editor-Änderungen werden vor dem Zug
automatisch gesichert; ändert der Assistent die offene Datei, lädt der Editor
das Ergebnis sofort neu.

**Projektkonventionen.** Der Assistent ist angewiesen, vorhandene Konventionen
zu übernehmen (Front-Matter-Format YAML/TOML/JSON, Datumsformat, Config-Sprache,
Templating-Stil), statt zu raten — er prüft dafür Nachbardateien, `archetypes/`
und die Hugo-Config. Zusätzlich kann jede Webseite eine versteckte Datei
**`.hugocms-assistant.md`** im Wurzelverzeichnis eines Mounts hinterlegen; deren
Inhalt wird als vorrangige, projektweite Anweisung in den Systemkontext geladen
(z. B. „Front Matter immer YAML", „Theme ananke", „Inhalte auf Deutsch"). Fehlt
die Datei, greift die allgemeine Konventionserkennung.

## Pro-Version

Die **Pro-Version** schaltet je Webseite über einen Lizenzschlüssel zusätzliche
Funktionen frei:

- **SEO-Check** des gebauten Projekts (regelbasierter Bericht mit Funden je Regel),
- **Content-Qualität** — KI-Prüfung einzelner Inhaltsdateien samt direkter
  **KI-Verbesserung** (auch per Cron),
- **Git-Versionierung** des Hugo-Projekts.

Voraussetzung für alle Pro-Funktionen ist eine gültige, domaingebundene Lizenz
(siehe Lizenzmodell) und ein konfiguriertes **Hugo-Projekt** der Webseite
(`[hugo] source` der Mount-Konfiguration). Die Content-Qualität und die
KI-Verbesserung brauchen zusätzlich einen **KI-Schlüssel** (`[ai] api_key`).

### Git-Versionierung

Status (Branch, geänderte Dateien), Verlauf der Versionsstände, Diff eines
Versionsstands, Sichern eines Versionsstands, Hochladen der Änderungen (Push)
und Zurücksetzen des Arbeitsbaums. Ist eine Webseite freigeschaltet, erscheint in der Titelleiste ein
**Versionierungs-Knopf** (`mdi-source-branch`), der den Git-Dialog öffnet. Git
arbeitet im **Hugo-Projektverzeichnis** der Webseite — dort liegt die
Versionierung.

In der Oberfläche heißt die Funktion durchgängig **Versionierung**, ein
einzelner Commit **Versionsstand** und der Push **Änderungen hochladen**; im
Code und in der API bleiben die Git-Begriffe (`gitcommit`, `gitpush`, `sha`)
erhalten.

**Vorbelegte Beschreibung.** Das Beschreibungsfeld ist mit einem Gerüst gefüllt:
erste Zeile die Zusammenfassung (`3 geändert, 1 neu`), danach eine Zeile je
geänderter Datei mit ihrer Art. Die erste Zeile trägt bewusst die Zusammenfassung
und keinen Dateipfad — git behandelt sie als Betreff, und genau sie zeigt die
Spalte **Beschreibung** im Verlauf.

Die Dateiliste wird an einem Zeichenbudget gekürzt (900 der 1000 zulässigen
Zeichen; der Rest bleibt für eigene Ergänzungen). Ein Theme-Import oder ein Build
kann hunderte Dateien umfassen, deren Pfade die Grenze sonst weit überschritten
und den Versionsstand mit `GIT-MESSAGE-TOO-LONG` scheitern ließen. Was nicht mehr
hineinpasst, weist die letzte Zeile als Anzahl aus (`… und 181 weitere Dateien`).

Beschreibung und Versionsnummer werden nur überschrieben, solange dort nichts
Eigenes steht: Ein selbst getippter Text bleibt erhalten, wenn der Verlauf
zwischendurch neu geladen wird.

Der Verlauf zeigt in der Spalte **Beschreibung** nur die erste Zeile — die Spalte
ist einzeilig. Die **vollständige** Beschreibung samt Dateiliste steht im
Diff-Dialog über den Änderungen; `gitdiff` liefert sie dafür als `message` mit.

**Versionsnummern.** Beim Sichern lässt sich eine Versionsnummer vergeben; das
Feld ist mit dem nächsten freien Wert im Schema `1`, `2`, `3` … vorbelegt und
frei überschreibbar. Bleibt es leer, entsteht ein Versionsstand ohne Nummer. Im
Verlauf zeigt die Spalte **Versionsstand** die Nummer, wo eine vergeben ist, und
sonst den gekürzten Hash.

Der Zähler wird **nicht gespeichert**: Der Vorschlag entsteht aus den vorhandenen
Tags des Repositorys (höchste rein numerische Nummer + 1). Damit bleibt das
Repository die einzige Quelle der Wahrheit — ein Umzug, ein Klon oder ein von
Hand gesetztes Tag kann keine Kollision erzeugen. Abweichend benannte Tags (etwa
`1.2.0` oder `release-alt`) bleiben beim Zählen unberücksichtigt und stören
nicht.

Frühere Fassungen stellten den Nummern ein `v` voran (`v1`, `v2` …). Vergeben
werden jetzt reine Zahlen; beim Weiterzählen wird die alte Form aber weiterhin
GELESEN, damit ein Repository mit v-Tags nicht wieder bei 1 beginnt. Bestehende
Tags werden nicht umbenannt.

Technisch sind die Nummern **annotierte Git-Tags**. Deshalb überträgt „Änderungen
hochladen" sie mit (`push --follow-tags`) — ein einfaches `git push` schickt
keine Tags. Name und Verfügbarkeit werden **vor** dem Commit geprüft: Eine
bereits vergebene oder ungültige Nummer verhindert den Commit, statt ihn
unwiderruflich mit fehlender Nummer stehen zu lassen. Scheitert erst das Tag
selbst, bleibt der Versionsstand gültig und der Dialog weist die fehlende Nummer
getrennt als Warnung aus.

Der automatische Versionsstand des Cron (`[git] auto_commit`) vergibt die
Nummern ebenfalls — allerdings nur für den Stand, der **veröffentlicht** wird.
Die Vorab-Sicherung offener Änderungen vor dem Build bleibt ohne Nummer: Sie ist
eine Zwischenablage, kein Ausgabestand. Das Wort vor der Nummer steht für diese
Läufe in der Mount-Konfiguration (`[git] tag_label`, Vorgabe `Ausgabe`), weil
beim Cron kein Client die Sprache kennt; im Dialog kommt es weiterhin aus der
Oberfläche. Leerer Wert = nur die Nummer.

**Protokoll neu aufbauen.** Der Knopf *Protokoll neu aufbauen* im
Versionierungs-Dialog erzeugt `changelog.md` vollständig aus der Historie —
berücksichtigt werden ausschließlich Stände **mit Versionsnummer**, in der
Reihenfolge neueste zuerst, mit dem Datum des jeweiligen Standes. Der bisherige
Inhalt wird ersetzt, von Hand ergänzter Text geht also verloren; der Dialog
fragt deshalb vorher nach. Geschrieben wird nur die Datei: Sie erscheint danach
als offene Änderung und geht mit dem nächsten Versionsstand mit — was in die
Historie wandert, entscheidet der Benutzer.

**Änderungsprotokoll.** Bei jedem Versionsstand bekommt die Seite
`changelog.md` im Content-Mount einen Abschnitt dazu — Überschrift aus
Versionsnummer und Datum (`## Ausgabe 3 — 26.08.2026 14:12`; auf Englisch
`Edition`), darunter die Beschreibung samt Dateiliste. Das Wort vor der Nummer
schickt der Client mit (`tagLabel`), wie die Beschreibungen selbst — sichtbarer
Text ist sprachabhängig und gehört deshalb nicht ins Backend. Neue
Einträge stehen oben, damit niemand an das Ende einer wachsenden Seite scrollen
muss. Die Seite entsteht beim ersten Mal von selbst, mit Front Matter und dem
Titel „Änderungen"; danach wird nur noch ihr `lastmod` fortgeschrieben, während
der übrige Kopf und der vorhandene Text unangetastet bleiben.

Geschrieben wird **vor** `git add -A` ([GitService.php](backend/core/GitService.php),
Parameter `$beforeAdd` von `commit()`). Die Seite liegt selbst im Repository und
muss deshalb in genau dem Stand landen, den sie beschreibt — sonst bliebe sie
nach jedem Sichern als offene Änderung liegen und der Arbeitsbaum würde nie
sauber. Der Zugriff läuft über `FileService`/`MountResolver`, sodass Einsperrung,
Schreibrechte und erlaubte Endungen des Mounts genauso greifen wie bei jeder
anderen Bearbeitung.

Einen Eintrag bekommen **alle** Versionsstände, die HugoCMS anlegt: das Sichern
von Hand, beide Commits einer Wiederherstellung und der automatische Commit des
Cron. Bei der Wiederherstellung wird der Stand der Seite vorher festgehalten
(`ChangelogService::pin()`), denn `read-tree` setzte sonst auch sie auf den alten
Inhalt zurück und das Protokoll verlöre genau die Einträge, die es festhalten
soll.

Da die Seite unter `content/` liegt, wird sie von Hugo gebaut und ist im Web
öffentlich lesbar — mitsamt der Dateipfade. Wer das nicht möchte, ergänzt im
Front Matter `draft: true` oder `headless: true`; die Fortschreibung lässt beides
unangetastet. Ein Fehlschlag beim Schreiben (kein Content-Mount, schreibgeschützt)
wird protokolliert und lässt den Versionsstand unberührt — das Protokoll ist
Beiwerk, der Versionsstand die Hauptsache.

Abschalten lässt es sich je Webseite über `[git] changelog = false` in der
Mount-Konfiguration, in der Oberfläche über den Schalter **Änderungsprotokoll
führen** in den Projekteinstellungen. Die Vorgabe ist **an**: Der Schalter dient
zum Abschalten, nicht zum Einschalten — eine Bestandsinstallation ohne den
Schlüssel führt das Protokoll also mit. Er hängt bewusst *nicht* an
`auto_commit`, denn das Protokoll entsteht bei jedem Versionsstand, nicht nur bei
denen des Cron.

**Zu einem alten Stand zurückkehren.** Der Diff-Dialog trägt „Diesen Stand
wiederherstellen". Die Rückkehr geht bewusst **vorwärts**: Der alte Inhalt wird
geholt und als *neuer* Versionsstand gesichert. Die Historie bleibt vollständig,
jeder Stand bleibt erreichbar, und die Wiederherstellung selbst lässt sich
genauso zurücknehmen. Ein `reset --hard` würde stattdessen die späteren Stände
auslöschen und den Push nur noch mit Gewalt zulassen — für Benutzer ohne
Git-Kenntnisse die falsche Zusage.

Der Ablauf im Einzelnen:

1. **Vorschau statt Warnung.** Vor der Bestätigung zeigt der Dialog die
   betroffenen Dateien mit denselben Abzeichen wie die Liste der offenen
   Änderungen. „Sind Sie sicher?" kann niemand fundiert beantworten, eine Liste
   dessen, was sich ändert, dagegen schon.
2. **Offene Änderungen werden zuvor gesichert**, als eigener Versionsstand. So
   kann die Wiederherstellung nichts vernichten, was noch nicht in der Historie
   steht. Der Dialog sagt es an, bevor er es tut.
3. **Holen und sichern.** Technisch `git read-tree -u --reset <sha>`, dann ein
   Commit. Ein `checkout <sha> -- .` wäre falsch: Es überschreibt nur, was im
   alten Stand existiert, und ließe alles seither Hinzugekommene liegen — das
   Ergebnis wäre ein Mischzustand, den es nie gab. `read-tree` entfernt diese
   Dateien und lässt zugleich unversionierte Verzeichnisse (`public/`,
   `resources/`) unangetastet.
4. **Hinweis auf den Build.** Die veröffentlichte Seite zeigt bis zum nächsten
   Erzeugen noch den bisherigen Inhalt; der Erfolgshinweis bietet den Build
   direkt an, löst ihn aber nicht selbst aus.

**Einzelne Datei zurückholen.** Der Diff-Dialog listet die Dateien des Standes
und stellt jeder ein `mdi-file-restore-outline` zur Seite. Diese Datei landet
als *offene Änderung* im Arbeitsbaum — ohne eigenen Commit — und wird über
dasselbe Formular gesichert wie jede andere Bearbeitung. Das deckt den
häufigeren und harmloseren Wunsch ab: eine Datei auf den alten Inhalt bringen,
ohne die ganze Seite anzufassen.

Der Freigabe-Entwurfsspeicher liegt außerhalb des Repositorys
(`backend/var/review/<hash>`) und bleibt von einer Wiederherstellung unberührt.
Ein bereits terminierter Entwurf kann danach allerdings inhaltlich veraltet
sein, weil er auf einer zurückgesetzten Datei aufbaut.

Voraussetzungen: `git` ist auf dem Server installiert, das Projektverzeichnis
steht unter Git-Versionierung, und für `push` sind die Zugangsdaten der
Gegenstelle in der Serverumgebung eingerichtet (SSH-Schlüssel bzw.
Credential-Helper).

### Mehrbenutzer (`driver = multiuser`)

Mehrere Konten mit zwei Rollen. Die Anmeldung bleibt treiberabstrahiert: Der
Connector kennt weder Speicherformat noch Treibertyp, alle Entscheidungen fallen
im Treiber.

**Speicherform: eine INI je Konto.** Bewusst keine Datenbank — es gibt keine
Abfrage, die SQL bräuchte, und `pdo_sqlite` ist auf Shared Hosting nicht
verlässlich vorhanden. Stattdessen das Muster der Mount-Konfigurationen:

```
backend/users/
  3f2a…c1.ini    ; „redakteur“
  9b71…04.ini    ; „lektorin“
```

Der Dateiname ist der SHA-256 des kleingeschriebenen Anmeldenamens. Damit findet
die Anmeldung das Konto mit EINEM Zugriff statt über einen Verzeichnis-Durchlauf,
und ein Name mit Sonderzeichen kann keinen Pfad aufbrechen. Eine Datei je Konto
(statt einer gemeinsamen Liste) hält gleichzeitige Schreibvorgänge auseinander:
Wer seine Einstellungen speichert, fasst nur seine eigene Datei an.

```ini
[account]
username      = "redakteur"
password_hash = "$2y$10$…"
role          = "editor"                   ; admin | editor
sites         = "kunde-a.example.com"      ; Kommaliste der HOSTS oder "*"
disabled      = "false"

[user]
; Wie die [user]-Sektion der hugocms.ini, nur je Konto. Was hier fehlt, fällt
; auf die dortigen Vorgaben zurück.
content_width = "1440"
```

**Rollen.** `admin` erreicht alle Webseiten, verwaltet Konten und setzt fremde
Passwörter neu; `editor` arbeitet an den unter `sites` zugewiesenen Webseiten.
Kein feingranulares Rechtesystem: Was auf einem Mount erlaubt ist, entscheidet
unverändert dessen `permissions`/`readonly`.

**Zuordnung über den Host**, nicht über den vollen SiteKey — dieselbe Bezugsgröße
wie die Lizenz. Ein Umzug des Endpunkts von `/cms-api` nach `/hugocms-api`
entwertet damit keine Zuordnung. Die Auswahlliste in der Oberfläche liest die
Hosts aus den Kopfzeilen der Mount-Konfigurationen (`SiteKey::knownHosts()`) —
dieselbe Zeile, die `bin/sites.sh` parst.

**Pro-Schranke.** Ohne gültige Lizenz für die aufgerufene Webseite melden sich
nur Administratoren an. Eine harte Sperre hätte eine Installation unbedienbar
gemacht, sobald eine Lizenz fehlt oder abläuft — so bleibt der Weg herein offen,
um sie einzutragen, während die übrigen Konten ruhen.

**Schnittstellen.** `AuthInterface` trägt nur, was beide Treiber können
(Anmeldung, Anmeldedaten ändern, Einstellungen laden/speichern). Alles Weitere
steht in eigenen Verträgen, die der Connector per `instanceof` prüft:

| Schnittstelle | Umsetzung | Wirkung |
|---|---|---|
| `UserAdminInterface` | nur `MultiUser` | Kontenverwaltung; ohne sie gibt es die Befehle `users…` nicht |
| `SiteAwareInterface` | nur `MultiUser` | `bindSite(host, isPro)` — der Connector reicht den Webseiten-Kontext nach, da die Mount-Konfiguration erst nach dem Konstruktor feststeht (Lizenzstatus als Rückruf, damit die Prüfung nur bei Bedarf läuft) |

**Umstieg vom Einzelbenutzer.** Im Dialog „Konfiguration ändern" umschaltbar
(Abschnitt *Anmeldung*) oder von Hand: Es genügt, `driver = multiuser` zu setzen. Ist
`users/` noch leer, macht `AuthFactory::seedFirstAdmin()` aus `[auth] username` +
`password_hash` das erste Administratorkonto — der Hash wird übernommen, das
Passwort bleibt also unverändert, und eine laufende Sitzung überlebt den Wechsel
(beide Treiber nutzen denselben Sitzungsschlüssel). Fehlen Konten UND
Einzelbenutzer, bricht der Aufbau mit `AUTH-MULTIUSER-NO-ACCOUNTS` ab, statt eine
Installation ohne Zugang zu hinterlassen.

`username`/`password_hash` bleiben danach in `[auth]` stehen und veralten dort —
gelesen werden sie nur, solange `users/` leer ist. Das ist ein
Wiederherstellungsweg; wer ihn nicht will, löscht beide Zeilen nach dem Umstieg.

**Rückweg.** Der Dialog schaltet auch zurück auf `singleuser`. Dabei schreibt
`authSectionForDriver()` die Anmeldedaten des GERADE angemeldeten Kontos in
`[auth]` — sonst gälte wieder der Stand vor der Umstellung, und wer sein Passwort
seither geändert hat, käme nicht mehr herein. Die Kontodateien bleiben liegen;
ein erneuter Wechsel findet sie unverändert vor.

**Verwaltende Befugnisse.** `MultiUser::ADMIN_PERMISSIONS` führt, was der Rolle
`admin` vorbehalten ist: `users.manage` (Konten) und `config.manage`. Die Grenze
verläuft entlang SCHREIBEN, nicht LESEN:

| Befehl | Redakteur | Grund |
|---|---|---|
| `config` | ja | reines Lesen; die Antwort führt keine Geheimnisse, Schlüssel und Passwörter erscheinen nur als `…Configured`-Flag |
| `reconfigure`, `aimodels`, `activate` | nein | verändern die Installation bzw. die Lizenz (`requireConfigAdmin()`) |
| `projectconfig`, `projectreconfigure` | ja | Einstellungen EINER Webseite (SEO-Ausschlüsse, Verbesserer, Cron-Pausen, automatischer Versionsstand, Analyse-Adressen) — redaktionelle Arbeit |
| `users…` | nein | Kontenverwaltung (`users.manage`) |

Entsprechend melden `reconfigurable` und `projectConfigurable` nur, ob es
überhaupt eine Datei zum Anzeigen gibt; die Befugnis zum Speichern steht getrennt
in `manageConfig`. Der Konfigurationsdialog öffnet sich damit auch für
Redakteure, sperrt aber sämtliche Felder (`<v-form :disabled>`) und blendet einen
Hinweis ein. `licensable` bleibt an `config.manage` gebunden — dort gibt es
nichts einzusehen, nur zu aktivieren.

Beim Einzelbenutzer liefert `can()` immer `true` — dort ändert sich nichts.

**Selbstsperren ausgeschlossen.** Das letzte aktive Administratorkonto lässt sich
weder löschen noch herabstufen noch sperren; das eigene Konto lässt sich nicht
selbst entmachten und nicht über die Verwaltung ändern — dafür gibt es den
Konto-Dialog mit Passwortbestätigung.

### Lizenzmodell

Die Lizenz gilt **pro Webseite** und ist an deren **Domain** gebunden
(normalisierter Host: klein, ohne Port, ohne Endpunkt-Pfad). Ein Umzug des
API-Endpunkts (`/cms-api` → `/hugocms-api`) lässt die Lizenz gültig; eine andere
Domain nicht. Es gibt **kein Ablaufdatum**. Da eine Installation mehrere
Webseiten bedient, bekommt jede Webseite ihren eigenen Schlüssel.

Geprüft wird die Lizenz mit einem im Backend eingebetteten **öffentlichen**
Ed25519-Schlüssel (`backend/core/License.php`); manipulierte Schlüssel werden
abgewiesen. Die Schlüssel werden vom Anbieter ausgestellt (signiert) — der
zugehörige private Schlüssel ist nicht Teil dieses Repos.

### Einen Pro-Schlüssel verwenden

1. In der Titelleiste den **Lizenz-Knopf** öffnen (`mdi-key-outline`; bei
   aktiver Pro-Lizenz `mdi-license`). Der Dialog zeigt Edition, Lizenznehmer und
   die Domain dieser Webseite.
2. Den vom Anbieter erhaltenen Schlüssel (`HUGOCMS-…-…`) einfügen und
   **Aktivieren**.
3. HugoCMS prüft Signatur und Domain und schreibt den Schlüssel in die
   `[license]`-Sektion der **geladenen** Mount-Konfiguration dieser Webseite
   (host-eigene `mounts/<hash>.ini` oder der Rückfall `mounts.ini` — letzterer
   greift bei Einzelprojekt-Installationen und in der Entwicklung). Die übrigen
   Sektionen bleiben unverändert. Die Pro-Funktionen greifen ab der nächsten
   Anfrage; der Versionierungs-Knopf erscheint.

Ein für eine andere Domain ausgestellter Schlüssel wird abgewiesen
(`LICENSE-INVALID`); ohne gültige Lizenz sind die Pro-Befehle gesperrt
(`PRO-REQUIRED`). Der Schlüssel lässt sich auch direkt in der
`mounts/<hash>.ini` hinterlegen:

```ini
[license]
key = "HUGOCMS-...-..."
```

### SEO-Check

Analysiert das **gebaute** Projekt (`public/`) plus die Hugo-Quellen und meldet
Funde je Regel — fehlende/duplizierte Titel und Meta-Descriptions,
Überschriften-Hierarchie, Bilder ohne `alt`, Canonical, Open Graph/Twitter,
HTML-Grundgerüst, defekte interne Links, URL-Struktur, `robots.txt`/Sitemap,
Hugo-Front-Matter u. a. Der Check läuft rein aus Bordmitteln
(`DOMDocument`/`DOMXPath`), ohne HTTP-Requests. Ein Bericht wird je Webseite als
Verlauf vorgehalten (`backend/var/audit/<hash>/`, JSON — bewusst keine
Datenbank). Jeder Fund trägt nur eine **Regel-ID** (kein übersetzter Text); der
Client übersetzt und verlinkt eine ausführliche Hilfe (`backend/help/audit/`),
und springt aus einem Fund direkt zur betroffenen Quelldatei.

Geöffnet über den **SEO-Check-Knopf** in der Titelleiste; im Reiter
„SEO-Bericht" lassen sich Läufe starten, filtern (Schweregrad/Kategorie, Suche
nach URL/Quelle) und vergleichen.

**Duplikate erscheinen zusammengefasst:** Steht derselbe Titel oder dieselbe
Meta-Description auf vielen Seiten, zeigt der Bericht dafür EINE aufklappbare
Zeile mit der Zahl der betroffenen Seiten. Gezählt werden die Funde weiterhin
einzeln — jede Seite behält ihren eigenen Fund samt Sprung zur Quelldatei.

**Auswahl und Sammelaktionen:** Jede Zeile trägt ein Kontrollkästchen, das
Kopf-Kästchen wählt alles, was der aktuelle Filter zeigt (nicht nur die
gerenderten Zeilen); bei einer zusammengefassten Duplikat-Gruppe wählt das
Kästchen der Kopfzeile alle ihre Funde. Mit einer Auswahl erscheint eine Leiste
mit zwei Aktionen: **ignorieren** und **mit KI bearbeiten**.

**Ignorierte Funde:** Ignorieren gilt **je Webseite und dauerhaft**, nicht je
Lauf — die Vormerkung liegt als `ignored.json` neben den Berichten
(`backend/var/audit/<hash>/`, Klasse `Audit\IgnoreStore`) und überlebt neue
Durchläufe. Angewandt wird sie erst beim Ausliefern, deshalb wirkt sie
rückwirkend auch auf gespeicherte Läufe. Ein ignorierter Fund zählt **nirgends
mehr mit**: nicht in der Zusammenfassung, nicht in den Kategoriezählern, nicht
im nächtlichen E-Mail-Bericht (der die Zahl der Ignorierten aber ausweist) und
auch nicht in der Verknüpfung mit der Content-Qualität. Er verschwindet aber
nicht aus der Liste, sondern rutscht blass an ihr Ende — eine Entscheidung, die
man nicht mehr sieht, ließe sich auch nicht mehr zurücknehmen. Ein eigener
Filter-Chip zeigt ausschließlich die ignorierten Funde. Angesprochen wird ein
Fund über `ruleId|url` (ersatzweise `ruleId|sourceFile`) — dieselbe Kennung, mit
der der Server ihn im Bericht wiederfindet.

**Micro-Aufträge an die KI:** Funde, die sich über die Content-Datei beheben
lassen (`RuleCatalog::FIXABLE` — Titel, Meta-Description, Überschriften,
Bild-`alt`, Open Graph, Front-Matter u. a.), tragen einen Zauberstab-Knopf. Er
startet über `assistantfix` einen eng gefassten Auftrag: genau dieser eine Fund,
genau diese Datei. Den Fund schlägt der **Server** im gespeicherten Lauf nach —
der Client schickt nur Lauf-ID, Regel-ID und URL, kein Meldungstext. Die
Anweisung enthält zusätzlich den Hilfetext der Regel und, bei Duplikaten, die
übrigen betroffenen Seiten, damit der neue Text sich von ihnen unterscheidet.
Schreibmodus, Bestätigungspause und Entwurfs-Freigabe sind die des normalen
Assistenten. Der Fund selbst bleibt im Bericht stehen — dieser ist der
Schnappschuss eines Laufs; er verschwindet erst nach dem nächsten Bauen und
Prüfen.

**Sammelauftrag (`assistantfixmany`):** „Mit KI bearbeiten" über eine Auswahl
bündelt die Funde **je Content-Datei zu einem Auftrag** — das Modell liest die
Datei einmal statt für jeden Fund erneut und sieht die Mängel im Zusammenhang
(ein Titel, der die Description ergänzt, statt zweier isolierter
Umformulierungen). Über mehrere Dateien hinweg arbeitet der Client die Gruppen
**nacheinander** ab, ein Request je Datei; das Backend bleibt damit zustandslos.
Wartet ein Auftrag auf den Benutzer (Bestätigungspause im `confirm`-Modus oder
Schrittgrenze), hält die Reihe an und läuft nach seiner Entscheidung von selbst
weiter; eine Leiste nennt die laufende Datei und lässt die Reihe anhalten. Nicht
über die Content-Datei behebbare und ignorierte Funde fallen aus der Bündelung
heraus — die Leiste weist die Differenz aus, statt sie zu verschweigen.

**Diagnose statt Behebung:** Die übrigen 30 Regeln wurzeln im Theme, in der
Hugo-Konfiguration oder in der Seitenstruktur — sie tragen deshalb einen
Stethoskop-Knopf (`mode=diagnose`). Der Assistent läuft dafür im
**Nur-Lese-Modus**, bekommt also gar keine Schreibwerkzeuge, liest sich durch das
Projekt (die von `install.sh` erzeugten Mounts umfassen das Projektverzeichnis
mit Konfiguration und `layouts/`) und antwortet in vier Teilen: Ursache,
Fundstelle, konkrete Änderung, Reichweite. Die Anweisung nennt ihm dabei, wie
viele Seiten dieselbe Regel im selben Lauf betrifft — betrifft sie viele, liegt
die Ursache fast sicher in einem Template und nicht an einer einzelnen Seite.
Gehört die Fundstelle zu einem Theme, soll er das Überschreiben in `layouts/` als
aktualisierungsfesten Weg nennen. Behebbar und diagnostizierbar schließen
einander aus (21 + 30 = alle 51 Regeln); es gibt deshalb nur EINE gepflegte
Liste, `RuleCatalog::FIXABLE` — der Rest ergibt sich daraus.

### Content-Qualität & KI-Verbesserung

Ergänzend zum regelbasierten SEO-Check bewertet die **Content-Qualität** einzelne
Inhaltsdateien per LLM (Lesbarkeit, Dünn-Content, Meta-/SEO-Felder) — je Datei
ein einziger Modellaufruf mit erzwungener, strukturierter Ausgabe (Score 0–100,
Befunde, Vorschläge). Ergebnisse liegen je Datei unter
`backend/var/audit-content/<hash>/` und werden im Reiter „Content-Qualität"
gelistet (Marker „veraltet", wenn die Quelle sich seit der Prüfung geändert hat).
Ausgelöst wird die Prüfung aus dem Kontextmenü, dem Editor oder der Liste.

Der **Gesamt-Bericht** einer Datei verbindet das Qualitätsurteil mit den
SEO-Funden derselben Datei aus dem jüngsten SEO-Check. Auf dieser Grundlage
verbessert der **KI-Assistent** eine Datei direkt: Über den Befehl
`assistantimprove` (Knopf „Mit KI verbessern" in Editor, Kontextmenü und Liste)
ruft er intern `get_file_report` auf und bearbeitet ausschließlich die Zieldatei;
im `confirm`-Modus wird jede Änderung wie gewohnt bestätigt. Der Schnellweg
funktioniert auch **ohne** vorher erstellten Qualitätsbericht.

**Die SEO-Funde werden dabei mit abgearbeitet** — beim Knopfdruck wie im
nächtlichen Cron-Lauf, denn beide nutzen dieselbe Startanweisung. Damit das
verlässlich gelingt, liefert `get_file_report` zu jedem Fund mit, was zum Beheben
nötig ist: `fixable` markiert die Funde, die sich allein über diese Content-Datei
beheben lassen (`RuleCatalog::FIXABLE`); `duplicateOf` nennt bei doppelten Titeln
und Meta-Descriptions die übrigen betroffenen Seiten, damit der neue Text sich von
ihnen abhebt statt erneut austauschbar zu sein; das Glossar `rules` erklärt jede
vorkommende Regel (Titel und Zusammenfassung aus `backend/help/audit/`, je Regel
einmal statt an jedem Fund). Die Anweisung verlangt ausdrücklich, jeden
`fixable`-Fund zu beheben, die übrigen nur zu benennen und keine Front-Matter-
Felder für Theme-Probleme zu erfinden.

Jede vom Assistenten geschriebene Datei wird als „verbessert" vermerkt; die
Arbeitsliste „Zu verbessern" (Score < 100 und noch nicht verbessert) leitet sich
daraus ab. Ein Eintrag lässt sich über „Wieder aufnehmen" erneut aufnehmen.

**Ohne Prüfung vormerken (Check überspringen).** Über das Kontextmenü „Mit KI
später verbessern" lassen sich eine oder mehrere Markdown-Dateien direkt in die
Liste „Zu verbessern" aufnehmen — **ohne** den kostenpflichtigen Qualitäts-Check
(Befehl `auditcontentqueue`). Vorher kann der Benutzer eine Anweisung an die KI
mitgeben; sie ist mit einem sinnvollen Vorschlag vorausgefüllt und lässt sich
über Bausteine ergänzen. Der so entstandene Eintrag trägt kein Qualitätsurteil
(Score leer), sondern nur die Anweisung (`userInstruction`); der
Cron-Verbesserer arbeitet ihn wie einen geprüften Eintrag ab und befolgt die
Anweisung vorrangig.

**Cron (automatische Verbesserung).** Das CLI-Werkzeug
`backend/cli/cron-improve.php` verbessert die nächsten Dateien dieser
Arbeitsliste automatisch — im Schreibmodus `auto`, ohne Bestätigung:

```bash
# Probelauf: nur zeigen, was verarbeitet würde (kein API-Aufruf, kein Schreiben)
php backend/cli/cron-improve.php --dry-run --limit=5

# Echter Lauf (schreibt wirklich); --host ist die lizenzierte Domain
php backend/cli/cron-improve.php --host=example.com --limit=3
```

Optionen: `--host=<domain>` (Pflicht außer bei `--dry-run` — die Lizenz ist
domaingebunden), `--mounts=<datei>` (Standard `backend/mounts.ini`),
`--limit=<N>`, `--locale=<de|en>`, `--dry-run`. Exit-Codes: 0 Erfolg,
1 Laufzeitfehler, 2 Aufruffehler. Der Cron **prüft** nicht selbst — er verbessert
nur bereits geprüfte Dateien und stößt keine automatische Neuprüfung an.

## Gestaffelte Veröffentlichung (Freigabe)

Damit nicht jede automatische oder ungeprüfte Änderung sofort online geht, gibt
es eine **Freigabe-Warteschlange**. Entwürfe liegen serverseitig unter
`var/review/` (Blob mit dem vollständigen Vorschlag) — die Live-Datei bleibt bis
zur Freigabe unangetastet.

- **Was landet als Entwurf?** Schreibvorgänge im KI-Schreibmodus `auto` (der
  Cron-Verbesserer, oder ein so konfigurierter interaktiver Assistent) gehen bei
  einem konfigurierten Hugo-Projekt nicht in die Datei, sondern als Entwurf in
  die Warteschlange — die veröffentlichte Seite bleibt unangetastet. Im Modus
  `confirm` entscheidet der Benutzer je Schreibvorgang: „Übernehmen" schreibt
  live, **„Später veröffentlichen"** legt denselben Vorschlag als Entwurf ab und
  **„Termin festlegen"** gleich terminiert (Antwort `draft` auf die Bestätigung,
  optional mit `publishDate`). Zusätzlich legt der **Entwurf-Knopf**
  neben „Speichern" den aktuellen Editor-Stand manuell als Entwurf ab. Normales
  Speichern ist nicht betroffen. Nur Schreib-/Anlege-Vorgänge werden gestaffelt,
  keine Löschungen oder Umbenennungen.
- **Freigabe.** Die Warteschlange (Werkzeugschiene) zeigt jeden Entwurf mit
  einem Zeilen-Diff gegen den Live-Stand. Der Benutzer gibt frei — **sofort** oder
  **terminiert** — oder verwirft.
- **Sofortige Freigabe** schreibt den Vorschlag direkt in die Live-Datei
  (`draft:false`) und entfernt den Entwurf.
- **Terminierte Freigabe (verzögerter Austausch).** Der Entwurf bleibt mit einem
  Feld `publishAt` im Speicher; die Live-Datei wird **nicht** angefasst. So bleibt
  die **bestehende Fassung bis zum Termin unverändert veröffentlicht**. Ein Build
  wendet fällige Austausche vorab an: Ist `publishAt` erreicht, wird der Vorschlag
  jetzt in die Datei geschrieben und danach gebaut. Es wird **kein** `publishDate`
  und **kein** `draft` als Zeitschalter benutzt — die alte Version geht also nie
  offline, sie wird zum Zeitpunkt schlicht ersetzt.

Damit terminierte Austausche auslösen, muss regelmäßig gebaut werden (der Web-
„Veröffentlichen"-Knopf und der CLI-Build wenden fällige Austausche jeweils vorab
an):

```bash
# wendet fällige Austausche an und baut, WENN welche fällig waren; sonst wird der
# Hugo-Lauf übersprungen. Ohne Web-Anmeldung; Minify/Ziel/Clean aus [hugo].
php backend/cli/cron-build.php --mounts=backend/mounts.ini --quiet
```

Der CLI-Build **baut nur, wenn tatsächlich eine terminierte Freigabe fällig war** —
läuft der Cron alle paar Minuten, spart das den Hugo-Lauf, solange nichts zu
veröffentlichen ist (Meldung „Keine fälligen Freigaben — kein Build.", Exit 0).
Mit **`--force`** wird immer gebaut; das braucht, wer zusätzlich über Hugos
eigenes Front-Matter-`publishDate` terminiert, dessen Fälligkeit keinen
Warteschlangen-Eintrag erzeugt und erst ein Build sichtbar macht. Der Web-
„Veröffentlichen"-Knopf baut unabhängig davon immer.

Die Auflösung der Staffelung entspricht dem Cron-Intervall (z. B. alle 15
Minuten). Exit-Codes: 0 Erfolg (gebaut oder übersprungen), 1 Hugo-/Laufzeitfehler,
2 Aufruffehler. Keine Pro-Lizenz nötig.

**Zuordnung zur Webseite (Mandantenfähigkeit).** Entwürfe liegen — wie das
Audit — je Webseite getrennt unter `var/review/<hash(source)>/`, gehasht aus dem
Hugo-Quellverzeichnis. Ein Web-Request lädt über den Host die passende
`mounts/<hash>.ini` (siehe [Mandantenfähigkeit](#mandantenfähigkeit-mehrere-webseiten)),
deren `[hugo] source` den Store-Ordner bestimmt — ein Entwurf gehört damit
eindeutig zu genau einer Webpräsenz. Zwei Folgen für den Mehrfach-Betrieb:

- **CLI je Domain aufrufen.** `cron-build.php`/`cron-improve.php` müssen mit der
  host-eigenen Mount-Datei laufen (`--mounts=backend/mounts/<hash>.ini`), sonst
  gilt der Standard `backend/mounts.ini` und der Lauf — inklusive der fälligen
  Austausche — bezöge sich auf die falsche Webseite.
- **Fehlt die host-eigene `mounts/<hash>.ini`**, greift der Rückfall auf
  `mounts.ini` (mit Hinweis, siehe Mandantenfähigkeit). Teilen sich mehrere Hosts
  so dieselbe Quelle, teilen sie sich auch den Review-Ordner. Im sauber
  eingerichteten Mehrfach-Betrieb hat jede Domain ihre eigene Mount-Datei.

## Entwurfsfilter im Dateimanager

Rechts neben dem Suchfeld schaltet ein Knopf (`mdi-file-document-alert-outline`)
die Ansicht auf **Entwürfe** um: alle Content-Dateien ab dem angezeigten
Verzeichnis, deren Front Matter `draft: true` trägt — also die Seiten, die Hugo
nicht veröffentlicht. Gesucht wird rekursiv über den Befehl `draftsearch`; die
Treffer erscheinen in derselben Ergebnisliste wie die Namenssuche, samt
Pfadspalte. Ein zweiter Klick führt zurück.

Erkannt wird das Kennzeichen von `FrontMatter::isTrue()` — dieselbe
Formaterkennung wie beim Setzen (YAML `---`, TOML `+++`, JSON), ohne zusätzlichen
Parser. Gelesen werden nur die ersten 8 KB je Datei: Das Front Matter steht am
Anfang, eine ganze Seite einzulesen wäre für diese Frage verschwendet. Grenzen
wie bei der Namenssuche (200 Treffer, 20.000 besuchte Einträge).

> Nicht zu verwechseln mit den **Freigabe-Entwürfen** der gestaffelten
> Veröffentlichung: Die liegen im Index-Store und betreffen vorgeschlagene
> Fassungen, hier geht es um das Front Matter der Datei selbst.

## Hyperlink-Suche

Ein Werkzeug der Werkzeugschiene (frei, kein Pro), das eine Adresse in den
Hugo-Quellen (`content/`, aus der Hugo-Konfiguration gelesen) und im gebauten
Ordner sucht. Zweck ist das Aufspüren **falsch geschriebener Links**: Gefunden
wird nicht nur die eingegebene Adresse, sondern auch, was ihr ähnlich sieht.

- **Vier Arten von Treffern.** *Genau so geschrieben* (zeichengleich),
  *abweichende Schreibweise* (nach Normalisierung gleich: Groß-/Kleinschreibung,
  Schrägstrich am Ende, Umlaute, vollständige Adresse statt Pfad), *mögliche
  Tippfehler* (Editierdistanz ≤ 1–3, gestaffelt nach Länge der Suchadresse) und
  *Adressen darunter* (die Suchadresse ist ein Präfix an einer Segmentgrenze).
- **Protokoll-Suche.** Sieht die Eingabe wie ein Protokoll aus (`http:`,
  `http://`, `https://`, `mailto:` …, Doppelpunkt ist Pflicht), zählt allein der
  Anfang des Links: Geliefert wird alles, was so beginnt. Der praktische Zweck
  ist `http://` — unverschlüsselte externe Links, die auf `https` gehören.
  Ähnlichkeit bleibt dabei außen vor, denn `https:` ist kein Vertipper von
  `http:`, sondern das Gegenteil.
- **Pfad oder Domain.** Jede Adresse wird in zwei Vergleichsformen zerlegt: mit
  Rechnernamen (`www.example.de/kontakt`) und nur der Pfad (`kontakt`); verglichen
  wird jede Form gegen jede. So findet die Suche nach `/kontakt` auch das absolut
  geschriebene `https://www.example.de/kontakt` — und die Suche nach
  `www.example.de` die Links auf diese Domain, samt ihrer Unterseiten. Bei der
  eigenen Domain sind das schnell Tausende Fundstellen (jede gebaute Seite
  verlinkt die Navigation); die Ansicht bündelt sie je Adresse.
- **Was als Hyperlink gilt.** HTML-Attribute `href`/`src`, Markdown-Ziele
  `](…)` und Referenzdefinitionen sowie die Hugo-Shortcodes `{{< ref >}}` /
  `{{< relref >}}`. Durchsucht werden `.md`/`.markdown`/`.html` in den Quellen
  und `.html` im gebauten Ordner; Papierkörbe bleiben außen vor.
- **Segmentiert statt am Stück.** `linkscan` durchsucht je Aufruf 250 Dateien
  und liefert den nächsten `cursor` zurück; der Client ruft den Befehl, bis
  `done` gesetzt ist. So braucht es **keinen** Hintergrundlauf und keinen
  Job-Zustand auf dem Server — der Fortschritt liegt allein im Client, und kein
  einzelner Request kann in ein Zeitlimit laufen. Ein Abbruch wirkt sofort; die
  Ansicht darf zwischendurch geschlossen werden, der Lauf läuft weiter.
- **Grenzen.** 400 Treffer je Segment und 5000 je Lauf; darüber meldet die
  Ansicht das Ergebnis ausdrücklich als unvollständig. Dateien über 4 MiB werden
  übersprungen.
- **Sprung zur Quelle.** Jeder Treffer trägt die Dateimanager-ID seiner Datei,
  sofern sie in einem Mount liegt — Serverpfade gibt der Befehl nie aus. Beim
  gebauten Ordner fehlt die ID, wenn er nicht eingebunden ist; die Fundstelle
  wird dann nur angezeigt. Fundstellen im gebauten Ordner tragen zusätzlich
  `sourceFile`/`sourceFileId`: den Sprung zur **Hugo-Quelle**, aus der die Seite
  entstand — dort wird der Link tatsächlich korrigiert, die gebaute Datei
  überschreibt der nächste Hugo-Lauf. Die Zuordnung macht der
  `Audit\SourceGuesser`, denselben Rückabgleich nutzt der SEO-Check für die
  Quellangabe seiner Funde.

## API-Befehle

| Befehl     | Methode | Parameter                            | Zweck                                  |
|------------|---------|--------------------------------------|----------------------------------------|
| `whoami`   | GET     | –                                    | Anmeldestatus abfragen (liefert u. a. `ui`, `manageUsers`, `csrf`) |
| `login`    | POST    | `username`, `password`               | Anmelden — die Antwort trägt `ui` und `manageUsers` mit, da der Client danach kein `whoami` nachholt |
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
| `draftsearch`| GET  | `target`                             | Entwurfsfilter: alle Content-Dateien ab `target` mit `draft: true` im Front Matter (rekursiv, gleiche Antwortform wie `search`) |
| `linkscan` | GET     | `url` (≥ 2 Z.), `cursor`?            | Hyperlink-Suche in `content/` und im gebauten Ordner — EIN Segment je Aufruf (siehe „Hyperlink-Suche") |
| `trashlist`| GET     | –                                    | Papierkörbe aller Mounts auflisten     |
| `restore`  | POST    | `mount`, `names` (Liste)             | Aus dem Papierkorb wiederherstellen    |
| `emptytrash`| POST   | `mount`?                             | Papierkorb endgültig leeren            |
| `build`    | POST    | –                                    | Hugo aufrufen (Webseite erzeugen)      |
| `previewbuild`| POST | `id` \| `draftKey`, `content`?       | Vorschau EINER Content-Seite bauen. Entweder `id` (Datei; `content` = ungespeicherter Editor-Stand) oder `draftKey` (Freigabe-Entwurf, liefert Pfad und Inhalt selbst — auch für Seiten, die es live noch nicht gibt). Antwort: Einmal-Token |
| `preview`  | GET     | `token`                              | Gebaute Vorschau als HTML ausliefern (nur angemeldet, Token gilt einmal, `X-Robots-Tag: noindex`) |
| `assistant`| POST    | `messages`, `locale`?, `confirm`?, `publishDate`?, `autoConfirm`?, `openFilePath`?, `openDirPath`? | KI-Assistent: einen Zug ausführen (Werkzeug-Schleife). `confirm`: `allow` \| `draft` (als Entwurf ablegen) \| `reject`; `publishDate` terminiert den Entwurf; `autoConfirm` setzt die Bestätigungspause für diesen Zug aus („nicht mehr fragen") |
| `config`   | GET     | –                                    | Aktuelle Konfigurationswerte inkl. AI-Status (ohne Geheimnisse) |
| `reconfigure`| POST  | `authDriver`?, `sessionPath`, `logFile`, `logLevel`, `hugoBin`?, `aiApiKey`?, `aiModel`?, `aiWriteMode`? | hugocms.ini ändern (Anmeldeverfahren/Verzeichnisse/Log/Hugo/AI). Verlangt `config.manage` — ebenso `aimodels` und `activate`. `config` (lesen) und `projectconfig`/`projectreconfigure` nicht |
| `account`  | POST    | `currentPassword`, `username`, `password`? | Anmeldedaten ändern (danach Neuanmeldung) |
| `setuserprefs`| POST | `contentWidth`?, `toolbarCollapsed`?, `sessionLifetime`? (Stunden), `updateLastmod`? (`null` = nachfragen) | Eigene `[user]`-Einstellungen schreiben; nur die genannten Felder |
| `users`    | GET     | –                                    | **Pro/multiuser:** Konten, bekannte Webseiten, Rollen |
| `usercreate`| POST   | `username`, `password`, `role`, `sites` | **Pro/multiuser:** Konto anlegen        |
| `userupdate`| POST   | `username`, `role`?, `sites`?, `disabled`? | **Pro/multiuser:** Rolle, Zuordnung oder Sperre ändern |
| `userpassword`| POST | `username`, `password`               | **Pro/multiuser:** fremdes Passwort neu setzen |
| `userdelete`| POST   | `username`                           | **Pro/multiuser:** Konto löschen        |
| `license`  | GET     | –                                    | Lizenzstatus (Edition, Lizenznehmer, Domain) |
| `activate` | POST    | `key`                                | Pro-Lizenz aktivieren (schreibt `mounts/<hash>.ini`) |
| `gitstatus`| GET     | –                                    | **Pro:** Git-Status (Branch, geänderte Dateien, nächste freie Versionsnummer) |
| `gitlog`   | GET     | `page`?, `perPage`?                  | **Pro:** Verlauf der Versionsstände samt Versionsnummern (seitenweise) |
| `gitdiff`  | GET     | `sha`                                | **Pro:** Diff eines Versionsstands samt vollständiger Beschreibung (`message`) |
| `gitcommit`| POST    | `message`, `tag`?                    | **Pro:** alle Änderungen als Versionsstand sichern; `tag` vergibt die Versionsnummer (leer = ohne) |
| `gitpush`  | POST    | –                                    | **Pro:** Änderungen samt Versionsnummern zur konfigurierten Gegenstelle hochladen |
| `gitchangelog`| POST | `tagLabel`?                        | **Pro:** changelog.md aus der Historie neu erzeugen (nur Stände mit Versionsnummer); schreibt nur die Datei |
| `gitreset` | POST    | `ref`?                               | **Pro:** Arbeitsbaum zurücksetzen (Standard: `HEAD`) |
| `gitrestorepreview` | GET | `sha`                            | **Pro:** Vorschau — welche Dateien eine Wiederherstellung ändern würde |
| `gitrestore` | POST  | `sha`, `message`, `tag`?, `presaveMessage` | **Pro:** zu einem alten Stand zurückkehren; sichert ihn als neuen Versionsstand |
| `gitrestorefile` | POST | `sha`, `path`                    | **Pro:** EINE Datei aus einem alten Stand zurückholen (ohne eigenen Commit) |
| `assistantimprove`| POST | `id` (Datei-ID), `locale`?         | **Pro:** KI-Verbesserung einer Datei starten (nutzt `get_file_report`) |
| `assistantfix`| POST | `runId`, `ruleId`, `url`?, `mode`?, `locale`? | **Pro:** KI-Micro-Auftrag zu genau einem Fund des SEO-Berichts (`mode`: `fix` behebt, `diagnose` erklärt nur) |
| `assistantfixmany`| POST | `runId`, `issues` (`[{ruleId, url}]`), `locale`?, `autoConfirm`? | **Pro:** gebündelter KI-Auftrag über mehrere Funde EINER Content-Datei (höchstens 25) |
| `audit`    | POST    | –                                    | **Pro:** SEO-Check-Lauf ausführen (Bericht)            |
| `auditlist`| GET     | –                                    | **Pro:** gespeicherte Läufe auflisten                  |
| `auditget` | GET     | `id`                                 | **Pro:** vollständiger Bericht eines Laufs             |
| `auditdelete`| POST  | `id`                                 | **Pro:** einen Lauf löschen                            |
| `auditignore`| POST  | `keys` (`ruleId\|url`), `ignored`?, `runId`? | **Pro:** Funde dauerhaft ignorieren/wieder aufnehmen (je Webseite); liefert mit `runId` den neu gerechneten Bericht zurück |
| `auditcontent`| POST | `id` (Datei-ID), `locale`?           | **Pro:** Content-Qualität einer Datei prüfen (LLM)     |
| `auditcontentlist`| GET | –                                  | **Pro:** geprüfte Dateien auflisten (Score, Marker)    |
| `auditcontentget` | GET | `key`                               | **Pro:** gespeichertes Prüfergebnis einer Datei        |
| `auditcontentreport`| GET | `key`                             | **Pro:** Gesamt-Bericht (Qualität + zugehörige SEO-Funde) |
| `auditcontentrequeue`| POST | `key`                           | **Pro:** „Wieder aufnehmen" (Verbesserungs-Vermerk löschen) |
| `auditcontentqueue`| POST | `ids` (Datei-IDs), `instruction`?  | **Pro:** Dateien ohne Prüfung zur Verbesserung vormerken (mit KI-Anweisung) |
| `auditcontentdelete`| POST | `key`                            | **Pro:** ein Prüfergebnis löschen                      |
| `reviewsave`| POST   | `target` (ID), `content`             | Inhalt als Freigabe-Entwurf ablegen (nicht live)     |
| `reviewlist`| GET    | –                                    | offene Entwürfe der Warteschlange auflisten            |
| `reviewget` | GET    | `key`                                | Entwurf samt aktuellem Live-Stand (für den Diff)       |
| `reviewapprove`| POST | `key`, `publishDate`?, `force`?     | Freigeben: ohne Termin sofort live; mit künftigem `publishDate` terminiert (verzögerter Austausch) |
| `reviewdiscard`| POST | `key`                               | Entwurf verwerfen (Live-Datei bleibt)                  |

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

## Sitzungen und ihre Bereinigung

Die Sitzungen liegen in `backend/var/sessions` (`[session] path`), nicht im
Standardverzeichnis von PHP. Das hat eine Folge, die man kennen muss: PHPs
eigene Müllabfuhr räumt dort **nicht** auf. Auf Debian und Ubuntu steht
`session.gc_probability` auf 0, weil ein System-Cron
(`/usr/lib/php/sessionclean`) das Standardverzeichnis putzt — das eigene kennt er
nicht. Ohne Gegenmaßnahme bleibt die Datei jedes Benutzers liegen, der einfach
den Tab schließt.

**Auf der Kommandozeile entsteht gar keine Sitzung.** Die Cron-Skripte melden
sich nicht an, PHP legte aber bei jedem Lauf trotzdem eine Datei an — bei einem
Build-Cron alle 15 Minuten fast hundert am Tag, die nie jemand abholt.
`startSession()` steigt deshalb bei `PHP_SAPI === 'cli'` sofort aus. Das
Aufräumen selbst (`Connector::purgeSessions()`) läuft im Cron unverändert.

`Auth\SessionCleaner` übernimmt den Rest. Zwei Dinge machen ihn nötig und
zugleich unaufwendig:

- **Jede Sitzung trägt ihren Verfallszeitpunkt selbst.** `enforceIdleTimeout()`
  schreibt neben dem letzten Zugriff auch `hugocms_fm_expires`. Das ist
  entscheidend, weil die Sitzungsdauer **je Konto** einstellbar ist
  (`prefs.session_lifetime`, sonst `[user] session_lifetime`) und nach oben offen
  ist: Eine feste Frist würde Konten mit langer Dauer aus dem Verzeichnis werfen
  und die Betreffenden abmelden. Gelöscht wird nur, was laut eigener Angabe
  abgelaufen ist — plus eine Stunde Gnadenfrist.
- **Aufgeräumt wird im Web-Request**, direkt nach `session_start()`. Dort läuft
  der Code unter dem Benutzer, dem die Dateien gehören — ein Cron unter einem
  anderen Konto dürfte sie unter Umständen gar nicht löschen. `purgeDue()`
  begrenzt sich über einen Merker (`.hugocms-purge`) auf **einen Lauf je
  Stunde**: Der erste Zugriff nach Ablauf des Fensters räumt auf, alle anderen
  sind sofort wieder draußen. Bewusst zeitgesteuert statt zufällig — eine
  Wahrscheinlichkeit von 1:100 bedeutet bei einer Redaktion mit wenigen
  Zugriffen am Tag, dass tagelang nichts passiert und niemand nachvollziehen
  kann, ob die Bereinigung arbeitet. Je Lauf höchstens 500 Löschungen, damit ein
  Request nicht an einem großen Verzeichnis hängt; der Rest folgt beim nächsten
  Mal.

Dateien **ohne** den Verfallszeitpunkt — Altbestände aus der Zeit vor dieser
Änderung — verschwinden über eine bewusst großzügige Rückfall-Frist von 30 Tagen
ohne Zugriff. Angefasst wird ausschließlich, was mit `sess_` beginnt.

Zusätzlich ruft `cron-build.php` `Connector::purgeSessions()` auf: Läuft der Cron
ohnehin alle paar Minuten und passen die Dateirechte, kostet das nichts. Der
verlässliche Weg bleibt der im Web-Request.

## Sicherheit

- **Einsperrung pro Mount:** Pfade werden mit `realpath()` aufgelöst und müssen
  innerhalb ihres Mounts liegen; `..` ist verboten.
- **Anmeldepflicht:** Alle Datei-Befehle erfordern eine gültige Sitzung.
- **Rechte je Mount:** `permissions` und `readonly` begrenzen Operationen pro
  Mount. Sie gelten unabhängig vom Anmeldeverfahren: Beim Mehrbenutzer regelt
  die Rolle nur, WELCHE Webseiten ein Konto sieht und wer Konten verwaltet —
  was auf einem Mount erlaubt ist, entscheidet weiterhin allein dessen
  Konfiguration.
- **Passwörter nur als Hash:** `password_hash()` mit `PASSWORD_DEFAULT`, in der
  `hugocms.ini` bzw. in der Kontodatei. Beim Mehrbenutzer laufen Anmeldeversuche
  mit unbekanntem Namen gegen einen Vergleichshash, damit die Antwortzeit nicht
  verrät, welche Konten es gibt.
- **Host-sicherer Mount-Pfad:** Die host-spezifische Mount-Datei wird über einen
  SHA-256-Hash adressiert; ein manipulierter Host-Header kann keinen
  Pfad-Ausbruch erzeugen.
- **Schreiboperationen nur per POST**, Session-Cookie mit `HttpOnly` und
  `SameSite=Lax`.
- **CSRF-Token:** Alle Schreibbefehle verlangen das sitzungsgebundene Token
  aus `whoami` im Header `X-CSRF-Token` (zweite Schicht neben `SameSite=Lax`).
  Das einmalige Einrichtungs-Setup (vor Existenz der `hugocms.ini`) läuft ohne
  Token, da dort noch keine schützenswerte Sitzung besteht.

## Entwicklungsstand

Welche Funktionen fertig sind und was noch aussteht, steht in
[ENTWICKLUNGSSTAND.md](ENTWICKLUNGSSTAND.md).
