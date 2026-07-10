---
title: "URL wirkt wie versehentliche Kopie"
summary: "Der Slug trägt ein Kopie-Merkmal (…-kopie, neu-…), und die Ausgangsseite existiert bereits – vermutlich eine ungewollte Dublette."
severity: error
see_also: [filename.near_duplicate, title.duplicate, canonical.missing]
---

## Beschreibung

Der letzte Teil dieser URL (der **Slug**) enthält ein typisches Kopie-Merkmal
wie `-kopie`, `-copy`, `-neu`, `-alt` oder `-entwurf`, und ohne dieses Merkmal
entspricht er einer bereits vorhandenen Seite. Das ist das klassische Muster
einer versehentlich duplizierten Datei: Aus `impressum` wird beim Kopieren
`impressum-kopie`, beide liegen anschließend im veröffentlichten Projekt.

## Warum es wichtig ist

- Zwei nahezu identische Seiten unter verschiedenen Adressen sind **Duplicate
  Content**. Suchmaschinen müssen raten, welche die „richtige" ist, und teilen
  die Sichtbarkeit auf beide auf.
- Interne Links, geteilte Adressen und die Sitemap zeigen unkontrolliert mal auf
  die eine, mal auf die andere Fassung.
- Nutzer, die auf die Kopie geraten, sehen womöglich eine veraltete oder
  unfertige Version.

## Lösung

Prüfe, welche der beiden Seiten die gewünschte ist, und **entferne die Kopie**:

- Ist die Kopie überflüssig, lösche die zugehörige Quelldatei (in Hugo die
  entsprechende `.md`-Datei bzw. den Ordner).
- Wird die Adresse noch gebraucht, richte eine Weiterleitung (Alias/Redirect)
  auf das Original ein, statt eine zweite Seite zu behalten.
- Soll die neue Fassung das Original ersetzen, benenne sie sauber um und lösche
  die alte, statt beide parallel zu betreiben.

Lässt sich eine Dublette kurzfristig nicht vermeiden, setze auf der Kopie einen
`canonical`-Verweis auf das Original, damit Suchmaschinen die maßgebliche Seite
kennen.
