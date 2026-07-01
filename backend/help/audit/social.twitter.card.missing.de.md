---
title: "Twitter-Card-Angabe fehlt"
summary: "Ohne twitter:card zeigt X/Twitter nur eine schlichte statt einer großen Vorschau."
severity: hint
see_also: [social.og.image.missing, social.og.title.missing]
---

## Beschreibung

Der Seite fehlt das Meta-Tag `twitter:card`. Es steuert, wie ein geteilter Link
auf X (Twitter) dargestellt wird – als kleine Zeile oder als große Bildvorschau.

## Warum es wichtig ist

- Ohne die Angabe fällt die Vorschau schlichter aus und wird seltener beachtet.
- Die Open-Graph-Tags (Titel, Bild, Beschreibung) werden dabei mitgenutzt.

## Lösung

Ergänze im `<head>` die Kartenart – für ein großes Vorschaubild:

    <meta name="twitter:card" content="summary_large_image">

Ein passendes `og:image` sollte vorhanden sein, damit die große Karte greift.
