---
title: "Leerzeichen in der URL"
summary: "Die URL enthält ein (kodiertes) Leerzeichen (%20) – unschön und fehleranfällig."
severity: warning
see_also: [url.uppercase, url.non_ascii]
---

## Beschreibung

Die Adresse dieser Seite enthält ein Leerzeichen, das im Link als `%20` erscheint.
Leerzeichen gehören nicht in saubere URLs.

## Warum es wichtig ist

- `%20`-URLs sind schwer lesbar, brechen beim Kopieren leicht und wirken
  unprofessionell.
- Sie deuten meist auf einen Dateinamen oder Slug mit Leerzeichen hin.

## Lösung

Ersetze Leerzeichen durch Bindestriche. Benenne die Inhaltsdatei entsprechend
(`achsvermessung-bmw.md`) oder setze einen sauberen `slug` im Front Matter:

    ---
    slug: "achsvermessung-bmw-3er"
    ---
