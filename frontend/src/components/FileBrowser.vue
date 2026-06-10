<script setup>
import { ref } from 'vue'
import { useFilesStore } from '../stores/files'
import { formatSize, formatDate, iconFor } from '../util/format'

const files = useFilesStore()
const error = ref(null)

async function activate(entry) {
  error.value = null
  try {
    if (entry.type === 'dir') {
      await files.openDir(entry.id, entry.name)
    } else if (entry.editable) {
      await files.openTextFile(entry)
    }
    // Bilder/Binärdateien: Anzeige folgt in Stufe 3.
  } catch (e) {
    error.value = e.message
  }
}

function crumb(b) {
  files.openDir(b.id, b.name)
}
</script>

<template>
  <div class="pa-4">
    <div v-if="!files.cwd" class="text-medium-emphasis text-body-1 mt-8 text-center">
      Wähle links einen Mount.
    </div>

    <template v-else>
      <v-breadcrumbs :items="files.breadcrumb" density="compact" class="px-0">
        <template #title="{ item }">
          <a class="text-decoration-none cursor-pointer" @click="crumb(item)">{{ item.name }}</a>
        </template>
      </v-breadcrumbs>

      <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>

      <v-table density="comfortable" hover>
        <thead>
          <tr>
            <th>Name</th>
            <th class="text-right" style="width: 120px">Größe</th>
            <th style="width: 180px">Geändert</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="files.entries.length === 0">
            <td colspan="3" class="text-medium-emphasis text-center py-6">Leeres Verzeichnis</td>
          </tr>
          <tr
            v-for="entry in files.entries"
            :key="entry.id"
            class="cursor-pointer"
            @click="activate(entry)"
          >
            <td>
              <v-icon :icon="iconFor(entry)" class="mr-2" size="small" />
              {{ entry.name }}
            </td>
            <td class="text-right">{{ entry.type === 'dir' ? '' : formatSize(entry.size) }}</td>
            <td>{{ formatDate(entry.mtime) }}</td>
          </tr>
        </tbody>
      </v-table>
    </template>
  </div>
</template>

<style scoped>
.cursor-pointer { cursor: pointer; }
</style>
