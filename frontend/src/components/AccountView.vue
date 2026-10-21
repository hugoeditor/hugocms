<script setup>
// Konto und Einstellungen: Anmeldedaten des angemeldeten Benutzers und die
// Einstellungen der [user]-Sektion. Als Overlay-Ansicht wie die
// Benutzerverwaltung (OverlayView), nicht als v-dialog.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useFilesStore } from '../stores/files'
import { errorText } from '../i18n/apiMessage'
import OverlayView from './OverlayView.vue'

const { t } = useI18n()
const auth = useAuthStore()
const files = useFilesStore()

const MIN_PASSWORD_LENGTH = 8
// Grenzen der Sitzungsdauer in Stunden — dieselben prüft der Server nach.
const MIN_SESSION_HOURS = 0.25
const MAX_SESSION_HOURS = 720

const model = defineModel({ type: Boolean, default: false })
// changed = die Anmeldedaten wurden geändert, die Sitzung ist beendet.
// saved   = nur Einstellungen wurden geschrieben, die Anmeldung bleibt.
const emit = defineEmits(['changed', 'saved'])

const username = ref('')
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
// Passwörter im Klartext zeigen (Augen-Symbol). Ein Schalter für alle drei
// Felder: Wer das neue Passwort prüfen will, will meist auch die Bestätigung
// sehen. Beim Öffnen der Ansicht wieder verborgen.
const revealPasswords = ref(false)

// Einstellungen der [user]-Sektion. sessionLifetime steht in STUNDEN (wie in
// der INI); update_lastmod ist dreiwertig. Die Auswahl arbeitet mit Namen statt
// mit null/true/false, weil eine Auswahlliste den Wert null als „nichts
// ausgewählt" deutet und dann leer bliebe.
const sessionLifetime = ref('8')
const lastmodChoice = ref('ask')
// Versteckte Dateien im Dateimanager — schaltbar nur mit Freigabe.
const showHidden = ref(false)

const LASTMOD_TO_VALUE = { ask: null, always: true, never: false }

function lastmodToChoice(value) {
  if (value === true) return 'always'
  if (value === false) return 'never'
  return 'ask'
}

const updateLastmodOptions = computed(() => [
  { title: t('account.updateLastmodAsk'), value: 'ask' },
  { title: t('account.updateLastmodAlways'), value: 'always' },
  { title: t('account.updateLastmodNever'), value: 'never' },
])

const saving = ref(false)
const error = ref(null)

// Anmeldedaten gelten als geändert, sobald der Name abweicht oder ein neues
// Passwort gesetzt wurde. Nur dann beendet das Speichern die Sitzung.
const credentialsChanged = computed(
  () => username.value !== (auth.user?.name ?? '') || newPassword.value !== '',
)

// Beim Öffnen den aktuellen Stand vorbefüllen, Passwortfelder leeren.
watch(model, (open) => {
  if (!open) return
  username.value = auth.user?.name ?? ''
  currentPassword.value = ''
  newPassword.value = ''
  newPasswordConfirm.value = ''
  sessionLifetime.value = String(auth.ui?.sessionLifetimeHours ?? 8)
  lastmodChoice.value = lastmodToChoice(auth.ui?.updateLastmod ?? null)
  showHidden.value = !!auth.ui?.showHidden
  revealPasswords.value = false
  error.value = null
})

// Nur die tatsächlich geänderten Einstellungen schicken — so bleibt in der INI
// unberührt, was der Benutzer nicht angefasst hat.
function changedPrefs() {
  const patch = {}
  const hours = Number(sessionLifetime.value)
  if (hours !== (auth.ui?.sessionLifetimeHours ?? 8)) patch.sessionLifetime = hours
  const lastmod = LASTMOD_TO_VALUE[lastmodChoice.value]
  if (lastmod !== (auth.ui?.updateLastmod ?? null)) patch.updateLastmod = lastmod
  if (showHidden.value !== !!auth.ui?.showHidden) patch.showHidden = showHidden.value
  return patch
}

async function submit() {
  error.value = null
  if (newPassword.value && newPassword.value !== newPasswordConfirm.value) {
    error.value = t('account.passwordMismatch')
    return
  }
  const hours = Number(sessionLifetime.value)
  if (!Number.isFinite(hours) || hours < MIN_SESSION_HOURS || hours > MAX_SESSION_HOURS) {
    error.value = t('account.sessionLifetimeInvalid')
    return
  }

  saving.value = true
  try {
    // Die Einstellungen zuerst: Eine Änderung der Anmeldedaten beendet die
    // Sitzung, danach ließe sich nichts mehr schreiben.
    const prefs = changedPrefs()
    const prefsChanged = Object.keys(prefs).length > 0
    if (prefsChanged) await auth.saveUserPrefs(prefs)
    // Die Dateiliste filtert der Server — nach dem Umschalten neu laden.
    if ('showHidden' in prefs) await files.refresh()

    if (credentialsChanged.value) {
      await auth.changeAccount({
        username: username.value,
        currentPassword: currentPassword.value,
        password: newPassword.value, // leer = Passwort unverändert
      })
      // Server hat die Sitzung beendet; App zeigt nun die Login-Maske.
      emit('changed')
    } else if (prefsChanged) {
      emit('saved')
    }
    model.value = false
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <OverlayView
    v-if="model"
    icon="mdi-account-circle-outline"
    :title="$t('account.title')"
    :busy="saving"
    @close="model = false"
  >
    <template #actions>
      <v-btn
        icon="mdi-content-save"
        variant="text"
        density="comfortable"
        color="primary"
        :loading="saving"
        :disabled="saving"
        :aria-label="$t('account.submit')"
        @click="submit"
      />
    </template>

    <p class="text-body-2 text-medium-emphasis mb-4">{{ $t('account.intro') }}</p>
    <v-form @submit.prevent="submit">
      <div class="text-subtitle-2 mb-2">{{ $t('account.credentials') }}</div>
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
        :type="revealPasswords ? 'text' : 'password'"
        :append-inner-icon="revealPasswords ? 'mdi-eye-off' : 'mdi-eye'"
        prepend-inner-icon="mdi-lock"
        autocomplete="current-password"
        variant="outlined"
        density="comfortable"
        class="mb-2"
        @click:append-inner="revealPasswords = !revealPasswords"
      />
      <v-divider class="mb-3" />
      <div class="text-caption text-medium-emphasis mb-1">{{ $t('account.newPasswordHint', [MIN_PASSWORD_LENGTH]) }}</div>
      <v-text-field
        v-model="newPassword"
        :label="$t('account.newPassword')"
        :type="revealPasswords ? 'text' : 'password'"
        :append-inner-icon="revealPasswords ? 'mdi-eye-off' : 'mdi-eye'"
        prepend-inner-icon="mdi-lock-plus"
        autocomplete="new-password"
        variant="outlined"
        density="comfortable"
        class="mb-2"
        @click:append-inner="revealPasswords = !revealPasswords"
      />
      <v-text-field
        v-model="newPasswordConfirm"
        :label="$t('account.newPasswordConfirm')"
        :type="revealPasswords ? 'text' : 'password'"
        :append-inner-icon="revealPasswords ? 'mdi-eye-off' : 'mdi-eye'"
        prepend-inner-icon="mdi-lock-check"
        autocomplete="new-password"
        variant="outlined"
        density="comfortable"
        @click:append-inner="revealPasswords = !revealPasswords"
      />

      <v-divider class="my-4" />
      <div class="text-subtitle-2 mb-2">{{ $t('account.preferences') }}</div>
      <v-text-field
        v-model="sessionLifetime"
        :label="$t('account.sessionLifetime')"
        :suffix="$t('account.hours')"
        :hint="$t('account.sessionLifetimeHint')"
        persistent-hint
        type="number"
        :min="MIN_SESSION_HOURS"
        :max="MAX_SESSION_HOURS"
        step="0.25"
        prepend-inner-icon="mdi-clock-outline"
        variant="outlined"
        density="comfortable"
        class="mb-4"
      />
      <v-select
        v-model="lastmodChoice"
        :items="updateLastmodOptions"
        :label="$t('account.updateLastmod')"
        :hint="$t('account.updateLastmodHint')"
        persistent-hint
        prepend-inner-icon="mdi-calendar-clock"
        variant="outlined"
        density="comfortable"
      />
      <v-switch
        v-model="showHidden"
        :label="$t('account.showHidden')"
        :hint="auth.ui?.hiddenAllowed ? $t('account.showHiddenHint') : $t('account.showHiddenLocked')"
        :disabled="!auth.ui?.hiddenAllowed"
        persistent-hint
        color="primary"
        density="comfortable"
        class="mt-2"
      />

      <v-alert v-if="error" type="error" density="compact" class="mt-3">{{ error }}</v-alert>
      <div class="text-caption text-medium-emphasis mt-3">{{ $t('account.noteCredentials') }}</div>
    </v-form>

    <template #footer>
      <v-btn variant="text" :disabled="saving" @click="model = false">{{ $t('account.cancel') }}</v-btn>
      <v-btn color="primary" variant="flat" :loading="saving" @click="submit">
        {{ $t('account.submit') }}
      </v-btn>
    </template>
  </OverlayView>
</template>
