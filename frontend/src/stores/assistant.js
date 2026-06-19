import { defineStore } from 'pinia'
import { api } from '../api/client'

// KI-Assistent. Hält den Gesprächsverlauf im Anthropic-Nachrichtenformat
// (role + content-Blöcke) und schickt ihn bei jedem Zug ans Backend. Das
// Backend ist zustandslos und gibt den fortgeschriebenen Verlauf zurück.
export const useAssistantStore = defineStore('assistant', {
  state: () => ({
    open: false,
    history: [], // rohe Anthropic-messages (inkl. tool_use/tool_result/thinking)
    pending: null, // { tool, input, oldContent? } — ausstehende Schreibaktion (confirm-Modus)
    actions: [], // im letzten Zug ausgeführte Schreibaktionen
    busy: false,
    error: null, // ApiError oder null
  }),

  getters: {
    // Aus dem rohen Verlauf eine anzeigbare Liste ableiten. Thinking- und
    // tool_result-Blöcke werden ausgeblendet; tool_use als kompakte Notiz.
    bubbles: (s) => {
      const out = []
      for (const m of s.history) {
        if (m.role === 'user') {
          if (typeof m.content === 'string') out.push({ kind: 'user', text: m.content })
          // Array-Content (tool_results) wird nicht angezeigt.
        } else if (m.role === 'assistant' && Array.isArray(m.content)) {
          for (const b of m.content) {
            if (b.type === 'text' && b.text && b.text.trim()) {
              out.push({ kind: 'assistant', text: b.text })
            } else if (b.type === 'tool_use') {
              out.push({ kind: 'tool', tool: b.name, path: b.input?.path ?? '' })
            }
          }
        }
      }
      return out
    },
  },

  actions: {
    // Sendet eine neue Nutzernachricht. Rückgabe: true bei Erfolg. Bei Fehler
    // wird die gerade angehängte Nachricht zurückgerollt (alternierende
    // Rollen bleiben gültig) und der Aufrufer kann den Text erneut anbieten.
    // ctx: { openFilePath, openDirPath } — Kontext aus Editor und Dateimanager.
    async send(text, locale, ctx = {}) {
      this.error = null
      this.history.push({ role: 'user', content: text })
      this.busy = true
      try {
        const data = await api.post('assistant', {
          messages: this.history,
          locale,
          openFilePath: ctx.openFilePath ?? null,
          openDirPath: ctx.openDirPath ?? null,
        })
        this.apply(data)
        return true
      } catch (e) {
        this.history.pop() // Rollback der unbeantworteten Nachricht
        this.error = e
        return false
      } finally {
        this.busy = false
      }
    },

    // Beantwortet eine ausstehende Schreibaktion (confirm-Modus).
    async resolve(decision, locale, ctx = {}) {
      this.error = null
      this.busy = true
      try {
        const data = await api.post('assistant', {
          messages: this.history,
          locale,
          confirm: decision,
          openFilePath: ctx.openFilePath ?? null,
          openDirPath: ctx.openDirPath ?? null,
        })
        this.apply(data)
        return true
      } catch (e) {
        this.error = e
        return false
      } finally {
        this.busy = false
      }
    },

    apply(data) {
      this.history = Array.isArray(data.messages) ? data.messages : this.history
      this.pending = data.pending ?? null
      this.actions = Array.isArray(data.actions) ? data.actions : []
    },

    reset() {
      this.history = []
      this.pending = null
      this.actions = []
      this.error = null
    },
  },
})
