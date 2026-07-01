---
title: "Mehrere Meta-Descriptions"
summary: "Die Seite enthält mehr als ein description-Tag – erlaubt ist genau eines."
severity: warning
see_also: [meta.description.missing]
---

## Beschreibung

Diese Seite hat mehr als ein `<meta name="description">`. Suchmaschinen wissen
dann nicht, welche Beschreibung gilt, und wählen oft die falsche.

## Warum es wichtig ist

- Das Ergebnis ist nicht steuerbar – mal greift die eine, mal die andere.
- Meist ein Layout-Fehler (Description im Baseof und zusätzlich in einem Partial
  oder Theme gesetzt).

## Lösung

Gib die Description nur an **einer** Stelle aus (`partials/head.html`). Ein
zweites description-Tag aus Theme oder Partial entfernen.
