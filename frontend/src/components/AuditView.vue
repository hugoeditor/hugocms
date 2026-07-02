<script setup>
// SEO-Audit-Vollbildansicht (Pro-Funktion). Startet Läufe, zeigt den Bericht
// gruppiert nach Kategorie und Schweregrad und springt aus einem Fund zur
// editierbaren Hugo-Quelldatei. Aufbau wie die Papierkorb-Ansicht (TrashView).
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditStore } from '../stores/audit'
import { useAuditContentStore } from '../stores/auditContent'
import { useAuthStore } from '../stores/auth'
import { useFilesStore } from '../stores/files'
import { useHelpStore } from '../stores/help'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'
import { useConfirm } from '../util/confirm'
import AuditSeverityChip from './AuditSeverityChip.vue'
import AuditIssueTable from './AuditIssueTable.vue'
import AuditContentList from './AuditContentList.vue'

const { t, locale } = useI18n()
const audit = useAuditStore()
const auditContent = useAuditContentStore()
const auth = useAuthStore()
const files = useFilesStore()
const help = useHelpStore()
const confirm = useConfirm()
const error = useTransientError()

// Reiter: SEO-Bericht (Standard) oder LLM-Content-Qualität. Der zweite Reiter
// erscheint nur, wenn freigeschaltet (Pro + KI-Schlüssel).
const tab = ref('report')

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

onMounted(async () => {
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
  if (!id || id === audit.current?.id) return
  try {
    await audit.fetchRun(id)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

async function removeRun() {
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

async function openSource(issue) {
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
    <!-- Kopfzeile mit Aktionen -->
    <div class="audit-head nemo-noselect">
      <button class="audit-back" :title="$t('audit.close')" @click="files.leaveAudit()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-clipboard-search-outline" size="18" />
      <span class="audit-title">{{ $t('audit.title') }}</span>
      <div class="audit-head-spacer" />

      <template v-if="tab === 'report'">
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
      </template>
    </div>

    <!-- Reiter: nur wenn die Content-Prüfung freigeschaltet ist (sonst gibt es
         nur den Bericht und eine Reiterleiste wäre überflüssig). -->
    <div v-if="auth.auditContent" class="audit-tabs nemo-noselect">
      <button class="audit-tab" :class="{ active: tab === 'report' }" @click="tab = 'report'">
        <v-icon icon="mdi-clipboard-search-outline" size="16" class="mr-1" />{{ $t('audit.tabReport') }}
      </button>
      <button class="audit-tab" :class="{ active: tab === 'content' }" @click="tab = 'content'">
        <v-icon icon="mdi-text-search" size="16" class="mr-1" />{{ $t('contentQuality.title') }}
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
        <div v-show="headerOpen" class="audit-sevfilters">
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

    <div class="nemo-content nemo-scroll">
      <!-- Reiter „Content-Qualität": Liste der geprüften Seiten -->
      <AuditContentList v-if="tab === 'content'" />

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
        @open-source="openSource"
        @open-help="openHelp"
      />
    </div>

    <footer class="nemo-statusbar nemo-noselect">
      <span v-if="tab === 'content'">
        {{ $t('contentQuality.pageCount', [auditContent.checked.length]) }}
      </span>
      <span v-else-if="audit.current">
        {{ $t('audit.issueCount', [audit.filteredIssues.length]) }}
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
.audit-head-spacer { flex: 1 1 auto; }

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

/* Schmale Schirme (Handy): Kopfzeile umbrechen statt nach rechts überlaufen.
   Auswahlfeld füllt eine eigene Zeile, der Abstandshalter entfällt, damit die
   Schaltflächen nicht auseinandergedrückt werden. */
@media (max-width: 599.98px) {
  .audit-head {
    flex-wrap: wrap;
    row-gap: 6px;
  }
  .audit-head-spacer {
    display: none;
  }
  .audit-runselect {
    order: 10;
    flex: 1 1 100%;
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
