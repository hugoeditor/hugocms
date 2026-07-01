---
title: "DOCTYPE fehlt"
summary: "Ohne <!DOCTYPE html> rendern Browser im „Quirks-Modus" – mit Darstellungsfehlern."
severity: error
see_also: [html.charset.missing, html.viewport.missing]
---

## Beschreibung

Der Seite fehlt die Deklaration `<!DOCTYPE html>` in der allerersten Zeile. Sie
schaltet den standardkonformen Darstellungsmodus des Browsers ein.

## Warum es wichtig ist

- Ohne DOCTYPE fällt der Browser in den „Quirks-Modus" – ältere, inkonsistente
  Regeln, die zu Layout-Fehlern führen.
- Ein fehlender DOCTYPE deutet auf ein defektes Grundgerüst des Layouts hin.

## Lösung

Stelle sicher, dass die HTML-Ausgabe mit dem DOCTYPE beginnt. In Hugo steht er
ganz oben in `layouts/_default/baseof.html`:

    <!DOCTYPE html>
    <html lang="{{ .Site.Language.Lang }}">

Achte darauf, dass davor keine Leerzeile oder Ausgabe (z. B. aus einem Partial)
steht.
