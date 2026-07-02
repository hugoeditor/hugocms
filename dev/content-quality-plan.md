# Umsetzungsplan: LLM-Content-Qualität (per Seite, auf Abruf)

Status: in Umsetzung (Backend zuerst).

## Ziel & Abgrenzung

Ein Pro-Nutzer prüft **eine einzelne Content-Datei** auf Abruf durch Claude
(Lesbarkeit, Dünn-Content, Keyword-Fokus, Tonalität, konkrete Verbesserungen).

- Ein Aufruf = **ein** `AnthropicClient::createMessage` = eine JSON-Antwort.
  Kein Tool-Loop, kein Async, kein Batch → hosting-tauglich, linientreu zu
  `CLAUDE.md` (ein Request → eine Antwort, kein langlaufender Zustand).
- **Nicht** Teil des synchronen Gesamt-Audits: Ein LLM-Aufruf je Seite über die
  ganze Site würde die Worker-/Zeitgrenzen auf Shared Hosting sprengen.
- Ergebnisse liegen als **eine Datei je geprüfter Seite** unter `var/`, sodass
  sich eine **Liste bereits geprüfter Seiten** anzeigen lässt (Bewertung, Datum,
  „veraltet"-Kennung, wenn die Quelle seither geändert wurde).

## Speicherung (Empfehlung, bestätigt)

`backend/var/audit-content/<sha1(hugo-source)>/<sha1(mount:rel)>.json`

- Site-Scope wie beim Audit (`sha1` des Hugo-Quellverzeichnisses).
- Eine Datei je Content-Datei; erneutes Prüfen überschreibt, Liste = `glob()`.
- Kein Retention-Limit nötig (Content-Dateien sind endlich).

Datei-Inhalt:

```json
{
  "key": "<sha1(mount:rel)>",
  "mount": "<mount-name>",
  "rel": "content/blog/beitrag.md",
  "title": "…",
  "checkedAt": "2026-07-01T12:00:00+00:00",
  "model": "claude-opus-4-8",
  "contentHash": "<sha1(body)>",
  "truncated": false,
  "verdict": {
    "score": 0,
    "summary": "…",
    "readability": { "rating": "good|medium|weak", "note": "…" },
    "findings": [ { "severity": "hint|warning", "title": "…", "detail": "…" } ],
    "suggestions": [ "…" ]
  }
}
```

Maschinenwerte (`rating`, `severity`) sind stabil englisch; der Client
übersetzt. Freitexte (`summary`, `note`, `title`, `detail`, `suggestions`)
liefert das Modell in der übergebenen `locale`.

## Backend

### Task B1 — `ContentQualityService` (`backend/core/Audit/ContentQualityService.php`)

Dünner Dienst analog zu `AuditService`. Konstruktor:
`AnthropicClient $client, string $model, MountResolver $resolver, FileService $files, string $storageDir`.

- `analyze(string $fileId, string $locale): array`
  1. `resolver->resolve($fileId, true)` → Mount/abs/rel (Mount-Einsperrung greift).
  2. `files->readText($mount, $abs)` → Inhalt (Editierbarkeit/Größe geprüft).
  3. Front-Matter abtrennen (`+++`/`---`-Block per Regex, kein Parser), Titel und
     Fließtext extrahieren; Body auf ~24 000 Zeichen kappen (`truncated`).
  4. Leerer Body → `AUDIT-CONTENT-EMPTY`.
  5. Payload mit **einem** Werkzeug `report_quality` und
     `tool_choice = {type: tool, name: report_quality}` → erzwungene strukturierte
     Ausgabe, kein Schreibwerkzeug.
  6. `tool_use`-Block auslesen, Eintrag bauen, `persist`, zurückgeben.
- `list(): array` — Metadaten aller Einträge (inkl. `score`, `rating`), neueste
  zuerst, ohne `findings`/`suggestions`.
- `get(string $key), delete(string $key)` — wie beim Audit; `key` gegen
  `/^[a-f0-9]{40}$/` validieren.

### Task B2 — Connector-Verdrahtung (`backend/core/Connector.php`)

- `use HugoCMS\FileManager\Audit\ContentQualityService;`
- Gemeinsamer Einstieg `contentQuality()`: `requireAuth` + `requirePro` +
  Hugo-Projekt + `ai['apiKey'] !== null` (sonst `AI-NOT-CONFIGURED`).
- Vier `match`-Zweige + Methoden:
  - `auditcontent` (POST, `id` = fileId, `locale`) → `@set_time_limit(180)`,
    `analyze`, Ergebnis um `fileId` anreichern.
  - `auditcontentlist` → `{ pages: [...] }`, je Eintrag `fileId` rekonstruiert
    (`encodeId(mount, rel)`, nur wenn Mount noch existiert).
  - `auditcontentget` (`key`) → vollständiger Eintrag + `fileId`.
  - `auditcontentdelete` (POST, `key`).
- Freischalt-Map (`bootstrap`-Antwort): `auditContent` = Audit-Voraussetzung
  **und** KI konfiguriert.

### Task B3 — Fehlercodes

`AI-NOT-CONFIGURED` (vorhanden), neu: `AUDIT-CONTENT-EMPTY`,
`AUDIT-CONTENT-NOT-FOUND`. Nur Codes im Backend; Übersetzung im Client.

### Verifikation Backend

- `php -l` auf jede geänderte Datei.
- Wegwerf-Skript gegen den Autoloader mit einem Fake-`AnthropicClient` (festes
  Verdikt) → Persistenz + `list()`/`get()`-Roundtrip ohne echten API-Aufruf.

## Frontend (umgesetzt)

Abweichung vom ersten Entwurf: Ergebnis **und** Liste leben gemeinsam in einem
überlagernden Dialog (`ContentQualityDialog.vue`) — kein eigener Modus/Overlay
und keine Änderung an `AuditView.vue`. Der Dialog ist aus jedem Einstieg
erreichbar und gibt der Liste der bereits geprüften Seiten so einen festen Ort.

### Task F1 — Store `frontend/src/stores/auditContent.js` ✓

`check(fileId, fileName, locale)`, `fetchChecked()`, `openResult(key)`,
`recheck()`, `remove(key)`, `closeDialog()`; State `checked`, `current`,
`dialogOpen`, `busy`, `loading`, `error`, `fileName`. Fehler werden im Dialog
angezeigt, nicht geworfen.

### Task F2 — Prüf-Einstiege ✓

1. **Editor** (`EditorPanel.vue`): Werkzeug „Content-Qualität prüfen" in der
   Toolbar — nur bei Markdown-Dateien und `auth.auditContent`.
2. **Dateiliste (nemo-view) — Kontextmenü** (`FileBrowser.vue`): Eintrag für
   einzelne Markdown-Dateien, ruft `auditContent.check(entry.id, …)`.

### Task F3 — Dialog ✓

`ContentQualityDialog.vue` (global in `App.vue` eingehängt): Ladezustand
(Prüflauf/Abruf), Fehler, Ergebnis (Score-Avatar, Lesbarkeits-Chip,
Zusammenfassung, Befunde über `AuditSeverityChip`, Vorschläge) und darunter die
Liste bereits geprüfter Seiten (Score-Chip, Datum, öffnen/löschen). Aktionen:
„Zur Quelldatei" (`files.openFileById`), „Neu prüfen", „Schließen".

### Task F4 — i18n ✓

`contentQuality.*`, `ctx.contentQuality`, `common.close` und die Fehlercodes
`AUDIT-CONTENT-EMPTY`/`AUDIT-CONTENT-NOT-FOUND` in `de.js` UND `en.js`.
Fähigkeit `auth.auditContent` (aus `whoami`).

### Verifikation Frontend ✓

`npm run --prefix frontend build` fehlerfrei. Offen: manueller Durchlauf gegen
eine echte Instanz mit Pro-Lizenz + KI-Schlüssel.

## Phase 2 (umgesetzt)

### Liste als Reiter in der AuditView ✓

Die Liste ist aus dem Dialog in einen zweiten Reiter der `AuditView.vue`
gewandert (`AuditContentList.vue`). Reiter „SEO-Bericht" / „Content-Qualität";
der zweite erscheint nur bei `auth.auditContent`. Der Dialog
(`ContentQualityDialog.vue`) zeigt jetzt nur noch das Einzelergebnis; eine Zeile
der Liste öffnet ihn (`openResult`), außerdem Neu prüfen/Löschen je Zeile.

### „Veraltet"-Marker ✓

`ContentQualityService::list()` berechnet je Eintrag den aktuellen Prüf-Hash der
Quelle (`currentBodyHash`, dieselbe `prepareBody`-Aufbereitung wie beim Prüfen)
und liefert `stale` (Quelle seit der Prüfung geändert) sowie `sourceMissing`
(Datei/Mount weg). Die Liste zeigt entsprechend einen Marker „veraltet" bzw.
„Quelle fehlt".

## Phase 3 — Gesamt-Bericht je Datei (umgesetzt)

Verknüpft das LLM-Qualitätsurteil einer Content-Datei mit den SEO-Funden
derselben Datei aus dem **jüngsten** Audit-Lauf. Basis für die spätere
Assistenten-/Cron-Verbesserung.

### Backend ✓

- `AuditService::latest()` — vollständiger Bericht des jüngsten Laufs (oder null).
- Connector `auditcontentreport` (param `key`) → `{ file, contentQuality, audit }`.
  `audit` ist null, wenn kein Lauf vorliegt; sonst `{ runId, startedAt, issues,
  summary }` mit den Funden dieser Datei. Verknüpfung über den Quellpfad relativ
  zum Hugo-Projekt (`sourceRelForEntry` ↔ `Issue::sourceFile`); jeder Fund erhält
  die `fileId` der Datei.

### Frontend ✓

- Store: `current` hält den Gesamt-Bericht; `loadReport(key)` lädt ihn, `check`
  und `openResult` nutzen ihn.
- `ContentQualityDialog.vue`: unter dem Qualitätsurteil ein Abschnitt
  „SEO-Befunde dieser Seite" (Schweregrad-Zusammenfassung + `AuditIssueTable` mit
  Hilfe-/Quelllinks). Hinweis, wenn kein Audit-Lauf vorliegt.
- i18n `contentQuality.{seoIssues,seoNoIssues,noAuditRun}`.

## Phase 4 — KI-Verbesserung einer Datei (manuell umgesetzt; Cron offen)

Der KI-Assistent verbessert eine einzelne Datei anhand ihres Gesamt-Berichts.
Manuelle Auslösung ist gebaut; der Cron bleibt zukünftig.

### Backend ✓

- **Assistenten-Werkzeug `get_file_report`** (`AssistantService`): dünne Hülle,
  liefert zu einem Pfad (`<mount>/<rel>` → fileId) den Gesamt-Bericht als JSON.
  Nur-Lesen (Leserecht des Mounts). Wird nur angeboten, wenn eine Bericht-Quelle
  injiziert ist — der Connector setzt sie nur bei Pro-Lizenz + Hugo-Projekt.
- **`ContentQualityService::forFile(fileId)`** — Qualitätseintrag zur Datei (oder
  null).
- **Connector:** `assistantService()` bündelt den Assistenten-Aufbau (inkl.
  `get_file_report`-Closure via `buildFileReportById`), von `cmdAssistant` und
  `cmdAssistantImprove` genutzt. Neuer Befehl `assistantimprove` (param `id`):
  seedet die Verbesserungsanweisung (`improveInstruction`, de/en) und führt den
  ersten Zug aus. Schreibmodus = konfigurierter Modus; bei `confirm` pausiert der
  Lauf vor dem Schreiben (pending), der Client bestätigt wie beim normalen
  Assistenten. Gate: Pro + KI-Schlüssel + Hugo-Projekt.

### Frontend ✓

- Store `assistant.improve(fileId, locale)`: ruft `assistantimprove`, öffnet das
  Panel, übernimmt den Verlauf. Folgezüge/Bestätigung laufen über den normalen
  `assistant`-Weg (behält das Werkzeug).
- Button „Mit KI verbessern" im `ContentQualityDialog` und je Zeile in
  `AuditContentList`. i18n `contentQuality.improve`.

### Verifikation ✓

`php -l` fehlerfrei; Wegwerf-Test der Werkzeug-Verdrahtung (7/7, echter Mount +
Testdatei, kein Netz); `npm run build` grün. Offen: manueller End-zu-Ende-Lauf
gegen eine Instanz mit Pro-Lizenz + KI-Schlüssel.

### Cron (zukünftig, nicht gebaut)

Eigener CLI-Einstieg: wählt die erste Datei der AuditContent-Liste, Schreibmodus
`auto`, kein interaktives Bestätigen. Nutzt denselben `assistantService()` +
`improveInstruction`.

## Offene Punkte / Phase 2

- „Veraltet"-Erkennung im Editor (Vergleich `contentHash`).
- Optionaler Re-Check-Stapel über eine ganze Sektion (dann bewusst asynchron/
  extern — nicht im synchronen Backend).
