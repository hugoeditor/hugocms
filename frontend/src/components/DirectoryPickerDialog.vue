<script setup>
// Auswahl eines Verzeichnisses auf dem Server — nach dem Vorbild von
// OpensourceERP (directory-picker.dialog.vue).
//
// Der Browser kann kein Serververzeichnis auswählen: Seine Dateiauswahl meint
// immer den Rechner des Benutzers. Deshalb listet das Backend auf (Befehl
// browsedirs, nur für Administratoren), und dieser Dialog navigiert darin.
// Sichtbar ist nur, was unterhalb eines Einstiegspunkts liegt ([system]
// browse_roots in der hugocms.ini, sonst abgeleitet).
//
//   <DirectoryPickerDialog v-model="open" :start="path" @select="path = $event" />
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api/client'
import { errorText } from '../i18n/apiMessage'

const props = defineProps({
  // Pfad, bei dem der Dialog öffnet (leer = erster Einstiegspunkt).
  start: { type: String, default: '' },
})
const emit = defineEmits(['select'])
const open = defineModel({ type: Boolean, default: false })

const { t } = useI18n()

const loading = ref(false)
const error = ref('')
const state = ref({
  roots: [],
  root: '',
  path: '',
  parent: null,
  writable: false,
  selectable: false,
  entries: [],
  fileCount: 0,
  truncated: false,
})

// Ein Verzeichnis ohne Unterverzeichnisse heißt nur dann „leer“, wenn auch
// keine Dateien darin liegen.
const emptyText = computed(() =>
  state.value.fileCount > 0 ? t('directoryPicker.onlyFiles', [state.value.fileCount]) : t('directoryPicker.empty'),
)

async function load(path = '') {
  loading.value = true
  error.value = ''
  try {
    state.value = await api.get('browsedirs', { path })
  } catch (e) {
    error.value = errorText(t, e)
    // Ein gespeicherter Pfad außerhalb der Einstiegspunkte (oder nicht mehr
    // vorhanden): an der ersten Wurzel beginnen statt leer zu bleiben.
    if (path && !state.value.path) await load('')
  } finally {
    loading.value = false
  }
}

function apply() {
  emit('select', state.value.path)
  open.value = false
}

// Beim Öffnen dort beginnen, wo das Feld gerade steht.
watch(open, (isOpen) => {
  if (isOpen) {
    state.value = { ...state.value, path: '' }
    load(props.start || '')
  }
})
</script>

<template>
  <v-dialog v-model="open" max-width="720" scrollable>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon icon="mdi-folder-search-outline" class="mr-2" />
        {{ $t('directoryPicker.title') }}
        <v-spacer />
        <v-btn icon="mdi-close" size="small" variant="text" @click="open = false" />
      </v-card-title>
      <v-divider />

      <v-card-text class="pa-3">
        <!-- Einstiegspunkt wählen, wenn es mehrere gibt -->
        <v-select
          v-if="state.roots.length > 1"
          :model-value="state.root"
          :items="state.roots"
          :label="$t('directoryPicker.root')"
          density="compact"
          variant="outlined"
          hide-details
          class="mb-3"
          @update:model-value="load"
        />

        <div class="d-flex align-center ga-2 mb-2">
          <v-btn
            size="small"
            variant="text"
            icon="mdi-arrow-up"
            :disabled="!state.parent || loading"
            :title="$t('directoryPicker.up')"
            @click="load(state.parent)"
          />
          <div class="text-body-2 text-medium-emphasis flex-grow-1 text-truncate" :title="state.path">
            {{ state.path }}
          </div>
          <v-btn
            size="small"
            variant="text"
            icon="mdi-refresh"
            :loading="loading"
            :title="$t('common.refresh')"
            @click="load(state.path)"
          />
        </div>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-2" :text="error" />
        <v-alert
          v-else-if="state.truncated"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-2"
          :text="$t('directoryPicker.truncated')"
        />

        <v-card variant="outlined" class="dp-entries">
          <v-list density="compact" class="py-0">
            <v-list-item v-if="!loading && !state.entries.length" class="text-medium-emphasis">
              {{ emptyText }}
            </v-list-item>
            <v-list-item v-for="entry in state.entries" :key="entry.path" @click="load(entry.path)">
              <template #prepend>
                <v-icon icon="mdi-folder" color="amber-darken-2" />
              </template>
              <v-list-item-title>{{ entry.name }}</v-list-item-title>
              <template #append>
                <v-icon
                  v-if="!entry.writable"
                  icon="mdi-lock-outline"
                  size="x-small"
                  color="grey"
                  :title="$t('directoryPicker.readOnly')"
                />
              </template>
            </v-list-item>
          </v-list>
        </v-card>

        <div class="text-caption text-medium-emphasis mt-2">
          <template v-if="!state.selectable">{{ $t('directoryPicker.protected') }}</template>
          <template v-else>{{ state.writable ? $t('directoryPicker.writable') : $t('directoryPicker.notWritable') }}</template>
        </div>
      </v-card-text>

      <v-divider />
      <v-card-actions class="pa-3">
        <div class="text-caption text-truncate">{{ $t('directoryPicker.selection') }}: {{ state.path || '—' }}</div>
        <v-spacer />
        <v-btn variant="text" @click="open = false">{{ $t('common.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :disabled="!state.path || !state.selectable || loading" @click="apply">
          {{ $t('directoryPicker.apply') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.dp-entries {
  max-height: 46vh;
  overflow-y: auto;
}
</style>
