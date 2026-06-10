import { defineStore } from 'pinia'
import { api } from '../api/client'

export const useFilesStore = defineStore('files', {
  state: () => ({
    mounts: [],
    activeMount: null,   // Name des aktiven Mounts
    cwd: null,           // Metadaten des aktuellen Verzeichnisses
    entries: [],         // Inhalt des aktuellen Verzeichnisses
    breadcrumb: [],      // [{ id, name }] von der Mount-Wurzel bis cwd
    loading: false,

    openFile: null,      // { id, name, content, mtime } oder null
    dirty: false,        // ungespeicherte Änderungen im Editor
  }),

  actions: {
    async loadMounts() {
      const data = await api.get('mounts')
      this.mounts = data.mounts
    },

    async openDir(id, label = null) {
      this.loading = true
      try {
        const data = await api.get('list', { target: id })
        this.cwd = data.cwd
        this.entries = data.entries

        const mount = this.mounts.find((m) => m.id === id)
        if (mount) {
          // Mount-Wurzel: Brotkrumen zurücksetzen
          this.activeMount = mount.name
          this.breadcrumb = [{ id, name: mount.label }]
        } else {
          // Unterverzeichnis: an die Brotkrumen anhängen oder abschneiden
          const existing = this.breadcrumb.findIndex((b) => b.id === id)
          if (existing >= 0) {
            this.breadcrumb = this.breadcrumb.slice(0, existing + 1)
          } else {
            this.breadcrumb.push({ id, name: label ?? this.cwd.name })
          }
        }
      } finally {
        this.loading = false
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
