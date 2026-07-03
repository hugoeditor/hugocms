---
title: "Seitentitel zu lang"
summary: "Der <title> hat mehr als ~60 Zeichen und wird im Suchergebnis abgeschnitten."
severity: hint
see_also: [title.too_short]
---

## Beschreibung

Der Titel dieser Seite ist länger als etwa 60 Zeichen. Google schneidet zu lange
Titel im Suchergebnis ab (…), sodass das Ende – oft die Marke oder der
wichtigste Zusatz – nicht mehr sichtbar ist.

## Warum es wichtig ist

- Abgeschnittene Titel wirken unprofessionell und verlieren Aussagekraft.
- Die wichtigsten Wörter sollten vorne stehen, bevor abgeschnitten wird.

## Lösung

Kürze den Titel auf **30–60 Zeichen**. Stelle das Hauptthema an den Anfang und
verzichte auf Füllwörter:

    ❌ "Professionelle Achsvermessung für Ihren BMW 3er zu günstigen Preisen bei Autofit in der Region"
    ✅ "Achsvermessung BMW 3er – Preise & Ablauf | Autofit"

Ein automatisch angehängtes `| {{ .Site.Title }}` beim Kürzen mitrechnen.
