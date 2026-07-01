---
title: "Pflichtfeld im Front Matter fehlt"
summary: "Einer Hugo-Inhaltsdatei fehlt ein erforderliches Front-Matter-Feld (z. B. title)."
severity: error
see_also: [title.missing, hugo.markdown.dead_link]
---

## Beschreibung

Einer Inhaltsdatei (`content/**/*.md`) fehlt ein Pflichtfeld im Front Matter –
typischerweise `title` oder `date`. Das Front Matter steht am Dateianfang zwischen
zwei `---`-Zeilen (bzw. `+++` bei TOML).

## Warum es wichtig ist

- Ohne `title` erzeugt Hugo eine Seite ohne sinnvolle Überschrift und ohne
  brauchbaren `<title>`.
- Fehlende Felder führen oft zu leeren Stellen im Layout oder Build-Warnungen.

## Lösung

Ergänze die fehlenden Felder am Dateianfang, z. B.:

    ---
    title: "Achsvermessung BMW 3er"
    date: 2026-01-15
    ---

Lege in `archetypes/` sinnvolle Vorlagen an, damit neue Seiten die Pflichtfelder
von Anfang an mitbringen.
