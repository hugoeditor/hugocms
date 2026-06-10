<script setup>
import { onMounted, ref, watch } from 'vue'
import { useAuthStore } from './stores/auth'
import { useFilesStore } from './stores/files'
import LoginView from './components/LoginView.vue'
import MountSidebar from './components/MountSidebar.vue'
import FileBrowser from './components/FileBrowser.vue'
import EditorPanel from './components/EditorPanel.vue'

const auth = useAuthStore()
const files = useFilesStore()
const drawer = ref(true)
const error = ref(null)

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

onMounted(async () => {
  try {
    await auth.check()
  } catch (e) {
    error.value = e.message
  }
})

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
    <template v-if="!auth.ready">
      <v-main class="d-flex align-center justify-center">
        <v-progress-circular indeterminate color="primary" />
      </v-main>
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
  </v-app>
</template>
