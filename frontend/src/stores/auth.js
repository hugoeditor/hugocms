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
    ai: { enabled: false, model: '', writeMode: 'confirm' }, // KI-Assistent (aus whoami)
    // globale UI-Vorgaben aus [user]. updateLastmod ist dreiwertig:
    // null = beim Speichern nachfragen, true/false = ohne Nachfrage anwenden.
    ui: { contentWidth: 1200, updateLastmod: null },
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
    review: false, // gestaffelte Veröffentlichung: Entwürfe zur Freigabe (Hugo-Projekt)
  }),

  getters: {
    // Pro-Edition freigeschaltet (gültige, host-gebundene Lizenz).
    isPro: (state) => state.license.edition === 'pro',
  },

  actions: {
    async check() {
      const data = await api.get('whoami')
      this.authenticated = data.authenticated
      this.user = data.user
      this.warnings = data.warnings ?? []
      this.setupRequired = data.setupRequired ?? false
      this.setupDefaults = data.defaults ?? null
      this.buildable = data.buildable ?? false
      this.reconfigurable = data.reconfigurable ?? false
      this.projectConfigurable = data.projectConfigurable ?? false
      this.ai = data.ai ?? { enabled: false, model: '', writeMode: 'confirm' }
      this.ui = data.ui ?? { contentWidth: 1200, updateLastmod: null }
      this.license = data.license ?? { edition: 'community', licensee: null, domain: '', configured: false }
      this.licensable = data.licensable ?? false
      this.git = data.git ?? false
      this.audit = data.audit ?? false
      this.auditContent = data.auditContent ?? false
      this.speech = data.speech ?? false
      this.pagespeed = data.pagespeed ?? false
      this.pagespeedUrl = data.pagespeedUrl ?? ''
      this.pagespeedUrlDetected = data.pagespeedUrlDetected ?? ''
      this.review = data.review ?? false
      setCsrfToken(data.csrf)
      this.ready = true
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

    // Einstellungen DIESER Webseite (Mount-Konfiguration) zum Vorbefüllen des
    // Dialogs „Projekteinstellungen“ — das Gegenstück zu loadConfig.
    async loadProjectConfig() {
      return api.get('projectconfig')
    },

    // Schreibt die Einstellungen dieser Webseite in ihre Mount-Konfiguration.
    async projectReconfigure(payload) {
      await api.post('projectreconfigure', payload)
    },

    // Prüft den Spracheingabe-Schlüssel (eingegeben oder hinterlegt) gegen den
    // Dienst, ohne etwas zu speichern. Wirft bei ungültigem Schlüssel oder
    // nicht erreichbarem Dienst; liefert sonst { valid, quota… }.
    async verifySpeech(payload) {
      return api.post('speechverify', payload)
    },

    // Merkt die Benutzerwahl zum lastmod-Verhalten in [user] update_lastmod.
    async setUpdateLastmod(value) {
      await api.post('setupdatelastmod', { value })
      this.ui = { ...this.ui, updateLastmod: value }
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
      const data = await api.post('login', { username, password })
      this.authenticated = data.authenticated
      this.user = data.user
      // Frisches CSRF-Token der angemeldeten Sitzung übernehmen, damit der erste
      // Schreibbefehl nach dem Login gelingt — auch nach einem Sitzungsablauf,
      // bei dem das vorige Token verworfen wurde.
      if (data.csrf) setCsrfToken(data.csrf)
    },

    async logout() {
      await api.post('logout')
      this.authenticated = false
      this.user = null
    },
  },
})
