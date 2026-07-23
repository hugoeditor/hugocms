<script setup>
import { computed, ref, watch } from 'vue'
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

// Automatikmodus des Cron-Verbesserers: Ist er an, terminiert der Cron jeden
// erzeugten Entwurf gleich selbst — zu einem zufälligen Zeitpunkt im Tagesfenster
// und höchstens `improvePerDay` Stück je Tag.
const improveAuto = ref(false)
const improveWindowStart = ref('07:00')
const improveWindowEnd = ref('16:00')
const improvePerDay = ref(3)

// „HH:MM“ mit Stunde 0–23 und Minute 0–59. Ungültiges würde der Server auf die
// Vorgabe zurückfallen lassen — besser, es fällt schon im Formular auf.
const TIME_RE = /^([01]?\d|2[0-3]):[0-5]\d$/
const timeRule = (v) => TIME_RE.test(String(v ?? '').trim()) || t('projectConfig.timeInvalid')
// Länge des Fensters in Minuten; null, solange die Eingaben unvollständig oder
// rückwärts gerichtet sind.
const windowMinutes = computed(() => {
  const a = String(improveWindowStart.value).trim()
  const b = String(improveWindowEnd.value).trim()
  if (!TIME_RE.test(a) || !TIME_RE.test(b)) return null
  const [ah, am] = a.split(':').map(Number)
  const [bh, bm] = b.split(':').map(Number)
  const diff = bh * 60 + bm - (ah * 60 + am)
  return diff > 0 ? diff : null
})
// Ein Fenster muss vorwärts laufen, sonst gibt es keine Zeitpunkte darin.
const windowValid = computed(() => windowMinutes.value !== null)

// Mehr Freigaben als Minuten im Fenster kann der Server nicht unterbringen —
// er kürzt dann still auf die Zahl der Minuten. Das soll hier auffallen,
// solange es sich noch ändern lässt.
const effectivePerDay = computed(() =>
  windowMinutes.value === null ? null : Math.min(Number(improvePerDay.value) || 1, windowMinutes.value),
)
const perDayTooHigh = computed(
  () => windowMinutes.value !== null && Number(improvePerDay.value) > windowMinutes.value,
)

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
    improveAuto.value = !!cfg.improveAuto
    improveWindowStart.value = cfg.improveWindowStart ?? '07:00'
    improveWindowEnd.value = cfg.improveWindowEnd ?? '16:00'
    improvePerDay.value = cfg.improvePerDay ?? 3
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
      improveAuto: improveAuto.value,
      improveWindowStart: improveWindowStart.value,
      improveWindowEnd: improveWindowEnd.value,
      improvePerDay: improvePerDay.value,
    })
    // Der Schalter in der Liste „zu verbessern“ liest denselben Zustand aus
    // whoami — nach dem Speichern nachziehen.
    await auth.check()
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

          <!-- Automatische Terminierung des Cron-Verbesserers -->
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-2">{{ $t('projectConfig.improveSection') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ $t('projectConfig.improveHint') }}
          </div>
          <v-switch
            v-model="improveAuto"
            :label="$t('projectConfig.improveAuto')"
            color="primary"
            density="compact"
            hide-details
            class="mb-2"
          />
          <div class="d-flex" style="gap: 10px">
            <v-text-field
              v-model="improveWindowStart"
              :label="$t('projectConfig.improveWindowStart')"
              :rules="[timeRule]"
              :disabled="!improveAuto"
              prepend-inner-icon="mdi-clock-start"
              placeholder="07:00"
              variant="outlined"
              density="comfortable"
            />
            <v-text-field
              v-model="improveWindowEnd"
              :label="$t('projectConfig.improveWindowEnd')"
              :rules="[timeRule]"
              :disabled="!improveAuto"
              prepend-inner-icon="mdi-clock-end"
              placeholder="16:00"
              variant="outlined"
              density="comfortable"
            />
          </div>
          <v-alert
            v-if="improveAuto && !windowValid"
            type="warning"
            density="compact"
            variant="tonal"
            class="mb-2"
          >
            {{ $t('projectConfig.improveWindowInvalid') }}
          </v-alert>
          <v-text-field
            v-model.number="improvePerDay"
            :label="$t('projectConfig.improvePerDay')"
            :disabled="!improveAuto"
            type="number"
            min="1"
            max="50"
            prepend-inner-icon="mdi-counter"
            variant="outlined"
            density="comfortable"
            :hint="$t('projectConfig.improvePerDayHint')"
            persistent-hint
          />
          <v-alert
            v-if="improveAuto && perDayTooHigh"
            type="warning"
            density="compact"
            variant="tonal"
            class="mt-2"
          >
            {{ $t('projectConfig.improvePerDayCapped', [windowMinutes, effectivePerDay]) }}
          </v-alert>

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
