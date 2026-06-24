<script setup>
// Papierkorb-Verwaltung (Stufe 4): listet die Papierkörbe aller Mounts
// zusammen, stellt ausgewählte Einträge am Ursprungsort wieder her oder
// leert alles endgültig.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { formatSize, formatDate } from '../util/format'
import { errorText } from '../i18n/apiMessage'
import { useTransientError } from '../util/transientError'
import { useConfirm } from '../util/confirm'

const { t } = useI18n()
const files = useFilesStore()
const confirm = useConfirm()
const error = useTransientError() // blendet sich nach kurzer Zeit selbst aus
const busy = ref(false)

function mountLabel(name) {
  return files.mounts.find((m) => m.name === name)?.label ?? name
}

function isSelected(entry) {
  return files.trashSelected.some((s) => s.mount === entry.mount && s.trashName === entry.trashName)
}

function toggle(entry) {
  const i = files.trashSelected.findIndex(
    (s) => s.mount === entry.mount && s.trashName === entry.trashName,
  )
  if (i >= 0) files.trashSelected.splice(i, 1)
  else files.trashSelected.push({ mount: entry.mount, trashName: entry.trashName })
}

async function run(fn) {
  error.value = null
  busy.value = true
  try {
    await fn()
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    busy.value = false
  }
}

function restoreSelected() {
  if (files.trashSelected.length) run(() => files.restoreTrash([...files.trashSelected]))
}

async function emptyAll() {
  const ok = await confirm({
    title: t('trash.emptyTitle'),
    message: t('trash.emptyConfirm'),
    confirmText: t('trash.emptyConfirmAction'),
    color: 'error',
  })
  if (ok) run(() => files.emptyTrash())
}
</script>

<template>
  <section class="nemo-view">
    <!-- Kopfzeile mit Aktionen -->
    <div class="trash-head nemo-noselect">
      <v-icon icon="mdi-trash-can-outline" size="18" />
      <span class="trash-title">{{ $t('trash.title') }}</span>
      <div style="flex: 1 1 auto" />
      <button
        class="trash-btn"
        :disabled="busy || files.trashSelected.length === 0"
        @click="restoreSelected"
      >
        <v-icon icon="mdi-restore" size="16" class="mr-1" />{{ $t('trash.restore') }}
      </button>
      <button
        class="trash-btn danger"
        :disabled="busy || files.trashEntries.length === 0"
        @click="emptyAll"
      >
        <v-icon icon="mdi-delete-forever-outline" size="16" class="mr-1" />{{ $t('trash.emptyAction') }}
      </button>
    </div>

    <v-alert v-if="error" type="error" density="compact" class="ma-2 nemo-alert" tile closable @click:close="error = null">
      {{ error }}
    </v-alert>

    <div class="nemo-content nemo-scroll nemo-noselect">
      <div v-if="files.trashEntries.length === 0" class="nemo-empty">
        <v-icon icon="mdi-trash-can-outline" size="56" class="nemo-empty-icon" />
        <p>{{ $t('trash.empty') }}</p>
      </div>

      <table v-else class="nemo-list">
        <thead>
          <tr>
            <th class="col-name">{{ $t('files.colName') }}</th>
            <th>{{ $t('trash.colOrigin') }}</th>
            <th>{{ $t('trash.colMount') }}</th>
            <th class="col-size">{{ $t('files.colSize') }}</th>
            <th class="col-date">{{ $t('trash.colDeleted') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="entry in files.trashEntries"
            :key="entry.mount + ':' + entry.trashName"
            class="nemo-row"
            :class="{ selected: isSelected(entry) }"
            @click="toggle(entry)"
          >
            <td class="col-name">
              <v-icon
                :icon="entry.type === 'dir' ? 'mdi-folder' : 'mdi-file'"
                size="18"
                class="nemo-row-icon"
              />
              <span>{{ entry.name }}</span>
            </td>
            <td class="col-origin">{{ entry.origRel || '—' }}</td>
            <td>{{ mountLabel(entry.mount) }}</td>
            <td class="col-size">{{ entry.type === 'dir' ? '' : formatSize(entry.size) }}</td>
            <td class="col-date">{{ formatDate(entry.deletedAt) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="nemo-statusbar nemo-noselect">
      <span>
        {{ files.trashEntries.length === 1 ? $t('status.itemOne') : $t('status.items', [files.trashEntries.length]) }}
      </span>
      <span v-if="files.trashSelected.length">· {{ $t('status.selected', [files.trashSelected.length]) }}</span>
    </footer>
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

.trash-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.trash-title {
  font-weight: 600;
  font-size: 0.92rem;
}
.trash-btn {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 4px 12px;
  font-size: 0.85rem;
  color: var(--mint-text);
  cursor: pointer;
}
.trash-btn:hover:not(:disabled) {
  background: var(--mint-panel-hover);
}
.trash-btn:disabled {
  color: #b6b6b3;
  cursor: default;
}
.trash-btn.danger:hover:not(:disabled) {
  background: #fbeaea;
  border-color: #d9b0ab;
  color: #b03a2e;
}

.nemo-content {
  flex: 1 1 auto;
  overflow: auto;
}

.nemo-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  color: var(--mint-text-muted);
  gap: 10px;
}
.nemo-empty-icon { color: #c4c4c0; }

.nemo-list { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
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
.col-date { width: 160px; }
.nemo-list td.col-size { text-align: right; }

/* Schmale Schirme: platzraubende Spalten ausblenden (wie im Dateimanager). */
@media (max-width: 720px) {
  .col-origin { display: none; }
}
@media (max-width: 560px) {
  .col-date { display: none; }
}

.nemo-row { cursor: default; color: var(--mint-text); }
.nemo-row td {
  padding: 4px 10px;
  border-bottom: 1px solid #f0f0ee;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.nemo-row:hover { background: var(--mint-row-hover); }
.nemo-row.selected { background: var(--mint-green); color: #fff; }
.nemo-row.selected td,
.nemo-row.selected .nemo-row-icon { color: #fff; }
.col-name { display: flex; align-items: center; gap: 8px; }
.nemo-row-icon { color: #6f8f63; flex: 0 0 auto; }
.col-origin { color: var(--mint-text-muted); }

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
</style>
