<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import CodeMirrorEditor from './CodeMirrorEditor.vue'

const { t } = useI18n()
const files = useFilesStore()

const draft = ref('')
const saving = ref(false)
const error = ref(null)

// Bei jedem neu geöffneten File den Entwurf übernehmen.
watch(
  () => files.openFile?.id,
  () => {
    draft.value = files.openFile?.content ?? ''
    files.dirty = false
    error.value = null
  },
)

function onInput(value) {
  draft.value = value
  files.dirty = value !== files.openFile?.content
}

async function save() {
  // saving fängt Doppelaufrufe ab: Strg+S erreicht sowohl den CodeMirror-
  // Keymap als auch den globalen Fenster-Handler; der zweite Aufruf würde
  // sonst mit veralteter mtime speichern und einen Scheinkonflikt auslösen.
  if (!files.dirty || saving.value) return
  saving.value = true
  error.value = null
  try {
    await files.saveOpenFile(draft.value)
  } catch (e) {
    // Externer Konflikt: Überschreiben nur nach ausdrücklicher Bestätigung.
    if (e?.code === 'ECONFLICT' && confirm(t('editor.conflictConfirm'))) {
      try {
        await files.saveOpenFile(draft.value, { force: true })
        error.value = null
        return
      } catch (e2) {
        error.value = errorText(t, e2)
        return
      }
    }
    error.value = errorText(t, e)
  } finally {
    saving.value = false
  }
}

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
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeWindowUnload)
})
</script>

<template>
  <v-dialog :model-value="!!files.openFile" fullscreen :scrim="false" transition="dialog-bottom-transition">
    <v-card v-if="files.openFile" class="d-flex flex-column" style="height: 100vh">
      <v-toolbar color="surface" density="comfortable" flat>
        <v-icon class="mx-3" icon="mdi-file-document-edit" />
        <v-toolbar-title>
          {{ files.openFile.name }}
          <span v-if="files.dirty" class="text-warning">•</span>
        </v-toolbar-title>
        <v-spacer />
        <v-btn
          prepend-icon="mdi-content-save"
          color="primary"
          variant="flat"
          class="mr-2"
          :loading="saving"
          :disabled="!files.dirty"
          @click="save"
        >{{ $t('editor.save') }}</v-btn>
        <v-btn icon="mdi-close" variant="text" @click="close" />
      </v-toolbar>

      <v-alert v-if="error" type="error" density="compact" class="ma-0" tile>{{ error }}</v-alert>

      <div class="flex-grow-1" style="overflow: hidden">
        <CodeMirrorEditor
          :key="files.openFile.id"
          :model-value="draft"
          :filename="files.openFile.name"
          @update:model-value="onInput"
          @save="save"
        />
      </div>
    </v-card>
  </v-dialog>
</template>
