---
title: "Cron-Aufgaben einrichten"
summary: "HugoCMS erledigt drei Aufgaben zeitgesteuert über die Crontab des Servers: bauen, verbessern und den Gesundheitscheck. Diese Seite erklärt, wie sie eingetragen werden."
see_also: []
---

## Was die Cron-Aufgaben tun

HugoCMS führt drei Skripte über die Kommandozeile aus. Sie liegen im
Installationsverzeichnis unter `backend/cli/` und lassen sich **nur dort**
starten — nicht über den Browser.

- **Webseite bauen** (`cron-build.php`) — baut die Webseite mit Hugo und
  veröffentlicht dabei fällige terminierte Freigaben. Keine Pro-Lizenz nötig.
- **Inhalte verbessern** (`cron-improve.php`) — lässt die KI geprüfte Seiten
  überarbeiten. Pro-Funktion.
- **Gesundheitscheck** (`cron-healthcheck.php`) — prüft die veröffentlichte
  Webseite und meldet Probleme per E-Mail. Pro-Funktion.

Der Abschnitt **Cron-Aufgaben** im Systemstatus zeigt, wann jede Aufgabe zuletzt
lief, in welchem Takt und ob eine überfällig ist. Erscheint dort „Nie gelaufen",
ist der zugehörige Crontab-Eintrag vermutlich noch nicht gesetzt.

## Eintragen in die Crontab

Die Skripte werden über die Crontab des Servers zeitgesteuert. Wichtig dabei:

- **Voller PHP-Pfad.** In der Crontab den vollständigen Pfad zum PHP-Programm
  angeben (z. B. `/usr/bin/php`); `php` allein steht dort meist nicht im
  Suchpfad.
- **`--host` bei den Pro-Aufgaben.** Die Pro-Lizenz ist an die Domain gebunden.
  Auf der Kommandozeile gibt es keinen Domainnamen, deshalb brauchen die beiden
  Pro-Skripte `--host=example.com` mit genau der lizenzierten Domain.
- **`--mounts` bei mehreren Webseiten.** Betreibt eine Installation mehrere
  Webseiten, bekommt jede einen eigenen Crontab-Eintrag mit ihrer
  Mount-Konfiguration.

Ein übliches Beispiel für eine Webseite:

    # Alle 15 Minuten bauen (veröffentlicht zugleich fällige Freigaben)
    */15 * * * *  /usr/bin/php /pfad/backend/cli/cron-build.php --quiet

    # Nachts drei Seiten verbessern
    0 3 * * *     /usr/bin/php /pfad/backend/cli/cron-improve.php --host=example.com --limit=3

    # Morgens der Gesundheitscheck
    30 6 * * *    /usr/bin/php /pfad/backend/cli/cron-healthcheck.php --host=example.com

## Aufgaben pausieren

Jede der drei Aufgaben lässt sich pro Webseite pausieren, ohne die Crontab des
Hosters anzufassen. Eingestellt wird das in den **Projekteinstellungen**; der
Systemstatus zeigt zu jeder Aufgabe den aktuellen Zustand und führt mit einem
Knopf direkt dorthin. Ist eine Aufgabe pausiert, prüft ihr Skript das beim Start
und tut nichts; der Crontab-Eintrag bleibt bestehen und greift wieder, sobald
die Pause aufgehoben wird.

Ist der **Build** pausiert, gehen terminierte Freigaben nicht live — die
Freigabe-Warteschlange weist darauf hin. Ist der **Verbesserer** pausiert, wird
die Liste „zu verbessern" nicht abgearbeitet.

## Die Besonderheit: automatische Verbesserung

Der Verbesserer legt sein Ergebnis normalerweise als **Entwurf zur Freigabe** ab
— jemand sieht ihn durch und gibt ihn frei. Im **Automatikmodus** terminiert er
jeden Entwurf stattdessen selbst, zu einem zufälligen Zeitpunkt innerhalb eines
Tagesfensters. Verbesserte Seiten gehen dann über den Tag verteilt live statt
alle auf einmal.

Eingeschaltet wird der Automatikmodus in der Anwendung: **SEO-Check →
Inhaltsprüfung → Zu verbessern**, dort der Schalter *Automatisch terminieren*.
Zeitfenster (z. B. 07:00 bis 16:00 Uhr) und Menge pro Tag stehen in den
**Projekteinstellungen**.

So verteilt der Automatikmodus die Termine: Das Fenster wird in so viele
Abschnitte geteilt, wie Seiten pro Tag erlaubt sind. Jede Seite bekommt einen
eigenen Abschnitt und darin eine zufällige Uhrzeit — so liegen zwei Freigaben
nie dicht beieinander. Bei 07:00–16:00 und drei Seiten also eine am Vormittag,
eine mittags, eine am Nachmittag.

Zu beachten:

- **Der Build-Takt bestimmt die Genauigkeit.** Eine für 08:22 Uhr vorgemerkte
  Freigabe geht erst beim nächsten Build **nach** diesem Zeitpunkt online. Ohne
  regelmäßigen `cron-build.php` passiert zum Termin nichts.
- **Die Uhrzeiten sind Serverzeit**, nicht die des eigenen Browsers.
- **Zu enges Fenster.** Passen weniger Minuten ins Fenster als Freigaben
  gewünscht sind, wird die Tagesmenge stillschweigend gekürzt; der Rest wandert
  auf die Folgetage. Die Projekteinstellungen warnen davor.
- **`--limit` und die Tagesmenge sind zwei verschiedene Dinge.** `--limit`
  bestimmt, wie viele Seiten ein Lauf bearbeitet (und damit die Kosten des
  KI-Dienstes); die Tagesmenge, wie viele davon pro Tag live gehen.

## Vor dem Eintragen prüfen

Die beiden Pro-Skripte lassen sich gefahrlos zur Probe starten — sie verändern
dabei nichts:

    /usr/bin/php /pfad/backend/cli/cron-improve.php --dry-run
    /usr/bin/php /pfad/backend/cli/cron-healthcheck.php --dry-run

Danach zeigt der Systemstatus unter **Cron-Aufgaben**, ob die echten Läufe
ankommen. Eine ausführliche Fassung mit allen Optionen liegt als `README.md`
im Verzeichnis `backend/cli/`.
