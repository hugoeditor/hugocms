---
title: "Seitentitel fehlt"
summary: "Die Seite hat kein <title>-Element – das wichtigste On-Page-SEO-Signal fehlt."
severity: error
see_also: [title.too_short, title.identical_to_h1]
---

## Beschreibung

Im `<head>` dieser Seite fehlt ein `<title>`-Element (oder es ist leer). Der
Titel ist die anklickbare Überschrift im Suchergebnis, die Beschriftung des
Browser-Tabs und der Standardtext beim Teilen.

## Warum es wichtig ist

- Ohne Titel erzeugt Google einen Ersatztext aus dem Seiteninhalt – meist
  schlechter und weniger klickstark.
- Der Titel ist eines der stärksten Ranking-Signale einer Seite.

## Lösung

Setze in Hugo im Front Matter einen `title` und stelle sicher, dass das Layout
ihn ausgibt (`partials/head.html`):

    <title>{{ if .Title }}{{ .Title }} | {{ .Site.Title }}{{ else }}{{ .Site.Title }}{{ end }}</title>

Jede veröffentlichte Seite braucht einen eindeutigen, beschreibenden Titel
(30–60 Zeichen).
