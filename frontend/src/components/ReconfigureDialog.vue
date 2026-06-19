<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'

const { t } = useI18n()
const auth = useAuthStore()

// Sichtbarkeit als v-model (Vue 3.4+). Der Button in der Titelleiste öffnet.
const model = defineModel({ type: Boolean, default: false })
const emit = defineEmits(['saved'])

const sessionPath = ref('')
const logFile = ref('')
const logLevel = ref('warning')
const logLevels = ref(['debug', 'info', 'warning', 'error'])
const hugoBin = ref('')

// KI-Assistent. Der Schlüssel wird nie geladen (Geheimnis); leeres Feld lässt
// ihn unverändert. aiConfigured zeigt nur an, ob bereits einer gesetzt ist.
const aiApiKey = ref('')
const aiModel = ref('claude-opus-4-8')
const aiWriteMode = ref('confirm')
const aiConfigured = ref(false)
const aiModels = ['claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5']
const writeModeItems = computed(() =>
  ['readonly', 'confirm', 'auto'].map((v) => ({ value: v, title: t(`assistant.mode.${v}`) })),
)

const loading = ref(false) // Laden der aktuellen Werte beim Öffnen
const saving = ref(false)
const error = ref(null)

// Beim Öffnen die aktuellen (rohen) Werte aus der hugocms.ini vorbefüllen.
watch(model, async (open) => {
  if (!open) return
  error.value = null
  loading.value = true
  try {
    const cfg = await auth.loadConfig()
    sessionPath.value = cfg.sessionPath ?? ''
    logFile.value = cfg.logFile ?? ''
    logLevel.value = cfg.logLevel ?? 'warning'
    logLevels.value = cfg.logLevels ?? ['debug', 'info', 'warning', 'error']
    hugoBin.value = cfg.hugoBin ?? ''
    aiApiKey.value = ''
    aiModel.value = cfg.aiModel || 'claude-opus-4-8'
    aiWriteMode.value = cfg.aiWriteMode || 'confirm'
    aiConfigured.value = !!cfg.aiConfigured
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
})

async function submit() {
  saving.value = true
  error.value = null
  try {
    await auth.reconfigure({
      sessionPath: sessionPath.value,
      logFile: logFile.value,
      logLevel: logLevel.value,
      hugoBin: hugoBin.value,
      aiApiKey: aiApiKey.value, // leer = unverändert
      aiModel: aiModel.value,
      aiWriteMode: aiWriteMode.value,
    })
    emit('saved')
    model.value = false
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog v-model="model" width="480" :persistent="saving">
    <v-card class="pa-2">
      <v-card-title class="text-h6">{{ $t('reconfigure.title') }}</v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('reconfigure.intro') }}</v-card-subtitle>
      <v-card-text>
        <v-skeleton-loader v-if="loading" type="article" />
        <v-form v-else @submit.prevent="submit">
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('setup.sessionPathHint') }}</div>
          <v-text-field
            v-model="sessionPath"
            :label="$t('setup.sessionPath')"
            prepend-inner-icon="mdi-folder-account"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('setup.logFileHint') }}</div>
          <v-text-field
            v-model="logFile"
            :label="$t('setup.logFile')"
            prepend-inner-icon="mdi-file-document-outline"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('setup.logLevelHint') }}</div>
          <v-select
            v-model="logLevel"
            :items="logLevels"
            :label="$t('setup.logLevel')"
            prepend-inner-icon="mdi-tune"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('setup.hugoBinHint') }}</div>
          <v-text-field
            v-model="hugoBin"
            :label="$t('setup.hugoBin')"
            prepend-inner-icon="mdi-language-go"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />

          <v-divider class="my-3" />
          <div class="text-subtitle-2 mb-2">{{ $t('aiConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-1">
            {{ aiConfigured ? $t('aiConfig.apiKeyHintSet') : $t('aiConfig.apiKeyHintUnset') }}
          </div>
          <v-text-field
            v-model="aiApiKey"
            :label="$t('aiConfig.apiKey')"
            type="password"
            prepend-inner-icon="mdi-key-variant"
            autocomplete="off"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-select
            v-model="aiModel"
            :items="aiModels"
            :label="$t('aiConfig.model')"
            prepend-inner-icon="mdi-brain"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-1">{{ $t('aiConfig.writeModeHint') }}</div>
          <v-select
            v-model="aiWriteMode"
            :items="writeModeItems"
            :label="$t('aiConfig.writeMode')"
            prepend-inner-icon="mdi-shield-edit-outline"
            variant="outlined"
            density="comfortable"
          />

          <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
          <div class="text-caption text-medium-emphasis mt-3">{{ $t('reconfigure.note') }}</div>
        </v-form>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="model = false">{{ $t('reconfigure.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="saving" :disabled="loading" @click="submit">
          {{ $t('reconfigure.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
