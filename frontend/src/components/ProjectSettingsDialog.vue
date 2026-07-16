<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'

const { t } = useI18n()
const auth = useAuthStore()

// Sichtbarkeit als v-model (Vue 3.4+). Der Knopf in der Werkzeugschiene öffnet.
const model = defineModel({ type: Boolean, default: false })
const emit = defineEmits(['saved'])

// SEO-Bericht: Ausschlüsse NUR für diese Webseite, eine je Zeile. Sie ergänzen
// die globalen aus dem Konfigurationsdialog und die fest verdrahteten; keine
// Ebene kann eine andere aufheben.
const seoExcludePrefixes = ref('')
const seoExcludeFiles = ref('')

const loading = ref(false) // Laden der aktuellen Werte beim Öffnen
const saving = ref(false)
const error = ref(null)

// Beim Öffnen die aktuellen Werte aus der Mount-Konfiguration vorbefüllen.
watch(model, async (open) => {
  if (!open) return
  error.value = null
  loading.value = true
  try {
    const cfg = await auth.loadProjectConfig()
    seoExcludePrefixes.value = cfg.seoExcludePrefixes ?? ''
    seoExcludeFiles.value = cfg.seoExcludeFiles ?? ''
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
    await auth.projectReconfigure({
      seoExcludePrefixes: seoExcludePrefixes.value,
      seoExcludeFiles: seoExcludeFiles.value,
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
      <v-card-title class="d-flex align-center text-h6">
        <span>{{ $t('projectConfig.title') }}</span>
        <v-spacer />
        <v-btn
          icon="mdi-content-save"
          variant="text"
          density="comfortable"
          color="primary"
          :loading="saving"
          :disabled="saving || loading"
          :aria-label="$t('projectConfig.submit')"
          @click="submit"
        />
        <v-btn
          icon="mdi-close"
          variant="text"
          density="comfortable"
          :disabled="saving"
          :aria-label="$t('projectConfig.cancel')"
          @click="model = false"
        />
      </v-card-title>
      <v-card-subtitle class="text-wrap mb-2">{{ $t('projectConfig.intro') }}</v-card-subtitle>
      <v-card-text>
        <v-skeleton-loader v-if="loading" type="article" />
        <v-form v-else @submit.prevent="submit">
          <div class="text-subtitle-2 mb-2">{{ $t('seoConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ $t('projectConfig.excludePrefixesHint') }}
          </div>
          <v-textarea
            v-model="seoExcludePrefixes"
            :label="$t('seoConfig.excludePrefixes')"
            :placeholder="$t('seoConfig.excludePrefixesPlaceholder')"
            prepend-inner-icon="mdi-folder-remove-outline"
            variant="outlined"
            density="comfortable"
            rows="3"
            auto-grow
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">
            {{ $t('projectConfig.excludeFilesHint') }}
          </div>
          <v-textarea
            v-model="seoExcludeFiles"
            :label="$t('seoConfig.excludeFiles')"
            :placeholder="$t('seoConfig.excludeFilesPlaceholder')"
            prepend-inner-icon="mdi-file-remove-outline"
            variant="outlined"
            density="comfortable"
            rows="3"
            auto-grow
          />

          <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
          <div class="text-caption text-medium-emphasis mt-3">{{ $t('projectConfig.note') }}</div>
        </v-form>
      </v-card-text>
      <v-card-actions v-if="!loading">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="model = false">
          {{ $t('projectConfig.cancel') }}
        </v-btn>
        <v-btn color="primary" variant="flat" :loading="saving" :disabled="loading" @click="submit">
          {{ $t('projectConfig.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>
