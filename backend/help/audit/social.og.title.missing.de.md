---
title: "Open-Graph-Titel fehlt"
summary: "Ohne og:title nutzen soziale Netzwerke einen Ersatztitel beim Teilen."
severity: warning
see_also: [social.og.description.missing, social.og.image.missing]
---

## Beschreibung

Der Seite fehlt `og:title` (Open Graph). Dieses Tag legt die Überschrift der
Vorschau fest, wenn die Seite in sozialen Netzwerken oder Messengern geteilt
wird.

## Warum es wichtig ist

- Ohne `og:title` raten die Plattformen (meist der `<title>`) – oft mit dem
  angehängten Markenzusatz, der in der Vorschau stört.
- Ein eigener Social-Titel kann prägnanter und einladender sein.

## Lösung

Setze im `<head>` einen Open-Graph-Titel, in Hugo meist zentral im Layout:

    <meta property="og:title" content="{{ .Title }}">

Prüfe zusammen mit `og:description` und `og:image`, damit die Vorschau
vollständig ist.
