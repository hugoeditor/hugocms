// Schlanker API-Client für den HugoCMS-Connector.
// Basis-Adresse ist konfigurierbar (VITE_API_BASE), Standard ist der
// API-Endpunkt /cms-api/. Im Dev-Betrieb leitet der Vite-Proxy /cms-api an den
// PHP-Connector weiter; in Produktion zeigt /cms-api/ auf die Connector-index.php.

// Auf ein Verzeichnis zeigende Endpunkte MÜSSEN mit / enden. Sonst leitet
// der Webserver (z. B. Apache) /api per 301 auf /api/ um — und beim Folgen
// eines 301 verwirft der Browser den POST-Body, sodass beim Login der Befehl
// verloren geht. Ein fehlender Slash wird hier ergänzt (außer bei *.php).
function normalizeBase(value) {
  const base = value || '/cms-api/'
  if (base.endsWith('/') || base.endsWith('.php')) return base
  return base + '/'
}

const BASE = normalizeBase(import.meta.env.VITE_API_BASE)

// CSRF-Token: kommt aus whoami (auth-Store ruft setCsrfToken) und wird bei
// jedem Schreibbefehl als Header mitgesendet.
let csrfToken = null

export function setCsrfToken(token) {
  csrfToken = typeof token === 'string' && token !== '' ? token : null
}

function csrfHeaders() {
  return csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
}

// Fehler trägt die Übersetzungsdaten des Backends: code (Fehlerklasse),
// optionaler key (genauerer Meldungsschlüssel) und params. Der lesbare Text
// entsteht erst im Frontend über die i18n-Wörterbücher (siehe i18n/apiMessage).
export class ApiError extends Error {
  constructor({ code = 'EUNKNOWN', key = null, params = [] } = {}) {
    super(key || code)
    this.code = code
    this.key = key
    this.params = params ?? []
  }
}

async function handle(response) {
  let payload
  try {
    payload = await response.json()
  } catch {
    throw new ApiError({ code: 'ENETWORK', params: [String(response.status)] })
  }
  if (!payload.ok) {
    throw new ApiError(payload.error ?? { code: 'EUNKNOWN' })
  }
  return payload.data
}

export const api = {
  // Lesebefehle als GET.
  async get(cmd, params = {}) {
    const query = new URLSearchParams({ cmd, ...params }).toString()
    const res = await fetch(`${BASE}?${query}`, {
      method: 'GET',
      credentials: 'include',
    })
    return handle(res)
  },

  // Schreibbefehle als POST mit JSON-Körper.
  async post(cmd, body = {}) {
    const res = await fetch(BASE, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', ...csrfHeaders() },
      body: JSON.stringify({ cmd, ...body }),
    })
    return handle(res)
  },

  // Datei-Upload als multipart/form-data (FormData trägt cmd + target + files[]).
  async postForm(form) {
    const res = await fetch(BASE, {
      method: 'POST',
      credentials: 'include',
      headers: csrfHeaders(),
      body: form,
    })
    return handle(res)
  },

  // Direkte URL eines GET-Befehls — für <img src> (thumb/raw) und Downloads.
  url(cmd, params = {}) {
    const query = new URLSearchParams({ cmd, ...params }).toString()
    return `${BASE}?${query}`
  },
}
