<script setup>
// Orte verwalten: Mounts dieser Webseite hinzufügen, umbenennen, entfernen.
// Nur für Administratoren (auth.manageConfig); der Server prüft das selbst.
//
// Umbenennen ändert nur den sichtbaren Namen (label) — die Sektions-ID steckt
// in den Datei-IDs und bleibt fest. Entfernen löscht nur den Eintrag, nicht
// die Dateien im Verzeichnis. Das Verzeichnis eines neuen Orts wählt der
// Verzeichnis-Picker (DirectoryPickerDialog); das Feld bleibt frei
// beschreibbar, der Server prüft den Pfad gegen dieselben Einstiegspunkte.
import { ref, watch } from 'vue'
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
const error = ref(null)
const mounts = ref([])
const shared = ref(false) // Rückfall-Datei mounts.ini, gilt für mehrere Webseiten

// Umbenennen direkt in der Zeile.
const editName = ref(null)
const editLabel = ref('')

// Neuer Ort.
const newLabel = ref('')
const newPath = ref('')
const pickerOpen = ref(false)

function applyState(data) {
  mounts.value = data.mounts ?? []
  shared.value = !!data.shared
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

// Gemeinsamer Ablauf der drei Schreibbefehle: Fehler im Dialog anzeigen,
// Erfolg an App melden.
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

function startEdit(mount) {
  editName.value = mount.name
  editLabel.value = mount.label
}

async function saveEdit() {
  if (await write('mountrename', { name: editName.value, label: editLabel.value })) {
    editName.value = null
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
    newPath.value = ''
  }
}

// Ohne eigenen Namen den Verzeichnisnamen vorschlagen.
function onPicked(path) {
  newPath.value = path
  if (!newLabel.value.trim()) newLabel.value = path.split('/').filter(Boolean).pop() ?? ''
}

watch(open, (isOpen) => {
  if (!isOpen) return
  editName.value = null
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
      </v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('places.intro') }}</v-card-subtitle>

      <v-card-text>
        <v-alert v-if="shared" type="warning" variant="tonal" density="compact" class="mb-3">
          {{ $t('places.sharedHint') }}
        </v-alert>
        <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>
        <v-progress-linear v-if="loading" indeterminate class="mb-2" />

        <v-table density="comfortable" class="pl-table">
          <thead>
            <tr>
              <th>{{ $t('places.label') }}</th>
              <th>{{ $t('places.path') }}</th>
              <th class="text-right" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="mount in mounts" :key="mount.name">
              <td class="pl-label">
                <v-text-field
                  v-if="editName === mount.name"
                  v-model="editLabel"
                  :maxlength="MAX_LABEL"
                  density="compact"
                  variant="outlined"
                  hide-details
                  autofocus
                  @keyup.enter="saveEdit"
                  @keyup.esc="editName = null"
                />
                <template v-else>
                  {{ mount.label }}
                  <v-chip v-if="mount.readonly" size="x-small" variant="tonal" class="ml-1">{{ $t('places.readonly') }}</v-chip>
                </template>
              </td>
              <td class="text-caption pl-path" :title="mount.path">
                <v-icon v-if="mount.missing" icon="mdi-alert-outline" size="14" color="warning" :title="$t('places.missing')" />
                {{ mount.path }}
              </td>
              <td class="text-right text-no-wrap">
                <template v-if="editName === mount.name">
                  <v-btn icon="mdi-check" size="small" variant="text" color="primary" :loading="busy" @click="saveEdit" />
                  <v-btn icon="mdi-close" size="small" variant="text" :disabled="busy" @click="editName = null" />
                </template>
                <template v-else>
                  <v-btn
                    icon="mdi-pencil"
                    size="small"
                    variant="text"
                    :title="$t('places.rename')"
                    :disabled="busy"
                    @click="startEdit(mount)"
                  />
                  <v-btn
                    icon="mdi-delete"
                    size="small"
                    variant="text"
                    color="error"
                    :title="$t('places.deleteAction')"
                    :disabled="busy || mounts.length <= 1"
                    @click="remove(mount)"
                  />
                </template>
              </td>
            </tr>
          </tbody>
        </v-table>

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
.pl-label { min-width: 180px; }
.pl-path {
  max-width: 340px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pl-browse { cursor: pointer; }
</style>
