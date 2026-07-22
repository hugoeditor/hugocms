// Baut aus den Dialogwerten den einzufügenden Quelltext — Markdown oder HTML.
// Gemeinsam genutzt, damit die Maskierungsregeln an einer Stelle stehen.

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
