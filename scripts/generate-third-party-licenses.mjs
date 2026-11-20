#!/usr/bin/env node
/**
 * Erzeugt THIRD_PARTY_LICENSES.md aus den installierten Frontend-Abhängigkeiten.
 *
 * Quelle: frontend/package-lock.json + frontend/node_modules — nur
 * Produktionsabhängigkeiten, denn nur sie landen im ausgelieferten Bündel.
 * Vite entfernt beim Minifizieren alle Lizenzkommentare aus dem Bündel; diese
 * Datei ersetzt sie und wird mit eingecheckt (packaging.sh kopiert sie ins
 * Auslieferungs-Repo).
 *
 * Das PHP-Backend hat bewusst keine Abhängigkeiten (kein Composer); Hugo wird
 * nicht mitgeliefert, sondern von bin/get-hugo.sh im Zielsystem geladen.
 *
 * Aufruf: node scripts/generate-third-party-licenses.mjs   (oder: npm run licenses)
 * Voraussetzung: npm ci in frontend/.
 */

import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const FRONTEND = join(ROOT, 'frontend');
const OUT = join(ROOT, 'THIRD_PARTY_LICENSES.md');

const warnings = [];
const warn = (msg) => { warnings.push(msg); console.warn('WARNUNG: ' + msg); };

/** Lizenzdatei eines Paketverzeichnisses lesen (LICENSE, LICENSE.md, COPYING …) */
function readLicenseFile(dir) {
    if (!existsSync(dir)) return null;
    const names = readdirSync(dir).filter((n) => /^(licen[sc]e|copying)/i.test(n));
    names.sort((a, b) => a.length - b.length); // "LICENSE" vor "LICENSE-MIT.txt"
    for (const n of names) {
        const p = join(dir, n);
        if (statSync(p).isFile()) return readFileSync(p, 'utf8').replace(/\r\n/g, '\n').trim();
    }
    return null;
}

function licenseString(pkg) {
    let lic = pkg.license;
    if (!lic && Array.isArray(pkg.licenses)) lic = pkg.licenses.map((l) => l.type || l).join(' OR ');
    if (lic && typeof lic === 'object') lic = lic.type;
    return lic || 'UNBEKANNT';
}

function authorString(pkg) {
    const a = pkg.author || pkg.authors;
    if (!a) return '';
    if (typeof a === 'string') return a;
    if (Array.isArray(a)) return a.map((x) => (typeof x === 'string' ? x : x.name)).filter(Boolean).join(', ');
    return a.name || '';
}

/** Alle Produktionspakete anhand des Lockfiles (v2/v3) sammeln */
function collectNpm(dir) {
    const lockPath = join(dir, 'package-lock.json');
    if (!existsSync(lockPath)) { warn('frontend/package-lock.json fehlt'); return []; }
    if (!existsSync(join(dir, 'node_modules'))) { warn('frontend/node_modules fehlt (npm ci ausführen)'); return []; }
    const lock = JSON.parse(readFileSync(lockPath, 'utf8'));
    if (!lock.packages) { warn(`Lockfile ohne "packages" (lockfileVersion ${lock.lockfileVersion})`); return []; }

    const seen = new Map();
    for (const [key, entry] of Object.entries(lock.packages)) {
        if (key === '' || entry.link || entry.dev) continue;
        const pkgDir = join(dir, key);
        const pkgJson = join(pkgDir, 'package.json');
        if (!existsSync(pkgJson)) {
            // Optionale Plattformpakete sind auf anderen Systemen nicht installiert
            if (!entry.optional) warn(`${key}: nicht installiert`);
            continue;
        }
        const pkg = JSON.parse(readFileSync(pkgJson, 'utf8'));
        const id = `${pkg.name}@${pkg.version}`;
        if (seen.has(id)) continue;
        seen.set(id, {
            name: pkg.name,
            version: pkg.version,
            license: licenseString(pkg),
            author: authorString(pkg),
            homepage: pkg.homepage || (typeof pkg.repository === 'string' ? pkg.repository : pkg.repository?.url) || '',
            text: readLicenseFile(pkgDir),
        });
    }
    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name) || a.version.localeCompare(b.version));
}

const packages = collectNpm(FRONTEND);
const counts = {};
for (const p of packages) counts[p.license] = (counts[p.license] || 0) + 1;

const today = new Date().toISOString().slice(0, 10);
const lines = [];
lines.push('# Lizenzen der Drittkomponenten');
lines.push('');
lines.push(`Erzeugt am ${today} mit \`node scripts/generate-third-party-licenses.mjs\`. Nicht von Hand bearbeiten.`);
lines.push('');
lines.push('HugoCMS steht unter der GNU Affero General Public License v3 oder später; die');
lines.push('Pro-Bestandteile unter der HugoCMS Pro-Lizenz (siehe `LICENSE` und `LICENSE-PRO`).');
lines.push('Diese Datei enthält die Lizenztexte und Urheberrechtshinweise der im Client-Bündel');
lines.push('mitgelieferten npm-Pakete. Eine kuratierte Übersicht mit Einordnung, einschließlich der');
lines.push('nicht mitgelieferten Komponenten (PHP-Erweiterungen, Hugo, externe Dienste), steht in');
lines.push('`docs/drittkomponenten.md`.');
lines.push('');
lines.push('## Zusammenfassung');
lines.push('');
lines.push('| Lizenz | Pakete |');
lines.push('|---|---|');
for (const [lic, n] of Object.entries(counts).sort((a, b) => b[1] - a[1])) lines.push(`| ${lic} | ${n} |`);
lines.push(`| **Gesamt** | **${packages.length}** |`);
lines.push('');
lines.push(`## Frontend (npm, Produktionsabhängigkeiten, ${packages.length})`);
lines.push('');
for (const p of packages) {
    lines.push(`### ${p.name} ${p.version}`);
    lines.push('');
    lines.push(`- Lizenz: ${p.license}`);
    if (p.author) lines.push(`- Autor: ${p.author}`);
    if (p.homepage) lines.push(`- Quelle: ${p.homepage}`);
    lines.push('');
    if (p.text) {
        lines.push('```');
        lines.push(p.text);
        lines.push('```');
    } else {
        lines.push('_Keine Lizenzdatei im Paket; es gilt die oben genannte Lizenz laut package.json._');
    }
    lines.push('');
}
if (warnings.length) {
    lines.push('## Hinweise beim Erzeugen');
    lines.push('');
    for (const w of warnings) lines.push(`- ${w}`);
    lines.push('');
}
writeFileSync(OUT, lines.join('\n'));
console.log(`${OUT}: ${packages.length} Pakete, ${Object.keys(counts).length} Lizenzarten`);
