<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'

const { t } = useI18n()
const auth = useAuthStore()

// Sichtbarkeit als v-model (Vue 3.4+). Ein Knopf in der Titelleiste öffnet.
const model = defineModel({ type: Boolean, default: false })
const emit = defineEmits(['activated'])

const key = ref('')
const saving = ref(false)
const error = ref(null)

// Beim Öffnen Eingabefeld und Fehler zurücksetzen.
watch(model, (open) => {
  if (open) {
    key.value = ''
    error.value = null
  }
})

async function submit() {
  if (saving.value || key.value.trim() === '') return
  saving.value = true
  error.value = null
  try {
    await auth.activateLicense(key.value.trim())
    emit('activated')
    model.value = false
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog v-model="model" width="560" :persistent="saving">
    <v-card class="pa-2">
      <v-card-title class="d-flex align-center text-h6">
        <v-icon icon="mdi-license" color="primary" class="mr-2" />
        {{ $t('license.title') }}
      </v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('license.intro') }}</v-card-subtitle>
      <v-card-text>
        <!-- Aktueller Status -->
        <div class="d-flex align-center mb-1">
          <span class="text-medium-emphasis mr-2">{{ $t('license.edition') }}:</span>
          <v-chip
            :color="auth.isPro ? 'success' : 'default'"
            size="small"
            :prepend-icon="auth.isPro ? 'mdi-check-decagram' : 'mdi-account-outline'"
            label
          >
            {{ auth.isPro ? $t('license.editionPro') : $t('license.editionCommunity') }}
          </v-chip>
        </div>
        <div v-if="auth.isPro && auth.license.licensee" class="text-body-2 mb-1">
          {{ $t('license.licensee') }}: <strong>{{ auth.license.licensee }}</strong>
        </div>
        <div class="text-body-2 mb-1">
          {{ $t('license.domain') }}: <code>{{ auth.license.domain }}</code>
        </div>
        <!-- Schlüssel hinterlegt, aber ungültig (z. B. für eine andere Domain). -->
        <v-alert
          v-if="auth.license.configured && !auth.isPro"
          type="warning"
          density="compact"
          class="mt-2"
          :text="$t('license.configuredInvalid')"
        />

        <v-divider class="my-3" />

        <!-- Aktivierung nur möglich, wenn eine Mount-Datei geladen wurde
             (dorthin wird der Schlüssel geschrieben). -->
        <v-alert
          v-if="!auth.licensable"
          type="info"
          density="compact"
          class="mb-2"
          :text="$t('license.notActivatable')"
        />
        <div class="text-caption text-medium-emphasis mb-1">{{ $t('license.keyHint') }}</div>
        <v-textarea
          v-model="key"
          :label="$t('license.key')"
          prepend-inner-icon="mdi-key-variant"
          variant="outlined"
          density="comfortable"
          rows="3"
          auto-grow
          autocomplete="off"
          spellcheck="false"
          :disabled="!auth.licensable"
          @keydown.enter.ctrl.prevent="submit"
        />

        <v-alert v-if="error" type="error" density="compact" class="mt-1">{{ error }}</v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="model = false">{{ $t('app.close') }}</v-btn>
        <v-btn
          color="primary"
          variant="flat"
          :loading="saving"
          :disabled="key.trim() === '' || !auth.licensable"
          @click="submit"
        >
          {{ $t('license.activate') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
