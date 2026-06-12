<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import LanguageSwitcher from './LanguageSwitcher.vue'

const { t } = useI18n()
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
    error.value = t('setup.passwordTooShort', [MIN_PASSWORD_LENGTH])
    return
  }
  if (password.value !== passwordConfirm.value) {
    error.value = t('setup.passwordMismatch')
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
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-main class="d-flex flex-column align-center justify-center bg-grey-lighten-3">
    <div class="d-flex justify-end setup-card mb-2">
      <LanguageSwitcher />
    </div>
    <v-card width="460" class="pa-4 setup-card" elevation="4">
      <v-card-title class="text-h6">{{ $t('setup.title') }}</v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('setup.intro') }}</v-card-subtitle>
      <v-card-text>
        <v-form @submit.prevent="submit">
          <div class="text-subtitle-2 mb-2">{{ $t('setup.sectionLogin') }}</div>
          <v-text-field
            v-model="username"
            :label="$t('setup.username')"
            prepend-inner-icon="mdi-account"
            autofocus
            variant="outlined"
            density="comfortable"
          />
          <v-text-field
            v-model="password"
            :label="$t('setup.password')"
            type="password"
            prepend-inner-icon="mdi-lock"
            :hint="$t('setup.passwordHint', [MIN_PASSWORD_LENGTH])"
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="passwordConfirm"
            :label="$t('setup.passwordConfirm')"
            type="password"
            prepend-inner-icon="mdi-lock-check"
            variant="outlined"
            density="comfortable"
          />

          <v-divider class="my-3" />
          <div class="text-subtitle-2 mb-2">{{ $t('setup.sectionDirs') }}</div>
          <v-text-field
            v-model="sessionPath"
            :label="$t('setup.sessionPath')"
            prepend-inner-icon="mdi-folder-account"
            :hint="$t('setup.sessionPathHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="logFile"
            :label="$t('setup.logFile')"
            prepend-inner-icon="mdi-file-document-outline"
            :hint="$t('setup.logFileHint')"
            persistent-hint
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-select
            v-model="logLevel"
            :items="logLevels"
            :label="$t('setup.logLevel')"
            prepend-inner-icon="mdi-tune"
            variant="outlined"
            density="comfortable"
          />

          <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>
          <v-btn type="submit" color="primary" block :loading="loading">
            {{ $t('setup.submit') }}
          </v-btn>
        </v-form>
      </v-card-text>
    </v-card>
  </v-main>
</template>

<style scoped>
.setup-card {
  width: 460px;
}
</style>
