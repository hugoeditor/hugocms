import { defineStore } from 'pinia'
import { api } from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    authenticated: false,
    ready: false, // true, sobald der erste whoami-Aufruf beantwortet wurde
  }),

  actions: {
    async check() {
      const data = await api.get('whoami')
      this.authenticated = data.authenticated
      this.user = data.user
      this.ready = true
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
