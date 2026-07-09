// Zugangsschranke für KI-Funktionen, die einen KI-API-Schlüssel voraussetzen.
// Die zugehörigen Bedienelemente (KI-Assistent, Content-Qualität/Verbessern)
// bleiben immer sichtbar, damit die Funktionen auffindbar sind. Ist kein
// Schlüssel konfiguriert, meldet sich die Funktion beim Anklicken mit einem
// Hinweisdialog und bietet an, die Konfiguration zu öffnen.
//
// Analog zu util/confirm.js: ein app-weit geteilter Zustand, den App.vue
// beobachtet, um den ReconfigureDialog zu öffnen.
import { reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useConfirm } from './confirm'

const state = reactive({
  // Wird auf true gesetzt, wenn eine KI-Funktion um das Öffnen der
  // Konfiguration bittet. App.vue setzt es nach dem Öffnen wieder zurück.
  reconfigureRequested: false,
})

/** Interner Zugriff für App.vue (beobachtet das Flag). */
export function aiGateState() {
  return state
}

/**
 * Liefert eine Funktion, die prüft, ob die KI einsatzbereit ist. Ist sie es
 * nicht, erscheint ein Hinweisdialog; bei Bestätigung wird die Konfiguration
 * geöffnet. Rückgabe: Promise<boolean> — true, wenn die aufrufende Funktion
 * fortfahren darf (KI verfügbar).
 *
 * Muss im setup() aufgerufen werden (nutzt useI18n/useAuthStore/useConfirm).
 */
export function useAiGate() {
  const { t } = useI18n()
  const confirm = useConfirm()
  const auth = useAuthStore()
  return async function requireAi() {
    if (auth.ai.enabled) return true
    const ok = await confirm({
      title: t('assistant.unavailableTitle'),
      message: t('assistant.unavailableMessage'),
      confirmText: t('assistant.unavailableConfirm'),
    })
    if (ok) state.reconfigureRequested = true
    return false
  }
}
