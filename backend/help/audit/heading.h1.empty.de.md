---
title: "H1-Überschrift ist leer"
summary: "Es gibt eine <h1>, aber ohne Text – sie trägt keine Bedeutung."
severity: warning
see_also: [heading.h1.missing]
---

## Beschreibung

Diese Seite hat eine `<h1>`, die keinen Text enthält (leer oder nur ein Bild/Icon
ohne Alternativtext). Damit geht das wichtige Überschriften-Signal verloren.

## Warum es wichtig ist

- Eine leere H1 ist für Suchmaschinen und Screenreader wertlos.
- Häufig steckt eine leere Titelvariable oder ein reines Logo in der H1.

## Lösung

Fülle die H1 mit einer aussagekräftigen Textüberschrift. Steht dort ein Logo,
gib ihm einen `alt`-Text oder verlagere das Logo aus der H1 heraus und setze eine
echte Textüberschrift ein:

    <h1>{{ .Title | default "Achsvermessung für Ihren BMW 3er" }}</h1>
