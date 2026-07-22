// Hilfsfunktionen rund um den Link-Dialog: der einzufügende Quelltext
// (Markdown oder HTML) sowie die Vorschläge, mit denen der Dialog beim
// externen Link vorbelegt wird. Gemeinsam genutzt, damit die Maskierungs- und
// Erkennungsregeln an einer Stelle stehen.

// Entfernt ein vorangestelltes Schema (https:// o. Ä.).
export function withoutScheme(value) {
  return String(value).trim().replace(/^[a-z][a-z0-9+.-]*:\/\//i, '')
}

// Macht aus beliebigem markiertem Text den Anfang einer Adresse: übernommen
// wird der erste Abschnitt aus Zeichen, die in einem Rechnernamen vorkommen
// dürfen — aus „Hallo Welt" wird also „hallo". War der Rechnername
// vollständig gültig, bleibt ein angehängter Pfad erhalten
// („example.org/blog/x").
export function domainFromText(value) {
  const cleaned = withoutScheme(value)
  const cut = cleaned.search(/[/?#]/)
  const hostPart = (cut === -1 ? cleaned : cleaned.slice(0, cut)).toLowerCase()
  const rest = cut === -1 ? '' : cleaned.slice(cut).split(/\s/)[0]
  const host = (hostPart.match(/^[a-z0-9.-]*/) ?? [''])[0].replace(/[.-]+$/, '')
  return host === hostPart ? host + rest : host
}

// Schlägt aus der Adresse einen Kurztitel vor: das letzte Pfadstück in
// lesbarer Form, ergänzt um den Rechnernamen — bei einer reinen Domain nur
// diesen. Mehr ist im Browser nicht möglich, die Zielseite wird nicht
// abgerufen; der Vorschlag ist ausdrücklich zum Überschreiben gedacht.
export function suggestLinkTitle(href) {
  const cleaned = withoutScheme(href)
  if (cleaned === '') return ''
  const [hostPart, ...rest] = cleaned.split('/')
  const host = hostPart.replace(/^www\./i, '').replace(/:\d+$/, '')
  const path = rest.join('/').split(/[?#]/)[0]
  const last = path.split('/').filter(Boolean).pop() ?? ''
  let label = last.replace(/\.(html?|php|aspx?)$/i, '')
  try {
    label = decodeURIComponent(label)
  } catch {
    // Unvollständige Prozentkodierung — dann eben unverändert.
  }
  label = label.replace(/[-_+]+/g, ' ').trim()
  if (label === '') return host
  const nice = label.charAt(0).toUpperCase() + label.slice(1)
  return host === '' ? nice : `${nice} – ${host}`
}

function escapeAttribute(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function escapeHtmlText(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

// <a href="…" title="…">Text</a>
export function htmlLink({ href, text, title }) {
  const label = text === '' ? href : text
  const titleAttr = title === '' ? '' : ` title="${escapeAttribute(title)}"`
  return `<a href="${escapeAttribute(href)}"${titleAttr}>${escapeHtmlText(label)}</a>`
}

// [Text](Adresse "Titel") — Adressen mit Leerzeichen oder Klammern gehören in
// spitze Klammern, sonst bricht die Markdown-Syntax.
export function markdownLink({ href, text, title }) {
  const label = (text === '' ? href : text).replace(/([[\]])/g, '\\$1')
  const target = /[\s()<>]/.test(href) ? `<${href.replace(/([<>])/g, '\\$1')}>` : href
  const titlePart = title === '' ? '' : ` "${title.replace(/"/g, '\\"')}"`
  return `[${label}](${target}${titlePart})`
}
