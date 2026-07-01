---
title: "Viewport-Angabe fehlt"
summary: "Ohne viewport-Meta-Tag ist die Seite auf dem Handy nicht mobilfreundlich."
severity: warning
see_also: [html.doctype.missing]
---

## Beschreibung

Dem `<head>` fehlt das Meta-Tag `viewport`. Es sagt mobilen Browsern, dass sie
die Seite in Gerätebreite darstellen sollen, statt eine Desktop-Seite
herauszuzoomen.

## Warum es wichtig ist

- Ohne Viewport wirkt die Seite auf dem Smartphone winzig; Nutzer müssen zoomen.
- „Mobilfreundlichkeit" ist ein Google-Rankingfaktor (Mobile-First-Indexierung).

## Lösung

Ergänze im `<head>`:

    <meta name="viewport" content="width=device-width, initial-scale=1">

Prüfe anschließend, ob das Layout mit responsivem CSS auf schmale Breiten
reagiert.
