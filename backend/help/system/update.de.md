---
title: "Software-Update aus der Oberfläche"
summary: "Administratoren können HugoCMS und das Hugo-Programm im Systemstatus auf den neuesten Stand bringen. Das entspricht bin/update.sh --all auf der Kommandozeile."
see_also: [cron]
---

## Was das Update tut

Die Karte **Software-Update** im Systemstatus führt das ausgelieferte Skript
`bin/update.sh` des Release-Repos aus. Es gibt zwei Knöpfe:

- **Alles aktualisieren** (`update.sh --all`) — holt den neuesten Stand des
  Release-Repos (`git pull`), frischt die App-Kopien aller eingerichteten
  Webseiten auf (`edit/` und `cms-api/` im Publish-Ordner und in `static/`)
  und erneuert das Hugo-Programm, falls es eine neuere Fassung gibt.
- **Nur Hugo erneuern** (`update.sh --hugo`) — tauscht ausschließlich das
  Hugo-Programm unter `bin/hugo/hugo` aus, wenn auf GitHub eine neuere
  Fassung bereitsteht. Das Release-Repo und die Webseiten bleiben unberührt.

**Auf Updates prüfen** fragt vorab nach außen: `git fetch` im Release-Repo und
die GitHub-API für Hugo. Dabei wird nichts verändert.

Während eines Laufs hält HugoCMS die Bausperre der Webseite, damit kein
Hugo-Lauf in den Austausch des Programms gerät. Die Ausgabe des Skripts steht
danach wörtlich unter den Knöpfen — bei einem Fehlschlag ist sie die
Fehlerbeschreibung.

## Voraussetzungen

Das Skript läuft unter dem Benutzer des PHP-Prozesses. Dieser braucht:

- die Freigabe, Programme zu starten (`exec` darf in der `php.ini` nicht unter
  `disable_functions` stehen),
- `bash` und — für den vollen Lauf — `git` im Suchpfad,
- Schreibrecht auf das Release-Repo einschließlich `.git/` und auf die
  Publish-Ordner der Webseiten,
- ausgehenden Zugriff auf GitHub (Release-Repo und Hugo-Download).

Fehlt eine Voraussetzung, nennt die Karte den Grund und deaktiviert den
betroffenen Knopf. Das Update bleibt dann weiterhin per SSH möglich:

    hugocms-release/bin/update.sh --all

Auf Hetzner Webhosting ist der SSH-Benutzer derselbe wie der PHP-Benutzer;
dort funktioniert das Update aus der Oberfläche ohne weitere Einrichtung.

## Nach dem Update

Die Oberfläche selbst wird beim Lauf ausgetauscht. Die Seite danach **neu
laden**, damit Browser und Server denselben Stand nutzen. Die Buildnummer
oben im Systemstatus zeigt den neuen Stand.

## Zurück zu einer bestimmten Hugo-Version

Die Oberfläche installiert immer die neueste Fassung. Soll eine ältere,
bekannte Version zurück, geht das nur per SSH:

    hugocms-release/bin/get-hugo.sh --version 0.164.0

## Siehe auch

- Cron-Aufgaben einrichten (`cron`)
