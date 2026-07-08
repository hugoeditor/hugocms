---
title: "Interner Link führt ins Leere"
summary: "Ein Link auf dieser Seite zeigt auf eine Adresse, die im gebauten Projekt nicht existiert."
severity: error
see_also: []
---

## Beschreibung

Ein interner Link auf dieser Seite verweist auf eine Adresse, zu der im
gebauten `public/`-Ordner keine Seite gefunden wurde. Besucher landen dann auf
einer Fehlerseite (404), und Suchmaschinen werten tote Links negativ.

## Warum es wichtig ist

- Tote Links frustrieren Besucher und unterbrechen den Weg zum Ziel (z. B. zum
  Anfrageformular).
- Suchmaschinen verteilen „Link-Kraft" nur über funktionierende Verweise.
- Häufig steckt ein Tippfehler oder eine seit dem Umbau veraltete Adresse
  dahinter.

## Lösung

1. Prüfe die im Fund genannte Ziel-Adresse und vergleiche sie mit der tatsächlich
   erzeugten URL.
2. Korrigiere den Link in der Inhalts- oder Layout-Datei – achte auf führenden
   Schrägstrich und die genaue Schreibweise.
3. Wurde die Zielseite entfernt oder umbenannt, setze den Link auf die neue
   Adresse oder richte in Hugo einen Alias (Weiterleitung) ein:

        ---
        aliases: ["/alte-adresse/"]
        ---

## Hinweis

Wird das Ziel von einem **PHP-Handler** ausgeliefert (z. B. `/anfrage/` über eine
`index.php`) statt von einer Hugo-Seite, ist der Link in Wahrheit gültig. Der
Check berücksichtigt `index.php`/`index.htm` als Verzeichnis-Standardseite; ein
solcher Fund sollte daher nicht mehr auftreten.

## Siehe auch

Verwandte Regeln stehen im Abschnitt „Siehe auch" oben.
