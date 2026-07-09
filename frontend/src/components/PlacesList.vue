<script setup>
import { useFilesStore } from '../stores/files'

// Reine Orte-Liste (Mounts + Papierkorb). Meldet die Auswahl per Ereignis nach
// oben, statt selbst zu navigieren — so verhält sich ein Klick überall wie das
// Orte-Menü (App.leaveEditorThen: ungespeicherte Änderungen abfragen, offene
// Überlagerungen schließen). Wird sowohl in der Werkzeugschiene (Desktop
// ausgeklappt) als auch in der eingeschobenen Orte-Seitenleiste verwendet.
const files = useFilesStore()

// spread=true schiebt den Papierkorb ans untere Ende (wie in Nemo) — genutzt in
// der eingeschobenen Orte-Leiste, die die volle Höhe füllt. In der kompakten
// Werkzeugschienen-Liste bleibt der Papierkorb direkt unter den Orten.
defineProps({
  spread: { type: Boolean, default: false },
})

defineEmits(['select', 'trash'])
</script>

<template>
  <div class="nemo-places-wrap nemo-noselect" :class="{ spread }">
    <ul class="nemo-places">
      <li
        v-for="mount in files.mounts"
        :key="mount.id"
        class="nemo-place"
        :class="{ active: !files.trashMode && files.activeMount === mount.name }"
        @click="$emit('select', mount)"
      >
        <v-icon
          :icon="!files.trashMode && files.activeMount === mount.name ? 'mdi-folder-open' : 'mdi-folder'"
          size="18"
          class="nemo-place-icon"
        />
        <span class="nemo-place-label">{{ mount.label }}</span>
      </li>
    </ul>

    <div v-if="spread" class="nemo-places-spacer" />

    <ul class="nemo-places">
      <li
        class="nemo-place"
        :class="{ active: files.trashMode }"
        @click="$emit('trash')"
      >
        <v-icon icon="mdi-trash-can-outline" size="18" class="nemo-place-icon" />
        <span class="nemo-place-label">{{ $t('trash.title') }}</span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.nemo-places-wrap {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
/* Volle Höhe füllen, damit der Papierkorb ans untere Ende rücken kann. */
.nemo-places-wrap.spread {
  flex: 1 1 auto;
  min-height: 0;
}
/* Schiebt den Papierkorb ans untere Ende (wie in Nemo). */
.nemo-places-spacer {
  flex: 1 1 auto;
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
