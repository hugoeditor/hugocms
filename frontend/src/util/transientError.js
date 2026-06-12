import { ref, watch, onBeforeUnmount } from 'vue'

/**
 * Fehler-Ref mit Zeitbegrenzung: Jede gesetzte Meldung verschwindet nach
 * `timeout` Millisekunden von selbst (ein neuer Fehler startet die Frist
 * neu). Die Verwendung bleibt wie bei einem normalen ref:
 *
 *   const error = useTransientError()
 *   error.value = '…'   // wird nach Ablauf automatisch wieder null
 */
export function useTransientError(timeout = 8000) {
  const error = ref(null)
  let timer = null

  watch(error, (value) => {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
    if (value) {
      timer = setTimeout(() => {
        error.value = null
      }, timeout)
    }
  })

  onBeforeUnmount(() => {
    if (timer) clearTimeout(timer)
  })

  return error
}
