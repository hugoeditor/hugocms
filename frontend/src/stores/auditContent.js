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
    current: null, // vollständiger Eintrag im Dialog
    dialogOpen: false,
    busy: false, // ein Prüflauf läuft (LLM-Aufruf)
    loading: false, // ein gespeichertes Ergebnis wird geladen
    error: null, // roher Fehler des letzten Aufrufs (Dialog übersetzt ihn)
    fileName: '', // Name der geprüften/angezeigten Datei (Dialogtitel)
  }),

  actions: {
    // Prüft eine Datei per LLM und zeigt das Ergebnis im Dialog. Fehler werden
    // im Dialog angezeigt (nicht geworfen), damit der Aufrufer nichts weiter
    // behandeln muss.
    async check(fileId, fileName = '', locale = 'de') {
      this.dialogOpen = true
      this.busy = true
      this.error = null
      this.current = null
      this.fileName = fileName
      try {
        this.current = await api.post('auditcontent', { id: fileId, locale })
        this.fileName = this.current.title || fileName
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

    // Öffnet ein gespeichertes Ergebnis im Dialog (aus der Liste heraus).
    async openResult(key, fileName = '') {
      this.dialogOpen = true
      this.loading = true
      this.error = null
      this.current = null
      this.fileName = fileName
      try {
        this.current = await api.get('auditcontentget', { key })
        this.fileName = this.current.title || fileName
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

    // Löscht ein gespeichertes Ergebnis.
    async remove(key) {
      await api.post('auditcontentdelete', { key })
      if (this.current?.key === key) {
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
