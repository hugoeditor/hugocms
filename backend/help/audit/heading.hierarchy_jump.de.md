---
title: "Überschriften-Ebene übersprungen"
summary: "Die Überschriften springen eine Ebene (z. B. von H2 direkt zu H4)."
severity: hint
see_also: [heading.h1.missing]
---

## Beschreibung

Auf dieser Seite folgt auf eine Überschrift eine um mehr als eine Stufe tiefere
(z. B. H2 direkt gefolgt von H4, ohne H3 dazwischen). Die logische Gliederung
weist damit eine Lücke auf.

## Warum es wichtig ist

- Eine saubere Reihenfolge (H1 → H2 → H3 …) hilft Screenreadern und
  Suchmaschinen, den Aufbau zu verstehen.
- Sprünge deuten oft darauf hin, dass Überschriften nur wegen ihrer Größe (Optik)
  gewählt wurden.

## Lösung

Wähle die Überschriften-Ebene nach der **Gliederung**, nicht nach der Optik:
nach einer H2 kommt eine H3, dann erst H4. Die Schriftgröße stellst du per CSS
ein, nicht durch die Wahl der Ebene.
