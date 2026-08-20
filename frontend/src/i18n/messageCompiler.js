// Eigener Nachrichten-Compiler für vue-i18n.
//
// Warum nicht der eingebaute: Der Compiler von vue-i18n erzeugt seine
// Übersetzungsfunktionen zur Laufzeit über `new Function(...)`. Eine
// Content-Security-Policy müsste dafür 'unsafe-eval' erlauben — für die einzige
// Stelle im ganzen Client, die das braucht. Mit diesem Compiler kommt die App
// ohne aus (siehe die CSP in der von scripts/packaging.sh erzeugten
// app/.htaccess); zugleich fällt der eingebaute Compiler aus dem Bündel, weil
// vite.config.js auf die Laufzeit-Fassung von vue-i18n verweist.
//
// Unterstützt wird genau das, was die Wörterbücher nutzen:
//   Text                     unverändert
//   {0}, {1}, …              Platzhalter aus der Parameterliste
//   {name}                   benannter Platzhalter
//   {'@'}                    Zeichenkette wörtlich (nötig, weil @ in vue-i18n
//                            sonst eine Verweis-Syntax einleitet)
//
// NICHT unterstützt sind Pluralformen (`eins | viele`) und Verweise auf andere
// Schlüssel (`@:pfad.zum.schluessel`). Beide kommen in de.js/en.js nicht vor.
// Wer sie einführt, erweitert hier — sonst erscheint die Form wörtlich.

/** Zerlegt eine Nachricht in Text- und Platzhalter-Abschnitte. */
function parse(message) {
  const tokens = []
  let text = ''
  let i = 0
  while (i < message.length) {
    const char = message[i]
    if (char !== '{') {
      text += char
      i++
      continue
    }
    const end = message.indexOf('}', i + 1)
    if (end === -1) {
      // Keine schließende Klammer — den Rest wörtlich übernehmen.
      text += message.slice(i)
      break
    }
    const inner = message.slice(i + 1, end).trim()
    i = end + 1
    // {'…'} — der Inhalt gehört wörtlich in den Text.
    const literal = /^'(.*)'$/s.exec(inner)
    if (literal) {
      text += literal[1]
      continue
    }
    if (inner === '') {
      // {} ist kein Platzhalter — wörtlich lassen.
      text += '{}'
      continue
    }
    if (text !== '') {
      tokens.push({ type: 'text', value: text })
      text = ''
    }
    tokens.push(
      /^\d+$/.test(inner)
        ? { type: 'list', index: Number(inner) }
        : { type: 'named', key: inner },
    )
  }
  if (text !== '') tokens.push({ type: 'text', value: text })
  return tokens
}

// Einmal zerlegte Nachrichten wiederverwenden: vue-i18n ruft den Compiler bei
// jeder Übersetzung auf, die Zerlegung hängt aber nur an der Zeichenkette.
const cache = new Map()

/**
 * vue-i18n-Schnittstelle: nimmt die Nachricht und liefert die Funktion, die
 * daraus mit den Parametern des Aufrufs den Text baut.
 *
 * Der Aufbau der Rückgabe (normalize/interpolate über den Kontext) entspricht
 * dem, was der eingebaute Compiler erzeugt — dadurch funktionieren auch tm()
 * und rt() sowie die Komponenten-Interpolation unverändert.
 */
export function compileMessage(message) {
  // Bereits kompilierte Nachrichten (Funktionen) unverändert durchreichen.
  if (typeof message === 'function') return message

  const source = String(message)
  let tokens = cache.get(source)
  if (!tokens) {
    tokens = parse(source)
    cache.set(source, tokens)
  }

  return (ctx) =>
    ctx.normalize(
      tokens.map((token) => {
        if (token.type === 'text') return token.value
        const value = token.type === 'list' ? ctx.list(token.index) : ctx.named(token.key)
        return ctx.interpolate(value)
      }),
    )
}
