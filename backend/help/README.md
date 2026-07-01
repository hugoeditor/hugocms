# HugoCMS – Hilfe / Wissensdatenbank

Dieses Verzeichnis enthält die Hilfetexte, die der Client anzeigt (z. B. die
ausführlichen Erklärungen zu den SEO-Audit-Regeln). Das System ist bewusst
einfach gehalten, damit auch Nicht-Entwickler Beiträge liefern können.

## Aufbau

Ein Hilfethema ist eine **Markdown-Datei** mit **YAML-Front-Matter**, benannt
nach dem Schema

    <sektion>/<id>.<sprache>.md

- **sektion** – Themenbereich, z. B. `audit` (später `editor`, `general`, …).
- **id** – eindeutige Kennung. Bei Audit-Regeln ist das die Regel-ID
  (`issue.ruleId`), z. B. `title.too_short`. Erlaubt: `a–z 0–9 . _ -`.
- **sprache** – `de` oder `en`. Fehlt die gewünschte Sprache, zeigt der Client
  automatisch die englische Fassung.

Beispiel:

    audit/title.too_short.de.md
    audit/title.too_short.en.md

## Front-Matter

Am Dateianfang, zwischen zwei `---`-Zeilen. Unterstützt werden einfache
Schlüssel/Wert-Paare, `"Strings"` und Inline-Listen `[a, b, c]`:

    ---
    title: "Seitentitel zu kurz"
    summary: "Kurzfassung in einem Satz."
    severity: warning            # error | warning | hint (informativ)
    see_also: [title.identical_to_h1, title.missing]
    ---

- **title** (empfohlen) – Überschrift der Hilfeseite.
- **summary** (optional) – ein Satz, wird hervorgehoben angezeigt.
- **severity** (optional) – nur informativ.
- **see_also** (optional) – IDs verwandter Themen; im Client anklickbar.

## Rumpf

Normales Markdown. Bewährt hat sich eine man-artige Gliederung mit
Überschriften der Ebene 2:

    ## Beschreibung
    ## Warum es wichtig ist
    ## Lösung
    ## Beispiel
    ## Siehe auch

Der Client rendert den Markdown-Rumpf; Front-Matter und Sprachwahl übernimmt das
Backend. Es ist **kein** Build-Schritt nötig – Datei anlegen genügt.

## Eine neue Hilfeseite hinzufügen

1. Datei `<sektion>/<id>.de.md` (und möglichst `.en.md`) anlegen.
2. Front-Matter mit `title` und `summary` ausfüllen.
3. Rumpf schreiben. Fertig – beim nächsten Öffnen im Client ist sie da.
