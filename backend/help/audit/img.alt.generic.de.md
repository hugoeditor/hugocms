---
title: "Alt-Text ist nichtssagend"
summary: "Der Alt-Text ist ein Platzhalter wie \"bild\" oder ein Dateiname – ohne Aussage."
severity: warning
see_also: [img.alt.missing]
---

## Beschreibung

Der Alt-Text eines Bildes besteht aus einem nichtssagenden Wort (z. B. „bild",
„image", „foto", „DSC_0001.jpg"). Er beschreibt den Inhalt des Bildes nicht.

## Warum es wichtig ist

- Ein generischer Alt-Text hilft weder Screenreader-Nutzern noch der Bildersuche.
- Er entsteht oft, wenn beim Einfügen der Dateiname übernommen wird.

## Lösung

Beschreibe, was auf dem Bild **tatsächlich zu sehen** ist – kurz und konkret:

    ❌ alt="bild1"
    ✅ alt="Techniker misst den Sturz an der Vorderachse eines BMW 3er"
