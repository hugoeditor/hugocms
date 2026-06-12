// Übersetzt die strukturierten Meldungen des Backends ({code, key, params})
// in lesbaren Text. Der Lookup-Schlüssel ist key, sonst der eindeutige code.
// Parameter können einfache Werte sein oder selbst übersetzbar: ein Objekt
// {t: "i18n.schlüssel"} wird zuvor übersetzt (z. B. Feldnamen).

function resolveParams(t, params) {
  return (params || []).map((p) =>
    p && typeof p === 'object' && typeof p.t === 'string' ? t(p.t) : p,
  )
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
