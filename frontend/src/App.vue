<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
import AuditView from './components/AuditView.vue'
import ContentQualityDialog from './components/ContentQualityDialog.vue'
import EditorPanel from './components/EditorPanel.vue'
import ReconfigureDialog from './components/ReconfigureDialog.vue'
import AccountDialog from './components/AccountDialog.vue'
import LicenseDialog from './components/LicenseDialog.vue'
import RepositoryDialog from './components/RepositoryDialog.vue'
import HelpView from './components/HelpView.vue'
import AssistantPanel from './components/AssistantPanel.vue'
import { useAssistantStore } from './stores/assistant'
import { useHelpStore } from './stores/help'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { useConfirm } from './util/confirm'
import { buildNumber } from './util/version'

// Ziel-URLs der Links im Versionsdialog (sprachunabhängig).
const HUGOCMS_URL = 'https://hugocms.com/'
const COMPANY_URL = 'https://inter-data.de/'

// Adresse der veröffentlichten Webseite: die Domain-Wurzel, unter der HugoCMS
// läuft (die erzeugte public/ wird dort ausgeliefert). Konstant pro Seitenaufruf.
const siteUrl = window.location.origin

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
const help = useHelpStore()
const error = ref(null)
const fatalError = ref(null)
const warningsVisible = ref(false)

// --- Fensterbreite (Hauptfenster, große Bildschirme) -----------------------
// Die Breite des zentrierten Fensters lässt sich über Greifränder mit der Maus
// einstellen. Startwert ist die [user] content_width aus der hugocms.ini (über
// whoami geliefert). Laufzeit-Änderungen werden NICHT gespeichert — nach dem
// Neuladen gilt wieder der INI-Wert (Persistenz folgt mit der Mehrbenutzer-
// Umsetzung).
const MIN_CONTENT_WIDTH = 640
const contentWidth = ref(auth.ui?.contentWidth ?? 1200)

// Den Vorgabewert übernehmen, sobald whoami ihn liefert (bzw. wenn er sich
// durch eine Umkonfiguration ändert). Gleichbleibende Werte lassen eine bereits
// per Maus eingestellte Breite unberührt, da der Watcher nur auf Änderungen reagiert.
watch(
  () => auth.ui?.contentWidth,
  (w) => {
    if (typeof w === 'number' && w > 0) contentWidth.value = w
  },
)

let resizing = false

function startResize(event) {
  resizing = true
  event.preventDefault()
  window.addEventListener('pointermove', onResize)
  window.addEventListener('pointerup', stopResize)
}

function onResize(event) {
  if (!resizing) return
  // Die gezogene Kante folgt dem Zeiger: Da das Fenster mittig sitzt, ergibt
  // sich die Breite aus dem doppelten Abstand des Zeigers zur Bildschirmmitte.
  const center = window.innerWidth / 2
  const w = Math.round(Math.abs(event.clientX - center) * 2)
  contentWidth.value = Math.max(MIN_CONTENT_WIDTH, Math.min(window.innerWidth, w))
}

function stopResize() {
  resizing = false
  window.removeEventListener('pointermove', onResize)
  window.removeEventListener('pointerup', stopResize)
}

// Doppelklick auf einen Greifrand: zurück auf den Vorgabewert aus [user].
function resetWidth() {
  contentWidth.value = auth.ui?.contentWidth ?? 1200
}

onBeforeUnmount(stopResize)

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
  // Eine geöffnete Hilfe-Überlagerung schließen, sonst bliebe sie über der neu
  // gewählten Ansicht (Dateimanager, Papierkorb, SEO-Audit) liegen.
  help.close()
  if (files.openFile) files.closeFile()
  action()
}
function openPlace(mount) {
  leaveEditorThen(() => files.openDir(mount.id))
}
function openTrashView() {
  leaveEditorThen(() => files.openTrash())
}
function openAuditView() {
  // Schaltfläche wirkt als Umschalter: bei offenem Bericht wieder schließen.
  if (files.auditMode) {
    help.close()
    files.leaveAudit()
    return
  }
  leaveEditorThen(() => files.openAudit())
}

// --- Hugo aufrufen (Veröffentlichen) ---------------------------------------
const building = ref(false)
const buildResult = ref(null) // { success, exitCode, output, seconds } oder null
const buildDialogOpen = ref(false) // Ergebnis-Dialog mit Statistiken/Ausgabe
const buildSuccessToast = ref(false) // kurzer Erfolgs-Toast mit Details-Knopf

// Den Ergebnis-Dialog (Statistiken/Ausgabe) aus dem Erfolgs-Toast heraus öffnen.
function openBuildDetails() {
  buildSuccessToast.value = false
  buildDialogOpen.value = true
}
const editorPanelRef = ref(null)

// --- Konfiguration im laufenden Betrieb ändern -----------------------------
const reconfigureOpen = ref(false)
const accountOpen = ref(false)
const versionOpen = ref(false)
const licenseOpen = ref(false)
const repositoryOpen = ref(false)
const notice = ref(null) // kurze Erfolgsmeldung (Snackbar)

// --- Vertikale Werkzeugleiste (links, vor der Orte-Seitenleiste) -----------
// Eingeklappt/ausgeklappt; Vorgabe ausgeklappt. Das Erscheinungsbild richtet
// sich zusätzlich nach der Breite (CSS-Umbruchpunkt 960 px):
//   Desktop ausgeklappt → Icon + Name · Desktop eingeklappt → nur Icon-Schiene
//   Schmal  ausgeklappt → nur Icon-Schiene (Tooltip) · Schmal eingeklappt → ganz aus
const toolbarCollapsed = ref(false)

function onLicenseActivated() {
  notice.value = auth.isPro ? t('license.activatedPro') : t('license.activated')
}

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
    // Erfolg: nur ein kurzer Toast mit Details-Knopf. Fehler: der vollständige
    // Dialog mit Ausgabe wie bisher, damit die Fehlersuche sofort sichtbar ist.
    if (buildResult.value?.success) {
      buildSuccessToast.value = true
    } else {
      buildDialogOpen.value = true
    }
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
        <div class="nemo-window" :style="{ maxWidth: contentWidth + 'px' }">
        <!-- Greifränder zum Einstellen der Fensterbreite (nur große Schirme). -->
        <div
          class="nemo-resize nemo-resize--left"
          :title="$t('app.resizeWindow')"
          @pointerdown="startResize"
          @dblclick="resetWidth"
        />
        <div
          class="nemo-resize nemo-resize--right"
          :title="$t('app.resizeWindow')"
          @pointerdown="startResize"
          @dblclick="resetWidth"
        />
        <!-- Fenster-Titelleiste (Marke, Sprache, Benutzer, Abmelden) -->
        <header class="nemo-titlebar nemo-noselect">
          <!-- Werkzeugleiste ein-/ausblenden (nur schmale Schirme: dort ist die
               vertikale Leiste im eingeklappten Zustand vollständig ausgeblendet
               und braucht hier ihren Umschalter). -->
          <v-tooltip :text="$t('nav.tools')" location="bottom">
            <template #activator="{ props }">
              <v-btn
                v-bind="props"
                icon="mdi-menu"
                variant="text"
                size="small"
                class="d-md-none mr-1"
                @click="toolbarCollapsed = !toolbarCollapsed"
              />
            </template>
          </v-tooltip>

          <!-- Marke: Klick auf Symbol oder Titel öffnet die Versionsinfo. -->
          <v-tooltip :text="$t('version.open')" location="bottom">
            <template #activator="{ props }">
              <button
                v-bind="props"
                type="button"
                class="nemo-brand-btn nemo-noselect"
                @click="versionOpen = true"
              >
                <v-icon icon="mdi-folder-multiple-outline" size="20" class="nemo-brand-icon" />
                <span class="nemo-title d-none d-md-inline">{{ $t('app.title') }}</span>
              </button>
            </template>
          </v-tooltip>

          <!-- Orte-Menü: schneller Sprung zu einem Mount-Point oder dem
               Papierkorb. Auf schmalen Schirmen ersetzt es die ausgeblendete
               Seitenleiste als Hauptnavigation. -->
          <v-menu v-if="files.mounts.length">
            <template #activator="{ props }">
              <v-btn
                v-bind="props"
                variant="text"
                size="small"
                prepend-icon="mdi-folder"
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
                :prepend-icon="!files.trashMode && files.activeMount === mount.name ? 'mdi-folder-open' : 'mdi-folder'"
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

          <!-- Veröffentlichen, Repository, KI-Assistent, Lizenz sowie Konto und
               Konfiguration sind in die vertikale Werkzeugleiste (links)
               umgezogen — Konto und Konfiguration sitzen dort unten. -->

          <LanguageSwitcher />
          <v-btn variant="text" size="small" prepend-icon="mdi-logout" @click="logout">
            <span class="d-none d-md-inline">{{ $t('app.logout') }}</span>
          </v-btn>
        </header>

        <!-- Arbeitsbereich unterhalb der Titelleiste: Werkzeugleiste,
             Seitenleiste + Inhalt. Der Editor legt sich als Überlagerung
             NUR über diesen Bereich — die Titelleiste (Sprache, Benutzer,
             Abmelden) bleibt auch im Editor sichtbar. -->
        <div class="nemo-main">
          <!-- Vertikale Werkzeugleiste: globale Aktionen (Veröffentlichen,
               Repository, KI-Assistent, Lizenz). Liegt bewusst AUSSERHALB des
               Arbeitsbereichs, damit sie auch bei geöffnetem Editor erreichbar
               bleibt — der Editor überlagert nur den nemo-workspace rechts. -->
          <nav class="nemo-toolrail nemo-noselect" :class="{ collapsed: toolbarCollapsed }">
              <!-- Ein-/Ausklappen (nur Desktop: auf schmalen Schirmen übernimmt
                   der Hamburger in der Titelleiste das Ein-/Ausblenden). -->
              <v-tooltip :text="toolbarCollapsed ? $t('nav.expandTools') : $t('nav.collapseTools')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn nemo-tool-toggle d-none d-md-flex"
                    @click="toolbarCollapsed = !toolbarCollapsed"
                  >
                    <v-icon :icon="toolbarCollapsed ? 'mdi-chevron-right' : 'mdi-chevron-left'" size="20" />
                  </button>
                </template>
              </v-tooltip>

              <!-- Veröffentlichte Webseite ansehen (Domain-Wurzel, neuer Tab) -->
              <v-tooltip v-if="auth.buildable" :text="$t('site.open')" location="right">
                <template #activator="{ props }">
                  <a
                    v-bind="props"
                    :href="siteUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="nemo-tool-btn"
                  >
                    <v-icon icon="mdi-web" size="20" />
                    <span class="nemo-tool-label">{{ $t('site.open') }}</span>
                  </a>
                </template>
              </v-tooltip>

              <!-- Hugo veröffentlichen (nur wenn für die Webseite konfiguriert) -->
              <v-tooltip v-if="auth.buildable" :text="$t('build.publish')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn nemo-tool-btn--primary"
                    :disabled="building"
                    @click="build"
                  >
                    <v-progress-circular v-if="building" indeterminate size="18" width="2" />
                    <v-icon v-else icon="mdi-rocket-launch-outline" size="20" />
                    <span class="nemo-tool-label">{{ $t('build.publish') }}</span>
                  </button>
                </template>
              </v-tooltip>

              <!-- Repository (Git) — Pro-Funktion, nur bei gültiger Lizenz und
                   konfiguriertem Hugo-Projekt (dort liegt das Repository). -->
              <v-tooltip v-if="auth.git" :text="$t('repo.open')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn"
                    @click="repositoryOpen = true"
                  >
                    <v-icon icon="mdi-source-branch" size="20" />
                    <span class="nemo-tool-label">{{ $t('repo.open') }}</span>
                  </button>
                </template>
              </v-tooltip>

              <!-- SEO-Audit — Pro-Funktion, nur bei gültiger Lizenz und
                   konfiguriertem Hugo-Projekt (public/ und content/). -->
              <v-tooltip v-if="auth.audit" :text="$t('audit.open')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn"
                    :class="{ active: files.auditMode }"
                    @click="openAuditView"
                  >
                    <v-icon icon="mdi-clipboard-search-outline" size="20" />
                    <span class="nemo-tool-label">{{ $t('audit.open') }}</span>
                  </button>
                </template>
              </v-tooltip>

              <!-- KI-Assistent (nur wenn ein API-Schlüssel konfiguriert ist) -->
              <v-tooltip v-if="auth.ai.enabled" :text="$t('assistant.open')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn"
                    :class="{ active: assistant.open }"
                    @click="assistant.open = !assistant.open"
                  >
                    <v-icon icon="mdi-creation" size="20" />
                    <span class="nemo-tool-label">{{ $t('assistant.open') }}</span>
                  </button>
                </template>
              </v-tooltip>

              <!-- Pro-Lizenz aktivieren/anzeigen (pro Webseite; sichtbar, sobald
                   eine Mount-Konfiguration geladen ist) -->
              <v-tooltip v-if="auth.licensable" :text="$t('license.open')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn"
                    :class="{ 'nemo-tool-btn--pro': auth.isPro }"
                    @click="licenseOpen = true"
                  >
                    <v-icon :icon="auth.isPro ? 'mdi-license' : 'mdi-key-outline'" size="20" />
                    <span class="nemo-tool-label">{{ $t('license.open') }}</span>
                  </button>
                </template>
              </v-tooltip>

              <!-- Spacer schiebt Konto und Konfiguration nach unten (VS-Code). -->
              <div class="nemo-tool-spacer" />

              <!-- Konto/Anmeldedaten (unten) -->
              <v-tooltip :text="$t('account.open')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn"
                    @click="accountOpen = true"
                  >
                    <v-icon icon="mdi-account-circle-outline" size="20" />
                    <span class="nemo-tool-label">{{ auth.user?.name || $t('account.open') }}</span>
                  </button>
                </template>
              </v-tooltip>

              <!-- Konfiguration ändern (nur bei INI-basierter Installation) -->
              <v-tooltip v-if="auth.reconfigurable" :text="$t('reconfigure.open')" location="right">
                <template #activator="{ props }">
                  <button
                    v-bind="props"
                    type="button"
                    class="nemo-tool-btn"
                    @click="reconfigureOpen = true"
                  >
                    <v-icon icon="mdi-cog-outline" size="20" />
                    <span class="nemo-tool-label">{{ $t('reconfigure.open') }}</span>
                  </button>
                </template>
              </v-tooltip>
          </nav>

          <div class="nemo-workspace">
            <NemoToolbar />
            <div class="nemo-body">
              <aside class="nemo-aside d-none d-md-block" :class="{ collapsed: files.sidebarCollapsed }"><MountSidebar /></aside>
              <main class="nemo-mainarea">
                <TrashView v-if="files.trashMode" />
                <FileBrowser v-else />
              </main>
            </div>
            <!-- SEO-Audit als eigenständige Überlagerung über den gesamten
                 Arbeitsbereich (wie der Editor). Klare Trennung vom
                 Dateimanager; ein geöffneter Editor (z-index höher) legt sich
                 zusätzlich darüber. -->
            <AuditView v-if="files.auditMode" />
            <EditorPanel ref="editorPanelRef" />
            <!-- LLM-Content-Qualität: überlagernder Dialog, geöffnet aus dem
                 Editor und dem Kontextmenü der Dateiliste. -->
            <ContentQualityDialog />
            <!-- Hilfe-/Wissensdatenbank: Überlagerung mit Zurück-Button, öffnet
                 z. B. aus einem SEO-Audit-Fund die ausführliche Erklärung. -->
            <HelpView />
          </div>
        </div>
        </div>
      </div>
    </template>

    <!-- Ergebnis des Hugo-Laufs: vollständige Anzeige mit Statistiken/Ausgabe.
         Im Fehlerfall automatisch, im Erfolgsfall über „Details" im Toast. -->
    <v-dialog v-model="buildDialogOpen" width="720">
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
          <v-btn variant="text" @click="buildDialogOpen = false">{{ $t('app.close') }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Versionsinfo (Klick auf Marke in der Titelleiste) -->
    <v-dialog v-model="versionOpen" width="380">
      <v-card>
        <v-card-title class="d-flex align-center text-subtitle-1">
          <v-icon icon="mdi-folder-multiple-outline" color="primary" class="mr-2" />
          {{ $t('version.title') }}
        </v-card-title>
        <v-card-text>
          <div class="d-flex justify-space-between align-center">
            <span class="text-medium-emphasis">{{ $t('version.build') }}</span>
            <span class="text-h6 font-weight-medium">{{ buildNumber }}</span>
          </div>
          <div class="text-caption text-medium-emphasis mt-4 nemo-version-credit">
            <a :href="HUGOCMS_URL" target="_blank" rel="noopener noreferrer">{{ $t('app.title') }}</a>
            {{ $t('version.copyright') }}
            <a :href="COMPANY_URL" target="_blank" rel="noopener noreferrer">{{ $t('version.company') }}</a>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="versionOpen = false">{{ $t('app.close') }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Konfiguration im laufenden Betrieb ändern -->
    <ReconfigureDialog v-model="reconfigureOpen" @saved="onReconfigured" />
    <AccountDialog v-model="accountOpen" @changed="onAccountChanged" />

    <!-- Pro-Lizenz aktivieren · Git-Versionierung (Pro-Funktion) -->
    <LicenseDialog v-model="licenseOpen" @activated="onLicenseActivated" />
    <RepositoryDialog v-model="repositoryOpen" />

    <!-- KI-Assistent (rechtes Seitenpanel) -->
    <AssistantPanel v-if="auth.ai.enabled" />

    <v-snackbar :model-value="!!error" color="error" @update:model-value="error = null">
      {{ error }}
    </v-snackbar>

    <v-snackbar :model-value="!!notice" color="success" :timeout="3000" @update:model-value="notice = null">
      {{ notice }}
    </v-snackbar>

    <!-- Erfolgreicher Hugo-Lauf: kurzer Toast; „Details" öffnet den Dialog. -->
    <v-snackbar v-model="buildSuccessToast" color="success" :timeout="6000">
      {{ $t('build.successTitle') }}
      <template #actions>
        <v-btn variant="text" @click="openBuildDetails">{{ $t('build.details') }}</v-btn>
      </template>
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
  position: relative;
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: var(--mint-content-max);
  height: 100vh;
  background: var(--mint-content);
  box-shadow: 0 0 1px rgba(0, 0, 0, 0.25), 0 0 22px rgba(0, 0, 0, 0.1);
}

/* Greifränder zum Einstellen der Fensterbreite. Liegen über dem Inhalt (auch
   über der Editor-Überlagerung), damit die Breite überall anpassbar ist. Nur
   auf großen Bildschirmen, wo das Fenster zentriert und begrenzt ist. */
.nemo-resize {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 7px;
  z-index: 20;
  cursor: ew-resize;
  background: transparent;
  transition: background 0.12s;
  touch-action: none; /* Drag statt Scroll-Geste */
}
.nemo-resize--left { left: 0; }
.nemo-resize--right { right: 0; }
.nemo-resize:hover,
.nemo-resize:active {
  background: var(--mint-green-soft);
}
@media (max-width: 959.98px) {
  .nemo-resize { display: none; }
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
/* Marke als Schaltfläche: ohne eigenen Knopf-Look, öffnet die Versionsinfo. */
.nemo-brand-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.nemo-brand-btn:hover .nemo-title { text-decoration: underline; }
/* Links in der Versionsinfo (HugoCMS, Inter-Data) im Akzentgrün. */
.nemo-version-credit a {
  color: var(--mint-green);
  text-decoration: none;
}
.nemo-version-credit a:hover { text-decoration: underline; }
.nemo-title {
  font-weight: 600;
  font-size: 0.95rem;
}
.nemo-titlebar-spacer { flex: 1 1 auto; min-width: 0; }

/* Schmale Schirme: Titelleiste kompakt halten, damit alle Schaltflächen in eine
   Zeile passen. Vuetify gibt jedem v-btn min-width:64px — bei mehreren Buttons
   (Werkzeug-Umschalter, Orte, Sprache, Abmelden) überläuft die Leiste sonst den
   Viewport, verbreitert den Body und löst horizontales Scrollen aus (was u. a.
   das vollbreite KI-Panel zu breit wirken ließ). */
@media (max-width: 959.98px) {
  .nemo-titlebar {
    gap: 2px;
    padding: 0 4px;
  }
  .nemo-titlebar :deep(.v-btn) {
    min-width: 0;
  }
}

/* Zeilen-Ebene unterhalb der Titelleiste: links die vertikale Werkzeugleiste,
   rechts der Arbeitsbereich. Die Leiste liegt hier (nicht im Workspace), damit
   die Editor-Überlagerung sie nicht verdeckt und die Werkzeuge immer
   erreichbar bleiben. */
.nemo-main {
  flex: 1 1 auto;
  display: flex;
  min-height: 0;
}

/* Bezugsrahmen für die Editor-Überlagerung (rechts neben der Werkzeugleiste). */
.nemo-workspace {
  position: relative;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
}

.nemo-body {
  flex: 1 1 auto;
  display: flex;
  min-height: 0;
}

/* --- Vertikale Werkzeugleiste (links vor der Orte-Seitenleiste) ------------ */
.nemo-toolrail {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 188px;            /* Desktop ausgeklappt: Platz für Icon + Name */
  padding: 8px 8px;
  background: var(--mint-panel);
  border-right: 1px solid var(--mint-border);
  overflow: hidden;
  transition: width 0.18s ease;
}
/* Desktop eingeklappt: schmale Icon-Schiene. */
.nemo-toolrail.collapsed {
  width: 48px;
}

/* Werkzeug-Schaltfläche: Icon links, Name rechts (GTK-artig flach). */
.nemo-tool-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  height: 38px;
  padding: 0 8px;
  border: 1px solid transparent;
  border-radius: var(--mint-radius);
  background: transparent;
  color: var(--mint-text);
  font: inherit;
  font-size: 0.9rem;
  text-align: left;
  text-decoration: none; /* auch als <a> (Webseiten-Link) ohne Unterstreichung */
  white-space: nowrap;
  cursor: pointer;
}
.nemo-tool-btn :deep(.v-icon) { flex: 0 0 auto; }
.nemo-tool-label {
  flex: 1 1 auto;
  overflow: hidden;
  text-overflow: ellipsis;
}
.nemo-tool-btn:hover:not(:disabled) {
  background: var(--mint-panel-hover);
  border-color: var(--mint-border);
}
.nemo-tool-btn:active:not(:disabled) {
  background: #e0e0dd;
}
.nemo-tool-btn:disabled {
  color: #b6b6b3;
  cursor: default;
}
/* Aktiver Zustand (z. B. KI-Panel geöffnet). */
.nemo-tool-btn.active {
  background: var(--mint-green-soft);
  border-color: #cfe0c5;
  color: var(--mint-green-soft-text);
}
/* Veröffentlichen: hervorgehobene Hauptaktion. */
.nemo-tool-btn--primary {
  background: var(--mint-green);
  color: #fff;
}
.nemo-tool-btn--primary:hover:not(:disabled) {
  background: var(--mint-green-dark);
  border-color: var(--mint-green-dark);
}
/* Pro-Lizenz aktiv: grün eingefärbtes Symbol. */
.nemo-tool-btn--pro { color: var(--mint-green-soft-text); }
/* Umschalter sitzt am oberen Rand, etwas abgesetzt. */
.nemo-tool-toggle {
  justify-content: flex-end;
  height: 30px;
  margin-bottom: 2px;
  color: var(--mint-text-muted);
}

/* Schiebt die unteren Werkzeuge (Konto, Konfiguration) ans Leistenende. */
.nemo-tool-spacer { flex: 1 1 auto; }

/* Eingeklappt (Desktop): nur Icons, zentriert; Namen ausblenden. */
.nemo-toolrail.collapsed .nemo-tool-label { display: none; }
.nemo-toolrail.collapsed .nemo-tool-btn { justify-content: center; padding: 0; }
.nemo-toolrail.collapsed .nemo-tool-toggle { justify-content: center; }

/* Schmale Schirme (Handy/Tablet): Namen passen nicht — immer Icon-Schiene.
   Eingeklappt heißt hier vollständig ausgeblendet (Umschalter in der
   Titelleiste). */
@media (max-width: 959.98px) {
  .nemo-toolrail {
    width: 48px;
  }
  .nemo-toolrail .nemo-tool-label { display: none; }
  .nemo-toolrail .nemo-tool-btn { justify-content: center; padding: 0; }
  .nemo-toolrail.collapsed {
    width: 0;
    padding: 0;
    border-right: none;
  }
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
