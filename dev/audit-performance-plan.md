# Entscheidungsvorlage: Performance der SEO-Audit-Liste

Status: Erste Frontend-Optimierungen umgesetzt; SQLite-Frage offen (bewusst
vertagt für eine spätere Entscheidung).

## Ausgangslage

Beim Beispiel-Webseitenprojekt auf dem Entwicklungsrechner ist die Arbeit mit der
SEO-Audit-Liste (Bericht + Fundtabelle) spürbar langsam und zäh.

Kenngrößen des großen Beispiel-Berichts:

- ~2093 Funde, 435 Seiten, ~429 KB JSON.
- Ablage aktuell: eine JSON-Datei je Lauf unter
  `backend/var/audit/<sha1(source)>/<id>.json` (bewusst keine Datenbank).

## Diagnose: nicht der Speicher, sondern das Frontend

429 KB JSON liest PHP in Millisekunden — die Datenmenge ist unkritisch. Die
Zähigkeit ist Frontend-seitig:

1. **Tiefe Reaktivität**: Pinia umhüllt jedes der ~2093 Fund-Objekte in einen
   Proxy; jeder Filter-/Renderzugriff läuft darüber.
2. **Schwere Tabellenzeilen**: je Zeile Vuetify-Komponenten (`v-chip`, `v-icon`)
   — bei 300 gerenderten Zeilen über tausend Komponenten-Instanzen.
3. **Filtern ohne Entprellung**: die URL/Quelle-Suche filterte + rerenderte bei
   jedem Tastendruck.

Fazit: Der Engpass ist Renderaufwand und Reaktivitäts-Overhead, nicht Ablage
oder Abfrage. **Eine Datenbank würde das gefühlte Problem nicht lösen.**

## Bereits umgesetzt (rein Frontend, kein Architektur-Eingriff)

- **`markRaw` für den Bericht** (`stores/audit.js`, `runAudit`/`fetchRun`): Der
  Bericht ist reine Anzeige — ohne tiefe Reaktivität entfällt der Proxy-Aufwand
  über alle Funde.
- **Leichter Schweregrad-Chip** (`components/AuditIssueTable.vue`): `v-chip` je
  Zeile durch ein CSS-gestyltes `<span>` ersetzt.
- **Entprellte Suche** (200 ms): kein Filtern/Rendern mehr pro Tastenanschlag.

Erwartung: deutliche Reduktion der spürbaren Zähigkeit. Wirkung am
Entwicklungsrechner noch zu bestätigen.

## Offene Entscheidung 1: SQLite einführen?

Frage des Nutzers: Ist es sinnvoll, die „keine-Datenbank"-Beschränkung
aufzugeben?

**Empfehlung: jetzt nein.** SQLite hilft nur, wenn zusätzlich **Filtern und
Paginierung auf den Server** wandern (nur N Zeilen je Seite per SQL). Dagegen:

- **Hosting-Kompatibilität** (CLAUDE.md-Prinzip): `pdo_sqlite`/`sqlite3` sind
  PHP-Bordmittel, aber auf minimalem Shared Hosting nicht garantiert. Die
  JSON-Wahl war genau dieser Kompatibilität geschuldet.
- **Zustand/Aufwand**: Schema, Migration, Zustand — genau das, was das schlanke,
  zustandslose Backend vermeidet.
- Bei einigen Tausend Funden ist serverseitige Paginierung überdimensioniert.

**Wann neu bewerten:** Wenn Berichte in die **Zehntausende** Funde wachsen ODER
serverseitige Suche/Trendauswertung über viele Läufe gewünscht ist. Dann wäre
eine DB das richtige Werkzeug — idealerweise mit **Fallback auf JSON**, falls
`pdo_sqlite` fehlt (Capability-Prüfung beim Start).

## Offene Entscheidung 2: nächster Frontend-Hebel, falls noch zäh

Immer noch **ohne** Datenbank, in Reihenfolge des Aufwand/Nutzen:

1. **Virtualisiertes Scrollen** der Fundtabelle: nur sichtbare Zeilen rendern
   (`v-virtual-scroll` oder CSS `content-visibility: auto` je Zeile) statt der
   jetzigen 300er-Blöcke. Größter Hebel bei sehr langen Listen.
2. **Kleinere Erst-Seite** (STEP von 300 auf z. B. 100) — trivial, aber nur
   Symptomlinderung.
3. **Weniger `v-icon` je Zeile**: Hilfe-/Sprung-Icons ggf. durch CSS/`<i>`
   ersetzen (kleinerer Hebel als der bereits ersetzte `v-chip`).

## Nächster Schritt

Umgesetzte Änderungen am Entwicklungsrechner testen. Reicht es nicht, zuerst
Frontend-Hebel 1 (Virtualisierung) umsetzen — die SQLite-Frage bleibt bis dahin
vertagt.
