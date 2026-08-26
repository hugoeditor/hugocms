import { defineStore } from 'pinia'
import { api } from '../api/client'

// Git-Versionierung (Pro-Funktion). Spricht die gitstatus/gitlog/gitdiff/
// gitcommit/gitpush/gitreset-Befehle des Connectors an. Das Repository ist das
// Hugo-Projektverzeichnis der Webseite; der Server sperrt alle Aufrufe darauf ein.
export const useRepoStore = defineStore('repo', {
  state: () => ({
    // { branch, clean, nextTag, entries[] }; entries: [{ path, status, from }] mit
    // status modified|added|deleted|renamed|untracked|conflict (from nur bei
    // renamed). nextTag ist die vorgeschlagene nächste Versionsnummer.
    status: null,
    commits: [], // [{ sha, shortSha, author, email, date, tags[], message }]
    total: 0, // Gesamtzahl der Commits
    page: 1,
    perPage: 20,
    // { sha, message, diff } des ausgewählten Commits. message ist die
    // VOLLSTÄNDIGE Beschreibung; die Verlaufsliste kennt nur deren erste Zeile.
    diff: null,
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
    // tag ist die Versionsnummer des Standes (annotiertes Git-Tag); leer heißt
    // „ohne Versionsnummer sichern“. tagLabel ist das Wort, das im
    // Änderungsprotokoll vor der Nummer steht („Ausgabe“ / „Edition“) — wie die
    // Beschreibungen sprachabhängig und deshalb von hier.
    async commit(message, tag = '', tagLabel = '') {
      return api.post('gitcommit', { message, tag, tagLabel })
    },

    async push() {
      return api.post('gitpush')
    },

    // Erzeugt das Änderungsprotokoll (changelog.md) neu — nur aus Ständen MIT
    // Versionsnummer. Schreibt die Datei; gesichert wird sie danach wie jede
    // andere offene Änderung.
    async rebuildChangelog(tagLabel = '') {
      return api.post('gitchangelog', { tagLabel })
    },

    async reset(ref = 'HEAD') {
      return api.post('gitreset', { ref })
    },

    // Was eine Wiederherstellung ändern würde — Einträge in derselben Form wie
    // im Status, damit die Vorschau dieselbe Darstellung nutzen kann.
    async restorePreview(sha) {
      return api.get('gitrestorepreview', { sha })
    },

    // Kehrt zu einem alten Stand zurück und sichert ihn als NEUEN Versionsstand.
    // Die Beschreibungen kommen von hier, weil sie sprachabhängig sind.
    async restore(sha, message, tag, presaveMessage, tagLabel = '') {
      return api.post('gitrestore', { sha, message, tag, presaveMessage, tagLabel })
    },

    // Holt EINE Datei zurück; sie erscheint als offene Änderung und wird über
    // das gewohnte Formular gesichert.
    async restoreFile(sha, path) {
      return api.post('gitrestorefile', { sha, path })
    },

    // Status, Verlauf und ggf. Diff nach einer Aktion auffrischen.
    async refresh() {
      await Promise.all([this.fetchStatus(), this.fetchLog(this.page)])
    },
  },
})
