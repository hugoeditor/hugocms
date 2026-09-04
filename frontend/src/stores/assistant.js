import { defineStore } from 'pinia'
import { api } from '../api/client'
import { useReviewStore } from './review'

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
    // Fehler stehen im Gesprächsverlauf statt in einer eigenen, sich selbst
    // ausblendenden Meldung: Wer nach einem gescheiterten Zug zurückkommt, soll
    // noch sehen, woran er gescheitert ist. Je Eintrag { at, error } — `at` ist
    // die Länge von `history` zum Zeitpunkt des Fehlers und bestimmt, an welcher
    // Stelle des Verlaufs die Meldung erscheint.
    notices: [],
    // Sitzungsbezogene Auswahl aus dem Panel. `null` heißt: NICHT gewählt —
    // dann geht nichts mit, und der Server nimmt den konfigurierten Wert. Beide
    // dürfen deshalb nie mit dem Standard vorbelegt werden: Der eingefrorene
    // Wert würde als Übersteuerung mitgeschickt, und eine spätere Änderung in
    // der Konfiguration käme nie an. Das Panel zeigt den wirksamen Wert
    // (Auswahl, sonst auth.ai) und stellt „Wie konfiguriert" zum Zurücksetzen
    // bereit. Eine getroffene Auswahl gilt nur zur Laufzeit (ein Neuladen setzt
    // sie zurück); reset() lässt sie stehen.
    model: null,
    writeMode: null,
    // „Nicht mehr fragen": Der Benutzer hat die Bestätigungen für den LAUFENDEN
    // Auftrag vorab erteilt. Der Schalter geht bei jedem Zug mit und wird
    // zurückgenommen, sobald der Auftrag zu Ende ist — danach gilt wieder der
    // eingestellte Schreibmodus. Bewusst kein Wechsel auf „automatisch": Der
    // würde jede Änderung als Entwurf in die Freigabe-Warteschlange legen
    // statt in die Datei (Entwurfspflicht der gestaffelten Veröffentlichung).
    autoConfirm: false,
    // Sammelauftrag aus dem SEO-Bericht: eine Gruppe je Content-Datei, jede mit
    // ihren Funden. Die Gruppen laufen NACHEINANDER — ein Request je Datei,
    // passend zum zustandslosen Backend. Wartet ein Auftrag auf den Benutzer
    // (Bestätigung im confirm-Modus oder Schrittgrenze), hält die Reihe an und
    // läuft nach seiner Entscheidung von selbst weiter.
    // { runId, locale, groups, index, submittedKeys, stopped, finished }
    batch: null,
  }),

  getters: {
    // Läuft gerade ein Sammelauftrag (mehr als eine Datei, noch nicht am Ende)?
    // Treibt die Fortschrittszeile im Panel.
    batchActive: (s) => !!s.batch && !s.batch.finished && !s.batch.stopped,

    // Aus dem rohen Verlauf eine anzeigbare Liste ableiten. Thinking- und
    // tool_result-Blöcke werden ausgeblendet; tool_use als kompakte Notiz.
    // Fehlermeldungen (notices) werden an ihrer Position eingemischt, damit sie
    // dort stehen, wo der Zug gescheitert ist.
    bubbles: (s) => {
      const out = []
      const noticesAt = (at) => {
        for (const n of s.notices) {
          if (n.at === at) out.push({ kind: 'error', error: n.error, count: n.count ?? 1 })
        }
      }
      noticesAt(0) // Fehler vor dem ersten Zug (z. B. Bereitschaftsprüfung)
      for (let i = 0; i < s.history.length; i++) {
        const m = s.history[i]
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
        noticesAt(i + 1)
      }
      // Sicherheitsnetz: Wurde der Verlauf nachträglich kürzer, ginge eine
      // Meldung sonst still verloren — solche Einträge kommen ans Ende.
      for (const n of s.notices) {
        if (n.at > s.history.length) out.push({ kind: 'error', error: n.error, count: n.count ?? 1 })
      }
      return out
    },
  },

  actions: {
    // Hängt einen Fehler als Eintrag an den Verlauf. Zentral für alle
    // Assistant-Fehler (Chat, Bereitschaftsprüfung, Transkription, Mikrofon).
    // Die Meldung bleibt stehen, bis der Verlauf geleert wird.
    noteError(err) {
      if (!err) return
      // Wiederholt sich derselbe Fehler an derselben Stelle — etwa ein zweiter
      // Versuch, der erneut in die Zeitüberschreitung läuft —, wird nicht
      // gestapelt, sondern gezählt. Unterdrücken wäre falsch: Der Verlauf sähe
      // danach unverändert aus, und der Fehlversuch bliebe unsichtbar.
      const last = this.notices[this.notices.length - 1]
      const same = last
        && last.at === this.history.length
        && (last.error?.key ?? null) === (err.key ?? null)
        && (last.error?.code ?? null) === (err.code ?? null)
      if (same) {
        last.count = (last.count ?? 1) + 1
        last.error = err // frischere Parameter übernehmen (z. B. neue Limit-Zeit)
        return
      }
      this.notices.push({ at: this.history.length, error: err, count: 1 })
    },

    // Sendet eine neue Nutzernachricht. Rückgabe: true bei Erfolg. Bei Fehler
    // wird die gerade angehängte Nachricht zurückgerollt (alternierende
    // Rollen bleiben gültig) und der Aufrufer kann den Text erneut anbieten.
    // ctx: { openFilePath, openDirPath } — Kontext aus Editor und Dateimanager.
    async send(text, locale, ctx = {}) {
      this.history.push({ role: 'user', content: text })
      this.busy = true
      let ok = false
      try {
        const data = await api.post('assistant', {
          messages: this.history,
          locale,
          model: this.model ?? undefined, // leer/undefined = konfiguriertes Modell
          writeMode: this.writeMode ?? undefined,
          autoConfirm: this.autoConfirm || undefined,
          openFilePath: ctx.openFilePath ?? null,
          openDirPath: ctx.openDirPath ?? null,
        })
        this.apply(data)
        ok = true
      } catch (e) {
        this.history.pop() // Rollback der unbeantworteten Nachricht
        this.noteError(e)
      } finally {
        this.busy = false
      }
      // Führt der Benutzer einen an der Schrittgrenze angehaltenen Auftrag zu
      // Ende, läuft ein wartender Sammellauf danach weiter.
      if (ok) await this.continueBatch()
      this.releaseAutoConfirm()

      return ok
    },

    // Bereitschaftsprüfung: fragt das Backend, ob die Claude-API erreichbar und
    // der Schlüssel gültig ist (GET /v1/models, ohne Token-Verbrauch). Der Merker
    // `readyChecked` wird NUR bei Erfolg gesetzt, damit die Prüfung pro Sitzung
    // einmal gelingt, aber nach einem Fehler beim nächsten Öffnen erneut läuft.
    // Setzt `ready` bzw. legt bei Fehlern eine Meldung in den Verlauf.
    async checkReady() {
      this.ready = false
      this.checking = true
      try {
        const data = await api.post('assistantping')
        this.ready = data.ready === true
        this.readyChecked = this.ready
        return this.ready
      } catch (e) {
        this.noteError(e)
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
      this.batch = null // ein Verbesserungslauf löst eine noch offene Reihe ab
      this.releaseAutoConfirm(true)
      this.reset()
      this.open = true
      this.busy = true
      try {
        const data = await api.post('assistantimprove', { id: fileId, locale })
        this.apply(data)
        return true
      } catch (e) {
        this.noteError(e)
        return false
      } finally {
        this.busy = false
      }
    },

    // Micro-Auftrag aus dem SEO-Bericht: lässt genau EINEN Fund bearbeiten. Der
    // Server schlägt den Fund selbst im gespeicherten Lauf nach — hierher gehen
    // nur die Kennungen, kein Meldungstext.
    //
    // mode 'fix' behebt ihn in der Content-Datei; mode 'diagnose' erklärt nur,
    // was im Theme, in der Konfiguration oder an der Struktur zu ändern wäre
    // (der Server läuft dafür im Nur-Lese-Modus).
    async fixIssue(runId, ruleId, url, locale, mode = 'fix') {
      this.batch = null // ein Einzelauftrag löst eine noch offene Reihe ab
      this.releaseAutoConfirm(true)
      this.reset()
      this.open = true
      this.busy = true
      try {
        const data = await api.post('assistantfix', { runId, ruleId, url: url ?? null, locale, mode })
        this.apply(data)
        return true
      } catch (e) {
        this.noteError(e)
        return false
      } finally {
        this.busy = false
      }
    },

    // „Nicht mehr fragen": bestätigt die ausstehende Änderung und lässt den
    // Assistenten den REST DIESES AUFTRAGS ohne weitere Rückfragen abarbeiten.
    // Geschrieben wird weiterhin wie bisher (live bzw. je nach Modus) — es
    // entfällt nur die Pause vor jeder einzelnen Änderung.
    async allowRest(locale, ctx = {}) {
      if (!this.pending) return false
      this.autoConfirm = true
      return this.resolve('allow', locale, ctx)
    },

    // Nimmt die Vorab-Bestätigung zurück, sobald der Auftrag zu Ende ist: kein
    // Zug läuft, nichts steht aus. Ein an der Schrittgrenze angehaltener Zug
    // (`aborted`) und ein laufender Sammelauftrag zählen als „noch nicht
    // fertig" — die Freigabe galt der ganzen Aufgabe, nicht dem einzelnen Zug.
    releaseAutoConfirm(force = false) {
      if (!this.autoConfirm) return
      if (!force && (this.busy || this.pending || this.aborted || this.batchActive)) return
      this.autoConfirm = false
    },

    // Startet einen Sammelauftrag über mehrere Funde. `groups` ist bereits nach
    // Quelldatei gruppiert: [{ sourceFile, issues: [{ ruleId, url }] }]. Je
    // Gruppe geht EIN Auftrag ans Backend — das Modell liest die Datei einmal
    // und behebt alle ihre Funde zusammen, statt sie einzeln anzufassen.
    async fixIssueGroups(runId, groups, locale) {
      if (!groups.length) return false
      this.releaseAutoConfirm(true) // neue Aufgabe, neue Entscheidung
      this.batch = {
        runId,
        locale,
        groups,
        index: 0,
        submittedKeys: [], // tatsächlich abgesetzte Funde (für den Erledigt-Haken)
        stopped: false,
        finished: false,
      }
      this.open = true
      await this.runBatch()
      return true
    },

    // Arbeitet die Gruppen ab, solange nichts auf den Benutzer wartet.
    async runBatch() {
      const b = this.batch
      if (!b) return
      while (!b.stopped && b.index < b.groups.length) {
        const group = b.groups[b.index]
        // Jede Datei bekommt ein eigenes Gespräch: Der Verlauf der vorigen
        // gehört nicht in den nächsten Auftrag (und würde ihn nur verteuern).
        this.reset()
        this.busy = true
        try {
          const data = await api.post('assistantfixmany', {
            runId: b.runId,
            // Nur die Kennungen: Den Fund selbst schlägt der Server im
            // gespeicherten Lauf nach (`key` dient allein dem Erledigt-Haken).
            issues: group.issues.map((i) => ({ ruleId: i.ruleId, url: i.url })),
            locale: b.locale,
            autoConfirm: this.autoConfirm || undefined,
          })
          this.apply(data)
          b.submittedKeys = [...b.submittedKeys, ...group.issues.map((i) => i.key)]
        } catch (e) {
          // Ein gescheiterter Auftrag hält die Reihe an, statt sie stumm
          // weiterlaufen zu lassen — der Fehler steht im Verlauf, und der
          // Benutzer entscheidet, ob er den Rest noch will.
          this.noteError(e)
          b.stopped = true
          return
        } finally {
          this.busy = false
        }
        // Bestätigung ausstehend oder an der Schrittgrenze angehalten: Jetzt ist
        // der Benutzer am Zug; weiter geht es über continueBatch().
        if (this.pending || this.aborted) return
        b.index += 1
      }
      b.finished = true
      this.releaseAutoConfirm()
    },

    // Setzt die Reihe fort, nachdem der Benutzer den wartenden Auftrag beendet
    // hat (Bestätigung entschieden bzw. Zug zu Ende geführt). Tut nichts,
    // solange noch etwas aussteht.
    async continueBatch() {
      const b = this.batch
      if (!b || b.stopped || b.finished) return
      if (this.busy || this.pending || this.aborted) return
      b.index += 1
      await this.runBatch()
    },

    // Bricht die Reihe ab. Der bereits laufende Auftrag bleibt stehen — er ist
    // abgeschickt; abgebrochen wird nur, was noch nicht begonnen hat.
    stopBatch() {
      if (this.batch) this.batch.stopped = true
      // Die Reihe ist beendet — damit endet auch die Freigabe für sie.
      this.releaseAutoConfirm(true)
    },

    // Beantwortet eine ausstehende Schreibaktion (confirm-Modus): 'allow'
    // schreibt live, 'draft' legt einen Entwurf zur Freigabe ab, 'reject' lehnt
    // ab. Zu 'draft' kann ein Termin (ISO 8601) mitgegeben werden — dann ist der
    // Entwurf gleich terminiert und die Live-Datei bleibt bis dahin unverändert.
    async resolve(decision, locale, ctx = {}, publishDate = '') {
      this.busy = true
      let ok = false
      try {
        const data = await api.post('assistant', {
          messages: this.history,
          locale,
          confirm: decision,
          publishDate: publishDate || undefined,
          model: this.model ?? undefined, // leer/undefined = konfiguriertes Modell
          writeMode: this.writeMode ?? undefined,
          autoConfirm: this.autoConfirm || undefined,
          openFilePath: ctx.openFilePath ?? null,
          openDirPath: ctx.openDirPath ?? null,
        })
        this.apply(data)
        ok = true
      } catch (e) {
        this.noteError(e)
      } finally {
        this.busy = false
      }
      // Gehört der beantwortete Auftrag zu einem Sammellauf und steht nichts
      // mehr aus, geht es mit der nächsten Datei weiter.
      if (ok) await this.continueBatch()
      this.releaseAutoConfirm()

      return ok
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

    // Verlauf leeren (Besen-Knopf im Panel). Beendet auch einen wartenden
    // Sammelauftrag: Mit dem Verlauf verschwindet die ausstehende Bestätigung,
    // an der die Reihe hängt — sie liefe sonst nie weiter und ließe sich nicht
    // mehr abbrechen.
    clearConversation() {
      this.batch = null
      this.releaseAutoConfirm(true)
      this.reset()
    },

    reset() {
      this.history = []
      this.pending = null
      this.actions = []
      this.aborted = false
      this.notices = []
      // `ready`/`readyChecked` bleiben erhalten: die Prüfung gilt sitzungsweit.
    },
  },
})
