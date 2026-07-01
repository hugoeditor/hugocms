---
title: "Mehrere H1-Überschriften"
summary: "Die Seite hat mehr als eine <h1> – üblich und am klarsten ist genau eine."
severity: warning
see_also: [heading.h1.missing, heading.hierarchy_jump]
---

## Beschreibung

Diese Seite enthält mehr als eine `<h1>`. Auch wenn HTML5 mehrere H1 technisch
erlaubt, ist genau eine Hauptüberschrift die klarste und am besten verstandene
Struktur.

## Warum es wichtig ist

- Eine einzige H1 macht das Hauptthema eindeutig – für Nutzer, Suchmaschinen und
  Screenreader.
- Mehrere H1 entstehen oft versehentlich (Layout-H1 plus `#` im Markdown).

## Lösung

Lege eine H1 als Hauptüberschrift fest und stufe die übrigen zu `<h2>`/`<h3>`
herab. Prüfe, ob Layout **und** Inhalt jeweils eine H1 erzeugen, und entferne
die doppelte.
