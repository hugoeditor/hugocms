<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import LanguageSwitcher from './LanguageSwitcher.vue'

const { t } = useI18n()
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
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-main class="d-flex flex-column align-center justify-center bg-grey-lighten-3">
    <div class="d-flex justify-end login-card mb-2">
      <LanguageSwitcher />
    </div>
    <v-card width="380" class="pa-4 login-card" elevation="4">
      <v-card-title class="text-h6">{{ $t('login.title') }}</v-card-title>
      <v-card-text>
        <v-form @submit.prevent="submit">
          <v-text-field
            v-model="username"
            :label="$t('login.username')"
            prepend-inner-icon="mdi-account"
            autofocus
            variant="outlined"
            density="comfortable"
          />
          <v-text-field
            v-model="password"
            :label="$t('login.password')"
            type="password"
            prepend-inner-icon="mdi-lock"
            variant="outlined"
            density="comfortable"
          />
          <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>
          <v-btn type="submit" color="primary" block :loading="loading">{{ $t('login.submit') }}</v-btn>
        </v-form>
      </v-card-text>
    </v-card>
  </v-main>
</template>

<style scoped>
.login-card {
  width: 380px;
}
</style>
