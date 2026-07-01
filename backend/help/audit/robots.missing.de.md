---
title: "robots.txt fehlt"
summary: "Es wurde keine robots.txt gefunden – empfehlenswert zur Steuerung des Crawlings."
severity: hint
see_also: [robots.no_sitemap_ref, sitemap.missing]
---

## Beschreibung

Im gebauten Projekt wurde keine `robots.txt` gefunden. Diese Datei im
Wurzelverzeichnis sagt Suchmaschinen, welche Bereiche sie crawlen dürfen, und
verweist üblicherweise auf die Sitemap.

## Warum es wichtig ist

- Ohne `robots.txt` crawlen Suchmaschinen zwar trotzdem, aber du verschenkst die
  Steuerungsmöglichkeit und den Sitemap-Hinweis.
- Für kleine Seiten unkritisch, für größere empfehlenswert.

## Lösung

Aktiviere in Hugo die robots.txt-Ausgabe (`enableRobotsTXT = true` in der
Konfiguration) oder lege eine eigene `static/robots.txt` an, z. B.:

    User-agent: *
    Allow: /
    Sitemap: https://example.com/sitemap.xml
