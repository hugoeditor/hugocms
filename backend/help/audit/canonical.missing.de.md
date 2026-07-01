---
title: "Canonical-Link fehlt"
summary: "Ohne rel=canonical kann bei ähnlichen URLs unklar sein, welche die maßgebliche ist."
severity: warning
see_also: [canonical.multiple, canonical.self_reference]
---

## Beschreibung

Der Seite fehlt ein `<link rel="canonical">`. Dieses Tag nennt die „offizielle"
Adresse einer Seite. Es hilft, wenn dieselbe Seite über mehrere URLs erreichbar
ist (mit/ohne `www`, mit Parametern, http/https).

## Warum es wichtig ist

- Ohne Canonical kann Google mehrere URL-Varianten als getrennte Seiten werten
  (Duplicate Content) und die Ranking-Kraft aufteilen.
- Der Canonical bündelt die Signale auf einer Adresse.

## Lösung

Gib im `<head>` die kanonische URL der Seite aus. In Hugo:

    <link rel="canonical" href="{{ .Permalink }}">

Achte auf eine korrekt gesetzte `baseURL` in der Hugo-Konfiguration, damit die
Permalinks absolut und einheitlich sind.
