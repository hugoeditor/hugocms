---
title: "Mehrere Canonical-Links"
summary: "Die Seite hat mehr als ein rel=canonical – Suchmaschinen ignorieren dann meist alle."
severity: error
see_also: [canonical.missing]
---

## Beschreibung

Diese Seite enthält mehr als ein `<link rel="canonical">`. Bei widersprüchlichen
Angaben ignoriert Google die Canonical-Angabe in der Regel komplett.

## Warum es wichtig ist

- Der Zweck des Canonicals – Eindeutigkeit – wird ins Gegenteil verkehrt.
- Fast immer ein Layout-Fehler (Canonical im Baseof und zusätzlich in einem
  Partial oder Theme gesetzt).

## Lösung

Gib den Canonical nur an **einer** Stelle aus (`partials/head.html`). Prüfe
Theme und eigene Partials auf ein zweites `rel="canonical"` und entferne es.
