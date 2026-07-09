import { defineStore } from 'pinia'
import { api } from '../api/client'

// Freigabe-Warteschlange der gestaffelten Veröffentlichung. Offene Entwürfe
// (von der KI im Modus auto, vom Cron oder manuell über den Entwurf-Button
// abgelegt) werden hier gelistet, im Diff gegen den Live-Stand geprüft und dann
// freigegeben (optional mit Veröffentlichungsdatum) oder verworfen. Serverseitig
// liegen die Entwürfe unter var/review/; die Live-Datei bleibt bis zur Freigabe
// unangetastet.
export const useReviewStore = defineStore('review', {
  state: () => ({
    drafts: [], // offene Entwürfe (Metadaten, neueste zuerst)
    current: null, // im Diff geöffneter Entwurf inkl. proposedContent + original
    queueOpen: false, // Warteschlangen-Overlay sichtbar
    dialogOpen: false, // Diff-Ansicht eines einzelnen Entwurfs sichtbar
    listLoading: false, // die Liste wird geladen
    loading: false, // ein Entwurf wird geladen
    busy: false, // Freigabe/Verwerfen läuft
    error: null, // roher Fehler des letzten Aufrufs (Ansicht übersetzt ihn)
  }),

  getters: {
    count: (state) => state.drafts.length,
  },

  actions: {
    // Öffnet die Warteschlange und lädt die offenen Entwürfe.
    async openQueue() {
      this.queueOpen = true
      this.error = null
      this.listLoading = true
      try {
        await this.fetch()
      } catch (e) {
        this.error = e
      } finally {
        this.listLoading = false
      }
    },

    closeQueue() {
      this.queueOpen = false
      this.closeDialog()
    },

    // Lädt die Liste offener Entwürfe.
    async fetch() {
      const data = await api.get('reviewlist')
      this.drafts = data.drafts ?? []
    },

    // Öffnet einen Entwurf im Diff-Dialog (lädt Vorschlag + aktuellen Live-Stand).
    async open(key) {
      this.dialogOpen = true
      this.loading = true
      this.error = null
      this.current = null
      try {
        this.current = await api.get('reviewget', { key })
      } catch (e) {
        this.error = e
      } finally {
        this.loading = false
      }
    },

    // Gibt einen Entwurf frei: schreibt ihn live, setzt draft:false und optional
    // publishDate (ISO 8601, leer = sofort). Liefert true bei Erfolg.
    async approve(key, publishDate = '', force = false) {
      this.busy = true
      this.error = null
      try {
        await api.post('reviewapprove', { key, publishDate, force })
        await this.fetch()
        if (this.current?.key === key) this.closeDialog()
        return true
      } catch (e) {
        this.error = e
        return false
      } finally {
        this.busy = false
      }
    },

    // Verwirft einen Entwurf; die Live-Datei bleibt unberührt.
    async discard(key) {
      this.busy = true
      this.error = null
      try {
        await api.post('reviewdiscard', { key })
        await this.fetch()
        if (this.current?.key === key) this.closeDialog()
        return true
      } catch (e) {
        this.error = e
        return false
      } finally {
        this.busy = false
      }
    },

    closeDialog() {
      this.dialogOpen = false
      this.current = null
      this.error = null
    },
  },
})
