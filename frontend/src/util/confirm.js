// Promise-basierte Bestätigungsabfrage als Ersatz für das blockierende
// window.confirm(). Ein einziger, app-weit geteilter Zustand speist die global
// eingebundene ConfirmDialog-Komponente; useConfirm() öffnet den Dialog und
// liefert ein Promise, das mit true (bestätigt) oder false (abgebrochen) endet.
import { reactive } from 'vue'

const state = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: '',
  cancelText: '',
  color: 'primary',
})

let resolver = null

/** Interner Zugriff für die ConfirmDialog-Komponente. */
export function confirmState() {
  return state
}

/** Schließt den Dialog und löst das Promise auf (true = bestätigt). */
export function settleConfirm(value) {
  state.open = false
  const resolve = resolver
  resolver = null
  if (resolve) {
    resolve(value)
  }
}

/**
 * Liefert eine confirm-Funktion. Aufruf:
 *   const ok = await confirm({ message, title?, confirmText?, cancelText?, color? })
 */
export function useConfirm() {
  return function confirm({ title = '', message = '', confirmText = '', cancelText = '', color = 'primary' } = {}) {
    // Einen noch offenen Dialog zuerst als abgebrochen auflösen, damit kein
    // Promise hängen bleibt.
    if (resolver) {
      settleConfirm(false)
    }
    state.title = title
    state.message = message
    state.confirmText = confirmText
    state.cancelText = cancelText
    state.color = color
    state.open = true
    return new Promise((resolve) => {
      resolver = resolve
    })
  }
}
