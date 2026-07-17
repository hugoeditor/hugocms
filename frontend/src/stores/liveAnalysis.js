import { markRaw } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../api/client'

// Live-Analyse (Pro, seo-success). Job-basierter Proxy: anstoßen → pollen →
// Ergebnis. Bewusst ein EIGENER Store (nicht in audit.js, dort liegt PageSpeed) —
// die beiden Prüfungen sind strikt getrennt.
//
// Das Polling ist das erste im Client: rekursives setTimeout (kein setInterval,
// damit sich Anfragen bei langsamer Antwort nicht überlappen), 2 s Takt, nach
// 60 s auf 5 s gestreckt, harte Obergrenze 10 min. stopPolling() beendet nur die
// lokale Schleife; cancel() bricht den Lauf serverseitig ab.

const POLL_FAST_MS = 2000
const POLL_SLOW_MS = 5000
const POLL_SLOW_AFTER_MS = 60000
const POLL_MAX_MS = 600000 // 10 min Obergrenze, dann Abbruch mit „weiter warten"

export const useLiveAnalysisStore = defineStore('liveAnalysis', {
  state: () => ({
    result: null, // Roh-result der API (markRaw: reine Anzeige, viele Befunde)
    analyzedAt: null, // Zeitpunkt des angezeigten Ergebnisses (ISO)
    resultJobId: null, // Auftrags-ID des angezeigten Ergebnisses (für den Export)
    jobId: null, // laufender Auftrag (null = kein Polling aktiv)
    running: false, // läuft gerade eine Analyse (queued/running)?
    status: null, // 'queued' | 'running' | 'done' | 'error' | 'cancelled'
    stale: false, // Job hängt, kein Worker aktiv (Hinweis, kein Abbruch)
    timedOut: false, // Polling-Obergrenze erreicht — „weiter warten" anbieten
    error: null, // { code, key, params } eines Poll-Fehlers (Panel übersetzt)
    history: [], // Verlaufsläufe (neueste zuerst) für die Kurve
    quota: null, // { name, quotaLimit, quotaRemaining, quotaExceeded } oder null
    locale: null, // Berichtssprache des laufenden/angezeigten Ergebnisses (de/en)
    severityFilter: 'all', // 'all' | 'critical' | 'warning' | 'info'
    typeFilter: 'all', // 'all' | <befund-typ>
    _timer: null, // Polling-Timer (intern)
    _startedAt: 0, // Startzeit des laufenden Pollings (intern, für Takt/Frist)
  }),

  getters: {
    // Befunde des angezeigten Ergebnisses, gefiltert nach Schweregrad und Typ,
    // nach Schweregrad sortiert (critical → warning → info; stabil).
    filteredIssues(state) {
      const issues = state.result?.issues ?? []
      const rank = { critical: 0, warning: 1, info: 2 }
      return issues
        .filter(
          (i) =>
            (state.severityFilter === 'all' || i.severity === state.severityFilter) &&
            (state.typeFilter === 'all' || i.type === state.typeFilter),
        )
        .sort((a, b) => (rank[a.severity] ?? 99) - (rank[b.severity] ?? 99))
    },

    // Zählung je Schweregrad aus dem Ergebnis (für die Filterknöpfe).
    bySeverity: (state) => state.result?.summary?.by_severity ?? { critical: 0, warning: 0, info: 0 },

    // Restkontingent als Zahl, null bei unbegrenzt (quotaLimit === null).
    quotaRemaining: (state) => (state.quota ? state.quota.quotaRemaining : null),

    // Ist das Kontingent erschöpft? Nur bei gesetztem Limit relevant.
    quotaExceeded: (state) => !!state.quota?.quotaExceeded,
  },

  actions: {
    // Zuletzt abgelegtes Ergebnis dieser Webseite laden — und einen etwaigen
    // offenen Job wieder aufnehmen (Polling), damit ein Neuladen den Lauf nicht
    // verliert.
    async fetchLatest() {
      const data = await api.get('liveanalyzelatest')
      this.result = data.result ? markRaw(data.result) : null
      this.analyzedAt = data.analyzedAt ?? null
      this.resultJobId = data.jobId ?? null
      if (data.job?.id) {
        this.jobId = data.job.id
        this.running = true
        this.status = 'running'
        this.startPolling()
      }
      return data
    },

    // Analyse der eingegebenen Adresse anstoßen und mit dem Polling beginnen.
    // `locale` legt die Berichtssprache fest (der Dienst lokalisiert über den
    // Befund-Typ). Wirft bei ungültiger Adresse, erschöpftem Kontingent oder
    // totem Worker (ANALYZE-WORKER-DOWN) — das Panel fängt und zeigt den Grund.
    async start(url, locale) {
      this.reset()
      this.locale = locale ?? null
      this.running = true
      this.status = 'queued'
      try {
        const res = await api.post('liveanalyze', { url, locale })
        this.jobId = res.jobId
        this.status = res.status ?? 'queued'
        this.startPolling()
      } catch (e) {
        this.running = false
        this.status = null
        throw e
      }
    },

    // Angezeigtes Ergebnis in einer anderen Sprache neu holen. Der Dienst
    // übersetzt die Befunde beim Abruf über den sprachneutralen Typ — derselbe
    // Auftrag, kein neuer Lauf, KEIN Kontingent. Best effort: Ist der Auftrag
    // beim Dienst nicht mehr vorhanden, bleibt das Ergebnis in der alten Sprache.
    async refetchInLocale(locale) {
      if (!this.resultJobId || this.running) return
      this.locale = locale ?? null
      try {
        const res = await api.get('liveanalyzestatus', { jobId: this.resultJobId, locale })
        if (res.status === 'done' && res.result) {
          this.result = markRaw(res.result)
          // analyzedAt bewusst NICHT überschreiben — es wurde nichts neu geprüft.
        }
      } catch {
        // Auftrag weg/nicht abrufbar: alte Sprache stehen lassen, nicht stören.
      }
    },

    // Serverseitiger Abbruch: stoppt den Lauf wirklich (kein weiteres Kontingent)
    // und beendet das Polling. Anders als stopPolling(), das nur lokal aufhört.
    async cancel() {
      const jobId = this.jobId
      this.stopPolling()
      this.running = false
      this.status = 'cancelled'
      if (!jobId) return
      const res = await api.post('liveanalyzecancel', { jobId })
      // Ist der Lauf im Rennen mit dem Worker fertig geworden, liefert das
      // Backend das Ergebnis statt eines Abbruchs — dann anzeigen.
      if (res.status === 'done' && res.result) {
        this.result = markRaw(res.result)
        this.analyzedAt = res.analyzedAt ?? null
        this.resultJobId = res.jobId ?? jobId
        this.status = 'done'
        this.fetchHistory().catch(() => {})
        this.fetchQuota().catch(() => {})
      }
    },

    // Startet die rekursive Poll-Schleife (interner Helfer).
    startPolling() {
      this.stopPolling()
      this.timedOut = false
      this.error = null
      this._startedAt = Date.now()
      this._scheduleNextPoll(0)
    },

    // Beendet nur die lokale Poll-Schleife (Ansicht schließen / cancel). Der
    // serverseitige Lauf bleibt davon unberührt.
    stopPolling() {
      if (this._timer) {
        clearTimeout(this._timer)
        this._timer = null
      }
    },

    _scheduleNextPoll(delay) {
      this._timer = setTimeout(() => this._poll(), delay)
    },

    async _poll() {
      if (!this.jobId) return
      try {
        const res = await api.get('liveanalyzestatus', { jobId: this.jobId, locale: this.locale ?? '' })
        this.status = res.status ?? null
        this.stale = !!res.stale

        if (res.status === 'done') {
          this.result = res.result ? markRaw(res.result) : null
          this.analyzedAt = res.analyzedAt ?? null
          this.resultJobId = res.jobId ?? this.jobId
          this._finish()
          this.fetchHistory().catch(() => {})
          this.fetchQuota().catch(() => {})
          return
        }
        if (res.status === 'error') {
          this.error = { code: 'ESEOANALYZE', key: 'ANALYZE-JOB-ERROR', params: [res.error ?? ''] }
          this._finish()
          return
        }
        if (res.status === 'cancelled') {
          this._finish()
          return
        }
      } catch (e) {
        // Poll-Fehler (Netz/Dienst): Schleife beenden, Grund ans Panel reichen.
        this.error = e
        this._finish()
        return
      }

      // Weiterpollen, sofern die Obergrenze nicht erreicht ist.
      const elapsed = Date.now() - this._startedAt
      if (elapsed > POLL_MAX_MS) {
        this.timedOut = true
        this.stopPolling()
        return
      }
      this._scheduleNextPoll(elapsed > POLL_SLOW_AFTER_MS ? POLL_SLOW_MS : POLL_FAST_MS)
    },

    // „Weiter warten" nach erreichter Obergrenze: Polling erneut aufnehmen.
    resume() {
      if (!this.jobId) return
      this.running = true
      this.startPolling()
    },

    _finish() {
      this.stopPolling()
      this.running = false
      this.jobId = null
      this.stale = false
    },

    // Verlaufsläufe für die Kurve laden (Host aus der gespeicherten Adresse).
    async fetchHistory() {
      const data = await api.get('liveanalyzehistory')
      this.history = data.runs ?? []
      return data
    },

    // Restkontingent für die Anzeige vor dem Start (serviceverify, ohne zu messen).
    async fetchQuota() {
      const info = await api.post('serviceverify', {})
      this.quota = {
        name: info.name ?? '',
        quotaLimit: info.quotaLimit ?? null,
        quotaRemaining: info.quotaRemaining ?? null,
        quotaExceeded: !!info.quotaExceeded,
      }
      return this.quota
    },

    setSeverityFilter(value) {
      this.severityFilter = this.severityFilter === value ? 'all' : value
    },

    setTypeFilter(value) {
      this.typeFilter = this.typeFilter === value ? 'all' : value
    },

    // Setzt die laufbezogenen Felder zurück (vor einem neuen Start), lässt aber
    // das zuletzt angezeigte Ergebnis stehen, bis das neue vorliegt.
    reset() {
      this.stopPolling()
      this.jobId = null
      this.status = null
      this.stale = false
      this.timedOut = false
      this.error = null
      this.severityFilter = 'all'
      this.typeFilter = 'all'
    },
  },
})
