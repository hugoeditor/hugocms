# Umsetzungsplan: Verbesserungs-Zustand & abgeleitete Arbeitsliste (audit-content)

Status: Backend + Frontend umgesetzt und geprüft; Cron weiterhin zukünftig.

## Ziel

Nach einer KI-Bearbeitung soll eine geprüfte Content-Datei als „verbessert"
vermerkt und in einer separaten Listenansicht geführt werden — damit (vor allem
per Cron) nicht unnötig oft geprüft/verbessert wird. Die Arbeitsliste wird
**abgeleitet** (keine eigene Queue-Infrastruktur).

## Entschieden (Richtungsvorgaben des Nutzers)

1. **Abgeleitete Arbeitsliste** — keine explizite Queue; die „Queue" ist ein
   Filter über die vorhandenen Qualitäts-Einträge.
2. **Verbesserungsbedürftig** = es existiert ein Qualitätsbericht mit
   `score < 100` UND die Datei wurde noch nicht verbessert (`improvedAt` leer).
3. **Keine automatische Neuprüfung** nach einer Verbesserung.
4. **Jede KI-Bearbeitung** einer geprüften Datei gilt als „verbessert"
   (allgemeiner Haken im Schreibpfad, nicht nur der dedizierte Verbesserungs-Flow).

## Datenmodell (Erweiterung des bestehenden Eintrags)

`var/audit-content/<sha1(source)>/<sha1(mount:rel)>.json` erhält zwei Felder:

```json
{
  "…": "… (unverändert: key, mount, rel, title, checkedAt, model, contentHash, verdict) …",
  "improvedAt": null,        // ISO-Zeit der letzten KI-Bearbeitung, sonst null
  "improveModel": null       // Modell, das bearbeitet hat
}
```

Kein neuer Speicher. Ableitungen:

- **„Zu verbessern"** (Queue) = `verdict.score != null && verdict.score < 100 && !improvedAt`.
- **„Verbessert"** = `improvedAt != null`.
- **Wieder aufnehmen** = `improvedAt` auf null zurücksetzen (günstig, kein
  LLM-Aufruf) → Datei erscheint wieder in „Zu verbessern", falls `score < 100`.

Hinweis: Nach einer Verbesserung ist der Eintrag zugleich `stale` (Hash ≠, weil
die Datei geändert wurde) — das gespeicherte Urteil stammt von VOR der
Bearbeitung. Wegen Entscheidung 3 (keine Auto-Neuprüfung) bleibt das so, bis der
Nutzer „Neu prüfen" auslöst. In der Anzeige hat der „verbessert"-Marker Vorrang
vor „veraltet".

## Backend

### B1 — `ContentQualityService`

- `analyze()`: neuen Eintrag um `improvedAt => null`, `improveModel => null`
  ergänzen (frische Prüfung löscht damit einen früheren Verbesserungs-Vermerk).
- `markImproved(string $fileId, string $model): void` — löst die fileId auf,
  bildet den Schlüssel; existiert ein Eintrag, `improvedAt = gmdate('c')`,
  `improveModel = $model`, atomar speichern. Kein Eintrag → No-op.
- `requeue(string $key): array` — `improvedAt`/`improveModel` auf null setzen,
  speichern; liefert die aktualisierten Metadaten.
- `list()`: `improvedAt` und `improveModel` in die Metadaten aufnehmen.

### B2 — Schreibpfad-Haken in `AssistantService`

- Konstruktor um optionale `?\Closure $onWrite = null` (Signatur `(string $fileId)`)
  erweitern — analog zur bestehenden `$fileReport`-Closure.
- In `toolWriteFile()` nach erfolgreichem `writeText`: fileId aus
  `encodeId($mount->name(), $rel)` bilden und `($this->onWrite)($id)` aufrufen
  (nur, wenn gesetzt). Fehler dort dürfen den Schreibvorgang nicht kippen.
- Wirkt für JEDEN Schreibvorgang (normaler Chat, confirm-Bestätigung,
  Verbesserungslauf, Cron) — deckt Entscheidung 4 ab.

### B3 — Connector-Verdrahtung

- `assistantService()`: `onWrite`-Closure unter DERSELBEN Bedingung wie
  `fileReport` einhängen (Pro-Lizenz + Hugo-Projekt) →
  `fn (string $fileId) => $this->markFileImproved($fileId)`.
- `markFileImproved(string $fileId): void`: baut den Content-Quality-Store zum
  aktuellen Hugo-Projekt (Pfad wie in `contentQuality()`), ruft
  `markImproved($fileId, $this->ai['model'])`. Ohne Hugo-Projekt No-op. Kein
  KI-Gate nötig (läuft ohnehin nur im angemeldeten Assistenten-Kontext).
- Neuer Befehl `auditcontentrequeue` (POST, `key`) → `contentQuality()->requeue($key)`;
  im `match` registrieren; Ergebnis via `withContentFileId` anreichern.
- `auditcontentlist`/`auditcontentget`/`auditcontentreport` liefern `improvedAt`
  automatisch mit (unveränderte Durchreiche der Einträge).

### Verifikation Backend
- `php -l` je Datei.
- Wegwerf-Test: `markImproved` setzt/`requeue` löscht die Felder; `list()`
  reicht sie durch; Schreibhaken feuert (Fake-`onWrite`) — ohne Netz.

## Frontend

### F1 — Store `auditContent`
- `requeue(key)` → POST `auditcontentrequeue`, danach `fetchChecked()`.
- Listeneinträge tragen `improvedAt`/`improveModel` (nur Durchreiche).

### F2 — `AuditContentList` (die separate Sicht)
- **Segmentfilter** oben (neben der Suche): „Alle" · „Zu verbessern" ·
  „Verbessert".
  - Zu verbessern: `score != null && score < 100 && !improvedAt`.
  - Verbessert: `!!improvedAt`.
  - Kombiniert mit dem bestehenden URL/Quelle-Suchfilter.
- **Zeilen-Marker** „verbessert" (mit Datum als Titel) — Vorrang vor „veraltet".
- **Zeilen-Aktion** „Wieder aufnehmen" (`requeue`), sichtbar wenn `improvedAt`
  gesetzt. „Neu prüfen" bleibt bestehen (frisches Urteil, löscht `improvedAt`
  ohnehin durch den neuen Eintrag).

### F3 — `ContentQualityDialog`
- Vermerk „Verbessert am {Datum} ({Modell})", wenn `improvedAt` gesetzt.

### F4 — i18n (de/en)
- `contentQuality.improvedChip`, `improvedAt`, `requeue`, sowie die Filterlabels
  `filterAll` / `filterToImprove` / `filterImproved`.

### Verifikation Frontend
- `npm run build`; manueller Durchlauf: prüfen → verbessern (Bestätigung) →
  Eintrag zeigt „verbessert", wandert in die „Verbessert"-Liste und aus „Zu
  verbessern" → „Wieder aufnehmen" bringt ihn zurück.

## Cron (umgesetzt)

CLI-Einstieg `backend/cli/cron-improve.php` (nur über die Kommandozeile):
`php backend/cli/cron-improve.php --host=<domain> [--mounts=<datei>] [--limit=N] [--locale=de] [--dry-run]`.

`--dry-run` zeigt nur die Arbeitsliste (Pfad + Score) — ohne API-Aufruf, ohne
Schreiben, ohne Pro-/KI-Voraussetzung (dann ist `--host` nicht nötig). Zum
gefahrlosen Testen und zur Vorschau, was der Cron nehmen würde.

- `Connector::improveNextContent(limit, locale)` leitet die Arbeitsliste ab
  (`score < 100 && !improvedAt && !sourceMissing`), nimmt die ersten N Dateien
  und verbessert sie über `assistantService('auto')` + `improveInstruction`
  (Schreibmodus `auto`, kein Bestätigen). `runImprove` setzt bei erreichter
  Schrittgrenze bis zu 4 Züge fort.
- Der Schreibhaken (B2) setzt `improvedAt` → Datei fällt aus der Liste. Keine
  automatische Neuprüfung (Entscheidung 3). Prüfungen bleiben manuell.
- **Kein Web-Auth** (lokaler Aufruf), aber Pro-Lizenz/Hugo-Projekt/KI-Schlüssel
  vorausgesetzt. Da die Pro-Lizenz domänengebunden ist und `license()` die
  Domäne aus `$_SERVER['HTTP_HOST']` liest, setzt das Skript diese aus `--host`.
- Dafür wurden `audit()`/`contentQuality()` in gegatete Web-Einstiege und
  auth-freie Store-Builder (`auditStore()`/`contentQualityStore()`) getrennt;
  `assistantService()` nimmt einen Schreibmodus-Override.
- Exit-Codes: 0 Erfolg, 1 Laufzeitfehler (z. B. keine Pro-Lizenz), 2 Aufruffehler
  (fehlende Datei/Option).

Beispiel-Crontab (täglich 3 Uhr, 3 Dateien):
`0 3 * * *  php /pfad/backend/cron-improve.php --host=example.com --limit=3`

## Offene Punkte / bewusst später

- Score bleibt nach der Verbesserung der alte (Pre-Verbesserungs-)Wert, bis
  manuell neu geprüft wird — akzeptiert (Entscheidung 3).
- Ob „Wieder aufnehmen" zusätzlich eine Neuprüfung anbietet, bleibt offen; der
  Basisweg setzt nur `improvedAt` zurück.
