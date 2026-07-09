import { defineStore } from 'pinia'
import { api } from '../api/client'

const VIEW_KEY = 'hugocms_view'

function readView() {
  try {
    const v = localStorage.getItem(VIEW_KEY)
    if (v === 'list' || v === 'icons') return v
  } catch {
    // ignorieren
  }
  return 'list'
}

export const useFilesStore = defineStore('files', {
  state: () => ({
    mounts: [],
    activeMount: null, // Name des aktiven Mounts
    cwd: null, // Metadaten des aktuellen Verzeichnisses
    entries: [], // Inhalt des aktuellen Verzeichnisses
    breadcrumb: [], // [{ id, name }] von der Mount-Wurzel bis cwd
    loading: false,

    view: readView(), // 'list' | 'icons'
    filter: '', // Schnellfilter (Namensteil) auf das aktuelle Verzeichnis

    selectedIds: [], // markierte Einträge (Mehrfachauswahl)
    selectionAnchor: null, // Anker für die Bereichsauswahl (Shift)
    clipboard: { mode: null, ids: [] }, // mode: 'copy' | 'cut' | null

    newRequest: null, // 'folder' | 'file' | 'upload' — von der Toolbar gesetzt, vom Browser konsumiert
    uploading: false, // läuft gerade ein Upload? (Fortschrittsanzeige)
    viewerId: null, // im Bildbetrachter geöffneter Eintrag (oder null)
    editImageId: null, // im Bild-Editor geöffneter Eintrag (oder null)

    // Serverseitige Suche (Stufe 4): aktiv, solange searchQuery gesetzt ist.
    searchQuery: '',
    searchResults: [],
    searchTruncated: false,

    // Papierkorb-Ansicht (Stufe 4).
    trashMode: false,
    trashEntries: [],
    trashSelected: [], // [{mount, trashName}]

    // SEO-Audit-Ansicht (Pro-Funktion). Wie trashMode eine Vollbild-Ansicht im
    // Hauptbereich; die eigentlichen Audit-Daten liegen im audit-Store.
    auditMode: false,

    // Navigationsverlauf (wie Nemo Zurück/Vor). Jeder Eintrag ist eine
    // Momentaufnahme { id, breadcrumb, activeMount }.
    history: [],
    historyIndex: -1,

    openFile: null, // { id, name, content, mtime, path } oder null
    dirty: false, // ungespeicherte Änderungen im Editor
    reloadTick: 0, // erhöht sich, wenn der offene Inhalt extern neu geladen wurde
  }),

  getters: {
    canBack: (s) => s.historyIndex > 0,
    canForward: (s) => s.historyIndex < s.history.length - 1,
    canUp: (s) => s.breadcrumb.length > 1,
    visibleEntries: (s) => {
      const q = s.filter.trim().toLowerCase()
      if (q === '') return s.entries
      return s.entries.filter((e) => e.name.toLowerCase().includes(q))
    },
    isSelected: (s) => (id) => s.selectedIds.includes(id),
    selectedCount: (s) => s.selectedIds.length,
    hasClipboard: (s) => s.clipboard.ids.length > 0,
    // Bilder des aktuellen Verzeichnisses (Reihenfolge = Anzeige) für den Betrachter.
    imageEntries: (s) => s.entries.filter((e) => e.image),
    viewerEntry(state) {
      return state.viewerId ? this.imageEntries.find((e) => e.id === state.viewerId) ?? null : null
    },
    viewerIndex(state) {
      return state.viewerId ? this.imageEntries.findIndex((e) => e.id === state.viewerId) : -1
    },
    // Im Bild-Editor geöffneter Eintrag (aus dem aktuellen Verzeichnis).
    editImageEntry: (s) => (s.editImageId ? s.entries.find((e) => e.id === s.editImageId) ?? null : null),
    // Rechte des aktiven Mounts (für Menü-/Toolbar-Aktivierung).
    can: (s) => (perm) => {
      const m = s.mounts.find((mm) => mm.name === s.activeMount)
      return m ? m.permissions.includes(perm) : false
    },
  },

  actions: {
    async loadMounts() {
      const data = await api.get('mounts')
      this.mounts = data.mounts
      // Wie Nemo direkt in einem Ort starten: den ersten Mount öffnen.
      if (!this.cwd && this.mounts.length > 0) {
        await this.openDir(this.mounts[0].id)
      }
    },

    async _list(id) {
      this.loading = true
      try {
        const data = await api.get('list', { target: id })
        this.cwd = data.cwd
        this.entries = data.entries
        this.selectedIds = []
        this.selectionAnchor = null
        this.filter = ''
        this.leaveSearch()
        return data
      } finally {
        this.loading = false
      }
    },

    async openDir(id, label = null) {
      this.trashMode = false
      this.auditMode = false
      const data = await this._list(id)

      const mount = this.mounts.find((m) => m.id === id)
      if (mount) {
        this.activeMount = mount.name
        this.breadcrumb = [{ id, name: mount.label }]
      } else {
        const existing = this.breadcrumb.findIndex((b) => b.id === id)
        if (existing >= 0) {
          this.breadcrumb = this.breadcrumb.slice(0, existing + 1)
        } else {
          this.breadcrumb.push({ id, name: label ?? data.cwd.name })
        }
      }
      this._pushHistory(id)
    },

    _pushHistory(id) {
      this.history = this.history.slice(0, this.historyIndex + 1)
      this.history.push({
        id,
        breadcrumb: this.breadcrumb.map((b) => ({ ...b })),
        activeMount: this.activeMount,
      })
      this.historyIndex = this.history.length - 1
    },

    async _restore(snapshot) {
      await this._list(snapshot.id)
      this.breadcrumb = snapshot.breadcrumb.map((b) => ({ ...b }))
      this.activeMount = snapshot.activeMount
    },

    async goBack() {
      if (!this.canBack) return
      this.historyIndex--
      await this._restore(this.history[this.historyIndex])
    },

    async goForward() {
      if (!this.canForward) return
      this.historyIndex++
      await this._restore(this.history[this.historyIndex])
    },

    async goUp() {
      if (!this.canUp) return
      await this.openDir(this.breadcrumb[this.breadcrumb.length - 2].id)
    },

    async refresh() {
      const current = this.history[this.historyIndex]
      if (current) await this._restore(current)
    },

    setView(view) {
      if (view !== 'list' && view !== 'icons') return
      this.view = view
      try {
        localStorage.setItem(VIEW_KEY, view)
      } catch {
        // ignorieren
      }
    },

    setFilter(value) {
      this.filter = value
    },

    requestNew(kind) {
      this.newRequest = kind
    },

    // --- Auswahl ---------------------------------------------------------

    selectOnly(id) {
      this.selectedIds = id == null ? [] : [id]
      this.selectionAnchor = id
    },

    toggleSelect(id) {
      const i = this.selectedIds.indexOf(id)
      if (i >= 0) this.selectedIds.splice(i, 1)
      else this.selectedIds.push(id)
      this.selectionAnchor = id
    },

    selectRange(id) {
      const list = this.visibleEntries.map((e) => e.id)
      const a = this.selectionAnchor ? list.indexOf(this.selectionAnchor) : 0
      const b = list.indexOf(id)
      if (a < 0 || b < 0) {
        this.selectOnly(id)
        return
      }
      const [lo, hi] = a <= b ? [a, b] : [b, a]
      this.selectedIds = list.slice(lo, hi + 1)
    },

    clearSelection() {
      this.selectedIds = []
      this.selectionAnchor = null
    },

    // --- Zwischenablage --------------------------------------------------

    copySelection() {
      if (this.selectedIds.length) this.clipboard = { mode: 'copy', ids: [...this.selectedIds] }
    },

    cutSelection() {
      if (this.selectedIds.length) this.clipboard = { mode: 'cut', ids: [...this.selectedIds] }
    },

    async paste() {
      if (!this.cwd || this.clipboard.ids.length === 0) return
      const { mode, ids } = this.clipboard
      const cmd = mode === 'cut' ? 'move' : 'copy'
      await api.post(cmd, { sources: ids, dest: this.cwd.id })
      if (mode === 'cut') this.clipboard = { mode: null, ids: [] }
      await this.refresh()
    },

    // --- Operationen (Stufe 2) ------------------------------------------

    async createFolder(name) {
      await api.post('mkdir', { target: this.cwd.id, name })
      await this.refresh()
    },

    async createFile(name) {
      await api.post('newfile', { target: this.cwd.id, name })
      await this.refresh()
    },

    async renameEntry(id, name) {
      await api.post('rename', { target: id, name })
      await this.refresh()
    },

    async trashEntries(ids) {
      if (!ids.length) return
      await api.post('delete', { targets: ids })
      await this.refresh()
    },

    // --- Stufe 4: Suche und Papierkorb ------------------------------------

    async search(query) {
      const q = query.trim()
      if (!this.cwd || q.length < 2) return
      const data = await api.get('search', { target: this.cwd.id, q })
      this.searchQuery = data.query
      this.searchResults = data.entries
      this.searchTruncated = data.truncated
      this.selectedIds = []
      this.selectionAnchor = null
    },

    leaveSearch() {
      this.searchQuery = ''
      this.searchResults = []
      this.searchTruncated = false
    },

    async openTrash() {
      this.trashMode = true
      this.auditMode = false
      this.leaveSearch()
      await this.loadTrash()
    },

    leaveTrash() {
      this.trashMode = false
      this.trashEntries = []
      this.trashSelected = []
    },

    // --- SEO-Audit-Ansicht ----------------------------------------------

    openAudit() {
      this.auditMode = true
      this.trashMode = false
      this.leaveSearch()
    },

    leaveAudit() {
      this.auditMode = false
    },

    // Öffnet eine Datei direkt über ihre (undurchsichtige) Dateimanager-ID —
    // genutzt vom Audit, um aus einem Fund zur Quelldatei zu springen. Der
    // Audit-Modus bleibt dabei aktiv, sodass der Editor sich nur überlagert und
    // beim Schließen der Bericht wieder erscheint (siehe AuditView.openSource).
    async openFileById(id) {
      const data = await api.get('read', { target: id })
      this.openFile = { id, name: data.name, content: data.content, mtime: data.mtime, path: data.path }
      this.dirty = false
    },

    async loadTrash() {
      const data = await api.get('trashlist')
      this.trashEntries = data.entries
      this.trashSelected = []
    },

    async restoreTrash(items) {
      // Je Mount bündeln (das Backend stellt mount-weise wieder her).
      const byMount = new Map()
      for (const it of items) {
        if (!byMount.has(it.mount)) byMount.set(it.mount, [])
        byMount.get(it.mount).push(it.trashName)
      }
      for (const [mount, names] of byMount) {
        await api.post('restore', { mount, names })
      }
      await this.loadTrash()
      if (this.cwd) await this.refresh()
    },

    async emptyTrash() {
      await api.post('emptytrash')
      await this.loadTrash()
    },

    // --- Stufe 3: Upload, Download, Bildbetrachter -----------------------

    async upload(fileList) {
      if (!this.cwd || !fileList?.length) return
      const form = new FormData()
      form.append('cmd', 'upload')
      form.append('target', this.cwd.id)
      for (const f of fileList) form.append('files[]', f)
      this.uploading = true
      try {
        await api.postForm(form)
      } finally {
        this.uploading = false
      }
      await this.refresh()
    },

    // Download über eine unsichtbare Navigation — der Browser speichert die
    // Antwort dank Content-Disposition: attachment, die App bleibt stehen.
    download(entry) {
      const a = document.createElement('a')
      a.href = api.url('download', { target: entry.id })
      a.download = entry.name
      document.body.appendChild(a)
      a.click()
      a.remove()
    },

    thumbUrl(entry, size = 256) {
      return api.url('thumb', { target: entry.id, size })
    },

    rawUrl(entry) {
      return api.url('raw', { target: entry.id })
    },

    openViewer(entry) {
      this.viewerId = entry.id
    },

    closeViewer() {
      this.viewerId = null
    },

    viewerStep(delta) {
      const list = this.imageEntries
      if (!list.length || this.viewerIndex < 0) return
      const next = (this.viewerIndex + delta + list.length) % list.length
      this.viewerId = list[next].id
    },

    // --- Bild-Editor (filerobot) ----------------------------------------

    openImageEditor(entry) {
      this.editImageId = entry.id
    },

    closeImageEditor() {
      this.editImageId = null
    },

    // Speichert ein bearbeitetes Rasterbild zurück. dataUrl ist die base64-
    // Ausgabe des Editors (data:image/…;base64,…). mode: 'overwrite' ersetzt
    // die Quelldatei, 'copy' legt unter name eine neue Datei an.
    async saveEditedImage(id, dataUrl, { mode = 'overwrite', name = null } = {}) {
      const body = { target: id, data: dataUrl, mode }
      if (mode === 'copy' && name) body.name = name
      const meta = await api.post('writeimage', body)
      await this.refresh()
      return meta
    },

    // --- Editor (Stufe 1) ------------------------------------------------

    async activate(entry) {
      if (entry.type === 'dir') {
        await this.openDir(entry.id, entry.name)
      } else if (entry.image) {
        this.openViewer(entry)
      } else if (entry.editable) {
        await this.openTextFile(entry)
      }
    },

    async openTextFile(entry) {
      const data = await api.get('read', { target: entry.id })
      this.openFile = { id: entry.id, name: data.name, content: data.content, mtime: data.mtime, path: data.path }
      this.dirty = false
    },

    // Lädt die offene Datei neu (z. B. nachdem der KI-Assistent sie geändert
    // hat) und stößt über reloadTick die Anzeige im Editor an. Nur wirksam,
    // wenn sich der Inhalt tatsächlich geändert hat.
    async reloadOpenFile() {
      if (!this.openFile) return
      const data = await api.get('read', { target: this.openFile.id })
      if (data.content === this.openFile.content) return
      this.openFile.content = data.content
      this.openFile.mtime = data.mtime
      this.dirty = false
      this.reloadTick++
    },

    async saveOpenFile(content, { force = false } = {}) {
      if (!this.openFile) return
      // Konfliktschutz: Die mtime des geladenen Standes geht mit; der Server
      // lehnt mit CONFLICT-MTIME ab, wenn die Datei seit dem Öffnen extern
      // geändert wurde. Per force=true wird ohne Prüfung überschrieben.
      const body = { target: this.openFile.id, content }
      if (!force) body.mtime = this.openFile.mtime
      const meta = await api.post('write', body)
      this.openFile.content = content
      this.openFile.mtime = meta.mtime
      this.dirty = false
      const row = this.entries.find((e) => e.id === meta.id)
      if (row) {
        row.size = meta.size
        row.mtime = meta.mtime
      }
    },

    // Legt den aktuellen Editor-Inhalt als Freigabe-Entwurf ab, statt ihn live
    // zu speichern (gestaffelte Veröffentlichung). Die Live-Datei bleibt
    // unverändert; der Entwurf wartet in der Warteschlange auf Freigabe.
    async saveAsDraft(content) {
      if (!this.openFile) return
      await api.post('reviewsave', { target: this.openFile.id, content })
      this.dirty = false
    },

    closeFile() {
      this.openFile = null
      this.dirty = false
    },
  },
})
