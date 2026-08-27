<script setup>
// PageSpeed-Check (Pro-Funktion, eigener Reiter im SEO-Check). Misst die in der
// Konfiguration hinterlegte Live-Adresse über Google PageSpeed Insights und
// zeigt die Kategorie-Scores sowie die Kern-Web-Vitalwerte. Anders als der
// SEO-Bericht wird kein Verlauf vorgehalten — angezeigt wird der jüngste Lauf.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditStore } from '../stores/audit'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'

const { t, locale } = useI18n()
const audit = useAuditStore()
const auth = useAuthStore()
// Fehler bleibt stehen, bis die nächste Aktion ihn ersetzt: Eine Meldung, die
// sich nach acht Sekunden selbst ausblendet, hinterlässt ein Ergebnis, dem man
// nicht ansieht, ob es der letzte Lauf ist oder ein alter Stand.
const error = ref(null)

// Beim Öffnen das zuletzt gespeicherte Ergebnis dieser Webseite laden (falls
// eines vorliegt), damit Datum und Kennzahlen ohne neue Messung erscheinen.
onMounted(async () => {
  try {
    await audit.fetchPageSpeed()
  } catch {
    // Kein gespeichertes Ergebnis / Ladefehler: Panel bleibt leer, unkritisch.
  }
})

// Zeitpunkt der letzten Messung, sprachabhängig formatiert (oder null).
const lastMeasured = computed(() => {
  const iso = audit.pageSpeed?.measuredAt
  return iso ? new Date(iso).toLocaleString() : null
})

// Zu messende Live-Adresse. Vorbelegung: die pro Webseite gespeicherte Adresse,
// sonst die aus der Hugo-baseURL erkannte. Beim Messstart wird sie serverseitig
// gespeichert.
const url = ref(auth.pagespeedUrl || auth.pagespeedUrlDetected || '')

// Nur absolute http(s)-Adressen sind messbar (Google ruft sie selbst ab).
const canRun = computed(() => /^https?:\/\/.+/i.test(url.value.trim()))

// Anzuzeigende Kategorien in fester Reihenfolge (nur, was Google zurückliefert).
const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo']
// Kern-Web-Vitalwerte (Labor) in Anzeigereihenfolge (Lighthouse-Audit-IDs).
const CORE_METRICS = [
  'largest-contentful-paint',
  'cumulative-layout-shift',
  'total-blocking-time',
  'first-contentful-paint',
  'speed-index',
  'interactive',
]
// Weitere Diagnose-Kennwerte (Gruppe C), eigener Abschnitt.
const MORE_METRICS = [
  'server-response-time',
  'total-byte-weight',
  'dom-size',
  'bootup-time',
  'mainthread-work-breakdown',
]
// CrUX-Metriken (Gruppe A) in bevorzugter Reihenfolge; unbekannte werden
// hinten angehängt.
const CRUX_ORDER = [
  'LARGEST_CONTENTFUL_PAINT_MS',
  'INTERACTION_TO_NEXT_PAINT',
  'CUMULATIVE_LAYOUT_SHIFT_SCORE',
  'FIRST_CONTENTFUL_PAINT_MS',
  'EXPERIMENTAL_TIME_TO_FIRST_BYTE',
  'FIRST_INPUT_DELAY_MS',
]

const result = computed(() => audit.pageSpeed)
const scores = computed(() => result.value?.scores ?? {})

// Ampelfarbe eines Scores (Lighthouse-Schwellen): ≥90 gut, ≥50 mittel, sonst
// schlecht.
function scoreColor(value) {
  if (value >= 90) return 'good'
  if (value >= 50) return 'avg'
  return 'poor'
}

// --- Felddaten (CrUX, Gruppe A) ---------------------------------------------

// Umschalter: diese Seite (url) oder die ganze Domain (origin).
const fieldScope = ref('url')
// Gewählte Erfahrung; fällt auf die jeweils andere zurück, wenn eine fehlt.
const fieldExp = computed(() => {
  const fd = result.value?.fieldData
  if (!fd) return null
  return fd[fieldScope.value] || null
})
// Sind überhaupt Felddaten vorhanden (für Umschalter/Hinweis)?
const hasFieldData = computed(() => !!(result.value?.fieldData?.url || result.value?.fieldData?.origin))

// Klasse aus einer CrUX-Kategorie (FAST/AVERAGE/SLOW).
function catClass(category) {
  if (category === 'FAST') return 'good'
  if (category === 'AVERAGE') return 'avg'
  if (category === 'SLOW') return 'poor'
  return ''
}

// Perzentilwert lesbar: CLS einheitenlos (Wert/100), sonst Millisekunden →
// Sekunden ab 1 s.
function fieldValue(key, percentile) {
  if (percentile == null) return '–'
  if (key.includes('CUMULATIVE_LAYOUT_SHIFT')) return (percentile / 100).toFixed(2)
  return percentile >= 1000 ? (percentile / 1000).toFixed(1) + ' s' : Math.round(percentile) + ' ms'
}

// Beschriftung einer CrUX-Metrik. Fehlt der Schlüssel, gibt vue-i18n den PFAD
// zurück ('pagespeed.cruxMetric.…') — der stünde dann in der Oberfläche. Führt
// Google eine neue Metrik ein, erscheint stattdessen ihr roher Name.
function metricLabel(key) {
  const path = 'pagespeed.cruxMetric.' + key
  const label = t(path)
  return label === path ? key.replace(/_/g, ' ') : label
}

// CrUX-Metriken der gewählten Erfahrung in fester Reihenfolge, anzeigefertig.
const fieldMetrics = computed(() => {
  const m = fieldExp.value?.metrics
  if (!m) return []
  const keys = [...CRUX_ORDER.filter((k) => k in m), ...Object.keys(m).filter((k) => !CRUX_ORDER.includes(k))]
  return keys.map((key) => ({
    key,
    label: metricLabel(key),
    value: fieldValue(key, m[key].percentile),
    cls: catClass(m[key].category),
    good: Math.round((m[key].good || 0) * 100),
    ni: Math.round((m[key].needsImprovement || 0) * 100),
    poor: Math.round((m[key].poor || 0) * 100),
  }))
})

// Eine Kennwert-Kachel: eigene kurze Beschriftung, ersatzweise Lighthouses
// Titel; die Erklärung des Kennwerts erscheint als Hinweis beim Überfahren.
function metricCell(id) {
  const m = result.value?.metrics?.[id] || {}
  const path = 'pagespeed.metric.' + id
  const label = t(path)
  return {
    label: label === path ? (m.title || id) : label,
    value: m.display || metricFallback(m),
    tip: descParts(m.description).text,
  }
}

// Manche Kennwerte kommen ohne fertige Darstellung („dom-size" liefert nur die
// Anzahl). Dann wird der Zahlenwert anhand seiner Einheit selbst gesetzt.
function metricFallback(m) {
  if (m.value == null) return ''
  if (m.unit === 'millisecond') return savingsText(m.value).slice(1)
  if (m.unit === 'byte') return formatBytes(m.value)
  return String(Math.round(m.value))
}

// --- Optimierungs-Chancen (Gruppe B) ----------------------------------------

// Die Lighthouse-Beschreibung wird gleich mit aufbereitet: Der Titel nennt nur
// das Thema („Bilder in modernen Formaten bereitstellen"), erst die Beschreibung
// sagt, was zu ändern ist.
const opportunities = computed(() =>
  (result.value?.opportunities ?? []).map((o) => ({ ...o, ...descParts(o.description) })),
)

// Verbesserungshinweise je Kategorie (Barrierefreiheit, Best Practices, SEO):
// fehlgeschlagene Prüfungen mit Titel + Beschreibung, nur vorhandene Kategorien.
const FINDING_CATEGORIES = ['accessibility', 'best-practices', 'seo']
const findings = computed(() => result.value?.findings ?? {})
const findingGroups = computed(() =>
  FINDING_CATEGORIES
    .filter((c) => findings.value[c]?.length)
    .map((c) => ({ cat: c, items: findings.value[c].map((f) => ({ ...f, ...descParts(f.description) })) })),
)

// Lighthouse-Beschreibung: den Markdown-Doku-Link abtrennen. text = Beschreibung
// ohne Link, url = die verlinkte Adresse (für „Mehr erfahren").
function descParts(desc) {
  if (!desc) return { text: '', url: '' }
  const m = desc.match(/\[([^\]]+)\]\(([^)]+)\)/)
  const url = m ? m[2] : ''
  // Steht der Doku-Link am Schluss („… Weitere Informationen"), übernimmt ihn
  // der eigene Knopf und er fällt aus dem Text. Steht er mitten im Satz, bleibt
  // sein Text stehen — sonst fehlt dem Satz das Prädikat.
  const text = desc
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (full, label, href, offset) =>
      /^[.!?;:\s]*$/.test(desc.slice(offset + full.length)) ? '' : label,
    )
    .replace(/\s+/g, ' ')
    .replace(/\s+([.,;:!?])/g, '$1')
    // Der entfallene Link hinterlässt sonst ein doppeltes Satzzeichen.
    .replace(/([.!?;:])[.!?;:\s]*$/, '$1')
    .trim()
  return { text, url }
}
// Ersparnis lesbar (Zeit; ab 1 s in Sekunden).
function savingsText(ms) {
  return ms >= 1000 ? '−' + (ms / 1000).toFixed(1) + ' s' : '−' + Math.round(ms) + ' ms'
}
// Aufgeklappte Chance (Akkordeon; null = keine).
const openOpp = ref(null)
function toggleOpp(id) {
  openOpp.value = openOpp.value === id ? null : id
}
// Bytes lesbar (B/KB/MB).
function formatBytes(b) {
  if (b == null) return ''
  if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB'
  if (b >= 1024) return Math.round(b / 1024) + ' KB'
  return b + ' B'
}
// Ersparnis eines Detailpostens: Bytes bevorzugt, sonst Millisekunden.
function itemValue(item) {
  if (item.bytes != null) return '−' + formatBytes(item.bytes)
  if (item.ms != null) return savingsText(item.ms)
  return ''
}

async function run(strategy) {
  if (!canRun.value) return
  error.value = null
  try {
    await audit.runPageSpeed(url.value.trim(), strategy, locale.value)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// Beide Strategien nacheinander messen (Mobil und Desktop).
async function runBoth() {
  if (!canRun.value) return
  error.value = null
  try {
    await audit.runPageSpeedBoth(url.value.trim(), locale.value)
  } catch (e) {
    error.value = errorText(t, e)
  }
}
</script>

<template>
  <div class="ps-panel">
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

    <!-- Zeitpunkt der letzten Messung — ganz oben, sobald ein Ergebnis vorliegt. -->
    <div v-if="lastMeasured" class="ps-last mb-4">
      <v-icon icon="mdi-history" size="20" class="mr-1 mb-1" /><span class="text-subtitle-1">{{ $t('pagespeed.lastMeasured', [lastMeasured]) }}</span>
    </div>

    <!-- Zu messende Live-Adresse (pro Webseite). Vorbelegt aus der gespeicherten
         bzw. der aus der Hugo-baseURL erkannten Adresse; beim Messstart gespeichert. -->
    <v-text-field
      v-model="url"
      :label="$t('pagespeed.urlLabel')"
      :hint="$t('pagespeed.urlHint')"
      persistent-hint
      placeholder="https://example.com/"
      prepend-inner-icon="mdi-web"
      density="compact"
      variant="outlined"
      class="mb-5"
      :disabled="audit.pageSpeedRunning"
      @keyup.enter="run()"
    />

    <!-- Steuerung: Strategie (Mobil/Desktop) + Start. -->
    <div class="ps-controls nemo-noselect">
      <div class="ps-strategy">
        <button
          class="ps-chip"
          :class="{ active: audit.pageSpeedStrategy === 'mobile' }"
          :disabled="audit.pageSpeedRunning"
          @click="audit.pageSpeedStrategy = 'mobile'"
        >
          <v-icon icon="mdi-cellphone" size="16" class="mr-1" />{{ $t('pagespeed.mobile') }}
        </button>
        <button
          class="ps-chip"
          :class="{ active: audit.pageSpeedStrategy === 'desktop' }"
          :disabled="audit.pageSpeedRunning"
          @click="audit.pageSpeedStrategy = 'desktop'"
        >
          <v-icon icon="mdi-monitor" size="16" class="mr-1" />{{ $t('pagespeed.desktop') }}
        </button>
      </div>
      <button class="audit-btn primary" :disabled="audit.pageSpeedRunning || !canRun" @click="run()">
        <v-progress-circular v-if="audit.pageSpeedRunning" indeterminate size="14" width="2" class="mr-1" />
        <v-icon v-else icon="mdi-speedometer" size="16" class="mr-1" />{{ $t('pagespeed.run') }}
      </button>
      <button class="audit-btn" :disabled="audit.pageSpeedRunning || !canRun" @click="runBoth()">
        <v-icon icon="mdi-cellphone-link" size="16" class="mr-1" />{{ $t('pagespeed.runBoth') }}
      </button>
    </div>

    <!-- Läuft gerade -->
    <div v-if="audit.pageSpeedRunning && !result" class="nemo-empty">
      <v-progress-circular indeterminate size="40" width="3" color="primary" />
      <p>{{ $t('pagespeed.running') }}</p>
    </div>

    <!-- Noch keine Messung -->
    <div v-else-if="!result" class="nemo-empty">
      <v-icon icon="mdi-speedometer" size="56" class="nemo-empty-icon" />
      <p>{{ $t('pagespeed.empty') }}</p>
    </div>

    <!-- Ergebnis -->
    <div v-else class="ps-result">
      <div class="ps-url">
        <v-icon icon="mdi-link-variant" size="14" class="mr-1" />
        <a :href="result.analyzedUrl" target="_blank" rel="noopener">{{ result.analyzedUrl }}</a>
      </div>

      <!-- Lauf-Warnungen (E) -->
      <v-alert
        v-for="(w, i) in result.runWarnings || []"
        :key="'w' + i"
        type="warning"
        density="compact"
        variant="tonal"
        class="mb-2"
      >
        {{ w }}
      </v-alert>

      <!-- Kategorie-Scores als Ampel-Ringe -->
      <div class="ps-scores">
        <div v-for="cat in CATEGORIES" v-show="cat in scores" :key="cat" class="ps-score" :class="scoreColor(scores[cat])">
          <div class="ps-score-val">{{ scores[cat] }}</div>
          <div class="ps-score-label">{{ $t('pagespeed.category.' + cat) }}</div>
        </div>
      </div>

      <!-- Echte Nutzerdaten (CrUX-Felddaten, Gruppe A) -->
      <div v-if="hasFieldData" class="ps-section">
        <div class="ps-section-head">
          <div class="ps-metrics-title">{{ $t('pagespeed.fieldTitle') }}</div>
          <div class="ps-scope">
            <button
              class="ps-chip sm"
              :class="{ active: fieldScope === 'url' }"
              :disabled="!result.fieldData.url"
              @click="fieldScope = 'url'"
            >
              {{ $t('pagespeed.scopeUrl') }}
            </button>
            <button
              class="ps-chip sm"
              :class="{ active: fieldScope === 'origin' }"
              :disabled="!result.fieldData.origin"
              @click="fieldScope = 'origin'"
            >
              {{ $t('pagespeed.scopeOrigin') }}
            </button>
          </div>
        </div>
        <div v-if="fieldExp" class="ps-crux">
          <div v-for="m in fieldMetrics" :key="m.key" class="ps-crux-row">
            <div class="ps-crux-head">
              <span class="ps-crux-label">{{ m.label }}</span>
              <span class="ps-crux-val" :class="m.cls">{{ m.value }}</span>
            </div>
            <div class="ps-crux-bar">
              <span class="seg good" :style="{ width: m.good + '%' }" :title="$t('pagespeed.good') + ': ' + m.good + '%'" />
              <span class="seg avg" :style="{ width: m.ni + '%' }" :title="$t('pagespeed.needsImprovement') + ': ' + m.ni + '%'" />
              <span class="seg poor" :style="{ width: m.poor + '%' }" :title="$t('pagespeed.poor') + ': ' + m.poor + '%'" />
            </div>
          </div>
        </div>
      </div>
      <!-- Keine Felddaten (zu wenig realer Verkehr) -->
      <div v-else class="ps-hint">{{ $t('pagespeed.noFieldData') }}</div>

      <!-- Kern-Web-Vitalwerte (Labormessung) -->
      <div class="ps-metrics ps-section">
        <div class="ps-metrics-title">{{ $t('pagespeed.metricsTitle') }}</div>
        <div class="ps-metrics-grid">
          <template v-for="id in CORE_METRICS" :key="id">
            <div
              v-if="result.metrics[id]"
              class="ps-metric"
              :class="{ explained: metricCell(id).tip }"
              :title="metricCell(id).tip"
            >
              <span class="ps-metric-label">{{ metricCell(id).label }}</span>
              <span class="ps-metric-value">{{ metricCell(id).value }}</span>
            </div>
          </template>
        </div>
      </div>

      <!-- Weitere Diagnose-Kennwerte (Gruppe C) -->
      <div class="ps-metrics ps-section">
        <div class="ps-metrics-title">{{ $t('pagespeed.moreMetricsTitle') }}</div>
        <div class="ps-metrics-grid">
          <template v-for="id in MORE_METRICS" :key="id">
            <div
              v-if="result.metrics[id]"
              class="ps-metric"
              :class="{ explained: metricCell(id).tip }"
              :title="metricCell(id).tip"
            >
              <span class="ps-metric-label">{{ metricCell(id).label }}</span>
              <span class="ps-metric-value">{{ metricCell(id).value }}</span>
            </div>
          </template>
        </div>
      </div>

      <!-- Optimierungs-Chancen (Gruppe B) — als letzter Abschnitt -->
      <div v-if="opportunities.length" class="ps-section">
        <div class="ps-metrics-title">{{ $t('pagespeed.opportunitiesTitle') }}</div>
        <div class="ps-opps">
          <div v-for="o in opportunities" :key="o.id" class="ps-opp">
            <button
              class="ps-opp-head"
              :class="{ clickable: o.items.length || o.text }"
              :disabled="!o.items.length && !o.text"
              @click="toggleOpp(o.id)"
            >
              <v-icon
                v-if="o.items.length || o.text"
                :icon="openOpp === o.id ? 'mdi-chevron-down' : 'mdi-chevron-right'"
                size="18"
                class="ps-opp-chev"
              />
              <span class="ps-opp-title">{{ o.title }}</span>
              <span class="ps-opp-savings">{{ o.display || savingsText(o.savingsMs) }}</span>
            </button>

            <!-- Was zu tun ist (aufgeklappt), darunter die Verursacher -->
            <div v-if="openOpp === o.id && o.text" class="ps-opp-desc">
              {{ o.text }}
              <a v-if="o.url" :href="o.url" target="_blank" rel="noopener" class="ps-find-more">{{ $t('pagespeed.learnMore') }}</a>
            </div>

            <!-- Detailtabelle: konkrete Verursacher (aufgeklappt) -->
            <div v-if="openOpp === o.id && o.items.length" class="ps-opp-items">
              <div v-for="(it, i) in o.items" :key="i" class="ps-opp-item">
                <span class="ps-opp-item-main">
                  <span class="ps-opp-item-label" :title="it.label">{{ it.label }}</span>
                  <span v-if="it.url" class="ps-opp-item-url" :title="it.url">{{ it.url }}</span>
                </span>
                <span class="ps-opp-item-val">{{ itemValue(it) }}</span>
              </div>
              <div v-if="o.moreItems" class="ps-opp-more">{{ $t('pagespeed.moreItems', [o.moreItems]) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Verbesserungshinweise je Kategorie (Barrierefreiheit / Best Practices / SEO) -->
      <div v-if="findingGroups.length" class="ps-section">
        <div class="ps-metrics-title">{{ $t('pagespeed.findingsTitle') }}</div>
        <div class="ps-finds">
          <details v-for="g in findingGroups" :key="g.cat" class="ps-find">
            <summary class="ps-find-head">
              <v-icon icon="mdi-chevron-right" size="18" class="ps-find-chev" />
              <span class="ps-find-cat">{{ $t('pagespeed.category.' + g.cat) }}</span>
              <span class="ps-find-count">{{ g.items.length }}</span>
            </summary>
            <div class="ps-find-list">
              <div v-for="f in g.items" :key="f.id" class="ps-find-item">
                <div class="ps-find-item-title">{{ f.title }}</div>
                <div v-if="f.text || f.url" class="ps-find-item-desc">
                  {{ f.text }}
                  <a v-if="f.url" :href="f.url" target="_blank" rel="noopener" class="ps-find-more">{{ $t('pagespeed.learnMore') }}</a>
                </div>

                <!-- Fundstellen: ohne sie bliebe offen, WO der Mangel steckt.
                     Der Ausschnitt taugt als Suchbegriff für die Quelldatei. -->
                <details v-if="f.items?.length" class="ps-where">
                  <summary class="ps-where-head">
                    <v-icon icon="mdi-chevron-right" size="14" class="ps-where-chev" />
                    {{ f.itemsTotal > f.items.length
                        ? $t('pagespeed.whereSome', [f.items.length, f.itemsTotal])
                        : $t('pagespeed.where', [f.items.length]) }}
                  </summary>
                  <ol class="ps-where-list">
                    <li v-for="(it, i) in f.items" :key="i" class="ps-where-item">
                      <code v-if="it.selector" class="ps-where-sel">{{ it.selector }}</code>
                      <span v-else-if="it.label" class="ps-where-sel">{{ it.label }}</span>
                      <!-- Die betroffene Ressource: bei Meldungen wie
                           „net::ERR_TIMED_OUT" steht sie nur hier. -->
                      <div v-if="it.url" class="ps-where-url">{{ it.url }}</div>
                      <pre v-if="it.snippet" class="ps-where-snippet">{{ it.snippet }}</pre>
                      <div v-if="it.explanation" class="ps-where-why">{{ it.explanation }}</div>
                    </li>
                  </ol>
                </details>
              </div>
            </div>
          </details>
        </div>
      </div>

      <!-- Messbedingungen (E) + Datenquelle -->
      <div class="ps-foot">
        <span v-if="result.environment?.lighthouseVersion">
          {{ $t('pagespeed.env', [$t('pagespeed.' + (result.environment.device === 'desktop' ? 'desktop' : 'mobile')), result.environment.lighthouseVersion]) }}
          ·
        </span>
        {{ $t('pagespeed.provider') }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.ps-panel {
  padding: 16px;
  max-width: 900px;
  margin: 0 auto;
}
.ps-last {
  display: flex;
  align-items: center;
  font-size: 0.8rem;
  opacity: 0.7;
  margin-bottom: 12px;
}
.ps-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
/* Knopf-Stile wie im SEO-Check (AuditView); dort scoped, daher hier erneut,
   damit die Panel-Knöpfe (Kind-Komponente) dieselbe Gestaltung erhalten. */
.audit-btn {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 4px 12px;
  font-size: 0.85rem;
  color: var(--mint-text);
  cursor: pointer;
  white-space: nowrap;
}
.audit-btn:hover:not(:disabled) {
  background: var(--mint-panel-hover);
}
.audit-btn:disabled {
  color: #b6b6b3;
  cursor: default;
}
.audit-btn.primary {
  border-color: var(--mint-green);
  color: var(--mint-green);
}
.audit-btn.primary:hover:not(:disabled) {
  background: var(--mint-green);
  color: #fff;
}
.ps-strategy {
  display: flex;
  gap: 6px;
}
.ps-chip {
  display: inline-flex;
  align-items: center;
  padding: 5px 12px;
  border-radius: 999px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 0.85rem;
  color: rgb(var(--v-theme-on-surface));
  background: transparent;
  cursor: pointer;
}
.ps-chip.active {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  border-color: rgb(var(--v-theme-primary));
}
.ps-chip:disabled {
  opacity: 0.5;
  cursor: default;
}
.ps-url {
  font-size: 0.85rem;
  margin-bottom: 16px;
  word-break: break-all;
}
.ps-url a {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
.ps-scores {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 24px;
}
.ps-score {
  flex: 1 1 120px;
  min-width: 120px;
  text-align: center;
  padding: 16px 8px;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ps-score-val {
  font-size: 2rem;
  font-weight: 600;
  line-height: 1;
}
.ps-score-label {
  font-size: 0.8rem;
  margin-top: 6px;
  color: rgb(var(--v-theme-on-surface));
  opacity: 0.75;
}
/* Ampelfarben — dieselbe Bedeutung wie in Lighthouse. */
.ps-score.good .ps-score-val,
.ps-crux-val.good {
  color: #0a7c3e;
}
.ps-score.avg .ps-score-val,
.ps-crux-val.avg {
  color: #b25e00;
}
.ps-score.poor .ps-score-val,
.ps-crux-val.poor {
  color: #c62828;
}
.ps-score.good {
  border-color: rgba(10, 124, 62, 0.5);
}
.ps-score.avg {
  border-color: rgba(178, 94, 0, 0.5);
}
.ps-score.poor {
  border-color: rgba(198, 40, 40, 0.5);
}
.ps-metrics-title {
  font-size: 0.9rem;
  font-weight: 600;
  margin-bottom: 10px;
}
.ps-metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 8px 20px;
}
.ps-metric {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.6));
  font-size: 0.85rem;
}
.ps-metric.explained {
  cursor: help;
}
.ps-metric-label {
  opacity: 0.8;
}
.ps-metric-value {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
/* Abschnitte (Felddaten, Chancen, Kennwerte) */
.ps-section {
  margin-top: 28px;
}
.ps-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.ps-scope {
  display: flex;
  gap: 6px;
}
.ps-chip.sm {
  padding: 3px 10px;
  font-size: 0.78rem;
}
.ps-hint {
  margin-top: 12px;
  font-size: 0.82rem;
  opacity: 0.65;
}

/* CrUX-Felddaten: je Metrik Wert + Verteilungsbalken. */
.ps-crux {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ps-crux-head {
  display: flex;
  justify-content: space-between;
  font-size: 0.85rem;
  margin-bottom: 4px;
}
.ps-crux-label {
  opacity: 0.85;
}
.ps-crux-val {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.ps-crux-bar {
  display: flex;
  height: 8px;
  border-radius: 4px;
  overflow: hidden;
  background: rgba(var(--v-border-color), var(--v-border-opacity));
}
.ps-crux-bar .seg {
  height: 100%;
}
.ps-crux-bar .seg.good {
  background: #0a7c3e;
}
.ps-crux-bar .seg.avg {
  background: #e8a13a;
}
.ps-crux-bar .seg.poor {
  background: #c62828;
}

/* Optimierungs-Chancen: Titel + Ersparnis + Balken relativ zur größten. */
.ps-opps {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ps-opp-head {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 0;
  border: none;
  background: transparent;
  font-size: 0.85rem;
  color: rgb(var(--v-theme-on-surface));
  text-align: left;
}
.ps-opp-head.clickable {
  cursor: pointer;
}
.ps-opp-chev {
  flex: 0 0 auto;
  opacity: 0.7;
}
.ps-opp-title {
  flex: 1 1 auto;
  min-width: 0;
}
.ps-opp-savings {
  flex: 0 0 auto;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: #b25e00;
  white-space: nowrap;
}
.ps-opp-desc {
  padding: 8px 12px 4px 34px;
  font-size: 0.85rem;
  line-height: 1.45;
  color: rgba(var(--v-theme-on-surface), 0.75);
}
.ps-opp-items {
  margin: 8px 0 4px 26px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.ps-opp-item {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.8rem;
  padding: 3px 0;
  border-bottom: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.5));
}
.ps-opp-item-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ps-opp-item-url {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: monospace;
  font-size: 0.72rem;
  opacity: 0.6;
  direction: rtl;
  text-align: left;
}
.ps-opp-item-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  opacity: 0.8;
  direction: rtl; /* bei langen URLs das Ende (Dateiname) sichtbar halten */
  text-align: left;
}
.ps-opp-item-val {
  flex: 0 0 auto;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  opacity: 0.9;
}
.ps-opp-more {
  font-size: 0.78rem;
  opacity: 0.6;
  padding-top: 3px;
}

/* Verbesserungshinweise je Kategorie (aufklappbar) */
.ps-finds { display: flex; flex-direction: column; gap: 6px; }
.ps-find {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: var(--mint-radius);
  overflow: hidden;
}
.ps-find-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  cursor: pointer;
  list-style: none;
  font-size: 0.86rem;
  color: rgb(var(--v-theme-on-surface));
}
.ps-find-head::-webkit-details-marker { display: none; }
.ps-find-chev { flex: 0 0 auto; transition: transform 0.15s; }
.ps-find[open] .ps-find-chev { transform: rotate(90deg); }
.ps-find-cat { flex: 1 1 auto; font-weight: 600; }
.ps-find-count {
  flex: 0 0 auto;
  min-width: 22px;
  height: 22px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 6px;
  border-radius: 999px;
  background: rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 0.75rem;
}
.ps-find-list { padding: 2px 10px 8px; }
.ps-find-item {
  padding: 6px 0;
  border-top: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.6));
}
.ps-find-item:first-child { border-top: none; }
.ps-find-item-title { font-weight: 600; font-size: 0.82rem; color: rgb(var(--v-theme-on-surface)); }
.ps-find-item-desc { font-size: 0.78rem; margin-top: 2px; color: rgb(var(--v-theme-on-surface)); opacity: 0.8; }
.ps-find-more { color: rgb(var(--v-theme-primary)); white-space: nowrap; margin-left: 4px; }

.ps-foot {
  margin-top: 28px;
  font-size: 0.75rem;
  opacity: 0.6;
}
/* Fundstellen eines Hinweises — bewusst schmal und monospace, damit Selektor
   und HTML-Ausschnitt als Code lesbar bleiben. */
.ps-where {
  margin-top: 6px;
}
.ps-where-head {
  cursor: pointer;
  list-style: none;
  font-size: 0.78rem;
  color: var(--mint-text-muted, #666);
  display: flex;
  align-items: center;
  gap: 2px;
}
.ps-where-head::-webkit-details-marker {
  display: none;
}
.ps-where-chev {
  transition: transform 0.15s;
}
.ps-where[open] .ps-where-chev {
  transform: rotate(90deg);
}
.ps-where-list {
  margin: 6px 0 0;
  padding-left: 22px;
}
.ps-where-item {
  margin-bottom: 8px;
  font-size: 0.78rem;
}
.ps-where-url {
  font-family: monospace;
  font-size: 0.74rem;
  opacity: 0.75;
  word-break: break-all;
  margin-top: 2px;
}
.ps-where-sel {
  font-family: monospace;
  color: var(--mint-text, #222);
  word-break: break-all;
}
.ps-where-snippet {
  margin: 2px 0;
  padding: 4px 6px;
  background: rgba(0, 0, 0, 0.04);
  border-radius: 4px;
  font-size: 0.74rem;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 120px;
  overflow: auto;
}
.ps-where-why {
  color: var(--mint-text-muted, #666);
}
</style>
