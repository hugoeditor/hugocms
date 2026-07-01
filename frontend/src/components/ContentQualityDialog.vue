<script setup>
// Dialog der LLM-Content-Qualität (Pro-Funktion). Zeigt das Ergebnis einer
// Prüfung (Score, Lesbarkeit, Funde, Vorschläge) und darunter die Liste der
// bereits geprüften Seiten. Wird aus dem Editor und aus dem Kontextmenü der
// Dateiliste geöffnet; von der Liste aus lassen sich frühere Ergebnisse wieder
// aufrufen. Kein eigener Modus/Overlay — bewusst als überlagernder Dialog.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditContentStore } from '../stores/auditContent'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import AuditSeverityChip from './AuditSeverityChip.vue'

const { t, locale } = useI18n()
const store = useAuditContentStore()
const files = useFilesStore()

const verdict = computed(() => store.current?.verdict ?? null)

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

// Erneut prüfen: nutzt die fileId des aktuellen Eintrags.
function recheck() {
  const id = store.current?.fileId
  if (id) store.recheck(id, store.current.title, locale.value)
}

// Zur Quelldatei springen: Editor öffnen, Dialog schließen.
async function toSource() {
  const id = store.current?.fileId
  if (!id) return
  store.closeDialog()
  await files.openFileById(id)
}

function openChecked(page) {
  store.openResult(page.key, page.title || page.rel)
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
            {{ $t('contentQuality.checkedAt', [formatDate(store.current.checkedAt)]) }}
            <template v-if="store.current.model"> · {{ store.current.model }}</template>
            <span v-if="store.current.truncated"> · {{ $t('contentQuality.truncated') }}</span>
          </div>
        </template>

        <!-- Liste bereits geprüfter Seiten -->
        <template v-if="store.checked.length">
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-2">{{ $t('contentQuality.checkedList') }}</div>
          <v-list density="compact" class="py-0">
            <v-list-item
              v-for="page in store.checked"
              :key="page.key"
              :active="page.key === store.current?.key"
              class="px-2"
              @click="openChecked(page)"
            >
              <template #prepend>
                <v-chip :color="scoreColor(page.score)" size="small" variant="flat" label class="mr-2">
                  {{ page.score ?? '–' }}
                </v-chip>
              </template>
              <v-list-item-title>{{ page.title || page.rel }}</v-list-item-title>
              <v-list-item-subtitle>{{ formatDate(page.checkedAt) }}</v-list-item-subtitle>
              <template #append>
                <v-btn
                  icon="mdi-delete-outline"
                  size="small"
                  variant="text"
                  density="comfortable"
                  @click.stop="store.remove(page.key)"
                />
              </template>
            </v-list-item>
          </v-list>
        </template>

        <div
          v-else-if="!store.busy && !store.loading && !store.error && !verdict"
          class="text-medium-emphasis text-center py-6"
        >
          {{ $t('contentQuality.empty') }}
        </div>
      </v-card-text>

      <v-divider />

      <v-card-actions>
        <v-btn
          v-if="store.current?.fileId"
          prepend-icon="mdi-file-document-edit-outline"
          variant="text"
          @click="toSource"
        >
          {{ $t('contentQuality.toSource') }}
        </v-btn>
        <v-spacer />
        <v-btn
          v-if="store.current?.fileId"
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
