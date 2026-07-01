---
title: "Open-Graph-Beschreibung fehlt"
summary: "Ohne og:description fehlt beim Teilen der erklärende Text unter der Überschrift."
severity: warning
see_also: [social.og.title.missing, social.og.image.missing]
---

## Beschreibung

Der Seite fehlt `og:description`. Dieses Tag liefert den kurzen Beschreibungstext
in der Vorschau, wenn die Seite geteilt wird.

## Warum es wichtig ist

- Ohne Beschreibung wirkt die geteilte Vorschau unvollständig und weniger
  einladend.
- Eine gute Beschreibung erhöht die Klick- und Teilrate.

## Lösung

Setze im `<head>` eine Open-Graph-Beschreibung, in Hugo oft aus der
Seiten-Description:

    <meta property="og:description" content="{{ .Description }}">
