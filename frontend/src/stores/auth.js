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
  }),

  actions: {
    async check() {
      const data = await api.get('whoami')
      this.authenticated = data.authenticated
      this.user = data.user
      this.warnings = data.warnings ?? []
      this.setupRequired = data.setupRequired ?? false
      this.setupDefaults = data.defaults ?? null
      this.buildable = data.buildable ?? false
      setCsrfToken(data.csrf)
      this.ready = true
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
    },

    async logout() {
      await api.post('logout')
      this.authenticated = false
      this.user = null
    },
  },
})
