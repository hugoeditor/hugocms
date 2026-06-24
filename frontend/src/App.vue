<script setup>
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from './stores/auth'
import { useFilesStore } from './stores/files'
import { api, setUnauthorizedHandler, suspendAuthGuard } from './api/client'
import { errorText, warningText } from './i18n/apiMessage'
import LoginView from './components/LoginView.vue'
import SetupView from './components/SetupView.vue'
import MountSidebar from './components/MountSidebar.vue'
import NemoToolbar from './components/NemoToolbar.vue'
import FileBrowser from './components/FileBrowser.vue'
import TrashView from './components/TrashView.vue'
import EditorPanel from './components/EditorPanel.vue'
import ReconfigureDialog from './components/ReconfigureDialog.vue'
import AccountDialog from './components/AccountDialog.vue'
import AssistantPanel from './components/AssistantPanel.vue'
import { useAssistantStore } from './stores/assistant'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { useConfirm } from './util/confirm'

const { t } = useI18n()
const confirm = useConfirm()

// Wiederkehrende Rückfrage: ungespeicherte Editor-Änderungen verwerfen?
function confirmDiscard() {
  return confirm({
    title: t('editor.discardTitle'),
    message: t('editor.discardConfirm'),
    confirmText: t('editor.discardAction'),
    color: 'warning',
  })
}
const auth = useAuthStore()
const files = useFilesStore()
const assistant = useAssistantStore()
const error = ref(null)
const fatalError = ref(null)
const warningsVisible = ref(false)

// Läuft eine geschützte Anfrage in EAUTH (Sitzung serverseitig abgelaufen),
// zentral zur Login-Ansicht zurückkehren: abmelden, alle geladenen Daten
// verwerfen und einen Hinweis zeigen. App.vue blendet dann automatisch die
// LoginView ein, sodass keine Daten mehr sichtbar sind und der Benutzer sich
// gleich wieder anmelden kann.
setUnauthorizedHandler(() => {
  if (!auth.authenticated) return // bereits abgemeldet
  auth.authenticated = false
  auth.user = null
  files.$reset()
  error.value = t('app.sessionExpired')
  // Ein veraltetes CSRF-Token ist hier unkritisch: Der Login ist serverseitig
  // von der CSRF-Prüfung ausgenommen und liefert ein frisches Token zurück.
})

async function loadMounts() {
  error.value = null
  // Während des Mount-Ladens nach dem Login den globalen Sitzungswächter
  // aussetzen: Der erste Versuch kann durch das Cookie-Timing (siehe unten) ein
  // EAUTH liefern, ohne dass die Sitzung tatsächlich abgelaufen ist.
  suspendAuthGuard(true)
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
  } finally {
    suspendAuthGuard(false)
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
    // Das Fehler-OBJEKT merken (nicht den fertig übersetzten Text), damit die
    // dauerhaft sichtbare Meldung beim Sprachwechsel über den Umschalter auf
    // diesem Bildschirm mitübersetzt wird. Die Auflösung passiert im Template.
    fatalError.value = e
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
  if (files.dirty && !(await confirmDiscard())) return
  await auth.logout()
  files.$reset()
}

// Orte-Menü: zu einem Mount-Point oder zum Papierkorb wechseln. Ist gerade eine
// Datei im Editor offen, wird dieser geschlossen und der Dateimanager mit dem
// gewählten Ziel angezeigt — bei ungespeicherten Änderungen vorher nachfragen
// (wie beim Schließen-Knopf des Editors).
async function leaveEditorThen(action) {
  if (files.dirty && !(await confirmDiscard())) return
  if (files.openFile) files.closeFile()
  action()
}
function openPlace(mount) {
  leaveEditorThen(() => files.openDir(mount.id))
}
function openTrashView() {
  leaveEditorThen(() => files.openTrash())
}

// --- Hugo aufrufen (Veröffentlichen) ---------------------------------------
const building = ref(false)
const buildResult = ref(null) // { success, exitCode, output, seconds } oder null
const editorPanelRef = ref(null)

// --- Konfiguration im laufenden Betrieb ändern -----------------------------
const reconfigureOpen = ref(false)
const accountOpen = ref(false)
const notice = ref(null) // kurze Erfolgsmeldung (Snackbar)

function onAccountChanged() {
  // Der Server hat die Sitzung beendet; der Store-Zustand ist bereits auf
  // abgemeldet aktualisiert, daher zeigt App nun die Login-Maske. Hinweis dazu.
  notice.value = t('account.note')
}

async function onReconfigured() {
  notice.value = t('reconfigure.success')
  // buildable/Warnungen können sich geändert haben (z. B. Hugo-Programm) —
  // Status neu laden.
  try {
    await auth.check()
  } catch {
    // unkritisch — beim nächsten Laden konsistent
  }
}

async function build() {
  if (building.value) return
  // Ungespeicherte Editor-Änderungen zuerst sichern, damit Hugo den aktuellen
  // Stand verarbeitet. Schlägt das Speichern fehl (z. B. Konflikt abgelehnt),
  // wird NICHT gebaut — der Editor zeigt den Fehler.
  if (files.openFile && files.dirty) {
    const saved = await editorPanelRef.value?.save()
    if (!saved) return
  }
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
          <p class="mb-4">{{ errorText(t, fatalError) }}</p>
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
      <!-- Äußerer Rahmen: zentriert das gesamte Fenster und begrenzt seine
           Breite auf großen Monitoren (volle Höhe bleibt erhalten). -->
      <div class="nemo-shell">
        <div class="nemo-window">
        <!-- Fenster-Titelleiste (Marke, Sprache, Benutzer, Abmelden) -->
        <header class="nemo-titlebar nemo-noselect">
          <v-icon icon="mdi-folder-multiple-outline" size="20" class="nemo-brand-icon" />
          <span class="nemo-title d-none d-md-inline">{{ $t('app.title') }}</span>

          <!-- Orte-Menü: schneller Sprung zu einem Mount-Point oder dem
               Papierkorb. Auf schmalen Schirmen ersetzt es die ausgeblendete
               Seitenleiste als Hauptnavigation. -->
          <v-menu v-if="files.mounts.length">
            <template #activator="{ props }">
              <v-btn
                v-bind="props"
                variant="text"
                size="small"
                prepend-icon="mdi-folder-network-outline"
                append-icon="mdi-menu-down"
                class="ml-2 nemo-places-btn"
              >
                <span class="d-none d-sm-inline">{{ $t('files.places') }}</span>
              </v-btn>
            </template>
            <v-list density="compact" min-width="200">
              <v-list-item
                v-for="mount in files.mounts"
                :key="mount.id"
                :prepend-icon="!files.trashMode && files.activeMount === mount.name ? 'mdi-folder-open' : 'mdi-folder-network-outline'"
                :title="mount.label"
                :active="!files.trashMode && files.activeMount === mount.name"
                @click="openPlace(mount)"
              />
              <v-divider class="my-1" />
              <v-list-item
                prepend-icon="mdi-trash-can-outline"
                :title="$t('trash.title')"
                :active="files.trashMode"
                @click="openTrashView()"
              />
            </v-list>
          </v-menu>

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
            <span class="d-none d-md-inline">{{ $t('build.publish') }}</span>
          </v-btn>

          <!-- KI-Assistent (nur wenn ein API-Schlüssel konfiguriert ist) -->
          <v-tooltip v-if="auth.ai.enabled" :text="$t('assistant.open')" location="bottom">
            <template #activator="{ props }">
              <v-btn
                v-bind="props"
                icon="mdi-robot-happy-outline"
                variant="text"
                size="small"
                class="mr-1"
                @click="assistant.open = !assistant.open"
              />
            </template>
          </v-tooltip>

          <!-- Konfiguration ändern (nur bei INI-basierter Installation) -->
          <v-tooltip v-if="auth.reconfigurable" :text="$t('reconfigure.open')" location="bottom">
            <template #activator="{ props }">
              <v-btn
                v-bind="props"
                icon="mdi-cog-outline"
                variant="text"
                size="small"
                class="mr-1"
                @click="reconfigureOpen = true"
              />
            </template>
          </v-tooltip>

          <LanguageSwitcher />
          <v-tooltip :text="$t('account.open')" location="bottom">
            <template #activator="{ props }">
              <button
                v-bind="props"
                type="button"
                class="nemo-user nemo-user-btn d-none d-sm-inline-block"
                @click="accountOpen = true"
              >{{ auth.user?.name }}</button>
            </template>
          </v-tooltip>
          <v-btn variant="text" size="small" prepend-icon="mdi-logout" @click="logout">
            <span class="d-none d-md-inline">{{ $t('app.logout') }}</span>
          </v-btn>
        </header>

        <!-- Arbeitsbereich unterhalb der Titelleiste: Werkzeugleiste,
             Seitenleiste + Inhalt. Der Editor legt sich als Überlagerung
             NUR über diesen Bereich — die Titelleiste (Sprache, Benutzer,
             Abmelden) bleibt auch im Editor sichtbar. -->
        <div class="nemo-workspace">
          <NemoToolbar />
          <div class="nemo-body">
            <aside class="nemo-aside d-none d-md-block" :class="{ collapsed: files.sidebarCollapsed }"><MountSidebar /></aside>
            <main class="nemo-mainarea">
              <TrashView v-if="files.trashMode" />
              <FileBrowser v-else />
            </main>
          </div>
          <EditorPanel ref="editorPanelRef" />
        </div>
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

    <!-- Konfiguration im laufenden Betrieb ändern -->
    <ReconfigureDialog v-model="reconfigureOpen" @saved="onReconfigured" />
    <AccountDialog v-model="accountOpen" @changed="onAccountChanged" />

    <!-- KI-Assistent (rechtes Seitenpanel) -->
    <AssistantPanel v-if="auth.ai.enabled" />

    <v-snackbar :model-value="!!error" color="error" @update:model-value="error = null">
      {{ error }}
    </v-snackbar>

    <v-snackbar :model-value="!!notice" color="success" :timeout="3000" @update:model-value="notice = null">
      {{ notice }}
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

    <!-- Globaler Bestätigungsdialog (Ersatz für window.confirm) -->
    <ConfirmDialog />
  </v-app>
</template>

<style scoped>
/* Nemo-Fenster: Titelleiste, Werkzeugleiste, dann Seitenleiste + Inhalt. */
/* Äußerer Rahmen: zentriert das Fenster und hebt es auf großen Monitoren mit
   einem abgesetzten Hintergrund ab. */
.nemo-shell {
  display: flex;
  justify-content: center;
  min-height: 100vh;
  background: var(--mint-shell-bg);
}
.nemo-window {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: var(--mint-content-max);
  height: 100vh;
  background: var(--mint-content);
  box-shadow: 0 0 1px rgba(0, 0, 0, 0.25), 0 0 22px rgba(0, 0, 0, 0.1);
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

/* Klickbarer Benutzername (öffnet den Anmeldedaten-Dialog) — als Text gestaltet,
   nicht als Standard-Button. */
.nemo-user-btn {
  background: none;
  border: none;
  padding: 2px 6px;
  border-radius: 4px;
  cursor: pointer;
  font: inherit;
}
.nemo-user-btn:hover {
  color: var(--mint-text);
  background: var(--mint-hover, rgba(0, 0, 0, 0.06));
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
  min-width: 0;
  overflow: hidden;
  transition: flex-basis 0.18s ease;
}
/* Eingeklappt: Breite auf 0 zusammenfahren (Inhalt wird abgeschnitten). */
.nemo-aside.collapsed {
  flex-basis: 0;
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
