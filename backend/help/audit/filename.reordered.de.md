---
title: "URL-Slug mit umgestellten Wörtern"
summary: "Zwei Slugs bestehen aus denselben Wörtern in anderer Reihenfolge – möglicherweise dieselbe Seite doppelt."
severity: warning
see_also: [filename.near_duplicate, filename.copy_suspect, title.duplicate]
---

## Beschreibung

Der Slug dieser Seite enthält dieselben Wörter wie der einer anderen, nur in
anderer Reihenfolge (`sommer-angebot-2024` gegenüber `angebot-sommer-2024`).
Häufig steckt dahinter dieselbe Seite, die unter zwei Adressvarianten abgelegt
wurde.

## Warum es wichtig ist

- Zwei Adressen für denselben Inhalt bedeuten **Duplicate Content** und teilen
  die Sichtbarkeit auf.
- Uneinheitliche Wortreihenfolgen wirken zufällig und erschweren es, die richtige
  Adresse zu merken oder zu verlinken.

## Lösung

Lege eine verbindliche Wortreihenfolge fest und entferne die zweite Variante:

- Entscheide dich für die aussagekräftigere Fassung und lösche die andere
  Quelldatei.
- Wird die alte Adresse noch aufgerufen, richte eine Weiterleitung auf die
  verbleibende Seite ein.
- Handelt es sich doch um zwei verschiedene Seiten, mache das im Slug durch
  eindeutige Begriffe kenntlich.
