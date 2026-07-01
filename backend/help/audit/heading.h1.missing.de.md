---
title: "H1-Überschrift fehlt"
summary: "Die Seite hat keine <h1> – die zentrale inhaltliche Überschrift fehlt."
severity: error
see_also: [heading.h1.multiple, heading.hierarchy_jump]
---

## Beschreibung

Diese Seite enthält keine `<h1>`. Die H1 ist die sichtbare Hauptüberschrift und
sagt Nutzern wie Suchmaschinen in einem Satz, worum es auf der Seite geht.

## Warum es wichtig ist

- Die H1 ist nach dem `<title>` ein wichtiges thematisches Signal.
- Screenreader nutzen die Überschriften-Struktur zur Orientierung.

## Lösung

Sorge dafür, dass jede Seite genau eine `<h1>` hat. In Hugo kommt sie meist aus
der Inhaltsüberschrift oder dem Layout:

    <h1>{{ .Title }}</h1>

Bei Markdown-Inhalten wird die erste `#`-Überschrift zur H1 – achte darauf, dass
sie nicht doppelt (einmal im Layout, einmal im Inhalt) entsteht.
