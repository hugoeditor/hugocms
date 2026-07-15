<script setup>
// PageSpeed-Check (Pro-Funktion, eigener Reiter im SEO-Check). Misst die in der
// Konfiguration hinterlegte Live-Adresse über Google PageSpeed Insights und
// zeigt die Kategorie-Scores sowie die Kern-Web-Vitalwerte. Anders als der
// SEO-Bericht wird kein Verlauf vorgehalten — angezeigt wird der jüngste Lauf.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditStore } from '../stores/audit'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'

const { t } = useI18n()
const audit = useAuditStore()
const error = useTransientError()

// Anzuzeigende Kategorien in fester Reihenfolge (nur, was Google zurückliefert).
const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo']
// Kern-Web-Vitalwerte in Anzeigereihenfolge (Lighthouse-Audit-IDs).
const METRICS = [
  'largest-contentful-paint',
  'cumulative-layout-shift',
  'total-blocking-time',
  'first-contentful-paint',
  'speed-index',
  'interactive',
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

// CrUX-Felddaten-Gesamturteil (echte Nutzererfahrung), falls vorhanden.
const fieldClass = computed(() => {
  const c = result.value?.fieldData
  if (c === 'FAST') return 'good'
  if (c === 'AVERAGE') return 'avg'
  if (c === 'SLOW') return 'poor'
  return null
})

async function run(strategy) {
  error.value = null
  try {
    await audit.runPageSpeed(strategy)
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
      <button class="audit-btn primary" :disabled="audit.pageSpeedRunning" @click="run()">
        <v-progress-circular v-if="audit.pageSpeedRunning" indeterminate size="14" width="2" class="mr-1" />
        <v-icon v-else icon="mdi-speedometer" size="16" class="mr-1" />{{ $t('pagespeed.run') }}
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

      <!-- Kategorie-Scores als Ampel-Ringe -->
      <div class="ps-scores">
        <div v-for="cat in CATEGORIES" v-show="cat in scores" :key="cat" class="ps-score" :class="scoreColor(scores[cat])">
          <div class="ps-score-val">{{ scores[cat] }}</div>
          <div class="ps-score-label">{{ $t('pagespeed.category.' + cat) }}</div>
        </div>
      </div>

      <!-- Kern-Web-Vitalwerte (Labormessung) -->
      <div class="ps-metrics">
        <div class="ps-metrics-title">{{ $t('pagespeed.metricsTitle') }}</div>
        <div class="ps-metrics-grid">
          <template v-for="id in METRICS" :key="id">
            <div v-if="result.metrics[id]" class="ps-metric">
              <span class="ps-metric-label">{{ $t('pagespeed.metric.' + id) }}</span>
              <span class="ps-metric-value">{{ result.metrics[id].display }}</span>
            </div>
          </template>
        </div>
      </div>

      <!-- CrUX-Felddaten (echte Nutzererfahrung), falls vorhanden -->
      <div v-if="fieldClass" class="ps-field" :class="fieldClass">
        {{ $t('pagespeed.field.' + result.fieldData) }}
      </div>

      <div class="ps-foot">{{ $t('pagespeed.provider') }}</div>
    </div>
  </div>
</template>

<style scoped>
.ps-panel {
  padding: 16px;
  max-width: 900px;
  margin: 0 auto;
}
.ps-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
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
.ps-field.good {
  color: #0a7c3e;
}
.ps-score.avg .ps-score-val,
.ps-field.avg {
  color: #b25e00;
}
.ps-score.poor .ps-score-val,
.ps-field.poor {
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
.ps-metric-label {
  opacity: 0.8;
}
.ps-metric-value {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.ps-field {
  margin-top: 20px;
  font-size: 0.85rem;
  font-weight: 600;
}
.ps-foot {
  margin-top: 24px;
  font-size: 0.75rem;
  opacity: 0.6;
}
</style>
