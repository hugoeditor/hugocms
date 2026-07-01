---
title: "Mehrere <title>-Elemente"
summary: "Die Seite enthält mehr als ein <title> – erlaubt ist genau eines."
severity: warning
see_also: [title.missing]
---

## Beschreibung

Diese Seite hat mehr als ein `<title>`-Element im `<head>`. Laut HTML-Standard
ist genau eines vorgesehen. Suchmaschinen und Browser verwenden dann meist das
erste und ignorieren den Rest – oft nicht das gewünschte.

## Warum es wichtig ist

- Welcher Titel „gewinnt", ist nicht zuverlässig steuerbar.
- Es deutet fast immer auf einen Fehler im Layout hin (z. B. Titel doppelt im
  Baseof und in einem Partial gesetzt).

## Lösung

Stelle sicher, dass der Titel nur an **einer** Stelle ausgegeben wird – üblich
in `layouts/_default/baseof.html` bzw. `partials/head.html`. Ein zweites
`<title>` in einem eingebundenen Partial oder Theme entfernen.
