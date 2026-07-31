import { defineStore } from 'pinia'
import { api } from '../api/client'

// Benutzerverwaltung (Mehrbenutzer-Verfahren, Rolle admin) — als Overlay-Ansicht
// wie Systemstatus und Freigabe-Warteschlange, nicht als Dialog.
//
// Ob es die Ansicht überhaupt gibt, entscheidet der Server: Nur der
// Mehrbenutzer-Treiber bringt die Verwaltungsbefehle mit, und nur
// Administratoren dürfen sie aufrufen (auth.manageUsers aus whoami).
//
// Die vier Schreibbefehle liefern die frische Liste gleich mit — deshalb kommt
// die Ansicht ohne zweiten Aufruf auf den neuen Stand.
export const useUsersStore = defineStore('users', {
  state: () => ({
    open: false, // Overlay sichtbar
    users: [], // [{ name, role, sites, disabled, self }]
    sites: [], // bekannte Webseiten dieser Installation (Hosts)
    loading: false,
    error: null, // roher Fehler (die Ansicht übersetzt ihn)
  }),

  actions: {
    async openView() {
      this.open = true
      await this.fetch()
    },

    close() {
      this.open = false
      this.error = null
    },

    async fetch() {
      this.error = null
      this.loading = true
      try {
        const data = await api.get('users')
        this.users = data.users ?? []
        this.sites = data.sites ?? []
      } catch (e) {
        this.error = e
      } finally {
        this.loading = false
      }
    },

    // Die Schreibaktionen werfen bei einem Fehler weiter: Sie laufen aus
    // Formularen heraus, die den Fehler bei sich anzeigen — nicht in der Liste
    // dahinter, wo er verdeckt wäre.
    async create(payload) {
      const data = await api.post('usercreate', payload)
      this.users = data.users ?? this.users
    },

    // Rolle, Webseiten-Zuordnung oder Sperre ändern. Nicht genannte Felder
    // bleiben, wie sie sind.
    async update(payload) {
      const data = await api.post('userupdate', payload)
      this.users = data.users ?? this.users
    },

    // Passwort eines FREMDEN Kontos neu setzen („Passwort vergessen“). Das
    // eigene Passwort ändert man im Konto-Dialog mit Bestätigung.
    async resetPassword(username, password) {
      await api.post('userpassword', { username, password })
    },

    async remove(username) {
      const data = await api.post('userdelete', { username })
      this.users = data.users ?? this.users
    },
  },
})
