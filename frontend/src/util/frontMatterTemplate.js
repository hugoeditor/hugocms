// Hugo-Front-Matter für neue bzw. bestehende Content-Markdown-Dateien.
// Einzige Stelle für die Template-Logik — Anlegen (files-Store), Öffnen-Dialog
// (EditorPanel) und die Ergänzung greifen alle hierauf zu.

// Regex für einen YAML-Front-Matter-Block (--- … ---) am Dateianfang.
const FM_RE = /^---\r?\n([\s\S]*?)\r?\n---(\r?\n|$)/
// TOML (+++) oder JSON ({ … }) Front Matter — das fassen wir nicht an.
const NON_YAML_RE = /^﻿?\s*(\+\+\+|\{)/

function pad(n) {
  return String(n).padStart(2, '0')
}

// Aktuelle (oder übergebene) Zeit als lokale ISO-8601-Angabe mit Zonenversatz,
// z. B. 2026-07-09T14:30:00+02:00 — genau das Format, das Hugo erwartet.
export function localIso(date = new Date()) {
  const offset = -date.getTimezoneOffset()
  const sign = offset >= 0 ? '+' : '-'
  const abs = Math.abs(offset)
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}` +
    `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
  )
}

// Dateiname -> lesbarer Titel: Endung ab, Trenner zu Leerzeichen, erster
// Buchstabe groß. "mein-erster-beitrag.md" -> "Mein erster Beitrag".
export function titleFromFilename(name) {
  const base = String(name ?? '')
    .replace(/\.[^.]+$/, '')
    .replace(/[-_]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  return base === '' ? '' : base.charAt(0).toUpperCase() + base.slice(1)
}

// Empfohlene Felder in kanonischer Reihenfolge, jeweils mit Typ (für die
// Dialog-Eingabe) und Vorschlagswert. type: text | multiline | date | boolean.
export function defaultFields(name, when = new Date()) {
  const iso = localIso(when)
  return [
    { key: 'title', type: 'text', value: titleFromFilename(name) },
    { key: 'date', type: 'date', value: iso },
    { key: 'lastmod', type: 'date', value: iso },
    { key: 'draft', type: 'boolean', value: false },
    { key: 'description', type: 'multiline', value: '' },
  ]
}

// Ein Feld als YAML-Zeile: Wahrheitswert und Datum unquotiert, Text quotiert.
function fieldLine({ key, type, value }) {
  if (type === 'boolean') return `${key}: ${value ? 'true' : 'false'}`
  if (type === 'date') return `${key}: ${value}`
  return `${key}: ${JSON.stringify(String(value ?? ''))}`
}

// Top-Level-Schlüssel eines YAML-Blocks (Kleinschreibung).
function presentKeys(inner) {
  return new Set(
    inner
      .split(/\r?\n/)
      .map((line) => line.match(/^([A-Za-z0-9_-]+)\s*:/)?.[1]?.toLowerCase())
      .filter(Boolean),
  )
}

// Ermittelt, welche empfohlenen Felder im Content noch fehlen.
// Rückgabe: { skip, hasBlock, fields }.
//  - skip=true  -> TOML/JSON-Front-Matter, nicht anfassen.
//  - hasBlock    -> es gibt bereits einen YAML-Block.
//  - fields      -> fehlende Felder (mit Vorschlagswerten).
export function missingFrontMatterFields(content, name, when = new Date()) {
  const text = content ?? ''
  const all = defaultFields(name, when)
  const yaml = text.match(FM_RE)
  if (!yaml) {
    if (NON_YAML_RE.test(text)) return { skip: true, hasBlock: false, fields: [] }
    return { skip: false, hasBlock: false, fields: all }
  }
  const present = presentKeys(yaml[1])
  return { skip: false, hasBlock: true, fields: all.filter((f) => !present.has(f.key)) }
}

// Fügt die übergebenen Felder ins Front Matter ein: in einen vorhandenen
// YAML-Block vor die schließende ----Zeile, sonst als neuen Block voranstellen.
// TOML/JSON bleibt unberührt. Gibt den neuen Gesamtinhalt zurück.
export function applyFrontMatterFields(content, name, fields) {
  const text = content ?? ''
  if (!fields || fields.length === 0) return text
  const lines = fields.map(fieldLine).join('\n')

  const yaml = text.match(FM_RE)
  if (yaml) {
    const inner = yaml[1].length === 0 ? lines : `${yaml[1]}\n${lines}`
    return `---\n${inner}\n---${yaml[2] || '\n'}` + text.slice(yaml[0].length)
  }
  if (NON_YAML_RE.test(text)) return text
  const block = `---\n${lines}\n---\n`
  const body = text.length === 0 ? '' : text.startsWith('\n') ? text : `\n${text}`
  return block + body
}

// --- lastmod aktualisieren -------------------------------------------------
// Front-Matter-Block (nur die verbreiteten YAML- und TOML-Formen) mit
// Erfassungsgruppen: [0]=ganz, [1]=öffnender Delimiter+NL, [2]=Innentext,
// [3]=schließender Delimiter.
const YAML_BLOCK = /^(---\r?\n)([\s\S]*?)(\r?\n---(?:\r?\n|$))/
const TOML_BLOCK = /^(\+\+\+\r?\n)([\s\S]*?)(\r?\n\+\+\+(?:\r?\n|$))/

function blockMatch(text) {
  return text.match(YAML_BLOCK) || text.match(TOML_BLOCK)
}

// Hat der Content ein Top-Level-Feld `lastmod` im Front Matter?
export function hasLastmod(content) {
  const m = blockMatch(content ?? '')
  return m ? /^lastmod\s*[:=]/m.test(m[2]) : false
}

// Setzt ein vorhandenes `lastmod`-Feld auf die aktuelle Zeit (lokale ISO-8601).
// Fehlt das Feld, bleibt der Content unverändert. Rückgabe: { content, changed }.
export function withUpdatedLastmod(content, when = new Date()) {
  const text = content ?? ''
  const m = blockMatch(text)
  if (!m) return { content: text, changed: false }
  const line = /^(lastmod\s*[:=]\s*).*$/m
  if (!line.test(m[2])) return { content: text, changed: false }
  const newInner = m[2].replace(line, `$1${localIso(when)}`)
  if (newInner === m[2]) return { content: text, changed: false }
  return { content: m[1] + newInner + m[3] + text.slice(m[0].length), changed: true }
}

// Bequemlichkeit fürs Anlegen: fehlende Felder mit Vorschlagswerten ergänzen.
// Rückgabe: { content, changed }.
export function ensureFrontMatter(content, name, when = new Date()) {
  const { skip, fields } = missingFrontMatterFields(content, name, when)
  if (skip || fields.length === 0) return { content: content ?? '', changed: false }
  return { content: applyFrontMatterFields(content ?? '', name, fields), changed: true }
}
