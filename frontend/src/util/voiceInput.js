import { onBeforeUnmount, ref } from 'vue'

// Kapselt die Sprachaufnahme des Browsers per MediaRecorder.
//
//   const { supported, recording, start, stop, cancel } = useVoiceInput()
//
// `supported` ist ein einfacher Boolean (Feature-Detection): MediaRecorder und
// getUserMedia müssen vorhanden sein, und der Kontext muss sicher sein (HTTPS
// bzw. localhost) — sonst verweigert der Browser den Mikrofonzugriff.
//
// `start()` fragt die Mikrofon-Erlaubnis an und beginnt die Aufnahme (kann
// werfen, wenn der Nutzer ablehnt). `stop()` beendet sie und liefert das
// aufgenommene Audio als Blob (webm/opus je nach Browser). `cancel()` verwirft
// eine laufende Aufnahme ohne Ergebnis.
export function useVoiceInput() {
  const supported =
    typeof navigator !== 'undefined' &&
    !!navigator.mediaDevices?.getUserMedia &&
    typeof window !== 'undefined' &&
    typeof window.MediaRecorder !== 'undefined' &&
    (window.isSecureContext ?? true)

  const recording = ref(false)
  let recorder = null
  let stream = null
  let chunks = []

  function cleanup() {
    stream?.getTracks().forEach((track) => track.stop())
    stream = null
    recorder = null
    chunks = []
  }

  async function start() {
    if (!supported || recording.value) return
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    chunks = []
    recorder = new MediaRecorder(stream)
    recorder.addEventListener('dataavailable', (e) => {
      if (e.data && e.data.size) chunks.push(e.data)
    })
    recorder.start()
    recording.value = true
  }

  // Beendet die Aufnahme und liefert den Blob (oder null, wenn nicht aktiv).
  function stop() {
    return new Promise((resolve) => {
      if (!recording.value || !recorder) {
        resolve(null)
        return
      }
      const type = recorder.mimeType || 'audio/webm'
      recorder.addEventListener(
        'stop',
        () => {
          const blob = chunks.length ? new Blob(chunks, { type }) : null
          cleanup()
          resolve(blob)
        },
        { once: true },
      )
      recording.value = false
      recorder.stop()
    })
  }

  function cancel() {
    if (recorder && recording.value) {
      recording.value = false
      try {
        recorder.stop()
      } catch {
        // bereits gestoppt — ignorieren
      }
    }
    cleanup()
  }

  // Aufräumen, falls die Komponente während einer Aufnahme verschwindet.
  onBeforeUnmount(cancel)

  return { supported, recording, start, stop, cancel }
}
