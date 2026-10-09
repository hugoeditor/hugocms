<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import { endpointUrl } from '../api/client'
import { useConfirm } from '../util/confirm'
import DirectoryPickerDialog from './DirectoryPickerDialog.vue'

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

// Shop-Erweiterung (OpensourceERP): Schalter, Freigaben und Schlüssel dieser
// Webseite, nur für Administratoren. Der Schlüssel selbst kommt nur einmal,
// direkt nach dem Erzeugen — dann steht er in newShopKey, bis der Dialog
// schließt. Schalter und Schlüssel wirken sofort, die Freigaben mit „Speichern“.
const SHOP_EMPTY = { enabled: false, set: false, hint: null, created: null, grants: {} }
const shop = ref({ ...SHOP_EMPTY })
const newShopKey = ref('')
const shopBusy = ref(false)
const shopError = ref(null)
const shopCopied = ref(false)
const shopEndpoint = endpointUrl()

// Freigaben: wohin OpensourceERP schreiben darf, relativ zum Hugo-Projekt.
// file = eine Datei statt eines Verzeichnisses (die Auswahl liefert dann nur
// das Verzeichnis, der Dateiname bleibt).
const SHOP_GRANTS = [
  { field: 'contentDir', icon: 'mdi-file-document-multiple-outline', file: false },
  { field: 'categoryGroups', icon: 'mdi-file-tree-outline', file: true },
  { field: 'images', icon: 'mdi-image-multiple-outline', file: false },
  { field: 'thumbnails', icon: 'mdi-image-size-select-small', file: false },
]
const shopGrants = ref({})

function resetShopGrants() {
  shopGrants.value = Object.fromEntries(SHOP_GRANTS.map(({ field }) => [field, shop.value.grants?.[field] ?? '']))
}

const shopGrantsDirty = computed(() =>
  SHOP_GRANTS.some(({ field }) => String(shopGrants.value[field] ?? '').trim() !== (shop.value.grants?.[field] ?? '')),
)

// Dieselben Regeln wie auf dem Server (MountConfig::shopGrantPath), damit ein
// Fehler schon beim Tippen auffällt.
const shopGrantRule = (file) => (v) => {
  const value = String(v ?? '').trim().replace(/\/+$/, '')
  if (!value) return t('projectConfig.shopGrantRequired')
  const segments = value.split('/').filter(Boolean)
  if (value.startsWith('/') || segments.some((part) => part.startsWith('.') || !/^[\p{L}\p{N}_\-. ]+$/u.test(part))) {
    return t('projectConfig.shopGrantInvalid')
  }
  if (file && !value.toLowerCase().endsWith('.json')) return t('projectConfig.shopGrantJson')
  return true
}

// Verzeichnisauswahl, auf das Hugo-Projekt begrenzt (shopbrowsedirs). Den
// Serverpfad des Projekts kennt die Oberfläche nur als Administrator.
const pickerOpen = ref(false)
const pickerField = ref(null)
const pickerStart = computed(() => {
  const grant = SHOP_GRANTS.find(({ field }) => field === pickerField.value)
  const value = String(shopGrants.value[pickerField.value] ?? '').replace(/^\/+|\/+$/g, '')
  const dir = grant?.file ? value.split('/').slice(0, -1).join('/') : value
  return shop.value.source ? (dir ? `${shop.value.source}/${dir}` : shop.value.source) : ''
})

function pickGrant(field) {
  pickerField.value = field
  pickerOpen.value = true
}

function onGrantPicked(path) {
  const source = shop.value.source ?? ''
  const grant = SHOP_GRANTS.find(({ field }) => field === pickerField.value)
  if (!grant || !source) return
  const dir = path === source ? '' : path.startsWith(`${source}/`) ? path.slice(source.length + 1) : null
  if (dir === null) return
  if (grant.file) {
    const name = String(shopGrants.value[grant.field] || 'category_groups.json').split('/').pop()
    shopGrants.value[grant.field] = dir ? `${dir}/${name}` : name
    return
  }
  if (!dir) {
    shopError.value = t('projectConfig.shopGrantRoot')
    return
  }
  shopError.value = null
  shopGrants.value[grant.field] = dir
}

// Ein- und Ausschalten wirkt sofort. Aus heißt: Die Anbindung weist
// OpensourceERP ab; Schlüssel und Freigaben bleiben gespeichert.
async function toggleShop(enabled) {
  if (!enabled) {
    const ok = await confirm({
      title: t('projectConfig.shopDisableTitle'),
      message: t('projectConfig.shopDisableConfirm'),
      confirmText: t('projectConfig.shopDisable'),
      color: 'warning',
    })
    if (!ok) return
  }
  shopBusy.value = true
  shopError.value = null
  try {
    shop.value = await auth.shopSettingsSet({ enabled })
    resetShopGrants()
    // Der Systemstatus und andere Ansichten lesen den Schalter aus whoami
    await auth.check()
  } catch (e) {
    shopError.value = errorText(t, e)
  } finally {
    shopBusy.value = false
  }
}

// Wie in der Freigabe-Warteschlange: toLocaleString in der Oberflächensprache
const shopKeyCreatedText = computed(() => {
  const created = shop.value.created
  if (!created) return ''
  const date = new Date(created)
  return Number.isNaN(date.getTime()) ? created : date.toLocaleString(locale.value)
})

async function createShopKey() {
  // Ein vorhandener Schlüssel gilt nach dem Ersetzen sofort nicht mehr —
  // OpensourceERP braucht dann den neuen.
  if (shop.value.set) {
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
    // Schalter, Freigaben und Signaturschlüssel bleiben, wie sie sind
    shop.value = { ...shop.value, set: true, hint: res.hint, created: res.created }
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
    shop.value = { ...shop.value, set: false, hint: null, created: null }
  } catch (e) {
    shopError.value = errorText(t, e)
  } finally {
    shopBusy.value = false
  }
}

// Signaturschlüssel von OpensourceERP: Erst damit nimmt die Anbindung die
// PHP-Einstiegspunkte des Pakets an (Weiterleiter, 404-Seite), und nur
// signiert. Der Schlüssel ist öffentlich — er darf angezeigt werden.
const newSigningKey = ref('')

async function setSigningKey() {
  shopBusy.value = true
  shopError.value = null
  try {
    const res = await auth.shopSigningKeySet(newSigningKey.value.trim())
    shop.value = { ...shop.value, signingKey: res.signingKey, signingAvailable: res.signingAvailable }
    newSigningKey.value = ''
  } catch (e) {
    shopError.value = errorText(t, e)
  } finally {
    shopBusy.value = false
  }
}

async function deleteSigningKey() {
  const ok = await confirm({
    title: t('projectConfig.shopSigningDeleteTitle'),
    message: t('projectConfig.shopSigningDeleteConfirm'),
    confirmText: t('projectConfig.shopSigningDelete'),
    color: 'error',
  })
  if (!ok) return
  shopBusy.value = true
  shopError.value = null
  try {
    await auth.shopSigningKeyDelete()
    shop.value = { ...shop.value, signingKey: null }
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
    shop.value = cfg.shop ?? { ...SHOP_EMPTY }
    resetShopGrants()
    newShopKey.value = ''
    newSigningKey.value = ''
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
    // Freigaben der Shop-Anbindung zuerst: Sie prüft der Server streng, und
    // ein Fehler darin bricht ab, bevor der Rest gespeichert ist.
    if (auth.manageConfig && shop.value.enabled && shopGrantsDirty.value) {
      shop.value = await auth.shopSettingsSet(
        Object.fromEntries(SHOP_GRANTS.map(({ field }) => [field, String(shopGrants.value[field] ?? '').trim()])),
      )
      resetShopGrants()
    }
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

          <!-- Shop-Erweiterung: Anbindung an OpensourceERP, nur für
               Administratoren. Schalter und Schlüssel wirken sofort, die
               Freigaben mit „Speichern“. -->
          <template v-if="auth.manageConfig">
            <v-divider class="my-4" />
            <div class="text-subtitle-2 mb-1">{{ $t('projectConfig.shopSection') }}</div>
            <div class="text-caption text-medium-emphasis mb-2">{{ $t('projectConfig.shopHint') }}</div>
            <v-switch
              :model-value="shop.enabled"
              :label="$t('projectConfig.shopEnabled')"
              :loading="shopBusy"
              :disabled="shopBusy || loading || saving"
              color="primary"
              density="compact"
              hide-details
              class="mb-2"
              @update:model-value="toggleShop"
            />
            <div v-if="!shop.enabled" class="text-caption text-medium-emphasis mb-2">
              {{ $t('projectConfig.shopDisabledHint') }}
            </div>

            <template v-if="shop.enabled">
              <v-text-field
                :model-value="shopEndpoint"
                :label="$t('projectConfig.shopEndpoint')"
                :hint="$t('projectConfig.shopEndpointHint')"
                prepend-inner-icon="mdi-link-variant"
                variant="outlined"
                density="comfortable"
                readonly
                persistent-hint
                class="mb-3 mt-2"
              />

              <div class="text-body-2 mb-2">
                <template v-if="shop.set">
                  {{ $t('projectConfig.shopKeySet', [shop.hint ?? '', shopKeyCreatedText]) }}
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
                  {{ shop.set ? $t('projectConfig.shopKeyReplace') : $t('projectConfig.shopKeyCreate') }}
                </v-btn>
                <v-btn
                  v-if="shop.set"
                  variant="text"
                  color="error"
                  prepend-icon="mdi-key-remove"
                  :disabled="shopBusy || loading || saving"
                  @click="deleteShopKey"
                >
                  {{ $t('projectConfig.shopKeyDelete') }}
                </v-btn>
              </div>

              <!-- Freigaben: wohin OpensourceERP schreiben darf. OpensourceERP
                   zeigt sie nur an und übernimmt sie bei jedem Lauf. -->
              <div class="text-subtitle-2 mt-5 mb-1">{{ $t('projectConfig.shopGrantsSection') }}</div>
              <div class="text-caption text-medium-emphasis mb-3">{{ $t('projectConfig.shopGrantsHint') }}</div>
              <v-text-field
                v-for="grant in SHOP_GRANTS"
                :key="grant.field"
                v-model="shopGrants[grant.field]"
                :label="$t(`projectConfig.shopGrant.${grant.field}`)"
                :hint="$t(`projectConfig.shopGrantHint.${grant.field}`)"
                :rules="[shopGrantRule(grant.file)]"
                :prepend-inner-icon="grant.icon"
                :disabled="loading || saving"
                variant="outlined"
                density="comfortable"
                persistent-hint
                class="mb-3"
              >
                <template #append-inner>
                  <v-btn
                    icon="mdi-folder-search-outline"
                    variant="text"
                    size="small"
                    :disabled="!shop.source || loading || saving"
                    :aria-label="$t('projectConfig.shopGrantPick')"
                    :title="$t('projectConfig.shopGrantPick')"
                    @click="pickGrant(grant.field)"
                  />
                </template>
              </v-text-field>
              <v-text-field
                :model-value="`${shop.grants?.package ?? 'oserp-shop'}/`"
                :label="$t('projectConfig.shopGrant.package')"
                :hint="$t('projectConfig.shopGrantHint.package')"
                prepend-inner-icon="mdi-package-variant-closed"
                variant="outlined"
                density="comfortable"
                readonly
                persistent-hint
                class="mb-2"
              />
              <div v-if="shopGrantsDirty" class="text-caption text-warning mb-2">
                {{ $t('projectConfig.shopGrantsUnsaved') }}
              </div>

              <!-- Signaturschlüssel von OpensourceERP: Weiterleiter und 404-Seite
                   (PHP) nimmt die Anbindung nur signiert an. Wirkt sofort. -->
              <div class="text-subtitle-2 mt-5 mb-1">{{ $t('projectConfig.shopSigningSection') }}</div>
              <div class="text-caption text-medium-emphasis mb-2">
                {{ $t('projectConfig.shopSigningHint') }}
                <code v-for="pfad in shop.signedPhp ?? []" :key="pfad" class="ml-1">{{ pfad }}</code>
              </div>
              <v-alert
                v-if="shop.signingAvailable === false"
                type="warning"
                variant="tonal"
                density="compact"
                class="mb-2"
              >
                {{ $t('projectConfig.shopSigningUnavailable') }}
              </v-alert>
              <div class="text-body-2 mb-2">
                <template v-if="shop.signingKey">
                  {{ $t('projectConfig.shopSigningSet', [shop.signingKey.slice(-6)]) }}
                </template>
                <template v-else>{{ $t('projectConfig.shopSigningNone') }}</template>
              </div>
              <v-text-field
                v-model="newSigningKey"
                :label="$t('projectConfig.shopSigningKey')"
                :hint="$t('projectConfig.shopSigningKeyHint')"
                prepend-inner-icon="mdi-shield-key-outline"
                variant="outlined"
                density="comfortable"
                persistent-hint
                class="mb-3"
              />
              <div class="d-flex flex-wrap" style="gap: 8px">
                <v-btn
                  variant="tonal"
                  color="primary"
                  prepend-icon="mdi-shield-key"
                  :loading="shopBusy"
                  :disabled="!newSigningKey.trim() || loading || saving"
                  @click="setSigningKey"
                >
                  {{ $t('projectConfig.shopSigningSave') }}
                </v-btn>
                <v-btn
                  v-if="shop.signingKey"
                  variant="text"
                  color="error"
                  prepend-icon="mdi-shield-remove"
                  :disabled="shopBusy || loading || saving"
                  @click="deleteSigningKey"
                >
                  {{ $t('projectConfig.shopSigningDelete') }}
                </v-btn>
              </div>
            </template>
            <v-alert v-if="shopError" type="error" density="compact" class="mt-2">{{ shopError }}</v-alert>
          </template>

          <v-alert v-if="error" type="error" density="compact" class="mt-2">{{ error }}</v-alert>
          <div class="text-caption text-medium-emphasis mt-3">{{ $t('projectConfig.note') }}</div>
        </v-form>
        <DirectoryPickerDialog
          v-model="pickerOpen"
          command="shopbrowsedirs"
          :start="pickerStart"
          @select="onGrantPicked"
        />
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
