<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFilesStore } from '../stores/files'
import { useAuditContentStore } from '../stores/auditContent'
import { useAssistantStore } from '../stores/assistant'
import { formatSize, formatDate, iconFor } from '../util/format'
import { errorText } from '../i18n/apiMessage'
import ImageViewer from './ImageViewer.vue'
import ImageEditor from './ImageEditor.vue'
import { useTransientError } from '../util/transientError'
import { useAiGate } from '../util/aiGate'

const { t, locale } = useI18n()
const files = useFilesStore()
const auditContent = useAuditContentStore()
const assistant = useAssistantStore()
const requireAi = useAiGate() // KI-Zugangsschranke (Hinweis + Konfiguration)
const error = useTransientError() // blendet sich nach kurzer Zeit selbst aus

// Markdown-Content-Datei (für die LLM-Content-Prüfung im Kontextmenü).
function isMarkdown(entry) {
  return entry?.type === 'file' && /\.(md|markdown)$/i.test(entry.name || '')
}

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
    if (entry.type === 'dir' || entry.editable || entry.image) {
      items.push({ icon: 'mdi-open-in-app', label: t('ctx.open'), action: () => onOpen(entry) })
      // Editierbare Bilder (z. B. SVG): Öffnen zeigt den Betrachter,
      // Bearbeiten führt in den Texteditor.
      if (entry.image && entry.editable) {
        items.push({ icon: 'mdi-pencil-outline', label: t('ctx.edit'), action: () => run(() => files.openTextFile(entry)) })
      }
      // Rasterbilder (png/jpg/…): im Bild-Editor bearbeiten. SVG bleibt außen
      // vor (image && editable) — es läuft über den Texteditor.
      if (entry.image && !entry.editable && sel === 1 && files.can('write')) {
        items.push({ icon: 'mdi-image-edit-outline', label: t('ctx.editImage'), action: () => files.openImageEditor(entry) })
      }
    }
    if (entry.type === 'file' && sel === 1) {
      items.push({ icon: 'mdi-download', label: t('ctx.download'), action: () => files.download(entry) })
    }
    // LLM-Content-Qualität: für einzelne Markdown-Dateien immer angeboten, damit
    // die Funktion auffindbar ist. Fehlt der KI-Schlüssel, meldet sich requireAi()
    // mit einem Hinweis und bietet die Konfiguration an.
    if (sel === 1 && isMarkdown(entry)) {
      items.push({
        icon: 'mdi-text-search',
        label: t('ctx.contentQuality'),
        action: async () => {
          if (await requireAi()) auditContent.check(entry.id, entry.name, locale.value)
        },
      })
      // Schnellweg: direkt von der KI verbessern lassen (ohne vorherigen Bericht).
      items.push({
        icon: 'mdi-creation',
        label: t('contentQuality.improve'),
        action: async () => {
          if (await requireAi()) assistant.improve(entry.id, locale.value)
        },
      })
    }
    if (items.length) items.push({ divider: true })
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
    if (files.can('upload')) items.push({ icon: 'mdi-upload-outline', label: t('ctx.upload'), action: () => pickUpload() })
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
  run(() => files.moveToTrash([...files.selectedIds]))
}

// --- Upload (Dateiauswahl + Drag-and-Drop) --------------------------------

const uploadInput = ref(null)
const dragOver = ref(false)

function pickUpload() {
  uploadInput.value?.click()
}

function onPicked(event) {
  const list = event.target.files
  if (list?.length) run(() => files.upload(list))
  event.target.value = '' // gleiche Auswahl erneut möglich
}

function onDragOver(event) {
  if (!files.can('upload') || !event.dataTransfer?.types?.includes('Files')) return
  event.preventDefault()
  dragOver.value = true
}

function onDragLeave(event) {
  // Nur beim Verlassen des Inhaltsbereichs (nicht beim Wandern über Kinder).
  if (event.currentTarget === event.target || !event.currentTarget.contains(event.relatedTarget)) {
    dragOver.value = false
  }
}

function onDrop(event) {
  if (!files.can('upload')) return
  event.preventDefault()
  dragOver.value = false
  const list = event.dataTransfer?.files
  if (list?.length) run(() => files.upload(list))
}

// Anfragen der Toolbar (Neuer Ordner / Neue Datei / Hochladen) konsumieren.
watch(
  () => files.newRequest,
  (kind) => {
    if (!kind) return
    if (kind === 'upload') pickUpload()
    else openNew(kind)
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
      <v-icon icon="mdi-folder" size="64" class="nemo-empty-icon" />
      <p>{{ $t('files.chooseMount') }}</p>
    </div>

    <template v-else>
      <v-alert v-if="error" type="error" density="compact" class="ma-2 nemo-alert" tile closable @click:close="error = null">
        {{ error }}
      </v-alert>

      <div
        class="nemo-content nemo-scroll nemo-noselect"
        :class="{ dragover: dragOver }"
        @click.self="files.clearSelection()"
        @contextmenu.self.prevent="openMenu(null, $event)"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
      >
        <div v-if="dragOver" class="nemo-drop-overlay">
          <v-icon icon="mdi-tray-arrow-down" size="44" />
          <span>{{ $t('dnd.dropHint') }}</span>
        </div>

        <!-- Suchergebnisse (Serversuche, Stufe 4) -->
        <template v-if="files.searchQuery">
          <div class="nemo-search-head nemo-noselect">
            <v-icon icon="mdi-magnify" size="18" />
            <span>{{ $t('search.resultsFor', [files.searchQuery]) }}</span>
            <span v-if="files.searchTruncated" class="nemo-search-trunc">
              {{ $t('search.truncated', [files.searchResults.length]) }}
            </span>
            <div style="flex: 1 1 auto" />
            <button class="nemo-search-leave" @click="files.leaveSearch()">
              <v-icon icon="mdi-close" size="16" class="mr-1" />{{ $t('search.leave') }}
            </button>
          </div>

          <div v-if="files.searchResults.length === 0" class="nemo-empty">
            <v-icon icon="mdi-file-search-outline" size="56" class="nemo-empty-icon" />
            <p>{{ $t('search.none', [files.searchQuery]) }}</p>
          </div>

          <table v-else class="nemo-list">
            <thead>
              <tr>
                <th class="col-name">{{ $t('files.colName') }}</th>
                <th>{{ $t('search.colPath') }}</th>
                <th class="col-size">{{ $t('files.colSize') }}</th>
                <th class="col-date">{{ $t('files.colModified') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="entry in files.searchResults"
                :key="entry.id"
                class="nemo-row"
                :class="{ selected: files.isSelected(entry.id) }"
                @click="files.selectOnly(entry.id)"
                @dblclick="onOpen(entry)"
              >
                <td class="col-name">
                  <div class="nemo-name-cell">
                    <img v-if="entry.image" :src="files.thumbUrl(entry, 64)" class="nemo-row-thumb" loading="lazy" alt="" />
                    <v-icon v-else :icon="iconFor(entry)" size="18" class="nemo-row-icon" />
                    <span class="nemo-row-name">{{ entry.name }}</span>
                  </div>
                </td>
                <td class="col-path">{{ entry.path }}</td>
                <td class="col-size">{{ entry.type === 'dir' ? '' : formatSize(entry.size) }}</td>
                <td class="col-date">{{ formatDate(entry.mtime) }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <div v-else-if="entries.length === 0" class="nemo-empty" @contextmenu.prevent="openMenu(null, $event)">
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
              <th class="col-created">{{ $t('files.colCreated') }}</th>
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
                <div class="nemo-name-cell">
                  <img
                    v-if="entry.image"
                    :src="files.thumbUrl(entry, 64)"
                    class="nemo-row-thumb"
                    loading="lazy"
                    alt=""
                  />
                  <v-icon v-else :icon="iconFor(entry)" size="18" class="nemo-row-icon" />
                  <span class="nemo-row-name">{{ entry.name }}</span>
                </div>
              </td>
              <td class="col-size">{{ entry.type === 'dir' ? '' : formatSize(entry.size) }}</td>
              <td class="col-type">{{ typeLabel(entry) }}</td>
              <td class="col-created">{{ formatDate(entry.ctime) }}</td>
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
            <img
              v-if="entry.image"
              :src="files.thumbUrl(entry, 256)"
              class="nemo-tile-thumb"
              loading="lazy"
              alt=""
            />
            <v-icon v-else :icon="iconFor(entry)" size="48" class="nemo-tile-icon" />
            <span class="nemo-tile-name">{{ entry.name }}</span>
          </button>
        </div>
      </div>

      <!-- Statusleiste -->
      <footer class="nemo-statusbar nemo-noselect">
        <span v-if="files.searchQuery">
          {{ files.searchResults.length === 1 ? $t('status.itemOne') : $t('status.items', [files.searchResults.length]) }}
        </span>
        <span v-else>
          {{ files.entries.length === 1 ? $t('status.itemOne') : $t('status.items', [files.entries.length]) }}
        </span>
        <span v-if="files.selectedCount" class="nemo-status-sel">· {{ $t('status.selected', [files.selectedCount]) }}</span>
        <span v-if="files.filter && !files.searchQuery" class="nemo-status-sel">· {{ entries.length }}/{{ files.entries.length }}</span>
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

    <!-- Verstecktes Upload-Feld (Toolbar/Kontextmenü lösen es aus) -->
    <input ref="uploadInput" type="file" multiple class="nemo-upload-input" @change="onPicked" />

    <!-- Bildbetrachter -->
    <ImageViewer />

    <!-- Bild-Editor -->
    <ImageEditor />
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
  position: relative;
}

/* Ablegezone für Drag-and-Drop-Upload */
.nemo-content.dragover {
  outline: 2px dashed var(--mint-green);
  outline-offset: -6px;
  background: var(--mint-green-soft);
}
.nemo-drop-overlay {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 100%;
  color: var(--mint-green-dark);
  font-weight: 600;
  pointer-events: none;
}

.nemo-upload-input {
  display: none;
}

/* Suchergebnis-Kopfzeile */
.nemo-search-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background: var(--mint-green-soft);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-green-soft-text);
  font-size: 0.85rem;
  font-weight: 600;
}
.nemo-search-trunc {
  font-weight: 400;
  color: var(--mint-text-muted);
}
.nemo-search-leave {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px 10px;
  font-size: 0.82rem;
  color: var(--mint-text);
  cursor: pointer;
}
.nemo-search-leave:hover {
  background: var(--mint-panel-hover);
}
.col-path {
  color: var(--mint-text-muted);
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
.col-created { width: 160px; }
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
.nemo-row.selected .col-created,
.nemo-row.selected .col-date { color: #fff; }
.nemo-row.cut { opacity: 0.5; }
/* Flex-Layout für Symbol + Name liegt auf einem inneren Wrapper, NICHT auf der
   Tabellenzelle selbst: ein <td>/<th> mit display:flex fällt aus dem
   Tabellen-Layout und wird anders hoch berechnet als die übrigen Zellen —
   das versetzte die Namensspalte gegenüber der Kopfzeile. */
.nemo-name-cell { display: flex; align-items: center; gap: 8px; min-width: 0; }
.nemo-row-name { overflow: hidden; text-overflow: ellipsis; }
.nemo-row-icon { color: #6f8f63; flex: 0 0 auto; }
.nemo-row-thumb {
  width: 22px;
  height: 22px;
  object-fit: cover;
  border-radius: 2px;
  flex: 0 0 auto;
}
.col-size, .col-type, .col-created, .col-date { color: var(--mint-text-muted); }

/* Schmale Schirme: platzraubende Spalten ausblenden und Zeilen für die
   Bedienung per Finger etwas höher machen. */
@media (max-width: 959.98px) {
  .nemo-row td { padding-top: 9px; padding-bottom: 9px; }
}
@media (max-width: 720px) {
  .col-type, .col-created, .col-path { display: none; }
}
@media (max-width: 560px) {
  .col-date { display: none; }
}

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
.nemo-tile-thumb {
  width: 72px;
  height: 72px;
  object-fit: cover;
  border-radius: 3px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
}
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
