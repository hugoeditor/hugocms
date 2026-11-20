---
title: "Sitemap mit falschem Wurzelelement"
summary: "Die Datei ist gültiges XML, aber keine Sitemap nach dem Sitemap-Protokoll."
severity: error
see_also: [sitemap.xml.malformed, sitemap.xml.loc_missing]
---

## Beschreibung

Die Datei lässt sich als XML lesen, ihr Wurzelelement ist aber weder `<urlset>`
noch `<sitemapindex>` – oder es trägt nicht den Namensraum des
Sitemap-Protokolls:

```xml
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
```

## Warum es wichtig ist

- Suchmaschinen erkennen die Datei nur an Wurzelelement und Namensraum als
  Sitemap. Fehlt eines von beiden, wird sie abgelehnt.
- Häufig liegt unter dem Namen `sitemap.xml` eine andere Datei, etwa eine
  HTML-Fehlerseite oder ein RSS-Feed.

## Lösung

- Prüfe ein eigenes Sitemap-Template (`layouts/sitemap.xml`,
  `layouts/sitemapindex.xml`): Das Wurzelelement muss das Attribut
  `xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"` tragen.
- Prüfe, ob eine Datei unter `static/sitemap.xml` die von Hugo erzeugte Sitemap
  überschreibt.
- Entferne das eigene Template, wenn es nicht benötigt wird – die eingebaute
  Vorlage von Hugo ist korrekt.
