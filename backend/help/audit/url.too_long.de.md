---
title: "URL sehr lang"
summary: "Die Adresse ist ungewöhnlich lang – kurze, sprechende URLs sind besser."
severity: hint
see_also: [url.uppercase]
---

## Beschreibung

Die Adresse dieser Seite ist sehr lang. Kurze, sprechende URLs sind lesbarer,
teilbarer und wirken vertrauenswürdiger.

## Warum es wichtig ist

- Lange URLs werden in Suchergebnissen und beim Teilen abgeschnitten.
- Tief verschachtelte Pfade deuten oft auf eine unnötig komplizierte Struktur
  hin.

## Lösung

Halte URLs kurz und aussagekräftig. Reduziere die Verschachtelung (flachere
Sektionen) und kürze überlange Slugs auf die wesentlichen Begriffe. In Hugo lässt
sich der Pfad über `slug`, `url` im Front Matter oder die `permalinks`-Konfig
steuern.
