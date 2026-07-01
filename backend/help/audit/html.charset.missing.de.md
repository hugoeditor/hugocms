---
title: "Zeichensatz (charset) fehlt"
summary: "Ohne <meta charset> können Umlaute und Sonderzeichen falsch dargestellt werden."
severity: warning
see_also: [html.doctype.missing, html.lang.missing]
---

## Beschreibung

Dem `<head>` fehlt die Angabe `<meta charset="utf-8">`. Sie legt fest, wie der
Browser die Bytes der Seite als Zeichen interpretiert.

## Warum es wichtig ist

- Ohne (oder mit falscher) Angabe erscheinen Umlaute und Sonderzeichen als
  „Kauderwelsch" (z. B. `Ã¼` statt `ü`).
- Die Angabe sollte möglichst früh im `<head>` stehen.

## Lösung

Setze den Zeichensatz als **erstes** Element im `<head>`:

    <head>
      <meta charset="utf-8">
      …
    </head>

Speichere die Dateien ebenfalls als UTF-8 (bei Hugo Standard).
