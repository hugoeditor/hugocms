# Umsetzungsplan: Gestaffelte Veröffentlichung (Draft → Freigabe → publishDate)

Status: Backend + Frontend + O1 umgesetzt und geprüft (php -l, Round-Trip-Tests
für FrontMatter/ReviewStore, `npm run build` grün, CLI-Aufrufpfad geprüft).
O1: `backend/cli/cron-build.php` + `Connector::buildSite()` + README-Abschnitt
„Gestaffelte Veröffentlichung". Offen: Erprobung im laufenden System mit
echtem Hugo-Projekt + KI-Schlüssel.

## Ziel

Automatisierte oder ungeprüfte Änderungen an Hugo-Content sollen nicht sofort
live gehen, sondern zunächst als **Entwurf** festgehalten werden. Erst nach
einer Freigabe durch einen Benutzer wird die Seite freigegeben — optional mit
einem **Veröffentlichungsdatum**, ab dem Hugo sie beim nächsten Build sichtbar
macht. So werden nicht alle geänderten Seiten auf einmal veröffentlicht.

Die Steuerung nutzt Hugos eigenes Modell (`draft`, `publishDate`) plus einen
regelmäßigen Cron-Build. Kein Streaming, kein langlaufender Zustand — passt zur
zustandslosen Backend-Architektur.

## Entschieden (Richtungsvorgaben des Nutzers)

1. **Entwurfsablage im Index-Store, nicht als `.md.draft`-Datei.** Grund:
   `FileService::writeText` weist fremde Endungen über die Endungs-Whitelist ab
   (`isSavable` + `Mount::accepts`, siehe `FileService.php:113-116`). Der
   Entwurfs-Blob liegt in einem eigenen JSON-Store; die Live-Datei bleibt bis
   zur Freigabe unangetastet. Vorteile: keine Kollision mit dem Hugo-Build,
   keine Whitelist-Probleme, kein Datei-Wildwuchs im `content/`-Baum.
2. **Front Matter ist die Veröffentlichungswahrheit; der Index ist nur die
   Warteschlange.** Freigabe schreibt `draft`/`publishDate` ins Front Matter der
   echten Datei.
3. **Draft-Pflicht nur für auto/Cron** (`improveNextContent` bzw. Assistent im
   Schreibmodus `auto`). Interaktive Bearbeitung wird nicht erzwungen.
4. **Editoren erhalten einen zusätzlichen Button** (`mdi-file-draft-outline`)
   neben „Speichern" — manuelles Ablegen in die Freigabe-Warteschlange statt
   direkt live.
5. **Dashboard: Freigabe-Warteschlange** — Liste offener Entwürfe, Diff
   Original↔Entwurf, „Freigeben mit Datum" / „Verwerfen".
6. **Nur Schreib- und Anlege-Vorgänge werden gestaffelt.** Löschungen und
   Umbenennungen gehen weiterhin direkt.

## Datenmodell

Neuer Store: `backend/core/var/review/<sha1(source)>/<sha1(mount:rel)>.json`.
Ein Eintrag pro offenem Entwurf:

```json
{
  "key": "<sha1(mount:rel)>",
  "mount": "<mount-name>",
  "rel": "content/blog/beitrag.md",
  "fileId": "<opaque MountResolver-ID>",
  "origin": "ai" | "user" | "cron",
  "isNew": false,               // true = Datei existiert live noch nicht
  "proposedContent": "…",       // vollständiger neuer Dateiinhalt
  "baseMtime": 1720000000,      // mtime der Live-Datei bei Entwurfserstellung (Konflikterkennung)
  "createdAt": "2026-07-09T09:00:00+00:00",
  "model": "claude-opus-4-8"    // nur bei origin ai/cron
}
```

Ein Entwurf je Datei (erneutes Ablegen überschreibt den offenen Entwurf).

## Backend

### B1 — `ReviewStore` (`backend/core/Review/ReviewStore.php`)
JSON-Store nach Vorbild von `ContentQualityService`: `list()`, `forFile($fileId)`,
`put($entry)`, `delete($key)`, atomar über Tempdatei + `rename`. Verzeichnis
lazy anlegen. Store je Webseite getrennt (`sha1(source)`).

### B2 — `FrontMatter` (`backend/core/Review/FrontMatter.php`)
Formatbewusster Einzelschlüssel-Upsert für `draft` (bool) und `publishDate`
(String/ISO), aufsetzend auf dem Splitter-Muster aus `Audit/Checks.php:410`
(YAML `---`, TOML `+++`, JSON). Setzt oder ersetzt einen Schlüssel unter
Beibehaltung des vorhandenen Formats. Fehlt ein Front-Matter-Block, wird keiner
erzwungen (Aufrufer entscheidet). Kein voller Parser.
Wegwerf-Round-Trip-Test gegen den Autoloader.

### B3 — Schreib-Interzeption für auto/Cron
Im Schreibmodus `auto` (`AssistantService`) geht `write_file` nicht mehr direkt
in die Datei, sondern legt den Inhalt als Entwurf im `ReviewStore` ab; die
Live-Datei bleibt stehen. Rückmeldung an das Modell: „als Entwurf zur Freigabe
vorgemerkt". Interaktive Modi (`confirm`) unverändert. `improveNextContent`/
`runImprove` erben das Verhalten automatisch (laufen im `auto`-Modus).

### B4 — Neue Connector-Befehle (`match`-Block in `Connector.php:292`)
- `reviewsave`    — manuelles „als Entwurf speichern" aus dem Editor (Button).
- `reviewlist`    — offene Entwürfe fürs Dashboard.
- `reviewget`     — Entwurf + aktuelles Original (für die Diff-Ansicht).
- `reviewapprove` — schreibt den Entwurf über `FileService` in die echte Datei,
  setzt `draft:false` und optional `publishDate` (aus Parameter) ins Front
  Matter, löscht den Entwurf. Konfliktprüfung über `baseMtime`.
- `reviewdiscard` — Entwurf verwerfen.

Alle Befehle laufen über `FileService`/`MountResolver` (Einsperrung,
`permissions`/`readonly`, Endungen). Freigabe ist Pro-gebunden wie Audit
(`requirePro`), sofern kein Grund dagegen spricht — beim Umsetzen prüfen.

## Frontend

### F1 — Entwurf-Button in den Editoren (`EditorPanel.vue:158`)
Zweiter Button `mdi-file-draft-outline` neben „Speichern" in der `tools`-Liste;
neue Aktion `saveAsDraft()` → `files`-Store → `reviewsave`. Über die vorhandenen
`@save`-Slots auch in `FrontMatterPanel`/`WysiwygEditor` durchreichen.

### F2 — Freigabe-Warteschlange
Neue `frontend/src/components/ReviewQueueView.vue` + `stores/review.js`. Liste
offener Entwürfe, Diff Original↔Entwurf über vorhandenes `util/lineDiff.js`,
Aktionen „Freigeben mit Datum" (Datumsauswahl → `publishDate`, leer = sofort) und
„Verwerfen". Einbindung als weitere View im Werkzeugschienen-Muster von
`App.vue` (analog `openAuditView`).

### F3 — i18n
Alle neuen Strings in `frontend/src/i18n/de.js` UND `en.js`.

## Betrieb

### O1 — Cron-Build
Beispiel/Doku für einen regelmäßigen `hugo`-Build-Cron (ohne `--buildFuture`),
der freigegebene, terminierte Seiten zum `publishDate` sichtbar macht. Die
Auflösung der Staffelung entspricht dem Build-Intervall.

## Offener Punkt (beim Freigeben beachten)

Bei einer terminierten Freigabe (`publishDate` in der Zukunft) einer bereits
live stehenden Seite geht der neue Inhalt sofort auf die Platte. Damit Hugo die
Seite bis zum Termin zurückhält, muss die Freigabe `publishDate` (und je nach
Theme ggf. kurzzeitig `draft`) korrekt ins Front Matter schreiben — das leistet
B2. Ohne künftiges `publishDate` erscheint der neue Inhalt beim nächsten Build.

## Reihenfolge / Verifikation

1. B1 ReviewStore, B2 FrontMatter (Wegwerf-Round-Trip-Test) — `php -l`.
2. B4 Connector-Befehle — `php -l`.
3. B3 Interzeption.
4. F1–F3 Frontend — `npm run --prefix frontend build`.
5. O1 Doku.
