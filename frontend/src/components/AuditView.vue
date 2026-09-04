<script setup>
// SEO-Audit-Vollbildansicht (Pro-Funktion). Startet Läufe, zeigt den Bericht
// gruppiert nach Kategorie und Schweregrad und springt aus einem Fund zur
// editierbaren Hugo-Quelldatei. Aufbau wie die Papierkorb-Ansicht (TrashView).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAssistantStore } from '../stores/assistant'
import { useAuditStore } from '../stores/audit'
import { useAuditContentStore } from '../stores/auditContent'
import { useAuthStore } from '../stores/auth'
import { useFilesStore } from '../stores/files'
import { useHelpStore } from '../stores/help'
import { errorText } from '../i18n/apiMessage'
import { groupFixableBySource, issueKey } from '../util/auditIssue'
import { useConfirm } from '../util/confirm'
import AuditSeverityChip from './AuditSeverityChip.vue'
import AuditIssueTable from './AuditIssueTable.vue'
import AuditContentList from './AuditContentList.vue'
import PageSpeedPanel from './PageSpeedPanel.vue'
import LiveAnalysisPanel from './LiveAnalysisPanel.vue'
import ProGate from './ProGate.vue'

// Die Lizenzaktivierung liegt in App.vue (LicenseDialog) — von hier angestoßen.
const emit = defineEmits(['activate-license'])

const { t, locale } = useI18n()
const assistant = useAssistantStore()
const audit = useAuditStore()
const auditContent = useAuditContentStore()
const auth = useAuthStore()
const files = useFilesStore()
const help = useHelpStore()
const confirm = useConfirm()
// Fehler bleibt stehen, bis die nächste Aktion ihn ersetzt: Eine Meldung, die
// sich nach acht Sekunden selbst ausblendet, hinterlässt ein Ergebnis, dem man
// nicht ansieht, ob es der letzte Lauf ist oder ein alter Stand.
const error = ref(null)

// Reiter: SEO-Bericht (Standard), LLM-Content-Qualität, PageSpeed, Live-Analyse.
// Alle bleiben sichtbar, auch ohne Freischaltung — der Inhalt zeigt dann den
// Pro-Hinweis statt der Ansicht.
const tab = ref('report')

// Funktionsname des gewählten Reiters, wenn dieser NICHT freigeschaltet ist —
// sonst null. Steuert, ob der Inhalt durch den Pro-Hinweis ersetzt wird.
const lockedTab = computed(() => {
  if (tab.value === 'content' && !auth.auditContent) return 'auditContent'
  if (tab.value === 'pagespeed' && !auth.pagespeed) return 'pagespeed'
  if (tab.value === 'live' && !auth.liveAnalysis) return 'liveAnalysis'
  if (tab.value === 'report' && !auth.audit) return 'audit'
  return null
})

// Klick auf die Problembeschreibung: ausführliche Hilfe zur Regel öffnen. Die
// HelpView legt sich als Überlagerung über den Bericht; beim Schließen erscheint
// er wieder (Audit-Modus bleibt aktiv).
function openHelp(ruleId) {
  help.open('audit', ruleId, locale.value)
}

const SEVERITIES = ['error', 'warning', 'hint']

// Feststehender Berichtskopf (Zusammenfassung + Kategoriefilter). Einklappbar,
// damit die Fundtabelle mehr Platz bekommt; bei jedem neuen Bericht wieder
// ausgeklappt.
const headerOpen = ref(true)
watch(
  () => audit.current?.id,
  () => { headerOpen.value = true },
)

// Suchbegriff (URL/Quelle) für die Fundtabelle. Das Feld sitzt im Berichtskopf,
// der Filter wirkt aber in AuditIssueTable — der entprellte Wert wird als Prop
// dorthin gereicht. Entprellt, damit nicht bei jedem Tastendruck bis zu Tausende
// Funde neu gefiltert und gerendert werden.
const searchInput = ref('') // an das Eingabefeld gebunden
const searchQuery = ref('') // entprellt, treibt den Filter in der Tabelle
let searchTimer = null
watch(searchInput, (v) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { searchQuery.value = v ?? '' }, 200)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

// Zahl der tatsächlich gezeigten Tabellenzeilen — kleiner als die Fundzahl,
// sobald die Tabelle Duplikate zu einer Zeile zusammenfasst. Kommt von dort
// zurück, damit die Statuszeile beides nennen kann.
const rowCount = ref(0)
const grouped = computed(() => rowCount.value > 0 && rowCount.value < audit.filteredIssues.length)

onMounted(async () => {
  // Ohne Freischaltung nichts laden: Der Bericht-Reiter zeigt dann den
  // Pro-Hinweis, und jeder Abruf liefe in ein PRO-REQUIRED des Servers.
  if (!auth.audit) return
  try {
    await audit.fetchRuns()
    if (!audit.current && audit.runs.length) {
      await audit.fetchRun(audit.runs[0].id)
    }
  } catch (e) {
    error.value = errorText(t, e)
  }
})

async function startRun() {
  error.value = null
  try {
    await audit.runAudit()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

async function selectRun(id) {
  error.value = null
  if (!id || id === audit.current?.id) return
  try {
    await audit.fetchRun(id)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

async function removeRun() {
  error.value = null
  if (!audit.current) return
  const ok = await confirm({
    title: t('audit.deleteTitle'),
    message: t('audit.deleteConfirm'),
    confirmText: t('audit.deleteAction'),
    color: 'error',
  })
  if (!ok) return
  try {
    await audit.deleteRun(audit.current.id)
    if (audit.runs.length) await audit.fetchRun(audit.runs[0].id)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// Verlauf aufräumen: alle Läufe bis auf den zuletzt erzeugten löschen.
async function removeOtherRuns() {
  error.value = null
  const ok = await confirm({
    title: t('audit.pruneTitle'),
    message: t('audit.pruneConfirm'),
    confirmText: t('audit.pruneAction'),
    color: 'error',
  })
  if (!ok) return
  try {
    await audit.deleteOtherRuns()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// KI-Micro-Auftrag zu genau einem Fund. Der Assistent öffnet sich mit dem
// bereits laufenden Auftrag (wie der Verbesserungslauf aus der Content-Liste);
// im Modus „confirm" pausiert er vor dem Schreiben und lässt bestätigen.
//
// Der Fund bleibt danach im Bericht stehen — dieser ist der Schnappschuss eines
// Laufs und wird nicht nachträglich verändert. Erledigte Funde merkt sich nur
// diese Sitzung, damit man beim Abarbeiten den Überblick behält.
const fixedKeys = ref([])

async function fixIssue(issue) {
  if (!audit.current?.id) return
  // Fehler zeigt das Assistenten-Panel selbst — es ist bereits geöffnet.
  const ok = await assistant.fixIssue(audit.current.id, issue.ruleId, issue.url ?? null, locale.value)
  if (!ok) return
  const key = issue.ruleId + '|' + (issue.url || issue.sourceFile || '')
  if (!fixedKeys.value.includes(key)) fixedKeys.value = [...fixedKeys.value, key]
}

// Diagnose zu einem Fund, der im Theme, in der Hugo-Konfiguration oder in der
// Seitenstruktur wurzelt. Der Assistent liest sich durch das Projekt und
// erklärt, was wo zu ändern wäre — geschrieben wird nichts, also gibt es auch
// keinen Erledigt-Merker.
function diagnoseIssue(issue) {
  if (!audit.current?.id) return
  assistant.fixIssue(audit.current.id, issue.ruleId, issue.url ?? null, locale.value, 'diagnose')
}

// --- Auswahl und Sammelaktionen ----------------------------------------
// Die Auswahl lebt hier und nicht in der Tabelle: Die Sammelaktionen brauchen
// die vollständigen Funde (Quelldatei, fixable), die Tabelle nur die Schlüssel.
const selectedKeys = ref([])

// Schlüssel → Fund, über ALLE Funde des Berichts. Bewusst nicht nur über die
// gefilterten: Eine Auswahl, die durch einen Filterwechsel aus der Anzeige
// fällt, bleibt bestehen (die Leiste nennt ihre Zahl) und darf beim Ausführen
// nicht stillschweigend zusammenschrumpfen.
const issuesByKey = computed(() => {
  const map = new Map()
  for (const issue of audit.current?.issues ?? []) map.set(issueKey(issue), issue)
  return map
})

const selectedIssues = computed(() =>
  selectedKeys.value.map((k) => issuesByKey.value.get(k)).filter(Boolean),
)

// Für die KI-Bündelung: je Content-Datei ein Auftrag. Was nicht über die
// Content-Datei behebbar ist (Theme, Konfiguration, Struktur), fällt dabei
// heraus — die Leiste weist die Differenz aus, statt sie zu verschweigen.
const fixGroups = computed(() => groupFixableBySource(selectedIssues.value))
const fixableCount = computed(() =>
  fixGroups.value.reduce((n, g) => n + g.issues.length, 0),
)
// Wie viele der gewählten Funde bereits ignoriert sind — entscheidet, welche
// der beiden Ignorier-Schaltflächen sinnvoll ist.
const selectedIgnoredCount = computed(() => selectedIssues.value.filter((i) => i.ignored).length)

function clearSelection() {
  selectedKeys.value = []
}

// Auswahl dauerhaft ignorieren bzw. wieder aufnehmen. Die Vormerkung gilt je
// Webseite: Sie überlebt neue Läufe und wirkt auch auf die gespeicherten.
async function ignoreSelected(ignored) {
  error.value = null
  const keys = selectedKeys.value
  if (!keys.length) return
  try {
    await audit.setIgnored(keys, ignored)
    clearSelection()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// Einen einzelnen Fund ignorieren/aufnehmen (Zeilenknopf, ohne Auswahl).
async function toggleIgnore(issue) {
  error.value = null
  try {
    await audit.setIgnored([issueKey(issue)], !issue.ignored)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// Sammelauftrag an den KI-Assistenten: EIN Auftrag je Content-Datei, alle ihre
// Funde zusammen — das Modell liest die Datei einmal und sieht die Mängel im
// Zusammenhang. Über mehrere Dateien hinweg arbeitet der Assistent die Gruppen
// nacheinander ab (siehe assistant.fixIssueGroups).
async function fixSelected() {
  error.value = null
  if (!audit.current?.id || !fixGroups.value.length) return
  const groups = fixGroups.value
  const ok = await confirm({
    title: t('audit.bulkFixTitle'),
    message: t('audit.bulkFixConfirm', [fixableCount.value, groups.length]),
    confirmText: t('audit.bulkFixAction'),
  })
  if (!ok) return
  clearSelection()
  await assistant.fixIssueGroups(audit.current.id, groups, locale.value)
}

// Abgesetzte Aufträge als erledigt kennzeichnen — dieselbe Markierung wie beim
// Einzelknopf. Sie wächst mit jedem abgeschickten Auftrag, nicht erst am Ende:
// Bricht die Reihe ab, bleibt sichtbar, was schon durchlief.
watch(
  () => assistant.batch?.submittedKeys.length ?? 0,
  () => {
    const keys = assistant.batch?.submittedKeys ?? []
    const next = new Set(fixedKeys.value)
    for (const k of keys) next.add(k)
    fixedKeys.value = [...next]
  },
)

// Neuer Bericht (anderer Lauf oder frischer Durchlauf): die Erledigt-Merker
// gehören zum alten Lauf und werden verworfen — die Auswahl ebenso.
watch(() => audit.current?.id, () => {
  fixedKeys.value = []
  clearSelection()
})

async function openSource(issue) {
  error.value = null
  try {
    // Audit-Modus bewusst NICHT verlassen: Der Editor legt sich nur als
    // Überlagerung über die Audit-Ansicht. Beim Schließen erscheint der Bericht
    // wieder — mit unveränderten Filtern und Scrollposition, sodass sich die
    // Funde ohne Unterbrechung abarbeiten lassen. (Aus der Dateiliste geöffnet
    // liegt entsprechend diese darunter und erscheint beim Schließen.)
    await files.openFileById(issue.fileId)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

function categoryCount(cat) {
  return audit.current?.byCategory?.[cat]?.total ?? 0
}

function runLabel(run) {
  const when = run.startedAt ? new Date(run.startedAt).toLocaleString() : run.id
  const s = run.summary || {}
  return `${when} — ${s.error || 0}/${s.warning || 0}/${s.hint || 0}`
}
</script>

<template>
  <section class="nemo-view audit-overlay">
    <!-- Kopfzeile: Zurück + Titel. Bleibt über beide Reiter hinweg gleich; die
         berichtsbezogenen Aktionen (Laufsteuerung, Suche) sitzen im
         Berichtskopf, damit beim Reiterwechsel hier nichts erscheint/verschwindet. -->
    <div class="audit-head nemo-noselect">
      <button class="audit-back" :title="$t('audit.close')" @click="files.leaveAudit()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-clipboard-search-outline" size="18" />
      <span class="audit-title">{{ $t('audit.title') }}</span>
    </div>

    <!-- Reiter immer vollständig: Auch nicht freigeschaltete Ansichten bleiben
         sichtbar (mit Schloss) und zeigen im Inhalt, was sie leisten würden. -->
    <div class="audit-tabs nemo-noselect">
      <button class="audit-tab" :class="{ active: tab === 'report' }" @click="tab = 'report'">
        <v-icon icon="mdi-clipboard-search-outline" size="16" class="mr-1" />{{ $t('audit.tabReport') }}
      </button>
      <button
        class="audit-tab"
        :class="{ active: tab === 'content', locked: !auth.auditContent }"
        @click="tab = 'content'"
      >
        <v-icon icon="mdi-text-search" size="16" class="mr-1" />{{ $t('contentQuality.title') }}
        <v-icon v-if="!auth.auditContent" icon="mdi-lock-outline" size="12" class="ml-1" />
      </button>
      <button
        class="audit-tab"
        :class="{ active: tab === 'pagespeed', locked: !auth.pagespeed }"
        @click="tab = 'pagespeed'"
      >
        <v-icon icon="mdi-speedometer" size="16" class="mr-1" />{{ $t('pagespeed.tab') }}
        <v-icon v-if="!auth.pagespeed" icon="mdi-lock-outline" size="12" class="ml-1" />
      </button>
      <button
        class="audit-tab"
        :class="{ active: tab === 'live', locked: !auth.liveAnalysis }"
        @click="tab = 'live'"
      >
        <v-icon icon="mdi-radar" size="16" class="mr-1" />{{ $t('liveAnalysis.tab') }}
        <v-icon v-if="!auth.liveAnalysis" icon="mdi-lock-outline" size="12" class="ml-1" />
      </button>
    </div>

    <v-alert v-if="error" type="error" density="compact" class="ma-2 nemo-alert" tile closable @click:close="error = null">
      {{ error }}
    </v-alert>

    <!-- Feststehender Berichtskopf: bleibt beim Scrollen der Fundtabelle stehen.
         Der Umschalter steht vor der Zusammenfassung und bleibt auch eingeklappt
         sichtbar, damit sich der Kopf wieder ausklappen lässt. -->
    <div
      v-if="tab === 'report' && audit.current && !audit.running && !audit.loading"
      class="audit-rhead nemo-noselect"
    >
      <div class="audit-summary">
        <button
          class="audit-headtoggle"
          :title="headerOpen ? $t('audit.collapseHeader') : $t('audit.expandHeader')"
          @click="headerOpen = !headerOpen"
        >
          <v-icon :icon="headerOpen ? 'mdi-unfold-less-horizontal' : 'mdi-unfold-more-horizontal'" size="20" />
        </button>
        <div class="audit-meta">
          {{ $t('audit.pagesScanned', [audit.current.pagesScanned]) }} ·
          {{ $t('audit.duration', [audit.current.seconds]) }}
          <span v-if="audit.current.truncated" class="audit-trunc">· {{ $t('audit.truncated') }}</span>
        </div>

        <!-- Laufsteuerung: Verlauf wählen, Lauf starten/löschen. Sitzt jetzt im
             Berichtskopf und klappt mit ihm ein und aus; die Zusammenfassung
             (Umschalter + Meta) bleibt sichtbar. -->
        <div v-show="headerOpen" class="audit-runctl">
          <v-select
            v-if="audit.runs.length"
            :model-value="audit.current?.id ?? null"
            :items="audit.runs"
            :item-title="runLabel"
            item-value="id"
            density="compact"
            variant="outlined"
            hide-details
            class="audit-runselect"
            :label="$t('audit.history')"
            @update:model-value="selectRun"
          />
          <button class="audit-btn primary" :disabled="audit.running" @click="startRun">
            <v-progress-circular v-if="audit.running" indeterminate size="14" width="2" class="mr-1" />
            <v-icon v-else icon="mdi-play" size="16" class="mr-1" />{{ $t('audit.run') }}
          </button>
          <button
            class="audit-btn danger"
            :disabled="audit.running || !audit.current"
            @click="removeRun"
          >
            <v-icon icon="mdi-delete-outline" size="16" class="mr-1" />{{ $t('audit.delete') }}
          </button>
          <!-- Verlauf aufräumen: erst sinnvoll, wenn es mehr als einen Lauf gibt. -->
          <button
            v-if="audit.runs.length > 1"
            class="audit-btn danger"
            :disabled="audit.running"
            :title="$t('audit.pruneHint')"
            @click="removeOtherRuns"
          >
            <v-icon icon="mdi-broom" size="16" class="mr-1" />{{ $t('audit.pruneOthers') }}
          </button>
        </div>
      </div>

      <!-- Suche (URL/Quelle) und Schweregradfilter: gemeinsame, einklappbare
           Zeile. Die Suche filtert die Fundtabelle; der Wert wird entprellt an
           AuditIssueTable gereicht. -->
      <div v-show="headerOpen" class="audit-filterrow">
        <div v-if="audit.current.issues?.length" class="audit-search">
          <v-text-field
            v-model="searchInput"
            :placeholder="$t('audit.searchPlaceholder')"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            variant="outlined"
            hide-details
            clearable
          />
        </div>
        <div class="audit-sevfilters">
          <button
            class="audit-chip"
            :class="{ active: audit.severityFilter === 'all' }"
            @click="audit.setSeverityFilter('all')"
          >
            {{ $t('audit.allSeverities') }}
          </button>
          <button
            v-for="sev in SEVERITIES"
            :key="sev"
            class="audit-chip-wrap"
            :class="{ dim: audit.severityFilter !== 'all' && audit.severityFilter !== sev }"
            @click="audit.setSeverityFilter(audit.severityFilter === sev ? 'all' : sev)"
          >
            <AuditSeverityChip :severity="sev" :count="audit.current.summary[sev] ?? 0" />
          </button>
          <!-- Ignorierte: eigener Blick. In allen anderen Ansichten laufen sie
               am Ende der Liste mit; hier stehen sie für sich. Der Chip
               erscheint erst, wenn es etwas zu zeigen gibt. -->
          <button
            v-if="audit.ignoredCount"
            class="audit-chip audit-chip-ignored"
            :class="{ active: audit.severityFilter === 'ignored' }"
            :title="$t('audit.ignoredFilterHint')"
            @click="audit.setSeverityFilter(audit.severityFilter === 'ignored' ? 'all' : 'ignored')"
          >
            <v-icon icon="mdi-bell-off-outline" size="13" class="mr-1" />
            {{ $t('audit.ignoredFilter') }} <span class="audit-catcount">{{ audit.ignoredCount }}</span>
          </button>
        </div>
      </div>

      <!-- Kategorie-Filter -->
      <div v-show="headerOpen" class="audit-cats">
        <button
          v-for="cat in audit.categories"
          :key="cat"
          class="audit-chip"
          :class="{ active: audit.categoryFilter === cat }"
          @click="audit.setCategoryFilter(cat)"
        >
          {{ $t('audit.category.' + cat) }} <span class="audit-catcount">{{ categoryCount(cat) }}</span>
        </button>
      </div>
    </div>

    <!-- Sammelaktionen: erscheinen erst mit einer Auswahl und legen sich als
         eigene Leiste zwischen Berichtskopf und Liste. -->
    <div v-if="tab === 'report' && selectedKeys.length" class="audit-bulkbar nemo-noselect">
      <span class="audit-bulkcount">{{ $t('audit.selectedCount', [selectedKeys.length]) }}</span>

      <button
        v-if="selectedIgnoredCount < selectedKeys.length"
        class="audit-btn"
        @click="ignoreSelected(true)"
      >
        <v-icon icon="mdi-bell-off-outline" size="16" class="mr-1" />{{ $t('audit.ignoreSelected') }}
      </button>
      <button v-if="selectedIgnoredCount > 0" class="audit-btn" @click="ignoreSelected(false)">
        <v-icon icon="mdi-bell-outline" size="16" class="mr-1" />{{ $t('audit.unignoreSelected') }}
      </button>

      <button
        class="audit-btn primary"
        :disabled="!fixGroups.length || assistant.busy"
        :title="$t('audit.bulkFixHint')"
        @click="fixSelected"
      >
        <v-icon icon="mdi-auto-fix" size="16" class="mr-1" />{{ $t('audit.fixSelected') }}
      </button>
      <!-- Ehrlich statt stillschweigend: Was im Theme oder in der Konfiguration
           wurzelt, kann der Sammelauftrag nicht anfassen. -->
      <span class="audit-bulknote">
        {{ fixableCount === selectedKeys.length
          ? $t('audit.bulkFixAll', [fixGroups.length])
          : $t('audit.bulkFixSome', [fixableCount, selectedKeys.length, fixGroups.length]) }}
      </span>

      <button class="audit-btn" @click="clearSelection">
        <v-icon icon="mdi-close" size="16" class="mr-1" />{{ $t('audit.clearSelection') }}
      </button>
    </div>

    <!-- Laufender Sammelauftrag: zeigt, bei welcher Datei die Reihe steht, und
         lässt sie anhalten. Ohne das wirkte das Zurücksetzen des Gesprächs
         zwischen zwei Dateien wie ein Fehler. -->
    <div v-if="tab === 'report' && assistant.batchActive" class="audit-batchbar nemo-noselect">
      <v-progress-circular indeterminate size="14" width="2" class="mr-1" />
      <span>{{ $t('audit.batchProgress', [
        assistant.batch.index + 1,
        assistant.batch.groups.length,
        assistant.batch.groups[assistant.batch.index]?.sourceFile ?? '',
      ]) }}</span>
      <button class="audit-btn" @click="assistant.stopBatch()">
        <v-icon icon="mdi-stop" size="16" class="mr-1" />{{ $t('audit.batchStop') }}
      </button>
    </div>

    <div class="nemo-content nemo-scroll">
      <!-- Gesperrter Reiter: statt der Ansicht der Hinweis, was sie leistet.
           Die Panels laden beim Einhängen Daten — sie dürfen hier gar nicht
           erst erzeugt werden. -->
      <ProGate
        v-if="lockedTab"
        :feature="lockedTab"
        @activate="emit('activate-license')"
      />

      <!-- Reiter „Content-Qualität": Liste der geprüften Seiten -->
      <AuditContentList v-else-if="tab === 'content'" />

      <!-- Reiter „PageSpeed": Geschwindigkeitsmessung der Live-Adresse -->
      <PageSpeedPanel v-else-if="tab === 'pagespeed'" />

      <!-- Reiter „Live-Analyse": Live-Crawl der Produktionssite (seo-success) -->
      <LiveAnalysisPanel v-else-if="tab === 'live'" />

      <!-- Läuft gerade / Bericht wird geladen -->
      <div v-else-if="audit.running || audit.loading" class="nemo-empty">
        <v-progress-circular indeterminate size="40" width="3" color="primary" />
        <p>{{ audit.running ? $t('audit.running') : $t('audit.loading') }}</p>
      </div>

      <!-- Noch kein Bericht -->
      <div v-else-if="!audit.current" class="nemo-empty">
        <v-icon icon="mdi-clipboard-search-outline" size="56" class="nemo-empty-icon" />
        <p>{{ $t('audit.empty') }}</p>
        <button class="audit-btn primary" @click="startRun">
          <v-icon icon="mdi-play" size="16" class="mr-1" />{{ $t('audit.run') }}
        </button>
      </div>

      <!-- Bericht -->
      <AuditIssueTable
        v-else
        :issues="audit.filteredIssues"
        :search="searchQuery"
        :fixed-keys="fixedKeys"
        :busy="assistant.busy"
        :selected="selectedKeys"
        @open-source="openSource"
        @open-help="openHelp"
        @fix-issue="fixIssue"
        @diagnose-issue="diagnoseIssue"
        @toggle-ignore="toggleIgnore"
        @update:row-count="rowCount = $event"
        @update:selected="selectedKeys = $event"
      />
    </div>

    <footer class="nemo-statusbar nemo-noselect">
      <span v-if="tab === 'content'">
        {{ $t('contentQuality.pageCount', [auditContent.checked.length]) }}
      </span>
      <span v-else-if="tab === 'pagespeed'">{{ $t('pagespeed.tab') }}</span>
      <span v-else-if="tab === 'live'">{{ $t('liveAnalysis.tab') }}</span>
      <span v-else-if="audit.current">
        {{ grouped
          ? $t('audit.issueCountGrouped', [audit.filteredIssues.length, rowCount])
          : $t('audit.issueCount', [audit.filteredIssues.length]) }}
      </span>
      <span v-else>{{ $t('audit.subtitle') }}</span>
    </footer>
  </section>
</template>

<style scoped>
.nemo-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--mint-content);
  min-width: 0;
}

/* Der Bericht legt sich als eigenständige Überlagerung über den gesamten
   Arbeitsbereich (Werkzeugleiste + Seitenleiste + Dateiliste) — wie der
   Editor, aber darunter (z-index kleiner als .editor-overlay = 10), damit ein
   aus einem Fund geöffneter Editor obenauf liegt. */
.audit-overlay {
  position: absolute;
  inset: 0;
  z-index: 9;
}

/* Leiste der Sammelaktionen (nur mit Auswahl sichtbar). */
.audit-bulkbar,
.audit-batchbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding: 6px 12px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  font-size: 0.84rem;
  color: var(--mint-text);
}
.audit-bulkcount { font-weight: 600; }
.audit-bulknote { color: var(--mint-text-muted); font-size: 0.8rem; }
.audit-batchbar { color: var(--mint-text-muted); }
/* Filter-Chip der ignorierten Funde: gedämpft, er zeigt nichts Dringliches. */
.audit-chip-ignored { color: var(--mint-text-muted); }

.audit-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
/* Zurück-Schaltfläche: schließt die Überlagerung (wie der Editor-Pfeil). */
.audit-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 1px solid transparent;
  border-radius: var(--mint-radius);
  background: transparent;
  color: var(--mint-text);
  cursor: pointer;
}
.audit-back:hover { background: var(--mint-panel-hover); border-color: var(--mint-border); }

.audit-title { font-weight: 600; font-size: 0.92rem; }
.audit-runselect { max-width: 280px; }

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
.audit-btn:hover:not(:disabled) { background: var(--mint-panel-hover); }
.audit-btn:disabled { color: #b6b6b3; cursor: default; }
.audit-btn.primary { border-color: var(--mint-green); color: var(--mint-green); }
.audit-btn.primary:hover:not(:disabled) { background: var(--mint-green); color: #fff; }
.audit-btn.danger:hover:not(:disabled) {
  background: #fbeaea;
  border-color: #d9b0ab;
  color: #b03a2e;
}

/* Reiterleiste zwischen Kopfzeile und Bericht/Liste. */
.audit-tabs {
  display: flex;
  gap: 4px;
  padding: 4px 12px 0;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
}
.audit-tab {
  display: inline-flex;
  align-items: center;
  border: 1px solid transparent;
  border-bottom: none;
  border-radius: var(--mint-radius) var(--mint-radius) 0 0;
  background: transparent;
  padding: 6px 14px;
  font-size: 0.85rem;
  color: var(--mint-text-muted);
  cursor: pointer;
}
.audit-tab:hover { color: var(--mint-text); }
/* Nicht freigeschalteter Reiter: anwählbar, aber zurückgenommen — sein Inhalt
   erklärt, was die Funktion leistet. */
.audit-tab.locked { opacity: 0.6; }
.audit-tab.active {
  background: var(--mint-content);
  border-color: var(--mint-border);
  color: var(--mint-text);
  font-weight: 600;
}

/* Feststehender Berichtskopf: schrumpft nicht, scrollt nicht mit der Tabelle
   (liegt außerhalb des scrollenden .nemo-content). */
.audit-rhead { flex: 0 0 auto; }

/* Umschalter zum Ein-/Ausklappen des Kopfes: flache Icon-Schaltfläche,
   steht vor der Zusammenfassung und bleibt auch eingeklappt sichtbar. */
.audit-headtoggle {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: 1px solid transparent;
  border-radius: var(--mint-radius);
  background: transparent;
  color: var(--mint-text);
  cursor: pointer;
}
.audit-headtoggle:hover { background: var(--mint-panel-hover); border-color: var(--mint-border); }

.audit-summary {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 16px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--mint-border);
}
.audit-meta { font-size: 0.82rem; color: var(--mint-text-muted); }
.audit-trunc { color: #b03a2e; }

/* Laufsteuerung im Berichtskopf: rechtsbündig neben der Zusammenfassung. */
.audit-runctl {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-left: auto;
}

/* Gemeinsame Zeile für Suchfeld (links) und Schweregradfilter (rechts). */
.audit-filterrow {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 16px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--mint-border);
}
.audit-search { flex: 1 1 240px; max-width: 340px; }
.audit-sevfilters { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-left: auto; }
/* Nicht schrumpfen: sonst quetscht der Flex-Container den Chip zusammen und
   dessen interner overflow:hidden schneidet den Text ab ("Hin" statt "Hinweis").
   Bei Platzmangel soll die Zeile stattdessen umbrechen (flex-wrap greift). */
.audit-chip-wrap { background: none; border: 0; padding: 0; cursor: pointer; flex: 0 0 auto; }
.audit-chip-wrap.dim { opacity: 0.4; }

.audit-cats {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--mint-border);
}
.audit-chip {
  border: 1px solid var(--mint-border);
  border-radius: 999px;
  background: #fff;
  padding: 2px 12px;
  font-size: 0.8rem;
  color: var(--mint-text);
  cursor: pointer;
}
.audit-chip:hover { background: var(--mint-panel-hover); }
.audit-chip.active { background: var(--mint-green); border-color: var(--mint-green); color: #fff; }
.audit-catcount { color: var(--mint-text-muted); margin-left: 4px; }
.audit-chip.active .audit-catcount { color: #e8f1e3; }

/* Schmale Schirme (Handy): Laufsteuerung und Filterzeile umbrechen statt nach
   rechts überzulaufen. Auswahlfeld und Suchfeld füllen jeweils die volle Breite,
   die Filter rücken nach links (kein margin-left:auto). */
@media (max-width: 599.98px) {
  .audit-runctl {
    width: 100%;
    margin-left: 0;
  }
  .audit-runselect {
    flex: 1 1 100%;
    max-width: 100%;
  }
  .audit-search {
    flex-basis: 100%;
    max-width: 100%;
  }
  .audit-sevfilters {
    margin-left: 0;
  }
}

.nemo-content { flex: 1 1 auto; overflow: auto; }
.nemo-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: var(--mint-text-muted);
  gap: 12px;
}
.nemo-empty-icon { color: #c4c4c0; }

.nemo-statusbar {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 6px;
  height: 26px;
  padding: 0 12px;
  background: var(--mint-panel);
  border-top: 1px solid var(--mint-border);
  font-size: 0.78rem;
  color: var(--mint-text-muted);
}
</style>
