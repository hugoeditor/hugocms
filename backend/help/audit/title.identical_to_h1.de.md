---
title: "Titel identisch zur H1-Überschrift"
summary: "Der <title> und die H1 der Seite sind wortgleich – eine verschenkte Gelegenheit."
severity: hint
see_also: [title.too_short]
---

## Beschreibung

Der Seitentitel (`<title>`) und die sichtbare Hauptüberschrift (`<h1>`) dieser
Seite sind identisch. Das ist kein Fehler, aber eine verschenkte Gelegenheit:
Titel und Überschrift erreichen den Leser in unterschiedlichen Zusammenhängen.

## Warum es wichtig ist

- Der **Titel** erscheint im Suchergebnis und im Browser-Tab – hier zählen
  Suchbegriffe, Marke und Klickanreiz.
- Die **H1** wird auf der Seite selbst gelesen – hier darf sie prägnanter oder
  einladender sein.
- Zwei aufeinander abgestimmte, aber nicht identische Formulierungen decken mehr
  Suchvarianten ab und wirken weniger schematisch.

## Lösung

Formuliere Titel und H1 bewusst unterschiedlich. Beispiel:

    title: "Achsvermessung BMW 3er – Preise & Ablauf | Autoprofis"
    # H1:  "Achsvermessung für Ihren BMW 3er"

In Hugo steht der Titel im Front Matter; die H1 kommt meist aus der Überschrift
des Inhalts oder dem Layout.

## Siehe auch

Verwandte Regeln stehen im Abschnitt „Siehe auch" oben.
