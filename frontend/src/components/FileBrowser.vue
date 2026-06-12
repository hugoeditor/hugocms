<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { formatSize, formatDate, iconFor } from '../util/format'
import { errorText } from '../i18n/apiMessage'

const { t } = useI18n()
const files = useFilesStore()
const error = ref(null)

const entries = computed(() => files.visibleEntries)

function typeLabel(entry) {
  if (entry.type === 'dir') return t('files.typeFolder')
  const dot = entry.name.lastIndexOf('.')
  if (dot > 0 && dot < entry.name.length - 1) {
    return entry.name.slice(dot + 1).toUpperCase()
  }
  return entry.mime || '—'
}

function onClick(entry) {
  files.select(entry.id)
}

async function onOpen(entry) {
  error.value = null
  try {
    await files.activate(entry)
  } catch (e) {
    error.value = errorText(t, e)
  }
}
</script>

<template>
  <section class="nemo-view">
    <!-- Kein Ort gewählt -->
    <div v-if="!files.cwd" class="nemo-empty">
      <v-icon icon="mdi-folder-network-outline" size="64" class="nemo-empty-icon" />
      <p>{{ $t('files.chooseMount') }}</p>
    </div>

    <template v-else>
      <v-alert v-if="error" type="error" density="compact" class="ma-2" tile>{{ error }}</v-alert>

      <div class="nemo-content nemo-scroll nemo-noselect" @click.self="files.select(null)">
        <!-- leerer / gefilterter Ordner -->
        <div v-if="entries.length === 0" class="nemo-empty">
          <v-icon
            :icon="files.filter ? 'mdi-file-search-outline' : 'mdi-folder-outline'"
            size="56"
            class="nemo-empty-icon"
          />
          <p>{{ files.filter ? $t('files.noMatch', [files.filter]) : $t('files.emptyDir') }}</p>
        </div>

        <!-- Listenansicht -->
        <table v-else-if="files.view === 'list'" class="nemo-list">
          <thead>
            <tr>
              <th class="col-name">{{ $t('files.colName') }}</th>
              <th class="col-size">{{ $t('files.colSize') }}</th>
              <th class="col-type">{{ $t('files.colType') }}</th>
              <th class="col-date">{{ $t('files.colModified') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="entry in entries"
              :key="entry.id"
              class="nemo-row"
              :class="{ selected: files.selectedId === entry.id }"
              @click="onClick(entry)"
              @dblclick="onOpen(entry)"
            >
              <td class="col-name">
                <v-icon :icon="iconFor(entry)" size="18" class="nemo-row-icon" />
                <span class="nemo-row-name">{{ entry.name }}</span>
              </td>
              <td class="col-size">{{ entry.type === 'dir' ? '' : formatSize(entry.size) }}</td>
              <td class="col-type">{{ typeLabel(entry) }}</td>
              <td class="col-date">{{ formatDate(entry.mtime) }}</td>
            </tr>
          </tbody>
        </table>

        <!-- Symbolansicht -->
        <div v-else class="nemo-grid">
          <button
            v-for="entry in entries"
            :key="entry.id"
            class="nemo-tile"
            :class="{ selected: files.selectedId === entry.id }"
            @click="onClick(entry)"
            @dblclick="onOpen(entry)"
          >
            <v-icon :icon="iconFor(entry)" size="48" class="nemo-tile-icon" />
            <span class="nemo-tile-name">{{ entry.name }}</span>
          </button>
        </div>
      </div>

      <!-- Statusleiste -->
      <footer class="nemo-statusbar nemo-noselect">
        <span>{{ files.entries.length === 1 ? $t('status.itemOne') : $t('status.items', [files.entries.length]) }}</span>
        <span v-if="files.selectedId" class="nemo-status-sel">· {{ $t('status.selected', [1]) }}</span>
        <span v-if="files.filter" class="nemo-status-sel">· {{ entries.length }}/{{ files.entries.length }}</span>
      </footer>
    </template>
  </section>
</template>

<style scoped>
.nemo-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--mint-content);
  min-width: 0;
}

.nemo-content {
  flex: 1 1 auto;
  overflow: auto;
}

/* Leerzustände */
.nemo-empty {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: var(--mint-text-muted);
  gap: 10px;
}
.nemo-empty-icon {
  color: #c4c4c0;
}

/* Listenansicht */
.nemo-list {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}
.nemo-list thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  text-align: left;
  font-weight: 600;
  color: var(--mint-text-muted);
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  padding: 6px 10px;
  white-space: nowrap;
}
.col-size { width: 110px; text-align: right; }
.col-type { width: 130px; }
.col-date { width: 160px; }
.nemo-list td.col-size { text-align: right; }

.nemo-row {
  cursor: default;
  color: var(--mint-text);
}
.nemo-row td {
  padding: 4px 10px;
  border-bottom: 1px solid #f0f0ee;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.nemo-row:hover {
  background: var(--mint-row-hover);
}
.nemo-row.selected {
  background: var(--mint-green);
  color: #fff;
}
.nemo-row.selected .nemo-row-icon,
.nemo-row.selected .col-size,
.nemo-row.selected .col-type,
.nemo-row.selected .col-date {
  color: #fff;
}
.col-name {
  display: flex;
  align-items: center;
  gap: 8px;
}
.nemo-row-icon { color: #6f8f63; flex: 0 0 auto; }
.col-size, .col-type, .col-date { color: var(--mint-text-muted); }

/* Symbolansicht */
.nemo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
  gap: 6px;
  padding: 12px;
  align-content: start;
}
.nemo-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 12px 6px;
  border: 1px solid transparent;
  border-radius: var(--mint-radius);
  background: transparent;
  cursor: default;
  color: var(--mint-text);
}
.nemo-tile:hover {
  background: var(--mint-row-hover);
}
.nemo-tile.selected {
  background: var(--mint-green-soft);
  border-color: #bcd6ac;
}
.nemo-tile-icon { color: #6f8f63; }
.nemo-tile.selected .nemo-tile-icon { color: var(--mint-green-dark); }
.nemo-tile-name {
  font-size: 0.8rem;
  text-align: center;
  line-height: 1.25;
  word-break: break-word;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.nemo-tile.selected .nemo-tile-name {
  color: var(--mint-green-dark);
  font-weight: 600;
}

/* Statusleiste */
.nemo-statusbar {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 6px;
  height: 26px;
  padding: 0 12px;
  background: var(--mint-panel);
  border-top: 1px solid var(--mint-border);
  font-size: 0.78rem;
  color: var(--mint-text-muted);
}
.nemo-status-sel { color: var(--mint-text-muted); }
</style>
