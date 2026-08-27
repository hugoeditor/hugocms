---
title: "Cron-Aufgaben einrichten"
summary: "HugoCMS erledigt drei Aufgaben zeitgesteuert über die Crontab des Servers: bauen, verbessern und den Gesundheitscheck. Diese Seite erklärt, wie sie eingetragen werden."
see_also: []
---

## Was die Cron-Aufgaben tun

HugoCMS führt drei Skripte über die Kommandozeile aus. Sie liegen im
Installationsverzeichnis unter `backend/cli/` und lassen sich **nur dort**
starten — nicht über den Browser.

- **Webseite bauen** (`cron-build.php`) — veröffentlicht fällige terminierte
  Freigaben und baut die Webseite mit Hugo. Gebaut wird nur, wenn tatsächlich
  eine Freigabe fällig war; sonst wird der Lauf übersprungen (mit `--force` immer
  bauen). Keine Pro-Lizenz nötig. Optional (Projekteinstellungen, Pro) sichert
  der Lauf danach einen Versionsstand — siehe unten.
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

    # Alle 15 Minuten prüfen und nur bei fälligen Freigaben bauen
    */15 * * * *  /usr/bin/php /pfad/backend/cli/cron-build.php --quiet

    # Nachts drei Seiten verbessern
    0 3 * * *     /usr/bin/php /pfad/backend/cli/cron-improve.php --host=example.com --limit=3

    # Morgens der Gesundheitscheck
    30 6 * * *    /usr/bin/php /pfad/backend/cli/cron-healthcheck.php --host=example.com

Ein Aufruf betrifft immer nur **eine** Webseite — es gibt keinen Lauf über alle
Projekte hinweg. Bei mehreren Webseiten bekommt daher jede eigene Einträge mit
ihrer Mount-Datei (`--mounts=…/mounts/<hash>.ini`); die beiden Pro-Skripte
zusätzlich mit ihrer jeweils lizenzierten Domain (`--host`). Beispiel für zwei
Webseiten:

    # Webseite 1 (eins.example.com)
    */15 * * * *  /usr/bin/php /pfad/backend/cli/cron-build.php       --mounts=/pfad/backend/mounts/<hash1>.ini --quiet
    0 3 * * *     /usr/bin/php /pfad/backend/cli/cron-improve.php     --mounts=/pfad/backend/mounts/<hash1>.ini --host=eins.example.com --limit=3
    30 6 * * *    /usr/bin/php /pfad/backend/cli/cron-healthcheck.php --mounts=/pfad/backend/mounts/<hash1>.ini --host=eins.example.com

    # Webseite 2 (zwei.example.com)
    */15 * * * *  /usr/bin/php /pfad/backend/cli/cron-build.php       --mounts=/pfad/backend/mounts/<hash2>.ini --quiet
    0 3 * * *     /usr/bin/php /pfad/backend/cli/cron-improve.php     --mounts=/pfad/backend/mounts/<hash2>.ini --host=zwei.example.com --limit=3
    30 6 * * *    /usr/bin/php /pfad/backend/cli/cron-healthcheck.php --mounts=/pfad/backend/mounts/<hash2>.ini --host=zwei.example.com

Diese Zeilen muss man nicht von Hand zusammenstellen: Das Skript
`bin/crontab-entries.sh` im Release-Verzeichnis gibt sie für alle eingerichteten
Webseiten fertig aus — Mount-Pfad, Host und Lizenzstatus liest es aus den
Mount-Dateien. Es ändert die Crontab nicht, sondern gibt nur die Zeilen aus;
prüfen und dann übernehmen (`crontab -e`).

Umgekehrt beantwortet `bin/crontab-entries.sh --status` die Frage, was bereits
eingerichtet ist: Es liest die vorhandene Crontab und zeigt je Webseite, welche
der drei Aufgaben eingetragen ist und in welchem Takt — dazu Einträge, die ins
Leere zeigen (Webseite entfernt), doppelt vorhandene und solche, die eine
Pro-Aufgabe ohne Lizenz starten. Verwaltet der Hoster die Crontab über ein
Webpanel, lässt sich der Export übergeben: `--status=cron.txt` oder über die
Standardeingabe (`--status=-`). Geändert wird auch dabei nichts.

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

## Automatischer Versionsstand beim Build (Pro)

Steht das Quellverzeichnis unter Git-Versionierung, kann der Cron-Build
automatisch Versionsstände sichern. Das schaltet man in den
**Projekteinstellungen** unter „Versionsstand automatisch sichern" ein; ein
Schalter, zwei Beschreibungen (jeweils mit einem sinnvollen Vorschlag als
Vorgabe, an die das Datum angehängt wird):

- **Vor dem Build** sichert der Cron offene, noch unversionierte Änderungen im
  Quellverzeichnis mit der ersten Beschreibung — aber nur, wenn welche
  vorliegen. Diese Prüfung läuft bei jedem Cron-Build-Lauf, auch ohne fällige
  Freigabe.
- **Nach der Veröffentlichung** fälliger Freigaben folgt der zweite
  Versionsstand mit der Beschreibung für die Veröffentlichung.

Der Versionsstand der Veröffentlichung bekommt außerdem die nächste freie
**Versionsnummer** (1, 2, 3 …) und einen Abschnitt im Änderungsprotokoll
(`changelog.md`). Die Vorab-Sicherung bleibt ohne Nummer — sie ist eine
Zwischenablage, kein Ausgabestand. Das Wort vor der Nummer („Ausgabe 12")
steht in den Projekteinstellungen; leer lassen schreibt nur die Nummer.

Die Trennung sorgt dafür, dass der Versionsstand der Veröffentlichung nur die
publizierten Dateien enthält und verstreute Direktbearbeitungen nicht mit
hineinrutschen. Übernommen werden — wie beim Sichern von Hand — alle offenen
Änderungen im Quellverzeichnis. Setzt eine gültige Pro-Lizenz voraus; scheitert
das Sichern, bricht der Build nicht ab, sondern vermerkt es nur im Protokoll.

## Die Besonderheit: automatische Verbesserung

Der Verbesserer legt sein Ergebnis normalerweise als **Entwurf zur Freigabe** ab
— jemand sieht ihn durch und gibt ihn frei. Im **Automatikmodus** terminiert er
jeden Entwurf stattdessen selbst, zu einem zufälligen Zeitpunkt innerhalb eines
Tagesfensters. Verbesserte Seiten gehen dann über den Tag verteilt live statt
alle auf einmal.

Eingeschaltet wird der Automatikmodus in der Anwendung: **SEO-Check →
Inhaltsprüfung → Zu verbessern**, dort der Schalter *Automatisch terminieren*.
Zeitfenster (z. B. 07:00 bis 16:00 Uhr), Menge pro Tag und – auf Wunsch – das
Ausnehmen von Samstag und Sonntag stehen in den **Projekteinstellungen**.

So verteilt der Automatikmodus die Termine: Das Fenster wird in so viele
Abschnitte geteilt, wie Seiten pro Tag erlaubt sind. Jede Seite bekommt einen
eigenen Abschnitt und darin eine zufällige Uhrzeit — so liegen zwei Freigaben
nie dicht beieinander. Bei 07:00–16:00 und drei Seiten also eine am Vormittag,
eine mittags, eine am Nachmittag.

Zu beachten:

- **Der Build-Takt bestimmt die Genauigkeit.** Eine für 08:22 Uhr vorgemerkte
  Freigabe geht erst beim nächsten Build **nach** diesem Zeitpunkt online. Ohne
  regelmäßigen `cron-build.php` passiert zum Termin nichts. Der Build läuft nur,
  wenn eine Freigabe fällig war — wer zusätzlich über Hugos Front-Matter-
  `publishDate` terminiert, ruft `cron-build.php --force` auf, damit auch das
  regelmäßig sichtbar wird.
- **Die Uhrzeiten sind Serverzeit**, nicht die des eigenen Browsers. Das gilt
  auch für die Wochenend-Ausnahme: Ob ein Tag als Samstag oder Sonntag zählt,
  richtet sich nach der Zeitzone des Servers.
- **Wochenenden ausnehmen** (Vorgabe an): An Samstagen und Sonntagen wird nichts
  terminiert; die Freigaben rücken auf den nächsten Werktag. In den
  Projekteinstellungen abschaltbar, wenn auch am Wochenende veröffentlicht
  werden soll.
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

Der Probelauf braucht weder `--host` noch eine INI-Angabe: Er ruft nichts
Lizenzpflichtiges auf, deshalb ist `--host` nur für den echten Lauf Pflicht. Die
`hugocms.ini` ist ohnehin nie ein Argument (es gilt immer die Datei neben dem
Skript), und `--mounts` hat die Vorgabe `backend/mounts.ini`.

Nur bei **mehreren Webseiten** sollte auch der Probelauf die passende Mount-Datei
bekommen — dieselbe, die der echte Cron nutzt (`--mounts=…/mounts/<hash>.ini`) —,
damit er den richtigen Vorrat vorschaut.

Danach zeigt der Systemstatus unter **Cron-Aufgaben**, ob die echten Läufe
ankommen. Eine ausführliche Fassung mit allen Optionen liegt als `README.md`
im Verzeichnis `backend/cli/`.
