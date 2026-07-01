---
title: "Open-Graph-Bild fehlt"
summary: "Ohne og:image erscheint beim Teilen kein Vorschaubild – Links wirken unattraktiv."
severity: warning
see_also: []
---

## Beschreibung

Der Seite fehlt die Angabe `og:image` (Open Graph). Dieses Meta-Tag legt fest,
welches Vorschaubild angezeigt wird, wenn die Seite in sozialen Netzwerken,
Messengern (WhatsApp, Signal) oder Chat-Programmen geteilt wird.

## Warum es wichtig ist

- Ohne Vorschaubild wirkt ein geteilter Link kahl und wird seltener angeklickt.
- Ein passendes Bild erhöht Reichweite und Vertrauen deutlich.
- Auch Suchmaschinen und Vorschau-Dienste greifen auf `og:image` zurück.

## Lösung

Hinterlege im `<head>` ein Open-Graph-Bild (empfohlen 1200 × 630 px):

    <meta property="og:image" content="https://example.com/bild.jpg">

In Hugo setzt man das üblicherweise zentral im Layout (`partials/head.html`) mit
einem Rückfall auf ein Standardbild und – wenn vorhanden – dem Beitragsbild aus
dem Front Matter:

    {{ with .Params.image }}
      <meta property="og:image" content="{{ . | absURL }}">
    {{ else }}
      <meta property="og:image" content="{{ "og-default.jpg" | absURL }}">
    {{ end }}

## Siehe auch

Prüfe auch `og:title` und `og:description`, damit die Vorschau vollständig ist.
