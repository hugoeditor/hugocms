// hugoFieldMeta.js — Schema des visuellen Hugo-Konfigurations-Editors.
//
// Reine Datenstruktur (keine UI-Logik), bewusst zum Anpassen gedacht: Sektionen
// hinzufügen/umordnen, Felder ergänzen. Die UI liest dieses Schema generisch.
//
// Zwei Arten bekannter Wurzelschlüssel:
//   • HUGO_SECTIONS    — SKALARE Schlüssel, gruppiert als typisierte Felder.
//   • HUGO_STRUCTURED  — STRUKTURIERTE Standard-Blöcke (markup, redirects …),
//                        je eine benannte Sektion mit dem rekursiven Baum darin.
//
// Alles, was hier nicht steht, geht nicht verloren: freie params landen in der
// Parameter-Sektion, unbekannte Blöcke im Baum unter „Weitere Einstellungen“,
// und über den Quelltext-Umschalter ist ohnehin das gesamte JSON erreichbar.

// Beschriftungen liegen in der i18n unter settings.fields.<i18nKey>.label;
// fehlt ein Eintrag, greift camelToLabel().
export const HUGO_SECTIONS = {
  siteBasics: {
    i18nKey: 'siteBasics',
    order: 1,
    fields: {
      title: { i18nKey: 'title', order: 1 },
      baseURL: { i18nKey: 'baseURL', order: 2 },
      theme: { i18nKey: 'theme', order: 3 },
      languageCode: { i18nKey: 'languageCode', order: 4 },
      defaultContentLanguage: { i18nKey: 'defaultContentLanguage', order: 5 },
      copyright: { i18nKey: 'copyright', order: 6 },
    },
  },
  directories: {
    i18nKey: 'directories',
    order: 2,
    fields: {
      contentDir: { i18nKey: 'contentDir', order: 1 },
      layoutDir: { i18nKey: 'layoutDir', order: 2 },
      dataDir: { i18nKey: 'dataDir', order: 3 },
      staticDir: { i18nKey: 'staticDir', order: 4 },
      publishDir: { i18nKey: 'publishDir', order: 5 },
      archetypeDir: { i18nKey: 'archetypeDir', order: 6 },
      resourceDir: { i18nKey: 'resourceDir', order: 7 },
      themesDir: { i18nKey: 'themesDir', order: 8 },
    },
  },
  options: {
    i18nKey: 'options',
    order: 3,
    fields: {
      enableRobotsTXT: { i18nKey: 'enableRobotsTXT', order: 1 },
      enableGitInfo: { i18nKey: 'enableGitInfo', order: 2 },
      enableEmoji: { i18nKey: 'enableEmoji', order: 3 },
      hasCJKLanguage: { i18nKey: 'hasCJKLanguage', order: 4 },
      summaryLength: { i18nKey: 'summaryLength', order: 5 },
      timeZone: { i18nKey: 'timeZone', order: 6 },
      paginate: { i18nKey: 'paginate', order: 7 },
      paginatePath: { i18nKey: 'paginatePath', order: 8 },
      rssLimit: { i18nKey: 'rssLimit', order: 9 },
      disableKinds: { i18nKey: 'disableKinds', order: 10 },
      capitalizeListTitles: { i18nKey: 'capitalizeListTitles', order: 11 },
      pluralizeListTitles: { i18nKey: 'pluralizeListTitles', order: 12 },
      removePathAccents: { i18nKey: 'removePathAccents', order: 13 },
      enableInlineShortcodes: { i18nKey: 'enableInlineShortcodes', order: 14 },
      ignoreFiles: { i18nKey: 'ignoreFiles', order: 15 },
    },
  },
  build: {
    i18nKey: 'build',
    order: 4,
    fields: {
      buildDrafts: { i18nKey: 'buildDrafts', order: 1 },
      buildFuture: { i18nKey: 'buildFuture', order: 2 },
      buildExpired: { i18nKey: 'buildExpired', order: 3 },
      canonifyURLs: { i18nKey: 'canonifyURLs', order: 4 },
      relativeURLs: { i18nKey: 'relativeURLs', order: 5 },
      uglyURLs: { i18nKey: 'uglyURLs', order: 6 },
      disablePathToLower: { i18nKey: 'disablePathToLower', order: 7 },
      disableHugoGeneratorInject: { i18nKey: 'disableHugoGeneratorInject', order: 8 },
      enableMissingTranslationPlaceholders: { i18nKey: 'enableMissingTranslationPlaceholders', order: 9 },
    },
  },
}

// Strukturierte Standard-Blöcke: je eine benannte Sektion, Inhalt als Baum.
// Titel in der i18n unter settings.structured.<i18nKey>.
export const HUGO_STRUCTURED = {
  markup: { i18nKey: 'markup', order: 1 },
  outputs: { i18nKey: 'outputs', order: 2 },
  outputFormats: { i18nKey: 'outputFormats', order: 3 },
  mediaTypes: { i18nKey: 'mediaTypes', order: 4 },
  taxonomies: { i18nKey: 'taxonomies', order: 5 },
  permalinks: { i18nKey: 'permalinks', order: 6 },
  redirects: { i18nKey: 'redirects', order: 7 },
  sitemap: { i18nKey: 'sitemap', order: 8 },
  security: { i18nKey: 'security', order: 9 },
  minify: { i18nKey: 'minify', order: 10 },
  build: { i18nKey: 'buildConfig', order: 11 },
  module: { i18nKey: 'module', order: 12 },
  related: { i18nKey: 'related', order: 13 },
  imaging: { i18nKey: 'imaging', order: 14 },
  privacy: { i18nKey: 'privacy', order: 15 },
  services: { i18nKey: 'services', order: 16 },
  frontmatter: { i18nKey: 'frontmatter', order: 17 },
  languages: { i18nKey: 'languages', order: 18 },
  caches: { i18nKey: 'caches', order: 19 },
}

// Sonderbehandelte Wurzelschlüssel (nicht über die Sektionen oben).
export const PARAMS_KEY = 'params'
export const MENU_KEYS = ['menu', 'menus']

// Alle bekannten skalaren Wurzelschlüssel — zum Erkennen „unbekannter“ Blöcke.
export const KNOWN_SCALAR_KEYS = new Set(
  Object.values(HUGO_SECTIONS).flatMap((s) => Object.keys(s.fields)),
)
// Alle bekannten strukturierten Wurzelschlüssel.
export const STRUCTURED_KEYS = new Set(Object.keys(HUGO_STRUCTURED))

// Sektionen nach order sortiert (für die Anzeige).
export function sortedSections() {
  return Object.entries(HUGO_SECTIONS).sort(([, a], [, b]) => a.order - b.order)
}
// Strukturierte Standard-Blöcke nach order sortiert.
export function sortedStructured() {
  return Object.entries(HUGO_STRUCTURED).sort(([, a], [, b]) => a.order - b.order)
}
// Felder einer Sektion nach order sortiert.
export function sortedFields(section) {
  return Object.entries(section.fields).sort(([, a], [, b]) => a.order - b.order)
}

// camelCase/Pfad in eine lesbare Beschriftung wandeln (Rückfall ohne i18n).
export function camelToLabel(key) {
  return key
    .replace(/([A-Z])/g, ' $1')
    .replace(/^./, (c) => c.toUpperCase())
    .trim()
}

// --- Pfad-Helfer (Punkt-Notation) ------------------------------------------
export function getByPath(obj, path) {
  return path.split('.').reduce((cur, key) => {
    if (cur && typeof cur === 'object' && key in cur) return cur[key]
    return undefined
  }, obj)
}

export function setByPath(obj, path, value) {
  const keys = path.split('.')
  const last = keys.pop()
  let cur = obj
  for (const key of keys) {
    if (!(key in cur) || typeof cur[key] !== 'object' || cur[key] === null) cur[key] = {}
    cur = cur[key]
  }
  cur[last] = value
}

export function deleteByPath(obj, path) {
  const keys = path.split('.')
  const last = keys.pop()
  let cur = obj
  const parents = []
  for (const key of keys) {
    if (!(key in cur) || typeof cur[key] !== 'object') return
    parents.push({ obj: cur, key })
    cur = cur[key]
  }
  delete cur[last]
  // Leer gewordene Elternobjekte aufräumen.
  for (let i = parents.length - 1; i >= 0; i--) {
    const p = parents[i]
    if (Object.keys(p.obj[p.key]).length === 0) delete p.obj[p.key]
    else break
  }
}

// Ist der Wert „komplex“ (Objekt oder Array, das Objekte/Arrays enthält)? Dann
// rendert die UI ihn als Baum, sonst als einfaches typisiertes Feld.
export function isComplexValue(value) {
  if (Array.isArray(value)) return value.some((e) => e !== null && typeof e === 'object')
  return value !== null && typeof value === 'object'
}
