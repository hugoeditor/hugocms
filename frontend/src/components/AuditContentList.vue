<script setup>
// Liste der bereits per LLM geprüften Content-Seiten (Reiter der AuditView).
// Zeigt Bewertung, Prüfdatum und einen Veraltet-/Quelle-fehlt-Marker. Eine Zeile
// öffnet das ausführliche Ergebnis im ContentQualityDialog; von hier lässt sich
// auch erneut prüfen oder ein Eintrag löschen.
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditContentStore } from '../stores/auditContent'
import { useConfirm } from '../util/confirm'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'

const { t, locale } = useI18n()
const store = useAuditContentStore()
const confirm = useConfirm()
const error = useTransientError()

onMounted(load)

async function load() {
  error.value = null
  try {
    await store.fetchChecked()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

function scoreColor(score) {
  if (score == null) return 'grey'
  if (score >= 70) return 'success'
  if (score >= 40) return 'warning'
  return 'error'
}

function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value)
}

function openDetail(page) {
  store.openResult(page.key, page.title || page.rel)
}

function recheck(page) {
  if (page.fileId) store.check(page.fileId, page.title || page.rel, locale.value)
}

async function remove(page) {
  const ok = await confirm({
    title: t('contentQuality.deleteTitle'),
    message: t('contentQuality.deleteConfirm', [page.title || page.rel]),
    confirmText: t('common.remove'),
    color: 'error',
  })
  if (!ok) return
  error.value = null
  try {
    await store.remove(page.key)
  } catch (e) {
    error.value = errorText(t, e)
  }
}
</script>

<template>
  <div class="cql-wrap">
    <v-alert v-if="error" type="error" density="compact" class="ma-2 nemo-alert" tile>{{ error }}</v-alert>

    <div v-if="!store.checked.length" class="nemo-empty">
      <v-icon icon="mdi-text-search" size="56" class="nemo-empty-icon" />
      <p>{{ $t('contentQuality.empty') }}</p>
    </div>

    <v-list v-else density="comfortable" class="cql-list py-0">
      <v-list-item
        v-for="page in store.checked"
        :key="page.key"
        class="cql-row"
        @click="openDetail(page)"
      >
        <template #prepend>
          <v-chip :color="scoreColor(page.score)" size="small" variant="flat" label class="mr-3">
            {{ page.score ?? '–' }}
          </v-chip>
        </template>

        <v-list-item-title class="d-flex align-center">
          <span class="text-truncate">{{ page.title || page.rel }}</span>
          <v-chip
            v-if="page.sourceMissing"
            color="error"
            size="x-small"
            variant="tonal"
            label
            class="ml-2"
          >
            {{ $t('contentQuality.sourceMissing') }}
          </v-chip>
          <v-chip
            v-else-if="page.stale"
            color="warning"
            size="x-small"
            variant="tonal"
            label
            class="ml-2"
          >
            {{ $t('contentQuality.stale') }}
          </v-chip>
        </v-list-item-title>
        <v-list-item-subtitle>{{ page.rel }} · {{ formatDate(page.checkedAt) }}</v-list-item-subtitle>

        <template #append>
          <v-btn
            :disabled="!page.fileId || store.busy"
            icon="mdi-refresh"
            size="small"
            variant="text"
            density="comfortable"
            :title="$t('contentQuality.recheck')"
            @click.stop="recheck(page)"
          />
          <v-btn
            icon="mdi-delete-outline"
            size="small"
            variant="text"
            density="comfortable"
            :title="$t('common.remove')"
            @click.stop="remove(page)"
          />
        </template>
      </v-list-item>
    </v-list>
  </div>
</template>

<style scoped>
.cql-wrap { height: 100%; overflow: auto; }
.cql-list { background: transparent; }
.cql-row { cursor: pointer; border-bottom: 1px solid var(--mint-border); }
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
</style>
