---
title: "Canonical zeigt nicht auf sich selbst"
summary: "Der Canonical verweist auf eine andere URL – prüfe, ob das gewollt ist."
severity: hint
see_also: [canonical.missing]
---

## Beschreibung

Der `rel="canonical"` dieser Seite zeigt **nicht** auf ihre eigene Adresse,
sondern auf eine andere URL. Das ist nur dann richtig, wenn die Seite bewusst als
Dublette einer anderen gilt.

## Warum es wichtig ist

- Ein falscher Canonical kann bewirken, dass diese Seite gar nicht indexiert wird
  – Google folgt dem Verweis auf die andere Adresse.
- Häufig ein Fehler: ein hart kodierter Canonical aus einer Vorlage oder ein
  falsch übernommenes Beispiel.

## Lösung

Prüfe, ob der Verweis beabsichtigt ist. Soll die Seite selbst indexiert werden,
zeige den Canonical auf ihre eigene URL:

    <link rel="canonical" href="{{ .Permalink }}">
