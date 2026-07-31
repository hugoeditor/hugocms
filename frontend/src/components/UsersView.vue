<script setup>
// Benutzerverwaltung: Konten dieser Installation anlegen, zuordnen, sperren,
// löschen und fremde Passwörter neu setzen. Nur beim Mehrbenutzer-Verfahren und
// nur für Administratoren — der Server meldet das über auth.manageUsers.
//
// Als Overlay-Ansicht wie StatusView/ReviewQueueView (nicht als v-dialog). Die
// beiden Formulare (anlegen/bearbeiten, Passwort neu vergeben) bleiben kleine
// Dialoge darüber: Sie sind kurz und sollen die Liste nicht ersetzen.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUsersStore } from '../stores/users'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import { useConfirm } from '../util/confirm'

// Erfolgsmeldungen gehen an App.vue: Dort steht die zentrale Snackbar, die
// alle Ansichten nutzen.
const emit = defineEmits(['notice'])

const { t } = useI18n()
const store = useUsersStore()
const auth = useAuthStore()
const confirm = useConfirm()

const MIN_PASSWORD_LENGTH = 8
const ALL_SITES = '*'

const busy = ref(false)

// Formular für „anlegen“ und „bearbeiten“. editing = null → neues Konto.
const formOpen = ref(false)
const editing = ref(null)
const form = ref({ username: '', password: '', role: 'editor', allSites: true, sites: [] })
const formError = ref(null)

// Getrenntes Formular fürs Zurücksetzen eines fremden Passworts.
const resetOpen = ref(false)
const resetTarget = ref(null)
const resetPassword = ref('')
const resetError = ref(null)

const roleOptions = computed(() => [
  { title: t('users.roleEditor'), value: 'editor' },
  { title: t('users.roleAdmin'), value: 'admin' },
])

// Die Webseiten-Zuordnung eines Kontos lesbar machen.
function siteLabel(user) {
  if (user.role === 'admin' || user.sites.includes(ALL_SITES)) return t('users.allSites')
  if (user.sites.length === 0) return t('users.noSites')
  return user.sites.join(', ')
}

function openCreate() {
  editing.value = null
  form.value = { username: '', password: '', role: 'editor', allSites: true, sites: [] }
  formError.value = null
  formOpen.value = true
}

function openEdit(user) {
  editing.value = user
  form.value = {
    username: user.name,
    password: '',
    role: user.role,
    allSites: user.sites.includes(ALL_SITES) || user.sites.length === 0,
    sites: user.sites.filter((s) => s !== ALL_SITES),
  }
  formError.value = null
  formOpen.value = true
}

function formSites() {
  return form.value.allSites ? [ALL_SITES] : form.value.sites
}

async function submitForm() {
  formError.value = null
  if (!editing.value && form.value.password.length < MIN_PASSWORD_LENGTH) {
    formError.value = t('users.passwordTooShort', [MIN_PASSWORD_LENGTH])
    return
  }
  busy.value = true
  try {
    if (editing.value) {
      await store.update({
        username: editing.value.name,
        role: form.value.role,
        sites: formSites(),
      })
      emit('notice', t('users.updated', [editing.value.name]))
    } else {
      await store.create({
        username: form.value.username,
        password: form.value.password,
        role: form.value.role,
        sites: formSites(),
      })
      emit('notice', t('users.created', [form.value.username]))
    }
    formOpen.value = false
  } catch (e) {
    formError.value = errorText(t, e)
  } finally {
    busy.value = false
  }
}

// Sperren/Entsperren — ein Klick, ohne Umweg über das Formular. Der Fehler
// gehört hier in die Liste: Der Klick kommt ja aus der Zeile.
async function toggleBlocked(user) {
  store.error = null
  busy.value = true
  try {
    await store.update({ username: user.name, disabled: !user.disabled })
    emit('notice', t('users.updated', [user.name]))
  } catch (e) {
    store.error = e
  } finally {
    busy.value = false
  }
}

function openReset(user) {
  resetTarget.value = user
  resetPassword.value = ''
  resetError.value = null
  resetOpen.value = true
}

async function submitReset() {
  resetError.value = null
  if (resetPassword.value.length < MIN_PASSWORD_LENGTH) {
    resetError.value = t('users.passwordTooShort', [MIN_PASSWORD_LENGTH])
    return
  }
  busy.value = true
  try {
    await store.resetPassword(resetTarget.value.name, resetPassword.value)
    emit('notice', t('users.passwordReset', [resetTarget.value.name]))
    resetOpen.value = false
  } catch (e) {
    resetError.value = errorText(t, e)
  } finally {
    busy.value = false
  }
}

async function removeUser(user) {
  const ok = await confirm({
    title: t('users.deleteTitle'),
    message: t('users.deleteConfirm', [user.name]),
    confirmText: t('users.deleteAction'),
    color: 'error',
  })
  if (!ok) return
  store.error = null
  busy.value = true
  try {
    await store.remove(user.name)
    emit('notice', t('users.deleted', [user.name]))
  } catch (e) {
    store.error = e
  } finally {
    busy.value = false
  }
}

</script>

<template>
  <!-- auth.manageUsers zusätzlich zum Ansichtszustand: Ein Kontowechsel darf
       die Verwaltung nicht offen zurücklassen, wenn das neue Konto sie nicht
       aufrufen darf. Die harte Grenze zieht ohnehin der Server. -->
  <div v-if="store.open && auth.manageUsers" class="us-overlay">
    <header class="us-head nemo-noselect">
      <button class="us-back" :title="$t('common.close')" @click="store.close()">
        <v-icon icon="mdi-arrow-left" size="20" />
      </button>
      <v-icon icon="mdi-account-group-outline" size="18" class="us-head-icon" />
      <span class="us-head-title text-truncate">{{ $t('users.title') }}</span>
      <v-spacer />
      <v-btn
        size="small"
        variant="text"
        prepend-icon="mdi-refresh"
        :loading="store.loading"
        @click="store.fetch()"
      >
        {{ $t('common.refresh') }}
      </v-btn>
      <v-btn
        color="primary"
        variant="flat"
        size="small"
        prepend-icon="mdi-account-plus"
        class="ml-2"
        :disabled="busy"
        @click="openCreate"
      >
        {{ $t('users.add') }}
      </v-btn>
    </header>

    <div class="us-content nemo-scroll">
      <div class="us-inner">
        <p class="us-hint">{{ $t('users.intro') }}</p>

        <!-- Ohne Pro-Lizenz bleibt die Verwaltung bedienbar, die angelegten
             Konten kommen aber nicht herein — das gehört an den Anfang. -->
        <v-alert v-if="!auth.isPro" type="info" density="comfortable" class="mb-4">
          {{ $t('users.proHint') }}
        </v-alert>
        <v-alert v-if="store.error" type="error" density="comfortable" class="mb-4">
          {{ errorText(t, store.error) }}
        </v-alert>

        <v-progress-linear v-if="store.loading" indeterminate class="mb-2" />

        <div v-if="store.users.length" class="us-card">
          <v-table density="comfortable">
            <thead>
              <tr>
                <th>{{ $t('users.name') }}</th>
                <th>{{ $t('users.role') }}</th>
                <th>{{ $t('users.sites') }}</th>
                <th>{{ $t('users.status') }}</th>
                <th class="text-right" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in store.users" :key="user.name">
                <td>
                  {{ user.name }}
                  <v-chip v-if="user.self" size="x-small" class="ml-1" variant="tonal">
                    {{ $t('users.self') }}
                  </v-chip>
                </td>
                <td>{{ user.role === 'admin' ? $t('users.roleAdmin') : $t('users.roleEditor') }}</td>
                <td class="text-caption">{{ siteLabel(user) }}</td>
                <td>
                  <v-chip :color="user.disabled ? 'warning' : 'success'" size="x-small" variant="tonal">
                    {{ user.disabled ? $t('users.disabled') : $t('users.active') }}
                  </v-chip>
                </td>
                <td class="text-right text-no-wrap">
                  <!-- Das eigene Konto ändert man im Konto-Dialog, nicht hier. -->
                  <template v-if="!user.self">
                    <v-tooltip :text="$t('users.editTitle', [user.name])" location="top">
                      <template #activator="{ props }">
                        <v-btn
                          v-bind="props"
                          icon="mdi-pencil"
                          variant="text"
                          size="small"
                          :disabled="busy"
                          @click="openEdit(user)"
                        />
                      </template>
                    </v-tooltip>
                    <v-tooltip :text="$t('users.resetAction')" location="top">
                      <template #activator="{ props }">
                        <v-btn
                          v-bind="props"
                          icon="mdi-lock-reset"
                          variant="text"
                          size="small"
                          :disabled="busy"
                          @click="openReset(user)"
                        />
                      </template>
                    </v-tooltip>
                    <v-tooltip :text="user.disabled ? $t('users.unblock') : $t('users.block')" location="top">
                      <template #activator="{ props }">
                        <v-btn
                          v-bind="props"
                          :icon="user.disabled ? 'mdi-account-check' : 'mdi-account-cancel'"
                          variant="text"
                          size="small"
                          :disabled="busy"
                          @click="toggleBlocked(user)"
                        />
                      </template>
                    </v-tooltip>
                    <v-tooltip :text="$t('users.deleteAction')" location="top">
                      <template #activator="{ props }">
                        <v-btn
                          v-bind="props"
                          icon="mdi-delete"
                          variant="text"
                          size="small"
                          color="error"
                          :disabled="busy"
                          @click="removeUser(user)"
                        />
                      </template>
                    </v-tooltip>
                  </template>
                </td>
              </tr>
            </tbody>
          </v-table>
        </div>
        <div v-else-if="!store.loading" class="us-hint">{{ $t('users.empty') }}</div>
      </div>
    </div>

    <!-- Anlegen / Bearbeiten -->
    <v-dialog v-model="formOpen" width="480" :persistent="busy">
      <v-card class="pa-2">
        <v-card-title class="text-h6">
          {{ editing ? $t('users.editTitle', [editing.name]) : $t('users.addTitle') }}
        </v-card-title>
        <v-card-text>
          <v-form @submit.prevent="submitForm">
            <v-text-field
              v-if="!editing"
              v-model="form.username"
              :label="$t('users.name')"
              prepend-inner-icon="mdi-account"
              variant="outlined"
              density="comfortable"
              class="mb-2"
            />
            <v-text-field
              v-if="!editing"
              v-model="form.password"
              :label="$t('users.password')"
              :hint="$t('users.passwordHint', [MIN_PASSWORD_LENGTH])"
              persistent-hint
              type="password"
              autocomplete="new-password"
              prepend-inner-icon="mdi-lock-plus"
              variant="outlined"
              density="comfortable"
              class="mb-4"
            />
            <v-select
              v-model="form.role"
              :items="roleOptions"
              :label="$t('users.role')"
              prepend-inner-icon="mdi-shield-account"
              variant="outlined"
              density="comfortable"
              class="mb-2"
            />
            <!-- Administratoren erreichen ohnehin alles — die Zuordnung
                 erscheint deshalb nur für die Rolle „Redakteur“. -->
            <template v-if="form.role !== 'admin'">
              <v-switch
                v-model="form.allSites"
                :label="$t('users.allSites')"
                color="primary"
                density="compact"
                hide-details
                class="mb-1"
              />
              <v-select
                v-if="!form.allSites"
                v-model="form.sites"
                :items="store.sites"
                :label="$t('users.sites')"
                :hint="$t('users.sitesHint')"
                persistent-hint
                multiple
                chips
                prepend-inner-icon="mdi-web"
                variant="outlined"
                density="comfortable"
              />
            </template>
          </v-form>
          <v-alert v-if="formError" type="error" density="compact" class="mt-3">{{ formError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="formOpen = false">{{ $t('users.cancel') }}</v-btn>
          <v-btn color="primary" variant="flat" :loading="busy" @click="submitForm">
            {{ $t('users.save') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Passwort neu vergeben -->
    <v-dialog v-model="resetOpen" width="440" :persistent="busy">
      <v-card class="pa-2">
        <v-card-title class="text-h6">{{ $t('users.resetTitle', [resetTarget?.name ?? '']) }}</v-card-title>
        <v-card-subtitle class="text-wrap">{{ $t('users.resetHint') }}</v-card-subtitle>
        <v-card-text>
          <v-text-field
            v-model="resetPassword"
            :label="$t('users.passwordNew')"
            :hint="$t('users.passwordHint', [MIN_PASSWORD_LENGTH])"
            persistent-hint
            type="password"
            autocomplete="new-password"
            prepend-inner-icon="mdi-lock-reset"
            variant="outlined"
            density="comfortable"
          />
          <v-alert v-if="resetError" type="error" density="compact" class="mt-3">{{ resetError }}</v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="busy" @click="resetOpen = false">{{ $t('users.cancel') }}</v-btn>
          <v-btn color="primary" variant="flat" :loading="busy" @click="submitReset">
            {{ $t('users.resetAction') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<style scoped>
/* Overlay über dem Arbeitsbereich — z-index wie Systemstatus und
   Freigabe-Warteschlange. */
.us-overlay {
  position: absolute;
  inset: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  background: var(--mint-content);
}

.us-head {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-panel);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.us-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  padding: 3px 6px;
  color: var(--mint-text);
  cursor: pointer;
}
.us-back:hover { background: var(--mint-panel-hover); }
.us-head-icon { color: var(--mint-green); }
.us-head-title { font-weight: 600; font-size: 0.95rem; min-width: 0; }

.us-content { flex: 1 1 auto; overflow: auto; }
/* Volle Breite wie die übrigen Overlay-Ansichten. */
.us-inner { max-width: none; margin: 0; padding: 12px 12px 24px; }

.us-hint {
  font-size: 0.82rem;
  color: var(--mint-text-muted);
  margin: 0 0 12px;
}

.us-card {
  border: 1px solid var(--mint-border);
  border-radius: var(--mint-radius);
  background: #fff;
  overflow: hidden;
}
</style>
