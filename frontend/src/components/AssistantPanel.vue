<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useAssistantStore } from '../stores/assistant'
import { useFilesStore } from '../stores/files'
import ProGate from './ProGate.vue'
import { errorText } from '../i18n/apiMessage'
import { lineDiff } from '../util/lineDiff'
import { flushEditor } from '../util/editorBridge'
import { useVoiceInput } from '../util/voiceInput'
import { AI_MODELS } from '../util/aiModels'

const { t, locale } = useI18n()
const auth = useAuthStore()
const assistant = useAssistantStore()
const files = useFilesStore()

// Modell und Schreibmodus dieser Sitzung. `null` im Store heißt: im Panel wurde
// NICHTS gewählt — dann gilt der konfigurierte Standard (auth.ai), und zwar
// jeweils der aktuelle. Den Store-Wert beim ersten Rendern vorzubelegen wäre
// falsch: Das fröre den damaligen Standard ein, und eine spätere Änderung in
// der Konfiguration käme nie beim Assistenten an, weil der Client den
// Store-Wert bei jedem Zug als Übersteuerung mitschickt.
const activeModel = computed(() => assistant.model ?? (auth.ai?.model || AI_MODELS[0]))
const activeWriteMode = computed(() => assistant.writeMode ?? (auth.ai?.writeMode || 'confirm'))

const writeModes = ['readonly', 'confirm', 'auto']

// Auswählbare Modelle: die in der hugocms.ini hinterlegte Liste ([ai] models),
// sonst die mitgelieferte, plus ein evtl. abweichend konfiguriertes Modell
// (damit es nicht aus der Auswahl fällt).
const modelItems = computed(() => {
  const list = auth.ai?.models?.length ? [...auth.ai.models] : [...AI_MODELS]
  for (const m of [auth.ai?.model, activeModel.value]) {
    if (m && !list.includes(m)) list.unshift(m)
  }
  return list
})

const input = ref('')
const scroller = ref(null)

// Spracheingabe (Pro-Feature). Der Button erscheint nur, wenn der Dienst
// freigeschaltet ist (auth.speech) UND der Browser Aufnahme unterstützt.
const { supported: voiceSupported, recording: voiceRecording, start: startVoice, stop: stopVoice } = useVoiceInput()
const transcribing = ref(false)
// Hinweisdialog, wenn die Spracheingabe nicht freigeschaltet ist.
const voiceGateOpen = ref(false)
const emit = defineEmits(['activate-license'])

// Sprachaufnahme umschalten: erster Klick startet, zweiter beendet und
// transkribiert. Der erkannte Text wird an die aktuelle Eingabe angehängt,
// damit der Nutzer ihn vor dem Senden noch prüfen und ändern kann.
async function toggleVoice() {
  if (assistant.busy || transcribing.value) return
  if (voiceRecording.value) {
    const blob = await stopVoice()
    if (!blob) return
    transcribing.value = true
    try {
      const text = (await assistant.transcribe(blob, locale.value)).trim()
      if (text) {
        input.value = input.value.trim() ? `${input.value.trimEnd()} ${text}` : text
      } else {
        // Antwort ohne Text (z. B. nichts erkannt) — nicht still übergehen.
        assistant.noteError({ code: 'ESPEECH', key: 'SPEECH-NO-RESULT' })
      }
    } catch (e) {
      assistant.noteError(e)
    } finally {
      transcribing.value = false
    }
  } else {
    try {
      await startVoice()
    } catch {
      // Mikrofon-Zugriff abgelehnt oder kein Gerät.
      assistant.noteError({ code: 'ESPEECH', key: 'SPEECH-MIC-DENIED' })
    }
  }
}

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

const writeModeLabel = computed(() => t(`assistant.mode.${activeWriteMode.value}`))

// Nutzungslimit-Fehler des KI-Kontos: blendet zusätzlich den Console-Link ein.
function isUsageLimitError(err) {
  return ['AI-USAGE-LIMIT', 'AI-USAGE-LIMIT-UNKNOWN'].includes(err?.key)
}

// Künftiger Inhalt einer ausstehenden Schreibaktion: bei write_file steht er in
// der Werkzeugeingabe, bei replace_in_file rechnet ihn der Server aus (die
// Eingabe trägt dort nur den ausgetauschten Abschnitt).
const pendingContent = computed(() => {
  const p = assistant.pending
  if (!p) return null
  if (p.tool === 'write_file') return p.input?.content ?? ''
  if (p.tool === 'replace_in_file') return p.newContent ?? null
  return null
})

// Inline-Diff für eine ausstehende Schreibaktion auf einer BESTEHENDEN Datei.
// null bei neuer Datei oder zu großem Inhalt → Panel zeigt dann die einfache
// Inhalts-Vorschau.
const diff = computed(() => {
  const p = assistant.pending
  if (!p || p.oldContent == null || pendingContent.value == null) return null
  return lineDiff(p.oldContent, pendingContent.value)
})

// Hat der letzte Zug einen Entwurf zur Freigabe erzeugt (Modus auto oder die
// Antwort „Später veröffentlichen")? Dann bleibt die Live-Datei unverändert —
// das sagt der Hinweis, damit niemand die Änderung auf der Webseite sucht.
const stashedDraft = computed(() => assistant.actions.some((a) => a.draft))

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

// „Nicht mehr fragen": bestätigt die anstehende Änderung und lässt den Rest des
// Auftrags ohne weitere Rückfragen durchlaufen. Danach gilt wieder der
// eingestellte Schreibmodus.
async function resolveRest() {
  await assistant.allowRest(locale.value, context())
  files.refresh?.()
  await reloadIfOpenChanged()
}

async function resolve(decision, publishDate = '') {
  await assistant.resolve(decision, locale.value, context(), publishDate)
  if (decision === 'allow') {
    files.refresh?.() // Ordneransicht könnte veraltet sein
    await reloadIfOpenChanged()
  }
  // Nach „draft" bleibt die Live-Datei unverändert — Editor und Ordneransicht
  // zeigen weiterhin den richtigen Stand.
}

// --- Termin für einen Entwurf ---------------------------------------------
// Wie in der Freigabe-Warteschlange: Datum pflicht, Uhrzeit optional (leer =
// 00:00 Uhr lokal). Der Entwurf entsteht damit gleich terminiert; die
// veröffentlichte Fassung bleibt bis zum Zeitpunkt unverändert online.
const scheduleDialog = ref(false)
const publishDay = ref('')
const publishTime = ref('')

const publishAtLocal = computed(() =>
  publishDay.value ? `${publishDay.value}T${publishTime.value || '00:00'}` : '',
)

// Lokale Eingabe in einen zonenbehafteten ISO-Zeitstempel wandeln.
function toIso(local) {
  if (!local) return ''
  const d = new Date(local)
  return Number.isNaN(d.getTime()) ? '' : d.toISOString()
}

// Nur ein künftiger Termin hält die Veröffentlichung zurück — ein vergangener
// bedeutet serverseitig „ohne Termin ablegen".
const scheduledInFuture = computed(() => {
  const t = new Date(publishAtLocal.value).getTime()
  return !Number.isNaN(t) && t > Date.now()
})

function openScheduleDialog() {
  publishDay.value = ''
  publishTime.value = ''
  scheduleDialog.value = true
}

async function resolveScheduled() {
  if (!scheduledInFuture.value) return
  scheduleDialog.value = false
  await resolve('draft', toIso(publishAtLocal.value))
}

// Nach einem an der Schrittgrenze abgebrochenen Zug fortsetzen.
async function continueRun() {
  if (assistant.busy) return
  const ok = await assistant.send(t('assistant.continueNudge'), locale.value, context())
  if (ok) await reloadIfOpenChanged()
}

// Bereitschaftsprüfung beim ersten Öffnen des Panels in einer Sitzung: prüft
// ohne Token-Verbrauch, ob die Claude-API erreichbar ist, und zeigt „Ich bin
// bereit" bzw. den Fehler. Der Merker `readyChecked` verhindert eine erneute
// Prüfung bei jedem weiteren Öffnen (nur einmal pro Sitzung).
watch(
  () => assistant.open,
  (open) => {
    if (open && !assistant.readyChecked && !assistant.checking && !assistant.busy) {
      assistant.checkReady()
    }
  },
  { immediate: true },
)

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
        <v-icon icon="mdi-creation" class="mr-2" />
        <span class="text-subtitle-1">{{ $t('assistant.title') }}</span>
        <v-spacer />
        <v-tooltip :text="$t('assistant.clear')" location="bottom">
          <template #activator="{ props }">
            <v-btn v-bind="props" icon="mdi-broom" variant="text" size="small" :disabled="assistant.busy || !assistant.bubbles.length" @click="assistant.clearConversation()" />
          </template>
        </v-tooltip>
        <v-btn icon="mdi-close" variant="text" size="small" @click="assistant.open = false" />
      </div>

      <!-- Modell und Schreibmodus (nur diese Sitzung; ändert die Konfiguration
           nicht). Während ein Zug läuft, gesperrt. -->
      <div class="d-flex align-center px-3 py-1 border-b assistant-config">
        <v-menu location="bottom start">
          <template #activator="{ props }">
            <v-chip
              v-bind="props"
              size="small"
              variant="tonal"
              :disabled="assistant.busy"
              :title="$t('assistant.model')"
            >
              <v-icon icon="mdi-brain" size="x-small" start />
              {{ activeModel }}
              <v-icon icon="mdi-menu-down" size="x-small" end />
            </v-chip>
          </template>
          <v-list density="compact" min-width="180">
            <!-- Zurück zum konfigurierten Standard: ohne diesen Eintrag bliebe
                 eine einmal getroffene Sitzungsauswahl bis zum Neuladen
                 bestehen — auch wenn die Konfiguration inzwischen ein anderes
                 Modell nennt. -->
            <v-list-item
              :active="assistant.model === null"
              @click="assistant.model = null"
            >
              <v-list-item-title>{{ $t('assistant.useConfigured') }}</v-list-item-title>
              <v-list-item-subtitle>{{ auth.ai?.model || AI_MODELS[0] }}</v-list-item-subtitle>
            </v-list-item>
            <v-divider />
            <v-list-item
              v-for="m in modelItems"
              :key="m"
              :active="assistant.model === m"
              @click="assistant.model = m"
            >
              <v-list-item-title>{{ m }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>

        <v-menu location="bottom start">
          <template #activator="{ props }">
            <v-chip
              v-bind="props"
              size="small"
              variant="tonal"
              class="ml-2"
              :disabled="assistant.busy"
              :title="$t('assistant.writeMode')"
            >
              <v-icon icon="mdi-shield-edit-outline" size="x-small" start />
              {{ writeModeLabel }}
              <v-icon icon="mdi-menu-down" size="x-small" end />
            </v-chip>
          </template>
          <v-list density="compact" min-width="180">
            <v-list-item
              :active="assistant.writeMode === null"
              @click="assistant.writeMode = null"
            >
              <v-list-item-title>{{ $t('assistant.useConfigured') }}</v-list-item-title>
              <v-list-item-subtitle>{{ $t('assistant.mode.' + (auth.ai?.writeMode || 'confirm')) }}</v-list-item-subtitle>
            </v-list-item>
            <v-divider />
            <v-list-item
              v-for="m in writeModes"
              :key="m"
              :active="assistant.writeMode === m"
              @click="assistant.writeMode = m"
            >
              <v-list-item-title>{{ $t('assistant.mode.' + m) }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>
      </div>

      <!-- Verlauf -->
      <div ref="scroller" class="flex-grow-1 pa-3 assistant-scroll">
        <!-- Bereitschaftsprüfung: solange kein Gespräch läuft. -->
        <div v-if="!assistant.history.length && assistant.checking" class="d-flex align-center text-caption text-medium-emphasis my-2">
          <v-progress-circular indeterminate size="16" width="2" class="mr-2" />{{ $t('assistant.checking') }}
        </div>
        <div v-else-if="!assistant.history.length && assistant.ready" class="d-flex align-center text-body-2 text-medium-emphasis my-2">
          <v-icon icon="mdi-check-circle-outline" size="small" color="success" class="mr-2" />{{ $t('assistant.ready') }}
        </div>

        <template v-for="(b, i) in assistant.bubbles" :key="i">
          <div v-if="b.kind === 'user'" class="d-flex justify-end mb-2">
            <div class="assistant-bubble assistant-bubble--user">{{ b.text }}</div>
          </div>
          <div v-else-if="b.kind === 'assistant'" class="d-flex mb-2">
            <div class="assistant-bubble assistant-bubble--bot">{{ b.text }}</div>
          </div>
          <!-- Gescheiterter Zug: bleibt als Teil des Verlaufs stehen, damit
               nachvollziehbar ist, warum an dieser Stelle nichts geschah. -->
          <v-alert v-else-if="b.kind === 'error'" type="error" density="compact" variant="tonal" class="my-2">
            {{ errorText(t, b.error) }}
            <!-- Mehrere gleiche Fehlversuche hintereinander: gezählt statt
                 gestapelt, damit auch der zweite Versuch sichtbar bleibt. -->
            <div v-if="b.count > 1" class="text-caption mt-1">{{ $t('assistant.errorRepeat', [b.count]) }}</div>
            <div v-if="isUsageLimitError(b.error)" class="mt-1">
              <a href="https://platform.claude.com/settings/limits" target="_blank" rel="noopener noreferrer">{{ $t('assistant.openLimits') }}</a>
            </div>
          </v-alert>
          <div v-else class="text-caption text-medium-emphasis mb-1 d-flex align-center">
            <v-icon icon="mdi-wrench-outline" size="x-small" class="mr-1" />{{ toolText(b) }}
          </div>
        </template>

        <!-- Ausstehende Änderung (confirm-Modus). Der Warnton sitzt auf Rahmen
             und Überschrift, NICHT auf der Karte: Ein color="warning" färbt den
             gesamten Inhalt ein und lässt die Schaltflächen darin blass wirken. -->
        <v-card v-if="assistant.pending" variant="outlined" class="my-2 pa-0 assistant-pending">
          <v-card-title class="text-subtitle-2 text-warning d-flex align-center">
            <v-icon icon="mdi-content-save-edit-outline" size="small" class="mr-2" />
            {{ $t('assistant.pendingTitle') }}
          </v-card-title>
          <v-card-text class="py-2">
            <template v-if="assistant.pending.tool === 'write_file' || assistant.pending.tool === 'replace_in_file'">
              <div class="text-body-2 mb-1">
                <template v-if="assistant.pending.tool === 'replace_in_file'">
                  {{ $t('assistant.diffReplace', [assistant.pending.input.path]) }}
                </template>
                <template v-else>
                  {{ assistant.pending.oldContent === null ? $t('assistant.diffNewFile', [assistant.pending.input.path]) : $t('assistant.diffOverwrite', [assistant.pending.input.path]) }}
                </template>
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
              <!-- Neue Datei, zu großer Diff oder ein Abschnitt, der sich nicht
                   eindeutig zuordnen ließ: einfache Inhalts-Vorschau. -->
              <pre v-else class="assistant-preview">{{ pendingContent ?? assistant.pending.input.new_text ?? assistant.pending.input.content }}</pre>
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
          <!-- Zwei Zeilen statt einer: Vier Schaltflächen passen in der Breite
               des Panels (440 px) nicht nebeneinander. Oben die Entwurfswege,
               unten die Entscheidung über die Live-Datei. -->
          <v-card-actions class="flex-column align-stretch ga-2 pt-0">
            <!-- Ja mit Aufschub: Der Vorschlag geht als Entwurf in die
                 Freigabe-Warteschlange, die Live-Datei bleibt unverändert — auf
                 Wunsch gleich mit Termin. Nur beim Schreiben einer Datei und nur
                 mit Hugo-Projekt (canDraft vom Server). -->
            <div v-if="assistant.pending.canDraft" class="d-flex flex-wrap ga-2">
              <v-btn
                variant="tonal"
                size="small"
                class="flex-grow-1"
                prepend-icon="mdi-clock-outline"
                :disabled="assistant.busy"
                :title="$t('assistant.approveLaterHint')"
                @click="resolve('draft')"
              >
                {{ $t('assistant.approveLater') }}
              </v-btn>
              <v-btn
                variant="tonal"
                size="small"
                class="flex-grow-1"
                prepend-icon="mdi-calendar-clock"
                :disabled="assistant.busy"
                :title="$t('assistant.scheduleHint')"
                @click="openScheduleDialog"
              >
                {{ $t('assistant.schedule') }}
              </v-btn>
            </div>
            <div class="d-flex flex-wrap ga-2 justify-end">
              <!-- Bestätigen UND für den Rest dieses Auftrags nicht mehr fragen.
                   Links abgesetzt, damit die gewohnte Entscheidung rechts
                   unverändert an ihrem Platz bleibt. -->
              <v-btn
                variant="text"
                size="small"
                class="mr-auto"
                prepend-icon="mdi-flash-outline"
                :disabled="assistant.busy"
                :title="$t('assistant.approveRestHint')"
                @click="resolveRest"
              >
                {{ $t('assistant.approveRest') }}
              </v-btn>
              <v-btn variant="text" size="small" :disabled="assistant.busy" @click="resolve('reject')">{{ $t('assistant.reject') }}</v-btn>
              <v-btn color="primary" variant="flat" size="small" :loading="assistant.busy" @click="resolve('allow')">{{ $t('assistant.approve') }}</v-btn>
            </div>
          </v-card-actions>
        </v-card>

        <!-- Ergebnis liegt in der Freigabe-Warteschlange, nicht in der Datei. -->
        <v-alert
          v-if="stashedDraft && !assistant.busy && !assistant.pending"
          type="info"
          density="compact"
          variant="tonal"
          class="my-2"
        >
          {{ $t('assistant.draftStashed') }}
        </v-alert>

        <!-- Zug an der Schrittgrenze abgebrochen: Fortsetzen anbieten. -->
        <div v-if="assistant.aborted && !assistant.busy && !assistant.pending" class="my-2">
          <v-btn color="primary" variant="tonal" size="small" prepend-icon="mdi-play" @click="continueRun">
            {{ $t('assistant.continueAction') }}
          </v-btn>
        </div>

        <div v-if="assistant.busy" class="d-flex align-center text-caption text-medium-emphasis my-2">
          <v-progress-circular indeterminate size="16" width="2" class="mr-2" />{{ $t('assistant.thinking') }}
        </div>
      </div>

      <!-- Vorab erteilte Bestätigung: bleibt sichtbar, solange sie gilt — und
           zwar außerhalb des scrollenden Verlaufs. Eine abgeschaltete
           Rückfrage darf man nicht wegscrollen können. -->
      <div v-if="assistant.autoConfirm" class="assistant-autoconfirm d-flex align-center px-3 py-1">
        <v-icon icon="mdi-flash-outline" size="x-small" class="mr-2" />
        <span class="text-caption">{{ $t('assistant.autoConfirmActive') }}</span>
        <v-btn
          variant="text"
          size="x-small"
          class="ml-auto"
          :disabled="assistant.busy"
          @click="assistant.releaseAutoConfirm(true)"
        >
          {{ $t('assistant.autoConfirmStop') }}
        </v-btn>
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
            <!-- Der Knopf bleibt auch ohne Freischaltung sichtbar (sofern der
                 Browser aufnehmen kann): Ein Klick erklärt dann, was die
                 Spracheingabe leistet, statt sie stillschweigend zu verbergen. -->
            <v-btn
              v-if="voiceSupported"
              :icon="voiceRecording ? 'mdi-stop' : 'mdi-microphone'"
              :color="voiceRecording ? 'error' : undefined"
              variant="text"
              size="small"
              :class="{ 'assistant-locked': !auth.speech }"
              :loading="transcribing"
              :disabled="assistant.busy"
              :title="!auth.speech
                ? $t('pro.feature.speech.title')
                : (voiceRecording ? $t('assistant.voiceStop') : $t('assistant.voiceRecord'))"
              @click="auth.speech ? toggleVoice() : (voiceGateOpen = true)"
            />
            <v-btn icon="mdi-send" variant="text" size="small" :disabled="assistant.busy || !input.trim()" @click="submit" />
          </template>
        </v-textarea>
      </div>
    </div>
  </v-navigation-drawer>

  <!-- Termin für den Entwurf: derselbe Aufbau wie in der Freigabe-Warteschlange
       (Datum pflicht, Uhrzeit optional). Bestätigt wird damit zugleich die
       Änderung — der Entwurf entsteht terminiert. -->
  <v-dialog v-model="scheduleDialog" max-width="480">
    <v-card>
      <v-card-title class="text-subtitle-1">{{ $t('assistant.scheduleTitle') }}</v-card-title>
      <v-card-text>
        <p class="text-body-2 mb-3">{{ $t('assistant.scheduleIntro') }}</p>
        <div class="d-flex ga-3">
          <v-text-field
            v-model="publishDay"
            type="date"
            :label="$t('review.pickDate')"
            density="comfortable"
            hide-details
            variant="outlined"
          />
          <v-text-field
            v-model="publishTime"
            type="time"
            :label="$t('review.pickTime')"
            density="comfortable"
            hide-details
            variant="outlined"
            style="max-width: 150px"
          />
        </div>
        <p class="text-caption text-medium-emphasis mt-1">{{ $t('review.timeOptionalHint') }}</p>
        <v-alert v-if="scheduledInFuture" type="info" density="compact" variant="tonal" class="mt-3">
          {{ $t('assistant.scheduleLiveInfo') }}
        </v-alert>
        <p v-else-if="publishDay" class="text-caption text-medium-emphasis mt-2">
          {{ $t('assistant.schedulePastHint') }}
        </p>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="assistant.busy" @click="scheduleDialog = false">
          {{ $t('common.cancel') }}
        </v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!scheduledInFuture"
          :loading="assistant.busy"
          @click="resolveScheduled"
        >
          {{ $t('assistant.scheduleConfirm') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Spracheingabe nicht freigeschaltet: Hinweis als kleiner Dialog — im
       schmalen Eingabebereich wäre für einen eingebetteten Kasten kein Platz. -->
  <v-dialog v-model="voiceGateOpen" width="560">
    <v-card class="pa-2">
      <v-card-text>
        <ProGate feature="speech" dense @activate="voiceGateOpen = false; emit('activate-license')" />
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="voiceGateOpen = false">{{ $t('common.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/* Band der vorab erteilten Bestätigung: warnfarben, aber ruhig — es meldet
   keinen Fehler, sondern einen Zustand, den man im Blick behalten soll. */
.assistant-autoconfirm {
  border-top: 1px solid rgb(var(--v-theme-warning));
  background: rgba(var(--v-theme-warning), 0.08);
  color: rgb(var(--v-theme-warning));
}
/* Spracheingabe ohne Freischaltung: sichtbar, aber zurückgenommen. */
.assistant-locked { opacity: 0.5; }

/* Bestätigungsbox: Warnton nur am Rahmen. Die Karte selbst bleibt neutral,
   damit die Schaltflächen darin ihre eigene Farbe behalten. */
.assistant-pending {
  border-color: rgb(var(--v-theme-warning)) !important;
}

/* Drawer-Höhe an die per VisualViewport gemessene, tatsächlich sichtbare Höhe
   binden (--assistant-vh, vom Script gesetzt). Das berücksichtigt auf echten
   Geräten die dynamische Adressleiste UND die Bildschirmtastatur — anders als
   100vh/100dvh. Fallback 100dvh, falls die Variable (noch) fehlt. Auf dem
   Desktop entspricht der Wert der Fensterhöhe, also unverändert. */
.assistant-drawer {
  height: var(--assistant-vh, 100dvh) !important;
  max-height: var(--assistant-vh, 100dvh) !important;
}
/* Auf schmalen Schirmen volle Breite PER CSS erzwingen. Vuetify setzt die per
   JS gemessene Pixelbreite als Inline-Style; durch Sub-Pixel-/Rundungs-
   differenzen blieb auf manchen Geräten (z. B. 360 px) ein schmaler Streifen
   am Rand. width:100% bindet exakt an die Viewport-Breite — geräteunabhängig. */
@media (max-width: 959.98px) {
  .assistant-drawer {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    /* Innenkanten-Rand entfernen: Bei voller Breite überflüssig und würde als
       1 px über die Breite hinausragen. */
    border: none !important;
  }
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
