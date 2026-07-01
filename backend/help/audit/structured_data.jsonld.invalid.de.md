---
title: "Strukturierte Daten (JSON-LD) ungültig"
summary: "Ein JSON-LD-Block auf der Seite ist kein gültiges JSON und wird ignoriert."
severity: warning
see_also: []
---

## Beschreibung

Diese Seite enthält einen `<script type="application/ld+json">`-Block, dessen
Inhalt kein gültiges JSON ist (z. B. ein Komma zu viel, ein fehlendes
Anführungszeichen). Suchmaschinen können ihn nicht auswerten.

## Warum es wichtig ist

- Strukturierte Daten ermöglichen Rich Results (z. B. Bewertungen, Öffnungszeiten,
  Breadcrumbs). Ist das JSON kaputt, entfällt der Vorteil vollständig.
- Ein Syntaxfehler macht den gesamten Block wertlos.

## Lösung

Prüfe den JSON-LD-Block auf Syntaxfehler – am schnellsten mit dem
Rich-Results-Test von Google oder dem Schema-Markup-Validator. Häufige Ursachen:

- ein Komma nach dem letzten Element,
- nicht escapte Anführungszeichen im Text,
- durch Templating erzeugte leere Werte.

In Hugo hilft es, JSON-LD aus Daten mit dem `jsonify`-Filter zu erzeugen, statt
es von Hand zusammenzusetzen.
