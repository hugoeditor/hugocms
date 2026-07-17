# Umsetzungsplan: Live-Analyse (seo-success `/v1/analyze`) im HugoCMS-Client

Status: API analysiert, Richtungsentscheidungen getroffen, noch nichts umgesetzt.
Die Gegenstelle läuft: `curl https://api.hugocms.com/v1/health` → `{"status":"ok"}`.

**Aktualisierung:** Die API liefert inzwischen die „ehrlichen Bausteine", die im
ersten Entwurf noch als Risiken offen standen — der Client muss sie nur nutzen:

- **Abbruch:** `POST /v1/analyze/<id>/cancel` bricht einen laufenden/wartenden
  Auftrag wirklich ab (`{status:"cancelled"}`). Der Knopf beendet also den Lauf,
  nicht nur die Anzeige.
- **Kein stilles Hängen:** `POST /v1/analyze` weist bei totem `audit-worker`
  sofort mit `503 WORKER-DOWN` ab (kein Job entsteht); ein bereits wartender Job
  wird im Status mit `stale:true` gekennzeichnet.
- **Kontingent vor dem Start:** `/v1/verify` liefert `quotaRemaining` — die
  Anzeige vor dem Start ist damit belegt (ein Lauf kostet bis zu 14 Einheiten).

Damit bleibt CMS-seitig im Kern nur die UI (ehrlicher Knopf-Text,
Kontingentanzeige). Der schmale Proxy-Anteil bleibt: eine Client-Methode und ein
Connector-Befehl für den Abbruch sowie die neuen Fehlercodes durchreichen.

## Ziel

Die job-basierte Webseiten-Analyse des seo-success-Gateways (`POST /v1/analyze`)
im Client nutzbar machen: Lauf anstoßen, Fortschritt verfolgen, Befunde,
Kennzahlen, Trend und Verlauf anzeigen, Bericht exportieren. Der Schlüssel bleibt
serverseitig; der Browser spricht nie direkt mit der API (sie sendet bewusst keine
CORS-Header).

## Ausgangslage — was bereits vorhanden ist

Die Integration beginnt nicht bei null; drei Bausteine tragen sie schon:

- **`SpeechClient.php`** spricht bereits mit `api.hugocms.com` (cURL, Bearer,
  `/v1/transcribe` + `/v1/verify`) und hat die vollständige Fehlerbehandlung.
- **`AuditView.vue`** ist eine Overlay-Ansicht mit Reitern (`report`, `content`,
  `pagespeed`), jeder über ein `auth`-Flag freigeschaltet — die Live-Analyse ist
  ein weiterer Reiter, kein neues Feature-Silo.
- **`[services] speech_key`/`speech_url`** in der `hugocms.ini` hält bereits den
  seo-success-Schlüssel. Es ist **derselbe Schlüssel mit demselben Kontingent**,
  den auch `/v1/analyze` verlangt.

Ebenfalls nutzbar: `AuditService::detectBaseUrl()` (Connector.php:521) erkennt die
Live-Adresse aus der Hugo-`baseURL`, `Config::updateSections()` schreibt
Mount-Sektionen, `persistPageSpeed()` (Connector.php:1672) zeigt das Muster für
eine „letztes Ergebnis je Webseite"-Ablage.

## Entschieden (Richtungsvorgaben des Nutzers)

1. **Ein Dienst, ein Schlüssel, neutrale Namen.** `[services] service_key` /
   `service_url`; `speech_key`/`speech_url` werden weiterhin als **Rückfall**
   gelesen, damit bestehende Installationen unverändert laufen.
2. **Voller Umfang in der ersten Stufe.** Anstoßen + Polling, Score/Note,
   Befundliste mit Fix-Hinweisen, Trend, Verlaufskurve, HTML-/CSV-Export,
   Kontingentanzeige. Die API liefert das alles fertig — der Client reicht durch
   und stellt dar.
3. **Live-Analyse und PageSpeed bleiben strikt getrennt.** Beide sind optional,
   der Benutzer wählt. Kein gemeinsamer Zustand, keine gemeinsamen Hauptzahlen,
   keine Verkürzung: Die Live-Analyse zeigt **alles**, was ihr Ergebnis hergibt —
   einschließlich des eigenen `browser`-Blocks aus dem chrome-sidecar. Der
   PageSpeed-Reiter bleibt unangetastet.

## Abgrenzung: drei unabhängige Prüfungen

| | Woher | Was | Voraussetzung |
|---|---|---|---|
| **SEO-Bericht** (`report`) | lokal, `site/public/*.html` nach dem Build | ~37 Regeln über die gebauten Dateien | Pro + Hugo-Projekt |
| **PageSpeed** (`pagespeed`) | Google PageSpeed Insights | CrUX-Echtnutzerdaten, Optimierungs-Chancen | Pro + Hugo-Projekt (Google-Schlüssel optional) |
| **Live-Analyse** (`live`, neu) | seo-success, Live-Crawl der Produktionssite | Crawl, Header, Server/TLS/DNS, Crawlability, eigener Lighthouse-Lauf, Trend | Pro + Hugo-Projekt + Dienst-Schlüssel |

Der lokale SEO-Bericht prüft, **was gebaut wurde**; die Live-Analyse prüft, **was
ausgeliefert wird** (inklusive Server, Zertifikat, DNS, robots/Sitemap) — die
Überschneidung ist gering und beabsichtigt.

## Was die API liefert

Alle Aufrufe mit `Authorization: Bearer <schlüssel>`; Fehler als
`{"error":"<CODE>","message":"…"}`.

| Aufruf | Antwort |
|---|---|
| `POST /v1/analyze` `{url}` | `202 {job_id, status:"queued"}`; ungültige Ziele sofort `400 URL-INVALID`/`URL-FORBIDDEN`; toter Worker sofort `503 WORKER-DOWN` (jeweils kein Job) |
| `GET /v1/analyze/<job_id>` | `{job_id, status}` — `queued`/`running`/`done`/`error`/`cancelled`; bei `done` `result`, bei `error` ein `error`-Klartext, bei `queued` ohne lebenden Worker zusätzlich `stale:true`, bei `cancelled` ggf. ein Teil-`result` |
| `POST /v1/analyze/<job_id>/cancel` | `{job_id, status:"cancelled"}`; bereits abgeschlossen → `409 JOB-NOT-CANCELABLE`; fremd/unbekannt → `404 NOT-FOUND` |
| `GET /v1/analyze/<job_id>?format=html\|csv` | eigenständiger HTML-Bericht bzw. CSV-Tabelle; vor Abschluss `409 JOB-NOT-READY` |
| `GET /v1/analyze/history?host=&limit=` | `{host, runs:[{id, created_at, score, grade, issue_count, by_severity}]}`, neueste zuerst |
| `GET /v1/verify` | `{valid, name, quotaLimit, quotaUsed, quotaRemaining, quotaExceeded}` |

`result` trägt: `start_url`, `host`, `pages_crawled`, `score` (0–100), `grade`
(A–F), `issues[]`, `summary{total, by_severity, by_type}`, `server`,
`crawlability`, `browser`, `trend`, `cost`. Jeder Befund hat `type`, `severity`
(`critical`/`warning`/`info`), `title`, `fix` und **entweder** `url` **oder**
`host`; die Liste ist nach Schweregrad sortiert.

**Kontingent:** Ein Lauf bucht `unit_base + Seiten × unit_per_page + (Browser? unit_browser)`
— voreingestellt `1 + 0,2/Seite + 3`. Eine 50-Seiten-Site mit Browser-Audit kostet
also **14** Einheiten. Der gebuchte Betrag steht als `result.cost` im Ergebnis.
Das rechtfertigt die Kontingentanzeige **vor** dem Start.

## Datenmodell (Client-Ablage)

Neu: `backend/var/analyze/<sha1(source)>.json` — je Webseite eine Datei, Muster wie
`var/pagespeed/`. Die Historie hält die API; der Client merkt sich nur den
jüngsten Lauf und einen etwaigen offenen Job:

```json
{
  "job":       { "id": "job_80f6c60972e1", "startedAt": "2026-07-16T13:30:00+00:00", "url": "https://example.com/" },
  "jobId":     "job_5ab3…",
  "analyzedAt":"2026-07-16T13:33:35+00:00",
  "result":    { "…": "Roh-result der API, unverändert" }
}
```

`job` ist der **offene** Lauf (sonst `null`) — dadurch nimmt das Panel das Polling
nach einem Neuladen der Seite wieder auf, statt den Lauf zu verlieren. `jobId`
gehört zum angezeigten `result` und wird für den Export gebraucht.

## Backend

### B1 — `SpeechClient` → `SeoSuccessClient`
`backend/core/SpeechClient.php` wird zu `SeoSuccessClient.php` (Klasse
`SeoSuccessClient`). Grund: Das Haus-Muster ist **eine Client-Klasse je externem
Dienst** (`AnthropicClient` = Claude, `PageSpeedClient` = Google) — seo-success
liefert nun Sprache *und* Analyse aus einer Hand; zwei Klassen für einen Dienst
würden `endpoint()`/`send()` doppeln. Aufrufstellen sind nur Connector.php:1491
und :1537.

Neue Methoden neben `transcribe()`/`verify()`:
```php
public function analyzeStart(string $url): array            // POST /v1/analyze → {job_id, status}
public function analyzeStatus(string $jobId): array         // GET  /v1/analyze/<id>
public function analyzeCancel(string $jobId): array         // POST /v1/analyze/<id>/cancel → {job_id, status:"cancelled"}
public function analyzeHistory(string $host, int $limit): array
public function analyzeExport(string $jobId, string $format): array  // {body, contentType} — roh, kein json_decode
```
`send()` bekommt die Fehler-Familie als Parameter (`SPEECH` bzw. `ANALYZE`), damit
die vorhandenen `SPEECH-*`-Codes und die neuen `ANALYZE-*`-Codes aus demselben
Rumpf fallen. Zusätzlich ein `sendRaw()` für den Export (liefert Bytes, kein JSON).

Fehler-Abbildung für die Analyse: 401 → `ANALYZE-AUTH-FAILED`, 429/`QUOTA-EXCEEDED`
→ `ANALYZE-QUOTA-EXCEEDED`, 400 `URL-INVALID`/`URL-FORBIDDEN` → gleichnamige
`ANALYZE-*`-Codes, 404 → `ANALYZE-JOB-NOT-FOUND`, 409 `JOB-NOT-READY` →
`ANALYZE-JOB-NOT-READY`, 409 `JOB-NOT-CANCELABLE` → `ANALYZE-JOB-NOT-CANCELABLE`,
503 `WORKER-DOWN` → `ANALYZE-WORKER-DOWN`, Transportfehler → `ANALYZE-UNREACHABLE`,
sonst `ANALYZE-REQUEST-FAILED`. Das `stale`-Flag ist kein Fehler, sondern ein
Feld der 200-Statusantwort — es wird durchgereicht, nicht geworfen.

### B2 — Konfiguration: neutrale Schlüsselnamen mit Rückfall
`Config::servicesSection()` (Config.php:337-349) liest künftig
`service_key ?? speech_key` und `service_url ?? speech_url` und liefert
`serviceKey`/`serviceUrl`/`pagespeedKey`. Umzubenennen sind die Nutzungen in
Connector.php: Feld-Deklaration :92, Zuweisung :192, `cmdSpeech` :1463/:1491-1493,
`cmdSpeechVerify` :1525/:1532, `cmdWhoami` :576-577, `cmdConfig` :2013-2014.

`cmdReconfigure` (:2091-2120) schreibt `service_key`/`service_url`. Angenehmer
Nebeneffekt: `Config::updateSections()` ersetzt eine benannte Sektion **vollständig**
(Config.php:414-425) — beim ersten Speichern verschwinden die alten
`speech_*`-Zeilen von selbst. Bis dahin trägt der Rückfall.

Erster Nutznießer ist die eigene Entwicklungsumgebung: Die lokale
`backend/hugocms.ini` trägt den (gültigen) Schlüssel noch als `[services]
speech_key`/`speech_url` → api.hugocms.com. Der Rückfall ist also von der ersten
Zeile Code an nötig, nicht bloß Vorsorge für Bestandsinstallationen — und liefert
zugleich den Testschlüssel für die Ende-zu-Ende-Prüfung.

`backend/hugocms.ini.beispiel` (:118-136): `[services]` auf die neuen Namen
umstellen, den Zweck („Spracheingabe **und** Live-Analyse — ein Schlüssel, ein
Kontingent") benennen und den Rückfall dokumentieren.

### B3 — Live-Adresse pro Webseite
Neue reservierte Mount-Sektion `[live_analysis] url` (analog `[pagespeed] url`,
aber **eigen** — die beiden Prüfungen teilen keinen Zustand). Feld
`$liveAnalysisUrl`, gelesen wie `$pagespeedUrl` (Connector.php:100), beim
Analysestart über `Config::updateSections($this->mountsPath, …)` geschrieben.

Die **Erkennung** aus der Hugo-`baseURL` wird geteilt, nicht der gespeicherte Wert:
`AuditService::detectBaseUrl()` läuft in `cmdWhoami` bereits einmal (:521). Das
neue whoami-Feld `siteUrlDetected` trägt diesen Wert — es ist eine Eigenschaft der
Webseite, nicht des PageSpeed-Checks — und belegt beide Panels vor.
`mounts.ini.beispiel` um die neue Sektion ergänzen (Muster :76-83).

> **Umgesetzt (Abweichung, bewusst):** `siteUrlDetected` wurde **additiv**
> eingeführt, statt `pagespeedUrlDetected` umzubenennen. So bleibt Block 2 ohne
> Bestandsrisiko — die PageSpeed-Vorbelegung läuft unverändert weiter. Beide Felder
> tragen denselben Wert. Das neue Live-Analyse-Panel nutzt `siteUrlDetected`; das
> alte `pagespeedUrlDetected` kann später (Aufräumen) entfallen, wenn auch der
> PageSpeed-Reiter auf das gemeinsame Feld zeigt. Per Smoke-Test bestätigt:
> `siteUrlDetected` und `pagespeedUrlDetected` liefern beide die baseURL.

### B4 — Neue Connector-Befehle (`match`-Block, Connector.php:368-432)
- `liveanalyze` (POST `{url}`) — Guards `requireAuth`/`requireMethod('POST')`/
  `requirePro`, Dienst konfiguriert, Hugo-Projekt vorhanden; `isPublicHttpUrl()`
  (:1639) als Vorprüfung; URL in `[live_analysis]` merken; `analyzeStart()`;
  offenen Job ablegen; `{jobId, status}` zurück.
- `liveanalyzestatus` (GET `{jobId}`) — `analyzeStatus()` durchreichen, inklusive
  `stale`-Flag; bei `done` Ergebnis + `analyzedAt` ablegen und den offenen Job
  löschen; bei `error`/`cancelled` den offenen Job ebenso aufräumen (bei
  `cancelled` mit Teil-`result`, falls vorhanden — nicht als jüngstes Ergebnis
  ablegen, es ist unvollständig).
- `liveanalyzecancel` (POST `{jobId}`) — Guards wie oben; `analyzeCancel()`
  durchreichen; offenen Job aufräumen. `409 JOB-NOT-CANCELABLE` (Rennen mit dem
  Worker: gerade fertig geworden) ist kein harter Fehler — der Status-Poll holt
  dann das fertige Ergebnis.
- `liveanalyzelatest` (GET) — abgelegtes Ergebnis **und** offener Job (damit das
  Panel nach dem Öffnen/Neuladen sofort anzeigt bzw. weiterpollt).
- `liveanalyzehistory` (GET `{limit}`) — `analyzeHistory()` für den Host der
  gespeicherten Adresse.
- `liveanalyzeexport` (GET `{jobId, format}`) — liefert Bytes am JSON-Umschlag
  vorbei, mit `Content-Disposition` (Muster `cmdDownload`/`cmdRaw`).
- `speechverify` → **`serviceverify`** umbenennen: Derselbe `/v1/verify`-Aufruf
  bedient jetzt den Konfigurationsdialog **und** die Kontingentanzeige der
  Live-Analyse; der alte Name führt in die Irre.

`cmdSpeech` behält seinen Namen — es bleibt die Sprach-Operation.

### B5 — Ablage
`analyzeStorePath()` + `persistAnalyze()`/`readAnalyze()` nach dem Vorbild von
`pagespeedStorePath()`/`persistPageSpeed()` (Connector.php:1654-1687): Pfad
`var/analyze/<sha1(source)>.json`, Schreiben als *best effort* — ein
fehlgeschlagener Schreibvorgang bricht den Lauf nicht ab.

### B6 — Freischaltung
In `cmdWhoami` (bei :582) ergänzen:
```php
'liveAnalysis' => $this->hugo !== null
    && $this->license()->isPro()
    && $this->services['serviceKey'] !== null
    && $this->services['serviceUrl'] !== null,
'liveAnalysisUrl' => $this->liveAnalysisUrl ?? '',
```
Anders als `pagespeed` verlangt die Live-Analyse zwingend den Dienst-Schlüssel.

## Frontend

### F1 — Eigener Store `stores/liveAnalysis.js`
Bewusst **nicht** in `audit.js` (dort liegt schon der PageSpeed-Zustand) — getrennte
Prüfungen, getrennte Stores. Options-Store wie die übrigen acht.

State: `result`, `jobId`, `job`, `status`, `running`, `stale`, `history`, `quota`,
`severityFilter`, `typeFilter`.
Actions: `fetchLatest()`, `start(url)`, `poll()`, `cancel()`, `stopPolling()`,
`fetchHistory()`, `fetchQuota()`.

`cancel()` ruft `liveanalyzecancel`, beendet das Polling und leert den offenen
Job — der Lauf ist serverseitig wirklich gestoppt. `stopPolling()` bleibt für den
lokalen Fall (Ansicht schließen, `onBeforeUnmount`): es beendet nur die Schleife,
ohne die API zu behelligen. Die beiden sind bewusst getrennt.

`poll()` wertet zwei neue Felder aus: `stale:true` (Job hängt, kein Worker) blendet
einen Hinweis ein, ohne das Polling abzubrechen; `WORKER-DOWN` beim `start()`
verhindert den Lauf gar nicht erst und zeigt denselben Hinweis.

**Polling — der einzige Teil ohne Vorbild im Code.** Es gibt bisher keinen einzigen
`setInterval`/Poll-Pfad; das Muster ist neu und die Hauptstelle zum Sorgfältigsein:

- rekursives `setTimeout`, kein `setInterval` (keine Überlappung bei langsamer
  Antwort),
- 2 s Takt, nach 60 s auf 5 s strecken,
- harte Obergrenze 10 min → Abbruch mit Hinweis und „weiter warten"-Knopf,
- Aufräumen in `onBeforeUnmount` und beim Schließen der Ansicht,
- `markRaw(result)` wie in `audit.js:56-58` (reine Anzeige, potenziell tausende
  Befunde).

Das Backend bleibt dabei zustandslos: Jeder Poll ist ein kurzer Request, kein
php-fpm-Worker blockiert — genau dafür ist die API job-basiert.

### F2 — `components/LiveAnalysisPanel.vue`
Neuer Reiter-Inhalt. Zeigt das Ergebnis **vollständig**, in dieser Reihenfolge:

1. **Kopf** — Adressfeld (vorbelegt aus `auth.liveAnalysisUrl` bzw.
   `auth.siteUrlDetected`), „Analyse starten", Restkontingent (aus `quota`, mit
   Hinweis „ein Lauf kostet bis zu 14 Einheiten"), „zuletzt geprüft". Ist das
   Kontingent zu knapp oder erschöpft, wird „Analyse starten" gesperrt statt in
   `ANALYZE-QUOTA-EXCEEDED` zu laufen.
   **Unbegrenztes Kontingent** (`quotaLimit`/`quotaRemaining = null`, im Test der
   Regelfall) ist ausdrücklich zu behandeln: dann keine Zahl anzeigen („unbegrenzt")
   und **nie** sperren. Nur bei gesetztem Limit vergleichen.
2. **Laufanzeige** — `queued`/`running` + Spinner + Knopf **„Analyse abbrechen"**
   (nicht „Anzeige beenden" — der Abbruch ist jetzt echt, `cancel()` stoppt den
   Lauf serverseitig). Bei `stale:true` bzw. `WORKER-DOWN` ein deutlicher Hinweis,
   dass der Analyse-Dienst gerade nicht arbeitet — kein stummes Warten mehr.
3. **Kennzahlen** — Score/Note als Ampelring (Idiom aus `PageSpeedPanel.vue:271-277`),
   `pages_crawled`, `cost` des Laufs, `summary.by_severity`.
4. **Trend** — `delta_score`, `previous_score`/`previous_grade`, Listen `resolved`
   und `new`; beim ersten Lauf der `first_run`-Hinweis.
5. **Verlaufskurve** — Score über die Läufe aus `history`.
6. **Befundliste** — Filter nach Schweregrad und Typ; je Befund Titel, `fix`,
   Ort (`url` oder `host`) und Zusatzfelder.
7. **`server`** — IPv4, SPF, DMARC, Zertifikat (Aussteller, `valid_to`,
   `days_left`), HTTP-Version, HTTPS-Redirect, www-Konsistenz.
8. **`crawlability`** — robots.txt, Sitemap, Titel, Meta-Description, Canonical,
   JSON-LD-Anzahl, og:image, Third-Party-Inventar.
9. **`browser`** — Lighthouse-Scores (performance, accessibility, best_practices,
   seo) + Metriken (lcp_ms, cls, tbt_ms); bei `available:false` der Hinweis, dass
   der chrome-sidecar aus ist (fail-open, der Rest bleibt gültig).
10. **Export** — HTML (neuer Tab, druckbar) und CSV (Download) über `api.url()`.

**Verlaufskurve:** handgeschriebenes Inline-SVG (`<polyline>`, ~30 Zeilen), keine
neue Abhängigkeit. Das Projekt hat bis heute **keine** Diagramm-Bibliothek und
**kein** `<svg>`; `PageSpeedPanel` malt mit CSS-Balken. Eine Kurve über die Zeit
mit CSS-Balken nachzubauen wäre schlechter als 30 Zeilen SVG — die Alternative
(Chart-Bibliothek) steht in keinem Verhältnis zu einem Diagramm.

`AuditSeverityChip.vue` (36 Zeilen) wird um `critical`/`info` erweitert; es ist
eine reine Anzeigekomponente, das Teilen koppelt die Features nicht.

### F3 — Reiter in `AuditView.vue`
Reiterleiste :166-176 um `live` ergänzen (`v-if="auth.liveAnalysis"`, Icon z. B.
`mdi-radar`), Inhaltsumschaltung :293-323, Fußzeile :326-329. Die Leiste ist heute
`v-if="auth.auditContent || auth.pagespeed"` — Bedingung um `auth.liveAnalysis`
erweitern.

### F4 — Konfigurationsdialog
`ReconfigureDialog.vue`: Abschnitt `speech` → `service` (Registry :86-91),
Überschrift „Dienst (Spracheingabe + Live-Analyse)", `DEFAULT_SPEECH_URL` →
`DEFAULT_SERVICE_URL` (:45), Felder `serviceKey`/`serviceUrl`. Die Hausregeln
bleiben: Feld startet leer, **leer = unverändert**, der Schlüssel kommt nie zum
Client zurück. Die Prüfung vor dem Speichern (:152-153) läuft künftig über
`auth.verifyService` → `serviceverify` und zeigt zusätzlich das Kontingent.

### F5 — i18n (`de.js` **und** `en.js`)
Neuer Namensraum `liveAnalysis` (flach, Muster wie `audit:` in de.js:287-401):
Reiter/Knöpfe/Status (inkl. „Analyse abbrechen"), der Worker-/`stale`-Hinweis,
`severity.{critical,warning,info}`, `trend.*`, `server.*`, `crawlability.*`,
`browser.*`, `export.*` sowie die Fehlerschlüssel `ANALYZE-*` (inkl.
`ANALYZE-WORKER-DOWN`, `ANALYZE-JOB-NOT-CANCELABLE`).

**Die i18n-Grenze — gelöst auf der API-Seite (überholt die frühere Planung).**
Der Dienst lokalisiert die Befunde inzwischen selbst (`Audit/Messages.php`,
Commit „Reporting überarbeitet (i18n-Support)"):

- `POST /v1/analyze` nimmt `lang` (am Auftrag gemerkt), `GET /v1/analyze/<id>`
  nimmt `?lang=` als Override — **auch fürs JSON** (`localizeResult`), nicht nur
  für den Export. Der Export trägt zusätzlich `Content-Language`.
- `Messages::localizeIssues()` übersetzt über den **sprachneutralen `type`** und
  zwar **beim Abruf**, mit Rückfall auf den deutschen Standard.
  `Messages::SUPPORTED = ['de','en']`.

**Daraus folgt: Der Client übersetzt die Befundtexte NICHT.** Er reicht nur die
Oberflächensprache durch (`locale` → `lang`) und zeigt `issue.title`/`issue.fix`
direkt. Gründe:

1. **Eine Quelle der Wahrheit.** Der Katalog liegt dort, wo die Typen entstehen.
   Ein zweiter Katalog im Client wäre Doppelpflege mit Divergenzrisiko.
2. **Der Export ist automatisch konsistent** — genau das konnte eine
   Client-Übersetzung prinzipiell nicht erreichen.
3. **Das frühere Gegenargument ist ausgeräumt.** Es lautete: „Der Text wird zur
   Analysezeit eingebrannt, ein Sprachwechsel zeigt weiter Deutsch, Neuberechnung
   kostet Kontingent." Die API lokalisiert **beim Abruf**, nicht beim Analysieren
   — derselbe Auftrag lässt sich in jeder Sprache holen, ohne neuen Lauf und ohne
   Kontingent. Ein Sprachwechsel im CMS ist damit ein reiner Neu-Abruf.

> **Umgesetzt:** `SeoSuccessClient::analyzeStart/analyzeStatus/analyzeExport`
> nehmen `$lang`; `Connector::analyzeLang()` normalisiert `locale` auf die
> zweistellige Kennung (Muster wie `cmdPageSpeed`); Store/Panel senden `locale`
> mit; `refetchInLocale()` holt das angezeigte Ergebnis bei Sprachwechsel neu
> (best effort, „zuletzt geprüft" bleibt stehen — es wurde nichts neu geprüft).
> Der geplante `rules`-Katalog im Client **entfällt ersatzlos**.
>
> Verifiziert gegen die echte API mit einem bestehenden Auftrag: gleicher `type`,
> `title`/`fix` wechseln de↔en, Export `<html lang="de">` bzw. `"en"` — ohne
> neuen Lauf.

## Betrieb / Doku

### O1 — README
Abschnitt „Live-Analyse": Voraussetzungen (Pro, Dienst-Schlüssel, öffentlich
erreichbare Site), `[services] service_key`/`service_url` samt Rückfall,
`[live_analysis] url`, Kontingentmodell (`1 + 0,2/Seite + 3`), Abgrenzung zu
SEO-Bericht und PageSpeed.

## Gelöst (API liefert die Bausteine — nur noch nutzen)

- **Serverseitiger Abbruch** über `POST /v1/analyze/<id>/cancel`. Der Knopf heißt
  jetzt zu Recht „Analyse abbrechen" und stoppt den Lauf wirklich (`cancel()` im
  Store). Nur beim Schließen der Ansicht wird ausschließlich lokal gestoppt
  (`stopPolling()`).
- **Kein stilles Hängen.** Toter `audit-worker` → `503 WORKER-DOWN` schon beim
  Start (kein Job); ein wartender Job trägt `stale:true` im Status. Beides blendet
  im Panel einen klaren Hinweis ein.
- **Kontingent vor dem Start** über `quotaRemaining` aus `/v1/verify` — Anzeige im
  Kopf, „Analyse starten" gesperrt, wenn es nicht reicht (bis zu 14 Einheiten/Lauf).

## Offene Punkte / Risiken

1. **Laufzeit.** 50 Seiten plus Lighthouse dauern Minuten. Die Obergrenze von
   10 min ist eine Annahme — beim ersten Ende-zu-Ende-Lauf gegen eine echte Site
   prüfen und ggf. anheben.
2. **Kontingent-Behandlung.** Die Anzeige vor dem Start ist gelöst (`quotaRemaining`).
   Bleibt die Fehlerbehandlung: `ANALYZE-QUOTA-EXCEEDED` heißt „bis zum nächsten
   Kalendermonat sperren", nicht „gleich nochmal" — nach diesem Code nicht
   automatisch neu anstoßen.
3. **Historie ist je Schlüssel + Host.** Zwei HugoCMS-Installationen mit demselben
   Schlüssel und demselben Host teilen sich eine Verlaufskurve. Für den Regelfall
   (ein Schlüssel je Kunde) unkritisch, aber zu wissen.
4. **SSRF-Prüfung liegt bei der API.** Die lokale `isPublicHttpUrl()` prüft nur
   Schema und Host; die verbindliche Prüfung (DNS-Auflösung, private Bereiche)
   macht das Gateway und weist sofort ab. Das ist die richtige Arbeitsteilung —
   nicht im Client nachbauen.

## Reihenfolge / Verifikation

1. **B2** Config (Rückfall) + **B1** Client — `php -l`; Wegwerf-Skript gegen den
   Autoloader: `/v1/verify` und ein echter `/v1/analyze`-Lauf mit Polling gegen
   `api.hugocms.com`. Der Schlüssel steht in der lokalen `hugocms.ini`
   (`[services] speech_key`) — Rückfall vor dem ersten Lauf prüfen.
2. **B3** Mount-URL, **B5** Ablage, **B4** Befehle, **B6** Flag — `php -l` je Datei.
3. **F1** Store + **F2** Panel + **F3** Reiter + **F4** Dialog + **F5** i18n —
   `npm run --prefix frontend build`.
4. **O1** README.
5. **Ende-zu-Ende** im laufenden System: Lauf anstoßen, Neuladen während des Laufs
   (Polling muss wieder aufnehmen), **Abbruch mitten im Lauf** (Job wird
   serverseitig `cancelled`, kein weiteres Kontingent), Ergebnis, Trend beim
   zweiten Lauf, HTML-/CSV-Export, Verhalten bei erschöpftem Kontingent
   (`quotaRemaining` sperrt den Start) und bei gestopptem `audit-worker`
   (`WORKER-DOWN` beim Start, `stale` beim Poll).
