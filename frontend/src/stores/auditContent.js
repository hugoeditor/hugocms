import { defineStore } from 'pinia'
import { api } from '../api/client'

// LLM-Content-Qualität (Pro-Funktion, braucht KI-Schlüssel). Prüft EINE
// Content-Datei auf Abruf über die auditcontent*-Befehle des Connectors und
// hält die bereits geprüften Seiten als Liste vor (serverseitig unter
// var/audit-content/ gespeichert). Ein Prüflauf ist ein einzelner LLM-Aufruf;
// das Ergebnis (Score, Lesbarkeit, Funde, Vorschläge) erscheint im Dialog.
export const useAuditContentStore = defineStore('auditContent', {
  state: () => ({
    checked: [], // Metadaten bereits geprüfter Seiten (neueste zuerst)
    current: null, // Gesamt-Bericht im Dialog: { file, contentQuality, audit }
    dialogOpen: false,
    busy: false, // ein Prüflauf läuft (LLM-Aufruf)
    loading: false, // ein gespeicherter Bericht wird geladen
    error: null, // roher Fehler des letzten Aufrufs (Dialog übersetzt ihn)
    fileName: '', // Name der geprüften/angezeigten Datei (Dialogtitel)
  }),

  actions: {
    // Prüft eine Datei per LLM und zeigt den Gesamt-Bericht im Dialog. Fehler
    // werden im Dialog angezeigt (nicht geworfen), damit der Aufrufer nichts
    // weiter behandeln muss.
    async check(fileId, fileName = '', locale = 'de') {
      this.dialogOpen = true
      this.busy = true
      this.error = null
      this.current = null
      this.fileName = fileName
      try {
        const entry = await api.post('auditcontent', { id: fileId, locale })
        await this.loadReport(entry.key, entry.title || fileName)
        await this.fetchChecked()
      } catch (e) {
        this.error = e
      } finally {
        this.busy = false
      }
    },

    // Lädt die Liste der bereits geprüften Seiten.
    async fetchChecked() {
      const data = await api.get('auditcontentlist')
      this.checked = data.pages ?? []
    },

    // Lädt den Gesamt-Bericht (Qualitätsurteil + zugehörige SEO-Funde) einer
    // geprüften Seite in den Dialog.
    async loadReport(key, fileName = '') {
      this.current = await api.get('auditcontentreport', { key })
      this.fileName = this.current.contentQuality?.title || this.current.file?.title || fileName
    },

    // Öffnet den gespeicherten Gesamt-Bericht im Dialog (aus der Liste heraus).
    async openResult(key, fileName = '') {
      this.dialogOpen = true
      this.loading = true
      this.error = null
      this.current = null
      this.fileName = fileName
      try {
        await this.loadReport(key, fileName)
      } catch (e) {
        this.error = e
      } finally {
        this.loading = false
      }
    },

    // Prüft die aktuell angezeigte (oder eine angegebene) Datei erneut.
    async recheck(fileId, fileName = '', locale = 'de') {
      await this.check(fileId, fileName, locale)
    },

    // Speichert die vom Benutzer bearbeiteten Teile des Berichts (Vorschläge
    // und Freitext-Anweisung an die KI). Der Server liefert den aktualisierten
    // Gesamt-Bericht zurück, der direkt in den Dialog übernommen wird.
    async saveEdits(key, suggestions, instruction) {
      this.error = null
      try {
        this.current = await api.post('auditcontentupdate', {
          key,
          suggestions,
          instruction,
        })
        return true
      } catch (e) {
        this.error = e
        return false
      }
    },

    // Nimmt eine bereits verbesserte Seite wieder in die Arbeitsliste auf
    // (löscht den Verbesserungs-Vermerk, ohne neu zu prüfen).
    async requeue(key) {
      await api.post('auditcontentrequeue', { key })
      await this.fetchChecked()
    },

    // Löscht ein gespeichertes Ergebnis.
    async remove(key) {
      await api.post('auditcontentdelete', { key })
      if (this.current?.contentQuality?.key === key) {
        this.current = null
      }
      await this.fetchChecked()
    },

    closeDialog() {
      this.dialogOpen = false
      this.current = null
      this.error = null
    },
  },
})
