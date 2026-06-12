<script setup>
import { onMounted, ref, watch } from 'vue'
import { useAuthStore } from './stores/auth'
import { useFilesStore } from './stores/files'
import LoginView from './components/LoginView.vue'
import SetupView from './components/SetupView.vue'
import MountSidebar from './components/MountSidebar.vue'
import FileBrowser from './components/FileBrowser.vue'
import EditorPanel from './components/EditorPanel.vue'

const auth = useAuthStore()
const files = useFilesStore()
const drawer = ref(true)
const error = ref(null)
const fatalError = ref(null)
const warningsVisible = ref(false)

async function loadMounts() {
  error.value = null
  try {
    await files.loadMounts()
  } catch (e) {
    // Direkt nach dem Login kann die erste Anfrage noch das alte Sitzungs-
    // Cookie tragen (der Server erneuert die ID per session_regenerate_id und
    // verwirft die alte Sitzung). Das neue Cookie ist beim zweiten Versuch
    // gesetzt — daher einmal erneut versuchen, bevor ein Fehler gemeldet wird.
    if (e.code === 'EAUTH') {
      try {
        await files.loadMounts()
        return
      } catch (retryError) {
        error.value = retryError.message
        return
      }
    }
    error.value = e.message
  }
}

// Anfangsprüfung (whoami) — läuft vor dem Login. Schlägt sie fehl, ist es ein
// Setup-/Verbindungsfehler, der erst behoben werden muss; er bleibt darum
// dauerhaft sichtbar (fatalError) statt als flüchtiger Snackbar zu verschwinden.
async function init() {
  fatalError.value = null
  try {
    await auth.check()
    if (auth.warnings.length > 0) warningsVisible.value = true
  } catch (e) {
    fatalError.value = e.message
  }
}

onMounted(init)

// Mounts laden, sobald die Anmeldung steht — gleicher Pfad für die anfängliche
// Sitzungsprüfung (Reload) wie für ein erfolgreiches Login. Bewusst reaktiv über
// auth.authenticated statt über ein Event der LoginView: Beim Statuswechsel hängt
// Vue die LoginView noch vor dem emit() aus, das Event ginge sonst verloren.
watch(() => auth.authenticated, (isAuthenticated) => {
  if (isAuthenticated) loadMounts()
})

async function logout() {
  await auth.logout()
  files.$reset()
}
</script>

<template>
  <v-app>
    <template v-if="fatalError">
      <v-main class="d-flex align-center justify-center pa-6">
        <v-alert type="error" prominent border="start" class="fatal-alert">
          <div class="text-h6 mb-2">HugoCMS ist nicht einsatzbereit</div>
          <p class="mb-4">{{ fatalError }}</p>
          <v-btn variant="outlined" @click="init">Erneut prüfen</v-btn>
        </v-alert>
      </v-main>
    </template>

    <template v-else-if="!auth.ready">
      <v-main class="d-flex align-center justify-center">
        <v-progress-circular indeterminate color="primary" />
      </v-main>
    </template>

    <template v-else-if="auth.setupRequired">
      <SetupView />
    </template>

    <template v-else-if="!auth.authenticated">
      <LoginView />
    </template>

    <template v-else>
      <v-app-bar color="primary" density="comfortable" flat>
        <v-app-bar-nav-icon @click="drawer = !drawer" />
        <v-app-bar-title>HugoCMS – Dateimanager</v-app-bar-title>
        <v-spacer />
        <span class="mr-4 text-body-2">{{ auth.user?.name }}</span>
        <v-btn variant="text" prepend-icon="mdi-logout" @click="logout">Abmelden</v-btn>
      </v-app-bar>

      <v-navigation-drawer v-model="drawer" width="260">
        <MountSidebar />
      </v-navigation-drawer>

      <v-main>
        <FileBrowser />
      </v-main>

      <EditorPanel />
    </template>

    <v-snackbar :model-value="!!error" color="error" @update:model-value="error = null">
      {{ error }}
    </v-snackbar>

    <v-snackbar
      :model-value="warningsVisible"
      color="warning"
      location="top"
      :timeout="10000"
      multi-line
      class="warning-snackbar"
      @update:model-value="warningsVisible = false"
    >
      <div class="font-weight-medium mb-1">Server-Hinweis zur Einrichtung</div>
      <ul class="ms-4">
        <li v-for="(w, i) in auth.warnings" :key="i">{{ w }}</li>
      </ul>
      <div class="d-flex justify-end mt-3">
        <v-btn variant="text" @click="warningsVisible = false">Schließen</v-btn>
      </div>
    </v-snackbar>
  </v-app>
</template>

<style scoped>
/* Dauerhafter Fehler-Block (Setup-/Pre-Login-Fehler): zentriert, begrenzt. */
.fatal-alert {
  max-width: 600px;
}

/* Hinweis-Snackbar breiter und mit etwas mehr Innenabstand, damit längere
   Meldungen (z. B. erwarteter Dateiname) lesbar sind. */
.warning-snackbar :deep(.v-snackbar__wrapper) {
  max-width: 720px;
}
.warning-snackbar :deep(.v-snackbar__content) {
  width: 100%;
  padding-block: 16px;
}
</style>
