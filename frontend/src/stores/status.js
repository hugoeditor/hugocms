import { defineStore } from 'pinia'
import { api } from '../api/client'

// Systemstatus dieser Webseite: hinterlegte Zugänge, Lizenz, laufende
// Cron-Aufgaben und deren Arbeitsvorrat. Zwei getrennte Aufrufe — `status`
// liefert rein lokal ermittelte Angaben und ist sofort da, `statuscheck` fragt
// die Dienste tatsächlich und läuft nur auf Knopfdruck.
export const useStatusStore = defineStore('status', {
  state: () => ({
    open: false, // Overlay sichtbar
    data: null, // Antwort von `status`
    loading: false,
    error: null, // roher Fehler (die Ansicht übersetzt ihn)
    // Ergebnis von `statuscheck`: { ai|service|mail: {status, key, message} }.
    checks: null,
    checking: false,
  }),

  getters: {
    cron: (state) => state.data?.cron ?? [],
    scheduled: (state) => state.data?.tasks?.scheduled ?? [],
    improve: (state) => state.data?.tasks?.improve ?? [],
    // Anzahl Aufgaben, die auf einen Cron-Lauf warten — Abzeichen der Werkzeugschiene.
    pendingCount: (state) =>
      (state.data?.tasks?.scheduled?.length ?? 0) + (state.data?.tasks?.improve?.length ?? 0),
    // Etwas verlangt Aufmerksamkeit: eine überfällige oder zuletzt
    // fehlgeschlagene Cron-Aufgabe, oder ein Zugang, der sich nicht meldet.
    hasProblem: (state) =>
      (state.data?.cron ?? []).some((c) => c.seen && (c.overdue || !c.success)) ||
      Object.values(state.checks ?? {}).some((c) => c.status === 'error'),
  },

  actions: {
    async openView() {
      this.open = true
      await this.fetch()
    },

    close() {
      this.open = false
    },

    async fetch() {
      this.error = null
      this.loading = true
      try {
        this.data = await api.get('status')
      } catch (e) {
        this.error = e
      } finally {
        this.loading = false
      }
    },

    // Prüft die hinterlegten Zugänge gegen ihren Dienst. Ein einzelner
    // fehlgeschlagener Zugang ist kein Fehler des Aufrufs — er steht als
    // Ergebnis in `checks`.
    async check() {
      this.error = null
      this.checking = true
      try {
        this.checks = await api.post('statuscheck', {})
      } catch (e) {
        this.error = e
      } finally {
        this.checking = false
      }
    },
  },
})
