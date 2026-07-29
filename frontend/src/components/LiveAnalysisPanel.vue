<script setup>
// Live-Analyse (Pro, seo-success) — eigener Reiter im SEO-Check. Stößt einen
// Live-Crawl der Produktionssite an, pollt den Job und zeigt das Ergebnis
// VOLLSTÄNDIG: Score/Note, Trend, Verlaufskurve, Befunde, Server-, Crawlability-
// und Browser-Block sowie Export. Strikt getrennt von PageSpeed — beide optional,
// der Benutzer wählt.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLiveAnalysisStore } from '../stores/liveAnalysis'
import { useAuthStore } from '../stores/auth'
import { api } from '../api/client'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'

const { t, te, locale } = useI18n()
const live = useLiveAnalysisStore()
const auth = useAuthStore()
const error = useTransientError()

// Zu prüfende Adresse: gespeicherte Adresse dieser Webseite, sonst die aus der
// Hugo-baseURL erkannte. Beim Start wird sie serverseitig gespeichert.
const url = ref(auth.liveAnalysisUrl || auth.siteUrlDetected || '')

// Nur absolute http(s)-Adressen sind prüfbar (der Dienst ruft sie selbst ab).
const canRun = computed(() => /^https?:\/\/.+/i.test(url.value.trim()))

onMounted(async () => {
  try {
    await live.fetchLatest()
  } catch {
    // Kein gespeichertes Ergebnis / Ladefehler: Panel bleibt leer, unkritisch.
  }
  // Restkontingent für die Anzeige vor dem Start (best effort).
  live.fetchQuota().catch(() => {})
})

// Poll-Fehler laufen über den Store-Zustand (die Schleife läuft im Hintergrund);
// hier in die zeitbegrenzte Fehleranzeige übernehmen.
watch(
  () => live.error,
  (e) => {
    if (e) error.value = errorText(t, e)
  },
)

// Sprachwechsel: Das angezeigte Ergebnis in der neuen Sprache neu holen. Der
// Dienst übersetzt beim Abruf über den Befund-Typ — derselbe Auftrag, kein
// neuer Lauf, kein Kontingent. „zuletzt geprüft" bleibt dabei stehen.
watch(locale, (l) => {
  live.refetchInLocale(l)
})

// Beim Verlassen der Ansicht nur lokal aufhören — der Lauf bleibt serverseitig
// bestehen und wird beim nächsten Öffnen (fetchLatest) wieder aufgenommen.
onBeforeUnmount(() => live.stopPolling())

const lastAnalyzed = computed(() =>
  live.analyzedAt ? new Date(live.analyzedAt).toLocaleString() : null,
)

// Hinweis, warum der Lauf endete. `stop_reason` ist die präzise Auskunft des
// Dienstes; „completed" ist der Normalfall und braucht keinen Hinweis. Ergebnisse
// aus der Zeit vor diesem Feld fallen auf `reached_page_limit` zurück.
const stopNote = computed(() => {
  const reason = live.result?.stop_reason
  if (reason && reason !== 'completed') {
    const key = `liveAnalysis.stopReason.${reason}`
    return te(key) ? t(key) : reason
  }
  if (!reason && live.result?.reached_page_limit) return t('liveAnalysis.pageLimitReached')
  return null
})

async function startAnalyse() {
  error.value = null
  try {
    await live.start(url.value.trim(), locale.value)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

async function cancelAnalyse() {
  try {
    await live.cancel()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// Note-Farbe (A/B gut, C/D mittel, F schlecht) — für Score-Kachel und Kurve.
function gradeClass(grade) {
  if (grade === 'A' || grade === 'B') return 'good'
  if (grade === 'C' || grade === 'D') return 'medium'
  return 'poor'
}

// Schweregrad → Farbe/Icon (inline, eigener Namensraum — nicht mit dem Audit-
// Chip geteilt, damit die Features getrennt bleiben).
const SEV = {
  critical: { cls: 'sev-critical', icon: 'mdi-alert-circle-outline' },
  warning: { cls: 'sev-warning', icon: 'mdi-alert-outline' },
  info: { cls: 'sev-info', icon: 'mdi-information-outline' },
}
function sevMeta(sev) {
  return SEV[sev] ?? SEV.info
}

// Befund-Texte kommen fertig lokalisiert vom Dienst: Er übersetzt sie über den
// sprachneutralen `type` beim Abruf (mit eigenem Rückfall auf Deutsch, wenn ein
// Typ dort noch nicht übersetzt ist). Der Client übersetzt sie bewusst NICHT
// parallel — ein Katalog an zwei Orten wäre eine zweite Quelle der Wahrheit und
// würde den HTML-/CSV-Export ohnehin nicht erreichen.
function issueLocation(issue) {
  return issue.url || issue.host || ''
}

// Zusatzfelder eines Befunds (alles außer den überall vorhandenen Standard-
// feldern) — z. B. `source` (Seite, auf der ein toter Link steht), `status`
// (HTTP-Code), `variant`, `bytes`, `count`. Generisch wie der Bericht des
// Dienstes (ReportExport::detail), damit auch künftige Befundtypen ihre Details
// zeigen, ohne dass der Client nachziehen muss. Bekannte Schlüssel bekommen eine
// lesbare Beschriftung, unbekannte den Rohnamen.
const ISSUE_STD_FIELDS = ['type', 'severity', 'title', 'fix', 'url', 'host']
function issueDetails(issue) {
  return Object.entries(issue)
    .filter(([k]) => !ISSUE_STD_FIELDS.includes(k))
    .map(([k, v]) => ({
      key: k,
      label: te(`liveAnalysis.issueField.${k}`) ? t(`liveAnalysis.issueField.${k}`) : k,
      value: Array.isArray(v) ? v.join(', ') : String(v),
    }))
}

const SEVERITIES = ['critical', 'warning', 'info']

// Befundtypen mit Anzahl (aus summary.by_type), häufigste zuerst — treibt den
// Typ-Filter neben dem Schweregrad-Filter.
const typeCounts = computed(() => {
  const byType = live.result?.summary?.by_type ?? {}
  return Object.entries(byType)
    .map(([type, count]) => ({ type, count }))
    .sort((a, b) => b.count - a.count)
})

// Browser-Kennwerte in Anzeigereihenfolge: erst die drei Core Web Vitals, dann
// die weiteren Ladekennwerte. Alle sechs liefert der chrome-sidecar; einzelne
// können null sein (Lighthouse konnte sie nicht messen).
const BROWSER_METRICS = [
  { key: 'lcp_ms', unit: ' ms' },
  { key: 'cls', unit: '' },
  { key: 'tbt_ms', unit: ' ms' },
  { key: 'fcp_ms', unit: ' ms' },
  { key: 'si_ms', unit: ' ms' },
  { key: 'tti_ms', unit: ' ms' },
]

// Diagnose-Kennwerte in Anzeigereihenfolge (Serverantwort, Seitengewicht,
// DOM-Größe, JS-Ausführungs- und Hauptthread-Zeit). Der Sidecar liefert je
// Kennwert Wert + lesbare Darstellung; fehlende lässt die Anzeige aus.
const BROWSER_DIAGNOSTICS = [
  'server-response-time', 'total-byte-weight', 'dom-size', 'bootup-time', 'mainthread-work-breakdown',
]

// Vorhandene Diagnose-Kennwerte in fester Reihenfolge, als { key, display }.
const browserDiagnostics = computed(() => {
  const diag = live.result?.browser?.diagnostics
  if (!diag) return []
  return BROWSER_DIAGNOSTICS
    .filter((key) => diag[key])
    .map((key) => ({ key, display: diag[key].display || String(diag[key].value) }))
})

// Optimierungs-Chancen (bereits nach Wirkung sortiert und gekappt vom Dienst).
const browserOpportunities = computed(() => live.result?.browser?.opportunities ?? [])

// Kompakte Byte-Angabe (KiB/MiB) für die Verursacher einer Chance.
function fmtBytes(n) {
  if (typeof n !== 'number' || !Number.isFinite(n)) return ''
  if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MiB'
  if (n >= 1024) return Math.round(n / 1024) + ' KiB'
  return n + ' B'
}

// Ersparnis-Text einer Chance: bevorzugt Lighthouses eigene Angabe, sonst aus
// den Zahlen zusammengesetzt (Zeit und/oder Datenmenge).
function opportunitySavings(o) {
  if (o.display) return o.display
  const parts = []
  if (o.savings_ms >= 1) parts.push(o.savings_ms + ' ms')
  if (o.savings_bytes) parts.push(fmtBytes(o.savings_bytes))
  return parts.join(' · ')
}

// CrUX-Felddaten (echte Nutzererfahrung): Core Web Vitals in fester Reihenfolge
// mit Einheit und Nachkommastellen für die p75-Anzeige. LCP/FCP in Sekunden,
// INP/TTFB in Millisekunden, CLS einheitenlos.
const FIELD_METRICS = [
  { key: 'lcp', unit: 's', factor: 0.001, digits: 2 },
  { key: 'inp', unit: 'ms', factor: 1, digits: 0 },
  { key: 'cls', unit: '', factor: 1, digits: 2 },
  { key: 'fcp', unit: 's', factor: 0.001, digits: 2 },
  { key: 'ttfb', unit: 'ms', factor: 1, digits: 0 },
]

// Felddaten-Block, sobald der Dienst ihn liefert (CrUX freigeschaltet). Fehlt
// er (kein Zentralschlüssel/Feature aus), bleibt er null und die Anzeige leer.
const fieldData = computed(() => live.result?.browser?.field_data ?? null)

// Vorhandene Metriken einer Ebene (url|origin) in fester Reihenfolge, je Zeile
// Anzeigekonfiguration + p75/Verteilung. null, wenn die Ebene keine Daten hat
// (CrUX hatte für dieses Ziel zu wenig Verkehr).
function fieldLevel(level) {
  const lvl = fieldData.value?.[level]
  if (!lvl || typeof lvl.metrics !== 'object' || lvl.metrics === null) return null
  const rows = FIELD_METRICS
    .filter((m) => lvl.metrics[m.key])
    .map((m) => ({ ...m, ...lvl.metrics[m.key] }))
  return rows.length ? rows : null
}
const fieldUrl = computed(() => fieldLevel('url'))
const fieldOrigin = computed(() => fieldLevel('origin'))

// p75 einer Feldmetrik lesbar: Faktor + Nachkommastellen + Einheit. „—" ohne Wert.
function fmtFieldValue(row) {
  if (row.p75 === null || row.p75 === undefined) return '—'
  return (row.p75 * row.factor).toFixed(row.digits) + (row.unit ? ' ' + row.unit : '')
}

// Prozentbreite eines Verteilungssegments (Dichte 0..1 → „72%").
function fieldPct(density) {
  return Math.round((density || 0) * 100) + '%'
}

// Export-Adressen (server-seitig, am JSON-Umschlag vorbei). HTML im neuen Tab
// zum Drucken, CSV als Download. `locale` lokalisiert auch den Bericht selbst
// (Befundtexte und Beschriftungen) — das leistet nur der Dienst.
function exportUrl(format) {
  return live.resultJobId
    ? api.url('liveanalyzeexport', { jobId: live.resultJobId, format, locale: locale.value })
    : '#'
}

// Verlaufskurve: Score (0–100) über die Läufe, älteste links. Handgezeichnetes
// SVG (keine Diagramm-Abhängigkeit im Projekt).
const CURVE_W = 480
const CURVE_H = 90
const CURVE_PAD = 6
const curvePoints = computed(() => {
  const runs = [...live.history].reverse() // API liefert neueste zuerst
  if (runs.length < 2) return null
  const n = runs.length
  const innerW = CURVE_W - 2 * CURVE_PAD
  const innerH = CURVE_H - 2 * CURVE_PAD
  return runs.map((r, i) => {
    const x = CURVE_PAD + (n === 1 ? 0 : (i / (n - 1)) * innerW)
    const y = CURVE_PAD + (1 - Math.max(0, Math.min(100, r.score)) / 100) * innerH
    return { x, y, run: r }
  })
})
const curveLine = computed(() =>
  curvePoints.value ? curvePoints.value.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ') : '',
)

function fmtDate(iso) {
  return iso ? new Date(iso).toLocaleDateString() : ''
}
</script>

<template>
  <div class="la-panel">
    <v-alert
      v-if="error"
      type="error"
      density="compact"
      class="mb-3 nemo-alert"
      tile
      closable
      @click:close="error = null"
    >
      {{ error }}
    </v-alert>

    <!-- 1) Kopf: Adresse, Start, Restkontingent, zuletzt geprüft -->
    <div class="la-head">
      <v-text-field
        v-model="url"
        :label="$t('liveAnalysis.urlLabel')"
        :placeholder="$t('liveAnalysis.urlPlaceholder')"
        prepend-inner-icon="mdi-web"
        density="compact"
        variant="outlined"
        hide-details
        class="la-url"
      />
      <button
        class="la-btn primary"
        :disabled="!canRun || live.running || live.quotaExceeded"
        @click="startAnalyse"
      >
        <v-progress-circular v-if="live.running" indeterminate size="14" width="2" class="mr-1" />
        <v-icon v-else icon="mdi-radar" size="16" class="mr-1" />{{ $t('liveAnalysis.run') }}
      </button>
    </div>

    <div class="la-subhead">
      <!-- Der Hinweis gilt nur bei begrenztem Kontingent: Der Dienst leitet daraus
           das Seitenbudget ab, ein Lauf kostet also nie mehr als das Restguthaben.
           Bei unbegrenztem Schlüssel greift stattdessen der Backstop des Dienstes. -->
      <span v-if="live.quota">
        <template v-if="live.quota.quotaRemaining === null">{{ $t('liveAnalysis.quotaUnlimited') }}</template>
        <template v-else>
          {{ $t('liveAnalysis.quotaRemaining', [live.quota.quotaRemaining]) }}
          · {{ $t('liveAnalysis.quotaHint') }}
        </template>
      </span>
      <span v-if="lastAnalyzed" class="la-muted">· {{ $t('liveAnalysis.lastAnalyzed', [lastAnalyzed]) }}</span>
      <span v-if="live.quotaExceeded" class="la-warn">· {{ $t('liveAnalysis.quotaExceeded') }}</span>
    </div>

    <!-- 2) Laufanzeige: Status + echter Abbruch + stale/Worker-Hinweis -->
    <div v-if="live.running" class="la-running">
      <v-progress-circular indeterminate size="20" width="2" color="primary" />
      <span>{{ $t('liveAnalysis.status.' + (live.status || 'queued')) }}</span>
      <button class="la-btn danger" @click="cancelAnalyse">
        <v-icon icon="mdi-stop" size="16" class="mr-1" />{{ $t('liveAnalysis.cancel') }}
      </button>
      <span v-if="live.stale" class="la-warn">{{ $t('liveAnalysis.workerDown') }}</span>
    </div>
    <div v-else-if="live.timedOut" class="la-running">
      <span class="la-warn">{{ $t('liveAnalysis.timedOut') }}</span>
      <button class="la-btn" @click="live.resume()">{{ $t('liveAnalysis.keepWaiting') }}</button>
    </div>

    <!-- Ergebnis -->
    <template v-if="live.result">
      <!-- 3) Kennzahlen -->
      <div class="la-kpis">
        <div class="la-score" :class="gradeClass(live.result.grade)">
          <div class="la-score-val">{{ live.result.score }}</div>
          <div class="la-score-grade">{{ live.result.grade }}</div>
        </div>
        <div class="la-kpi-list">
          <div class="la-kpi">
            <span class="la-kpi-n">{{ live.result.pages_crawled }}</span>{{ $t('liveAnalysis.pagesCrawled') }}
            <!-- Warum der Lauf endete: stop_reason ist die präzise Auskunft des
                 Dienstes (Kontingent-Budget, Backstop, Auslastung, Abbruch).
                 Ältere Ergebnisse ohne das Feld fallen auf reached_page_limit zurück. -->
            <span v-if="stopNote" class="la-warn la-limit">· {{ stopNote }}</span>
          </div>
          <div class="la-kpi">
            <span class="la-kpi-n">{{ live.result.cost }}</span>{{ $t('liveAnalysis.costUnits') }}
            <span class="la-muted">· {{ $t('liveAnalysis.totalIssues', [live.result.summary?.total ?? 0]) }}</span>
          </div>
          <div v-if="live.result.external_links_found" class="la-kpi la-muted la-extlinks">
            {{ $t('liveAnalysis.externalLinks', [live.result.external_links_found]) }}
            <span v-if="live.result.external_links_truncated" class="la-warn">
              · {{ $t('liveAnalysis.externalLinksTruncated') }}
            </span>
          </div>
          <div class="la-kpi la-sev-counts">
            <span v-for="sev in SEVERITIES" :key="sev" class="la-sev-count" :class="sevMeta(sev).cls">
              <v-icon :icon="sevMeta(sev).icon" size="14" />{{ live.bySeverity[sev] ?? 0 }}
            </span>
          </div>
        </div>
      </div>

      <!-- Geprüfte Adresse (was der Dienst tatsächlich angefasst hat) -->
      <div v-if="live.result.start_url" class="la-analyzed">
        <v-icon icon="mdi-web" size="14" class="mr-1" />
        <a :href="live.result.start_url" target="_blank" rel="noopener">{{ live.result.start_url }}</a>
      </div>

      <!-- 4) Trend -->
      <div v-if="live.result.trend" class="la-section">
        <div v-if="live.result.trend.first_run" class="la-trend-first">
          <v-icon icon="mdi-flag-outline" size="16" class="mr-1" />{{ $t('liveAnalysis.trend.firstRun') }}
        </div>
        <template v-else>
          <div class="la-trend">
            <span
              class="la-delta"
              :class="live.result.trend.delta_score >= 0 ? 'up' : 'down'"
            >
              <v-icon :icon="live.result.trend.delta_score >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'" size="16" />
              {{ live.result.trend.delta_score >= 0 ? '+' : '' }}{{ live.result.trend.delta_score }}
            </span>
            <span class="la-muted">
              {{ $t('liveAnalysis.trend.previous', [live.result.trend.previous_score, live.result.trend.previous_grade]) }}
              <template v-if="live.result.trend.previous_at">
                ({{ new Date(live.result.trend.previous_at).toLocaleString() }})
              </template>
            </span>
            <span v-if="live.result.trend.resolved_count" class="la-trend-good">
              {{ $t('liveAnalysis.trend.resolved', [live.result.trend.resolved_count]) }}
            </span>
            <span v-if="live.result.trend.new_count" class="la-trend-bad">
              {{ $t('liveAnalysis.trend.new', [live.result.trend.new_count]) }}
            </span>
          </div>

          <!-- Welche Befunde seither behoben bzw. hinzugekommen sind -->
          <div v-if="live.result.trend.resolved?.length || live.result.trend.new?.length" class="la-trend-lists">
            <div v-if="live.result.trend.resolved?.length" class="la-trend-list">
              <div class="la-trend-list-title la-trend-good">{{ $t('liveAnalysis.trend.resolvedTitle') }}</div>
              <ul>
                <li v-for="(x, i) in live.result.trend.resolved" :key="'r' + i">
                  {{ x.title || x.type }}<span v-if="x.location" class="la-muted"> — {{ x.location }}</span>
                </li>
              </ul>
            </div>
            <div v-if="live.result.trend.new?.length" class="la-trend-list">
              <div class="la-trend-list-title la-trend-bad">{{ $t('liveAnalysis.trend.newTitle') }}</div>
              <ul>
                <li v-for="(x, i) in live.result.trend.new" :key="'n' + i">
                  {{ x.title || x.type }}<span v-if="x.location" class="la-muted"> — {{ x.location }}</span>
                </li>
              </ul>
            </div>
          </div>
        </template>
      </div>

      <!-- 5) Verlaufskurve -->
      <div v-if="curvePoints" class="la-section">
        <div class="la-section-title">{{ $t('liveAnalysis.historyTitle') }}</div>
        <svg class="la-curve" :viewBox="`0 0 ${CURVE_W} ${CURVE_H}`" preserveAspectRatio="none" role="img">
          <polyline :points="curveLine" fill="none" stroke="var(--mint-green)" stroke-width="2" />
          <circle
            v-for="(p, i) in curvePoints"
            :key="i"
            :cx="p.x"
            :cy="p.y"
            r="3"
            :class="gradeClass(p.run.grade)"
          >
            <title>{{ fmtDate(p.run.created_at) }} — {{ p.run.score }} ({{ p.run.grade }})</title>
          </circle>
        </svg>
      </div>

      <!-- 6) Befundliste -->
      <div class="la-section">
        <div class="la-section-title">{{ $t('liveAnalysis.issuesTitle') }}</div>
        <div class="la-sevfilters">
          <button class="la-chip" :class="{ active: live.severityFilter === 'all' }" @click="live.setSeverityFilter('all')">
            {{ $t('liveAnalysis.allSeverities') }}
          </button>
          <button
            v-for="sev in SEVERITIES"
            :key="sev"
            class="la-chip"
            :class="[sevMeta(sev).cls, { active: live.severityFilter === sev }]"
            @click="live.setSeverityFilter(sev)"
          >
            <v-icon :icon="sevMeta(sev).icon" size="13" />
            {{ $t('liveAnalysis.severity.' + sev) }} · {{ live.bySeverity[sev] ?? 0 }}
          </button>
        </div>

        <!-- Typ-Filter aus summary.by_type (nach Häufigkeit absteigend) -->
        <div v-if="typeCounts.length" class="la-sevfilters la-typefilters">
          <button
            v-for="tc in typeCounts"
            :key="tc.type"
            class="la-chip"
            :class="{ active: live.typeFilter === tc.type }"
            :title="tc.type"
            @click="live.setTypeFilter(tc.type)"
          >
            {{ tc.type }} · {{ tc.count }}
          </button>
        </div>

        <div v-if="!live.filteredIssues.length" class="la-muted la-noissues">{{ $t('liveAnalysis.noIssues') }}</div>
        <ul v-else class="la-issues">
          <li v-for="(issue, i) in live.filteredIssues" :key="i" class="la-issue">
            <v-icon :icon="sevMeta(issue.severity).icon" size="16" :class="sevMeta(issue.severity).cls" class="la-issue-icon" />
            <div class="la-issue-body">
              <div class="la-issue-title">{{ issue.title || issue.type }}</div>
              <div v-if="issueLocation(issue)" class="la-issue-loc">{{ issueLocation(issue) }}</div>
              <!-- Zusatzfelder: bei toten Links steht hier die Quellseite (source)
                   und der HTTP-Code (status) — das Wichtigste zum Beheben. -->
              <div v-if="issueDetails(issue).length" class="la-issue-details">
                <span v-for="d in issueDetails(issue)" :key="d.key" class="la-issue-detail">
                  <span class="la-detail-k">{{ d.label }}:</span> {{ d.value }}
                </span>
              </div>
              <div v-if="issue.fix" class="la-issue-fix">{{ issue.fix }}</div>
            </div>
          </li>
        </ul>
      </div>

      <!-- 7) Server / Infrastruktur -->
      <div v-if="live.result.server" class="la-section">
        <div class="la-section-title">{{ $t('liveAnalysis.server.title') }}</div>
        <div class="la-facts">
          <div v-if="live.result.server.ipv4?.length" class="la-fact">
            <span class="la-fact-k">{{ $t('liveAnalysis.server.ipv4') }}</span>{{ live.result.server.ipv4.join(', ') }}
          </div>
          <div v-if="live.result.server.ipv6?.length" class="la-fact">
            <span class="la-fact-k">{{ $t('liveAnalysis.server.ipv6') }}</span>{{ live.result.server.ipv6.join(', ') }}
          </div>
          <div class="la-fact">
            <span class="la-fact-k">{{ $t('liveAnalysis.server.httpVersion') }}</span>HTTP/{{ live.result.server.http_version }}
          </div>
          <div class="la-fact">
            <span class="la-fact-k">SPF</span><span :class="live.result.server.spf ? 'la-yes' : 'la-no'">{{ live.result.server.spf ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
          </div>
          <div class="la-fact">
            <span class="la-fact-k">DMARC</span><span :class="live.result.server.dmarc ? 'la-yes' : 'la-no'">{{ live.result.server.dmarc ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
          </div>
          <div class="la-fact">
            <span class="la-fact-k">{{ $t('liveAnalysis.server.httpsRedirect') }}</span><span :class="live.result.server.https_redirect ? 'la-yes' : 'la-no'">{{ live.result.server.https_redirect ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
          </div>
          <div class="la-fact">
            <span class="la-fact-k">{{ $t('liveAnalysis.server.wwwConsistent') }}</span><span :class="live.result.server.www_consistent ? 'la-yes' : 'la-no'">{{ live.result.server.www_consistent ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
            <span v-if="live.result.server.www_variant" class="la-muted"> ({{ live.result.server.www_variant }})</span>
          </div>
          <div v-if="live.result.server.cert" class="la-fact la-fact-wide">
            <span class="la-fact-k">{{ $t('liveAnalysis.server.cert') }}</span>
            {{ live.result.server.cert.issuer }} · {{ $t('liveAnalysis.server.certValid', [fmtDate(live.result.server.cert.valid_to), live.result.server.cert.days_left]) }}
          </div>
        </div>
      </div>

      <!-- 8) Crawlability -->
      <div v-if="live.result.crawlability" class="la-section">
        <div class="la-section-title">{{ $t('liveAnalysis.crawl.title') }}</div>
        <div class="la-facts">
          <div class="la-fact">
            <span class="la-fact-k">robots.txt</span><span :class="live.result.crawlability.robots_txt ? 'la-yes' : 'la-no'">{{ live.result.crawlability.robots_txt ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
          </div>
          <div class="la-fact">
            <span class="la-fact-k">Sitemap</span><span :class="live.result.crawlability.sitemap ? 'la-yes' : 'la-no'">{{ live.result.crawlability.sitemap ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
            <span v-if="live.result.crawlability.sitemap_urls" class="la-muted">
              · {{ $t('liveAnalysis.crawl.sitemapUrls', [live.result.crawlability.sitemap_urls]) }}
            </span>
          </div>
          <template v-if="live.result.crawlability.page">
            <div class="la-fact la-fact-wide">
              <span class="la-fact-k">{{ $t('liveAnalysis.crawl.pageTitle') }}</span>{{ live.result.crawlability.page.title || '—' }}
            </div>
            <div class="la-fact">
              <span class="la-fact-k">Meta-Description</span><span :class="live.result.crawlability.page.meta_description ? 'la-yes' : 'la-no'">{{ live.result.crawlability.page.meta_description ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
            </div>
            <div class="la-fact">
              <span class="la-fact-k">Canonical</span><span :class="live.result.crawlability.page.canonical ? 'la-yes' : 'la-no'">{{ live.result.crawlability.page.canonical ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
            </div>
            <div class="la-fact">
              <span class="la-fact-k">JSON-LD</span>{{ live.result.crawlability.page.structured_data ?? 0 }}
            </div>
            <div class="la-fact">
              <span class="la-fact-k">og:image</span><span :class="live.result.crawlability.page.og_image ? 'la-yes' : 'la-no'">{{ live.result.crawlability.page.og_image ? $t('liveAnalysis.yes') : $t('liveAnalysis.no') }}</span>
            </div>
            <div v-if="live.result.crawlability.page.third_party?.hosts?.length" class="la-fact la-fact-wide">
              <span class="la-fact-k">{{ $t('liveAnalysis.crawl.thirdParty') }}</span>{{ live.result.crawlability.page.third_party.hosts.join(', ') }}
            </div>
            <div v-if="live.result.crawlability.page.third_party?.categories?.length" class="la-fact la-fact-wide">
              <span class="la-fact-k">{{ $t('liveAnalysis.crawl.thirdPartyCategories') }}</span>{{ live.result.crawlability.page.third_party.categories.join(', ') }}
            </div>
          </template>
        </div>
      </div>

      <!-- 9) Browser (Lighthouse aus dem chrome-sidecar) -->
      <div v-if="live.result.browser" class="la-section">
        <div class="la-section-title">{{ $t('liveAnalysis.browser.title') }}</div>
        <div v-if="!live.result.browser.available" class="la-muted">
          {{ $t('liveAnalysis.browser.unavailable') }}
          <span v-if="live.result.browser.error"> ({{ live.result.browser.error }})</span>
        </div>
        <template v-else>
          <div class="la-scores">
            <div v-for="(val, key) in live.result.browser.scores" :key="key" class="la-bscore" :class="gradeClass(val >= 90 ? 'A' : val >= 50 ? 'C' : 'F')">
              <div class="la-bscore-val">{{ val ?? '—' }}</div>
              <div class="la-bscore-label">{{ $t('liveAnalysis.browser.' + key) }}</div>
            </div>
          </div>

          <!-- Kennwerte: Core Web Vitals zuerst, dann die weiteren Ladekennwerte -->
          <div v-if="live.result.browser.metrics" class="la-facts la-bmetrics">
            <div v-for="m in BROWSER_METRICS" :key="m.key" class="la-fact">
              <span class="la-fact-k">{{ $t('liveAnalysis.browser.metric.' + m.key) }}</span>
              <template v-if="live.result.browser.metrics[m.key] !== null && live.result.browser.metrics[m.key] !== undefined">
                {{ live.result.browser.metrics[m.key] }}{{ m.unit }}
              </template>
              <template v-else>—</template>
            </div>
          </div>

          <!-- CrUX-Felddaten (echte Nutzererfahrung), sobald CrUX freigeschaltet -->
          <div v-if="fieldData && fieldData.available" class="la-field">
            <div class="la-field-title">{{ $t('liveAnalysis.browser.field.title') }}</div>
            <div class="la-field-legend">
              <span class="la-cwv-dot good"></span>{{ $t('liveAnalysis.browser.field.good') }}
              <span class="la-cwv-dot ni"></span>{{ $t('liveAnalysis.browser.field.ni') }}
              <span class="la-cwv-dot poor"></span>{{ $t('liveAnalysis.browser.field.poor') }}
            </div>
            <div v-for="lvl in [{ id: 'url', rows: fieldUrl }, { id: 'origin', rows: fieldOrigin }]" :key="lvl.id">
              <div v-if="lvl.rows" class="la-field-level">
                <div class="la-field-level-title">{{ $t('liveAnalysis.browser.field.' + lvl.id) }}</div>
                <div v-for="row in lvl.rows" :key="row.key" class="la-field-row">
                  <span class="la-field-k">{{ $t('liveAnalysis.browser.field.metric.' + row.key) }}</span>
                  <span class="la-field-p75">{{ fmtFieldValue(row) }}</span>
                  <div class="la-cwv-bar" :title="`${fieldPct(row.good)} · ${fieldPct(row.needs_improvement)} · ${fieldPct(row.poor)}`">
                    <span class="seg good" :style="{ width: fieldPct(row.good) }"></span>
                    <span class="seg ni" :style="{ width: fieldPct(row.needs_improvement) }"></span>
                    <span class="seg poor" :style="{ width: fieldPct(row.poor) }"></span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Diagnose-Kennwerte (Serverantwort, Seitengewicht, DOM-Größe …) -->
          <div v-if="browserDiagnostics.length" class="la-facts la-bmetrics">
            <div v-for="d in browserDiagnostics" :key="d.key" class="la-fact">
              <span class="la-fact-k">{{ $t('liveAnalysis.browser.diag.' + d.key) }}</span>
              {{ d.display }}
            </div>
          </div>

          <!-- Optimierungs-Chancen mit Verursachern (aufklappbar) -->
          <div v-if="browserOpportunities.length" class="la-opps">
            <div class="la-opps-title">{{ $t('liveAnalysis.browser.oppsTitle') }}</div>
            <details v-for="o in browserOpportunities" :key="o.id" class="la-opp">
              <summary class="la-opp-head">
                <span class="la-opp-title">{{ o.title }}</span>
                <span v-if="opportunitySavings(o)" class="la-opp-savings">{{ opportunitySavings(o) }}</span>
              </summary>
              <ul v-if="o.items?.length" class="la-opp-items">
                <li v-for="(it, i) in o.items" :key="i" class="la-opp-item">
                  <span class="la-opp-item-label">{{ it.label }}</span>
                  <span v-if="it.bytes || it.ms" class="la-opp-item-cost">
                    <template v-if="it.bytes">{{ fmtBytes(it.bytes) }}</template>
                    <template v-if="it.ms">{{ it.bytes ? ' · ' : '' }}{{ it.ms }} ms</template>
                  </span>
                </li>
                <li v-if="o.more_items > 0" class="la-opp-more">
                  {{ $t('liveAnalysis.browser.oppsMore', [o.more_items]) }}
                </li>
              </ul>
            </details>
          </div>

          <!-- Lauf-Warnungen von Lighthouse (Messung evtl. beeinträchtigt) -->
          <div v-if="live.result.browser.run_warnings?.length" class="la-warnings">
            <div v-for="(w, i) in live.result.browser.run_warnings" :key="i" class="la-warning">{{ w }}</div>
          </div>

          <!-- Konkrete Accessibility-Verstöße (der Befund nennt nur den Score) -->
          <div v-if="live.result.browser.accessibility_failures?.length" class="la-a11y">
            <div class="la-a11y-title">
              {{ $t('liveAnalysis.browser.a11yTitle', [live.result.browser.accessibility_failures.length]) }}
            </div>
            <ul class="la-a11y-list">
              <li v-for="f in live.result.browser.accessibility_failures" :key="f.id" class="la-a11y-item">
                <span v-if="f.impact" class="la-a11y-impact" :class="'impact-' + f.impact">{{ f.impact }}</span>
                <span class="la-a11y-text">{{ f.title || f.id }}</span>
                <code class="la-a11y-id">{{ f.id }}</code>
              </li>
            </ul>
          </div>

          <div v-if="live.result.browser.lighthouse_version" class="la-muted la-lhver">
            {{ $t('liveAnalysis.browser.version', [live.result.browser.lighthouse_version]) }}
            <template v-if="live.result.browser.environment?.device">
              · {{ live.result.browser.environment.device }}<template v-if="live.result.browser.environment.throttling"> · {{ live.result.browser.environment.throttling }}</template>
            </template>
          </div>
        </template>
      </div>

      <!-- 10) Export -->
      <div v-if="live.resultJobId" class="la-section la-export">
        <a class="la-btn" :href="exportUrl('html')" target="_blank" rel="noopener">
          <v-icon icon="mdi-file-document-outline" size="16" class="mr-1" />{{ $t('liveAnalysis.exportHtml') }}
        </a>
        <a class="la-btn" :href="exportUrl('csv')">
          <v-icon icon="mdi-file-delimited-outline" size="16" class="mr-1" />{{ $t('liveAnalysis.exportCsv') }}
        </a>
      </div>
    </template>

    <!-- Noch kein Ergebnis und kein Lauf -->
    <div v-else-if="!live.running" class="la-empty">
      <v-icon icon="mdi-radar" size="56" class="la-empty-icon" />
      <p>{{ $t('liveAnalysis.empty') }}</p>
    </div>
  </div>
</template>

<style scoped>
.la-panel { padding: 14px 16px; max-width: 900px; }

.la-head { display: flex; align-items: center; gap: 10px; }
.la-url { flex: 1 1 auto; }
.la-subhead { margin: 8px 0 4px; font-size: 0.82rem; color: var(--mint-text); }
.la-muted { color: var(--mint-text-muted); }
.la-warn { color: #b03a2e; }

.la-btn {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 5px 14px;
  font-size: 0.85rem;
  color: var(--mint-text);
  cursor: pointer;
  white-space: nowrap;
  text-decoration: none;
}
.la-btn:hover:not(:disabled) { background: var(--mint-panel-hover); }
.la-btn:disabled { color: #b6b6b3; cursor: default; }
.la-btn.primary { border-color: var(--mint-green); color: var(--mint-green); }
.la-btn.primary:hover:not(:disabled) { background: var(--mint-green); color: #fff; }
.la-btn.danger:hover { background: #fbeaea; border-color: #d9b0ab; color: #b03a2e; }

.la-running {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 12px 0;
  padding: 10px 12px;
  background: var(--mint-panel);
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  font-size: 0.88rem;
}

/* Kennzahlen */
.la-kpis { display: flex; align-items: center; gap: 20px; margin: 16px 0; }
.la-score {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 84px;
  height: 84px;
  border-radius: 50%;
  color: #fff;
  flex: 0 0 auto;
}
.la-score-val { font-size: 1.6rem; font-weight: 700; line-height: 1; }
.la-score-grade { font-size: 0.8rem; opacity: 0.9; }
.la-score.good { background: #4a9c5d; }
.la-score.medium { background: #c47f17; }
.la-score.poor { background: #b03a2e; }

.la-kpi-list { display: flex; flex-direction: column; gap: 4px; font-size: 0.86rem; color: var(--mint-text-muted); }
.la-kpi-n { font-weight: 700; color: var(--mint-text); margin-right: 6px; font-size: 1.05rem; }
.la-sev-counts { display: flex; gap: 12px; margin-top: 2px; }
.la-sev-count { display: inline-flex; align-items: center; gap: 3px; font-weight: 600; }

.sev-critical { color: #b03a2e; }
.sev-warning { color: #c47f17; }
.sev-info { color: #3a7ca5; }

/* Abschnitte */
.la-section { margin: 18px 0; padding-top: 14px; border-top: 1px solid var(--mint-border); }
.la-section-title { font-weight: 600; font-size: 0.9rem; margin-bottom: 10px; color: var(--mint-text); }

/* Trend */
.la-trend { display: flex; align-items: center; flex-wrap: wrap; gap: 14px; font-size: 0.86rem; }
.la-trend-first { color: var(--mint-text-muted); font-size: 0.86rem; }
.la-delta { display: inline-flex; align-items: center; font-weight: 700; }
.la-delta.up { color: #4a9c5d; }
.la-delta.down { color: #b03a2e; }
.la-trend-good { color: #4a9c5d; }
.la-trend-bad { color: #b03a2e; }

/* Verlaufskurve */
.la-curve { width: 100%; height: 90px; display: block; }
.la-curve circle.good { fill: #4a9c5d; }
.la-curve circle.medium { fill: #c47f17; }
.la-curve circle.poor { fill: #b03a2e; }

/* Filter-Chips */
.la-sevfilters { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.la-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid var(--mint-border);
  border-radius: 999px;
  background: #fff;
  padding: 2px 12px;
  font-size: 0.8rem;
  color: var(--mint-text);
  cursor: pointer;
}
.la-chip:hover { background: var(--mint-panel-hover); }
.la-chip.active { background: var(--mint-green); border-color: var(--mint-green); color: #fff; }

/* Befundliste */
.la-issues { list-style: none; margin: 0; padding: 0; }
.la-issue { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--mint-border); }
.la-issue-icon { flex: 0 0 auto; margin-top: 2px; }
.la-issue-body { min-width: 0; }
.la-issue-title { font-weight: 600; font-size: 0.88rem; color: var(--mint-text); }
.la-issue-loc { font-size: 0.78rem; color: var(--mint-text-muted); word-break: break-all; margin-top: 1px; }
.la-issue-fix { font-size: 0.82rem; color: var(--mint-text); margin-top: 3px; }
.la-noissues { padding: 8px 0; }

/* Zusatzfelder eines Befunds (source, status, …) — kompakt unter dem Ort. */
.la-issue-details { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 3px; }
.la-issue-detail { font-size: 0.78rem; color: var(--mint-text); word-break: break-all; }
.la-detail-k { color: var(--mint-text-muted); }

.la-typefilters { margin-top: -4px; }
.la-typefilters .la-chip { font-family: inherit; }

/* Geprüfte Adresse + Umfangshinweise */
.la-analyzed { margin: -8px 0 12px; font-size: 0.8rem; }
.la-analyzed a { color: var(--mint-green); text-decoration: none; word-break: break-all; }
.la-analyzed a:hover { text-decoration: underline; }
.la-limit, .la-extlinks { font-size: 0.82rem; }

/* Trend: Listen der behobenen/neuen Befunde */
.la-trend-lists { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 12px; }
.la-trend-list { flex: 1 1 260px; min-width: 0; }
.la-trend-list-title { font-size: 0.82rem; font-weight: 600; margin-bottom: 4px; }
.la-trend-list ul { list-style: none; margin: 0; padding: 0; }
.la-trend-list li { font-size: 0.8rem; padding: 2px 0; word-break: break-all; }

/* Fakten-Raster (server/crawlability) */
.la-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px 18px; font-size: 0.84rem; }
.la-fact-wide { grid-column: 1 / -1; word-break: break-all; }
.la-fact-k { display: inline-block; min-width: 120px; color: var(--mint-text-muted); }
.la-yes { color: #4a9c5d; font-weight: 600; }
.la-no { color: #b03a2e; font-weight: 600; }

/* Browser-Scores */
.la-scores { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 10px; }
.la-bscore { display: flex; flex-direction: column; align-items: center; width: 72px; padding: 8px 0; border-radius: var(--mint-radius); color: #fff; }
.la-bscore.good { background: #4a9c5d; }
.la-bscore.medium { background: #c47f17; }
.la-bscore.poor { background: #b03a2e; }
.la-bscore-val { font-size: 1.3rem; font-weight: 700; }
.la-bscore-label { font-size: 0.72rem; text-align: center; }
.la-bmetrics { margin-top: 4px; }

/* Konkrete Accessibility-Verstöße: kompakte Liste mit Wirkungsgrad. */
.la-a11y { margin-top: 14px; }
.la-a11y-title { font-size: 0.84rem; font-weight: 600; margin-bottom: 6px; color: var(--mint-text); }
.la-a11y-list { list-style: none; margin: 0; padding: 0; }
.la-a11y-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 4px 0;
  font-size: 0.82rem;
  border-bottom: 1px solid var(--mint-border);
}
.la-a11y-impact {
  flex: 0 0 auto;
  border-radius: 999px;
  padding: 1px 8px;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  background: var(--mint-panel);
  color: var(--mint-text-muted);
}
.la-a11y-impact.impact-critical { background: #fbeaea; color: #b03a2e; }
.la-a11y-impact.impact-serious { background: #fbeaea; color: #b03a2e; }
.la-a11y-impact.impact-moderate { background: #fdf2e0; color: #c47f17; }
.la-a11y-impact.impact-minor { background: #eaf1f6; color: #3a7ca5; }
.la-a11y-text { flex: 1 1 auto; color: var(--mint-text); }
.la-a11y-id { flex: 0 0 auto; font-size: 0.72rem; color: var(--mint-text-muted); }
.la-lhver { margin-top: 10px; font-size: 0.76rem; }

/* CrUX-Felddaten (echte Nutzererfahrung) */
.la-field { margin-top: 14px; }
.la-field-title { font-size: 0.84rem; font-weight: 600; margin-bottom: 4px; color: var(--mint-text); }
.la-field-legend {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  font-size: 0.72rem;
  color: var(--mint-text-muted);
  margin-bottom: 8px;
}
.la-cwv-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-left: 10px; }
.la-cwv-dot:first-child { margin-left: 0; }
.la-field-level { margin-bottom: 8px; }
.la-field-level-title { font-size: 0.76rem; font-weight: 600; color: var(--mint-text-muted); margin-bottom: 4px; }
.la-field-row { display: flex; align-items: center; gap: 10px; padding: 3px 0; font-size: 0.8rem; }
.la-field-k { flex: 0 0 auto; min-width: 42px; color: var(--mint-text); font-weight: 600; }
.la-field-p75 { flex: 0 0 auto; min-width: 62px; color: var(--mint-text); }
.la-cwv-bar {
  flex: 1 1 auto;
  display: flex;
  height: 8px;
  min-width: 80px;
  border-radius: 4px;
  overflow: hidden;
  background: var(--mint-border);
}
.la-cwv-bar .seg { height: 100%; }
.la-cwv-dot.good, .la-cwv-bar .seg.good { background: #3a9d5d; }
.la-cwv-dot.ni, .la-cwv-bar .seg.ni { background: #c47f17; }
.la-cwv-dot.poor, .la-cwv-bar .seg.poor { background: #b03a2e; }

/* Optimierungs-Chancen (aufklappbar) */
.la-opps { margin-top: 14px; }
.la-opps-title { font-size: 0.84rem; font-weight: 600; margin-bottom: 6px; color: var(--mint-text); }
.la-opp { border-bottom: 1px solid var(--mint-border); }
.la-opp-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 5px 0;
  font-size: 0.82rem;
  cursor: pointer;
  list-style: none;
}
.la-opp-head::-webkit-details-marker { display: none; }
.la-opp-head::before {
  content: '▸';
  flex: 0 0 auto;
  color: var(--mint-text-muted);
  font-size: 0.7rem;
}
.la-opp[open] > .la-opp-head::before { content: '▾'; }
.la-opp-title { flex: 1 1 auto; color: var(--mint-text); }
.la-opp-savings { flex: 0 0 auto; color: var(--mint-text-muted); white-space: nowrap; }
.la-opp-items { list-style: none; margin: 2px 0 8px; padding: 0 0 0 16px; }
.la-opp-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 2px 0;
  font-size: 0.76rem;
}
.la-opp-item-label { flex: 1 1 auto; word-break: break-all; color: var(--mint-text-muted); }
.la-opp-item-cost { flex: 0 0 auto; color: var(--mint-text-muted); white-space: nowrap; }
.la-opp-more { padding: 2px 0; font-size: 0.74rem; color: var(--mint-text-muted); font-style: italic; }

/* Lauf-Warnungen von Lighthouse */
.la-warnings { margin-top: 12px; }
.la-warning {
  padding: 5px 8px;
  margin-bottom: 4px;
  font-size: 0.78rem;
  border-radius: 6px;
  background: #fdf2e0;
  color: #c47f17;
}

.la-export { display: flex; gap: 10px; }

.la-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 0;
  color: var(--mint-text-muted);
  gap: 12px;
}
.la-empty-icon { color: #c4c4c0; }

@media (max-width: 599.98px) {
  .la-head { flex-wrap: wrap; }
  .la-url { flex-basis: 100%; }
}
</style>
