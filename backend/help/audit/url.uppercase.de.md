---
title: "Großbuchstaben in der URL"
summary: "Die URL enthält Großbuchstaben – das führt leicht zu Dubletten und toten Links."
severity: hint
see_also: [url.underscore, url.space]
---

## Beschreibung

Die Adresse dieser Seite enthält Großbuchstaben. URLs sind auf den meisten
Servern zwischen Groß- und Kleinschreibung unterschiedlich – `/Seite/` und
`/seite/` gelten dann als **zwei** Adressen.

## Warum es wichtig ist

- Groß-/Kleinvarianten derselben Seite wirken als Duplicate Content.
- Manuell getippte oder verlinkte Adressen führen leicht ins Leere, wenn die
  Schreibweise nicht exakt stimmt.

## Lösung

Verwende durchgängig **Kleinbuchstaben** in URLs. In Hugo entstehen Slugs
standardmäßig klein; achte bei manuell gesetzten `url:`/`slug:`-Werten im Front
Matter darauf. Für bereits verbreitete Großschreib-Adressen ggf. einen Alias auf
die Kleinschreibvariante setzen.
