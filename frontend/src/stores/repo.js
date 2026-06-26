import { defineStore } from 'pinia'
import { api } from '../api/client'

// Git-Versionierung (Pro-Funktion). Spricht die gitstatus/gitlog/gitdiff/
// gitcommit/gitpush/gitreset-Befehle des Connectors an. Das Repository ist das
// Hugo-Projektverzeichnis der Webseite; der Server sperrt alle Aufrufe darauf ein.
export const useRepoStore = defineStore('repo', {
  state: () => ({
    status: null, // { branch, clean, modified[], untracked[] }
    commits: [], // [{ sha, shortSha, author, email, date, message }]
    total: 0, // Gesamtzahl der Commits
    page: 1,
    perPage: 20,
    diff: null, // { sha, diff } des ausgewählten Commits
    selectedSha: null,
  }),

  getters: {
    hasMore: (state) => state.page * state.perPage < state.total,
  },

  actions: {
    async fetchStatus() {
      this.status = await api.get('gitstatus')
    },

    async fetchLog(page = 1) {
      const data = await api.get('gitlog', { page, perPage: this.perPage })
      this.commits = data.commits ?? []
      this.total = data.total ?? 0
      this.page = data.page ?? page
    },

    async fetchDiff(sha) {
      this.selectedSha = sha
      this.diff = await api.get('gitdiff', { sha })
    },

    clearDiff() {
      this.selectedSha = null
      this.diff = null
    },

    // Schreibbefehle geben das Roh-Ergebnis zurück ({ success, output, ... }) —
    // ein fehlgeschlagener Git-Vorgang ist kein API-Fehler, sondern Inhalt.
    async commit(message) {
      return api.post('gitcommit', { message })
    },

    async push() {
      return api.post('gitpush')
    },

    async reset(ref = 'HEAD') {
      return api.post('gitreset', { ref })
    },

    // Status, Verlauf und ggf. Diff nach einer Aktion auffrischen.
    async refresh() {
      await Promise.all([this.fetchStatus(), this.fetchLog(this.page)])
    },
  },
})
