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
    selectedId: null, // markierter Eintrag (Einfachklick)
    filter: '', // Schnellfilter (Namensteil) auf das aktuelle Verzeichnis

    // Navigationsverlauf (wie Nemo Zurück/Vor). Jeder Eintrag ist eine
    // Momentaufnahme { id, breadcrumb, activeMount }.
    history: [],
    historyIndex: -1,

    openFile: null, // { id, name, content, mtime } oder null
    dirty: false, // ungespeicherte Änderungen im Editor
  }),

  getters: {
    canBack: (s) => s.historyIndex > 0,
    canForward: (s) => s.historyIndex < s.history.length - 1,
    canUp: (s) => s.breadcrumb.length > 1,
    // Nach dem Schnellfilter sichtbare Einträge.
    visibleEntries: (s) => {
      const q = s.filter.trim().toLowerCase()
      if (q === '') return s.entries
      return s.entries.filter((e) => e.name.toLowerCase().includes(q))
    },
  },

  actions: {
    async loadMounts() {
      const data = await api.get('mounts')
      this.mounts = data.mounts
    },

    // Listet ein Verzeichnis und setzt cwd/entries; Auswahl und Filter zurück.
    async _list(id) {
      this.loading = true
      try {
        const data = await api.get('list', { target: id })
        this.cwd = data.cwd
        this.entries = data.entries
        this.selectedId = null
        this.filter = ''
        return data
      } finally {
        this.loading = false
      }
    },

    // Navigation durch Klick (Mount, Unterordner, Brotkrume): baut die
    // Brotkrumen fort und legt eine Verlaufs-Momentaufnahme an.
    async openDir(id, label = null) {
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
      // Vorwärts-Verlauf ab der aktuellen Position verwerfen.
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
      const parent = this.breadcrumb[this.breadcrumb.length - 2]
      await this.openDir(parent.id)
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

    select(id) {
      this.selectedId = id
    },

    setFilter(value) {
      this.filter = value
    },

    // Doppelklick/Enter: Ordner öffnen oder Textdatei in den Editor laden.
    async activate(entry) {
      if (entry.type === 'dir') {
        await this.openDir(entry.id, entry.name)
      } else if (entry.editable) {
        await this.openTextFile(entry)
      }
    },

    async openTextFile(entry) {
      const data = await api.get('read', { target: entry.id })
      this.openFile = { id: entry.id, name: data.name, content: data.content, mtime: data.mtime }
      this.dirty = false
    },

    async saveOpenFile(content) {
      if (!this.openFile) return
      const meta = await api.post('write', { target: this.openFile.id, content })
      this.openFile.content = content
      this.openFile.mtime = meta.mtime
      this.dirty = false
      // Größe/Änderungsdatum in der Liste auffrischen
      const row = this.entries.find((e) => e.id === meta.id)
      if (row) {
        row.size = meta.size
        row.mtime = meta.mtime
      }
    },

    closeFile() {
      this.openFile = null
      this.dirty = false
    },
  },
})
