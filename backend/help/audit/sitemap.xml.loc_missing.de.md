---
title: "Sitemap-Einträge ohne Adresse"
summary: "Einträge der Sitemap enthalten kein oder ein leeres <loc>-Element."
severity: warning
see_also: [sitemap.xml.malformed, sitemap.xml.root_invalid]
---

## Beschreibung

Jeder Eintrag einer Sitemap (`<url>`, im Sitemap-Index `<sitemap>`) muss genau
ein `<loc>`-Element mit der vollständigen Adresse enthalten. Die Meldung nennt
die Zahl der Einträge, bei denen `<loc>` fehlt oder leer ist.

## Warum es wichtig ist

- `<loc>` ist das einzige Pflichtfeld eines Eintrags. Ohne Adresse ist der
  Eintrag wertlos.
- Manche Suchmaschinen weisen eine Sitemap mit ungültigen Einträgen insgesamt
  zurück.

## Lösung

- Prüfe, ob in der Hugo-Konfiguration eine `baseURL` gesetzt ist.
- Prüfe ein eigenes Sitemap-Template: `<loc>` sollte `{{ .Permalink }}`
  ausgeben. Seiten ohne eigene Ausgabe (etwa mit `build.render: never`) haben
  keinen Permalink und gehören nicht in die Sitemap.
