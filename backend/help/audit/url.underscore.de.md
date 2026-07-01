---
title: "Unterstriche in der URL"
summary: "Die URL nutzt Unterstriche statt Bindestriche zur Worttrennung."
severity: hint
see_also: [url.uppercase, url.space]
---

## Beschreibung

Die Adresse dieser Seite trennt Wörter mit Unterstrichen (`_`). Als Worttrenner
in URLs ist der **Bindestrich** (`-`) der etablierte Standard.

## Warum es wichtig ist

- Google behandelt den Bindestrich als Worttrenner, den Unterstrich hingegen als
  Verbinder (`wort_wort` gilt als ein Wort).
- Bindestrich-URLs sind lesbarer und konsistenter.

## Lösung

Verwende Bindestriche zur Worttrennung: `/achsvermessung-bmw-3er/` statt
`/achsvermessung_bmw_3er/`. In Hugo bestimmt der Dateiname bzw. der `slug` die
URL – dort Bindestriche nutzen. Bei bereits verbreiteten Unterstrich-Adressen
ggf. einen Alias setzen.
