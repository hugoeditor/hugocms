# Cron-Jobs einrichten

In diesem Verzeichnis liegen drei Skripte, die HugoCMS über die Kommandozeile
ausführt. Sie sind **nur über die CLI aufrufbar** — ein Aufruf über den Browser
endet mit HTTP 403. Eine Web-Anmeldung gibt es dabei nicht; wer das Skript
starten darf, entscheidet der Server.

| Skript | Zweck | Pro-Lizenz |
|---|---|---|
| `cron-build.php` | Baut die Webseite mit Hugo und veröffentlicht fällige Freigaben | nein |
| `cron-improve.php` | Verbessert geprüfte Content-Dateien per KI | ja |
| `cron-healthcheck.php` | SEO-Gesundheitscheck, meldet Probleme per E-Mail | ja |

## Gemeinsame Voraussetzungen

**PHP-Pfad.** In der Crontab immer den vollen Pfad zum PHP-Programm angeben;
`php` allein steht dort meist nicht im Suchpfad. Auf vielen Shared Hostern gibt
es eine eigene CLI-Version (`/usr/bin/php8.3`, `/opt/php/8.3/bin/php` o. ä.).

**`--host` bei den Pro-Skripten.** Die Pro-Lizenz ist an die Domain gebunden und
wird sonst aus `$_SERVER['HTTP_HOST']` gelesen — im CLI gibt es keinen Host.
`cron-improve.php` und `cron-healthcheck.php` brauchen deshalb zwingend
`--host=example.com` mit genau der Domain, für die der Lizenzschlüssel
ausgestellt wurde. Fehlt sie, endet der Lauf mit Code 2.

**`--mounts` bei mehreren Webseiten.** Ohne Angabe gilt `backend/mounts.ini`.
Betreibt eine Installation mehrere Webseiten, hat jede ihre eigene Datei unter
`backend/mounts/<hash>.ini` — dort stehen Mounts, `[hugo]`, `[license]` und die
Einstellungen der automatischen Verbesserung. Der Dateiname ist ein Hash aus
Domain und Endpunkt-Verzeichnis; am einfachsten liest man ihn aus dem
vorhandenen Verzeichnis ab (`ls backend/mounts/`). **Je Webseite ein eigener
Crontab-Eintrag** mit dem passenden `--mounts` und `--host`.

**Protokoll.** Alle drei Skripte erzwingen `logLevel=info`, damit auch
erfolgreiche Läufe in `backend/log/hugocms.log` erscheinen — ein stiller Cron
bliebe sonst unsichtbar. Im Systemstatus der Anwendung sind die Einträge unter
„Protokoll" einsehbar; dort zeigt der Abschnitt „Cron-Aufgaben" außerdem, wann
jede Aufgabe zuletzt lief und ob sie überfällig ist.

**Exit-Codes.** `0` Erfolg · `1` Laufzeitfehler · `2` Aufruffehler (fehlende
Konfiguration, fehlendes `--host`).

**Pausieren ohne die Crontab anzufassen.** Jede der drei Aufgaben lässt sich pro
Webseite in den Projekteinstellungen pausieren (der Systemstatus zeigt den
Zustand an und verweist dorthin). Ist eine Aufgabe pausiert, prüft ihr Skript
das beim Start, meldet „… ist pausiert — kein Lauf." und endet mit Code 0, ohne
etwas zu tun. Der Crontab-Eintrag bleibt bestehen; man muss also nicht in die
Konsole des Hosters, um einen Cron-Job vorübergehend auszusetzen. Hinterlegt ist
die Pause in der `[cron]`-Sektion der Mount-Konfiguration
(`pause_build`, `pause_improve`, `pause_healthcheck`). Ein Probelauf
(`--dry-run`) ignoriert die Pause bewusst — er dient dem Test und ändert nichts.

## 1. `cron-build.php` — bauen und veröffentlichen

Baut die Webseite wie der „Veröffentlichen"-Knopf. Zuerst werden fällige
terminierte Freigaben in ihre Live-Datei geschrieben.

```cron
*/15 * * * *  /usr/bin/php /pfad/backend/cli/cron-build.php --mounts=/pfad/backend/mounts.ini --quiet
```

Optionen: `--mounts=<datei>`, `--force`, `--quiet` (bei Erfolg keine Ausgabe).

**Gebaut wird nur, wenn es etwas zu veröffentlichen gibt.** Fiel keine fällige
Freigabe an, überspringt der Lauf den Hugo-Aufruf und meldet „Keine fälligen
Freigaben — kein Build." (Code 0). Bei einem Cron alle paar Minuten spart das die
allermeisten Läufe. Der Herzschlag im Systemstatus wird trotzdem gesetzt, die
Aufgabe gilt also weiter als „läuft".

Mit **`--force`** wird immer gebaut. Das braucht, wer Seiten über Hugos eigenes
Front-Matter-`publishDate` terminiert (nicht über die Freigabe-Warteschlange):
Ein solcher Termin erzeugt keine „fällige Freigabe", wird also ohne `--force`
nicht sichtbar. Wer die gestaffelte Veröffentlichung ausschließlich über die
Warteschlange nutzt, braucht `--force` nicht.

Hugo läuft bewusst **ohne** `--buildFuture` und `--buildDrafts`: Seiten mit
künftigem `publishDate` oder `draft: true` bleiben unveröffentlicht. Minify,
Zielordner und `--cleanDestinationDir` stammen aus der `[hugo]`-Konfiguration.

**Automatischer Commit (optional, Pro).** Ist in den Projekteinstellungen der
Auto-Commit aktiviert und das Quellverzeichnis ein Git-Repository, legt der Lauf
zwei Commits an (`git add -A`, wie der manuelle Commit — also alle offenen
Änderungen): **vor** dem Build werden offene, noch unversionierte Änderungen
gesichert (`commit_message_pending`, nur wenn welche vorliegen — die Prüfung
läuft bei jedem Lauf), **nach** dem Einspielen fälliger Freigaben folgt der
Veröffentlichungs-Commit (`commit_message`). So bleibt Letzterer auf die
publizierten Dateien beschränkt. Die Nachrichten kommen aus der
`[git]`-Sektion der Mount-Konfiguration, das Datum wird jeweils angehängt. Ein
fehlgeschlagener Commit bricht den Build nicht ab, er wird nur protokolliert.

> **Der Build-Takt bestimmt die Genauigkeit jeder Terminierung.** Eine Freigabe,
> die für 08:22 Uhr vorgemerkt ist, geht erst beim nächsten Build **nach** diesem
> Zeitpunkt online. Bei einem stündlichen Build kann das bis zu eine Stunde
> dauern, bei einem täglichen bis zu einem Tag. Alle 15 Minuten ist ein guter
> Kompromiss.

## 2. `cron-improve.php` — automatische Verbesserung

Nimmt sich die nächsten geprüften Content-Dateien mit einer Bewertung unter 100,
die noch nicht verbessert wurden, und lässt den KI-Assistenten sie überarbeiten.

```cron
0 3 * * *  /usr/bin/php /pfad/backend/cli/cron-improve.php --host=example.com --limit=3
```

Optionen:

- `--host=<domain>` — **Pflicht** (Lizenzbindung)
- `--mounts=<datei>` — Mount-Konfiguration der Webseite
- `--limit=<N>` — Dateien je Lauf (Standard 1). Bestimmt den API-Verbrauch.
- `--locale=<de|en>` — Sprache der KI-Anweisung (Standard `de`)
- `--dry-run` — zeigt nur, welche Dateien an der Reihe wären: kein API-Aufruf,
  kein Schreiben, keine Lizenz nötig (dann entfällt auch `--host`)

Der Cron **prüft nicht selbst** — er verbessert nur bereits geprüfte Dateien.
Die Prüfung wird in der Anwendung ausgelöst (SEO-Check → Inhaltsprüfung). Nach
der Verbesserung gilt eine Datei als erledigt und fällt aus der Arbeitsliste;
eine automatische Neuprüfung findet bewusst nicht statt. Dateien mit einem
offenen Freigabe-Entwurf werden übersprungen, damit kein Lauf einen wartenden
Vorschlag überschreibt.

### Die Besonderheit: Automatikmodus

Standardmäßig legt der Verbesserer sein Ergebnis als **Entwurf zur Freigabe** ab
— jemand sieht ihn sich an und gibt ihn frei. Im **Automatikmodus** terminiert
der Cron jeden Entwurf stattdessen gleich selbst, zu einem zufälligen Zeitpunkt
innerhalb eines Tagesfensters. Verbesserte Seiten gehen dann verteilt live statt
alle auf einmal.

Eingeschaltet wird er in der Anwendung: SEO-Check → Inhaltsprüfung → „Zu
verbessern" → Schalter *Automatisch terminieren*. Fenster und Tagesmenge stehen
in den Projekteinstellungen. In der Mount-Konfiguration sieht das so aus:

```ini
[improve]
auto = true
window_start = "07:00"
window_end = "16:00"
per_day = 3
```

Die Uhrzeiten sind **Serverzeit**, nicht die des Browsers. Läuft der Server in
einer anderen Zeitzone, entsprechend umrechnen.

**Wie die Termine verteilt werden:** Das Fenster wird in so viele gleich große
Abschnitte geteilt, wie Seiten pro Tag erlaubt sind. Jede Seite bekommt einen
eigenen Abschnitt und darin eine zufällige Minute aus dessen mittlerer Hälfte —
so liegen zwei Freigaben nie dicht beieinander. Bei 07:00–16:00 und 3 Seiten:

| Abschnitt | Zeitraum | möglicher Zeitpunkt |
|---|---|---|
| 1 | 07:00–10:00 | 08:22 |
| 2 | 10:00–13:00 | 11:09 |
| 3 | 13:00–16:00 | 15:02 |

Ist ein Tag voll, wandern weitere Entwürfe auf die Folgetage; heute bereits
vergangene Abschnitte werden übersprungen.

**Was dabei zu beachten ist:**

- **`--limit` und `per_day` sind zwei verschiedene Dinge.** `--limit` steuert,
  wie viele Dateien ein Lauf *bearbeitet* (und damit die API-Kosten), `per_day`,
  wie viele davon pro Tag *live gehen*. Ist `--limit` dauerhaft größer als
  `per_day`, wächst die Warteschlange terminierter Entwürfe.
- **`cron-build.php` muss laufen.** Ohne ihn passiert zum Termin nichts — der
  Build ist es, der die Datei austauscht. Ein feiner verteilter Terminplan als
  der Build-Takt bringt keinen Gewinn.
- **Zu enges Fenster.** Passen weniger Minuten ins Fenster als Freigaben
  gewünscht sind, kürzt der Server die Tagesmenge stillschweigend auf die Zahl
  der Minuten; der Rest wandert auf Folgetage. Die Projekteinstellungen warnen
  davor, und die Anwendung zeigt stets die tatsächliche Menge.
- **Rückstau.** Die Suche nach einem freien Platz reicht 90 Tage voraus. Findet
  sie keinen, bleibt der Entwurf offen zur Freigabe und das Protokoll meldet:
  „Automatische Terminierung: kein freier Platz in den nächsten 90 Tagen …".
- Probeläufe (`--dry-run`) schreiben keinen Herzschlag und verfälschen die
  Takt-Schätzung im Systemstatus nicht.

## 3. `cron-healthcheck.php` — Gesundheitscheck der Webseite

Führt den SEO-Audit über den vorhandenen `public/`-Ordner aus und schickt eine
E-Mail, sobald der Bericht Fehler **oder** Warnungen enthält. Bloße Hinweise
lösen keine Benachrichtigung aus.

```cron
0 6 * * *  /usr/bin/php /pfad/backend/cli/cron-healthcheck.php --host=example.com
```

Optionen: `--host=<domain>` (Pflicht), `--mounts=<datei>`, `--dry-run` (führt
den Audit aus, versendet aber keine E-Mail und braucht keine Lizenz).

Der Check **baut nicht selbst** — er prüft den vorhandenen Stand. Fehlt
`public/`, meldet er `AUDIT-NO-BUILD-OUTPUT`. Für einen frischen Stand
`cron-build.php` vorher laufen lassen (getrennte Einträge, siehe unten).

Der Versand läuft über einen eigenen SMTP-Client; Zugang, Absender und Empfänger
stehen in der `[mail]`-Sektion der `hugocms.ini`. Sind Probleme zu melden, aber
`[mail]` fehlt, endet der Lauf mit einem Fehler statt still zu bleiben. Ob der
SMTP-Zugang funktioniert, lässt sich im Systemstatus mit „Prüfen" feststellen,
ohne auf den Ernstfall zu warten.

## Vollständiges Beispiel

Eine Webseite, Automatikmodus aktiv:

```cron
# Alle 15 Minuten prüfen und nur bei fälligen Freigaben bauen
*/15 * * * *  /usr/bin/php /var/www/backend/cli/cron-build.php --quiet

# Nachts drei Seiten verbessern (die Termine vergibt der Automatikmodus)
0 3 * * *     /usr/bin/php /var/www/backend/cli/cron-improve.php --host=example.com --limit=3

# Morgens der Gesundheitscheck, nach dem ersten Build des Tages
30 6 * * *    /usr/bin/php /var/www/backend/cli/cron-healthcheck.php --host=example.com
```

Zwei Webseiten auf derselben Installation — ein Aufruf betrifft immer nur eine
Webseite (kein Lauf über alle Projekte), deshalb je Domain eigene Einträge mit
`--mounts` und (bei den Pro-Skripten) `--host`:

```cron
# Kunde A (kunde-a.example.com)
*/15 * * * *  /usr/bin/php /var/www/backend/cli/cron-build.php       --mounts=/var/www/backend/mounts/a1b2….ini --quiet
0 3 * * *     /usr/bin/php /var/www/backend/cli/cron-improve.php     --mounts=/var/www/backend/mounts/a1b2….ini --host=kunde-a.example.com --limit=3
30 6 * * *    /usr/bin/php /var/www/backend/cli/cron-healthcheck.php --mounts=/var/www/backend/mounts/a1b2….ini --host=kunde-a.example.com

# Kunde B (kunde-b.example.com)
*/15 * * * *  /usr/bin/php /var/www/backend/cli/cron-build.php       --mounts=/var/www/backend/mounts/c3d4….ini --quiet
0 4 * * *     /usr/bin/php /var/www/backend/cli/cron-improve.php     --mounts=/var/www/backend/mounts/c3d4….ini --host=kunde-b.example.com --limit=3
40 6 * * *    /usr/bin/php /var/www/backend/cli/cron-healthcheck.php --mounts=/var/www/backend/mounts/c3d4….ini --host=kunde-b.example.com
```

**Zeilen automatisch erzeugen.** Diese Einträge muss man nicht von Hand
zusammenstellen: Das Skript `bin/crontab-entries.sh` im Release-Repo (neben
`install.sh`/`update.sh`) gibt sie für alle eingerichteten Webseiten fertig aus.
Mount-Pfad, Host (aus dem Kopfkommentar der Mount-Datei) und Lizenzstatus
(`[license] key`) liest es selbst; die Pro-Zeilen erscheinen nur für Webseiten
mit Lizenz, die Läufe werden je Seite zeitlich versetzt. Es ändert die Crontab
nicht, sondern gibt nur aus — prüfen und dann übernehmen:

```sh
bin/crontab-entries.sh              # Zeilen anzeigen
bin/crontab-entries.sh --limit=5    # --limit der Verbesserung setzen (Standard 3)
bin/crontab-entries.sh --php=/usr/bin/php8.2   # PHP-Pfad vorgeben
```

## Vor dem Eintragen prüfen

Jedes Skript einmal von Hand starten — so fallen falsche Pfade und fehlende
Rechte sofort auf, statt still im Cron zu verschwinden:

```sh
/usr/bin/php /pfad/backend/cli/cron-improve.php --dry-run
/usr/bin/php /pfad/backend/cli/cron-healthcheck.php --dry-run
```

Diese beiden Probeläufe verändern nichts: kein API-Aufruf, kein Schreiben, kein
E-Mail-Versand. Sie melden nur, was sie vorfinden — und ob Konfiguration und
Pfade stimmen.

**Der Probelauf braucht weder `--host` noch eine INI-Angabe.** Er ruft nichts
Lizenzpflichtiges auf (die Lizenzprüfung wird übersprungen), deshalb ist
`--host` nur für den echten Lauf Pflicht, nicht für `--dry-run`. Die
`hugocms.ini` ist ohnehin nie ein Argument — es gilt immer die Datei neben dem
Skript (`backend/hugocms.ini`). Und `--mounts` hat eine Vorgabe
(`backend/mounts.ini`), sodass ein Probelauf ohne Argumente die Standard-Webseite
vorschaut.

**Ausnahme Mehrfach-Sites:** Läuft die Installation mit mehreren Webseiten
(`backend/mounts/<hash>.ini` je Domain), zeigt `--dry-run` ohne `--mounts` die
Standard-`mounts.ini` — also womöglich die falsche Seite. Dann dieselbe
Mount-Datei angeben, die auch der echte Cron nutzt, damit der Probelauf den
richtigen Vorrat vorschaut:

```sh
/usr/bin/php /pfad/backend/cli/cron-improve.php --dry-run --mounts=/pfad/backend/mounts/<hash>.ini
```

`cron-build.php` kennt keinen Probelauf; ein Aufruf **baut die Webseite
tatsächlich** und veröffentlicht dabei fällige Freigaben. Das ist derselbe
Vorgang wie ein Klick auf „Veröffentlichen", also normalerweise unbedenklich —
nur wenn `--cleanDestinationDir` konfiguriert ist, sollte man wissen, dass der
Zielordner dabei von allem befreit wird, was Hugo nicht selbst erzeugt.

Nach den ersten echten Läufen zeigt der Systemstatus unter „Cron-Aufgaben", ob
sie ankommen, wie oft sie laufen und ob eine überfällig ist.
