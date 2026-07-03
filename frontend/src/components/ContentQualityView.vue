<script setup>
// Gesamt-Bericht einer Content-Datei (Pro-Funktion): das LLM-Qualitätsurteil
// (Score, Lesbarkeit, Befunde, Vorschläge) UND die SEO-Funde derselben Datei aus
// dem jüngsten Audit-Lauf. Wird aus dem Editor, dem Kontextmenü der Dateiliste
// und dem Content-Reiter der AuditView geöffnet.
//
// Als Overlay-Ansicht umgesetzt (wie Editor/Audit/Hilfe), NICHT als v-dialog:
// So kann sich die Hilfe-Ansicht (höherer z-index) über den Bericht legen, wenn
// man aus einem SEO-Fund die Regel-Hilfe öffnet — ihr Zurück-Button bringt den
// Bericht wieder zum Vorschein. Ein v-dialog läge über der Hilfe und verdeckte sie.
import { computed } from 'vue'
import { useDisplay } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { useAuditContentStore } from '../stores/auditContent'
import { useAssistantStore } from '../stores/assistant'
import { useFilesStore } from '../stores/files'
import { useHelpStore } from '../stores/help'
import { errorText } from '../i18n/apiMessage'
import AuditSeverityChip from './AuditSeverityChip.vue'
import AuditIssueTable from './AuditIssueTable.vue'

const { t, locale } = useI18n()
// Auf schmalen Schirmen (Smartphone) verkleinern sich die Aktions-Buttons unten
// zu reinen Icons, damit alle in eine Zeile passen.
const { smAndDown } = useDisplay()
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
  <div v-if="store.dialogOpen" class="cq-overlay">
    <!-- Kopfzeile: Zurück + Titel (wie Hilfe/Editor). Der Pfeil schließt den
         Bericht und gibt die darunterliegende Ansicht wieder frei. -->
    <header class="cq-head nemo-noselect">
      <button class="cq-back" :title="$t('common.close')" @click="store.closeDialog()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-text-search" size="18" class="cq-head-icon" />
      <span class="cq-head-title text-truncate">{{ store.fileName || $t('contentQuality.title') }}</span>
    </header>

    <div class="cq-content nemo-scroll">
      <div class="cq-inner">
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
          <v-chip
            v-if="entry.improvedAt"
            color="success"
            size="small"
            variant="tonal"
            label
            class="mt-2"
            prepend-icon="mdi-creation"
          >
            {{ $t('contentQuality.improvedAt', [formatDate(entry.improvedAt)]) }}
            <template v-if="entry.improveModel"> · {{ entry.improveModel }}</template>
          </v-chip>

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
      </div>
    </div>

    <!-- Aktionsleiste am unteren Rand. Auf schmalen Schirmen reine Icon-Buttons,
         damit alle Aktionen in eine Zeile passen; sonst Icon + Beschriftung wie
         gewohnt. Icon- und Text-Variante sind getrennte Buttons: ein (auch
         leerer) Default-Slot verdrängt in Vuetify sonst das icon-Prop, der Button
         bliebe leer. Das title-Attribut trägt im Icon-Modus die Bedeutung. -->
    <footer class="cq-actions nemo-noselect">
        <template v-if="fileId">
          <v-btn
            v-if="smAndDown"
            icon="mdi-file-document-edit-outline"
            :title="$t('contentQuality.toSource')"
            variant="text"
            @click="toSource"
          />
          <v-btn
            v-else
            prepend-icon="mdi-file-document-edit-outline"
            variant="text"
            @click="toSource"
          >
            {{ $t('contentQuality.toSource') }}
          </v-btn>

          <v-btn
            v-if="smAndDown"
            icon="mdi-creation"
            :title="$t('contentQuality.improve')"
            variant="text"
            color="primary"
            @click="improve"
          />
          <v-btn
            v-else
            prepend-icon="mdi-robot-outline"
            variant="text"
            color="primary"
            @click="improve"
          >
            {{ $t('contentQuality.improve') }}
          </v-btn>
        </template>
        <v-spacer />
        <template v-if="fileId">
          <v-btn
            v-if="smAndDown"
            icon="mdi-refresh"
            :title="$t('contentQuality.recheck')"
            variant="text"
            :disabled="store.busy"
            @click="recheck"
          />
          <v-btn
            v-else
            prepend-icon="mdi-refresh"
            variant="text"
            :disabled="store.busy"
            @click="recheck"
          >
            {{ $t('contentQuality.recheck') }}
          </v-btn>
        </template>
        <v-btn
          v-if="smAndDown"
          icon="mdi-close"
          :title="$t('common.close')"
          variant="text"
          @click="store.closeDialog()"
        />
        <v-btn v-else variant="text" @click="store.closeDialog()">{{ $t('common.close') }}</v-btn>
      </footer>
  </div>
</template>

<style scoped>
/* Overlay-Ansicht über dem Arbeitsbereich (wie Editor/Audit/Hilfe). Der z-index
   liegt zwischen Editor (10) und Hilfe (15): So legt sich die Hilfe zu einem
   SEO-Fund darüber; ihr Zurück-Button bringt den Bericht wieder zum Vorschein. */
.cq-overlay {
  position: absolute;
  inset: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.cq-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.cq-back {
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
.cq-back:hover { background: var(--mint-panel-hover); }
.cq-head-icon { color: var(--mint-green); }
.cq-head-title { font-weight: 600; font-size: 0.95rem; min-width: 0; }

/* Scrollender Inhalt; der Bericht sitzt zentriert in angenehmer Lesebreite. */
.cq-content { flex: 1 1 auto; overflow: auto; }
.cq-inner {
  max-width: 820px;
  margin: 0 auto;
  padding: 16px 20px 32px;
}

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

/* Aktionsleiste am unteren Rand. Darf umbrechen: auf schmalen Schirmen passen
   die vier Buttons (Quelle, Verbessern, Erneut prüfen, Schließen) nicht in eine
   Zeile — ohne Umbruch liefen die linken aus dem Bild. Der Umschlag greift den
   Spacer als Trenner: linke Aktionen oben, rechte in der Folgezeile. */
.cq-actions {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  row-gap: 4px;
  padding: 8px 12px;
  background: var(--mint-panel);
  border-top: 1px solid var(--mint-border);
}
</style>
