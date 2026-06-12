// i18n-Einrichtung (vue-i18n, Composition-Modus). Startsprache aus der
// gespeicherten Auswahl bzw. der Browsersprache; Standard Deutsch.
import { createI18n } from 'vue-i18n'
import de from './de'
import en from './en'

export const SUPPORTED_LOCALES = ['de', 'en']
const STORAGE_KEY = 'hugocms_lang'
const FALLBACK = 'de'

function detectLocale() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved && SUPPORTED_LOCALES.includes(saved)) return saved
  } catch {
    // localStorage nicht verfügbar (z. B. privater Modus) — ignorieren.
  }
  const nav = (navigator.language || FALLBACK).slice(0, 2).toLowerCase()
  return SUPPORTED_LOCALES.includes(nav) ? nav : FALLBACK
}

export const i18n = createI18n({
  legacy: false, // Composition-API
  globalInjection: true, // $t in Templates verfügbar
  locale: detectLocale(),
  fallbackLocale: FALLBACK,
  messages: { de, en },
})

document.documentElement.lang = i18n.global.locale.value

/** Wechselt die Sprache und merkt sich die Wahl. */
export function setLocale(locale) {
  if (!SUPPORTED_LOCALES.includes(locale)) return
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // ignorieren
  }
}
