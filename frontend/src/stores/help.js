import { defineStore } from 'pinia'
import { api } from '../api/client'

// Hilfe-/Wissensdatenbank (frei). Lädt ein Thema (Markdown + Metadaten) über den
// help-Befehl des Connectors. Die Anzeige übernimmt HelpView als Überlagerung;
// solange `topic` gesetzt ist, liegt sie über der aktuellen Ansicht.
export const useHelpStore = defineStore('help', {
  state: () => ({
    topic: null, // { section, id } des angezeigten Themas oder null (geschlossen)
    data: null, // geladenes Thema { title, summary, body, seeAlso, locale, fallback, ... }
    loading: false,
    error: null, // ApiError beim Laden (z. B. HELP-NOT-FOUND)
  }),

  actions: {
    // Öffnet ein Hilfethema. locale = aktuelle UI-Sprache (Rückfall im Backend).
    async open(section, id, locale = 'de') {
      this.topic = { section, id }
      this.loading = true
      this.error = null
      this.data = null
      try {
        this.data = await api.get('help', { section, id, locale })
      } catch (e) {
        this.error = e
      } finally {
        this.loading = false
      }
    },

    close() {
      this.topic = null
      this.data = null
      this.error = null
    },
  },
})
