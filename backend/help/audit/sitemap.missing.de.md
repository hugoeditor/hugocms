---
title: "Sitemap fehlt"
summary: "Es wurde keine sitemap.xml gefunden – Suchmaschinen finden Seiten dann schwerer."
severity: warning
see_also: [robots.no_sitemap_ref, link.orphan_page]
---

## Beschreibung

Im gebauten Projekt wurde keine `sitemap.xml` gefunden. Die Sitemap listet alle
Seiten auf und hilft Suchmaschinen, sie vollständig zu erfassen.

## Warum es wichtig ist

- Besonders bei vielen oder frisch veröffentlichten Seiten beschleunigt eine
  Sitemap das Auffinden und Indexieren.
- Sie ergänzt (ersetzt aber nicht) die interne Verlinkung.

## Lösung

Hugo erzeugt die Sitemap standardmäßig automatisch unter `/sitemap.xml`. Fehlt
sie, prüfe:

- ob die Ausgabe der Sitemap deaktiviert wurde (`disableKinds` in der
  Hugo-Konfiguration enthält `sitemap`),
- ob eine korrekte `baseURL` gesetzt ist,
- ob der Build vollständig durchgelaufen ist.
