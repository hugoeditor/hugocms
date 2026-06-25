// build-version.js — Ermittelt die Buildnummer für vite.config.js.
//
// Schema der Versionsnummer = fortlaufende Buildnummer. Sie beginnt bei 100 und
// wird AUSSCHLIESSLICH beim Release-Build hochgezählt (Umgebungsvariable
// HUGOCMS_RELEASE=1, gesetzt von scripts/packaging.sh). Gewöhnliche Entwicklungs-
// und Test-Builds (vite bzw. vite build ohne die Variable) lesen den Stand nur,
// ohne ihn zu verändern. Der Zähler liegt eingecheckt in build-number.json und
// wird vom Maintainer mit dem Release committet.
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const START = 100
const FILE = fileURLToPath(new URL('./build-number.json', import.meta.url))

// Liest den zuletzt vergebenen Stand aus build-number.json. Gibt null zurück,
// solange die Datei fehlt (vor dem ersten Release) oder unlesbar ist.
function readCurrent() {
  try {
    const data = JSON.parse(readFileSync(FILE, 'utf8'))
    return Number.isInteger(data.build) ? data.build : null
  } catch {
    return null
  }
}

// Liefert die Buildnummer für diesen Lauf. Bei einem Release-Build wird der
// Zähler erhöht (erstes Release = 100) und zurückgeschrieben; sonst wird der
// aktuelle Stand nur gelesen (vor dem ersten Release der Startwert 100).
export function resolveBuildNumber(isRelease) {
  const current = readCurrent()
  if (!isRelease) {
    return current ?? START
  }
  const next = current === null ? START : current + 1
  writeFileSync(FILE, JSON.stringify({ build: next }, null, 2) + '\n')
  return next
}
