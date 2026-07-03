<script setup>
// Bild-Editor (filerobot-image-editor): öffnet ein Rasterbild als Vollbild-
// Overlay, lädt das Original über cmd=raw und schreibt die bearbeitete Fassung
// über cmd=writeimage zurück. Die schwergewichtige Bibliothek wird erst beim
// Öffnen dynamisch geladen, damit sie nicht ins Haupt-Bundle wandert.
import { ref, reactive, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'

const { t, locale } = useI18n()
const files = useFilesStore()

const container = ref(null)
let editor = null // laufende filerobot-Instanz (oder null)

// Speichern-Dialog: erscheint, nachdem der Editor die bearbeiteten Bilddaten
// geliefert hat. Der Benutzer wählt Überschreiben oder Kopie.
const saveDialog = reactive({ open: false, mode: 'overwrite', name: '', data: null, error: null })
const saving = ref(false)

function stemOf(name) {
  const dot = name.lastIndexOf('.')
  return dot > 0 ? name.slice(0, dot) : name
}

function extOf(name) {
  const dot = name.lastIndexOf('.')
  return dot > 0 ? name.slice(dot + 1).toLowerCase() : ''
}

// filerobot benennt das Speicherformat 'jpeg' statt 'jpg'.
function savedType(ext) {
  return ext === 'jpg' ? 'jpeg' : ext
}

async function initEditor() {
  const entry = files.editImageEntry
  if (!entry || !container.value || editor) return

  const mod = await import('filerobot-image-editor')
  // Zwischen Laden und Fertigwerden könnte der Editor schon wieder geschlossen
  // worden sein — dann nicht mehr aufbauen.
  if (!files.editImageEntry || files.editImageEntry.id !== entry.id || !container.value) return
  // Das vanilla-Paket hängt TABS/TOOLS als statische Eigenschaften an den
  // Default-Export (keine benannten Exporte).
  const FilerobotImageEditor = mod.default
  const { TABS, TOOLS } = FilerobotImageEditor

  editor = new FilerobotImageEditor(container.value, {
    source: files.rawUrl(entry),
    defaultSavedImageName: stemOf(entry.name),
    defaultSavedImageType: savedType(extOf(entry.name)),
    language: locale.value === 'de' ? 'de' : 'en',
    // filerobot lädt Übersetzungen sonst von einem externen Dienst
    // (i18n.ultrafast.io) — das scheitert an CORS und widerspricht dem
    // Prinzip „keine externen Aufrufe". Eingebaute Texte verwenden.
    useBackendTranslations: false,
    // Den internen Speichern-Dialog (Name/Format/Qualität) überspringen und
    // direkt unseren Überschreiben/Kopie-Dialog zeigen.
    onBeforeSave: () => false,
    onSave: (edited) => openSaveDialog(edited),
    onClose: () => files.closeImageEditor(),
    tabsIds: [TABS.ADJUST, TABS.ANNOTATE, TABS.FILTERS, TABS.FINETUNE, TABS.RESIZE],
    defaultTabId: TABS.ADJUST,
    defaultToolId: TOOLS.CROP,
  })
  editor.render()
}

function destroyEditor() {
  if (editor) {
    try {
      editor.terminate()
    } catch {
      // Editor war bereits abgebaut — ignorieren.
    }
    editor = null
  }
  saveDialog.open = false
  saveDialog.data = null
}

function openSaveDialog(edited) {
  const entry = files.editImageEntry
  if (!entry) return
  saveDialog.data = edited.imageBase64
  saveDialog.mode = 'overwrite'
  saveDialog.name = `${stemOf(entry.name)}-bearbeitet.${extOf(entry.name)}`
  saveDialog.error = null
  saveDialog.open = true
}

async function confirmSave() {
  const entry = files.editImageEntry
  if (!entry || !saveDialog.data || saving.value) return
  const mode = saveDialog.mode
  const name = saveDialog.name.trim()
  if (mode === 'copy' && !name) return
  saving.value = true
  saveDialog.error = null
  try {
    await files.saveEditedImage(entry.id, saveDialog.data, { mode, name })
    saveDialog.open = false
    files.closeImageEditor()
  } catch (e) {
    // Editor und Dialog bleiben offen, damit der Benutzer erneut speichern kann.
    saveDialog.error = errorText(t, e)
  } finally {
    saving.value = false
  }
}

// Öffnen/Schließen wird zentral über den Store gesteuert.
watch(
  () => files.editImageId,
  async (id) => {
    if (id) {
      await nextTick()
      await initEditor()
    } else {
      destroyEditor()
    }
  },
)

onMounted(() => {
  if (files.editImageId) initEditor()
})
onBeforeUnmount(destroyEditor)
</script>

<template>
  <div v-if="files.editImageEntry" class="imgedit">
    <div ref="container" class="imgedit-canvas" />

    <!-- Speichern-Dialog: Überschreiben oder als Kopie -->
    <div v-if="saveDialog.open" class="imgedit-save">
      <div class="imgedit-save-card">
        <h2 class="imgedit-save-title">{{ t('imageEditor.saveTitle') }}</h2>
        <label class="imgedit-opt">
          <input v-model="saveDialog.mode" type="radio" value="overwrite" />
          <span>{{ t('imageEditor.overwrite', [files.editImageEntry.name]) }}</span>
        </label>
        <label class="imgedit-opt">
          <input v-model="saveDialog.mode" type="radio" value="copy" />
          <span>{{ t('imageEditor.saveCopy') }}</span>
        </label>
        <v-text-field
          v-model="saveDialog.name"
          :label="t('imageEditor.copyName')"
          :disabled="saveDialog.mode !== 'copy'"
          variant="outlined"
          density="compact"
          hide-details
          class="imgedit-name"
          @keydown.enter="confirmSave"
        />
        <v-alert v-if="saveDialog.error" type="error" density="compact" class="imgedit-save-error">
          {{ saveDialog.error }}
        </v-alert>
        <div class="imgedit-save-actions">
          <v-btn variant="text" :disabled="saving" @click="saveDialog.open = false">{{ t('common.cancel') }}</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="saving"
            :disabled="saveDialog.mode === 'copy' && !saveDialog.name.trim()"
            @click="confirmSave"
          >
            {{ t('common.save') }}
          </v-btn>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.imgedit {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: #1e1e1c;
}
.imgedit-canvas {
  width: 100%;
  height: 100%;
}

/* Eigener Speichern-Dialog über der Editor-Oberfläche (filerobot rendert im
   selben Overlay, daher ein höherer z-index statt eines v-dialog). */
.imgedit-save {
  position: absolute;
  inset: 0;
  z-index: 3100;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(20, 20, 19, 0.6);
}
.imgedit-save-card {
  width: min(440px, 92vw);
  padding: 20px;
  border-radius: var(--mint-radius);
  background: #fff;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
}
.imgedit-save-title {
  margin: 0 0 14px;
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--mint-text);
}
.imgedit-opt {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
  color: var(--mint-text);
  cursor: pointer;
}
.imgedit-name {
  margin-top: 10px;
}
.imgedit-save-error {
  margin-top: 12px;
}
.imgedit-save-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
</style>
