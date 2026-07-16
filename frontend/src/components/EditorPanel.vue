<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { useAuthStore } from '../stores/auth'
import { useAuditContentStore } from '../stores/auditContent'
import { useAssistantStore } from '../stores/assistant'
import { errorText } from '../i18n/apiMessage'
import CodeMirrorEditor from './CodeMirrorEditor.vue'
import { setEditorSaver, flushEditor } from '../util/editorBridge'
import WysiwygEditor from './WysiwygEditor.vue'
import FrontMatterPanel from './FrontMatterPanel.vue'
import FrontMatterDialog from './FrontMatterDialog.vue'
import HugoConfigEditor from './settings/HugoConfigEditor.vue'
import { useTransientError } from '../util/transientError'
import { useConfirm } from '../util/confirm'
import { useAiGate } from '../util/aiGate'
import { frontMatterMeta } from '../util/frontMatterMeta'
import { missingFrontMatterFields, applyFrontMatterFields, hasLastmod, withUpdatedLastmod } from '../util/frontMatterTemplate'

const { t, locale } = useI18n()
const files = useFilesStore()
const auth = useAuthStore()
const auditContent = useAuditContentStore()
const assistant = useAssistantStore()
const confirm = useConfirm()
const requireAi = useAiGate()

// LLM-Content-Qualität der offenen Markdown-Datei prüfen (Pro + KI-Schlüssel).
// Fehlt der KI-Schlüssel, führt requireAi() zum Hinweis + Konfiguration.
async function checkContentQuality() {
  if (!files.openFile) return
  if (!(await requireAi())) return
  auditContent.check(files.openFile.id, files.openFile.name, locale.value)
}

// Schnellweg: die offene Markdown-Datei direkt von der KI verbessern lassen
// (ohne vorher einen Qualitätsbericht zu erstellen). Ungespeicherte Änderungen
// zuvor sichern, damit die KI den aktuellen Stand verbessert und nicht
// überschreibt.
async function improveContent() {
  if (!files.openFile) return
  if (!(await requireAi())) return
  if (files.dirty) {
    const saved = await flushEditor()
    if (!saved) return
  }
  assistant.improve(files.openFile.id, locale.value)
}

const draft = ref('')
const saving = ref(false)
const savingDraft = ref(false)
const error = useTransientError() // blendet sich nach kurzer Zeit selbst aus
const notice = useTransientError(4000) // kurzlebige Erfolgsmeldung (z. B. Entwurf abgelegt)

// --- Visueller Markdown-Modus (Stufe 4) -----------------------------------
// Nur für Markdown-Dateien. Das Front-Matter (--- … ---) wird VOR TipTap
// abgetrennt und separat als Rohtext bearbeitet — der visuelle Editor sieht
// nur den Body und kann die Metadaten nicht beschädigen.
const MD_MODE_KEY = 'hugocms_md_mode'
const JSON_MODE_KEY = 'hugocms_json_mode'

const isMarkdown = computed(() => /\.(md|markdown)$/i.test(files.openFile?.name ?? ''))

// Speicherort der offenen Datei: Mount und Verzeichnis OHNE den Dateinamen —
// der steht bereits als Titel darüber. Das Backend liefert `path` als
// „<mount>/<relativer Pfad>“, wobei der Mount seine interne Kennung trägt; hier
// erscheint er mit dem Anzeigenamen aus der Seitenleiste. Quelle ist bewusst
// openFile.path und nicht der Breadcrumb: Aus dem SEO-Bericht heraus geöffnete
// Dateien liegen nicht im gerade angezeigten Verzeichnis.
const storagePath = computed(() => {
  const raw = files.openFile?.path ?? ''
  if (raw === '') return ''
  const segments = raw.split('/')
  const mountName = segments.shift()
  segments.pop() // Dateiname
  const label = files.mounts.find((m) => m.name === mountName)?.label ?? mountName
  return [label, ...segments].join('/') + '/'
})

// Veröffentlichungszustand aus dem Front Matter (Entwurf / geplant / abgelaufen)
// — macht sichtbar, ob und wann eine Content-Datei live geht. Reaktiv auf den
// Editorinhalt, aktualisiert sich also beim Tippen. Nur für Markdown-Content.
const publishStatus = computed(() => {
  if (!isMarkdown.value) return null
  const meta = frontMatterMeta(draft.value)
  if (meta.draft === true) return { kind: 'draft', date: null }
  const now = Date.now()
  if (meta.expiryDate) {
    const t = new Date(meta.expiryDate).getTime()
    if (!Number.isNaN(t) && t <= now) return { kind: 'expired', date: meta.expiryDate }
  }
  if (meta.publishDate) {
    const t = new Date(meta.publishDate).getTime()
    if (!Number.isNaN(t) && t > now) return { kind: 'scheduled', date: meta.publishDate }
  }
  return null
})

function formatDateTime(iso) {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value)
}
// Visuellen JSON-Editor gibt es nur für die Hugo-Konfigurationsdatei
// (hugo.json/config.json) — andere JSON-Dateien bleiben im Quelltext.
const isHugoConfig = computed(() => /^(hugo|config)\.json$/i.test(files.openFile?.name ?? ''))
const supportsVisual = computed(() => isMarkdown.value || isHugoConfig.value)

function readMode(key, fallback) {
  try {
    const m = localStorage.getItem(key)
    if (m === 'source' || m === 'wysiwyg' || m === 'visual') return m
  } catch {
    // ignorieren
  }
  return fallback
}
function persistMode(key, value) {
  try {
    localStorage.setItem(key, value)
  } catch {
    // ohne lokalen Speicher unkritisch
  }
}

// Vorgabe je Typ getrennt gemerkt: Markdown startet visuell, JSON bewusst im
// Quelltext (siehe Phase-1-Entscheidung).
const mdMode = ref(readMode(MD_MODE_KEY, 'wysiwyg')) // 'wysiwyg' | 'source'
const jsonMode = ref(readMode(JSON_MODE_KEY, 'source')) // 'visual' | 'source'

// Ungültiges JSON kann der visuelle Editor nicht darstellen — dann bleibt nur
// der Quelltext (zur Reparatur). Bei diesen Dateigrößen ist das Parsen je
// Tastendruck unkritisch.
const jsonParseable = computed(() => {
  if (!isHugoConfig.value) return true
  try {
    JSON.parse(draft.value)
    return true
  } catch {
    return false
  }
})

// Der Wert des „Visuell“-Knopfs unterscheidet sich je Typ (Markdown: wysiwyg,
// Hugo-Konfig: visual), damit derselbe Umschalter beide bedient.
const visualValue = computed(() => (isHugoConfig.value ? 'visual' : 'wysiwyg'))

const mode = computed(() => {
  if (isMarkdown.value) return mdMode.value
  if (isHugoConfig.value) return jsonParseable.value ? jsonMode.value : 'source'
  return 'source'
})

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
  if (!m) return
  if (isMarkdown.value) {
    if (m === mdMode.value) return
    if (m === 'wysiwyg') syncFromDraft()
    mdMode.value = m
    persistMode(MD_MODE_KEY, m)
  } else if (isHugoConfig.value) {
    if (m === jsonMode.value) return
    jsonMode.value = m
    persistMode(JSON_MODE_KEY, m)
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
  // Als Entwurf zur Freigabe ablegen (gestaffelte Veröffentlichung) — nur, wenn
  // die Webseite ein Hugo-Projekt ist (dort greifen draft/publishDate).
  ...(auth.review ? [{
    name: 'saveDraft',
    icon: 'mdi-content-save-edit-outline',
    label: t('editor.saveDraft'),
    disabled: !files.dirty,
    loading: savingDraft.value,
    action: saveAsDraft,
  }] : []),
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

// --- Front-Matter-Ergänzung beim Öffnen -----------------------------------
// Fehlen einer Hugo-Content-Datei empfohlene Front-Matter-Felder, wird beim
// Öffnen ein Dialog mit den fehlenden Feldern (Vorschlagswerte) angeboten —
// statt sie still zu ergänzen. So bleibt „draft: true" sichtbar und wird bewusst
// übernommen. date/lastmod richten sich nach der mtime der Datei.
const fmDialog = ref(false)
const fmFields = ref([])

function maybePromptFrontMatter(raw) {
  fmDialog.value = false
  fmFields.value = []
  if (!files.openFile?.contentFile || !isMarkdown.value) return
  const when = files.openFile.mtime ? new Date(files.openFile.mtime * 1000) : new Date()
  const { skip, fields } = missingFrontMatterFields(raw, files.openFile.name, when)
  if (skip || fields.length === 0) return
  fmFields.value = fields
  fmDialog.value = true
}

// Ersetzt den Entwurfsinhalt modusgerecht: im Quelltext-Modus über eine
// CodeMirror-Transaktion (damit „Rückgängig" den Schritt zurücknehmen kann und
// der Editor synchron bleibt), sonst direkt in den Entwurf (der strukturierte
// Front-Matter-Editor führt keine eigene Historie). Datei gilt danach als
// ungespeichert.
function setDraftContent(next) {
  if (next === draft.value) return
  if (mode.value === 'source' && editorRef.value?.replaceAll) {
    editorRef.value.replaceAll(next) // löst update:modelValue -> onInput aus
    return
  }
  onInput(next)
  if (isMarkdown.value && mode.value === 'wysiwyg') syncFromDraft()
}

// Übernimmt die im Dialog bestätigten Felder ins Front Matter.
function applyFrontMatter(fields) {
  setDraftContent(applyFrontMatterFields(draft.value, files.openFile?.name ?? '', fields))
}

// --- lastmod beim Speichern aktualisieren ----------------------------------
// Fehlt [user] update_lastmod (auth.ui.updateLastmod === null), wird vor dem
// Speichern gefragt, ob ein vorhandenes lastmod-Feld auf jetzt gesetzt werden
// soll — mit Option, die Wahl dauerhaft in der hugocms.ini zu merken.
const lastmodDialog = ref(false)
const lastmodRemember = ref(false)
let lastmodResolver = null

function askLastmod() {
  lastmodRemember.value = false
  lastmodDialog.value = true
  return new Promise((resolve) => {
    lastmodResolver = resolve
  })
}

function resolveLastmod(update) {
  lastmodDialog.value = false
  const resolve = lastmodResolver
  lastmodResolver = null
  if (resolve) resolve({ update, remember: lastmodRemember.value })
}

// Vor dem eigentlichen Speichern aufrufen (normal wie „als Entwurf"). Ändert bei
// Bedarf den Entwurf (lastmod = jetzt). Kein lastmod-Feld -> nichts zu tun.
async function maybeUpdateLastmod() {
  if (!isMarkdown.value || !hasLastmod(draft.value)) return
  const pref = auth.ui?.updateLastmod
  let update = pref
  if (pref !== true && pref !== false) {
    const res = await askLastmod()
    update = res.update
    if (res.remember) {
      try {
        await auth.setUpdateLastmod(res.update)
      } catch {
        // Persistenz-Fehler soll das Speichern nicht blockieren.
      }
    }
  }
  if (update) setDraftContent(withUpdatedLastmod(draft.value).content)
}

// Bei jedem neu geöffneten File den Entwurf übernehmen.
watch(
  () => files.openFile?.id,
  () => {
    draft.value = files.openFile?.content ?? ''
    files.dirty = false
    error.value = null
    if (isMarkdown.value && mode.value === 'wysiwyg') syncFromDraft()
    maybePromptFrontMatter(draft.value)
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
    // Vor dem Schreiben ggf. lastmod aktualisieren (Dialog, falls Wahl fehlt).
    await maybeUpdateLastmod()
    await files.saveOpenFile(draft.value)
    return true
  } catch (e) {
    // Externer Konflikt: Überschreiben nur nach ausdrücklicher Bestätigung.
    if (e?.code === 'ECONFLICT' && (await confirm({
      title: t('editor.conflictTitle'),
      message: t('editor.conflictConfirm'),
      confirmText: t('editor.conflictAction'),
      color: 'warning',
    }))) {
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

// Legt den aktuellen Stand als Freigabe-Entwurf ab, statt live zu speichern.
// Die Live-Datei bleibt unverändert; der Entwurf wartet in der Warteschlange.
async function saveAsDraft() {
  if (savingDraft.value || !files.openFile) return
  savingDraft.value = true
  error.value = null
  try {
    await maybeUpdateLastmod()
    await files.saveAsDraft(draft.value)
    notice.value = t('editor.savedAsDraft')
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    savingDraft.value = false
  }
}

// Für Aufrufer außerhalb (App.vue: vor dem Build speichern).
defineExpose({ save })

async function close() {
  if (files.dirty && !(await confirm({
    title: t('editor.discardTitle'),
    message: t('editor.discardConfirm'),
    confirmText: t('editor.discardAction'),
    color: 'warning',
  }))) return
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
          <div class="editor-name">
            {{ files.openFile.name }}
            <span v-if="files.dirty" class="text-warning dirty-dot">•</span>
          </div>
          <!-- Speicherort unter dem Dateinamen: bei gleichnamigen Dateien in
               mehreren Mounts die einzige Unterscheidung. -->
          <div
            v-if="storagePath"
            class="editor-path"
            :title="storagePath"
            :aria-label="$t('editor.storageLocation', [storagePath])"
          >
            <v-icon icon="mdi-folder-outline" size="13" class="mr-1" />{{ storagePath }}
          </div>
        </v-toolbar-title>
        <v-spacer />

        <!-- Moduswechsel (Markdown/Hugo-Konfig): Visuell <-> Quelltext. Bei
             ungültigem JSON ist „Visuell“ gesperrt (erst im Quelltext
             reparieren). -->
        <v-tooltip
          v-if="supportsVisual"
          :text="$t('editor.jsonInvalid')"
          :disabled="!(isHugoConfig && !jsonParseable)"
          location="bottom"
        >
          <template #activator="{ props: tip }">
            <div v-bind="tip" class="mr-3">
              <v-btn-toggle
                :model-value="mode"
                density="comfortable"
                variant="outlined"
                divided
                mandatory
                @update:model-value="setMode"
              >
                <v-btn
                  :value="visualValue"
                  :disabled="isHugoConfig && !jsonParseable"
                  size="small"
                  :prepend-icon="isHugoConfig ? 'mdi-cog-outline' : 'mdi-format-text'"
                >
                  {{ $t('editor.wysiwygView') }}
                </v-btn>
                <v-btn value="source" size="small" prepend-icon="mdi-code-tags">
                  {{ $t('editor.sourceView') }}
                </v-btn>
              </v-btn-toggle>
            </div>
          </template>
        </v-tooltip>

        <!-- LLM-Content-Qualität: für Markdown-Dateien immer sichtbar, damit die
             Funktion auffindbar ist. Fehlt der KI-Schlüssel, meldet sich der Klick
             mit einem Hinweis. Prüfen erzeugt einen Bericht; Verbessern ist der
             Schnellweg direkt in den Assistenten (ohne vorherigen Bericht). -->
        <template v-if="isMarkdown">
          <v-tooltip :text="$t('contentQuality.check')" location="bottom">
            <template #activator="{ props: tip }">
              <v-btn
                v-bind="tip"
                icon="mdi-text-search"
                variant="text"
                :loading="auditContent.busy"
                @click="checkContentQuality"
              />
            </template>
          </v-tooltip>
          <v-tooltip :text="$t('contentQuality.improve')" location="bottom">
            <template #activator="{ props: tip }">
              <v-btn
                v-bind="tip"
                icon="mdi-creation"
                variant="text"
                color="primary"
                @click="improveContent"
              />
            </template>
          </v-tooltip>
        </template>

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
      <v-alert v-if="notice" type="success" density="compact" class="ma-0 nemo-alert" tile>{{ notice }}</v-alert>
      <!-- Veröffentlichungszustand aus dem Front Matter: Entwurf / geplant / abgelaufen. -->
      <v-alert
        v-if="publishStatus"
        :type="publishStatus.kind === 'expired' ? 'warning' : 'info'"
        density="compact"
        class="ma-0 nemo-alert"
        tile
      >
        <template v-if="publishStatus.kind === 'draft'">{{ $t('editor.publishDraft') }}</template>
        <template v-else-if="publishStatus.kind === 'scheduled'">{{ $t('editor.publishScheduled', [formatDateTime(publishStatus.date)]) }}</template>
        <template v-else>{{ $t('editor.publishExpired', [formatDateTime(publishStatus.date)]) }}</template>
      </v-alert>

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
            :saving-draft="savingDraft"
            class="flex-grow-1"
            style="min-height: 0"
            @update:model-value="onWysiwygInput"
            @save="save"
            @save-draft="saveAsDraft"
            @clipboard-denied="error = t('editor.clipboardDenied')"
          />
        </template>

        <!-- Visueller Hugo-Konfigurations-Editor -->
        <HugoConfigEditor
          v-else-if="mode === 'visual'"
          :key="files.openFile.id + ':hugo:' + files.reloadTick"
          :model-value="draft"
          :save-disabled="!files.dirty"
          :saving="saving"
          class="flex-grow-1"
          style="min-height: 0"
          @update:model-value="onInput"
          @save="save"
        />

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

    <!-- Fehlendes Front Matter beim Öffnen ergänzen (Content-Dateien). -->
    <FrontMatterDialog v-model="fmDialog" :fields="fmFields" @apply="applyFrontMatter" />

    <!-- Beim Speichern fragen, ob lastmod aktualisiert werden soll (nur, solange
         [user] update_lastmod nicht gesetzt ist). -->
    <v-dialog
      :model-value="lastmodDialog"
      max-width="480"
      @update:model-value="(v) => { if (!v) resolveLastmod(false) }"
    >
      <v-card>
        <v-card-title class="text-h6">{{ $t('lastmod.title') }}</v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-3">{{ $t('lastmod.hint') }}</p>
          <v-checkbox
            v-model="lastmodRemember"
            :label="$t('lastmod.remember')"
            density="compact"
            hide-details
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="resolveLastmod(false)">{{ $t('lastmod.skip') }}</v-btn>
          <v-btn color="primary" variant="flat" @click="resolveLastmod(true)">{{ $t('lastmod.update') }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
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

/* Zwei Zeilen im Titel der Werkzeugleiste: Dateiname, darunter der Speicherort.
   Die Zeilenhöhen sind eng gesetzt, damit beides in die Leiste passt; lange
   Pfade werden abgeschnitten (der volle Pfad steht im title-Attribut). */
.editor-name {
  /* Kleiner als die 1.25rem, die v-toolbar-title vorgibt: Der Titel besteht
     jetzt aus zwei Zeilen und braucht weniger Gewicht auf der ersten. */
  font-size: 1.05rem;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
}
.editor-path {
  font-size: 0.9rem;
  line-height: 1.3;
  opacity: 0.75;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Indikator für ungespeicherte Änderungen: deutlich größer als der Titel, damit
   er nicht übersehen wird. */
.dirty-dot {
  font-size: 2.4em;
  line-height: 0;
  vertical-align: middle;
  margin-left: 2px;
}
</style>
