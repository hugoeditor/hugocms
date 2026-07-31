import { defineStore } from 'pinia'
import { api, setCsrfToken } from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    authenticated: false,
    ready: false, // true, sobald der erste whoami-Aufruf beantwortet wurde
    warnings: [], // Einrichtungs-Hinweise des Servers (z. B. fehlende Verzeichnisse)
    setupRequired: false, // true, solange keine hugocms.ini existiert (Erstinbetriebnahme)
    setupDefaults: null, // Vorgaben des Servers für das Setup-Formular
    buildable: false, // true, wenn für diese Webseite ein Hugo-Aufruf konfiguriert ist
    reconfigurable: false, // true, wenn die hugocms.ini im Betrieb änderbar ist
    // true, wenn die Einstellungen DIESER Webseite änderbar sind (die Mounts
    // stammen aus einer Datei). Steuert den Dialog „Projekteinstellungen“.
    projectConfigurable: false,
    // KI-Assistent (aus whoami). models = in der INI hinterlegte Auswahlliste;
    // leer bedeutet: die mitgelieferte Liste aus util/aiModels.js gilt.
    ai: { enabled: false, model: '', writeMode: 'confirm', models: [] },
    // globale UI-Vorgaben aus [user]. updateLastmod ist dreiwertig:
    // null = beim Speichern nachfragen, true/false = ohne Nachfrage anwenden.
    // contentWidth und toolbarCollapsed hält die Oberfläche selbst nach (siehe
    // saveUserPrefs) — sie sind der Zustand nach dem nächsten Neuladen.
    ui: { contentWidth: 1200, toolbarCollapsed: false, sessionLifetimeHours: 8, updateLastmod: null },
    // Pro-Lizenz (aus whoami). configured = ein Schlüssel ist hinterlegt (ggf.
    // ungültig/falsche Domain). git = Git-Funktion nutzbar (Pro + Hugo-Projekt).
    // Die Lizenz gilt pro Webseite; licensable = aktivierbar (Mount-Datei vorhanden).
    license: { edition: 'community', licensee: null, domain: '', configured: false },
    licensable: false,
    git: false,
    audit: false, // SEO-Audit nutzbar (Pro + Hugo-Projekt) — wie git
    auditContent: false, // LLM-Content-Prüfung (Audit-Voraussetzung + KI-Schlüssel)
    speech: false, // Spracheingabe des Assistenten (Pro + [services] konfiguriert)
    pagespeed: false, // PageSpeed-Check (Pro + Hugo-Projekt) — Panel immer sichtbar
    pagespeedUrl: '', // gespeicherte Live-Adresse dieser Webseite (Mount-Konfig)
    pagespeedUrlDetected: '', // aus der Hugo-baseURL erkannte Adresse (Vorbelegung)
    // Live-Analyse (Pro + Hugo-Projekt + seo-success-Dienst konfiguriert). Strikt
    // getrennt von PageSpeed; der Benutzer wählt, welche Analyse er nutzt.
    liveAnalysis: false,
    liveAnalysisUrl: '', // gespeicherte Live-Analyse-Adresse (Mount-Konfig)
    siteUrlDetected: '', // aus der Hugo-baseURL erkannte Adresse (Vorbelegung, geteilt)
    review: false, // gestaffelte Veröffentlichung: Entwürfe zur Freigabe (Hugo-Projekt)
    // Automatikmodus des Cron-Verbesserers dieser Webseite ([improve] der
    // Mount-Konfiguration): Ist `auto` an, terminiert der Cron jeden erzeugten
    // Entwurf gleich selbst — zufällig im Tagesfenster, höchstens perDay je Tag.
    improve: { auto: false, windowStart: '07:00', windowEnd: '16:00', perDay: 3, effectivePerDay: 3, skipWeekends: true },
    // Pausenzustand der drei Cron-Skripte ([cron] der Mount-Konfiguration).
    // Die Views zeigen daraus einen Hinweis, wenn die zuständige Aufgabe
    // ausgesetzt ist — die Freigabe-Warteschlange bei pausiertem Build, die
    // Liste „zu verbessern“ bei pausiertem Verbesserer.
    cronPause: { pauseBuild: false, pauseImprove: false, pauseHealthcheck: false },
    // Warum eine gesperrte Funktion nicht nutzbar ist:
    // { git|audit|auditContent|pagespeed|liveAnalysis|speech:
    //   { available: bool, blockers: ['pro'|'project'|'aiKey'|'service'] } }.
    // Pro-Funktionen werden NICHT mehr ausgeblendet — die Ansichten zeigen
    // stattdessen einen Hinweis (ProGate), der erklärt, was die Funktion kann
    // und was ihr noch fehlt.
    features: {},
    // Rechnername der Hugo-baseURL (z. B. dev.opensourceerp.dev) — benennt die
    // Webseite im Browser-Tab. Leer, wenn das Projekt keine baseURL führt.
    siteHost: '',
    // Darf dieses Konto andere Konten verwalten? Nur beim Mehrbenutzer-
    // Verfahren und nur für die Rolle „admin“.
    manageUsers: false,
    // Darf dieses Konto konfigurieren (hugocms.ini, Projekteinstellungen,
    // Lizenz)? Beim Einzelbenutzer immer, beim Mehrbenutzer nur als „admin“.
    manageConfig: false,
  }),

  getters: {
    // Pro-Edition freigeschaltet (gültige, host-gebundene Lizenz).
    isPro: (state) => state.license.edition === 'pro',
    // Offene Voraussetzungen einer Funktion (leer = nutzbar). Unbekannte
    // Funktionsnamen gelten als nutzbar, damit ein älteres Backend ohne
    // features-Feld nichts sperrt.
    blockers: (state) => (feature) => state.features?.[feature]?.blockers ?? [],
    // true, wenn der Funktion NUR die Pro-Lizenz fehlt — dann genügt ein
    // Lizenzschlüssel, und der Hinweis darf das ohne Einschränkung sagen.
    onlyNeedsPro: (state) => (feature) => {
      const b = state.features?.[feature]?.blockers ?? []
      return b.length === 1 && b[0] === 'pro'
    },
  },

  actions: {
    async check() {
      this.applyState(await api.get('whoami'))
    },

    // Übernimmt eine vollständige Zustandsantwort. Genau diese Form liefern
    // BEIDE Wege — `whoami` beim Laden der Seite und `login` nach dem Anmelden.
    // Deshalb hier zentral: Wird die Antwort um ein Feld erweitert, gilt das
    // sofort für beide, statt in einem von ihnen zu veralten.
    applyState(data) {
      this.authenticated = data.authenticated
      this.user = data.user
      this.warnings = data.warnings ?? []
      this.setupRequired = data.setupRequired ?? false
      this.setupDefaults = data.defaults ?? null
      this.buildable = data.buildable ?? false
      this.reconfigurable = data.reconfigurable ?? false
      this.projectConfigurable = data.projectConfigurable ?? false
      this.ai = data.ai ?? { enabled: false, model: '', writeMode: 'confirm' }
      this.ui = data.ui ?? { contentWidth: 1200, toolbarCollapsed: false, sessionLifetimeHours: 8, updateLastmod: null }
      this.license = data.license ?? { edition: 'community', licensee: null, domain: '', configured: false }
      this.licensable = data.licensable ?? false
      this.git = data.git ?? false
      this.audit = data.audit ?? false
      this.auditContent = data.auditContent ?? false
      this.speech = data.speech ?? false
      this.pagespeed = data.pagespeed ?? false
      this.pagespeedUrl = data.pagespeedUrl ?? ''
      this.pagespeedUrlDetected = data.pagespeedUrlDetected ?? ''
      this.liveAnalysis = data.liveAnalysis ?? false
      this.liveAnalysisUrl = data.liveAnalysisUrl ?? ''
      this.siteUrlDetected = data.siteUrlDetected ?? ''
      this.review = data.review ?? false
      this.improve = data.improve ?? { auto: false, windowStart: '07:00', windowEnd: '16:00', perDay: 3, effectivePerDay: 3, skipWeekends: true }
      this.cronPause = data.cronPause ?? { pauseBuild: false, pauseImprove: false, pauseHealthcheck: false }
      this.features = data.features ?? {}
      this.siteHost = data.siteHost ?? ''
      this.manageUsers = data.manageUsers ?? false
      this.manageConfig = data.manageConfig ?? false
      setCsrfToken(data.csrf)
      this.ready = true
    },

    // Schaltet die automatische Terminierung des Cron-Verbesserers um. Fenster
    // und Tagesmenge bleiben unverändert (die stehen in den Projekteinstellungen).
    async setImproveAuto(enabled) {
      const data = await api.post('improveauto', { enabled })
      this.improve = data.improve ?? this.improve
      return this.improve
    },

    // Aktiviert eine Pro-Lizenz (Schlüssel an den Host gebunden). Danach den
    // vollen Status neu laden (git-Flag, Edition) und das Ergebnis zurückgeben.
    async activateLicense(key) {
      const info = await api.post('activate', { key })
      this.license = info
      await this.check()
      return info
    },

    // Aktuelle (rohe) Konfigurationswerte zum Vorbefüllen des Umkonfigurations-
    // Dialogs. Anmeldedaten sind bewusst nicht enthalten.
    async loadConfig() {
      return api.get('config')
    },

    // Schreibt die hugocms.ini neu (Verzeichnisse, Log, Hugo-Programm).
    async reconfigure(payload) {
      await api.post('reconfigure', payload)
    },

    // Holt die verfügbaren Claude-Modelle von der API und hinterlegt sie in der
    // hugocms.ini ([ai] models). Braucht einen GESPEICHERTEN API-Schlüssel.
    // Liefert die neue Liste und übernimmt sie zugleich für das Assistenten-
    // Panel, damit die Auswahl ohne Neuladen stimmt.
    async refreshAiModels() {
      const { models } = await api.post('aimodels')
      this.ai = { ...this.ai, models }
      return models
    },

    // Einstellungen DIESER Webseite (Mount-Konfiguration) zum Vorbefüllen des
    // Dialogs „Projekteinstellungen“ — das Gegenstück zu loadConfig.
    async loadProjectConfig() {
      return api.get('projectconfig')
    },

    // Schreibt die Einstellungen dieser Webseite in ihre Mount-Konfiguration.
    async projectReconfigure(payload) {
      await api.post('projectreconfigure', payload)
    },

    // Prüft den seo-success-Schlüssel (eingegeben oder hinterlegt) gegen den
    // Dienst, ohne etwas zu speichern. Bedient den Konfigurationsdialog und die
    // Kontingentanzeige der Live-Analyse. Wirft bei ungültigem Schlüssel oder
    // nicht erreichbarem Dienst; liefert sonst { valid, name, quota… }.
    async verifyService(payload) {
      return api.post('serviceverify', payload)
    },

    // Schreibt Einstellungen der [user]-Sektion. Das Feld-Objekt enthält nur die
    // geänderten Werte: { contentWidth?, toolbarCollapsed?, sessionLifetime?
    // (Stunden), updateLastmod? (null = im Editor nachfragen) }.
    // Fensterbreite und Werkzeugleiste schickt die Oberfläche selbsttätig; dort
    // gilt ein Fehler als unkritisch (die Einstellung hält dann bis zum
    // Neuladen). Der Konto-Dialog zeigt einen Fehler dagegen an.
    async saveUserPrefs(patch) {
      const data = await api.post('setuserprefs', patch)
      this.ui = { ...this.ui, ...(data.ui ?? patch) }
    },

    // Merkt die Benutzerwahl zum lastmod-Verhalten in [user] update_lastmod.
    async setUpdateLastmod(value) {
      await this.saveUserPrefs({ updateLastmod: value })
    },

    // Ändert die Anmeldedaten (Name/Passwort). Der Server beendet danach die
    // Sitzung; per anschließendem whoami den Zustand (abgemeldet) und ein
    // frisches CSRF-Token holen, damit die Login-Maske sauber funktioniert.
    async changeAccount(payload) {
      await api.post('account', payload)
      await this.check()
    },

    async setup(payload) {
      const data = await api.post('setup', payload)
      this.authenticated = data.authenticated
      this.user = data.user
      this.warnings = data.warnings ?? []
      this.setupRequired = false
      // Den vollständigen Status (buildable, CSRF-Token, echte Warnungen) kennt
      // erst der reguläre Connector-Pfad — im Setup-Schritt werden die Mounts
      // nicht geladen. Jetzt existiert die hugocms.ini, also per whoami
      // nachladen, damit u. a. der Veröffentlichen-Knopf sofort erscheint.
      if (data.authenticated) {
        try {
          await this.check()
        } catch {
          // Setup war erfolgreich; ein fehlgeschlagener Statusabruf darf den
          // Ablauf nicht stören — der Status vervollständigt sich beim nächsten
          // Laden.
        }
      }
    },

    async login(username, password) {
      // Der Server antwortet mit demselben vollständigen Zustand wie `whoami`
      // (inklusive frischem CSRF-Token). Ein nachgelagertes whoami wäre hier
      // riskant: Die erste Anfrage nach dem Login kann noch das alte
      // Sitzungs-Cookie tragen und käme als „nicht angemeldet" zurück.
      this.applyState(await api.post('login', { username, password }))
    },

    async logout() {
      await api.post('logout')
      this.authenticated = false
      this.user = null
      // Rechte des abgemeldeten Kontos dürfen nicht stehen bleiben: Sonst
      // zeigte die Oberfläche dem nächsten Anmelder kurzzeitig Einträge, die
      // ihm nicht zustehen.
      this.manageUsers = false
      this.manageConfig = false
    },
  },
})
