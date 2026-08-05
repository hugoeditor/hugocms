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
import { computed, ref, watch } from 'vue'
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
// Listenfelder nur übernehmen, wenn sie wirklich Listen sind: v-for liefe sonst
// über die Zeichen einer Zeichenkette. Schützt die Anzeige vor Altberichten aus
// misslungenen Modellantworten (das Backend prüft neue Berichte selbst).
const findings = computed(() =>
  Array.isArray(verdict.value?.findings) ? verdict.value.findings : [],
)
const suggestions = computed(() =>
  Array.isArray(verdict.value?.suggestions) ? verdict.value.suggestions : [],
)
const audit = computed(() => store.current?.audit ?? null)
const fileId = computed(() => store.current?.file?.fileId ?? null)
// Schlüssel (sha1) des Berichts — Adresse für Speicher-/Verwaltungsbefehle.
const reportKey = computed(() => entry.value?.key ?? null)

// --- Lage zum Editor -------------------------------------------------------
// Der Bericht liegt normalerweise ÜBER dem Editor: Er wird auch AUS ihm heraus
// geöffnet („Qualität prüfen") und müsste sonst unsichtbar darunter liegen.
// Springt man dagegen VON hier zu einer Datei, kehrt sich die Ordnung um — der
// Editor legt sich darüber, und beim Schließen erscheint der Bericht wieder,
// statt den Benutzer im Dateimanager abzusetzen.
const belowEditor = ref(false)

// Zurück nach oben, sobald der Editor zu ist …
watch(
  () => files.openFile,
  (file) => {
    if (!file) belowEditor.value = false
  },
)
// … und ebenso, wenn ein Bericht neu geholt wird (Prüflauf oder gespeichertes
// Ergebnis). Das ist der Weg aus dem Editor heraus: Der Bericht ist dann bereits
// offen, nur eben verdeckt, und muss wieder nach vorn.
watch(
  () => store.busy || store.loading,
  (active) => {
    if (active) belowEditor.value = false
  },
)

// --- Bearbeiten: Benutzer korrigiert Vorschläge + Freitext-Anweisung, bevor
// die KI den Bericht abruft. Die KI-Befunde bleiben schreibgeschützt.
const editing = ref(false)
const saving = ref(false)
const editSuggestions = ref([])
const editInstruction = ref('')

function startEdit() {
  editSuggestions.value = [...suggestions.value]
  editInstruction.value = entry.value?.userInstruction ?? ''
  editing.value = true
}

function cancelEdit() {
  editing.value = false
}

function addSuggestion() {
  editSuggestions.value.push('')
}

function removeSuggestion(i) {
  editSuggestions.value.splice(i, 1)
}

async function saveEdit() {
  if (!reportKey.value || saving.value) return
  saving.value = true
  try {
    const ok = await store.saveEdits(
      reportKey.value,
      editSuggestions.value,
      editInstruction.value,
    )
    if (ok) editing.value = false
  } finally {
    saving.value = false
  }
}

// Hinweistext „Stand vor der Verbesserung". Geprüfte Seiten nennen das
// Prüfdatum; ohne Prüfung vorgemerkte Seiten (kein Urteil, nur Vorschläge und
// Anweisung) nennen stattdessen das Vormerkdatum. Fehlt beides — denkbar bei
// Einträgen aus älteren Ständen —, bleibt die knappe Fassung ohne Datum.
const preImproveNote = computed(() => {
  const improved = formatDate(entry.value?.improvedAt)
  if (entry.value?.checkedAt) {
    return t('contentQuality.preImproveNote', [formatDate(entry.value.checkedAt), improved])
  }
  if (entry.value?.queuedAt) {
    return t('contentQuality.preImproveNoteQueued', [formatDate(entry.value.queuedAt), improved])
  }
  return t('contentQuality.preImproveNotePlain', [improved])
})

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

// Erneut prüfen: nutzt die fileId der aktuellen Datei. Eine frische KI-Prüfung
// baut den Bericht neu auf und verwirft dabei manuelle Bearbeitungen — deshalb
// vorher nachfragen, sobald ein editedAt-Vermerk vorliegt.
const recheckDialog = ref(false)

function recheck() {
  if (!fileId.value) return
  if (entry.value?.editedAt) {
    recheckDialog.value = true
    return
  }
  doRecheck()
}

function doRecheck() {
  recheckDialog.value = false
  store.recheck(fileId.value, store.current?.file?.title, locale.value)
}

// Zur Quelldatei springen: Editor öffnen, Bericht offen lassen. Er rückt dabei
// unter den Editor (siehe belowEditor) und erscheint beim Schließen wieder — mit
// unverändertem Bearbeitungsstand.
async function toSource() {
  const id = fileId.value
  if (!id) return
  belowEditor.value = true
  try {
    await files.openFileById(id)
  } catch (e) {
    // Datei nicht mehr vorhanden: Bericht wieder nach vorn, Grund anzeigen.
    belowEditor.value = false
    store.error = e
  }
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

// SEO-Fund: zur Quelle springen bzw. ausführliche Regel-Hilfe öffnen. Wie bei
// toSource() bleibt der Bericht offen und rückt unter den Editor.
async function openIssueSource(issue) {
  if (!issue.fileId) return
  belowEditor.value = true
  try {
    await files.openFileById(issue.fileId)
  } catch (e) {
    belowEditor.value = false
    store.error = e
  }
}

function openHelp(ruleId) {
  help.open('audit', ruleId, locale.value)
}
</script>

<template>
  <div v-if="store.dialogOpen" class="cq-overlay" :class="{ 'cq-overlay--below': belowEditor }">
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

        <!-- Ergebnis (geprüft) ODER Vormerkung ohne Prüfung -->
        <template v-else-if="entry">
          <!-- Wurde die Seite von der KI verbessert, beschreibt der ganze Bericht
               den Textstand VOR dieser Bearbeitung: Die Verbesserung setzt nur den
               improvedAt-Vermerk, sie schreibt weder Bewertung noch Befunde fort.
               Der Hinweis steht deshalb ganz oben, vor dem Score. Alte Einträge
               ohne improvedAt zeigen ihn nicht. -->
          <v-alert
            v-if="entry.improvedAt"
            type="warning"
            variant="tonal"
            density="comfortable"
            class="mb-4"
            prepend-icon="mdi-history"
          >
            <div class="font-weight-medium mb-1">{{ $t('contentQuality.preImproveTitle') }}</div>
            <div class="text-body-2">{{ preImproveNote }}</div>
          </v-alert>

          <!-- Qualitätsurteil nur, wenn geprüft. -->
          <template v-if="verdict">
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
            <template v-if="findings.length">
              <div class="text-subtitle-2 mb-2">{{ $t('contentQuality.findings') }}</div>
              <div v-for="(f, i) in findings" :key="'f' + i" class="cq-finding mb-2">
                <AuditSeverityChip :severity="f.severity" size="x-small" />
                <div class="ml-2">
                  <div class="font-weight-medium">{{ f.title }}</div>
                  <div class="text-body-2 text-medium-emphasis">{{ f.detail }}</div>
                </div>
              </div>
            </template>
          </template>

          <!-- Ohne Prüfung vorgemerkt: Hinweis statt Urteil — aber nur, solange
               die Verbesserung noch aussteht. Ist sie erfolgt, beschreibt der
               Hinweis oben („Stand vor der Verbesserung") die Lage bereits
               vollständig, samt Vormerkdatum. -->
          <v-alert
            v-else-if="!entry.improvedAt"
            type="info"
            density="compact"
            variant="tonal"
            class="mb-2"
            prepend-icon="mdi-playlist-plus"
          >
            {{ $t('contentQuality.queuedNote') }}
          </v-alert>

          <!-- Vorschläge (vom Benutzer editierbar) -->
          <div class="d-flex align-center mt-4 mb-1">
            <div class="text-subtitle-2">{{ $t('contentQuality.suggestions') }}</div>
            <v-spacer />
            <template v-if="!editing">
              <v-btn
                size="small"
                variant="text"
                prepend-icon="mdi-pencil-outline"
                :disabled="store.busy"
                @click="startEdit"
              >
                {{ $t('contentQuality.edit') }}
              </v-btn>
            </template>
            <template v-else>
              <v-btn size="small" variant="text" :disabled="saving" @click="cancelEdit">
                {{ $t('common.cancel') }}
              </v-btn>
              <v-btn
                size="small"
                variant="flat"
                color="primary"
                :loading="saving"
                @click="saveEdit"
              >
                {{ $t('common.save') }}
              </v-btn>
            </template>
          </div>

          <!-- Anzeige -->
          <template v-if="!editing">
            <ul v-if="suggestions.length" class="cq-suggestions">
              <li v-for="(s, i) in suggestions" :key="'s' + i">{{ s }}</li>
            </ul>
            <p v-else class="text-body-2 text-medium-emphasis">
              {{ $t('contentQuality.noSuggestions') }}
            </p>
            <div v-if="entry.userInstruction" class="cq-instruction mt-3">
              <div class="text-subtitle-2 mb-1">{{ $t('contentQuality.instruction') }}</div>
              <p class="text-body-2">{{ entry.userInstruction }}</p>
            </div>
          </template>

          <!-- Bearbeiten -->
          <template v-else>
            <div
              v-for="(s, i) in editSuggestions"
              :key="'es' + i"
              class="d-flex align-start mb-2"
            >
              <v-textarea
                v-model="editSuggestions[i]"
                :rows="1"
                auto-grow
                density="compact"
                hide-details
                variant="outlined"
              />
              <v-btn
                icon="mdi-close"
                size="small"
                variant="text"
                class="ml-1 mt-1"
                :title="$t('contentQuality.removeSuggestion')"
                @click="removeSuggestion(i)"
              />
            </div>
            <v-btn
              size="small"
              variant="text"
              prepend-icon="mdi-plus"
              @click="addSuggestion"
            >
              {{ $t('contentQuality.addSuggestion') }}
            </v-btn>

            <div class="text-subtitle-2 mt-4 mb-1">{{ $t('contentQuality.instruction') }}</div>
            <v-textarea
              v-model="editInstruction"
              :rows="2"
              auto-grow
              density="compact"
              hide-details
              variant="outlined"
              :placeholder="$t('contentQuality.instructionHint')"
            />
          </template>

          <div class="text-caption text-medium-emphasis mt-4">
            <template v-if="entry.checkedAt">
              {{ $t('contentQuality.checkedAt', [formatDate(entry.checkedAt)]) }}
              <template v-if="entry.model"> · {{ entry.model }}</template>
              <span v-if="entry.truncated"> · {{ $t('contentQuality.truncated') }}</span>
            </template>
            <template v-else-if="entry.queuedAt">
              {{ $t('contentQuality.queuedAt', [formatDate(entry.queuedAt)]) }}
            </template>
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
          <v-chip
            v-if="entry.editedAt"
            color="info"
            size="small"
            variant="tonal"
            label
            class="mt-2 ml-2"
            prepend-icon="mdi-account-edit-outline"
          >
            {{ $t('contentQuality.editedAt', [formatDate(entry.editedAt)]) }}
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
            :disabled="editing"
            @click="toSource"
          />
          <v-btn
            v-else
            prepend-icon="mdi-file-document-edit-outline"
            variant="text"
            :disabled="editing"
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
            :disabled="editing"
            @click="improve"
          />
          <v-btn
            v-else
            prepend-icon="mdi-creation"
            variant="text"
            color="primary"
            :disabled="editing"
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
            :disabled="store.busy || editing"
            @click="recheck"
          />
          <v-btn
            v-else
            prepend-icon="mdi-refresh"
            variant="text"
            :disabled="store.busy || editing"
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

    <!-- Warnung vor dem Neu-Prüfen, wenn manuelle Bearbeitungen vorliegen:
         die frische KI-Prüfung überschreibt Vorschläge und Anweisung. -->
    <v-dialog v-model="recheckDialog" max-width="440">
      <v-card>
        <v-card-title class="text-subtitle-1">{{ $t('contentQuality.recheckTitle') }}</v-card-title>
        <v-card-text>{{ $t('contentQuality.recheckWarn') }}</v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="recheckDialog = false">{{ $t('common.cancel') }}</v-btn>
          <v-btn color="warning" variant="flat" @click="doRecheck">
            {{ $t('contentQuality.recheckAction') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<style scoped>
/* Overlay-Ansicht über dem Arbeitsbereich (wie Editor/Audit/Hilfe). Der z-index
   liegt zwischen Editor (10) und Hilfe (15): So legt sich die Hilfe zu einem
   SEO-Fund darüber; ihr Zurück-Button bringt den Bericht wieder zum Vorschein.
   Über dem Editor muss er liegen, weil dessen Knopf „Qualität prüfen" den
   Bericht öffnet, ohne den Editor zu schließen. */
.cq-overlay {
  position: absolute;
  inset: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}
/* Umgekehrte Lage, nachdem der Bericht selbst eine Datei geöffnet hat: unter dem
   Editor (wie das SEO-Audit mit 9), damit dessen Schließen zum Bericht
   zurückführt statt in den Dateimanager. Siehe belowEditor im Skript. */
.cq-overlay--below { z-index: 9; }

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
