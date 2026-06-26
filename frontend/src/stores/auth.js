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
    ai: { enabled: false, writeMode: 'confirm' }, // KI-Assistent (aus whoami)
    ui: { contentWidth: 1200 }, // globale UI-Vorgaben aus [user] (Fensterbreite)
    // Pro-Lizenz (aus whoami). configured = ein Schlüssel ist hinterlegt (ggf.
    // ungültig/falsche Domain). git = Git-Funktion nutzbar (Pro + Hugo-Projekt).
    // Die Lizenz gilt pro Webseite; licensable = aktivierbar (Mount-Datei vorhanden).
    license: { edition: 'community', licensee: null, domain: '', configured: false },
    licensable: false,
    git: false,
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
      this.ai = data.ai ?? { enabled: false, writeMode: 'confirm' }
      this.ui = data.ui ?? { contentWidth: 1200 }
      this.license = data.license ?? { edition: 'community', licensee: null, domain: '', configured: false }
      this.licensable = data.licensable ?? false
      this.git = data.git ?? false
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
