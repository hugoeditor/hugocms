<script setup>
import { ref, watch } from 'vue'
import { useFilesStore } from '../stores/files'
import CodeMirrorEditor from './CodeMirrorEditor.vue'

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
  if (!files.dirty) return
  saving.value = true
  error.value = null
  try {
    await files.saveOpenFile(draft.value)
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

function close() {
  if (files.dirty && !confirm('Ungespeicherte Änderungen verwerfen?')) return
  files.closeFile()
}
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
        >Speichern</v-btn>
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
