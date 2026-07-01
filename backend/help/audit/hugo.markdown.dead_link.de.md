---
title: "Toter Link in der Markdown-Quelle"
summary: "Ein Link in einer Inhaltsdatei zeigt auf eine Datei/Seite, die es nicht gibt."
severity: warning
see_also: [link.internal.broken, hugo.frontmatter.required]
---

## Beschreibung

In einer Hugo-Inhaltsdatei (`content/**/*.md`) verweist ein Markdown-Link oder
eine Bildreferenz auf eine Datei oder Seite, die im Projekt nicht existiert
(z. B. ein falscher relativer Pfad oder eine gelöschte Zieldatei).

## Warum es wichtig ist

- Der Link führt später auf der Webseite ins Leere (404) und stört Nutzer wie
  Suchmaschinen.
- Der Fehler steckt bereits in der Quelle – am besten dort beheben, bevor er
  online geht.

## Lösung

Prüfe den im Fund genannten Link in der Quelldatei:

- Bei internen Seiten möglichst Hugos `relref`/`ref` verwenden – so meldet der
  Build fehlerhafte Verweise sofort:

      [zur Übersicht]({{< relref "achsvermessung/_index.md" >}})

- Bei Bildern den Pfad prüfen und die Datei bereitstellen (Page Bundle oder
  `static/`). Gelöschte Ziele durch die neue Adresse ersetzen.
