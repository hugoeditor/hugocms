---
title: "robots.txt ohne Sitemap-Verweis"
summary: "Die robots.txt nennt die Sitemap nicht – ein einfacher, hilfreicher Hinweis fehlt."
severity: hint
see_also: [robots.missing, sitemap.missing]
---

## Beschreibung

Es gibt eine `robots.txt`, sie enthält aber keine `Sitemap:`-Zeile. Damit fehlt
Suchmaschinen der direkte Hinweis auf die Sitemap.

## Warum es wichtig ist

- Der Sitemap-Verweis in der robots.txt ist der einfachste Weg, Suchmaschinen die
  vollständige Seitenliste zu zeigen.
- Ohne ihn müssen sie die Sitemap erraten oder über andere Wege finden.

## Lösung

Ergänze in der `robots.txt` die absolute Adresse der Sitemap:

    Sitemap: https://example.com/sitemap.xml

Nutzt du Hugos automatische robots.txt, füge die Zeile in das entsprechende
Template (`layouts/robots.txt`) ein.
