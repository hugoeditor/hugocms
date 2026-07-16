import { markRaw } from 'vue'
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
    // PageSpeed-Check (Pro, eigener Reiter). Misst die konfigurierte Live-Adresse
    // über Google PageSpeed Insights; das Ergebnis wird nicht als Verlauf
    // vorgehalten (immer der jüngste Lauf).
    pageSpeed: null, // letztes Ergebnis (Scores + Kern-Web-Vitalwerte) oder null
    pageSpeedRunning: false, // läuft gerade eine Messung?
    pageSpeedStrategy: 'mobile', // 'mobile' | 'desktop'
  }),

  getters: {
    // Kategorien des aktuellen Berichts in Berichtsreihenfolge.
    categories: (state) => (state.current ? Object.keys(state.current.byCategory || {}) : []),

    // Funde des aktuellen Berichts, gefiltert nach Schweregrad und Kategorie.
    // Sortiert nach Schweregrad: zuerst Fehler, dann Warnungen, dann Hinweise.
    // Die Sortierung ist stabil, daher bleibt innerhalb eines Schweregrads die
    // ursprüngliche Berichtsreihenfolge erhalten.
    filteredIssues(state) {
      const issues = state.current?.issues ?? []
      const rank = { error: 0, warning: 1, hint: 2 }
      return issues
        .filter(
          (i) =>
            (state.severityFilter === 'all' || i.severity === state.severityFilter) &&
            (state.categoryFilter === 'all' || i.category === state.categoryFilter),
        )
        .sort((a, b) => (rank[a.severity] ?? 99) - (rank[b.severity] ?? 99))
    },
  },

  actions: {
    // Neuen Lauf starten; der vollständige Bericht kommt direkt zurück.
    async runAudit() {
      this.running = true
      try {
        const report = await api.post('audit')
        // markRaw: Der Bericht ist reine Anzeige (bis zu Tausende Funde). Ohne
        // tiefe Reaktivität entfällt der Proxy-Aufwand beim Filtern/Rendern.
        this.current = markRaw(report)
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
        this.current = markRaw(await api.get('auditget', { id }))
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

    // Alle Läufe bis auf den zuletzt erzeugten löschen (Verlauf aufräumen).
    async deleteOtherRuns() {
      const res = await api.post('auditdeleteothers')
      await this.fetchRuns()
      // War der angezeigte Lauf unter den gelöschten, auf den behaltenen
      // (jüngsten) umschalten.
      if (this.runs.length && (!this.current || !this.runs.some((r) => r.id === this.current.id))) {
        await this.fetchRun(this.runs[0].id)
      }
      return res
    },

    // Zuletzt gespeichertes PageSpeed-Ergebnis dieser Webseite laden (oder null).
    // Für die Anzeige beim Öffnen des Panels, ohne neu zu messen.
    async fetchPageSpeed() {
      const data = await api.get('pagespeedlatest')
      if (data.result) this.pageSpeed = markRaw(data.result)
      return data.result
    },

    // PageSpeed-Messung der eingegebenen Live-Adresse starten. Die Adresse wird
    // serverseitig pro Webseite gespeichert (Mount-Konfiguration); die Strategie
    // (mobile/desktop) kommt aus dem Zustand. Das reduzierte Ergebnis kommt
    // direkt zurück und wird angezeigt.
    async runPageSpeed(url, strategy) {
      if (strategy) this.pageSpeedStrategy = strategy
      this.pageSpeedRunning = true
      try {
        const result = await api.post('pagespeed', { url, strategy: this.pageSpeedStrategy })
        this.pageSpeed = markRaw(result)
        return result
      } finally {
        this.pageSpeedRunning = false
      }
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
