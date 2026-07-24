<script setup>
// Systemstatus: Zustand DIESER Installation — Lizenz, hinterlegte Zugänge,
// laufende Cron-Aufgaben und was diese demnächst abarbeiten. Bewusst getrennt
// vom Gesundheitscheck der WEBSEITE (cron-healthcheck.php, SEO-Audit): Der hier
// prüft die Anwendung, jener die veröffentlichten Seiten.
//
// Als Overlay-Ansicht wie ReviewQueueView/AuditView (nicht als v-dialog).
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStatusStore } from '../stores/status'
import { useAuthStore } from '../stores/auth'
import { useHelpStore } from '../stores/help'
import { errorText } from '../i18n/apiMessage'
import { useConfirm } from '../util/confirm'

const { t, locale } = useI18n()
const store = useStatusStore()
const auth = useAuthStore()
const help = useHelpStore()
const confirm = useConfirm()

// Die Lizenzaktivierung lebt weiterhin im LicenseDialog (App.vue) — von hier
// wird sie nur angestoßen, seit der eigene Werkzeugschienen-Eintrag entfallen ist.
// 'open-project-settings' trägt den Namen der Sektion, zu der der Dialog
// scrollen soll (hier 'cron') — Pausieren gehört in die Projekteinstellungen.
const emit = defineEmits(['activate-license', 'open-project-settings'])

// Feste Reihenfolge der Zugänge; `verifiable: false` bedeutet, dass sich der
// Zugang nicht folgenlos prüfen lässt (PageSpeed: jeder Test kostet Kontingent).
//
// `pro` markiert Zugänge, die ausschließlich Pro-Funktionen bedienen (der
// Server verlangt dort requirePro): Live-Analyse und Spracheingabe, PageSpeed,
// und der SMTP-Versand, den nur der Gesundheitscheck nutzt. Der KI-Schlüssel
// bleibt unmarkiert — der Assistent selbst läuft auch ohne Lizenz.
const KEY_ROWS = [
  { id: 'ai', icon: 'mdi-creation', pro: false },
  { id: 'service', icon: 'mdi-chart-line', pro: true },
  { id: 'pagespeed', icon: 'mdi-speedometer', pro: true },
  { id: 'mail', icon: 'mdi-email-outline', pro: true },
]

// Cron-Aufgaben, die eine gültige Pro-Lizenz voraussetzen. Der Bau der Webseite
// läuft bewusst auch ohne — sonst stünde eine Community-Installation ohne
// Veröffentlichungsweg da.
const PRO_JOBS = ['improve', 'healthcheck']

const keys = computed(() => store.data?.keys ?? {})
const license = computed(() => store.data?.license ?? null)

const CRON_ICON = {
  build: 'mdi-hammer',
  improve: 'mdi-creation',
  healthcheck: 'mdi-stethoscope',
}

function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value)
}

// Abstand zu jetzt in Worten („vor 2 Stunden“). Nutzt die Formatierung des
// Browsers, damit die Ausgabe zur eingestellten Sprache passt.
function relativeTime(iso) {
  if (!iso) return ''
  const ms = new Date(iso).getTime()
  if (Number.isNaN(ms)) return ''
  const diff = Math.round((ms - Date.now()) / 1000)
  const rtf = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })
  const units = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ]
  for (const [unit, secs] of units) {
    if (Math.abs(diff) >= secs) return rtf.format(Math.round(diff / secs), unit)
  }
  return rtf.format(diff, 'second')
}

// Geschätzter Takt in Worten. Die häufigen Fälle (stündlich, täglich) bekommen
// eine eigene Formulierung, alles andere wird als „alle N …“ ausgegeben.
function intervalText(seconds) {
  if (!seconds) return ''
  if (Math.abs(seconds - 3600) < 300) return t('status.cron.hourly')
  if (Math.abs(seconds - 86400) < 3600) return t('status.cron.daily')
  if (Math.abs(seconds - 604800) < 43200) return t('status.cron.weekly')
  if (seconds < 5400) return t('status.cron.everyMinutes', [Math.round(seconds / 60)])
  if (seconds < 172800) return t('status.cron.everyHours', [Math.round(seconds / 3600)])
  return t('status.cron.everyDays', [Math.round(seconds / 86400)])
}

// Ampel einer Cron-Aufgabe. Pausiert steht vor allem anderen (dann ist ein
// ausgesetzter Lauf kein Fehler, sondern gewollt); sonst: nie gelaufen (grau),
// überfällig oder zuletzt gescheitert (rot/orange), sonst in Ordnung.
function cronState(c) {
  if (c.paused) return { color: 'grey', icon: 'mdi-pause-circle-outline', label: t('status.cron.paused') }
  if (!c.seen) return { color: 'grey', icon: 'mdi-minus-circle-outline', label: t('status.cron.never') }
  if (!c.success) return { color: 'error', icon: 'mdi-alert-circle-outline', label: t('status.cron.failed') }
  if (c.overdue) return { color: 'warning', icon: 'mdi-clock-alert-outline', label: t('status.cron.overdue') }
  return { color: 'success', icon: 'mdi-check-circle-outline', label: t('status.cron.ok') }
}

// Prüfergebnis eines Zugangs (nach „Zugänge prüfen“). Ohne Lauf: null.
function checkState(id) {
  const c = store.checks?.[id]
  if (!c) return null
  if (c.status === 'ok') return { color: 'success', icon: 'mdi-check-circle-outline', text: t('status.keys.verified') }
  if (c.status === 'skipped') return null
  return {
    color: 'error',
    icon: 'mdi-alert-circle-outline',
    text: c.key ? t(`error.${c.key}`, [c.message]) : c.message || t('status.keys.failed'),
  }
}

// Kontingent des seo-success-Dienstes, sobald eine Prüfung gelaufen ist.
// quotaLimit === null heißt beim Dienst „unbegrenzt“ — dann gibt es keinen
// Balken, nur den Hinweis.
const serviceQuota = computed(() => {
  const info = store.checks?.service?.info
  if (!info || store.checks.service.status !== 'ok') return null
  const limit = info.quotaLimit
  const used = info.quotaUsed
  return {
    unlimited: limit === null || limit === undefined,
    limit,
    used: used ?? null,
    remaining: info.quotaRemaining ?? null,
    exceeded: !!info.quotaExceeded,
    // Anteil in Prozent für den Balken; bei unbegrenzt bedeutungslos.
    percent: limit ? Math.min(100, Math.round(((used ?? 0) / limit) * 100)) : 0,
    name: info.name || '',
  }
})

// Ab 90 % rot, ab 75 % orange — der Balken soll vor dem Anschlag warnen.
const quotaColor = computed(() => {
  const q = serviceQuota.value
  if (!q || q.unlimited) return 'success'
  if (q.exceeded || q.percent >= 90) return 'error'
  return q.percent >= 75 ? 'warning' : 'success'
})

// --- Protokoll --------------------------------------------------------------
// Erst auf Wunsch geladen: Es ist der umfangreichste Teil und für den
// Überblick über Lizenz, Zugänge und Cron nicht nötig.
const logOpen = ref(false)
// Nur Warnungen und Fehler zeigen — im Normalbetrieb die interessante Teilmenge.
const logProblemsOnly = ref(false)

function toggleLog() {
  logOpen.value = !logOpen.value
  if (logOpen.value && !store.log) store.fetchLog()
}

// Auswahlfeld über die vorhandenen Logstände: die aktuelle Datei (Index 0)
// und die rotierten Stände. Jeder Eintrag trägt Größe und — bei rotierten
// Ständen — den Zeitpunkt der Rotation, damit sich der richtige finden lässt.
const archiveItems = computed(() =>
  (store.log?.archives ?? []).map((a) => {
    const label = a.index === 0 ? t('status.log.current') : t('status.log.archive', [a.index])
    const meta = [formatBytes(a.size)]
    if (a.index !== 0 && a.mtime) meta.push(formatDate(a.mtime * 1000))
    return { value: a.index, title: `${label} · ${meta.join(' · ')}` }
  }),
)

function selectArchive(index) {
  if (index !== store.logIndex) store.fetchLog(null, index)
}

// Sofortige Rotation — der laufende Stand wandert ins Archiv, der älteste
// fällt weg. Klein, aber unumkehrbar, daher mit kurzer Rückfrage.
async function rotateLog() {
  const ok = await confirm({
    title: t('status.log.rotateConfirmTitle'),
    message: t('status.log.rotateConfirmMessage'),
    confirmText: t('status.log.rotateAction'),
    cancelText: t('common.cancel'),
  })
  if (ok) await store.rotateLog()
}

// Neueste zuerst: In einer Statusansicht interessiert das zuletzt Passierte.
const logLines = computed(() => {
  const lines = store.log?.lines ?? []
  const filtered = logProblemsOnly.value
    ? lines.filter((l) => l.level === 'error' || l.level === 'warning')
    : lines
  return [...filtered].reverse()
})

const LOG_LEVEL_COLOR = {
  error: 'text-error',
  warning: 'text-warning',
  info: 'text-medium-emphasis',
  debug: 'text-disabled',
}

// Dateigröße lesbar machen (die Rotation greift standardmäßig bei 1 MiB).
function formatBytes(n) {
  if (!n) return '0 B'
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KiB`
  return `${(n / 1024 / 1024).toFixed(1)} MiB`
}

function scoreColor(score) {
  if (score == null) return 'grey'
  if (score >= 70) return 'success'
  if (score >= 40) return 'warning'
  return 'error'
}
</script>

<template>
  <div v-if="store.open" class="st-overlay">
    <header class="st-head nemo-noselect">
      <button class="st-back" :title="$t('common.close')" @click="store.close()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-heart-pulse" size="18" class="st-head-icon" />
      <span class="st-head-title text-truncate">{{ $t('status.title') }}</span>
      <v-spacer />
      <v-btn
        size="small"
        variant="text"
        prepend-icon="mdi-refresh"
        :loading="store.loading"
        @click="store.fetch()"
      >
        {{ $t('common.refresh') }}
      </v-btn>
    </header>

    <div class="st-content nemo-scroll">
      <div class="st-inner">
        <v-alert v-if="store.error" type="error" density="comfortable" class="mb-4">
          {{ errorText(t, store.error) }}
        </v-alert>

        <div v-if="store.loading && !store.data" class="d-flex justify-center py-8">
          <v-progress-circular indeterminate color="primary" />
        </div>

        <template v-else-if="store.data">
          <!-- Lizenz — ersetzt den früheren eigenen Eintrag in der Werkzeugschiene -->
          <section class="st-card">
            <h2 class="st-card-title">
              <v-icon :icon="license?.edition === 'pro' ? 'mdi-license' : 'mdi-key-outline'" size="18" />
              {{ $t('status.license.heading') }}
            </h2>
            <div class="st-row">
              <div class="st-row-main">
                <div class="st-row-title">
                  {{ license?.edition === 'pro' ? $t('license.editionPro') : $t('license.editionCommunity') }}
                </div>
                <div class="st-row-sub">
                  <template v-if="license?.edition === 'pro'">
                    {{ $t('status.license.licensedTo', [license.licensee, license.domain]) }}
                  </template>
                  <template v-else-if="license?.configured">
                    {{ $t('status.license.invalidForDomain', [license.domain]) }}
                  </template>
                  <template v-else>{{ $t('status.license.none') }}</template>
                </div>
              </div>
              <v-btn
                v-if="auth.licensable"
                size="small"
                variant="tonal"
                @click="emit('activate-license')"
              >
                {{ license?.edition === 'pro' ? $t('status.license.change') : $t('license.activate') }}
              </v-btn>
            </div>
          </section>

          <!-- Zugänge: hinterlegt ja/nein, Prüfung gegen die Dienste auf Knopfdruck -->
          <section class="st-card">
            <h2 class="st-card-title">
              <v-icon icon="mdi-key-chain-variant" size="18" />
              {{ $t('status.keys.heading') }}
              <v-spacer />
              <v-btn
                size="small"
                variant="tonal"
                prepend-icon="mdi-lan-connect"
                :loading="store.checking"
                @click="store.check()"
              >
                {{ $t('status.keys.verify') }}
              </v-btn>
            </h2>
            <p class="st-hint">{{ $t('status.keys.hint') }}</p>

            <div v-for="row in KEY_ROWS" :key="row.id" class="st-row">
              <v-icon :icon="row.icon" size="18" class="st-row-icon" />
              <div class="st-row-main">
                <div class="st-row-title">
                  {{ $t(`status.keys.${row.id}`) }}
                  <span
                    v-if="row.pro"
                    class="st-pro"
                    :class="{ 'st-pro--inactive': !auth.isPro }"
                    :title="auth.isPro ? '' : $t('status.proRequired')"
                  >{{ $t('status.pro') }}</span>
                </div>
                <div class="st-row-sub">
                  <template v-if="!keys[row.id]?.configured">{{ $t('status.keys.missing') }}</template>
                  <template v-else-if="row.id === 'ai'">
                    {{ $t('status.keys.models', [keys.ai.model, keys.ai.modelCron]) }}
                  </template>
                  <template v-else-if="row.id === 'mail'">
                    {{ $t('status.keys.mailTarget', [keys.mail.host, keys.mail.to]) }}
                  </template>
                  <template v-else-if="row.id === 'service'">{{ keys.service.url }}</template>
                  <template v-else>{{ $t('status.keys.present') }}</template>
                </div>
                <!-- Ergebnis der Netzprüfung, sobald eine gelaufen ist -->
                <div v-if="checkState(row.id)" class="st-row-check" :class="`text-${checkState(row.id).color}`">
                  <v-icon :icon="checkState(row.id).icon" size="14" />
                  {{ checkState(row.id).text }}
                </div>
                <!-- Kontingent des Dienstes — erst nach einer Prüfung bekannt,
                     denn nur die Antwort auf /v1/verify trägt die Zahlen. -->
                <div v-if="row.id === 'service' && serviceQuota" class="st-quota">
                  <template v-if="serviceQuota.unlimited">
                    {{ $t('status.keys.quotaUnlimited') }}
                  </template>
                  <template v-else>
                    <div class="st-quota-line">
                      <span>{{ $t('status.keys.quotaUsage', [serviceQuota.used, serviceQuota.limit]) }}</span>
                      <span class="st-quota-rest">
                        {{ $t('status.keys.quotaRemaining', [serviceQuota.remaining]) }}
                      </span>
                    </div>
                    <v-progress-linear
                      :model-value="serviceQuota.percent"
                      :color="quotaColor"
                      height="6"
                      rounded
                    />
                    <div v-if="serviceQuota.exceeded" class="text-error mt-1">
                      {{ $t('status.keys.quotaExceeded') }}
                    </div>
                  </template>
                </div>
                <div v-else-if="keys[row.id]?.configured && keys[row.id]?.verifiable === false" class="st-row-check text-medium-emphasis">
                  <v-icon icon="mdi-information-outline" size="14" />
                  {{ $t('status.keys.notVerifiable') }}
                </div>
              </div>
              <v-icon
                :icon="keys[row.id]?.configured ? 'mdi-check-circle-outline' : 'mdi-minus-circle-outline'"
                :color="keys[row.id]?.configured ? 'success' : 'grey'"
                size="18"
              />
            </div>
          </section>

          <!-- Cron-Aufgaben: was auf diesem Hosting tatsächlich läuft -->
          <section class="st-card">
            <h2 class="st-card-title">
              <v-icon icon="mdi-clock-outline" size="18" />
              {{ $t('status.cron.heading') }}
              <v-spacer />
              <!-- Hilfe zur Crontab-Einrichtung (wie die README). Öffnet die
                   Wissensdatenbank als Überlagerung; Schließen kehrt hierher
                   zurück. -->
              <v-btn
                icon="mdi-help-circle-outline"
                variant="text"
                size="small"
                density="comfortable"
                :title="$t('status.cron.help')"
                @click="help.open('system', 'cron', locale)"
              />
            </h2>
            <p class="st-hint">{{ $t('status.cron.hint') }}</p>

            <div v-if="!store.cron.length" class="text-medium-emphasis py-2">
              {{ $t('status.cron.noProject') }}
            </div>
            <div v-for="c in store.cron" :key="c.job" class="st-row">
              <v-icon :icon="CRON_ICON[c.job]" size="18" class="st-row-icon" />
              <div class="st-row-main">
                <div class="st-row-title">
                  {{ $t(`status.cron.job.${c.job}`) }}
                  <span
                    v-if="PRO_JOBS.includes(c.job)"
                    class="st-pro"
                    :class="{ 'st-pro--inactive': !auth.isPro }"
                    :title="auth.isPro ? '' : $t('status.proRequired')"
                  >{{ $t('status.pro') }}</span>
                  <v-chip
                    v-if="c.seen && c.intervalSeconds"
                    size="x-small"
                    variant="tonal"
                    label
                    class="ml-2"
                  >
                    {{ intervalText(c.intervalSeconds) }}
                  </v-chip>
                </div>
                <div class="st-row-sub">
                  <template v-if="!c.seen">{{ $t('status.cron.neverHint') }}</template>
                  <template v-else>
                    {{ $t('status.cron.lastRun', [relativeTime(c.lastRun), formatDate(c.lastRun)]) }}
                    <template v-if="c.summary"> · {{ c.summary }}</template>
                  </template>
                </div>
                <div v-if="c.paused" class="st-row-check text-medium-emphasis">
                  <v-icon icon="mdi-pause-circle-outline" size="14" />
                  {{ $t('status.cron.pausedNote') }}
                </div>
                <div v-else-if="c.error" class="st-row-check text-error">
                  <v-icon icon="mdi-alert-circle-outline" size="14" />
                  {{ c.error }}
                </div>
                <div v-else-if="c.seen && !c.success && c.lastSuccessAt" class="st-row-check text-medium-emphasis">
                  {{ $t('status.cron.lastSuccess', [formatDate(c.lastSuccessAt)]) }}
                </div>
                <!-- Überfällig als Textzeile — nicht nur im Tooltip der Ampel,
                     damit die Warnung auch auf dem Smartphone (ohne Hover)
                     sichtbar ist. Nur wenn der letzte Lauf erfolgreich war;
                     ein Fehlschlag wird oben bereits gemeldet. -->
                <div v-else-if="c.overdue" class="st-row-check text-warning">
                  <v-icon icon="mdi-clock-alert-outline" size="14" />
                  {{ $t('status.cron.overdue') }}
                </div>
              </div>
              <!-- Pausenzustand — immer sichtbar, ob pausiert oder nicht.
                   Geändert wird er nicht hier, sondern in den Projekteinstellungen
                   (siehe Knopf unter der Aufgabenliste). -->
              <v-chip
                :color="c.paused ? 'warning' : undefined"
                size="x-small"
                variant="tonal"
                label
                class="mr-2"
              >
                {{ c.paused ? $t('status.cron.paused') : $t('status.cron.active') }}
              </v-chip>
              <v-tooltip :text="cronState(c).label" location="left">
                <template #activator="{ props }">
                  <v-icon v-bind="props" :icon="cronState(c).icon" :color="cronState(c).color" size="18" />
                </template>
              </v-tooltip>
            </div>

            <!-- Pausieren gehört zu den Projekteinstellungen; von hier führt nur
                 ein Weg dorthin, statt die Einstellung an zwei Stellen zu pflegen. -->
            <div v-if="store.cron.length" class="st-cron-manage">
              <span class="st-hint">{{ $t('status.cron.pauseHint') }}</span>
              <v-btn
                size="small"
                variant="tonal"
                prepend-icon="mdi-folder-cog-outline"
                @click="emit('open-project-settings', 'cron')"
              >
                {{ $t('status.cron.manage') }}
              </v-btn>
            </div>
          </section>

          <!-- Warteschlange der Cron-Aufgaben -->
          <section class="st-card">
            <h2 class="st-card-title">
              <v-icon icon="mdi-format-list-checks" size="18" />
              {{ $t('status.tasks.heading') }}
            </h2>

            <!-- Terminierte Freigaben: der nächste Build tauscht sie ein -->
            <h3 class="st-sub-title">
              {{ $t('status.tasks.scheduled') }}
              <v-chip size="x-small" variant="tonal" label class="ml-2">{{ store.scheduled.length }}</v-chip>
            </h3>
            <p class="st-hint">{{ $t('status.tasks.scheduledHint') }}</p>
            <div v-if="!store.scheduled.length" class="text-medium-emphasis py-2">
              {{ $t('status.tasks.noneScheduled') }}
            </div>
            <div v-for="s in store.scheduled" :key="s.key" class="st-row">
              <v-icon icon="mdi-calendar-clock" size="18" class="st-row-icon" />
              <div class="st-row-main">
                <div class="st-row-title">{{ s.rel }}</div>
                <div class="st-row-sub">
                  {{ formatDate(s.publishAt) }} · {{ relativeTime(s.publishAt) }}
                </div>
              </div>
              <v-chip v-if="s.due" size="x-small" color="warning" variant="tonal" label>
                {{ $t('status.tasks.dueNow') }}
              </v-chip>
            </div>

            <!-- Arbeitsvorrat des KI-Verbesserers, in Bearbeitungsreihenfolge -->
            <h3 class="st-sub-title mt-4">
              {{ $t('status.tasks.improve') }}
              <span
                class="st-pro"
                :class="{ 'st-pro--inactive': !auth.isPro }"
                :title="auth.isPro ? '' : $t('status.proRequired')"
              >{{ $t('status.pro') }}</span>
              <v-chip size="x-small" variant="tonal" label class="ml-2">{{ store.improve.length }}</v-chip>
            </h3>
            <p class="st-hint">{{ $t('status.tasks.improveHint') }}</p>
            <div v-if="!store.improve.length" class="text-medium-emphasis py-2">
              {{ $t('status.tasks.noneImprove') }}
            </div>
            <div v-for="(i, n) in store.improve" :key="`${i.mount}/${i.rel}`" class="st-row">
              <span class="st-rank">{{ n + 1 }}</span>
              <div class="st-row-main">
                <div class="st-row-title">{{ i.rel }}</div>
                <div class="st-row-sub">{{ $t('status.tasks.checkedAt', [formatDate(i.checkedAt)]) }}</div>
              </div>
              <v-chip :color="scoreColor(i.score)" size="x-small" variant="tonal" label>{{ i.score }}</v-chip>
            </div>
          </section>

          <!-- Protokoll: die letzten Zeilen der Logdatei. Eingeklappt, bis
               jemand sie sehen will — geladen wird erst dann. -->
          <section class="st-card">
            <h2 class="st-card-title">
              <v-icon icon="mdi-text-box-outline" size="18" />
              {{ $t('status.log.heading') }}
              <v-spacer />
              <v-btn
                size="small"
                variant="tonal"
                :prepend-icon="logOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                @click="toggleLog"
              >
                {{ logOpen ? $t('status.log.hide') : $t('status.log.show') }}
              </v-btn>
            </h2>

            <template v-if="logOpen">
              <div class="st-log-bar">
                <v-checkbox-btn
                  v-model="logProblemsOnly"
                  :label="$t('status.log.problemsOnly')"
                  density="compact"
                  hide-details
                  class="st-log-check"
                />
                <v-spacer />
                <!-- Auswahl des Stands: erst sinnvoll, sobald es neben der
                     aktuellen Datei auch rotierte Stände gibt. Rechts vor den
                     Aktionsknöpfen. -->
                <v-select
                  v-if="archiveItems.length > 1"
                  :model-value="store.logIndex"
                  :items="archiveItems"
                  :label="$t('status.log.fileLabel')"
                  density="compact"
                  variant="outlined"
                  hide-details
                  class="st-log-select"
                  :menu-props="{ class: 'st-log-menu' }"
                  @update:model-value="selectArchive"
                />
                <v-btn
                  size="small"
                  variant="text"
                  prepend-icon="mdi-file-rotate-left-outline"
                  :loading="store.logRotating"
                  @click="rotateLog"
                >
                  {{ $t('status.log.rotate') }}
                </v-btn>
                <v-btn
                  size="small"
                  variant="text"
                  prepend-icon="mdi-refresh"
                  :loading="store.logLoading"
                  @click="store.fetchLog()"
                >
                  {{ $t('common.refresh') }}
                </v-btn>
              </div>

              <div v-if="store.logLoading && !store.log" class="d-flex justify-center py-6">
                <v-progress-circular indeterminate color="primary" size="28" />
              </div>

              <template v-else-if="store.log">
                <p class="st-hint">
                  <template v-if="store.log.exists">
                    {{ store.log.file }} · {{ formatBytes(store.log.size) }}
                    <template v-if="store.log.truncated"> · {{ $t('status.log.truncated', [store.logLines]) }}</template>
                  </template>
                  <template v-else>{{ $t('status.log.noFile') }}</template>
                </p>

                <div v-if="store.log.exists && !logLines.length" class="text-medium-emphasis py-2">
                  {{ logProblemsOnly ? $t('status.log.noProblems') : $t('status.log.empty') }}
                </div>
                <div v-else-if="logLines.length" class="st-log">
                  <div v-for="(l, n) in logLines" :key="n" class="st-log-line">
                    <span v-if="l.time" class="st-log-time">{{ l.time }}</span>
                    <span v-if="l.level" class="st-log-level" :class="LOG_LEVEL_COLOR[l.level]">
                      {{ l.level.toUpperCase() }}
                    </span>
                    <span class="st-log-text">{{ l.text }}</span>
                  </div>
                </div>

                <!-- Nur anbieten, solange der Server nicht schon alles geliefert
                     hat (er deckelt bei 2000 Zeilen). -->
                <div v-if="store.log.truncated && store.logLines < 2000" class="mt-2">
                  <v-btn
                    size="small"
                    variant="text"
                    :loading="store.logLoading"
                    @click="store.fetchLog(Math.min(2000, store.logLines * 5))"
                  >
                    {{ $t('status.log.more') }}
                  </v-btn>
                </div>
              </template>
            </template>
          </section>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Overlay über dem Arbeitsbereich — z-index wie die Freigabe-Warteschlange. */
.st-overlay {
  position: absolute;
  inset: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.st-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.st-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px 6px;
  color: var(--mint-text);
  cursor: pointer;
}
.st-back:hover { background: var(--mint-panel-hover); }
.st-head-icon { color: var(--mint-green); }
.st-head-title { font-weight: 600; font-size: 0.95rem; min-width: 0; }

.st-content { flex: 1 1 auto; overflow: auto; }
/* Volle Breite wie die übrigen Overlay-Ansichten (Freigabe, SEO-Audit) — keine
   zentrierte Spalte, die links und rechts breite Ränder stehen ließe. */
.st-inner { max-width: none; margin: 0; padding: 12px 12px 24px; }

/* Weg zu den Projekteinstellungen unter der Cron-Liste: Hinweis links, Knopf
   rechts. */
.st-cron-manage {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--mint-border);
}
.st-cron-manage .st-hint { flex: 1 1 auto; margin: 0; }

.st-card {
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: var(--mint-panel);
  padding: 14px 16px;
  margin-bottom: 16px;
}
.st-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.95rem;
  font-weight: 600;
  margin-bottom: 4px;
}
.st-sub-title {
  display: flex;
  align-items: center;
  font-size: 0.85rem;
  font-weight: 600;
  margin-top: 8px;
}
.st-hint {
  font-size: 0.78rem;
  opacity: 0.7;
  margin: 2px 0 8px;
}

.st-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-top: 1px solid var(--mint-border);
}
.st-row:first-of-type { border-top: none; }
.st-row-icon { flex: 0 0 auto; opacity: 0.75; }
.st-row-main { flex: 1 1 auto; min-width: 0; }
.st-row-title {
  display: flex;
  align-items: center;
  font-size: 0.88rem;
  font-weight: 500;
  overflow-wrap: anywhere;
}
.st-row-sub {
  font-size: 0.78rem;
  opacity: 0.7;
  overflow-wrap: anywhere;
}
.st-row-check {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 0.78rem;
  margin-top: 2px;
  overflow-wrap: anywhere;
}

/* Protokollansicht: fester Zeichensatz, eigener Scrollbereich, damit die
   Statusansicht darüber nicht endlos wächst. */
.st-log-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 4px;
  flex-wrap: wrap;
}
/* Auswahl des Logstands: schmal halten, damit sie die Leiste nicht sprengt. */
.st-log-select {
  flex: 0 0 auto;
  width: 170px;
}
/* Schrift im Feld verkleinern: das schwebende Label (.v-label) und der
   angezeigte gewählte Eintrag (.v-select__selection-text). Beide rendert
   Vuetify in der Kindkomponente — daher :deep(). Die Einträge im aufgeklappten
   Menü sind NICHT erfasst; sie werden per Teleport an den App-Rand gehängt und
   liegen außerhalb dieser Komponente. */
.st-log-select :deep(.v-label),
.st-log-select :deep(.v-select__selection-text) {
  font-size: 0.87rem;
}
/* Checkbox nicht schrumpfen lassen: In der flex-Leiste würde sie sonst auf
   schmalen Smartphones von Auswahlfeld und Knöpfen zusammengedrückt, bis das
   Label Zeichen für Zeichen senkrecht umbricht. flex-shrink: 0 hält sie auf
   Inhaltsbreite; ist kein Platz, wandert sie dank flex-wrap als Ganzes in eine
   eigene Zeile. */
.st-log-check {
  flex: 0 0 auto;
}
/* Label der „Nur Warnungen und Fehler“-Checkbox verkleinern. Das Label rendert
   Vuetify als .v-label in der Kindkomponente — ohne :deep() käme die scoped
   Regel dort nicht an. nowrap verhindert zusätzlich den senkrechten Umbruch. */
.st-log-check :deep(.v-label) {
  font-size: 0.87rem;
  white-space: nowrap;
}
.st-log {
  max-height: 420px;
  overflow: auto;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: var(--mint-content);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.75rem;
  line-height: 1.5;
}
.st-log-line {
  display: flex;
  gap: 8px;
  padding: 1px 8px;
  border-bottom: 1px solid var(--mint-border);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.st-log-line:last-child { border-bottom: none; }
.st-log-time { flex: 0 0 auto; opacity: 0.55; }
.st-log-level { flex: 0 0 auto; min-width: 62px; font-weight: 700; }
.st-log-text { flex: 1 1 auto; min-width: 0; }

/* Kontingentanzeige des Dienstes (nur nach einer Prüfung sichtbar). */
.st-quota {
  margin-top: 6px;
  font-size: 0.78rem;
  max-width: 420px;
}
.st-quota-line {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 3px;
  opacity: 0.8;
}
.st-quota-rest { white-space: nowrap; }

/* Kennzeichnung einer Pro-Funktion. Ohne gültige Lizenz gedämpft und
   durchgestrichen — die Funktion ist dann vorhanden, aber nicht nutzbar. */
.st-pro {
  flex: 0 0 auto;
  margin-left: 6px;
  padding: 0 5px;
  border-radius: 4px;
  background: var(--mint-green);
  color: #fff;
  font-size: 0.65rem;
  font-weight: 700;
  line-height: 15px;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}
.st-pro--inactive {
  background: transparent;
  border: 1px solid var(--mint-border);
  color: var(--mint-text-muted, #777);
  text-decoration: line-through;
}

/* Platz in der Abarbeitungsreihenfolge des Verbesserers. */
.st-rank {
  flex: 0 0 auto;
  width: 22px;
  text-align: center;
  font-size: 0.78rem;
  opacity: 0.6;
  font-variant-numeric: tabular-nums;
}
</style>

<!-- Zweiter, NICHT scoped Style-Block: nur für das aufgeklappte Menü des
     Auswahlfelds. Das Menü hängt Vuetify per Teleport an den App-Rand, außerhalb
     dieser Komponente — scoped Regeln (auch mit :deep()) greifen dort nicht.
     Die eigene Klasse st-log-menu (über :menu-props gesetzt) grenzt die Regel
     wieder auf genau dieses Menü ein, sodass sie trotz globaler Reichweite
     nichts anderes trifft. -->
<style>
.st-log-menu .v-list-item-title {
  font-size: 0.87rem;
}
</style>
