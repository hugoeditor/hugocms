<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRepoStore } from '../stores/repo'
import { errorText } from '../i18n/apiMessage'
import { useConfirm } from '../util/confirm'

const { t } = useI18n()
const repo = useRepoStore()
const confirm = useConfirm()

// Sichtbarkeit als v-model (Vue 3.4+). Ein Knopf in der Titelleiste öffnet.
const model = defineModel({ type: Boolean, default: false })

const message = ref('')
const loading = ref(false)
const committing = ref(false)
const pushing = ref(false)
const resetting = ref(false)
const error = ref(null)
// Ergebnis einer Schreibaktion (Commit/Push/Reset): { ok, key, output }.
const action = ref(null)

// Beim Öffnen Status und Verlauf laden.
watch(model, async (open) => {
  if (!open) return
  error.value = null
  action.value = null
  message.value = ''
  repo.clearDiff()
  loading.value = true
  try {
    await repo.refresh()
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
})

// Diff in Zeilen mit Typ (Kontext/Hinzu/Weg/Kopf) für die Einfärbung.
const diffLines = computed(() => {
  const text = repo.diff?.diff ?? ''
  if (text === '') return []
  return text.split('\n').map((line) => {
    let kind = 'context'
    if (line.startsWith('diff ') || line.startsWith('@@') || line.startsWith('index ') ||
        line.startsWith('--- ') || line.startsWith('+++ ')) kind = 'meta'
    else if (line.startsWith('+')) kind = 'add'
    else if (line.startsWith('-')) kind = 'del'
    return { line, kind }
  })
})

const commitHeaders = computed(() => [
  { title: t('repo.colSha'), key: 'shortSha', width: 90, sortable: false },
  { title: t('repo.colMessage'), key: 'message', sortable: false },
  { title: t('repo.colAuthor'), key: 'author', width: 140, sortable: false },
  { title: t('repo.colDate'), key: 'date', width: 170, sortable: false },
])

function formatDate(iso) {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

async function reload() {
  loading.value = true
  error.value = null
  try {
    await repo.refresh()
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
}

async function doCommit() {
  if (committing.value || message.value.trim() === '') return
  committing.value = true
  action.value = null
  try {
    const res = await repo.commit(message.value.trim())
    action.value = { ok: res.success, key: res.success ? 'repo.commitOk' : 'repo.commitFail', output: res.output }
    if (res.success) {
      message.value = ''
      await repo.refresh()
    }
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    committing.value = false
  }
}

async function doPush() {
  if (pushing.value) return
  pushing.value = true
  action.value = null
  try {
    const res = await repo.push()
    action.value = { ok: res.success, key: res.success ? 'repo.pushOk' : 'repo.pushFail', output: res.output }
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    pushing.value = false
  }
}

async function doReset() {
  const ok = await confirm({
    title: t('repo.resetTitle'),
    message: t('repo.resetConfirm'),
    confirmText: t('repo.resetAction'),
    color: 'warning',
  })
  if (!ok) return
  resetting.value = true
  action.value = null
  try {
    const res = await repo.reset('HEAD')
    action.value = { ok: res.success, key: res.success ? 'repo.resetOk' : 'repo.resetFail', output: res.output }
    if (res.success) await repo.refresh()
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    resetting.value = false
  }
}

function onRowClick(_event, { item }) {
  repo.fetchDiff(item.sha).catch((e) => { error.value = errorText(t, e) })
}

const busy = computed(() => committing.value || pushing.value || resetting.value)
</script>

<template>
  <v-dialog v-model="model" width="900" scrollable>
    <v-card class="pa-2">
      <v-card-title class="d-flex align-center text-h6">
        <v-icon icon="mdi-source-branch" color="primary" class="mr-2" />
        {{ $t('repo.title') }}
        <v-spacer />
        <v-btn
          icon="mdi-refresh"
          variant="text"
          size="small"
          :loading="loading"
          :title="$t('repo.reload')"
          @click="reload"
        />
      </v-card-title>

      <v-card-text style="max-height: 70vh">
        <v-skeleton-loader v-if="loading && !repo.status" type="article" />
        <template v-else>
          <!-- Status -->
          <div v-if="repo.status" class="d-flex align-center flex-wrap mb-3" style="gap: 8px">
            <v-chip size="small" prepend-icon="mdi-source-branch" label>{{ repo.status.branch }}</v-chip>
            <v-chip
              :color="repo.status.clean ? 'success' : 'warning'"
              size="small"
              :prepend-icon="repo.status.clean ? 'mdi-check' : 'mdi-pencil'"
              label
            >
              {{ repo.status.clean ? $t('repo.clean') : $t('repo.dirty') }}
            </v-chip>
            <v-chip v-if="repo.status.modified.length" size="small" variant="tonal" color="warning" label>
              {{ $t('repo.modifiedCount', [repo.status.modified.length]) }}
            </v-chip>
            <v-chip v-if="repo.status.untracked.length" size="small" variant="tonal" label>
              {{ $t('repo.untrackedCount', [repo.status.untracked.length]) }}
            </v-chip>
            <v-spacer />
            <v-btn
              variant="text"
              size="small"
              prepend-icon="mdi-backup-restore"
              color="warning"
              :loading="resetting"
              :disabled="busy || repo.status.clean"
              @click="doReset"
            >
              {{ $t('repo.reset') }}
            </v-btn>
            <v-btn
              variant="tonal"
              size="small"
              prepend-icon="mdi-cloud-upload-outline"
              :loading="pushing"
              :disabled="busy"
              @click="doPush"
            >
              {{ $t('repo.push') }}
            </v-btn>
          </div>

          <!-- Geänderte/unverfolgte Dateien -->
          <div
            v-if="repo.status && (repo.status.modified.length || repo.status.untracked.length)"
            class="mb-3"
          >
            <div
              v-for="f in repo.status.modified"
              :key="'m-' + f"
              class="text-body-2 d-flex align-center"
            >
              <v-icon icon="mdi-file-edit-outline" size="16" color="warning" class="mr-1" />{{ f }}
            </div>
            <div
              v-for="f in repo.status.untracked"
              :key="'u-' + f"
              class="text-body-2 d-flex align-center text-medium-emphasis"
            >
              <v-icon icon="mdi-file-plus-outline" size="16" class="mr-1" />{{ f }}
            </div>
          </div>

          <!-- Commit-Formular -->
          <v-textarea
            v-model="message"
            :label="$t('repo.commitMessage')"
            prepend-inner-icon="mdi-message-text-outline"
            variant="outlined"
            density="comfortable"
            rows="2"
            auto-grow
            counter="1000"
            :disabled="repo.status?.clean"
            @keydown.enter.ctrl.prevent="doCommit"
          />
          <div class="d-flex align-center mb-2">
            <v-spacer />
            <v-btn
              color="primary"
              variant="flat"
              size="small"
              prepend-icon="mdi-content-save-outline"
              :loading="committing"
              :disabled="busy || repo.status?.clean || message.trim() === ''"
              @click="doCommit"
            >
              {{ $t('repo.commit') }}
            </v-btn>
          </div>

          <!-- Ergebnis der letzten Aktion -->
          <v-alert
            v-if="action"
            :type="action.ok ? 'success' : 'error'"
            density="compact"
            class="mb-3"
            closable
            @click:close="action = null"
          >
            <div>{{ $t(action.key) }}</div>
            <pre v-if="action.output" class="repo-output mt-1">{{ action.output }}</pre>
          </v-alert>

          <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>

          <!-- Commit-Verlauf -->
          <div class="text-subtitle-2 mb-1">{{ $t('repo.history') }}</div>
          <v-data-table
            :headers="commitHeaders"
            :items="repo.commits"
            :items-per-page="-1"
            density="compact"
            hover
            class="repo-table"
            @click:row="onRowClick"
          >
            <template #[`item.shortSha`]="{ item }">
              <code>{{ item.shortSha }}</code>
            </template>
            <template #[`item.date`]="{ item }">
              <span class="text-caption">{{ formatDate(item.date) }}</span>
            </template>
            <template #bottom>
              <div v-if="repo.total > repo.perPage" class="d-flex align-center justify-center pa-2" style="gap: 8px">
                <v-btn
                  size="small"
                  variant="text"
                  :disabled="repo.page <= 1 || loading"
                  @click="repo.fetchLog(repo.page - 1)"
                >{{ $t('repo.prev') }}</v-btn>
                <span class="text-caption">{{ $t('repo.pageOf', [repo.page, Math.ceil(repo.total / repo.perPage)]) }}</span>
                <v-btn
                  size="small"
                  variant="text"
                  :disabled="!repo.hasMore || loading"
                  @click="repo.fetchLog(repo.page + 1)"
                >{{ $t('repo.next') }}</v-btn>
              </div>
            </template>
          </v-data-table>

          <!-- Diff des ausgewählten Commits -->
          <template v-if="repo.diff">
            <div class="d-flex align-center mt-3 mb-1">
              <div class="text-subtitle-2">{{ $t('repo.diffOf', [repo.selectedSha?.slice(0, 7)]) }}</div>
              <v-spacer />
              <v-btn icon="mdi-close" variant="text" size="x-small" @click="repo.clearDiff()" />
            </div>
            <pre class="repo-diff nemo-scroll"><template
              v-for="(l, i) in diffLines"
              :key="i"
            ><span :class="'diff-' + l.kind">{{ l.line }}</span>
</template></pre>
          </template>
        </template>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="model = false">{{ $t('app.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.repo-table :deep(tbody tr) {
  cursor: pointer;
}
.repo-output {
  font-size: 0.78rem;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 160px;
  overflow: auto;
}
.repo-diff {
  max-height: 320px;
  overflow: auto;
  background: #f4f4f3;
  border: 1px solid #d3d3d1;
  border-radius: 4px;
  padding: 8px 10px;
  font-size: 0.78rem;
  line-height: 1.35;
}
.repo-diff span {
  display: block;
  white-space: pre-wrap;
  word-break: break-word;
}
.diff-add { color: #1b5e20; background: rgba(76, 175, 80, 0.12); }
.diff-del { color: #b71c1c; background: rgba(244, 67, 54, 0.1); }
.diff-meta { color: #1565c0; font-weight: 600; }
.diff-context { color: inherit; }
</style>
