<script setup>
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from './stores/auth'
import { useFilesStore } from './stores/files'
import { api } from './api/client'
import { errorText, warningText } from './i18n/apiMessage'
import LoginView from './components/LoginView.vue'
import SetupView from './components/SetupView.vue'
import MountSidebar from './components/MountSidebar.vue'
import NemoToolbar from './components/NemoToolbar.vue'
import FileBrowser from './components/FileBrowser.vue'
import TrashView from './components/TrashView.vue'
import EditorPanel from './components/EditorPanel.vue'
import LanguageSwitcher from './components/LanguageSwitcher.vue'

const { t } = useI18n()
const auth = useAuthStore()
const files = useFilesStore()
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
        error.value = errorText(t, retryError)
        return
      }
    }
    error.value = errorText(t, e)
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
    fatalError.value = errorText(t, e)
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
  // Der Abmelden-Knopf ist auch im Editor erreichbar — ungespeicherte
  // Änderungen nicht stillschweigend verwerfen.
  if (files.dirty && !confirm(t('editor.discardConfirm'))) return
  await auth.logout()
  files.$reset()
}

// --- Hugo aufrufen (Veröffentlichen) ---------------------------------------
const building = ref(false)
const buildResult = ref(null) // { success, exitCode, output, seconds } oder null

async function build() {
  if (building.value) return
  building.value = true
  try {
    buildResult.value = await api.post('build')
  } catch (e) {
    error.value = errorText(t, e) // Konfigurationsfehler als Snackbar
  } finally {
    building.value = false
  }
}
</script>

<template>
  <v-app>
    <template v-if="fatalError">
      <v-main class="d-flex flex-column align-center justify-center pa-6">
        <div class="d-flex justify-end fatal-alert mb-2">
          <LanguageSwitcher />
        </div>
        <v-alert type="error" prominent border="start" class="fatal-alert nemo-alert">
          <div class="text-h6 mb-2">{{ $t('app.notReady') }}</div>
          <p class="mb-4">{{ fatalError }}</p>
          <v-btn variant="outlined" @click="init">{{ $t('app.retry') }}</v-btn>
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
      <div class="nemo-window">
        <!-- Fenster-Titelleiste (Marke, Sprache, Benutzer, Abmelden) -->
        <header class="nemo-titlebar nemo-noselect">
          <v-icon icon="mdi-folder-multiple-outline" size="20" class="nemo-brand-icon" />
          <span class="nemo-title">{{ $t('app.title') }}</span>
          <div class="nemo-titlebar-spacer" />

          <!-- Hugo aufrufen (nur wenn für die Webseite konfiguriert) -->
          <v-btn
            v-if="auth.buildable"
            variant="flat"
            color="primary"
            size="small"
            prepend-icon="mdi-rocket-launch-outline"
            class="mr-2"
            :loading="building"
            @click="build"
          >
            {{ $t('build.publish') }}
          </v-btn>

          <LanguageSwitcher />
          <span class="nemo-user">{{ auth.user?.name }}</span>
          <v-btn variant="text" size="small" prepend-icon="mdi-logout" @click="logout">
            {{ $t('app.logout') }}
          </v-btn>
        </header>

        <!-- Arbeitsbereich unterhalb der Titelleiste: Werkzeugleiste,
             Seitenleiste + Inhalt. Der Editor legt sich als Überlagerung
             NUR über diesen Bereich — die Titelleiste (Sprache, Benutzer,
             Abmelden) bleibt auch im Editor sichtbar. -->
        <div class="nemo-workspace">
          <NemoToolbar />
          <div class="nemo-body">
            <aside class="nemo-aside"><MountSidebar /></aside>
            <main class="nemo-mainarea">
              <TrashView v-if="files.trashMode" />
              <FileBrowser v-else />
            </main>
          </div>
          <EditorPanel />
        </div>
      </div>
    </template>

    <!-- Ergebnis des Hugo-Laufs: Erfolg oder vollständige Fehlerausgabe -->
    <v-dialog :model-value="!!buildResult" width="720" @update:model-value="buildResult = null">
      <v-card v-if="buildResult">
        <v-card-title class="d-flex align-center text-subtitle-1">
          <v-icon
            :icon="buildResult.success ? 'mdi-check-circle' : 'mdi-alert-circle'"
            :color="buildResult.success ? 'success' : 'error'"
            class="mr-2"
          />
          {{ buildResult.success ? $t('build.successTitle') : $t('build.failTitle') }}
        </v-card-title>
        <v-card-text>
          <div class="text-caption text-medium-emphasis mb-2">
            {{ $t('build.duration', [buildResult.seconds]) }}
            <template v-if="!buildResult.success"> · {{ $t('build.exitCode', [buildResult.exitCode]) }}</template>
          </div>
          <pre class="build-output nemo-scroll">{{ buildResult.output }}</pre>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="buildResult = null">{{ $t('app.close') }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

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
      <div class="font-weight-medium mb-1">{{ $t('app.setupWarningTitle') }}</div>
      <ul class="ms-4">
        <li v-for="(w, i) in auth.warnings" :key="i">{{ warningText(t, w) }}</li>
      </ul>
      <div class="d-flex justify-end mt-3">
        <v-btn variant="text" @click="warningsVisible = false">{{ $t('app.close') }}</v-btn>
      </div>
    </v-snackbar>
  </v-app>
</template>

<style scoped>
/* Nemo-Fenster: Titelleiste, Werkzeugleiste, dann Seitenleiste + Inhalt. */
.nemo-window {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: var(--mint-content);
}

.nemo-titlebar {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 46px;
  padding: 0 10px;
  background: var(--mint-titlebar);
  border-bottom: 1px solid var(--mint-border);
  color: var(--mint-text);
}
.nemo-brand-icon { color: var(--mint-green); }
.nemo-title {
  font-weight: 600;
  font-size: 0.95rem;
}
.nemo-titlebar-spacer { flex: 1 1 auto; }
.nemo-user {
  font-size: 0.85rem;
  color: var(--mint-text-muted);
  margin: 0 4px 0 8px;
}

/* Bezugsrahmen für die Editor-Überlagerung (unterhalb der Titelleiste). */
.nemo-workspace {
  position: relative;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.nemo-body {
  flex: 1 1 auto;
  display: flex;
  min-height: 0;
}
.nemo-aside {
  flex: 0 0 210px;
  min-height: 0;
}
.nemo-mainarea {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
}

/* Dauerhafter Fehler-Block (Setup-/Pre-Login-Fehler): zentriert, begrenzt. */
.fatal-alert {
  max-width: 600px;
}

/* Hugo-Ausgabe im Ergebnisdialog: Monospace, scrollbar, begrenzt hoch. */
.build-output {
  max-height: 50vh;
  overflow: auto;
  background: #f4f4f3;
  border: 1px solid #d3d3d1;
  border-radius: 4px;
  padding: 10px 12px;
  font-size: 0.8rem;
  white-space: pre-wrap;
  word-break: break-word;
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
