<script setup>
// Freigabe-Warteschlange der gestaffelten Veröffentlichung: listet offene
// Entwürfe (KI im Modus auto, Cron oder manuell über den Entwurf-Button) und
// zeigt je Entwurf den zeilenweisen Diff gegen den aktuellen Live-Stand. Der
// Benutzer gibt frei — optional mit Veröffentlichungsdatum (publishDate) — oder
// verwirft. Erst die Freigabe schreibt die Live-Datei (draft:false); bis dahin
// bleibt die veröffentlichte Seite unangetastet.
//
// Als Overlay-Ansicht wie ContentQualityView/AuditView (nicht als v-dialog), mit
// zwei Zuständen: Liste und Diff-Detail (Zurück-Pfeil führt zur Liste).
import { computed, ref, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { useReviewStore } from '../stores/review'
import { useFilesStore } from '../stores/files'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import { api, apiUrl } from '../api/client'
import { lineDiff } from '../util/lineDiff'
import { useConfirm } from '../util/confirm'

const { t, locale } = useI18n()
const { smAndDown } = useDisplay()
const store = useReviewStore()
const files = useFilesStore()
const auth = useAuthStore()
const confirm = useConfirm()

// Wechsel zum Systemstatus (aus dem Build-Pausenhinweis). App.vue schließt
// dabei die Warteschlange und öffnet die Statusansicht.
const emit = defineEmits(['open-status'])
function openStatus() {
  emit('open-status')
}

// Zeilenweiser Diff Original → Vorschlag. Bei neuen Seiten (kein Original) und
// bei zu großen Eingaben fällt die Anzeige auf eine einfache Vorschau zurück.
const diff = computed(() => {
  const d = store.current
  if (!d) return null
  return lineDiff(d.original ?? '', d.proposedContent ?? '')
})

// Terminierte Freigabe läuft über einen Bestätigungsdialog. Datum ist Pflicht,
// die Uhrzeit optional — leer bedeutet 00:00 Uhr (Tagesbeginn, lokale Zone).
const publishDay = ref('') // YYYY-MM-DD
const publishTime = ref('') // HH:MM oder leer
const scheduleDialog = ref(false)

watch(
  () => store.current?.key,
  () => {
    publishDay.value = ''
    publishTime.value = ''
    scheduleDialog.value = false
  },
)

// Lokaler Zeitstempel (ohne Zone) aus Datum + Uhrzeit; leere Uhrzeit → 00:00.
const publishAtLocal = computed(() =>
  publishDay.value ? `${publishDay.value}T${publishTime.value || '00:00'}` : '',
)

// Lokale Zeit in einen zonenbehafteten ISO-Zeitstempel für Hugo/publishDate wandeln.
function toIso(local) {
  if (!local) return ''
  const d = new Date(local)
  return Number.isNaN(d.getTime()) ? '' : d.toISOString()
}

// Liegt der gewählte Termin in der Zukunft? Nur dann hält Hugo die Seite zurück;
// ein vergangener Termin veröffentlicht sofort.
const scheduledInFuture = computed(() => {
  const t = new Date(publishAtLocal.value).getTime()
  return !Number.isNaN(t) && t > Date.now()
})

// Sofort freigeben (ohne Termin) — schreibt live, setzt draft:false. Hat sich die
// Live-Datei seit dem Entwurf geändert (ECONFLICT), nachfragen und auf Wunsch mit
// force überschreiben.
async function approveNow() {
  if (!store.current) return
  const key = store.current.key
  const ok = await store.approve(key, '')
  if (ok || store.error?.code !== 'ECONFLICT') return

  store.error = null // die Rückfrage ersetzt die Fehlermeldung
  if (await confirm({
    title: t('review.conflictTitle'),
    message: t('review.conflictMessage'),
    confirmText: t('review.conflictConfirm'),
    color: 'warning',
  })) {
    await store.approve(key, '', true)
  }
}

// Terminiert freigeben — hinterlegt den Termin; die alte Fassung bleibt bis
// dahin online, ein Build tauscht die Datei zum Zeitpunkt (verzögerter Austausch).
async function approveScheduled() {
  if (!store.current || !publishDay.value) return
  const ok = await store.approve(store.current.key, toIso(publishAtLocal.value))
  if (ok) scheduleDialog.value = false
}

// Termin-Dialog öffnen und, falls bereits terminiert, mit dem geplanten
// Zeitpunkt vorbelegen (Umplanen).
function openScheduleDialog() {
  const iso = store.current?.publishAt
  if (iso) {
    const d = new Date(iso)
    if (!Number.isNaN(d.getTime())) {
      const p = (n) => String(n).padStart(2, '0')
      publishDay.value = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
      publishTime.value = `${p(d.getHours())}:${p(d.getMinutes())}`
    }
  }
  scheduleDialog.value = true
}

async function discard(key) {
  await store.discard(key)
}

const ORIGIN_ICON = { ai: 'mdi-creation', cron: 'mdi-clock-outline', user: 'mdi-account-outline' }

// Beschriftung der Entwurfs-Herkunft. Bei einem manuellen Entwurf steht der
// Name des Verfassers (Feld `author`). Entwürfe aus der Zeit vor diesem Feld
// führen ihn nicht — dann der angemeldete Benutzer (bei Einzelbenutzer-Betrieb
// dieselbe Person), sonst eine neutrale Umschreibung.
function originLabel(draft) {
  const name = draft?.author || auth.user?.name || t('review.origin.someone')
  return t('review.origin.' + (draft?.origin || 'user'), [name])
}

function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value)
}

// Zur Quelldatei springen (Live-Stand im Editor). Bei neuen Seiten fehlt sie ggf.
// Die Warteschlange wird dabei NICHT geschlossen: Der Editor legt sich nur
// darüber (höherer z-index), und beim Schließen erscheint der Diff wieder —
// sonst landete man nach jedem Blick in die Quelle im Dateimanager.
async function toSource() {
  const id = store.current?.fileId
  if (!id) return
  try {
    await files.openFileById(id)
  } catch {
    // Neue Seite: existiert live noch nicht — kein Sprungziel.
  }
}

// Vorschau des Entwurfs: zeigt den vorgeschlagenen Stand so, wie das Theme ihn
// darstellen würde — ohne ihn freizugeben. Auch für neue Seiten, die es live
// noch gar nicht gibt: Der Server überlagert das Projekt nur für diesen einen
// Hugo-Lauf (siehe PreviewService).
const previewing = ref(false)
const canPreview = computed(() => auth.buildable && !!store.current?.key)

async function previewDraft() {
  const key = store.current?.key
  if (!key || previewing.value) return
  previewing.value = true
  // Fenster vor dem Bau öffnen, sonst blockiert der Browser es als
  // ungefragtes Aufklappen.
  const win = window.open('', '_blank')
  try {
    const { token } = await api.post('previewbuild', { draftKey: key })
    const url = apiUrl('preview', { token })
    if (win) {
      win.location.href = url
    } else {
      window.open(url, '_blank')
    }
  } catch (e) {
    win?.close()
    store.error = e // roh ablegen — die Ansicht übersetzt ihn
  } finally {
    previewing.value = false
  }
}
</script>

<template>
  <div v-if="store.queueOpen" class="rev-overlay">
    <header class="rev-head nemo-noselect">
      <button
        class="rev-back"
        :title="store.dialogOpen ? $t('common.back') : $t('common.close')"
        @click="store.dialogOpen ? store.closeDialog() : store.closeQueue()"
      >
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-clipboard-text-clock-outline" size="18" class="rev-head-icon" />
      <span class="rev-head-title text-truncate">
        {{ store.dialogOpen ? (store.current?.rel || $t('review.title')) : $t('review.title') }}
      </span>
      <v-spacer />
      <v-chip v-if="!store.dialogOpen" size="small" variant="tonal" label>{{ store.count }}</v-chip>
    </header>

    <div class="rev-content nemo-scroll">
      <div class="rev-inner">
        <!-- Fehler -->
        <v-alert v-if="store.error" type="error" density="comfortable" class="mb-3">
          {{ errorText(t, store.error) }}
        </v-alert>

        <!-- Der Build ist pausiert: terminierte Freigaben werden vom Cron-Build
             live geschaltet — solange er ruht, geht nichts online. Hinweis mit
             direktem Weg zum Systemstatus, wo sich die Pause aufheben lässt. -->
        <v-alert
          v-if="auth.cronPause.pauseBuild"
          type="warning"
          density="comfortable"
          variant="tonal"
          class="mb-3"
          prepend-icon="mdi-pause-circle-outline"
        >
          {{ $t('review.buildPaused') }}
          <template #append>
            <v-btn size="small" variant="text" @click="openStatus">{{ $t('review.toStatus') }}</v-btn>
          </template>
        </v-alert>

        <!-- Diff-Detail eines Entwurfs -->
        <template v-if="store.dialogOpen">
          <div v-if="store.loading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" />
          </div>
          <template v-else-if="store.current">
            <div class="d-flex align-center mb-3 text-body-2 text-medium-emphasis">
              <v-icon :icon="ORIGIN_ICON[store.current.origin] || 'mdi-file-outline'" size="16" class="mr-1" />
              <span>{{ originLabel(store.current) }}</span>
              <span v-if="store.current.isNew" class="ml-2">· {{ $t('review.newPage') }}</span>
              <span v-if="store.current.createdAt" class="ml-2">· {{ formatDate(store.current.createdAt) }}</span>
              <span v-if="store.current.model" class="ml-2">· {{ store.current.model }}</span>
            </div>

            <!-- Bereits terminiert: Hinweis, dass die alte Fassung bis dahin online bleibt. -->
            <v-alert
              v-if="store.current.publishAt"
              type="info"
              density="compact"
              variant="tonal"
              class="mb-3"
              prepend-icon="mdi-calendar-clock"
            >
              {{ $t('review.scheduledBanner', [formatDate(store.current.publishAt)]) }}
            </v-alert>

            <!-- Zeilenweiser Diff (alt rot, neu grün, Kontext grau) -->
            <div v-if="diff" class="rev-diff">
              <div
                v-for="(l, k) in diff"
                :key="k"
                class="rev-diff__line"
                :class="`rev-diff__line--${l.t}`"
              ><span class="rev-diff__sign">{{ l.t === 'add' ? '+' : l.t === 'del' ? '-' : ' ' }}</span>{{ l.text }}</div>
            </div>
            <!-- Neue Seite oder zu großer Diff: einfache Vorschau des Vorschlags. -->
            <pre v-else class="rev-preview">{{ store.current.proposedContent }}</pre>
          </template>
        </template>

        <!-- Warteschlangen-Liste -->
        <template v-else>
          <div v-if="store.listLoading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" />
          </div>
          <div v-else-if="!store.drafts.length" class="text-medium-emphasis text-center py-8">
            {{ $t('review.empty') }}
          </div>
          <v-list v-else lines="two" density="comfortable" class="rev-list">
            <v-list-item
              v-for="d in store.drafts"
              :key="d.key"
              class="rev-item"
              @click="store.open(d.key)"
            >
              <template #prepend>
                <v-icon :icon="ORIGIN_ICON[d.origin] || 'mdi-file-outline'" />
              </template>
              <v-list-item-title>
                {{ d.rel }}
                <v-chip
                  v-if="d.publishAt"
                  size="x-small"
                  color="info"
                  variant="tonal"
                  label
                  class="ml-2"
                  prepend-icon="mdi-calendar-clock"
                >
                  {{ formatDate(d.publishAt) }}
                </v-chip>
              </v-list-item-title>
              <v-list-item-subtitle>
                {{ originLabel(d) }}
                <span v-if="d.isNew"> · {{ $t('review.newPage') }}</span>
                <span v-if="d.createdAt"> · {{ formatDate(d.createdAt) }}</span>
              </v-list-item-subtitle>
              <template #append>
                <v-btn
                  icon="mdi-delete-outline"
                  size="small"
                  variant="text"
                  :title="$t('review.discard')"
                  :disabled="store.busy"
                  @click.stop="discard(d.key)"
                />
              </template>
            </v-list-item>
          </v-list>
        </template>
      </div>
    </div>

    <!-- Aktionsleiste (nur im Diff-Detail): Freigeben mit/ohne Termin, Verwerfen.
         Icon- und Text-Variante sind getrennte Buttons: ein (auch leerer)
         Default-Slot verdrängt in Vuetify sonst das icon-Prop, der Button bliebe
         leer. Auf schmalen Schirmen reine Icon-Buttons (Bedeutung im title). -->
    <footer v-if="store.dialogOpen && store.current" class="rev-actions nemo-noselect">
      <template v-if="!store.current.isNew">
        <v-btn
          v-if="smAndDown"
          icon="mdi-file-document-edit-outline"
          :title="$t('review.toSource')"
          variant="text"
          :disabled="store.busy"
          @click="toSource"
        />
        <v-btn
          v-else
          prepend-icon="mdi-file-document-edit-outline"
          variant="text"
          :disabled="store.busy"
          @click="toSource"
        >
          {{ $t('review.toSource') }}
        </v-btn>
      </template>

      <!-- Vorschau des vorgeschlagenen Stands. Anders als „Zur Quelle" auch bei
           neuen Seiten sinnvoll: Der Entwurf trägt seinen Inhalt selbst. -->
      <template v-if="canPreview">
        <v-btn
          v-if="smAndDown"
          icon="mdi-eye-outline"
          :title="$t('review.preview')"
          variant="text"
          :disabled="store.busy || previewing"
          :loading="previewing"
          @click="previewDraft"
        />
        <v-btn
          v-else
          prepend-icon="mdi-eye-outline"
          variant="text"
          :disabled="store.busy || previewing"
          :loading="previewing"
          @click="previewDraft"
        >
          {{ $t('review.preview') }}
        </v-btn>
      </template>

      <v-btn
        v-if="smAndDown"
        icon="mdi-delete-outline"
        :title="$t('review.discard')"
        variant="text"
        color="error"
        :disabled="store.busy"
        @click="discard(store.current.key)"
      />
      <v-btn
        v-else
        prepend-icon="mdi-delete-outline"
        variant="text"
        color="error"
        :disabled="store.busy"
        @click="discard(store.current.key)"
      >
        {{ $t('review.discard') }}
      </v-btn>

      <v-spacer />

      <!-- Terminierte Freigabe über einen eigenen Bestätigungsdialog. Bei einem
           bereits terminierten Entwurf dient er zum Umplanen. -->
      <v-btn
        v-if="smAndDown"
        icon="mdi-calendar-clock"
        :title="store.current.publishAt ? $t('review.reschedule') : $t('review.schedule')"
        variant="text"
        :disabled="store.busy"
        @click="openScheduleDialog"
      />
      <v-btn
        v-else
        prepend-icon="mdi-calendar-clock"
        variant="text"
        :disabled="store.busy"
        @click="openScheduleDialog"
      >
        {{ store.current.publishAt ? $t('review.reschedule') : $t('review.schedule') }}
      </v-btn>
      <v-btn
        v-if="smAndDown"
        icon="mdi-check"
        :title="$t('review.approveNow')"
        color="primary"
        variant="flat"
        :loading="store.busy"
        @click="approveNow"
      />
      <v-btn
        v-else
        color="primary"
        variant="flat"
        prepend-icon="mdi-check"
        :loading="store.busy"
        @click="approveNow"
      >
        {{ $t('review.approveNow') }}
      </v-btn>
    </footer>

    <!-- Termin-Dialog: Datum/Uhrzeit wählen. Bestätigen hinterlegt den Termin;
         die aktuelle Fassung bleibt bis dahin online, ein Build tauscht sie zum
         Zeitpunkt (verzögerter Austausch). Vergangener Termin = sofort. -->
    <v-dialog v-model="scheduleDialog" max-width="480">
      <v-card v-if="store.current">
        <v-card-title class="text-subtitle-1">{{ $t('review.scheduleTitle') }}</v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-3">{{ $t('review.scheduleIntro') }}</p>
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
          <!-- Bei einer bereits veröffentlichten Seite: Zusicherung, dass sie
               bis zum Termin online bleibt (kein Offline-Zeitfenster mehr). -->
          <v-alert
            v-if="!store.current.isNew && scheduledInFuture"
            type="info"
            density="compact"
            variant="tonal"
            class="mt-3"
          >
            {{ $t('review.scheduleLiveInfo') }}
          </v-alert>
          <p v-else-if="publishDay && !scheduledInFuture" class="text-caption text-medium-emphasis mt-2">
            {{ $t('review.schedulePastHint') }}
          </p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="store.busy" @click="scheduleDialog = false">
            {{ $t('common.cancel') }}
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="!publishDay || store.busy"
            :loading="store.busy"
            @click="approveScheduled"
          >
            {{ $t('review.scheduleConfirm') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<style scoped>
/* Overlay über dem Arbeitsbereich, aber UNTER dem Editor (z-index kleiner als
   .editor-overlay = 10) — wie das SEO-Audit: Eine aus dem Diff geöffnete
   Quelldatei legt sich darüber, und beim Schließen erscheint die Warteschlange
   wieder. Die Ansicht wird nur aus der Werkzeugschiene geöffnet, die den Editor
   ohnehin verlässt; sie muss also nie über ihm liegen. */
.rev-overlay {
  position: absolute;
  inset: 0;
  z-index: 9;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.rev-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.rev-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px 6px;
  color: var(--mint-text);
  cursor: pointer;
}
.rev-back:hover { background: var(--mint-panel-hover); }
.rev-head-icon { color: var(--mint-green); }
.rev-head-title { font-weight: 600; font-size: 0.95rem; min-width: 0; }

.rev-content { flex: 1 1 auto; overflow: auto; }
.rev-inner { max-width: none; margin: 0; padding: 16px 20px 32px; }

.rev-list { background: transparent; }
.rev-item { cursor: pointer; border-bottom: 1px solid var(--mint-border); }

/* Diff-Darstellung — übernommen vom Assistenten-Panel. */
.rev-diff {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
  line-height: 1.45;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  overflow: auto;
}
.rev-diff__line { padding: 0 8px; white-space: pre-wrap; word-break: break-word; }
.rev-diff__sign {
  display: inline-block;
  width: 1ch;
  margin-right: 4px;
  opacity: 0.55;
  user-select: none;
}
.rev-diff__line--add { background: rgba(60, 133, 39, 0.16); }
.rev-diff__line--del {
  background: rgba(192, 57, 43, 0.14);
  text-decoration: line-through;
  text-decoration-color: rgba(192, 57, 43, 0.5);
}
.rev-diff__line--ctx { color: var(--mint-text-muted, #666); }

.rev-preview {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  padding: 8px;
  margin: 0;
}

.rev-actions {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  row-gap: 4px;
  padding: 8px 12px;
  background: var(--mint-panel);
  border-top: 1px solid var(--mint-border);
}
</style>
