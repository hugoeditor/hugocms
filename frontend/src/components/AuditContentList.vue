<script setup>
// Liste der bereits per LLM geprüften Content-Seiten (Reiter der AuditView).
// Zeigt Bewertung, Prüfdatum und einen Veraltet-/Quelle-fehlt-Marker. Eine Zeile
// öffnet das ausführliche Ergebnis im ContentQualityDialog; von hier lässt sich
// auch erneut prüfen oder ein Eintrag löschen.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuditContentStore } from '../stores/auditContent'
import { useAssistantStore } from '../stores/assistant'
import { useConfirm } from '../util/confirm'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'

const { t, locale } = useI18n()
const store = useAuditContentStore()
const assistant = useAssistantStore()
const confirm = useConfirm()
const error = useTransientError()

// Abgeleitete Sichten: alle / zu verbessern (Score < 100 und noch nicht
// verbessert) / bereits verbessert. Kombiniert mit dem Namensfilter.
const mode = ref('toImprove') // 'all' | 'toImprove' | 'improved' — Standard: zu verbessern

function inMode(p) {
  if (mode.value === 'improved') return !!p.improvedAt
  if (mode.value === 'toImprove') return !p.improvedAt && p.score != null && p.score < 100
  return true
}

// Filter über Datei- bzw. Verzeichnisname (rel enthält beides) und Titel.
const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return store.checked.filter(
    (p) =>
      inMode(p) &&
      (!q || (p.rel || '').toLowerCase().includes(q) || (p.title || '').toLowerCase().includes(q)),
  )
})

// Zähler je Sicht für die Umschalter-Beschriftung.
const counts = computed(() => {
  let toImprove = 0
  let improved = 0
  for (const p of store.checked) {
    if (p.improvedAt) improved++
    else if (p.score != null && p.score < 100) toImprove++
  }
  return { all: store.checked.length, toImprove, improved }
})

async function requeue(page) {
  error.value = null
  try {
    await store.requeue(page.key)
  } catch (e) {
    error.value = errorText(t, e)
  }
}

onMounted(load)

async function load() {
  error.value = null
  try {
    await store.fetchChecked()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// Schreibt der Assistent eine Datei, die in dieser Liste steht (z. B. nach einer
// bestätigten Verbesserung), die Liste neu laden — der Verbesserungs-Vermerk
// wurde serverseitig gesetzt. Der Drawer überlagert die Liste nur, mountet sie
// also nicht neu; darum reicht das reine Zurückkehren nicht.
watch(
  () => assistant.actions,
  (actions) => {
    if (!Array.isArray(actions) || !actions.length) return
    const listed = new Set(store.checked.map((p) => `${p.mount}/${p.rel}`))
    const touched = actions.some(
      (a) => a.path && listed.has(String(a.path).replace(/^\/+|\/+$/g, '')),
    )
    if (touched) load()
  },
)

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

function improve(page) {
  if (page.fileId) assistant.improve(page.fileId, locale.value)
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

    <template v-else>
      <div class="cql-search">
        <v-btn-toggle v-model="mode" mandatory density="compact" variant="outlined" divided class="mb-2 cql-modes">
          <v-btn value="all" size="small">{{ $t('contentQuality.filterAll') }} · {{ counts.all }}</v-btn>
          <v-btn value="toImprove" size="small">{{ $t('contentQuality.filterToImprove') }} · {{ counts.toImprove }}</v-btn>
          <v-btn value="improved" size="small">{{ $t('contentQuality.filterImproved') }} · {{ counts.improved }}</v-btn>
        </v-btn-toggle>
        <v-text-field
          v-model="search"
          :placeholder="$t('contentQuality.searchPlaceholder')"
          prepend-inner-icon="mdi-magnify"
          density="compact"
          variant="outlined"
          hide-details
          clearable
        />
      </div>

      <div v-if="!filtered.length" class="nemo-empty">
        <v-icon icon="mdi-file-search-outline" size="56" class="nemo-empty-icon" />
        <p>{{ $t('contentQuality.noMatches') }}</p>
      </div>

      <v-list v-else density="comfortable" class="cql-list py-0">
        <v-list-item
          v-for="page in filtered"
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
            v-if="page.improvedAt"
            color="success"
            size="x-small"
            variant="tonal"
            label
            class="ml-2"
            :title="$t('contentQuality.improvedAt', [formatDate(page.improvedAt)])"
          >
            {{ $t('contentQuality.improvedChip') }}
          </v-chip>
          <v-chip
            v-else-if="page.sourceMissing"
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
            v-if="page.improvedAt"
            icon="mdi-playlist-plus"
            size="small"
            variant="text"
            density="comfortable"
            :title="$t('contentQuality.requeue')"
            @click.stop="requeue(page)"
          />
          <v-btn
            :disabled="!page.fileId"
            icon="mdi-creation"
            size="small"
            variant="text"
            density="comfortable"
            color="primary"
            :title="$t('contentQuality.improve')"
            @click.stop="improve(page)"
          />
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
    </template>
  </div>
</template>

<style scoped>
.cql-wrap { height: 100%; overflow: auto; }
/* Suchfeld bleibt beim Scrollen der Liste oben stehen. */
.cql-search {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 8px 10px;
  background: var(--mint-content);
  border-bottom: 1px solid var(--mint-border);
}
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
