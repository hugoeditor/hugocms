---
title: "Alt-Text bei Bild fehlt"
summary: "Ein Bild hat kein alt-Attribut – schlecht für Barrierefreiheit und Bild-SEO."
severity: error
see_also: [img.alt.generic, img.alt.too_long]
---

## Beschreibung

Auf dieser Seite hat mindestens ein `<img>` kein `alt`-Attribut. Der Alt-Text
beschreibt das Bild in Worten – für Menschen, die es nicht sehen können, und für
Suchmaschinen.

## Warum es wichtig ist

- Screenreader lesen den Alt-Text vor; ohne ihn ist das Bild für blinde Nutzer
  bedeutungslos.
- Google nutzt Alt-Texte für die Bildersuche und zum Verständnis der Seite.
- Kann das Bild nicht laden, erscheint der Alt-Text als Ersatz.

## Lösung

Gib jedem inhaltlichen Bild einen kurzen, beschreibenden Alt-Text. In Markdown:

    ![Achsvermessung eines BMW 3er auf der Hebebühne](/img/achsvermessung-bmw.jpg)

Rein dekorative Bilder bekommen ein **leeres** `alt=""` (dann überspringt der
Screenreader sie bewusst).
