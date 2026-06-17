<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'

const { t } = useI18n()
const auth = useAuthStore()

const MIN_PASSWORD_LENGTH = 8

const model = defineModel({ type: Boolean, default: false })
const emit = defineEmits(['changed'])

const username = ref('')
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')

const saving = ref(false)
const error = ref(null)

// Beim Öffnen den aktuellen Anmeldenamen vorbefüllen, Passwortfelder leeren.
watch(model, (open) => {
  if (!open) return
  username.value = auth.user?.name ?? ''
  currentPassword.value = ''
  newPassword.value = ''
  newPasswordConfirm.value = ''
  error.value = null
})

async function submit() {
  error.value = null
  if (newPassword.value && newPassword.value !== newPasswordConfirm.value) {
    error.value = t('account.passwordMismatch')
    return
  }
  saving.value = true
  try {
    await auth.changeAccount({
      username: username.value,
      currentPassword: currentPassword.value,
      password: newPassword.value, // leer = Passwort unverändert
    })
    // Server hat die Sitzung beendet; App zeigt nun die Login-Maske.
    emit('changed')
    model.value = false
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog v-model="model" width="440" :persistent="saving">
    <v-card class="pa-2">
      <v-card-title class="text-h6">{{ $t('account.title') }}</v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('account.intro') }}</v-card-subtitle>
      <v-card-text>
        <v-form @submit.prevent="submit">
          <v-text-field
            v-model="username"
            :label="$t('account.username')"
            prepend-inner-icon="mdi-account"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="currentPassword"
            :label="$t('account.currentPassword')"
            type="password"
            prepend-inner-icon="mdi-lock"
            autocomplete="current-password"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-divider class="mb-3" />
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('account.newPasswordHint', [MIN_PASSWORD_LENGTH]) }}</div>
          <v-text-field
            v-model="newPassword"
            :label="$t('account.newPassword')"
            type="password"
            prepend-inner-icon="mdi-lock-plus"
            autocomplete="new-password"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="newPasswordConfirm"
            :label="$t('account.newPasswordConfirm')"
            type="password"
            prepend-inner-icon="mdi-lock-check"
            autocomplete="new-password"
            variant="outlined"
            density="comfortable"
          />

          <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
          <div class="text-caption text-medium-emphasis mt-3">{{ $t('account.note') }}</div>
        </v-form>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="model = false">{{ $t('account.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="saving" @click="submit">
          {{ $t('account.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
