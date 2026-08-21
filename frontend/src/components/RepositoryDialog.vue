<script setup>
import { computed, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { useRepoStore } from '../stores/repo'
import { useAuthStore } from '../stores/auth'
import ProGate from './ProGate.vue'
import { errorText } from '../i18n/apiMessage'
import { useConfirm } from '../util/confirm'

const { t } = useI18n()
const { smAndDown } = useDisplay()
const repo = useRepoStore()
const auth = useAuthStore()
const confirm = useConfirm()

// Die Lizenzaktivierung liegt in App.vue — von hier nur angestoßen.
const emit = defineEmits(['activate-license'])

// Sichtbarkeit als v-model (Vue 3.4+). Ein Knopf in der Titelleiste öffnet.
const model = defineModel({ type: Boolean, default: false })

const message = ref('')
// Versionsnummer des zu sichernden Standes (annotiertes Git-Tag). Vorbelegt mit
// dem Vorschlag des Servers, der aus den vorhandenen Tags abgeleitet wird.
const tag = ref('')
// Die zuletzt eingesetzten Vorschläge für Beschreibung und Nummer. Sie sind
// keine Anzeigewerte, sondern der Vergleichsmaßstab in applySuggestions() —
// deshalb ein einfaches Objekt statt reaktiver Refs.
const suggested = { tag: '', message: '' }
const loading = ref(false)
// Diff wird in einem eigenen, überlagernden Dialog gezeigt (auf dem Smartphone
// im Vollbild), damit die Auswahl unabhängig von der Scrollposition sichtbar ist.
const diffDialog = ref(false)
const diffLoading = ref(false)
const committing = ref(false)
const pushing = ref(false)
const resetting = ref(false)
const error = ref(null)
// Ergebnis einer Schreibaktion (Commit/Push/Reset): { ok, key, output }.
const action = ref(null)

// Beim Öffnen Status und Verlauf laden — es sei denn, die Funktion ist gar
// nicht freigeschaltet: Dann zeigt der Dialog nur den Pro-Hinweis, und ein
// Ladeversuch liefe bloß in ein PRO-REQUIRED des Servers.
watch(model, async (open) => {
  if (!open || !auth.git) return
  error.value = null
  action.value = null
  message.value = ''
  tag.value = ''
  suggested.tag = ''
  suggested.message = ''
  repo.clearDiff()
  loading.value = true
  try {
    await repo.refresh()
    applySuggestions()
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
})

// Belegt Beschreibung und Versionsnummer mit den aktuellen Vorschlägen — aber
// nur, wo noch nichts Eigenes steht. `suggested` hält je Feld den zuletzt
// eingesetzten Vorschlag fest; weicht der Inhalt davon ab, hat der Benutzer ihn
// bearbeitet und er bleibt beim Neuladen unangetastet.
function applySuggestions() {
  const nextTag = repo.status?.nextTag ?? ''
  if (tag.value === '' || tag.value === suggested.tag) tag.value = nextTag
  suggested.tag = nextTag

  const nextMessage = buildMessage()
  if (message.value === '' || message.value === suggested.message) message.value = nextMessage
  suggested.message = nextMessage
}

// Anzeige-Gruppen der Arbeitsbaum-Änderungen. Das Backend meldet den Git-Status
// je Datei; `added` (bereits vorgemerkt) und `untracked` (noch nicht vorgemerkt)
// bilden zusammen die Gruppe „Neu“, weil der Commit mit `add -A` ohnehin beides
// übernimmt — die Unterscheidung wäre für die Bedienung ohne Folge.
const STATUS_GROUP = {
  modified: 'modified',
  added: 'new',
  untracked: 'new',
  deleted: 'deleted',
  renamed: 'renamed',
  conflict: 'conflict',
}

// Je Gruppe Symbol, Farbe und Beschriftung. Die Art steht als Wort in der Liste,
// nicht nur als Farbe — sonst wäre sie bei Farbenblindheit nicht zu erkennen.
const GROUP_META = {
  conflict: { icon: 'mdi-alert-outline', color: 'error', label: 'repo.statusConflict', count: 'repo.countConflict' },
  deleted: { icon: 'mdi-file-remove-outline', color: 'error', label: 'repo.statusDeleted', count: 'repo.countDeleted' },
  new: { icon: 'mdi-file-plus-outline', color: 'success', label: 'repo.statusNew', count: 'repo.countNew' },
  modified: { icon: 'mdi-file-edit-outline', color: 'warning', label: 'repo.statusModified', count: 'repo.countModified' },
  renamed: { icon: 'mdi-file-move-outline', color: 'info', label: 'repo.statusRenamed', count: 'repo.countRenamed' },
}

// Reihenfolge in Liste und Zusammenfassung: Dringendes zuerst.
const GROUP_ORDER = ['conflict', 'deleted', 'new', 'modified', 'renamed']

// Änderungen nach Art gruppiert, damit Gleichartiges beieinandersteht.
const changes = computed(() =>
  (repo.status?.entries ?? [])
    .map((e) => ({ ...e, group: STATUS_GROUP[e.status] ?? 'modified' }))
    .sort(
      (a, b) =>
        GROUP_ORDER.indexOf(a.group) - GROUP_ORDER.indexOf(b.group) || a.path.localeCompare(b.path),
    ),
)

// Zähler je Gruppe für die Chips in der Kopfzeile.
const summary = computed(() => {
  const counts = new Map()
  for (const c of changes.value) counts.set(c.group, (counts.get(c.group) ?? 0) + 1)
  return GROUP_ORDER.filter((g) => counts.has(g)).map((g) => ({ group: g, count: counts.get(g) }))
})

// Obergrenze der Beschreibung — dieselbe wie GitService::MAX_MESSAGE. Wird sie
// überschritten, weist das Backend den Versionsstand mit GIT-MESSAGE-TOO-LONG ab.
const MESSAGE_MAX = 1000
// Die Vorbelegung schöpft das Budget bewusst nicht aus: Der Rest bleibt für
// eigene Ergänzungen, und der Zähler am Feld schlägt nicht sofort an.
const MESSAGE_BUDGET = 900

/**
 * Baut die vorgeschlagene Beschreibung: erste Zeile die Zusammenfassung, danach
 * eine Zeile je geänderter Datei.
 *
 * Die erste Zeile trägt bewusst die Zusammenfassung und keinen Dateipfad — git
 * behandelt sie als Betreff, und genau sie zeigt die Spalte „Beschreibung“ im
 * Verlauf. Die Dateiliste wird am Zeichenbudget gekürzt: Ein Theme-Import oder
 * ein Build kann hunderte Dateien umfassen, deren Pfade die Grenze sonst weit
 * überschritten. Was nicht mehr hineinpasst, weist die letzte Zeile als Anzahl aus.
 */
function buildMessage() {
  const list = changes.value
  if (list.length === 0) return ''

  const subject = summary.value.map((s) => t(GROUP_META[s.group].count, [s.count])).join(', ')
  // Abschlusszeile für den nicht gelisteten Rest. Eigener Schlüssel für die
  // Einzahl, weil das Projekt keine i18n-Pluralformen verwendet.
  const moreLine = (n) => (n === 1 ? t('repo.messageMoreOne') : t('repo.messageMore', [n]))
  // Platz dafür freihalten, bevor die erste Datei zählt — sonst bliebe für den
  // Hinweis auf den Rest womöglich keiner mehr übrig. Maßstab ist die längere
  // der beiden Formulierungen.
  const reserve = Math.max(moreLine(list.length).length, moreLine(1).length) + 1
  let used = subject.length + 1
  const lines = []

  for (const [i, c] of list.entries()) {
    const line = `- ${t(GROUP_META[c.group].label)}: ${c.path}`
    // Nur die letzte Datei darf die Reserve mitbenutzen: Wenn sie noch passt,
    // braucht es die Abschlusszeile nicht mehr.
    const room = MESSAGE_BUDGET - (i === list.length - 1 ? 0 : reserve)
    if (used + line.length + 1 > room) {
      lines.push(moreLine(list.length - lines.length))
      break
    }
    lines.push(line)
    used += line.length + 1
  }

  return `${subject}\n\n${lines.join('\n')}`
}

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
  { title: t('repo.colSha'), key: 'shortSha', width: 120, sortable: false },
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
    applySuggestions()
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
  error.value = null
  const wanted = tag.value.trim()
  try {
    const res = await repo.commit(message.value.trim(), wanted)
    if (!res.success) {
      action.value = { type: 'error', key: 'repo.commitFail', output: res.output }
    } else if (wanted !== '' && !res.tagged) {
      // Der Stand ist gesichert, nur die Versionsnummer fehlt. Als Warnung, weil
      // ein erneutes Sichern nichts brächte — hier hilft nur eine andere Nummer.
      action.value = { type: 'warning', key: 'repo.tagFail', params: [wanted], output: res.tagOutput }
    } else {
      action.value = res.tagged
        ? { type: 'success', key: 'repo.commitOkTagged', params: [res.tag], output: res.output }
        : { type: 'success', key: 'repo.commitOk', output: res.output }
    }
    if (res.success) {
      // Beide Felder gelten nach dem Sichern wieder als unverändert, damit die
      // frischen Vorschläge einrücken — der gesicherte Text hat sich erledigt.
      message.value = ''
      suggested.message = ''
      suggested.tag = tag.value
      await repo.refresh()
      applySuggestions()
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
    action.value = {
      type: res.success ? 'success' : 'error',
      key: res.success ? 'repo.pushOk' : 'repo.pushFail',
      output: res.output,
    }
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
    action.value = {
      type: res.success ? 'success' : 'error',
      key: res.success ? 'repo.resetOk' : 'repo.resetFail',
      output: res.output,
    }
    if (res.success) {
      await repo.refresh()
      applySuggestions()
    }
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    resetting.value = false
  }
}

// Bezeichnung des gewählten Standes für den Kopf des Diff-Dialogs: die
// Versionsnummer, wenn eine vergeben ist, sonst der gekürzte Hash.
const selectedLabel = ref('')

async function onRowClick(_event, { item }) {
  selectedLabel.value = item.tags?.[0] ?? item.shortSha
  diffDialog.value = true
  diffLoading.value = true
  try {
    await repo.fetchDiff(item.sha)
  } catch (e) {
    error.value = errorText(t, e)
    diffDialog.value = false
  } finally {
    diffLoading.value = false
  }
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
          v-if="auth.git"
          icon="mdi-refresh"
          variant="text"
          size="small"
          :loading="loading"
          :title="$t('repo.reload')"
          @click="reload"
        />
      </v-card-title>

      <v-card-text style="max-height: 70vh">
        <!-- Ohne Freischaltung nur der Hinweis, was Git hier leisten würde. -->
        <ProGate
          v-if="!auth.git"
          feature="git"
          @activate="model = false; emit('activate-license')"
        />
        <v-skeleton-loader v-else-if="loading && !repo.status" type="article" />
        <template v-else>
          <!-- Status -->
          <div v-if="repo.status" class="d-flex align-center flex-wrap mb-3" style="gap: 8px">
            <v-chip size="small" prepend-icon="mdi-source-branch" label>{{ repo.status.branch }}</v-chip>
            <v-chip v-if="repo.status.clean" color="success" size="small" prepend-icon="mdi-check" label>
              {{ $t('repo.clean') }}
            </v-chip>
            <template v-else>
              <v-chip
                v-for="s in summary"
                :key="s.group"
                size="small"
                variant="tonal"
                :color="GROUP_META[s.group].color"
                :prepend-icon="GROUP_META[s.group].icon"
                label
              >
                {{ $t(GROUP_META[s.group].count, [s.count]) }}
              </v-chip>
            </template>
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

          <!-- Geänderte Dateien, je Zeile mit der Art der Änderung -->
          <div v-if="changes.length" class="mb-3">
            <div
              v-for="c in changes"
              :key="c.status + '-' + c.path"
              class="d-flex align-center py-1"
            >
              <v-chip
                :color="GROUP_META[c.group].color"
                :prepend-icon="GROUP_META[c.group].icon"
                size="x-small"
                variant="tonal"
                label
                class="repo-badge mr-2"
              >
                {{ $t(GROUP_META[c.group].label) }}
              </v-chip>
              <span class="repo-path">{{ c.path }}</span>
              <span v-if="c.from" class="text-caption text-medium-emphasis ml-2 flex-shrink-0">
                {{ $t('repo.renamedFrom', [c.from]) }}
              </span>
            </div>
          </div>

          <!-- Commit-Formular -->
          <v-textarea
            v-model="message"
            :label="$t('repo.commitMessage')"
            prepend-inner-icon="mdi-message-text-outline"
            variant="outlined"
            density="comfortable"
            rows="4"
            auto-grow
            max-rows="12"
            :counter="MESSAGE_MAX"
            :disabled="repo.status?.clean"
            @keydown.enter.ctrl.prevent="doCommit"
          />
          <!-- Versionsnummer: vorbelegt mit dem fortlaufenden Vorschlag, aber
               frei überschreibbar; leer sichert den Stand ohne Nummer. -->
          <v-text-field
            v-model="tag"
            :label="$t('repo.tag')"
            :hint="$t('repo.tagHint')"
            persistent-hint
            prepend-inner-icon="mdi-tag-outline"
            variant="outlined"
            density="comfortable"
            :disabled="repo.status?.clean"
            class="repo-tag mb-1"
            @keydown.enter.prevent="doCommit"
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
            :type="action.type"
            density="compact"
            class="mb-3"
            closable
            @click:close="action = null"
          >
            <div>{{ $t(action.key, action.params ?? []) }}</div>
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
            <!-- Versionsnummer statt Hash, wo eine vergeben ist: Sie ist das,
                 was der Benutzer selbst gesetzt hat und wiedererkennt. Ohne Tag
                 bleibt der gekürzte Hash als einzige Kennung. -->
            <template #[`item.shortSha`]="{ item }">
              <template v-if="item.tags?.length">
                <v-chip
                  v-for="tagName in item.tags"
                  :key="tagName"
                  size="x-small"
                  color="primary"
                  variant="tonal"
                  prepend-icon="mdi-tag-outline"
                  label
                  class="mr-1"
                >{{ tagName }}</v-chip>
              </template>
              <code v-else>{{ item.shortSha }}</code>
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
        </template>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="model = false">{{ $t('app.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Diff des ausgewählten Commits als überlagernder Dialog -->
  <v-dialog
    v-model="diffDialog"
    width="900"
    scrollable
    :fullscreen="smAndDown"
    @after-leave="repo.clearDiff()"
  >
    <v-card class="pa-2">
      <v-card-title class="d-flex align-center text-h6">
        <v-icon icon="mdi-file-compare" color="primary" class="mr-2" />
        {{ $t('repo.diffOf', [selectedLabel]) }}
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" size="small" @click="diffDialog = false" />
      </v-card-title>

      <v-card-text>
        <div v-if="diffLoading" class="d-flex justify-center pa-6">
          <v-progress-circular indeterminate color="primary" />
        </div>
        <div v-else-if="diffLines.length === 0" class="text-medium-emphasis text-body-2 pa-2">
          {{ $t('repo.diffEmpty') }}
        </div>
        <pre v-else class="repo-diff nemo-scroll"><template
          v-for="(l, i) in diffLines"
          :key="i"
        ><span :class="'diff-' + l.kind">{{ l.line }}</span>
</template></pre>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.repo-table :deep(tbody tr) {
  cursor: pointer;
}
/* Feste Breite: So stehen die Pfade in einer Flucht und die Liste lässt sich
   nach der Art der Änderung überfliegen. */
.repo-badge {
  min-width: 86px;
  flex-shrink: 0;
}
/* Gelöschte Pfade werden bewusst normal gesetzt (weder durchgestrichen noch
   gedimmt): Die Art der Änderung trägt das Abzeichen, der Pfad bleibt lesbar. */
.repo-path {
  font-size: 0.82rem;
  word-break: break-all;
}
/* Die Versionsnummer ist kurz — ein Feld über die volle Dialogbreite ließe sie
   verloren wirken und suggerierte einen langen Wert. */
.repo-tag {
  max-width: 260px;
}
.repo-output {
  font-size: 0.78rem;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 160px;
  overflow: auto;
}
.repo-diff {
  /* Kein eigenes max-height: der scrollbare Dialog-Text übernimmt das Scrollen,
     damit der Diff im Vollbild (Smartphone) die volle Höhe nutzt. */
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
