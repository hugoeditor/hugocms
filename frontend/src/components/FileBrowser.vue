<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
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
  if (dot > 0 && dot < entry.name.length - 1) return entry.name.slice(dot + 1).toUpperCase()
  return entry.mime || '—'
}

function isCut(entry) {
  return files.clipboard.mode === 'cut' && files.clipboard.ids.includes(entry.id)
}

async function run(fn) {
  error.value = null
  try {
    await fn()
  } catch (e) {
    error.value = errorText(t, e)
  }
}

// --- Auswahl per Klick ---------------------------------------------------

function onRowClick(entry, event) {
  if (event.ctrlKey || event.metaKey) files.toggleSelect(entry.id)
  else if (event.shiftKey) files.selectRange(entry.id)
  else files.selectOnly(entry.id)
}

function onOpen(entry) {
  run(() => files.activate(entry))
}

// --- Kontextmenü ---------------------------------------------------------

const menu = reactive({ open: false, x: 0, y: 0, items: [] })

function buildItems(entry) {
  const items = []
  const sel = files.selectedCount
  if (entry) {
    if (entry.type === 'dir' || entry.editable) {
      items.push({ icon: 'mdi-open-in-app', label: t('ctx.open'), action: () => onOpen(entry) })
      items.push({ divider: true })
    }
    if (files.can('move')) items.push({ icon: 'mdi-content-cut', label: t('ctx.cut'), action: () => files.cutSelection() })
    if (files.can('copy')) items.push({ icon: 'mdi-content-copy', label: t('ctx.copy'), action: () => files.copySelection() })
  }
  // Einfügen (in das aktuelle Verzeichnis)
  const pastePerm = files.clipboard.mode === 'cut' ? 'move' : 'copy'
  if (files.hasClipboard && files.can(pastePerm)) {
    items.push({ icon: 'mdi-content-paste', label: t('ctx.paste'), action: () => run(() => files.paste()) })
  }
  if (entry) {
    if (sel === 1 && files.can('rename')) {
      items.push({ divider: true })
      items.push({ icon: 'mdi-rename-outline', label: t('ctx.rename'), action: () => openRename(entry) })
    }
    if (files.can('delete')) {
      items.push({ icon: 'mdi-delete-outline', label: t('ctx.trash'), action: () => trashSelection() })
    }
  } else {
    if (files.can('mkdir')) items.push({ icon: 'mdi-folder-plus-outline', label: t('ctx.newFolder'), action: () => openNew('folder') })
    if (files.can('write')) items.push({ icon: 'mdi-file-plus-outline', label: t('ctx.newFile'), action: () => openNew('file') })
  }
  return items
}

function openMenu(entry, event) {
  if (entry && !files.isSelected(entry.id)) files.selectOnly(entry.id)
  if (!entry) files.clearSelection()
  const items = buildItems(entry)
  if (items.length === 0) return
  menu.items = items
  menu.x = event.clientX
  menu.y = event.clientY
  menu.open = true
}

function closeMenu() {
  menu.open = false
}

function runItem(item) {
  closeMenu()
  item.action()
}

// --- Dialog (Neu / Umbenennen) ------------------------------------------

const dialog = reactive({ open: false, title: '', label: '', confirm: '', value: '', onOk: null })
const dialogInput = ref(null)

function showDialog({ title, label, confirm, value, onOk }) {
  dialog.title = title
  dialog.label = label
  dialog.confirm = confirm
  dialog.value = value
  dialog.onOk = onOk
  dialog.open = true
  nextTick(() => dialogInput.value?.focus())
}

function openNew(kind) {
  if (kind === 'folder') {
    showDialog({
      title: t('dialog.newFolderTitle'),
      label: t('dialog.newFolderLabel'),
      confirm: t('dialog.create'),
      value: '',
      onOk: (name) => files.createFolder(name),
    })
  } else {
    showDialog({
      title: t('dialog.newFileTitle'),
      label: t('dialog.newFileLabel'),
      confirm: t('dialog.create'),
      value: '',
      onOk: (name) => files.createFile(name),
    })
  }
}

function openRename(entry) {
  showDialog({
    title: t('dialog.renameTitle'),
    label: t('dialog.renameLabel'),
    confirm: t('dialog.rename'),
    value: entry.name,
    onOk: (name) => files.renameEntry(entry.id, name),
  })
}

async function confirmDialog() {
  const name = dialog.value.trim()
  if (!name) return
  const ok = dialog.onOk
  dialog.open = false
  await run(() => ok(name))
}

function trashSelection() {
  run(() => files.trashEntries([...files.selectedIds]))
}

// Anfragen der Toolbar (Neuer Ordner / Neue Datei) konsumieren.
watch(
  () => files.newRequest,
  (kind) => {
    if (!kind) return
    openNew(kind)
    files.newRequest = null
  },
)

// --- Tastenkürzel --------------------------------------------------------

function onKey(e) {
  if (!files.cwd || files.openFile || dialog.open) return
  const tag = e.target?.tagName
  if (tag === 'INPUT' || tag === 'TEXTAREA') return

  const ctrl = e.ctrlKey || e.metaKey
  if (ctrl && e.key.toLowerCase() === 'a') {
    e.preventDefault()
    files.selectedIds = entries.value.map((x) => x.id)
    return
  }
  if (ctrl && e.shiftKey && e.key.toLowerCase() === 'n') {
    e.preventDefault()
    if (files.can('mkdir')) openNew('folder')
    return
  }
  if (ctrl && e.key.toLowerCase() === 'c') {
    files.copySelection()
    return
  }
  if (ctrl && e.key.toLowerCase() === 'x') {
    files.cutSelection()
    return
  }
  if (ctrl && e.key.toLowerCase() === 'v') {
    if (files.hasClipboard) run(() => files.paste())
    return
  }
  if (e.key === 'F2' && files.selectedCount === 1) {
    const entry = entries.value.find((x) => x.id === files.selectedIds[0])
    if (entry && files.can('rename')) openRename(entry)
    return
  }
  if (e.key === 'Delete' && files.selectedCount && files.can('delete')) {
    trashSelection()
    return
  }
  if (e.key === 'Enter' && files.selectedCount === 1) {
    const entry = entries.value.find((x) => x.id === files.selectedIds[0])
    if (entry) onOpen(entry)
    return
  }
  if (e.key === 'Escape') {
    if (menu.open) closeMenu()
    else files.clearSelection()
  }
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <section class="nemo-view">
    <div v-if="!files.cwd" class="nemo-empty">
      <v-icon icon="mdi-folder-network-outline" size="64" class="nemo-empty-icon" />
      <p>{{ $t('files.chooseMount') }}</p>
    </div>

    <template v-else>
      <v-alert v-if="error" type="error" density="compact" class="ma-2" tile closable @click:close="error = null">
        {{ error }}
      </v-alert>

      <div
        class="nemo-content nemo-scroll nemo-noselect"
        @click.self="files.clearSelection()"
        @contextmenu.self.prevent="openMenu(null, $event)"
      >
        <div v-if="entries.length === 0" class="nemo-empty" @contextmenu.prevent="openMenu(null, $event)">
          <v-icon :icon="files.filter ? 'mdi-file-search-outline' : 'mdi-folder-outline'" size="56" class="nemo-empty-icon" />
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
              :class="{ selected: files.isSelected(entry.id), cut: isCut(entry) }"
              @click="onRowClick(entry, $event)"
              @dblclick="onOpen(entry)"
              @contextmenu.prevent="openMenu(entry, $event)"
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
            :class="{ selected: files.isSelected(entry.id), cut: isCut(entry) }"
            @click="onRowClick(entry, $event)"
            @dblclick="onOpen(entry)"
            @contextmenu.prevent="openMenu(entry, $event)"
          >
            <v-icon :icon="iconFor(entry)" size="48" class="nemo-tile-icon" />
            <span class="nemo-tile-name">{{ entry.name }}</span>
          </button>
        </div>
      </div>

      <!-- Statusleiste -->
      <footer class="nemo-statusbar nemo-noselect">
        <span>{{ files.entries.length === 1 ? $t('status.itemOne') : $t('status.items', [files.entries.length]) }}</span>
        <span v-if="files.selectedCount" class="nemo-status-sel">· {{ $t('status.selected', [files.selectedCount]) }}</span>
        <span v-if="files.filter" class="nemo-status-sel">· {{ entries.length }}/{{ files.entries.length }}</span>
      </footer>
    </template>

    <!-- Kontextmenü -->
    <template v-if="menu.open">
      <div class="nemo-menu-backdrop" @click="closeMenu" @contextmenu.prevent="closeMenu" @wheel="closeMenu" />
      <ul class="nemo-menu" :style="{ left: menu.x + 'px', top: menu.y + 'px' }">
        <template v-for="(item, i) in menu.items" :key="i">
          <li v-if="item.divider" class="nemo-menu-divider" />
          <li v-else class="nemo-menu-item" @click="runItem(item)">
            <v-icon :icon="item.icon" size="18" class="nemo-menu-icon" />
            <span>{{ item.label }}</span>
          </li>
        </template>
      </ul>
    </template>

    <!-- Dialog: Neu / Umbenennen -->
    <v-dialog v-model="dialog.open" width="420">
      <v-card>
        <v-card-title class="text-subtitle-1">{{ dialog.title }}</v-card-title>
        <v-card-text>
          <v-text-field
            ref="dialogInput"
            v-model="dialog.value"
            :label="dialog.label"
            variant="outlined"
            density="comfortable"
            hide-details
            autofocus
            @keydown.enter="confirmDialog"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="dialog.open = false">{{ $t('dialog.cancel') }}</v-btn>
          <v-btn color="primary" variant="flat" :disabled="!dialog.value.trim()" @click="confirmDialog">
            {{ dialog.confirm }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </section>
</template>

<style scoped>
.nemo-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--mint-content);
  min-width: 0;
  position: relative;
}

.nemo-content {
  flex: 1 1 auto;
  overflow: auto;
}

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
.nemo-empty-icon { color: #c4c4c0; }

/* Listenansicht */
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
.col-type { width: 130px; }
.col-date { width: 160px; }
.nemo-list td.col-size { text-align: right; }

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
.nemo-row.selected .nemo-row-icon,
.nemo-row.selected .col-size,
.nemo-row.selected .col-type,
.nemo-row.selected .col-date { color: #fff; }
.nemo-row.cut { opacity: 0.5; }
.col-name { display: flex; align-items: center; gap: 8px; }
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
.nemo-tile:hover { background: var(--mint-row-hover); }
.nemo-tile.selected { background: var(--mint-green-soft); border-color: #bcd6ac; }
.nemo-tile.cut { opacity: 0.5; }
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
.nemo-tile.selected .nemo-tile-name { color: var(--mint-green-dark); font-weight: 600; }

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

/* Kontextmenü */
.nemo-menu-backdrop {
  position: fixed;
  inset: 0;
  z-index: 2000;
}
.nemo-menu {
  position: fixed;
  z-index: 2001;
  min-width: 200px;
  margin: 0;
  padding: 4px;
  list-style: none;
  background: #fff;
  border: 1px solid var(--mint-border-strong);
  border-radius: var(--mint-radius);
  box-shadow: 0 6px 22px rgba(0, 0, 0, 0.18);
}
.nemo-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 12px;
  border-radius: 3px;
  font-size: 0.88rem;
  color: var(--mint-text);
  cursor: pointer;
}
.nemo-menu-item:hover { background: var(--mint-green); color: #fff; }
.nemo-menu-item:hover .nemo-menu-icon { color: #fff; }
.nemo-menu-icon { color: var(--mint-text-muted); }
.nemo-menu-divider {
  height: 1px;
  margin: 4px 6px;
  background: var(--mint-border);
}
</style>
