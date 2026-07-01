---
title: "Leerer Link (href)"
summary: "Ein Link hat ein leeres oder nur „#\"-Ziel – er führt nirgendwohin."
severity: hint
see_also: [link.internal.broken]
---

## Beschreibung

Auf dieser Seite gibt es einen `<a>`-Link mit leerem `href` (oder nur `#`). Ein
Klick lädt die Seite neu oder springt an den Anfang, statt zu einem Ziel zu
führen.

## Warum es wichtig ist

- Leere Links verwirren Nutzer und Screenreader (sie werden als anklickbar
  angekündigt, tun aber nichts Sinnvolles).
- Oft ein Platzhalter, der beim Bearbeiten vergessen wurde.

## Lösung

- Soll der Link irgendwohin führen: das richtige Ziel eintragen.
- Ist es ein Bedienelement (öffnet ein Menü o. Ä.) ohne echtes Ziel: einen
  `<button>` statt eines Links verwenden.
- Wird der Link nicht gebraucht: entfernen.
