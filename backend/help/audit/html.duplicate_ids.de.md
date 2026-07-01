---
title: "Doppelte id-Attribute"
summary: "Ein id-Wert kommt mehrfach vor – ids müssen pro Seite eindeutig sein."
severity: warning
see_also: [link.empty_href]
---

## Beschreibung

Auf dieser Seite kommt derselbe `id`-Wert an mehreren Elementen vor. Laut
HTML-Standard muss jede `id` innerhalb einer Seite eindeutig sein.

## Warum es wichtig ist

- Sprungmarken (`#id`), Label-Zuordnungen (`for`) und JavaScript greifen nur auf
  das **erste** Element mit dieser id – der Rest bleibt unerreichbar.
- Das führt zu subtilen Fehlern bei Ankerlinks und Formularen.

## Lösung

Vergib jede `id` nur einmal. Häufige Ursache sind wiederholte Bausteine (z. B.
mehrere Formulare oder Akkordeons mit fest verdrahteter id). Verwende dort
eindeutige Werte, etwa mit einem Index oder Slug:

    <section id="faq-{{ .Anchor }}"> … </section>
