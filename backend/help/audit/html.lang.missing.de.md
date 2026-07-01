---
title: "Sprachangabe (lang) fehlt"
summary: "Dem <html>-Element fehlt das lang-Attribut – wichtig für Barrierefreiheit und Suche."
severity: warning
see_also: [html.charset.missing]
---

## Beschreibung

Dem `<html>`-Element dieser Seite fehlt das `lang`-Attribut. Es gibt an, in
welcher Sprache der Inhalt verfasst ist (z. B. `de`).

## Warum es wichtig ist

- Screenreader wählen anhand von `lang` die richtige Aussprache; ohne die Angabe
  klingt Vorlesen falsch.
- Suchmaschinen und Browser (Übersetzungsangebot) nutzen die Sprachkennung.

## Lösung

Setze die Sprache im Layout, in Hugo aus der Seitensprache:

    <html lang="{{ .Site.Language.Lang | default "de" }}">

Bei mehrsprachigen Seiten stellt Hugo `.Site.Language.Lang` je Sprachversion
korrekt bereit.
