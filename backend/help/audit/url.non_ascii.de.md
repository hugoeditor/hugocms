---
title: "Sonderzeichen in der URL"
summary: "Die URL enthält Umlaute oder andere Nicht-ASCII-Zeichen – besser vermeiden."
severity: hint
see_also: [url.space, url.uppercase]
---

## Beschreibung

Die Adresse dieser Seite enthält Nicht-ASCII-Zeichen (z. B. Umlaute wie `ä`, `ö`,
`ü` oder `ß`). Im Link erscheinen sie als kryptische `%`-Codes
(`%C3%A4` für `ä`).

## Warum es wichtig ist

- Prozentkodierte URLs sind schwer lesbar und brechen beim Kopieren leicht.
- Verschiedene Systeme kodieren Sonderzeichen unterschiedlich, was zu Dubletten
  oder toten Links führen kann.

## Lösung

Verwende in URLs nur ASCII: schreibe Umlaute aus (`ae`, `oe`, `ue`, `ss`) oder
lasse sie weg. In Hugo geschieht das beim Slug meist automatisch; bei manuell
gesetzten `slug`/`url`-Werten im Front Matter darauf achten:

    slug: "achsvermessung-fuer-bmw"
