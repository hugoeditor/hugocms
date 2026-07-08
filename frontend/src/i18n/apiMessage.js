// Übersetzt die strukturierten Meldungen des Backends ({code, key, params})
// in lesbaren Text. Der Lookup-Schlüssel ist key, sonst der eindeutige code.
// Parameter können einfache Werte sein oder selbst aufbereitet werden:
//   {t: "i18n.schlüssel"} — wird zuvor übersetzt (z. B. Feldnamen).
//   {d: "2026-08-01"}     — ISO-Datum, sprachabhängig ausgeschrieben.
import { i18n } from './index'

// Formatiert ein ISO-Datum (YYYY-MM-DD) ausgeschrieben in der aktiven Sprache;
// in UTC verankert, damit sich der Kalendertag nicht durch die Zeitzone
// verschiebt. Bei ungültigem Wert bleibt der Rohtext erhalten.
function formatDate(iso) {
  const date = new Date(`${iso}T00:00:00Z`)
  if (Number.isNaN(date.getTime())) return iso
  return new Intl.DateTimeFormat(i18n.global.locale.value, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(date)
}

function resolveParams(t, params) {
  return (params || []).map((p) => {
    if (p && typeof p === 'object') {
      if (typeof p.t === 'string') return t(p.t)
      if (typeof p.d === 'string') return formatDate(p.d)
    }
    return p
  })
}

/**
 * @param {(key: string, list?: unknown[]) => string} t  useI18n().t
 * @param {{code?: string, key?: string, params?: unknown[]}} error
 */
export function errorText(t, error) {
  if (!error) return t('errors.EUNKNOWN')
  const key = error.key || error.code || 'EUNKNOWN'
  return t(`errors.${key}`, resolveParams(t, error.params))
}

/**
 * @param {(key: string, list?: unknown[]) => string} t
 * @param {{key: string, params?: unknown[]}} warning
 */
export function warningText(t, warning) {
  if (!warning?.key) return ''
  return t(`warnings.${warning.key}`, resolveParams(t, warning.params))
}
