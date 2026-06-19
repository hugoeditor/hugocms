<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useAssistantStore } from '../stores/assistant'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import { lineDiff } from '../util/lineDiff'

const { t, locale } = useI18n()
const auth = useAuthStore()
const assistant = useAssistantStore()
const files = useFilesStore()

const input = ref('')
const scroller = ref(null)

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

async function submit() {
  const text = input.value.trim()
  if (!text || assistant.busy) return
  input.value = ''
  const ok = await assistant.send(text, locale.value)
  if (!ok) input.value = text // bei Fehler zurück ins Feld
}

async function resolve(decision) {
  await assistant.resolve(decision, locale.value)
  // Nach einer Änderung könnte der aktuelle Ordner veraltet sein — auffrischen.
  if (decision === 'allow') files.refresh?.()
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
    width="440"
    @update:model-value="assistant.open = $event"
  >
    <div class="d-flex flex-column" style="height: 100%">
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
      <div ref="scroller" class="flex-grow-1 pa-3" style="overflow-y: auto">
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
