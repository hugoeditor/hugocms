---
title: "Meta-Description fehlt"
summary: "Ohne <meta name=\"description\"> bestimmt Google den Vorschautext selbst."
severity: warning
see_also: [meta.description.too_long, title.missing]
---

## Beschreibung

Der Seite fehlt eine Meta-Description – der kurze Text unter dem Titel im
Suchergebnis. Fehlt er, wählt Google selbst ein Textstück aus der Seite, das oft
weniger überzeugend ist.

## Warum es wichtig ist

- Eine gute Description ist wie ein Werbetext: Sie erhöht die Klickrate, auch
  ohne das Ranking direkt zu verbessern.
- Du steuerst so aktiv, was Suchende über die Seite lesen.

## Lösung

Setze in Hugo im Front Matter eine `description` (ca. 120–160 Zeichen) und gib
sie im Layout aus:

    ---
    description: "Achsvermessung für den BMW 3er: Ablauf, Dauer und Preise. Termin online anfragen."
    ---

    <meta name="description" content="{{ .Description }}">

Formuliere je Seite eine eigene, treffende Beschreibung mit Handlungsaufruf.
