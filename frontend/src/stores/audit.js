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
    // 'all' | 'error' | 'warning' | 'hint' | 'ignored'. „ignored" ist ein
    // eigener Wert und kein zusätzlicher Schalter: Die ignorierten Funde sind
    // ein Blick FÜR SICH — in jeder anderen Ansicht laufen sie am Ende der
    // Liste mit (siehe filteredIssues).
    severityFilter: 'all',
    categoryFilter: 'all', // 'all' | <Kategorie>
    // PageSpeed-Check (Pro, eigener Reiter). Misst die konfigurierte Live-Adresse
    // über Google PageSpeed Insights. Je Strategie wird das jüngste Ergebnis
    // vorgehalten (kein Verlauf); der Umschalter wählt, welches angezeigt wird.
    pageSpeedResults: { mobile: null, desktop: null }, // je Strategie das letzte Ergebnis oder null
    pageSpeedRunning: false, // läuft gerade eine Messung?
    pageSpeedStrategy: 'mobile', // angezeigte/zu messende Strategie: 'mobile' | 'desktop'
  }),

  getters: {
    // Ignorierte Funde des angezeigten Berichts. Der Server markiert sie beim
    // Ausliefern (`ignored: true`) und rechnet sie aus Zusammenfassung und
    // Kategoriezählern heraus; hier zählt nur, wie viele es sind — für den
    // Filter-Chip.
    ignoredCount: (state) =>
      state.current?.ignoredCount ?? (state.current?.issues ?? []).filter((i) => i.ignored).length,

    // Angezeigtes PageSpeed-Ergebnis: das der gerade gewählten Strategie (oder
    // null, wenn dafür noch keine Messung vorliegt).
    pageSpeed: (state) => state.pageSpeedResults[state.pageSpeedStrategy] ?? null,

    // Kategorien des aktuellen Berichts in Berichtsreihenfolge.
    categories: (state) => (state.current ? Object.keys(state.current.byCategory || {}) : []),

    // Funde des aktuellen Berichts, gefiltert nach Schweregrad und Kategorie.
    // Sortiert nach Schweregrad: zuerst Fehler, dann Warnungen, dann Hinweise.
    // Die Sortierung ist stabil, daher bleibt innerhalb eines Schweregrads die
    // ursprüngliche Berichtsreihenfolge erhalten.
    filteredIssues(state) {
      const issues = state.current?.issues ?? []
      const rank = { error: 0, warning: 1, hint: 2 }
      // Ignorierte Funde sortieren sich hinter alles andere. Sie verschwinden
      // nicht aus der Liste — eine Entscheidung, die man nicht mehr sieht, lässt
      // sich auch nicht mehr zurücknehmen.
      const key = (i) => (i.ignored ? 100 : (rank[i.severity] ?? 99))
      const onlyIgnored = state.severityFilter === 'ignored'
      return issues
        .filter(
          (i) =>
            (onlyIgnored
              ? i.ignored
              : state.severityFilter === 'all' || i.severity === state.severityFilter) &&
            (state.categoryFilter === 'all' || i.category === state.categoryFilter),
        )
        .sort((a, b) => key(a) - key(b))
    },
  },

  actions: {
    // Funde dauerhaft ignorieren oder wieder aufnehmen. Die Vormerkung gilt je
    // Webseite und überlebt neue Läufe; der Server liefert den neu gerechneten
    // Bericht gleich zurück (Zusammenfassung und Kategoriezähler ohne die
    // ignorierten Funde), sodass kein zweiter Abruf nötig ist.
    async setIgnored(keys, ignored) {
      if (!keys.length) return
      const res = await api.post('auditignore', {
        keys,
        ignored,
        runId: this.current?.id ?? undefined,
      })
      if (res.report) this.current = markRaw(res.report)
      // Wurde der letzte ignorierte Fund wieder aufgenommen, führt der Filter
      // „Ignoriert" auf eine leere Liste — und sein Chip ist mit dem letzten
      // Eintrag verschwunden. Also zurück auf die vollständige Ansicht.
      if (this.severityFilter === 'ignored' && this.ignoredCount === 0) {
        this.severityFilter = 'all'
      }
      // Dasselbe für die Kategorie: Sie fällt aus dem Bericht, sobald ihr
      // letzter nicht ignorierter Fund weg ist — ein Filter auf eine Kategorie
      // ohne Chip wäre eine Sackgasse.
      if (this.categoryFilter !== 'all' && !this.categories.includes(this.categoryFilter)) {
        this.categoryFilter = 'all'
      }
      // Der Verlauf nennt je Lauf die Fundzahlen — die haben sich mitgeändert.
      await this.fetchRuns()
      return res
    },

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

    // Zuletzt gespeicherte PageSpeed-Ergebnisse dieser Webseite laden — je
    // Strategie eines (oder null). Für die Anzeige beim Öffnen des Panels, ohne
    // neu zu messen.
    async fetchPageSpeed() {
      const data = await api.get('pagespeedlatest')
      this.pageSpeedResults = {
        mobile: data.mobile ? markRaw(data.mobile) : null,
        desktop: data.desktop ? markRaw(data.desktop) : null,
      }
      return data
    },

    // PageSpeed-Messung der eingegebenen Live-Adresse starten. Die Adresse wird
    // serverseitig pro Webseite gespeichert (Mount-Konfiguration); die Strategie
    // (mobile/desktop) kommt aus dem Zustand. Das Ergebnis wird der jeweiligen
    // Strategie zugeordnet, damit Mobil- und Desktop-Bericht nebeneinander
    // bestehen bleiben.
    async runPageSpeed(url, strategy, locale) {
      const s = strategy || this.pageSpeedStrategy
      this.pageSpeedStrategy = s
      this.pageSpeedRunning = true
      try {
        // locale steuert die Sprache der Optimierungs-Chancen (Lighthouse-Texte).
        const result = await api.post('pagespeed', { url, strategy: s, locale })
        this.pageSpeedResults[s] = markRaw(result)
        return result
      } finally {
        this.pageSpeedRunning = false
      }
    },

    // Beide Strategien nacheinander messen (die API liefert je Aufruf nur eine).
    // Die Anzeige folgt der jeweils laufenden Messung; am Ende wird die zuvor
    // gewählte Strategie wieder eingestellt.
    async runPageSpeedBoth(url, locale) {
      const original = this.pageSpeedStrategy
      this.pageSpeedRunning = true
      try {
        for (const s of ['mobile', 'desktop']) {
          this.pageSpeedStrategy = s
          const result = await api.post('pagespeed', { url, strategy: s, locale })
          this.pageSpeedResults[s] = markRaw(result)
        }
      } finally {
        this.pageSpeedStrategy = original
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
