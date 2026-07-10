---
title: "URL-Slugs fast identisch"
summary: "Zwei Seiten haben praktisch denselben Slug – Unterschied nur in Schreibweise, Trennzeichen oder Umlauten."
severity: error
see_also: [filename.copy_suspect, filename.similar, title.duplicate]
---

## Beschreibung

Der letzte Teil der URL (der **Slug**) unterscheidet sich von dem einer anderen
Seite nur in Nebensächlichkeiten: Groß-/Kleinschreibung, Bindestrich statt
Unterstrich oder ausgeschriebene Umlaute (`ueber-uns` gegenüber `ueber_uns`,
`Kontakt` gegenüber `kontakt`). Für Besucher und Suchmaschinen wirken das zwei
verschiedene Adressen für dieselbe Sache.

## Warum es wichtig ist

- Nahezu gleiche Adressen führen zu **Duplicate Content** und verwässern die
  Bewertung beider Seiten.
- Solche Paare entstehen fast immer versehentlich – etwa durch uneinheitliche
  Benennung oder eine kopierte Datei.
- Verweise und Freigaben verteilen sich zufällig auf beide Varianten.

## Lösung

Einige dich auf **eine** Schreibweise und entferne die zweite:

- Lösche die überflüssige Quelldatei oder benenne sie einheitlich um.
- Halte dich an eine durchgängige Konvention: Kleinschreibung, Bindestriche als
  Worttrenner, Umlaute konsistent behandeln.
- Wird die alte Adresse noch aufgerufen, richte eine Weiterleitung auf die
  verbleibende Seite ein.
