// Liest die veröffentlichungsrelevanten Front-Matter-Felder einer Hugo-
// Content-Datei — draft, publishDate, expiryDate — ohne vollen Parser. Das
// Format wird an den Begrenzern erkannt (--- YAML, +++ TOML, { } JSON) und nur
// der führende Block ausgewertet, damit gleichnamige Wörter im Rumpf nicht
// fälschlich greifen. Genügt für die Statusanzeige im Editor (Anzeige, nicht
// Persistenz — geschrieben wird serverseitig über FrontMatter.php).
//
// Rückgabe: { draft: boolean|null, publishDate: string|null, expiryDate: string|null }

function extractBlock(text) {
  const raw = String(text ?? '').replace(/^﻿/, '')
  // YAML (---) oder TOML (+++).
  const m = raw.match(/^(---|\+\+\+)\r?\n([\s\S]*?)\r?\n\1[ \t]*(?:\r?\n|$)/)
  if (m) return { format: m[1] === '---' ? 'yaml' : 'toml', inner: m[2] }
  // JSON: führendes Objekt (klammer-balanciert, Strings berücksichtigt).
  const json = extractJson(raw)
  if (json) return { format: 'json', inner: json }
  return null
}

function extractJson(raw) {
  let i = 0
  while (i < raw.length && /\s/.test(raw[i])) i++
  if (raw[i] !== '{') return null
  let depth = 0
  let inStr = false
  let esc = false
  for (let j = i; j < raw.length; j++) {
    const ch = raw[j]
    if (inStr) {
      if (esc) esc = false
      else if (ch === '\\') esc = true
      else if (ch === '"') inStr = false
      continue
    }
    if (ch === '"') inStr = true
    else if (ch === '{') depth++
    else if (ch === '}') {
      depth--
      if (depth === 0) return raw.slice(i, j + 1)
    }
  }
  return null
}

// Liest einen Skalar-Schlüssel aus einem YAML/TOML-Block (nur Top-Level-Zeilen).
function readKey(inner, key) {
  const re = new RegExp(`^["']?${key}["']?[ \\t]*[:=][ \\t]*(.+?)[ \\t]*$`, 'm')
  const m = inner.match(re)
  if (!m) return null
  // Umschließende Anführungszeichen entfernen.
  return m[1].replace(/^["']|["']$/g, '')
}

export function frontMatterMeta(text) {
  const block = extractBlock(text)
  const out = { draft: null, publishDate: null, expiryDate: null }
  if (!block) return out

  if (block.format === 'json') {
    try {
      const data = JSON.parse(block.inner)
      if (typeof data.draft === 'boolean') out.draft = data.draft
      if (typeof data.publishDate === 'string') out.publishDate = data.publishDate
      if (typeof data.expiryDate === 'string') out.expiryDate = data.expiryDate
    } catch {
      // ungültiges JSON — kein Status
    }
    return out
  }

  const draftRaw = readKey(block.inner, 'draft')
  if (draftRaw === 'true') out.draft = true
  else if (draftRaw === 'false') out.draft = false
  out.publishDate = readKey(block.inner, 'publishDate')
  out.expiryDate = readKey(block.inner, 'expiryDate')
  return out
}
