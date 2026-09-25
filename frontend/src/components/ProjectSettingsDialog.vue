<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import { endpointUrl } from '../api/client'
import { useConfirm } from '../util/confirm'

const { t, locale } = useI18n()
const auth = useAuthStore()
const confirm = useConfirm()

// Sichtbarkeit als v-model (Vue 3.4+). Der Knopf in der Werkzeugschiene öffnet.
const model = defineModel({ type: Boolean, default: false })
// Sektion, zu der beim Öffnen gescrollt werden soll (z. B. 'cron' aus dem
// Systemstatus). Leer = normal von oben.
const props = defineProps({ focusSection: { type: String, default: '' } })
const emit = defineEmits(['saved'])

// Verweise auf die scrollbaren Abschnitte, damit sich einer gezielt anspringen
// lässt. Aktuell nur die Cron-Sektion.
const cronSectionRef = ref(null)

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
const improveSkipWeekends = ref(true)

// Pausenschalter der drei Cron-Skripte. Dieselben Einstellungen lassen sich im
// Systemstatus direkt umlegen; hier stehen sie der Vollständigkeit halber.
const pauseBuild = ref(false)
const pauseImprove = ref(false)
const pauseHealthcheck = ref(false)

// Automatischer Commit nach der zeitgesteuerten Veröffentlichung (cron-build).
// Nur wirksam, wenn das Quellverzeichnis ein Git-Repository ist (gitRepo) und
// die Pro-Lizenz gilt (auth.git). Das Datum hängt der Server an die Nachricht.
const autoCommit = ref(false)
const commitMessage = ref('')
// Zweite Nachricht: Vorab-Commit offener Änderungen vor dem Build (gleicher
// Schalter autoCommit).
const commitMessagePending = ref('')
// Änderungsprotokoll (content/changelog.md). Unabhängig vom Auto-Commit: Es
// wird bei JEDEM Versionsstand fortgeschrieben, nicht nur bei denen des Cron.
// Vorgabe an — der Schalter dient zum Abschalten.
const changelog = ref(true)
// Zielpfade der Protokollseite im Inhaltsverzeichnis, kommagetrennt. Mehrere
// für mehrsprachige Projekte: Dort liegt der Inhalt je Sprache in einem eigenen
// Verzeichnis, und eine Seite im Wurzelverzeichnis gehörte zu keiner davon.
const changelogPath = ref('')
// Wort vor der Versionsnummer in der Überschrift des Protokolls („Ausgabe 12“).
// Beim Sichern von Hand schickt der Client es aus der Oberflächensprache mit;
// für die Cron-Läufe steht hier der Wert, weil dort keine Sprache bekannt ist.
const tagLabel = ref('')
const gitRepo = ref(false)
// Auto-Commit ist nur einstellbar, wenn Git nutzbar (Pro + Projekt) UND das
// Quellverzeichnis ein Repository ist.
const gitCommitAvailable = computed(() => auth.git && gitRepo.value)

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

// Shop-Anbindung (OpensourceERP): Stand des Schlüssels dieser Webseite. Der
// Schlüssel selbst kommt nur einmal, direkt nach dem Erzeugen — dann steht er in
// newShopKey, bis der Dialog schließt.
const shopKey = ref({ set: false, hint: null, created: null })
const newShopKey = ref('')
const shopBusy = ref(false)
const shopError = ref(null)
const shopCopied = ref(false)
const shopEndpoint = endpointUrl()

// Wie in der Freigabe-Warteschlange: toLocaleString in der Oberflächensprache
const shopKeyCreatedText = computed(() => {
  const created = shopKey.value.created
  if (!created) return ''
  const date = new Date(created)
  return Number.isNaN(date.getTime()) ? created : date.toLocaleString(locale.value)
})

async function createShopKey() {
  // Ein vorhandener Schlüssel gilt nach dem Ersetzen sofort nicht mehr —
  // OpensourceERP braucht dann den neuen.
  if (shopKey.value.set) {
    const ok = await confirm({
      title: t('projectConfig.shopKeyReplaceTitle'),
      message: t('projectConfig.shopKeyReplaceConfirm'),
      confirmText: t('projectConfig.shopKeyReplace'),
      color: 'warning',
    })
    if (!ok) return
  }
  shopBusy.value = true
  shopError.value = null
  shopCopied.value = false
  try {
    const res = await auth.shopKeyCreate()
    newShopKey.value = res.key
    shopKey.value = { set: true, hint: res.hint, created: res.created }
  } catch (e) {
    shopError.value = errorText(t, e)
  } finally {
    shopBusy.value = false
  }
}

async function deleteShopKey() {
  const ok = await confirm({
    title: t('projectConfig.shopKeyDeleteTitle'),
    message: t('projectConfig.shopKeyDeleteConfirm'),
    confirmText: t('projectConfig.shopKeyDelete'),
    color: 'error',
  })
  if (!ok) return
  shopBusy.value = true
  shopError.value = null
  try {
    await auth.shopKeyDelete()
    newShopKey.value = ''
    shopKey.value = { set: false, hint: null, created: null }
  } catch (e) {
    shopError.value = errorText(t, e)
  } finally {
    shopBusy.value = false
  }
}

async function copyShopKey() {
  try {
    await navigator.clipboard.writeText(newShopKey.value)
    shopCopied.value = true
  } catch {
    // Ohne Zugriff auf die Zwischenablage (unsichere Verbindung) bleibt das
    // Feld zum Markieren und Kopieren von Hand.
    shopCopied.value = false
  }
}

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
    improveSkipWeekends.value = !!cfg.improveSkipWeekends
    pauseBuild.value = !!cfg.pauseBuild
    pauseImprove.value = !!cfg.pauseImprove
    pauseHealthcheck.value = !!cfg.pauseHealthcheck
    autoCommit.value = !!cfg.autoCommit
    commitMessage.value = cfg.commitMessage ?? ''
    commitMessagePending.value = cfg.commitMessagePending ?? ''
    changelog.value = !!cfg.changelog
    changelogPath.value = cfg.changelogPath ?? ''
    tagLabel.value = cfg.tagLabel ?? ''
    gitRepo.value = !!cfg.gitRepo
    shopKey.value = cfg.shopKey ?? { set: false, hint: null, created: null }
    newShopKey.value = ''
    shopError.value = null
    shopCopied.value = false
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
  // Wurde der Dialog gezielt für eine Sektion geöffnet (aus dem Systemstatus),
  // nach dem Rendern dorthin scrollen.
  if (props.focusSection === 'cron') {
    await nextTick()
    // ref auf einer Vuetify-Komponente liefert die Instanz — das DOM-Element
    // steckt in $el; auf einem einfachen Element ist es der Wert selbst.
    const el = cronSectionRef.value?.$el ?? cronSectionRef.value
    el?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
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
      improveSkipWeekends: improveSkipWeekends.value,
      pauseBuild: pauseBuild.value,
      pauseImprove: pauseImprove.value,
      pauseHealthcheck: pauseHealthcheck.value,
      autoCommit: autoCommit.value,
      commitMessage: commitMessage.value,
      commitMessagePending: commitMessagePending.value,
      changelog: changelog.value,
      changelogPath: changelogPath.value,
      tagLabel: tagLabel.value,
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
          <v-switch
            v-model="improveSkipWeekends"
            :label="$t('projectConfig.improveSkipWeekends')"
            :disabled="!improveAuto"
            color="primary"
            density="compact"
            hide-details
            class="mt-2"
          />
          <div class="text-caption text-medium-emphasis">
            {{ $t('projectConfig.improveSkipWeekendsHint') }}
          </div>

          <!-- Cron-Aufgaben pausieren. Der Systemstatus verweist zum Umstellen
               hierher; ein pausiertes Skript prüft das beim Start und tut
               nichts, ohne dass die Crontab des Hosters geändert werden muss. -->
          <v-divider ref="cronSectionRef" class="my-4" />
          <div class="text-subtitle-2 mb-1">{{ $t('projectConfig.cronSection') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ $t('projectConfig.cronHint') }}
          </div>
          <v-switch
            v-model="pauseBuild"
            :label="$t('projectConfig.pauseBuild')"
            color="warning"
            density="compact"
            hide-details
          />
          <v-switch
            v-model="pauseImprove"
            :label="$t('projectConfig.pauseImprove')"
            color="warning"
            density="compact"
            hide-details
          />
          <v-switch
            v-model="pauseHealthcheck"
            :label="$t('projectConfig.pauseHealthcheck')"
            color="warning"
            density="compact"
            hide-details
          />

          <!-- Automatischer Commit nach der zeitgesteuerten Veröffentlichung.
               Nur einstellbar, wenn Git nutzbar (Pro) und das Quellverzeichnis
               ein Repository ist. -->
          <v-divider class="my-4" />
          <div class="text-subtitle-2 mb-1">{{ $t('projectConfig.gitSection') }}</div>
          <div class="text-caption text-medium-emphasis mb-2">{{ $t('projectConfig.gitHint') }}</div>
          <v-alert
            v-if="!auth.git"
            type="info"
            density="compact"
            variant="tonal"
            class="mb-2"
          >
            {{ $t('projectConfig.gitNeedsPro') }}
          </v-alert>
          <v-alert
            v-else-if="!gitRepo"
            type="info"
            density="compact"
            variant="tonal"
            class="mb-2"
          >
            {{ $t('projectConfig.gitNoRepo') }}
          </v-alert>
          <v-switch
            v-model="autoCommit"
            :label="$t('projectConfig.autoCommit')"
            :disabled="!gitCommitAvailable"
            color="primary"
            density="compact"
            hide-details
            class="mb-2"
          />
          <v-text-field
            v-model="commitMessage"
            :label="$t('projectConfig.commitMessage')"
            :disabled="!gitCommitAvailable || !autoCommit"
            prepend-inner-icon="mdi-message-text-outline"
            variant="outlined"
            density="comfortable"
            counter="200"
            maxlength="200"
            :hint="$t('projectConfig.commitMessageHint')"
            persistent-hint
            class="mb-2"
          />
          <v-text-field
            v-model="commitMessagePending"
            :label="$t('projectConfig.commitMessagePending')"
            :disabled="!gitCommitAvailable || !autoCommit"
            prepend-inner-icon="mdi-message-arrow-right-outline"
            variant="outlined"
            density="comfortable"
            counter="200"
            maxlength="200"
            :hint="$t('projectConfig.commitMessagePendingHint')"
            persistent-hint
            class="mb-2"
          />
          <!-- Bewusst NICHT an autoCommit gekoppelt: Das Protokoll entsteht bei
               jedem Versionsstand, auch bei denen von Hand. -->
          <v-switch
            v-model="changelog"
            :label="$t('projectConfig.changelog')"
            :disabled="!gitCommitAvailable"
            color="primary"
            density="compact"
            hide-details
          />
          <div class="text-caption text-medium-emphasis mb-2">
            {{ $t('projectConfig.changelogHint') }}
          </div>

          <v-text-field
            v-model="changelogPath"
            :label="$t('projectConfig.changelogPath')"
            :hint="$t('projectConfig.changelogPathHint')"
            :disabled="!gitCommitAvailable || !changelog"
            variant="outlined"
            density="comfortable"
            persistent-hint
            class="mb-2 mt-5"
          />

          <v-text-field
            v-model="tagLabel"
            :label="$t('projectConfig.tagLabel')"
            :hint="$t('projectConfig.tagLabelHint')"
            :disabled="!gitCommitAvailable || !changelog"
            variant="outlined"
            density="comfortable"
            persistent-hint
            class="mb-2 mt-5"
          />

          <!-- Shop-Anbindung: Schlüssel, mit dem OpensourceERP den Bau dieser
               Webseite anstößt. Wirkt sofort, nicht erst mit „Speichern“ —
               wie bei jedem Zugangsschlüssel. -->
          <template v-if="auth.manageConfig">
            <v-divider class="my-4" />
            <div class="text-subtitle-2 mb-1">{{ $t('projectConfig.shopSection') }}</div>
            <div class="text-caption text-medium-emphasis mb-2">{{ $t('projectConfig.shopHint') }}</div>

            <v-text-field
              :model-value="shopEndpoint"
              :label="$t('projectConfig.shopEndpoint')"
              :hint="$t('projectConfig.shopEndpointHint')"
              prepend-inner-icon="mdi-link-variant"
              variant="outlined"
              density="comfortable"
              readonly
              persistent-hint
              class="mb-3"
            />

            <div class="text-caption text-medium-emphasis mb-3">
              {{ $t('projectConfig.shopAreas') }}
              <code v-for="bereich in shopKey.areas ?? []" :key="bereich" class="ml-1">{{ bereich }}</code>
            </div>

            <div class="text-body-2 mb-2">
              <template v-if="shopKey.set">
                {{ $t('projectConfig.shopKeySet', [shopKey.hint ?? '', shopKeyCreatedText]) }}
              </template>
              <template v-else>{{ $t('projectConfig.shopKeyNone') }}</template>
            </div>

            <v-alert
              v-if="newShopKey"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-2"
            >
              <div class="mb-2">{{ $t('projectConfig.shopKeyShownOnce') }}</div>
              <v-text-field
                :model-value="newShopKey"
                variant="outlined"
                density="compact"
                readonly
                hide-details
                class="shop-key"
                @focus="$event.target.select()"
              >
                <template #append-inner>
                  <v-btn
                    :icon="shopCopied ? 'mdi-check' : 'mdi-content-copy'"
                    variant="text"
                    size="small"
                    :aria-label="$t('projectConfig.shopKeyCopy')"
                    @click="copyShopKey"
                  />
                </template>
              </v-text-field>
            </v-alert>

            <div class="d-flex flex-wrap" style="gap: 8px">
              <v-btn
                variant="tonal"
                color="primary"
                prepend-icon="mdi-key-plus"
                :loading="shopBusy"
                :disabled="loading || saving"
                @click="createShopKey"
              >
                {{ shopKey.set ? $t('projectConfig.shopKeyReplace') : $t('projectConfig.shopKeyCreate') }}
              </v-btn>
              <v-btn
                v-if="shopKey.set"
                variant="text"
                color="error"
                prepend-icon="mdi-key-remove"
                :disabled="shopBusy || loading || saving"
                @click="deleteShopKey"
              >
                {{ $t('projectConfig.shopKeyDelete') }}
              </v-btn>
            </div>
            <v-alert v-if="shopError" type="error" density="compact" class="mt-2">{{ shopError }}</v-alert>
          </template>

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

<style scoped>
.shop-key :deep(input) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.8rem;
}
</style>
