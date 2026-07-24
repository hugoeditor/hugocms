---
title: "Seite nicht auffindbar (weder verlinkt noch in der Sitemap)"
summary: "Diese Seite steht nicht in der Sitemap und ist von der Startseite aus über keinen Linkpfad erreichbar – für Suchmaschinen praktisch unsichtbar."
severity: error
see_also: [link.orphan_page, sitemap.missing, link.internal.broken]
---

## Beschreibung

Der Audit bestimmt, welche Seiten erreichbar sind: die Startseite, alle Einträge
der Sitemap und alles, was von dort aus über interne Links erreicht wird
(Schritt für Schritt weiterverfolgt). Diese HTML-Seite gehört zu keiner dieser
Gruppen – sie steht weder in der Sitemap noch führt ein Linkpfad von der
Startseite zu ihr.

Damit ist sie nur erreichbar, wer ihre genaue Adresse bereits kennt.

## Unterschied zur verwaisten Seite

- **Verwaiste Seite** (`link.orphan_page`, Hinweis): Auf die Seite verweist kein
  interner Link, sie steht aber in der Sitemap – Suchmaschinen finden sie also
  trotzdem.
- **Nicht auffindbar** (diese Regel, Fehler): Beides fehlt. Die Seite ist auf
  keinem üblichen Weg zu entdecken.

## Warum es wichtig ist

- Suchmaschinen erfassen Seiten über die Sitemap und über Links. Fehlt beides,
  wird die Seite in der Regel nicht indexiert und erscheint nicht in den
  Ergebnissen.
- Meist ist eine solche Seite ein Versehen: eine vergessene Verlinkung, eine aus
  der Navigation gefallene Seite oder eine unvollständige Sitemap.

## Lösung

Je nachdem, ob die Seite öffentlich sein soll:

- **Ja:** Verlinke sie dort, wo sie thematisch hingehört (Navigation,
  Übersichts- oder Kategorieseite, verwandte Beiträge), und sorge dafür, dass
  sie in der Sitemap steht. In Hugo entsteht die Sitemap automatisch; eine Seite
  fehlt dort meist, weil im Front Matter `sitemap` ausgeschlossen oder
  `private`/`draft` gesetzt ist.
- **Nein:** Ist die Seite gar nicht für Besucher gedacht (Testseite, internes
  Hilfsdokument), gehört sie nicht in den veröffentlichten `public/`-Ordner –
  oder du nimmst sie über die Ausschlüsse des SEO-Berichts von der Prüfung aus
  (Projekteinstellungen bzw. `[seo_report]`).

Fehlt die Sitemap ganz, meldet der Audit stattdessen `sitemap.missing`; dann
gelten alle Seiten als Wurzeln und diese Regel greift nicht.
