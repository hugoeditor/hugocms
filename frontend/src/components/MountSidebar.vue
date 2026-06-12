<script setup>
import { useFilesStore } from '../stores/files'

const files = useFilesStore()

function open(mount) {
  files.openDir(mount.id)
}
</script>

<template>
  <nav class="nemo-sidebar nemo-noselect nemo-scroll">
    <div class="nemo-sidebar-header">{{ $t('files.places') }}</div>
    <ul class="nemo-places">
      <li
        v-for="mount in files.mounts"
        :key="mount.id"
        class="nemo-place"
        :class="{ active: files.activeMount === mount.name }"
        @click="open(mount)"
      >
        <v-icon
          :icon="files.activeMount === mount.name ? 'mdi-folder-open' : 'mdi-folder-network-outline'"
          size="18"
          class="nemo-place-icon"
        />
        <span class="nemo-place-label">{{ mount.label }}</span>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
.nemo-sidebar {
  height: 100%;
  background: var(--mint-panel);
  border-right: 1px solid var(--mint-border);
  padding: 8px 6px;
  overflow-y: auto;
}

.nemo-sidebar-header {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--mint-text-muted);
  padding: 6px 8px 4px;
}

.nemo-places {
  list-style: none;
  margin: 0;
  padding: 0;
}

.nemo-place {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
  padding: 0 8px;
  border-radius: var(--mint-radius);
  color: var(--mint-text);
  cursor: pointer;
}
.nemo-place:hover {
  background: var(--mint-panel-hover);
}
.nemo-place.active {
  background: var(--mint-green);
  color: #fff;
}
.nemo-place.active .nemo-place-icon {
  color: #fff;
}
.nemo-place-icon {
  color: var(--mint-text-muted);
  flex: 0 0 auto;
}
.nemo-place-label {
  font-size: 0.9rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
