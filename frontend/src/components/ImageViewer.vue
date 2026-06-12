<script setup>
// Bildbetrachter (Stufe 3): zeigt das Original (cmd=raw) abgedunkelt im
// Vollbild, mit Vor/Zurück durch alle Bilder des Ordners und Tastatursteuerung.
import { onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'

const { t } = useI18n()
const files = useFilesStore()

function onKey(e) {
  if (!files.viewerEntry) return
  if (e.key === 'Escape') files.closeViewer()
  else if (e.key === 'ArrowLeft') files.viewerStep(-1)
  else if (e.key === 'ArrowRight') files.viewerStep(1)
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div v-if="files.viewerEntry" class="viewer nemo-noselect" @click.self="files.closeViewer()">
    <header class="viewer-bar">
      <span class="viewer-name">{{ files.viewerEntry.name }}</span>
      <span class="viewer-counter">
        {{ t('viewer.counter', [files.viewerIndex + 1, files.imageEntries.length]) }}
      </span>
      <div class="viewer-spacer" />
      <v-tooltip :text="t('viewer.download')" location="bottom">
        <template #activator="{ props }">
          <button v-bind="props" class="viewer-btn" @click="files.download(files.viewerEntry)">
            <v-icon icon="mdi-download" size="20" />
          </button>
        </template>
      </v-tooltip>
      <v-tooltip :text="t('viewer.close')" location="bottom">
        <template #activator="{ props }">
          <button v-bind="props" class="viewer-btn" @click="files.closeViewer()">
            <v-icon icon="mdi-close" size="20" />
          </button>
        </template>
      </v-tooltip>
    </header>

    <button
      v-if="files.imageEntries.length > 1"
      class="viewer-nav prev"
      :aria-label="t('viewer.prev')"
      @click="files.viewerStep(-1)"
    >
      <v-icon icon="mdi-chevron-left" size="40" />
    </button>

    <img class="viewer-img" :src="files.rawUrl(files.viewerEntry)" :alt="files.viewerEntry.name" />

    <button
      v-if="files.imageEntries.length > 1"
      class="viewer-nav next"
      :aria-label="t('viewer.next')"
      @click="files.viewerStep(1)"
    >
      <v-icon icon="mdi-chevron-right" size="40" />
    </button>
  </div>
</template>

<style scoped>
.viewer {
  position: fixed;
  inset: 0;
  z-index: 3000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(20, 20, 19, 0.92);
}

.viewer-bar {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  gap: 12px;
  height: 46px;
  padding: 0 14px;
  background: rgba(0, 0, 0, 0.45);
  color: #f0f0ee;
}
.viewer-name {
  font-size: 0.92rem;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.viewer-counter {
  font-size: 0.8rem;
  color: #c9c9c5;
}
.viewer-spacer { flex: 1 1 auto; }

.viewer-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border: none;
  border-radius: var(--mint-radius);
  background: transparent;
  color: #f0f0ee;
  cursor: pointer;
}
.viewer-btn:hover { background: rgba(255, 255, 255, 0.14); }

.viewer-img {
  max-width: calc(100vw - 160px);
  max-height: calc(100vh - 110px);
  object-fit: contain;
  /* Schachbrett hinter transparenten Bildern */
  background:
    repeating-conic-gradient(#3a3a38 0% 25%, #2e2e2c 0% 50%) 0 0 / 20px 20px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}

.viewer-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 80px;
  border: none;
  border-radius: var(--mint-radius);
  background: rgba(0, 0, 0, 0.35);
  color: #f0f0ee;
  cursor: pointer;
}
.viewer-nav:hover { background: rgba(0, 0, 0, 0.6); }
.viewer-nav.prev { left: 14px; }
.viewer-nav.next { right: 14px; }
</style>
