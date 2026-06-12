<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const defaults = auth.setupDefaults ?? {}

const MIN_PASSWORD_LENGTH = 8

const username = ref(defaults.username ?? 'admin')
const password = ref('')
const passwordConfirm = ref('')
const sessionPath = ref(defaults.sessionPath ?? 'var/sessions')
const logFile = ref(defaults.logFile ?? 'log/hugocms.log')
const logLevel = ref(defaults.logLevel ?? 'warning')
const logLevels = defaults.logLevels ?? ['debug', 'info', 'warning', 'error']

const loading = ref(false)
const error = ref(null)

async function submit() {
  error.value = null
  if (password.value.length < MIN_PASSWORD_LENGTH) {
    error.value = `Das Passwort muss mindestens ${MIN_PASSWORD_LENGTH} Zeichen lang sein.`
    return
  }
  if (password.value !== passwordConfirm.value) {
    error.value = 'Die Passwörter stimmen nicht überein.'
    return
  }

  loading.value = true
  try {
    // Bei Erfolg meldet der Server direkt an (authenticated=true); App.vue
    // wechselt daraufhin reaktiv in den Dateimanager.
    await auth.setup({
      username: username.value,
      password: password.value,
      sessionPath: sessionPath.value,
      logFile: logFile.value,
      logLevel: logLevel.value,
    })
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-main class="d-flex align-center justify-center bg-grey-lighten-3">
    <v-card width="460" class="pa-4" elevation="4">
      <v-card-title class="text-h6">HugoCMS einrichten</v-card-title>
      <v-card-subtitle class="text-wrap">
        Es wurde noch keine Konfiguration gefunden. Lege hier den Zugang an —
        daraus wird die hugocms.ini erzeugt.
      </v-card-subtitle>
      <v-card-text>
        <v-form @submit.prevent="submit">
          <div class="text-subtitle-2 mb-2">Anmeldung</div>
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
            :hint="`Mindestens ${MIN_PASSWORD_LENGTH} Zeichen`"
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="passwordConfirm"
            label="Passwort bestätigen"
            type="password"
            prepend-inner-icon="mdi-lock-check"
            variant="outlined"
            density="comfortable"
          />

          <v-divider class="my-3" />
          <div class="text-subtitle-2 mb-2">Verzeichnisse und Logging</div>
          <v-text-field
            v-model="sessionPath"
            label="Sitzungsverzeichnis"
            prepend-inner-icon="mdi-folder-account"
            hint="Relativ zum backend-Verzeichnis; wird angelegt, falls es fehlt."
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="logFile"
            label="Logdatei"
            prepend-inner-icon="mdi-file-document-outline"
            hint="Relativ zum backend-Verzeichnis; das Verzeichnis wird angelegt."
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-select
            v-model="logLevel"
            :items="logLevels"
            label="Log-Stufe"
            prepend-inner-icon="mdi-tune"
            variant="outlined"
            density="comfortable"
          />

          <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>
          <v-btn type="submit" color="primary" block :loading="loading">
            Einrichten und anmelden
          </v-btn>
        </v-form>
      </v-card-text>
    </v-card>
  </v-main>
</template>
