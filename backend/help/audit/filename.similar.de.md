---
title: "URL-Slugs sehr ähnlich"
summary: "Zwei Slugs unterscheiden sich nur um wenige Zeichen – möglicher Tippfehler oder Verwechslungsgefahr."
severity: warning
see_also: [filename.near_duplicate, filename.copy_suspect, url.underscore]
---

## Beschreibung

Der Slug dieser Seite gleicht dem einer anderen bis auf ein oder zwei Zeichen
(`kontakt` gegenüber `kontackt`, `produkt-uebersicht` gegenüber
`produkte-uebersicht`). Das deutet auf einen Tippfehler oder zwei sehr
schwer auseinanderzuhaltende Adressen hin.

## Warum es wichtig ist

- Ist eine der beiden Adressen ein Tippfehler, wirkt sie unprofessionell und
  lässt sich schlecht merken oder weitergeben.
- Sehr ähnliche URLs verwechseln Besucher leicht und erschweren die Pflege
  interner Links.
- Sind beide Seiten inhaltlich verwandt, droht zusätzlich Duplicate Content.

## Lösung

Prüfe, ob es sich um einen Fehler oder um zwei berechtigte Seiten handelt:

- Bei einem Tippfehler den Slug korrigieren und die falsche Adresse per
  Weiterleitung abfangen.
- Sind beide Seiten gewollt, wähle klar unterscheidbare, sprechende Slugs, damit
  Nutzer und Suchmaschinen sie sicher zuordnen.

Nummerierte Serien (`teil-1`, `teil-2`) werden bewusst nicht gemeldet.
