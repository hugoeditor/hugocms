<script setup>
// SEO-Audit-Vollbildansicht (Pro-Funktion). Startet Läufe, zeigt den Bericht
// gruppiert nach Kategorie und Schweregrad und springt aus einem Fund zur
// editierbaren Hugo-Quelldatei. Aufbau wie die Papierkorb-Ansicht (TrashView).
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditStore } from '../stores/audit'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'
import { useConfirm } from '../util/confirm'
import AuditSeverityChip from './AuditSeverityChip.vue'
import AuditIssueTable from './AuditIssueTable.vue'

const { t } = useI18n()
const audit = useAuditStore()
const files = useFilesStore()
const confirm = useConfirm()
const error = useTransientError()

const SEVERITIES = ['error', 'warning', 'hint']

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
    await files.openFileById(issue.fileId)
    files.leaveAudit()
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
  <section class="nemo-view">
    <!-- Kopfzeile mit Aktionen -->
    <div class="audit-head nemo-noselect">
      <v-icon icon="mdi-clipboard-search-outline" size="18" />
      <span class="audit-title">{{ $t('audit.title') }}</span>
      <div class="audit-head-spacer" />

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
    </div>

    <v-alert v-if="error" type="error" density="compact" class="ma-2 nemo-alert" tile closable @click:close="error = null">
      {{ error }}
    </v-alert>

    <div class="nemo-content nemo-scroll">
      <!-- Läuft gerade / Bericht wird geladen -->
      <div v-if="audit.running || audit.loading" class="nemo-empty">
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
      <template v-else>
        <div class="audit-summary nemo-noselect">
          <div class="audit-meta">
            {{ $t('audit.pagesScanned', [audit.current.pagesScanned]) }} ·
            {{ $t('audit.duration', [audit.current.seconds]) }}
            <span v-if="audit.current.truncated" class="audit-trunc">· {{ $t('audit.truncated') }}</span>
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
          </div>
        </div>

        <!-- Kategorie-Filter -->
        <div class="audit-cats nemo-noselect">
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

        <AuditIssueTable :issues="audit.filteredIssues" @open-source="openSource" />
      </template>
    </div>

    <footer class="nemo-statusbar nemo-noselect">
      <span v-if="audit.current">
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

.audit-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
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
.audit-chip-wrap { background: none; border: 0; padding: 0; cursor: pointer; }
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
