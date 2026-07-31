<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { errorText } from '../i18n/apiMessage'
import { useConfirm } from '../util/confirm'

const { t } = useI18n()
const auth = useAuthStore()
const confirm = useConfirm()

const MIN_PASSWORD_LENGTH = 8
const ALL_SITES = '*'

const model = defineModel({ type: Boolean, default: false })
const emit = defineEmits(['notice'])

const users = ref([])
const sites = ref([]) // bekannte Webseiten dieser Installation (Hosts)
const loading = ref(false)
const busy = ref(false)
// Je Dialog ein eigener Fehler: Ein Fehler aus einem Unterdialog wäre in der
// Liste dahinter verdeckt und damit unsichtbar.
const error = ref(null)
const formError = ref(null)
const resetError = ref(null)

// Formular für „anlegen“ und „bearbeiten“. editing = null → neues Konto.
const formOpen = ref(false)
const editing = ref(null)
const form = ref({ username: '', password: '', role: 'editor', allSites: true, sites: [] })

// Getrenntes Formular fürs Zurücksetzen eines fremden Passworts.
const resetOpen = ref(false)
const resetTarget = ref(null)
const resetPassword = ref('')

const roleOptions = computed(() => [
  { title: t('users.roleEditor'), value: 'editor' },
  { title: t('users.roleAdmin'), value: 'admin' },
])

watch(model, (open) => {
  if (open) load()
})

async function load() {
  loading.value = true
  error.value = null
  try {
    const data = await auth.loadUsers()
    users.value = data.users ?? []
    sites.value = data.sites ?? []
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    loading.value = false
  }
}

// Die Webseiten-Auswahl eines Kontos lesbar machen.
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
      const data = await auth.updateUser({
        username: editing.value.name,
        role: form.value.role,
        sites: formSites(),
      })
      users.value = data.users ?? users.value
      emit('notice', t('users.updated', [editing.value.name]))
    } else {
      const data = await auth.createUser({
        username: form.value.username,
        password: form.value.password,
        role: form.value.role,
        sites: formSites(),
      })
      users.value = data.users ?? users.value
      emit('notice', t('users.created', [form.value.username]))
    }
    formOpen.value = false
  } catch (e) {
    formError.value = errorText(t, e)
  } finally {
    busy.value = false
  }
}

// Sperren/Entsperren — ein Klick, ohne Umweg über das Formular.
async function toggleBlocked(user) {
  error.value = null
  busy.value = true
  try {
    const data = await auth.updateUser({ username: user.name, disabled: !user.disabled })
    users.value = data.users ?? users.value
    emit('notice', t('users.updated', [user.name]))
  } catch (e) {
    error.value = errorText(t, e)
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
    await auth.resetUserPassword(resetTarget.value.name, resetPassword.value)
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
  error.value = null
  busy.value = true
  try {
    const data = await auth.deleteUser(user.name)
    users.value = data.users ?? users.value
    emit('notice', t('users.deleted', [user.name]))
  } catch (e) {
    error.value = errorText(t, e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <v-dialog v-model="model" width="760" scrollable>
    <v-card class="pa-2">
      <v-card-title class="text-h6">{{ $t('users.title') }}</v-card-title>
      <v-card-subtitle class="text-wrap">{{ $t('users.intro') }}</v-card-subtitle>
      <v-card-text>
        <!-- Ohne Pro-Lizenz bleibt die Verwaltung bedienbar, die angelegten
             Konten kommen aber nicht herein — das gehört an den Anfang. -->
        <v-alert v-if="!auth.isPro" type="info" density="compact" class="mb-3">
          {{ $t('users.proHint') }}
        </v-alert>
        <v-alert v-if="error" type="error" density="compact" class="mb-3">{{ error }}</v-alert>

        <div class="d-flex justify-end mb-2">
          <v-btn
            color="primary"
            variant="flat"
            size="small"
            prepend-icon="mdi-account-plus"
            :disabled="busy"
            @click="openCreate"
          >
            {{ $t('users.add') }}
          </v-btn>
        </div>

        <v-progress-linear v-if="loading" indeterminate class="mb-2" />

        <v-table v-if="users.length" density="comfortable">
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
            <tr v-for="user in users" :key="user.name">
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
        <div v-else-if="!loading" class="text-medium-emphasis text-caption">{{ $t('users.empty') }}</div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="model = false">{{ $t('users.cancel') }}</v-btn>
      </v-card-actions>
    </v-card>

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
                :items="sites"
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
  </v-dialog>
</template>
