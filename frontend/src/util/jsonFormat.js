// jsonFormat.js — Hilfsfunktionen für den visuellen JSON-Editor (Phase 1).
//
// Der Quelltext bleibt im EditorPanel die Quelle der Wahrheit. Diese Funktionen
// wandeln zwischen Text, JS-Wert und einem bearbeitbaren Knotenbaum hin und her
// und kümmern sich um die „Formatierung beibehalten“-Vorgaben: dominante
// Einrückung und abschließender Zeilenumbruch werden erkannt und beim
// Zurückschreiben verwendet, die Schlüsselreihenfolge bleibt erhalten (sie
// folgt einfach der Reihenfolge der Knoten). Eine byte-genaue Erhaltung
// unregelmäßiger Einrückung ist bei einem strukturierten Editor nicht möglich;
// erst eine echte Bearbeitung schreibt überhaupt neu.

export const NODE_TYPES = ['object', 'array', 'string', 'number', 'boolean', 'null']

let _uid = 0
const uid = () => `jn${++_uid}`

// Typ eines JS-Werts auf unser Knotenmodell abbilden.
function classify(value) {
  if (value === null) return 'null'
  if (Array.isArray(value)) return 'array'
  switch (typeof value) {
    case 'object':
      return 'object'
    case 'number':
      return Number.isFinite(value) ? 'number' : 'string'
    case 'boolean':
      return 'boolean'
    default:
      return 'string'
  }
}

// Einen JS-Wert in einen Knoten umwandeln. key ist der Objektschlüssel des
// Knotens (null bei Array-Elementen und der Wurzel).
//   Knoten = { id, key, type, value, children }
function toNode(value, key) {
  const type = classify(value)
  const node = { id: uid(), key, type, value: undefined, children: [] }
  if (type === 'object') {
    node.children = Object.entries(value).map(([k, v]) => toNode(v, k))
  } else if (type === 'array') {
    node.children = value.map((v) => toNode(v, null))
  } else if (type === 'null') {
    node.value = null
  } else {
    node.value = value
  }
  return node
}

// Einen Knoten zurück in einen JS-Wert wandeln (für die Serialisierung).
export function nodeToValue(node) {
  switch (node.type) {
    case 'object': {
      const out = {}
      for (const child of node.children) out[child.key ?? ''] = nodeToValue(child)
      return out
    }
    case 'array':
      return node.children.map(nodeToValue)
    case 'null':
      return null
    case 'number': {
      const n = typeof node.value === 'number' ? node.value : Number(node.value)
      return Number.isFinite(n) ? n : 0
    }
    case 'boolean':
      return !!node.value
    default:
      return node.value == null ? '' : String(node.value)
  }
}

// Rohen JSON-Text parsen und als Wurzelknoten zurückgeben. Wirft bei
// ungültigem JSON (der Aufrufer hält dann den Quelltext-Modus offen).
export function parseToNode(text) {
  return toNode(JSON.parse(text), null)
}

// Einen bereits geparsten JS-Wert als Knoten aufbauen (für eingebettete
// Teilbäume im Hugo-Editor: ein einzelnes Objekt/Array statt einer ganzen Datei).
export function valueToNode(value, key = null) {
  return toNode(value, key)
}

// Leeren Knoten eines bestimmten Typs erzeugen (für „Hinzufügen“ und Typwechsel).
export function emptyNode(type, key = null) {
  const node = { id: uid(), key, type, value: undefined, children: [] }
  if (type === 'string') node.value = ''
  else if (type === 'number') node.value = 0
  else if (type === 'boolean') node.value = false
  else if (type === 'null') node.value = null
  return node
}

// Den Typ eines bestehenden Knotens wechseln; vorhandene Werte werden so weit
// wie sinnvoll übernommen. Mutiert den Knoten (er bleibt reaktiv).
export function coerceNodeType(node, newType) {
  if (node.type === newType) return
  const prev = node.value
  node.type = newType
  if (newType === 'object' || newType === 'array') {
    if (!Array.isArray(node.children)) node.children = []
    node.value = undefined
    if (newType === 'array') {
      node.children.forEach((c) => { c.key = null })
    } else {
      node.children.forEach((c, i) => { if (c.key == null) c.key = `feld${i + 1}` })
    }
  } else {
    node.children = []
    if (newType === 'string') node.value = prev == null ? '' : String(prev)
    else if (newType === 'number') { const n = Number(prev); node.value = Number.isFinite(n) ? n : 0 }
    else if (newType === 'boolean') node.value = !!prev
    else if (newType === 'null') node.value = null
  }
}

// Eindeutigen neuen Objektschlüssel auf Basis von base bilden (base, base2, …).
export function uniqueKey(children, base = 'neu') {
  const used = new Set(children.map((c) => c.key))
  if (!used.has(base)) return base
  let i = 2
  while (used.has(`${base}${i}`)) i += 1
  return `${base}${i}`
}

// Dominante Einrückung des Quelltextes ermitteln: Tabulator oder Anzahl
// Leerzeichen der ersten eingerückten Inhaltszeile. Rückgabe passend für
// JSON.stringify (Zahl oder "\t"); Vorgabe 2 Leerzeichen.
export function detectIndent(text) {
  const m = text.match(/\n([ \t]+)\S/)
  if (!m) return 2
  const ws = m[1]
  if (ws.includes('\t')) return '\t'
  return ws.length || 2
}

// JS-Wert mit der erkannten Einrückung serialisieren; abschließenden
// Zeilenumbruch bei Bedarf wieder anhängen.
export function serializeJson(value, indent, trailingNewline) {
  let text = JSON.stringify(value, null, indent)
  if (trailingNewline) text += '\n'
  return text
}
