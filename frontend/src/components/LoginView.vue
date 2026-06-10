<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

const username = ref('')
const password = ref('')
const loading = ref(false)
const error = ref(null)

async function submit() {
  loading.value = true
  error.value = null
  try {
    await auth.login(username.value, password.value)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-main class="d-flex align-center justify-center bg-grey-lighten-3">
    <v-card width="380" class="pa-4" elevation="4">
      <v-card-title class="text-h6">Anmeldung</v-card-title>
      <v-card-text>
        <v-form @submit.prevent="submit">
          <v-text-field
            v-model="username"
            label="Benutzername"
            prepend-inner-icon="mdi-account"
            autofocus
            variant="outlined"
            density="comfortable"
          />
          <v-text-field
            v-model="password"
            label="Passwort"
            type="password"
            prepend-inner-icon="mdi-lock"
            variant="outlined"
            density="comfortable"
          />
          <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>
          <v-btn type="submit" color="primary" block :loading="loading">Anmelden</v-btn>
        </v-form>
      </v-card-text>
    </v-card>
  </v-main>
</template>
