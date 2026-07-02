<script setup>
// Dialog des Gesamt-Berichts einer Content-Datei (Pro-Funktion): das
// LLM-Qualitätsurteil (Score, Lesbarkeit, Befunde, Vorschläge) UND die SEO-Funde
// derselben Datei aus dem jüngsten Audit-Lauf. Wird aus dem Editor, dem
// Kontextmenü der Dateiliste und dem Content-Reiter der AuditView geöffnet. Kein
// eigener Modus/Overlay — bewusst als überlagernder Dialog.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditContentStore } from '../stores/auditContent'
import { useAssistantStore } from '../stores/assistant'
import { useFilesStore } from '../stores/files'
import { useHelpStore } from '../stores/help'
import { errorText } from '../i18n/apiMessage'
import AuditSeverityChip from './AuditSeverityChip.vue'
import AuditIssueTable from './AuditIssueTable.vue'

const { t, locale } = useI18n()
const store = useAuditContentStore()
const assistant = useAssistantStore()
const files = useFilesStore()
const help = useHelpStore()

const entry = computed(() => store.current?.contentQuality ?? null)
const verdict = computed(() => entry.value?.verdict ?? null)
const audit = computed(() => store.current?.audit ?? null)
const fileId = computed(() => store.current?.file?.fileId ?? null)

function scoreColor(score) {
  if (score == null) return 'grey'
  if (score >= 70) return 'success'
  if (score >= 40) return 'warning'
  return 'error'
}

const RATING_COLOR = { good: 'success', medium: 'warning', weak: 'error' }

function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value)
}

// Erneut prüfen: nutzt die fileId der aktuellen Datei.
function recheck() {
  if (fileId.value) store.recheck(fileId.value, store.current?.file?.title, locale.value)
}

// Zur Quelldatei springen: Editor öffnen, Dialog schließen. Die ID VOR dem
// Schließen festhalten — closeDialog() leert store.current, wovon das
// fileId-Computed abhängt.
async function toSource() {
  const id = fileId.value
  if (!id) return
  store.closeDialog()
  await files.openFileById(id)
}

// Von der KI verbessern lassen: Dialog schließen, Assistenten-Panel öffnen und
// den Verbesserungslauf starten (Bericht → Bearbeitung, im confirm-Modus mit
// Bestätigung). Die ID ebenfalls vor dem Schließen festhalten.
function improve() {
  const id = fileId.value
  if (!id) return
  store.closeDialog()
  assistant.improve(id, locale.value)
}

// SEO-Fund: zur Quelle springen bzw. ausführliche Regel-Hilfe öffnen.
async function openIssueSource(issue) {
  if (!issue.fileId) return
  store.closeDialog()
  await files.openFileById(issue.fileId)
}

function openHelp(ruleId) {
  help.open('audit', ruleId, locale.value)
}
</script>

<template>
  <v-dialog
    :model-value="store.dialogOpen"
    width="720"
    scrollable
    @update:model-value="(v) => !v && store.closeDialog()"
  >
    <v-card>
      <v-card-title class="d-flex align-center text-subtitle-1">
        <v-icon icon="mdi-text-search" class="mr-2" />
        <span class="text-truncate">{{ store.fileName || $t('contentQuality.title') }}</span>
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" density="comfortable" @click="store.closeDialog()" />
      </v-card-title>

      <v-divider />

      <v-card-text style="max-height: 70vh">
        <!-- Prüflauf läuft -->
        <div v-if="store.busy" class="d-flex flex-column align-center py-8 text-medium-emphasis">
          <v-progress-circular indeterminate color="primary" class="mb-3" />
          <div>{{ $t('contentQuality.checking') }}</div>
        </div>

        <!-- Gespeichertes Ergebnis wird geladen -->
        <div v-else-if="store.loading" class="d-flex justify-center py-8">
          <v-progress-circular indeterminate color="primary" />
        </div>

        <!-- Fehler -->
        <v-alert v-else-if="store.error" type="error" density="comfortable" class="mb-2">
          {{ errorText(t, store.error) }}
        </v-alert>

        <!-- Ergebnis -->
        <template v-else-if="verdict">
          <div class="d-flex align-center mb-3">
            <v-avatar :color="scoreColor(verdict.score)" size="56" class="mr-4">
              <span class="text-h6">{{ verdict.score ?? '–' }}</span>
            </v-avatar>
            <div>
              <div class="text-caption text-medium-emphasis">{{ $t('contentQuality.score') }}</div>
              <v-chip
                v-if="verdict.readability?.rating"
                :color="RATING_COLOR[verdict.readability.rating] || 'default'"
                size="small"
                variant="flat"
                label
              >
                {{ $t('contentQuality.readability.' + verdict.readability.rating) }}
              </v-chip>
            </div>
          </div>

          <p v-if="verdict.summary" class="mb-2">{{ verdict.summary }}</p>
          <p v-if="verdict.readability?.note" class="text-body-2 text-medium-emphasis mb-4">
            {{ verdict.readability.note }}
          </p>

          <!-- Funde -->
          <template v-if="verdict.findings?.length">
            <div class="text-subtitle-2 mb-2">{{ $t('contentQuality.findings') }}</div>
            <div v-for="(f, i) in verdict.findings" :key="'f' + i" class="cq-finding mb-2">
              <AuditSeverityChip :severity="f.severity" size="x-small" />
              <div class="ml-2">
                <div class="font-weight-medium">{{ f.title }}</div>
                <div class="text-body-2 text-medium-emphasis">{{ f.detail }}</div>
              </div>
            </div>
          </template>

          <!-- Vorschläge -->
          <template v-if="verdict.suggestions?.length">
            <div class="text-subtitle-2 mt-4 mb-1">{{ $t('contentQuality.suggestions') }}</div>
            <ul class="cq-suggestions">
              <li v-for="(s, i) in verdict.suggestions" :key="'s' + i">{{ s }}</li>
            </ul>
          </template>

          <div class="text-caption text-medium-emphasis mt-4">
            {{ $t('contentQuality.checkedAt', [formatDate(entry.checkedAt)]) }}
            <template v-if="entry.model"> · {{ entry.model }}</template>
            <span v-if="entry.truncated"> · {{ $t('contentQuality.truncated') }}</span>
          </div>

          <!-- SEO-Funde derselben Datei aus dem jüngsten Audit-Lauf -->
          <template v-if="audit">
            <v-divider class="my-4" />
            <div class="d-flex align-center mb-2">
              <div class="text-subtitle-2">{{ $t('contentQuality.seoIssues') }}</div>
              <v-spacer />
              <AuditSeverityChip
                v-for="sev in ['error', 'warning', 'hint']"
                :key="sev"
                :severity="sev"
                :count="audit.summary[sev] ?? 0"
                size="x-small"
                class="ml-1"
              />
            </div>
            <div v-if="!audit.issues.length" class="text-body-2 text-medium-emphasis">
              {{ $t('contentQuality.seoNoIssues') }}
            </div>
            <AuditIssueTable
              v-else
              :issues="audit.issues"
              @open-source="openIssueSource"
              @open-help="openHelp"
            />
          </template>
          <p v-else class="text-caption text-medium-emphasis mt-2">
            {{ $t('contentQuality.noAuditRun') }}
          </p>
        </template>

        <div v-else class="text-medium-emphasis text-center py-6">
          {{ $t('contentQuality.empty') }}
        </div>
      </v-card-text>

      <v-divider />

      <v-card-actions>
        <v-btn
          v-if="fileId"
          prepend-icon="mdi-file-document-edit-outline"
          variant="text"
          @click="toSource"
        >
          {{ $t('contentQuality.toSource') }}
        </v-btn>
        <v-btn
          v-if="fileId"
          prepend-icon="mdi-robot-outline"
          variant="text"
          color="primary"
          @click="improve"
        >
          {{ $t('contentQuality.improve') }}
        </v-btn>
        <v-spacer />
        <v-btn
          v-if="fileId"
          prepend-icon="mdi-refresh"
          variant="text"
          :disabled="store.busy"
          @click="recheck"
        >
          {{ $t('contentQuality.recheck') }}
        </v-btn>
        <v-btn variant="text" @click="store.closeDialog()">{{ $t('common.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.cq-finding {
  display: flex;
  align-items: flex-start;
}
.cq-suggestions {
  margin: 0;
  padding-left: 1.2rem;
}
.cq-suggestions li {
  margin-bottom: 0.25rem;
}
</style>
