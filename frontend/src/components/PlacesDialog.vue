<script setup>
// Orte verwalten: Mounts dieser Webseite hinzufügen, bearbeiten, entfernen.
// Nur für Administratoren (auth.manageConfig); der Server prüft das selbst.
//
// Jeder Ort ist ein Expansion-Panel; ausgeklappt lassen sich Name und
// Einschränkungen (readonly, permissions, accept) bearbeiten. Gespeichert wird
// selbsttätig nach jeder Änderung (Schalter und Auswahlen sofort, der Name kurz
// nach dem letzten Tastendruck bzw. beim Verlassen des Felds). Umbenennen
// ändert nur den sichtbaren Namen (label) — die Sektions-ID steckt in den
// Datei-IDs und bleibt fest. Entfernen löscht nur den Eintrag, nicht
// die Dateien im Verzeichnis. Das Verzeichnis eines neuen Orts wählt der
// Verzeichnis-Picker (DirectoryPickerDialog); das Feld bleibt frei
// beschreibbar, der Server prüft den Pfad gegen dieselben Einstiegspunkte.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api/client'
import { errorText } from '../i18n/apiMessage'
import { useConfirm } from '../util/confirm'
import DirectoryPickerDialog from './DirectoryPickerDialog.vue'

const open = defineModel({ type: Boolean, default: false })
// changed = die Orte haben sich geändert; App lädt die Orte-Liste neu.
const emit = defineEmits(['changed'])

const { t } = useI18n()
const confirm = useConfirm()

const MAX_LABEL = 80

const loading = ref(false)
const busy = ref(false)
// Eine Fehlermeldung für alle Aktionen (Laden, Anlegen, Speichern, Entfernen).
// Sie steht unter der Einleitung außerhalb des scrollenden Bereichs und ist
// damit sichtbar, wo im Dialog man auch gerade arbeitet.
const error = ref(null)
const mounts = ref([])
const shared = ref(false) // Rückfall-Datei mounts.ini, gilt für mehrere Webseiten
// Vorgabe des Servers für das Verzeichnisfeld (Elternverzeichnis des
// Release-Verzeichnisses); der Picker öffnet ebenfalls dort.
const defaultPath = ref('')

// Alle Rechte eines Mounts (vom Server, Reihenfolge wie Mount::ALL_PERMISSIONS).
const allPermissions = ref([])
// Bearbeitungsstand je Ort (Sektions-ID → Formularwerte); wird bei jeder
// Serverantwort neu aus dem gespeicherten Stand gebildet — außer für Orte, an
// denen gerade noch getippt wird (geplantes Speichern), sonst verschwände die
// laufende Eingabe.
const drafts = ref({})
// Autosave: geplante Speichervorgänge je Ort (Timer) und Anzeige „wird
// gespeichert“. Die Aufrufe laufen nacheinander (chain), damit sich zwei
// Antworten nicht überholen.
const LABEL_DELAY = 800
const timers = {}
const saving = ref({})
let chain = Promise.resolve()
// Ausgeklappte Panels (Sektions-IDs).
const expanded = ref([])

const permissionItems = computed(() =>
  allPermissions.value.map((p) => ({ value: p, title: t(`places.perm.${p}`) })),
)
// Vorschläge für accept: die Endungen der vorhandenen Orte.
// Alle Dateitypen, die HugoCMS verarbeitet (vom Server: Editor-Endungen samt
// [editor] extra_editable und Bildformate) — Ziel von „Alle Dateitypen
// erlauben“.
const availableTypes = ref([])
// Grundausstattung für Redakteure: dieselben ohne die per extra_editable
// freigeschalteten Endungen.
const editorTypes = ref([])
// Die Endungen aus extra_editable, wie konfiguriert — auch solche, die zugleich
// eingebaut sind (etwa js). Genau diese entfernt der Redakteurs-Eintrag.
const extraTypes = ref([])
// Vorschläge für accept: die verfügbaren Typen und die Endungen der Orte.
const acceptSuggestions = computed(() =>
  [...new Set([...availableTypes.value, ...mounts.value.flatMap((m) => m.accept ?? [])])].sort(),
)

// „Alle Dateitypen erlauben“: trägt alle verfügbaren Typen ins Feld ein (die
// vorhandenen bleiben, auch von Hand ergänzte). Leer bleibt weiterhin „alle“.
function allowTypes(name, types) {
  const d = drafts.value[name]
  d.accept = [...new Set([...d.accept, ...types])]
  scheduleSave(name)
}

// „Dateitypen für den Redakteur erlauben“: die Grundausstattung eintragen und
// die per extra_editable freigeschalteten Endungen (etwa sh) wieder entfernen.
// Übrige, von Hand ergänzte Typen bleiben.
function allowEditorTypes(name) {
  const d = drafts.value[name]
  d.accept = [...new Set([...d.accept, ...editorTypes.value])].filter((type) => !extraTypes.value.includes(type))
  scheduleSave(name)
}

// Neuer Ort.
const newLabel = ref('')
const newPath = ref('')
const pickerOpen = ref(false)

function draftOf(mount) {
  return {
    label: mount.label,
    readonly: !!mount.readonly,
    permissions: [...(mount.permissions ?? [])],
    accept: [...(mount.accept ?? [])],
  }
}

function applyState(data) {
  mounts.value = data.mounts ?? []
  allPermissions.value = data.allPermissions ?? []
  availableTypes.value = data.fileTypes ?? []
  editorTypes.value = data.editorFileTypes ?? []
  extraTypes.value = data.extraFileTypes ?? []
  drafts.value = Object.fromEntries(
    mounts.value.map((m) => [m.name, timers[m.name] && drafts.value[m.name] ? drafts.value[m.name] : draftOf(m)]),
  )
  shared.value = !!data.shared
  defaultPath.value = data.defaultPath ?? ''
  if (!newPath.value) newPath.value = defaultPath.value
}

async function load() {
  loading.value = true
  error.value = null
  try {
    applyState(await api.get('mountadmin'))
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
}

// Gemeinsamer Ablauf für Anlegen und Entfernen: Erfolg an App melden, einen
// Fehler in der Meldungszeile anzeigen.
async function write(cmd, body) {
  busy.value = true
  error.value = null
  try {
    applyState(await api.post(cmd, body))
    emit('changed')
    return true
  } catch (e) {
    error.value = errorText(t, e)
    return false
  } finally {
    busy.value = false
  }
}

// Freie Eingaben der accept-Combobox angleichen (klein, ohne Punkt); die
// endgültige Prüfung macht der Server.
function cleanAccept(list) {
  return [...new Set(list.map((e) => String(e).trim().replace(/^\./, '').toLowerCase()).filter(Boolean))]
}

function sameSet(a, b) {
  return a.length === b.length && a.every((x) => b.includes(x))
}

// Weicht das Formular vom gespeicherten Stand ab?
function isDirty(mount) {
  const d = drafts.value[mount.name]
  if (!d) return false
  return d.label.trim() !== mount.label
    || d.readonly !== !!mount.readonly
    || !sameSet(d.permissions, mount.permissions ?? [])
    || !sameSet(cleanAccept(d.accept), mount.accept ?? [])
}

// Speichern für einen Ort vormerken; delay 0 = gleich (nach dem aktuellen
// Ereignis, damit v-model den Entwurf schon aktualisiert hat).
function scheduleSave(name, delay = 0) {
  clearTimeout(timers[name])
  saving.value = { ...saving.value, [name]: true }
  timers[name] = setTimeout(() => {
    delete timers[name]
    chain = chain.then(() => saveNow(name))
  }, delay)
}

// Alle vorgemerkten Speichervorgänge sofort ausführen (Dialog schließt).
function flushSaves() {
  for (const name of Object.keys(timers)) scheduleSave(name, 0)
}

async function saveNow(name) {
  const mount = mounts.value.find((m) => m.name === name)
  const d = drafts.value[name]
  // Ohne Namen nicht speichern — das Feld zeigt den Fehler, der Ort bleibt
  // als „nicht gespeichert“ markiert.
  if (!mount || !d || !isDirty(mount) || !d.label.trim()) {
    if (!timers[name]) saving.value = { ...saving.value, [name]: false }
    return
  }
  const body = { name, label: d.label, readonly: d.readonly, accept: cleanAccept(d.accept) }
  // Bei „nur lesen“ überschreibt readonly die Rechte — die Auswahl bleibt dann
  // unverändert in der Datei stehen und greift wieder, sobald readonly fällt.
  if (!d.readonly) body.permissions = d.permissions
  try {
    applyState(await api.post('mountupdate', body))
    error.value = null
    emit('changed')
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    if (!timers[name]) saving.value = { ...saving.value, [name]: false }
  }
}

async function remove(mount) {
  const ok = await confirm({
    title: t('places.deleteTitle'),
    message: t('places.deleteConfirm', [mount.label]),
    confirmText: t('places.deleteAction'),
    color: 'error',
  })
  if (ok) await write('mountdelete', { name: mount.name })
}

async function add() {
  if (await write('mountadd', { label: newLabel.value, path: newPath.value })) {
    newLabel.value = ''
    newPath.value = defaultPath.value
  }
}

// Ohne eigenen Namen den Verzeichnisnamen vorschlagen.
function onPicked(path) {
  newPath.value = path
  if (!newLabel.value.trim()) newLabel.value = path.split('/').filter(Boolean).pop() ?? ''
}

watch(open, (isOpen) => {
  if (!isOpen) {
    flushSaves()
    return
  }
  expanded.value = []
  error.value = null
  newLabel.value = ''
  newPath.value = ''
  load()
})
</script>

<template>
  <v-dialog v-model="open" width="760" scrollable :persistent="busy">
    <v-card class="pa-2">
      <v-card-title class="text-h6 d-flex align-center">
        <v-icon icon="mdi-folder-edit-outline" class="mr-2" />
        {{ $t('places.title') }}
        <v-spacer />
        <v-btn
          icon="mdi-close"
          size="small"
          variant="text"
          :title="$t('common.close')"
          :disabled="busy"
          @click="open = false"
        />
      </v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('places.intro') }}</v-card-subtitle>
      <!-- Außerhalb von v-card-text: scrollt nicht mit und bleibt sichtbar. -->
      <v-alert
        v-if="error"
        type="error"
        density="comfortable"
        closable
        class="mx-4 mt-2"
        @click:close="error = null"
      >
        {{ error }}
      </v-alert>

      <v-card-text>
        <v-alert v-if="shared" type="warning" variant="tonal" density="compact" class="mb-3">
          {{ $t('places.sharedHint') }}
        </v-alert>
        <v-progress-linear v-if="loading" indeterminate class="mb-2" />

        <v-expansion-panels v-model="expanded" multiple variant="accordion" class="pl-panels">
          <v-expansion-panel v-for="mount in mounts" :key="mount.name" :value="mount.name">
            <v-expansion-panel-title>
              <div class="pl-head">
                <div class="pl-head-line">
                  <v-icon icon="mdi-folder-outline" size="18" class="mr-2" />
                  <span class="font-weight-medium">{{ mount.label }}</span>
                  <v-chip v-if="mount.readonly" size="x-small" variant="tonal" color="warning" class="ml-2">
                    {{ $t('places.readonly') }}
                  </v-chip>
                  <v-chip
                    v-else-if="(mount.permissions ?? []).length < allPermissions.length"
                    size="x-small"
                    variant="tonal"
                    class="ml-2"
                  >
                    {{ $t('places.restricted') }}
                  </v-chip>
                  <v-chip v-if="mount.accept?.length" size="x-small" variant="tonal" class="ml-1">
                    {{ mount.accept.length }} {{ $t('places.types') }}
                  </v-chip>
                  <v-chip v-if="saving[mount.name]" size="x-small" color="primary" variant="tonal" class="ml-1">
                    {{ $t('places.saving') }}
                  </v-chip>
                  <v-chip v-else-if="isDirty(mount)" size="x-small" color="error" variant="tonal" class="ml-1">
                    {{ $t('places.unsaved') }}
                  </v-chip>
                </div>
                <div class="text-caption text-medium-emphasis pl-path" :title="mount.path">
                  <v-icon v-if="mount.missing" icon="mdi-alert-outline" size="14" color="warning" :title="$t('places.missing')" />
                  {{ mount.path }}
                </div>
              </div>
            </v-expansion-panel-title>

            <v-expansion-panel-text v-if="drafts[mount.name]">
              <v-text-field
                v-model="drafts[mount.name].label"
                :label="$t('places.label')"
                :maxlength="MAX_LABEL"
                :error-messages="drafts[mount.name].label.trim() ? [] : [$t('places.labelRequired')]"
                prepend-inner-icon="mdi-label-outline"
                variant="outlined"
                density="comfortable"
                class="mb-1"
                @update:model-value="scheduleSave(mount.name, LABEL_DELAY)"
                @blur="timers[mount.name] && scheduleSave(mount.name)"
                @keyup.enter="scheduleSave(mount.name)"
              />
              <v-switch
                v-model="drafts[mount.name].readonly"
                :label="$t('places.readonlyLabel')"
                @update:model-value="scheduleSave(mount.name)"
                :hint="$t('places.readonlyHint')"
                persistent-hint
                color="warning"
                density="compact"
                class="mb-3"
              />
              <div class="text-caption text-medium-emphasis mb-1">{{ $t('places.permissionsHint') }}</div>
              <v-chip-group
                v-model="drafts[mount.name].permissions"
                multiple
                @update:model-value="scheduleSave(mount.name)"
                column
                selected-class="text-primary"
                :disabled="drafts[mount.name].readonly"
                class="mb-3"
              >
                <v-chip
                  v-for="item in permissionItems"
                  :key="item.value"
                  :value="item.value"
                  :disabled="drafts[mount.name].readonly || item.value === 'read'"
                  filter
                  variant="outlined"
                  size="small"
                >
                  {{ item.title }}
                </v-chip>
              </v-chip-group>
              <v-combobox
                v-model="drafts[mount.name].accept"
                :items="acceptSuggestions"
                @update:model-value="scheduleSave(mount.name)"
                :label="$t('places.accept')"
                :placeholder="$t('places.acceptAll')"
                :hint="$t('places.acceptHint')"
                persistent-hint
                multiple
                chips
                closable-chips
                prepend-inner-icon="mdi-file-check-outline"
                variant="outlined"
                density="comfortable"
              >
                <!-- Oben in der Liste: alle verfügbaren Typen bzw. die Grundausstattung
                     für Redakteure (ohne extra_editable) auf einmal eintragen. -->
                <template #prepend-item>
                  <v-list-item
                    prepend-icon="mdi-check-all"
                    :title="$t('places.acceptAllTypes')"
                    :subtitle="availableTypes.join(', ')"
                    @click="allowTypes(mount.name, availableTypes)"
                  />
                  <v-list-item
                    prepend-icon="mdi-account-edit-outline"
                    :title="$t('places.acceptEditorTypes')"
                    :subtitle="editorTypes.join(', ')"
                    @click="allowEditorTypes(mount.name)"
                  />
                  <v-divider class="my-1" />
                </template>
              </v-combobox>
              <div class="d-flex align-center mt-4">
                <v-btn
                  color="error"
                  variant="text"
                  prepend-icon="mdi-delete"
                  :disabled="busy || mounts.length <= 1"
                  @click="remove(mount)"
                >
                  {{ $t('places.deleteAction') }}
                </v-btn>
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>

        <v-divider class="my-4" />
        <div class="text-subtitle-2 mb-2">{{ $t('places.addTitle') }}</div>
        <v-text-field
          v-model="newPath"
          :label="$t('places.path')"
          :hint="$t('places.pathHint')"
          persistent-hint
          prepend-inner-icon="mdi-folder-outline"
          variant="outlined"
          density="comfortable"
          class="mb-3"
        >
          <template #append-inner>
            <v-icon
              icon="mdi-folder-open-outline"
              size="small"
              class="pl-browse"
              :title="$t('directoryPicker.browse')"
              @mousedown.prevent
              @click.stop="pickerOpen = true"
            />
          </template>
        </v-text-field>
        <v-text-field
          v-model="newLabel"
          :label="$t('places.label')"
          :maxlength="MAX_LABEL"
          prepend-inner-icon="mdi-label-outline"
          variant="outlined"
          density="comfortable"
          @keyup.enter="add"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="busy" @click="open = false">{{ $t('common.close') }}</v-btn>
        <v-btn
          color="primary"
          variant="flat"
          prepend-icon="mdi-folder-plus-outline"
          :loading="busy"
          :disabled="!newPath.trim() || !newLabel.trim()"
          @click="add"
        >
          {{ $t('places.add') }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <DirectoryPickerDialog v-model="pickerOpen" :start="newPath" @select="onPicked" />
  </v-dialog>
</template>

<style scoped>
.pl-head { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
.pl-head-line { display: flex; align-items: center; flex-wrap: wrap; }
.pl-path {
  max-width: 600px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pl-browse { cursor: pointer; }
</style>
