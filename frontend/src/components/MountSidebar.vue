<script setup>
import { useFilesStore } from '../stores/files'

const files = useFilesStore()

function open(mount) {
  files.openDir(mount.id)
}
</script>

<template>
  <v-list density="comfortable" nav>
    <v-list-subheader>Mounts</v-list-subheader>
    <v-list-item
      v-for="mount in files.mounts"
      :key="mount.id"
      :active="files.activeMount === mount.name"
      prepend-icon="mdi-folder-network"
      :title="mount.label"
      @click="open(mount)"
    />
  </v-list>
</template>
