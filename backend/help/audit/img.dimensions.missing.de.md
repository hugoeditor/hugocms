---
title: "Bildabmessungen fehlen"
summary: "Ein Bild hat kein width/height – das verursacht Layout-Sprünge beim Laden."
severity: hint
see_also: [img.alt.missing]
---

## Beschreibung

Einem Bild fehlen die Attribute `width` und `height`. Ohne sie kennt der Browser
die Größe erst, wenn das Bild geladen ist, und der Inhalt „springt" nach.

## Warum es wichtig ist

- Layout-Sprünge (Cumulative Layout Shift, CLS) sind ein Google-Rankingfaktor
  (Core Web Vitals) und stören die Nutzung.
- Mit bekannten Abmessungen reserviert der Browser den Platz von Anfang an.

## Lösung

Gib bei `<img>` `width` und `height` an (die Anzeige skalierst du per CSS):

    <img src="/img/achsvermessung.jpg" width="1200" height="800" alt="…">

In Hugo liefern Image-Resources die Maße automatisch:

    {{ $img := resources.Get "achsvermessung.jpg" }}
    <img src="{{ $img.RelPermalink }}" width="{{ $img.Width }}" height="{{ $img.Height }}" alt="…">
