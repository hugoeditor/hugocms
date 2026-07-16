<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import { AI_MODELS } from '../util/aiModels'

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
// --cleanDestinationDir: leert vor dem Build das Zielverzeichnis (siehe Hinweis).
const hugoClean = ref(false)

// KI-Assistent. Der Schlüssel wird nie geladen (Geheimnis); leeres Feld lässt
// ihn unverändert. aiConfigured zeigt nur an, ob bereits einer gesetzt ist.
const aiApiKey = ref('')
const aiModel = ref('claude-opus-4-8')
// Cron-Verbesserer und Content-Qualität: leerer Wert = „wie Assistenten-Modell“.
const aiModelCron = ref('')
const aiModelAudit = ref('')
const aiWriteMode = ref('confirm')
const aiConfigured = ref(false)
const aiModels = AI_MODELS
// Auswahl für Cron/Audit: zusätzlich die Option „wie Assistenten-Modell“ (leer).
const aiSubModels = computed(() => [
  { value: '', title: t('aiConfig.modelSame') },
  ...aiModels.map((m) => ({ value: m, title: m })),
])
const writeModeItems = computed(() =>
  ['readonly', 'confirm', 'auto'].map((v) => ({ value: v, title: t(`assistant.mode.${v}`) })),
)

// Spracheingabe (Pro-Dienst). Der Schlüssel wird nie geladen (Geheimnis); leeres
// Feld lässt ihn unverändert. Die URL ist die Basis-Adresse des Dienstes; fehlt
// ein Eintrag in der hugocms.ini, wird der Standard vorbefüllt.
const DEFAULT_SPEECH_URL = 'https://api.hugocms.com/'
const speechUrl = ref(DEFAULT_SPEECH_URL)
const speechKey = ref('')
const speechConfigured = ref(false)

// PageSpeed-Check (Pro-Dienst). Nur der (globale) Google-Schlüssel — optional und
// ein Geheimnis: leeres Feld = unverändert. Die zu messende Live-Adresse steht
// pro Webseite in der Mount-Konfiguration und wird im PageSpeed-Panel gesetzt.
const pagespeedKey = ref('')
const pagespeedConfigured = ref(false)

// E-Mail-Versand (Gesundheitscheck). Das SMTP-Passwort wird nie geladen
// (Geheimnis); leeres Feld lässt es unverändert. mailPassConfigured zeigt nur
// an, ob bereits eines gesetzt ist.
const mailHost = ref('')
const mailPort = ref('')
const mailSecurity = ref('tls')
const mailUser = ref('')
const mailPass = ref('')
const mailFrom = ref('')
const mailTo = ref('')
const mailPassConfigured = ref(false)
const mailSecurities = ref(['tls', 'ssl', 'none'])

// SEO-Bericht (Pro-Funktion): zusätzliche Ausschluss-Präfixe, eine je Zeile.
// Die fest verdrahteten Ausschlüsse (edit/, cms-api/, cms-admin/ und die
// Papierkörbe der Mounts) stehen NICHT hier und lassen sich nicht entfernen.
const seoExcludePrefixes = ref('')
// Einzelne ausgeschlossene Dateien (exakter Pfad, eine je Zeile).
const seoExcludeFiles = ref('')
const mailSecurityItems = computed(() =>
  mailSecurities.value.map((v) => ({ value: v, title: t(`mailConfig.security.${v}`) })),
)

const loading = ref(false) // Laden der aktuellen Werte beim Öffnen
const saving = ref(false)
const error = ref(null)

// Sprungnavigation zu den Formularabschnitten (analog zu den Kategorie-Filtern
// im SEO-Bericht). Reihenfolge wie im Formular; jeder Knopf scrollt zur zugehörigen
// Überschrift. Die Elemente werden per Funktions-Ref registriert.
const sections = [
  { key: 'ai', label: 'aiConfig.section' },
  { key: 'speech', label: 'speechConfig.section' },
  { key: 'mail', label: 'mailConfig.section' },
  { key: 'seo', label: 'seoConfig.section' },
  { key: 'pagespeed', label: 'pagespeedConfig.section' },
]
const sectionEls = {}
function registerSection(key) {
  return (el) => {
    if (el) sectionEls[key] = el
  }
}
function scrollToSection(key) {
  sectionEls[key]?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

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
    hugoClean.value = !!cfg.hugoClean
    aiApiKey.value = ''
    aiModel.value = cfg.aiModel || 'claude-opus-4-8'
    aiModelCron.value = cfg.aiModelCron || ''
    aiModelAudit.value = cfg.aiModelAudit || ''
    aiWriteMode.value = cfg.aiWriteMode || 'confirm'
    aiConfigured.value = !!cfg.aiConfigured
    speechUrl.value = cfg.speechUrl || DEFAULT_SPEECH_URL
    speechKey.value = ''
    speechConfigured.value = !!cfg.speechConfigured
    pagespeedKey.value = ''
    pagespeedConfigured.value = !!cfg.pagespeedConfigured
    mailHost.value = cfg.mailHost ?? ''
    mailPort.value = cfg.mailPort ?? ''
    mailSecurity.value = cfg.mailSecurity || 'tls'
    mailSecurities.value = cfg.mailSecurities ?? ['tls', 'ssl', 'none']
    mailUser.value = cfg.mailUser ?? ''
    mailPass.value = ''
    mailFrom.value = cfg.mailFrom ?? ''
    mailTo.value = cfg.mailTo ?? ''
    mailPassConfigured.value = !!cfg.mailPassConfigured
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
    // Ist ein (neuer) Spracheingabe-Schlüssel eingegeben, vor dem Speichern
    // gegen den Dienst prüfen; bei ungültigem Schlüssel oder nicht erreichbarem
    // Dienst nicht speichern (der Fehler landet im gemeinsamen catch).
    if (speechKey.value.trim()) {
      await auth.verifySpeech({ speechKey: speechKey.value, speechUrl: speechUrl.value })
    }
    await auth.reconfigure({
      sessionPath: sessionPath.value,
      logFile: logFile.value,
      logLevel: logLevel.value,
      hugoBin: hugoBin.value,
      hugoClean: hugoClean.value,
      aiApiKey: aiApiKey.value, // leer = unverändert
      aiModel: aiModel.value,
      aiModelCron: aiModelCron.value, // leer = wie Assistenten-Modell
      aiModelAudit: aiModelAudit.value,
      aiWriteMode: aiWriteMode.value,
      speechKey: speechKey.value, // leer = unverändert
      speechUrl: speechUrl.value,
      pagespeedKey: pagespeedKey.value, // leer = unverändert
      mailHost: mailHost.value,
      mailPort: mailPort.value,
      mailSecurity: mailSecurity.value,
      mailUser: mailUser.value,
      mailPass: mailPass.value, // leer = unverändert
      mailFrom: mailFrom.value,
      mailTo: mailTo.value,
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
        <span>{{ $t('reconfigure.title') }}</span>
        <v-spacer />
        <v-btn
          icon="mdi-content-save"
          variant="text"
          density="comfortable"
          color="primary"
          :loading="saving"
          :disabled="saving || loading"
          :aria-label="$t('reconfigure.submit')"
          @click="submit"
        />
        <v-btn
          icon="mdi-close"
          variant="text"
          density="comfortable"
          :disabled="saving"
          :aria-label="$t('reconfigure.cancel')"
          @click="model = false"
        />
      </v-card-title>
      <v-card-subtitle class="text-wrap mb-2">{{ $t('reconfigure.intro') }}</v-card-subtitle>
      <!-- Sprungnavigation zu den Abschnitten (analog zu den Kategorie-Filtern
           im SEO-Bericht). Erst sichtbar, wenn das Formular geladen ist. -->
      <div v-if="!loading" class="rc-nav">
        <button
          v-for="s in sections"
          :key="s.key"
          type="button"
          class="rc-chip"
          @click="scrollToSection(s.key)"
        >
          {{ $t(s.label) }}
        </button>
      </div>
      <v-card-text>
        <v-skeleton-loader v-if="loading" type="article" />
        <v-form v-else @submit.prevent="submit">
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('setup.sessionPathHint') }}</div>
          <v-text-field
            v-model="sessionPath"
            :label="$t('setup.sessionPath')"
            prepend-inner-icon="mdi-folder-account"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('setup.logFileHint') }}</div>
          <v-text-field
            v-model="logFile"
            :label="$t('setup.logFile')"
            prepend-inner-icon="mdi-file-document-outline"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('setup.logLevelHint') }}</div>
          <v-select
            v-model="logLevel"
            :items="logLevels"
            :label="$t('setup.logLevel')"
            prepend-inner-icon="mdi-tune"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('setup.hugoBinHint') }}</div>
          <v-text-field
            v-model="hugoBin"
            :label="$t('setup.hugoBin')"
            prepend-inner-icon="mdi-language-go"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis">{{ $t('reconfigure.hugoCleanHint') }}</div>
          <v-checkbox
            v-model="hugoClean"
            :label="$t('reconfigure.hugoClean')"
            density="compact"
            hide-details
            class="mb-4"
          />

          <v-divider class="my-3" />
          <div :ref="registerSection('ai')" class="text-subtitle-2 mb-2">{{ $t('aiConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">
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
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('aiConfig.modelHint') }}</div>
          <v-select
            v-model="aiModel"
            :items="aiModels"
            :label="$t('aiConfig.model')"
            prepend-inner-icon="mdi-brain"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('aiConfig.modelCronHint') }}</div>
          <v-select
            v-model="aiModelCron"
            :items="aiSubModels"
            :label="$t('aiConfig.modelCron')"
            prepend-inner-icon="mdi-robot-outline"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('aiConfig.modelAuditHint') }}</div>
          <v-select
            v-model="aiModelAudit"
            :items="aiSubModels"
            :label="$t('aiConfig.modelAudit')"
            prepend-inner-icon="mdi-text-search"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('aiConfig.writeModeHint') }}</div>
          <v-select
            v-model="aiWriteMode"
            :items="writeModeItems"
            :label="$t('aiConfig.writeMode')"
            prepend-inner-icon="mdi-shield-edit-outline"
            variant="outlined"
            density="comfortable"
          />

          <v-divider class="my-3" />
          <div :ref="registerSection('speech')" class="text-subtitle-2 mb-2">{{ $t('speechConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('speechConfig.urlHint') }}</div>
          <v-text-field
            v-model="speechUrl"
            :label="$t('speechConfig.url')"
            prepend-inner-icon="mdi-web"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">
            {{ speechConfigured ? $t('speechConfig.keyHintSet') : $t('speechConfig.keyHintUnset') }}
          </div>
          <v-text-field
            v-model="speechKey"
            :label="$t('speechConfig.key')"
            type="password"
            prepend-inner-icon="mdi-key-variant"
            autocomplete="off"
            variant="outlined"
            density="comfortable"
          />

          <v-divider class="my-3" />
          <div :ref="registerSection('mail')" class="text-subtitle-2 mb-2">{{ $t('mailConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('mailConfig.intro') }}</div>
          <v-text-field
            v-model="mailHost"
            :label="$t('mailConfig.host')"
            prepend-inner-icon="mdi-server-network"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="d-flex ga-2">
            <v-text-field
              v-model="mailPort"
              :label="$t('mailConfig.port')"
              :placeholder="$t('mailConfig.portHint')"
              type="number"
              prepend-inner-icon="mdi-numeric"
              variant="outlined"
              density="comfortable"
              class="mb-2"
              style="max-width: 140px"
            />
            <v-select
              v-model="mailSecurity"
              :items="mailSecurityItems"
              :label="$t('mailConfig.securityLabel')"
              prepend-inner-icon="mdi-lock-outline"
              variant="outlined"
              density="comfortable"
              class="mb-2"
            />
          </div>
          <v-text-field
            v-model="mailUser"
            :label="$t('mailConfig.user')"
            :hint="$t('mailConfig.userHint')"
            prepend-inner-icon="mdi-account-outline"
            autocomplete="off"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis mb-2">
            {{ mailPassConfigured ? $t('mailConfig.passHintSet') : $t('mailConfig.passHintUnset') }}
          </div>
          <v-text-field
            v-model="mailPass"
            :label="$t('mailConfig.pass')"
            type="password"
            prepend-inner-icon="mdi-key-variant"
            autocomplete="off"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="mailFrom"
            :label="$t('mailConfig.from')"
            :placeholder="$t('mailConfig.fromHint')"
            prepend-inner-icon="mdi-email-arrow-right-outline"
            variant="outlined"
            density="comfortable"
            class="mb-2"
          />
          <v-text-field
            v-model="mailTo"
            :label="$t('mailConfig.to')"
            :hint="$t('mailConfig.toHint')"
            prepend-inner-icon="mdi-email-outline"
            variant="outlined"
            density="comfortable"
          />

          <v-divider class="my-3" />
          <div :ref="registerSection('seo')" class="text-subtitle-2 mb-2">{{ $t('seoConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('seoConfig.excludePrefixesHint') }}</div>
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
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('seoConfig.excludeFilesHint') }}</div>
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

          <v-divider class="my-3" />
          <div :ref="registerSection('pagespeed')" class="text-subtitle-2 mb-2">{{ $t('pagespeedConfig.section') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('pagespeedConfig.intro') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ pagespeedConfigured ? $t('pagespeedConfig.keyHintSet') : $t('pagespeedConfig.keyHintUnset') }}
          </div>
          <v-text-field
            v-model="pagespeedKey"
            :label="$t('pagespeedConfig.key')"
            type="password"
            prepend-inner-icon="mdi-key-variant"
            autocomplete="off"
            variant="outlined"
            density="comfortable"
          />

          <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
          <div class="text-caption text-medium-emphasis mt-3">{{ $t('reconfigure.note') }}</div>
        </v-form>
      </v-card-text>
      <v-card-actions v-if="!loading">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="model = false">{{ $t('reconfigure.cancel') }}</v-btn>
        <v-btn color="primary" variant="flat" :loading="saving" :disabled="loading" @click="submit">
          {{ $t('reconfigure.submit') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/* Sprungnavigation zu den Abschnitten — Stil analog zu den Kategorie-Chips
   des SEO-Berichts (AuditView). */
.rc-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0 16px 8px;
}
.rc-chip {
  border: 1px solid var(--mint-border);
  border-radius: 999px;
  background: #fff;
  padding: 2px 12px;
  font-size: 0.8rem;
  color: var(--mint-text);
  cursor: pointer;
}
.rc-chip:hover { background: var(--mint-panel-hover); }
</style>
