<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useAssistantStore } from '../stores/assistant'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import { lineDiff } from '../util/lineDiff'
import { flushEditor } from '../util/editorBridge'

const { t, locale } = useI18n()
const auth = useAuthStore()
const assistant = useAssistantStore()
const files = useFilesStore()

const input = ref('')
const scroller = ref(null)

// Auf schmalen Schirmen (< 960 px, smAndDown — dieselbe Schwelle wie das übrige
// responsive Layout) den Assistenten über die volle Bildschirmbreite zeigen;
// darüber die feste Breite. Die echte Zahl an Vuetify geben, damit die
// Ein-/Ausblend-Animation stimmt.
const display = useDisplay()
const drawerWidth = computed(() => (display.smAndDown.value ? display.width.value : 440))

// Höhe des Drawers an die TATSÄCHLICH sichtbare Fläche binden. Auf echten
// Smartphones überschätzen 100vh/100dvh den sichtbaren Bereich: Die dynamische
// Adressleiste und vor allem die eingeblendete Bildschirmtastatur verkleinern
// nur den "visual viewport", nicht aber vh/dvh. Dadurch rutscht das unten
// angedockte Eingabefeld aus dem Bild und man muss scrollen. Die VisualViewport-
// API liefert die echte Höhe und reagiert auf beide Effekte.
const viewportHeight = ref(window.visualViewport?.height ?? window.innerHeight)

function updateViewportHeight() {
  viewportHeight.value = window.visualViewport?.height ?? window.innerHeight
}

onMounted(() => {
  updateViewportHeight()
  const vv = window.visualViewport
  if (vv) {
    vv.addEventListener('resize', updateViewportHeight)
    vv.addEventListener('scroll', updateViewportHeight)
  }
  window.addEventListener('resize', updateViewportHeight)
})

onBeforeUnmount(() => {
  const vv = window.visualViewport
  if (vv) {
    vv.removeEventListener('resize', updateViewportHeight)
    vv.removeEventListener('scroll', updateViewportHeight)
  }
  window.removeEventListener('resize', updateViewportHeight)
})

const writeModeLabel = computed(() => {
  const m = auth.ai?.writeMode ?? 'confirm'
  return t(`assistant.mode.${m}`)
})

// Inline-Diff für eine ausstehende write_file-Aktion auf einer BESTEHENDEN
// Datei. null bei neuer Datei oder zu großem Inhalt → Panel zeigt dann die
// einfache Inhalts-Vorschau.
const diff = computed(() => {
  const p = assistant.pending
  if (!p || p.tool !== 'write_file' || p.oldContent == null) return null
  return lineDiff(p.oldContent, p.input?.content ?? '')
})

// Tool-Notiz lesbar machen (Werkzeugname → übersetzter Text mit Pfad).
function toolText(b) {
  const key = `assistant.tool.${b.tool}`
  const label = t(key, [b.path])
  return label === key ? `${b.tool} ${b.path}` : label
}

// Wenn der Assistent die im Editor geöffnete Datei geändert hat, deren Inhalt
// neu laden, damit das Ergebnis sofort im Editor erscheint.
function norm(p) {
  return String(p ?? '').replace(/^\/+|\/+$/g, '')
}
async function reloadIfOpenChanged() {
  const open = files.openFile
  if (open?.path && assistant.actions.some((a) => norm(a.path) === norm(open.path))) {
    await files.reloadOpenFile()
  }
}

// Kontext aus Editor (offene Datei) und Dateimanager (angezeigtes Verzeichnis).
function context() {
  return {
    openFilePath: files.openFile?.path ?? null,
    openDirPath: files.cwd?.path ?? null,
  }
}

async function submit() {
  const text = input.value.trim()
  if (!text || assistant.busy) return
  // Ungespeicherte Editor-Änderungen sichern, damit der Assistent den aktuellen
  // Stand sieht (analog zum Veröffentlichen-Knopf). Bei Speicherfehler abbrechen.
  if (files.dirty && files.openFile) {
    const saved = await flushEditor()
    if (!saved) return
  }
  input.value = ''
  const ok = await assistant.send(text, locale.value, context())
  if (!ok) {
    input.value = text // bei Fehler zurück ins Feld
    return
  }
  await reloadIfOpenChanged()
}

async function resolve(decision) {
  await assistant.resolve(decision, locale.value, context())
  if (decision === 'allow') {
    files.refresh?.() // Ordneransicht könnte veraltet sein
    await reloadIfOpenChanged()
  }
}

// Beim Eintreffen neuer Nachrichten ans Ende scrollen.
watch(
  () => [assistant.bubbles.length, assistant.busy, assistant.pending],
  () => nextTick(() => {
    const el = scroller.value
    if (el) el.scrollTop = el.scrollHeight
  }),
  { deep: true },
)
</script>

<template>
  <v-navigation-drawer
    :model-value="assistant.open"
    location="right"
    temporary
    :width="drawerWidth"
    class="assistant-drawer"
    :style="{ '--assistant-vh': viewportHeight + 'px' }"
    @update:model-value="assistant.open = $event"
  >
    <div class="d-flex flex-column assistant-body">
      <!-- Kopf -->
      <div class="d-flex align-center px-3 py-2 border-b">
        <v-icon icon="mdi-robot-happy-outline" class="mr-2" />
        <span class="text-subtitle-1">{{ $t('assistant.title') }}</span>
        <v-chip size="x-small" class="ml-2" variant="tonal">{{ writeModeLabel }}</v-chip>
        <v-spacer />
        <v-tooltip :text="$t('assistant.clear')" location="bottom">
          <template #activator="{ props }">
            <v-btn v-bind="props" icon="mdi-broom" variant="text" size="small" :disabled="assistant.busy || !assistant.history.length" @click="assistant.reset()" />
          </template>
        </v-tooltip>
        <v-btn icon="mdi-close" variant="text" size="small" @click="assistant.open = false" />
      </div>

      <!-- Verlauf -->
      <div ref="scroller" class="flex-grow-1 pa-3 assistant-scroll">
        <div v-if="!assistant.bubbles.length" class="text-medium-emphasis text-body-2">
          {{ $t('assistant.empty') }}
        </div>

        <template v-for="(b, i) in assistant.bubbles" :key="i">
          <div v-if="b.kind === 'user'" class="d-flex justify-end mb-2">
            <div class="assistant-bubble assistant-bubble--user">{{ b.text }}</div>
          </div>
          <div v-else-if="b.kind === 'assistant'" class="d-flex mb-2">
            <div class="assistant-bubble assistant-bubble--bot">{{ b.text }}</div>
          </div>
          <div v-else class="text-caption text-medium-emphasis mb-1 d-flex align-center">
            <v-icon icon="mdi-wrench-outline" size="x-small" class="mr-1" />{{ toolText(b) }}
          </div>
        </template>

        <!-- Ausstehende Änderung (confirm-Modus) -->
        <v-card v-if="assistant.pending" variant="outlined" class="my-2 pa-0" color="warning">
          <v-card-title class="text-subtitle-2 d-flex align-center">
            <v-icon icon="mdi-content-save-edit-outline" size="small" class="mr-2" />
            {{ $t('assistant.pendingTitle') }}
          </v-card-title>
          <v-card-text class="py-2">
            <template v-if="assistant.pending.tool === 'write_file'">
              <div class="text-body-2 mb-1">
                {{ assistant.pending.oldContent === null ? $t('assistant.diffNewFile', [assistant.pending.input.path]) : $t('assistant.diffOverwrite', [assistant.pending.input.path]) }}
              </div>
              <!-- Überschreiben: zeilenweiser Diff (alt rot, neu grün, Kontext grau). -->
              <div v-if="diff" class="assistant-diff">
                <div
                  v-for="(l, k) in diff"
                  :key="k"
                  class="assistant-diff__line"
                  :class="`assistant-diff__line--${l.t}`"
                ><span class="assistant-diff__sign">{{ l.t === 'add' ? '+' : l.t === 'del' ? '-' : ' ' }}</span>{{ l.text }}</div>
              </div>
              <!-- Neue Datei oder zu großer Diff: einfache Inhalts-Vorschau. -->
              <pre v-else class="assistant-preview">{{ assistant.pending.input.content }}</pre>
            </template>
            <div v-else-if="assistant.pending.tool === 'create_dir'" class="text-body-2">
              {{ $t('assistant.pendingDir', [assistant.pending.input.path]) }}
            </div>
            <div v-else-if="assistant.pending.tool === 'rename'" class="text-body-2">
              {{ $t('assistant.pendingRename', [assistant.pending.input.path, assistant.pending.input.new_name]) }}
            </div>
            <div v-else-if="assistant.pending.tool === 'delete'" class="text-body-2">
              {{ $t('assistant.pendingDelete', [assistant.pending.input.path]) }}
            </div>
            <div v-else-if="assistant.pending.tool === 'move'" class="text-body-2">
              {{ $t('assistant.pendingMove', [assistant.pending.input.path, assistant.pending.input.dest_dir]) }}
            </div>
          </v-card-text>
          <v-card-actions>
            <v-spacer />
            <v-btn variant="text" size="small" :disabled="assistant.busy" @click="resolve('reject')">{{ $t('assistant.reject') }}</v-btn>
            <v-btn color="primary" variant="flat" size="small" :loading="assistant.busy" @click="resolve('allow')">{{ $t('assistant.approve') }}</v-btn>
          </v-card-actions>
        </v-card>

        <div v-if="assistant.busy" class="d-flex align-center text-caption text-medium-emphasis my-2">
          <v-progress-circular indeterminate size="16" width="2" class="mr-2" />{{ $t('assistant.thinking') }}
        </div>
        <v-alert v-if="assistant.error" type="error" density="compact" class="my-2">{{ errorText(t, assistant.error) }}</v-alert>
      </div>

      <!-- Eingabe -->
      <div class="pa-2 border-t">
        <v-textarea
          v-model="input"
          :placeholder="$t('assistant.placeholder')"
          rows="2"
          max-rows="6"
          auto-grow
          variant="outlined"
          density="compact"
          hide-details
          :disabled="assistant.busy"
          @keydown.enter.exact.prevent="submit"
        >
          <template #append-inner>
            <v-btn icon="mdi-send" variant="text" size="small" :disabled="assistant.busy || !input.trim()" @click="submit" />
          </template>
        </v-textarea>
      </div>
    </div>
  </v-navigation-drawer>
</template>

<style scoped>
/* Drawer-Höhe an die per VisualViewport gemessene, tatsächlich sichtbare Höhe
   binden (--assistant-vh, vom Script gesetzt). Das berücksichtigt auf echten
   Geräten die dynamische Adressleiste UND die Bildschirmtastatur — anders als
   100vh/100dvh. Fallback 100dvh, falls die Variable (noch) fehlt. Auf dem
   Desktop entspricht der Wert der Fensterhöhe, also unverändert. */
.assistant-drawer {
  height: var(--assistant-vh, 100dvh) !important;
  max-height: var(--assistant-vh, 100dvh) !important;
}
.assistant-body {
  height: 100%;
}
/* Entscheidend: Ein scrollendes Flex-Element braucht min-height: 0, sonst kann
   es nicht unter seine Inhaltshöhe schrumpfen und drückt — bei vielen
   Nachrichten — das darunter liegende Eingabefeld aus dem sichtbaren Bereich.
   Genau das ließ sich auf kürzeren Displays nur durch Scrollen erreichen. */
.assistant-scroll {
  min-height: 0;
  overflow-y: auto;
}

.assistant-bubble {
  max-width: 85%;
  padding: 6px 10px;
  border-radius: 10px;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.9rem;
  line-height: 1.4;
}
.assistant-bubble--user {
  background: var(--mint-primary, #3c8527);
  color: #fff;
}
.assistant-bubble--bot {
  background: var(--mint-hover, rgba(0, 0, 0, 0.06));
}
.assistant-preview {
  max-height: 240px;
  overflow: auto;
  background: rgba(0, 0, 0, 0.04);
  padding: 6px 8px;
  border-radius: 6px;
  font-size: 0.8rem;
  white-space: pre-wrap;
  word-break: break-word;
}
.assistant-diff {
  max-height: 280px;
  overflow: auto;
  border-radius: 6px;
  background: rgba(0, 0, 0, 0.03);
  padding: 4px 0;
  font-family: monospace;
  font-size: 0.78rem;
  line-height: 1.45;
}
.assistant-diff__line {
  padding: 0 8px;
  white-space: pre-wrap;
  word-break: break-word;
}
.assistant-diff__sign {
  display: inline-block;
  width: 1ch;
  margin-right: 4px;
  opacity: 0.55;
  user-select: none;
}
.assistant-diff__line--add {
  background: rgba(60, 133, 39, 0.16);
}
.assistant-diff__line--del {
  background: rgba(192, 57, 43, 0.14);
  text-decoration: line-through;
  text-decoration-color: rgba(192, 57, 43, 0.5);
}
.assistant-diff__line--ctx {
  color: var(--mint-text-muted, #666);
}
</style>
