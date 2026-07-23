import { defineStore } from 'pinia'
import { api } from '../api/client'
import { useReviewStore } from './review'

// Fehlermeldungen des Assistenten blenden sich nach dieser Frist selbst aus
// (analog zu util/transientError). Ein modul-weiter Timer genügt, da es je
// Sitzung nur einen Assistenten gibt.
const ERROR_TIMEOUT = 8000
let errorTimer = null

// KI-Assistent. Hält den Gesprächsverlauf im Anthropic-Nachrichtenformat
// (role + content-Blöcke) und schickt ihn bei jedem Zug ans Backend. Das
// Backend ist zustandslos und gibt den fortgeschriebenen Verlauf zurück.
export const useAssistantStore = defineStore('assistant', {
  state: () => ({
    open: false,
    history: [], // rohe Anthropic-messages (inkl. tool_use/tool_result/thinking)
    pending: null, // { tool, input, oldContent? } — ausstehende Schreibaktion (confirm-Modus)
    actions: [], // im letzten Zug ausgeführte Schreibaktionen
    aborted: false, // letzter Zug an der Schrittgrenze abgebrochen → „Weiter" anbieten
    busy: false,
    checking: false, // Bereitschaftsprüfung (assistantping) läuft
    ready: false, // Claude-API zuletzt erfolgreich erreicht
    readyChecked: false, // Prüfung in dieser Sitzung bereits durchgeführt
    error: null, // ApiError oder null
    // Sitzungsbezogene Auswahl aus dem Panel. null = noch nicht gesetzt; das
    // Panel belegt beide beim Öffnen mit dem konfigurierten Standard (auth.ai).
    // Werden bei jedem Zug mitgeschickt und übersteuern die INI nur zur Laufzeit
    // (nach einem Neuladen gilt wieder der Standard). reset() lässt sie stehen.
    model: null,
    writeMode: null,
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
    // Setzt die Fehlermeldung und blendet sie nach ERROR_TIMEOUT selbst wieder
    // aus; null löscht sie sofort. Zentral für alle Assistant-Fehler (Chat,
    // Transkription, Mikrofon).
    setError(err) {
      this.error = err
      if (errorTimer) {
        clearTimeout(errorTimer)
        errorTimer = null
      }
      if (err) {
        errorTimer = setTimeout(() => {
          this.error = null
          errorTimer = null
        }, ERROR_TIMEOUT)
      }
    },

    // Sendet eine neue Nutzernachricht. Rückgabe: true bei Erfolg. Bei Fehler
    // wird die gerade angehängte Nachricht zurückgerollt (alternierende
    // Rollen bleiben gültig) und der Aufrufer kann den Text erneut anbieten.
    // ctx: { openFilePath, openDirPath } — Kontext aus Editor und Dateimanager.
    async send(text, locale, ctx = {}) {
      this.setError(null)
      this.history.push({ role: 'user', content: text })
      this.busy = true
      try {
        const data = await api.post('assistant', {
          messages: this.history,
          locale,
          model: this.model ?? undefined, // leer/undefined = konfiguriertes Modell
          writeMode: this.writeMode ?? undefined,
          openFilePath: ctx.openFilePath ?? null,
          openDirPath: ctx.openDirPath ?? null,
        })
        this.apply(data)
        return true
      } catch (e) {
        this.history.pop() // Rollback der unbeantworteten Nachricht
        this.setError(e)
        return false
      } finally {
        this.busy = false
      }
    },

    // Bereitschaftsprüfung: fragt das Backend, ob die Claude-API erreichbar und
    // der Schlüssel gültig ist (GET /v1/models, ohne Token-Verbrauch). Der Merker
    // `readyChecked` wird NUR bei Erfolg gesetzt, damit die Prüfung pro Sitzung
    // einmal gelingt, aber nach einem Fehler beim nächsten Öffnen erneut läuft.
    // Setzt `ready` bzw. legt bei Fehlern den ApiError in `error` ab.
    async checkReady() {
      this.setError(null)
      this.ready = false
      this.checking = true
      try {
        const data = await api.post('assistantping')
        this.ready = data.ready === true
        this.readyChecked = this.ready
        return this.ready
      } catch (e) {
        this.setError(e)
        return false
      } finally {
        this.checking = false
      }
    },

    // Startet einen Verbesserungslauf für eine einzelne Datei: das Backend
    // seedet die Anweisung und führt den ersten Zug aus (liest den Bericht,
    // pausiert im confirm-Modus vor dem Schreiben). Öffnet das Panel und
    // übernimmt den Verlauf; der Benutzer bestätigt bzw. plaudert weiter.
    async improve(fileId, locale) {
      this.reset()
      this.open = true
      this.busy = true
      try {
        const data = await api.post('assistantimprove', { id: fileId, locale })
        this.apply(data)
        return true
      } catch (e) {
        this.setError(e)
        return false
      } finally {
        this.busy = false
      }
    },

    // Beantwortet eine ausstehende Schreibaktion (confirm-Modus).
    async resolve(decision, locale, ctx = {}) {
      this.setError(null)
      this.busy = true
      try {
        const data = await api.post('assistant', {
          messages: this.history,
          locale,
          confirm: decision,
          model: this.model ?? undefined, // leer/undefined = konfiguriertes Modell
          writeMode: this.writeMode ?? undefined,
          openFilePath: ctx.openFilePath ?? null,
          openDirPath: ctx.openDirPath ?? null,
        })
        this.apply(data)
        return true
      } catch (e) {
        this.setError(e)
        return false
      } finally {
        this.busy = false
      }
    },

    apply(data) {
      this.history = Array.isArray(data.messages) ? data.messages : this.history
      this.pending = data.pending ?? null
      this.actions = Array.isArray(data.actions) ? data.actions : []
      this.aborted = data.aborted ?? false
      // Ging ein Schreibvorgang als Freigabe-Entwurf ab (Modus auto, der Server
      // markiert die Aktion mit draft), zählt die Werkzeugschiene einen Entwurf
      // mehr — Liste nachladen. Gilt für alle Wege in apply(): Gespräch,
      // bestätigte Aktion und den Verbesserungslauf aus der Content-Liste.
      if (this.actions.some((a) => a.draft)) {
        useReviewStore().fetch().catch(() => {})
      }
    },

    // Spracheingabe (Pro): nimmt ein aufgenommenes Audio-Blob, schickt es als
    // multipart an den Proxy-Befehl `speech` (der es an den externen Dienst
    // weiterreicht) und liefert den erkannten Text. Reiner API-Aufruf ohne
    // Nebenwirkung auf den Gesprächsverlauf; Fehler werden geworfen.
    async transcribe(blob, locale) {
      const form = new FormData()
      form.append('cmd', 'speech')
      form.append('locale', locale ?? 'de')
      form.append('audio', blob, 'aufnahme.webm')
      const data = await api.postForm(form)
      return typeof data.text === 'string' ? data.text : ''
    },

    reset() {
      this.history = []
      this.pending = null
      this.actions = []
      this.aborted = false
      this.setError(null)
      // `ready`/`readyChecked` bleiben erhalten: die Prüfung gilt sitzungsweit.
    },
  },
})
