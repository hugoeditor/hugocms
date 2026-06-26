import { defineStore } from 'pinia'
import { api } from '../api/client'

// SEO-Audit (Pro-Funktion). Spricht die audit/auditlist/auditget/auditdelete-
// Befehle des Connectors an. Ein Lauf analysiert den gebauten public/-Ordner
// und die Hugo-Quellen und liefert einen Bericht mit Funden je Kategorie und
// Schweregrad. Die Berichte werden serverseitig als Verlauf vorgehalten.
export const useAuditStore = defineStore('audit', {
  state: () => ({
    runs: [], // Metadaten gespeicherter Läufe (neueste zuerst)
    current: null, // vollständiger Bericht des angezeigten Laufs
    running: false, // läuft gerade ein Audit?
    loading: false, // Bericht/Liste wird geladen
    severityFilter: 'all', // 'all' | 'error' | 'warning' | 'hint'
    categoryFilter: 'all', // 'all' | <Kategorie>
  }),

  getters: {
    // Kategorien des aktuellen Berichts in Berichtsreihenfolge.
    categories: (state) => (state.current ? Object.keys(state.current.byCategory || {}) : []),

    // Funde des aktuellen Berichts, gefiltert nach Schweregrad und Kategorie.
    filteredIssues(state) {
      const issues = state.current?.issues ?? []
      return issues.filter(
        (i) =>
          (state.severityFilter === 'all' || i.severity === state.severityFilter) &&
          (state.categoryFilter === 'all' || i.category === state.categoryFilter),
      )
    },
  },

  actions: {
    // Neuen Lauf starten; der vollständige Bericht kommt direkt zurück.
    async runAudit() {
      this.running = true
      try {
        const report = await api.post('audit')
        this.current = report
        this.resetFilters()
        await this.fetchRuns()
        return report
      } finally {
        this.running = false
      }
    },

    async fetchRuns() {
      const data = await api.get('auditlist')
      this.runs = data.runs ?? []
    },

    async fetchRun(id) {
      this.loading = true
      try {
        this.current = await api.get('auditget', { id })
        this.resetFilters()
      } finally {
        this.loading = false
      }
    },

    async deleteRun(id) {
      await api.post('auditdelete', { id })
      if (this.current?.id === id) this.current = null
      await this.fetchRuns()
    },

    resetFilters() {
      this.severityFilter = 'all'
      this.categoryFilter = 'all'
    },

    setSeverityFilter(value) {
      this.severityFilter = value
    },

    setCategoryFilter(value) {
      this.categoryFilter = this.categoryFilter === value ? 'all' : value
    },
  },
})
