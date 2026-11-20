---
title: "Sitemap ist kein wohlgeformtes XML"
summary: "Die Sitemap lässt sich nicht als XML lesen – Suchmaschinen verwerfen sie vollständig."
severity: error
see_also: [sitemap.xml.root_invalid, sitemap.xml.loc_missing, sitemap.missing]
---

## Beschreibung

Die Sitemap im gebauten Projekt ist leer oder enthält einen XML-Syntaxfehler,
etwa ein nicht geschlossenes Element oder ein nicht maskiertes `&`. Die Meldung
nennt die Zeile des ersten Fehlers. Bei einem Sitemap-Index werden auch die
darin genannten Teil-Sitemaps geprüft; der Fund zeigt die betroffene Datei.

## Warum es wichtig ist

- Ein XML-Parser bricht beim ersten Syntaxfehler ab. Suchmaschinen werten die
  Datei dann gar nicht aus – auch nicht die Einträge vor der Fehlerstelle.
- Der SEO-Check kann aus einer solchen Sitemap keine Seiten lesen. Seiten, die
  nur über die Sitemap erreichbar wären, erscheinen deshalb zusätzlich als
  „weder verzeichnet noch verlinkt".

## Lösung

Hugo erzeugt die Sitemap selbst und maskiert Sonderzeichen korrekt. Ein Fehler
entsteht in der Regel an einer dieser Stellen:

- ein eigenes Template `layouts/sitemap.xml` oder `layouts/_default/sitemap.xml`
  gibt Werte ohne Maskierung aus oder schließt ein Element nicht,
- der Build wurde abgebrochen und hat eine unvollständige Datei hinterlassen,
- die Datei wurde nach dem Build von Hand oder durch ein Skript verändert.

Öffne die Datei an der genannten Zeile, behebe die Ursache im Template und baue
die Webseite neu.
