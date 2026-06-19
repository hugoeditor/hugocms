<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import CodeMirrorEditor from './CodeMirrorEditor.vue'
import { setEditorSaver } from '../util/editorBridge'
import WysiwygEditor from './WysiwygEditor.vue'
import FrontMatterPanel from './FrontMatterPanel.vue'
import { useTransientError } from '../util/transientError'

const { t } = useI18n()
const files = useFilesStore()

const draft = ref('')
const saving = ref(false)
const error = useTransientError() // blendet sich nach kurzer Zeit selbst aus

// --- Visueller Markdown-Modus (Stufe 4) -----------------------------------
// Nur für Markdown-Dateien. Das Front-Matter (--- … ---) wird VOR TipTap
// abgetrennt und separat als Rohtext bearbeitet — der visuelle Editor sieht
// nur den Body und kann die Metadaten nicht beschädigen.
const MODE_KEY = 'hugocms_md_mode'

const isMarkdown = computed(() => /\.(md|markdown)$/i.test(files.openFile?.name ?? ''))

function readMode() {
  try {
    const m = localStorage.getItem(MODE_KEY)
    if (m === 'source' || m === 'wysiwyg') return m
  } catch {
    // ignorieren
  }
  return 'wysiwyg'
}

const mdMode = ref(readMode())
const mode = computed(() => (isMarkdown.value ? mdMode.value : 'source'))

const fmDraft = ref('') // Front-Matter-Block inkl. ----Zeilen (oder '')
const bodyDraft = ref('') // Markdown-Body für TipTap

function splitFrontMatter(text) {
  const m = text.match(/^---\r?\n[\s\S]*?\r?\n---(\r?\n|$)/)
  if (!m) return { fm: '', body: text }
  return { fm: m[0], body: text.slice(m[0].length) }
}

function syncFromDraft() {
  const { fm, body } = splitFrontMatter(draft.value)
  fmDraft.value = fm
  bodyDraft.value = body
}

function setMode(m) {
  if (m === mdMode.value) return
  if (m === 'wysiwyg') syncFromDraft()
  mdMode.value = m
  try {
    localStorage.setItem(MODE_KEY, m)
  } catch {
    // ignorieren
  }
}

function onWysiwygInput(bodyMd) {
  bodyDraft.value = bodyMd
  onInput(fmDraft.value + bodyMd)
}

// Das Front-Matter-Panel meldet den fertigen Block (inkl. --- Zeilen) zurück;
// Body bleibt unangetastet.
function onFmInput(block) {
  fmDraft.value = block
  onInput(fmDraft.value + bodyDraft.value)
}

// Statuszeile: Cursorposition und erkannte Sprache, gemeldet vom Editor.
const cursor = ref({ line: 1, column: 1 })
const language = ref(null)

// Werkzeugleiste: ruft CodeMirror-Befehle über die exec-Schnittstelle des
// Editors auf; undo/redo werden nach dem Verlaufsstand (de)aktiviert.
const editorRef = ref(null)
const history = ref({ undo: false, redo: false })

const tools = computed(() => [
  {
    name: 'save',
    icon: 'mdi-content-save',
    label: t('editor.save'),
    disabled: !files.dirty,
    loading: saving.value,
    color: 'primary',
    action: save,
  },
  { divider: true },
  { name: 'undo', icon: 'mdi-undo', label: t('editor.undo'), disabled: !history.value.undo },
  { name: 'redo', icon: 'mdi-redo', label: t('editor.redo'), disabled: !history.value.redo },
  { divider: true },
  { name: 'clipboardCut', icon: 'mdi-content-cut', label: t('editor.cut') },
  { name: 'clipboardCopy', icon: 'mdi-content-copy', label: t('editor.copy') },
  { name: 'clipboardPaste', icon: 'mdi-content-paste', label: t('editor.paste') },
  { divider: true },
  { name: 'openSearchPanel', icon: 'mdi-magnify', label: t('editor.search') },
  { name: 'gotoLine', icon: 'mdi-format-list-numbered', label: t('editor.gotoLine') },
  { divider: true },
  { name: 'indentLess', icon: 'mdi-format-indent-decrease', label: t('editor.indentLess') },
  { name: 'indentMore', icon: 'mdi-format-indent-increase', label: t('editor.indentMore') },
  { name: 'toggleComment', icon: 'mdi-comment-text-outline', label: t('editor.toggleComment') },
  { divider: true },
  { name: 'foldAll', icon: 'mdi-unfold-less-horizontal', label: t('editor.foldAll') },
  { name: 'unfoldAll', icon: 'mdi-unfold-more-horizontal', label: t('editor.unfoldAll') },
])

// Bei jedem neu geöffneten File den Entwurf übernehmen.
watch(
  () => files.openFile?.id,
  () => {
    draft.value = files.openFile?.content ?? ''
    files.dirty = false
    error.value = null
    if (isMarkdown.value && mode.value === 'wysiwyg') syncFromDraft()
  },
)

// Externer Neu-Lade-Anstoß (z. B. nachdem der KI-Assistent die offene Datei
// geändert hat): Entwurf aus dem aktualisierten Inhalt übernehmen. Der
// CodeMirror-/WYSIWYG-Schlüssel enthält reloadTick und remountet dadurch.
watch(
  () => files.reloadTick,
  () => {
    draft.value = files.openFile?.content ?? ''
    files.dirty = false
    error.value = null
    if (isMarkdown.value && mode.value === 'wysiwyg') syncFromDraft()
  },
)

function onInput(value) {
  draft.value = value
  files.dirty = value !== files.openFile?.content
}

// Speichert den offenen Entwurf. Rückgabe: true, wenn nichts zu speichern war
// oder das Speichern gelang; false bei Fehler/abgelehntem Konflikt. Der
// boolesche Rückgabewert erlaubt Aufrufern (z. B. dem Veröffentlichen vor dem
// Build), nur bei Erfolg fortzufahren.
async function save() {
  // saving fängt Doppelaufrufe ab: Strg+S erreicht sowohl den CodeMirror-
  // Keymap als auch den globalen Fenster-Handler; der zweite Aufruf würde
  // sonst mit veralteter mtime speichern und einen Scheinkonflikt auslösen.
  if (saving.value) return false
  if (!files.dirty) return true
  saving.value = true
  error.value = null
  try {
    await files.saveOpenFile(draft.value)
    return true
  } catch (e) {
    // Externer Konflikt: Überschreiben nur nach ausdrücklicher Bestätigung.
    if (e?.code === 'ECONFLICT' && confirm(t('editor.conflictConfirm'))) {
      try {
        await files.saveOpenFile(draft.value, { force: true })
        error.value = null
        return true
      } catch (e2) {
        error.value = errorText(t, e2)
        return false
      }
    }
    error.value = errorText(t, e)
    return false
  } finally {
    saving.value = false
  }
}

// Für Aufrufer außerhalb (App.vue: vor dem Build speichern).
defineExpose({ save })

function close() {
  if (files.dirty && !confirm(t('editor.discardConfirm'))) return
  files.closeFile()
}

// Ctrl/Cmd+S speichert auch, wenn der Fokus außerhalb von CodeMirror liegt
// (Toolbar, Dialogrand). Sonst löst der Browser seinen Speichern-Dialog aus.
function onKeydown(e) {
  if (files.openFile && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault()
    save()
  }
}

// Schützt vor Datenverlust beim Schließen/Neuladen des Browsertabs.
function onBeforeWindowUnload(e) {
  if (!files.dirty) return
  e.preventDefault()
  e.returnValue = ''
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('beforeunload', onBeforeWindowUnload)
  // Dem Assistenten erlauben, ungespeicherte Änderungen vor einem Zug zu sichern.
  setEditorSaver(save)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeWindowUnload)
  setEditorSaver(null)
})
</script>

<template>
  <!-- Überlagerung des Arbeitsbereichs (nicht der Titelleiste): füllt den
       position:relative-Rahmen .nemo-workspace in App.vue. -->
  <div v-if="files.openFile" class="editor-overlay">
    <v-card class="d-flex flex-column flex-grow-1" flat tile style="height: 100%">
      <v-toolbar color="surface" density="comfortable" flat>
        <!-- Zurück zum Dateimanager — gleiche Wirkung wie das Schließen-Kreuz. -->
        <v-tooltip :text="$t('app.close')" location="bottom">
          <template #activator="{ props: tip }">
            <v-btn v-bind="tip" icon="mdi-arrow-left" variant="text" class="ml-1" @click="close" />
          </template>
        </v-tooltip>
        <v-icon class="mx-3" icon="mdi-file-document-edit" />
        <v-toolbar-title>
          {{ files.openFile.name }}
          <span v-if="files.dirty" class="text-warning">•</span>
        </v-toolbar-title>
        <v-spacer />

        <!-- Moduswechsel (nur Markdown): Visuell <-> Quelltext -->
        <v-btn-toggle
          v-if="isMarkdown"
          :model-value="mode"
          density="comfortable"
          variant="outlined"
          divided
          mandatory
          class="mr-3"
          @update:model-value="setMode"
        >
          <v-btn value="wysiwyg" size="small" prepend-icon="mdi-format-text">
            {{ $t('editor.wysiwygView') }}
          </v-btn>
          <v-btn value="source" size="small" prepend-icon="mdi-code-tags">
            {{ $t('editor.sourceView') }}
          </v-btn>
        </v-btn-toggle>

        <v-btn icon="mdi-close" variant="text" @click="close" />
      </v-toolbar>

      <!-- Werkzeugleiste des Quelltext-Editors; im visuellen Modus trägt die
           Formatleiste des WysiwygEditor den Speichern-Knopf selbst. -->
      <div v-if="mode === 'source'" class="d-flex flex-wrap align-center border-b px-2 py-1">
        <template v-for="(tool, i) in tools" :key="i">
          <v-divider v-if="tool.divider" vertical class="mx-1 align-self-stretch" />
          <v-tooltip v-else :text="tool.label" location="bottom">
            <template #activator="{ props: tip }">
              <v-btn
                v-bind="tip"
                :icon="tool.icon"
                size="small"
                variant="text"
                density="comfortable"
                :color="tool.color"
                :disabled="tool.disabled"
                :loading="tool.loading"
                @click="tool.action ? tool.action() : editorRef?.exec(tool.name)"
              />
            </template>
          </v-tooltip>
        </template>
      </div>

      <v-alert v-if="error" type="error" density="compact" class="ma-0 nemo-alert" tile>{{ error }}</v-alert>

      <div class="flex-grow-1 d-flex flex-column" style="overflow: hidden">
        <!-- Visueller Markdown-Modus: Front-Matter separat, Body in TipTap -->
        <template v-if="mode === 'wysiwyg'">
          <FrontMatterPanel
            :key="files.openFile.id + ':fm:' + files.reloadTick"
            :model-value="fmDraft"
            @update:model-value="onFmInput"
          />
          <WysiwygEditor
            :key="files.openFile.id + ':wysiwyg:' + files.reloadTick"
            :model-value="bodyDraft"
            :save-disabled="!files.dirty"
            :saving="saving"
            class="flex-grow-1"
            style="min-height: 0"
            @update:model-value="onWysiwygInput"
            @save="save"
            @clipboard-denied="error = t('editor.clipboardDenied')"
          />
        </template>

        <CodeMirrorEditor
          v-else
          ref="editorRef"
          :key="files.openFile.id + ':' + files.reloadTick"
          :model-value="draft"
          :filename="files.openFile.name"
          class="flex-grow-1"
          @update:model-value="onInput"
          @save="save"
          @cursor="cursor = $event"
          @language="language = $event"
          @history="history = $event"
          @clipboard-denied="error = t('editor.clipboardDenied')"
        />
      </div>

      <div v-if="mode === 'source'" class="d-flex align-center border-t px-4 py-1 text-caption text-medium-emphasis">
        <span>{{ $t('editor.cursor', [cursor.line, cursor.column]) }}</span>
        <v-spacer />
        <span>{{ language ?? $t('editor.plainText') }}</span>
      </div>
    </v-card>
  </div>
</template>

<style scoped>
/* Editor als Überlagerung über Werkzeugleiste + Dateibereich; die
   Nemo-Titelleiste darüber bleibt sichtbar und bedienbar (z. B. Abmelden). */
.editor-overlay {
  position: absolute;
  inset: 0;
  z-index: 10;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}
</style>
