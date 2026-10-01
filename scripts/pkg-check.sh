#!/bin/bash
# pkg-check.sh - Show installed versions of an npm package and known
# security advisories for it (e.g. to triage a Dependabot alert).
#
# Usage: scripts/pkg-check.sh <package> [--prefix <dir>]
#   <package>        npm package name, e.g. browserslist or @babel/core
#   --prefix <dir>   npm project directory (default: frontend)
#
# Data sources:
#   npm ls     - installed versions and the dependency path pulling them in
#   npm view   - latest version published in the registry
#   npm audit  - advisories from the GitHub Advisory Database (same source
#                Dependabot uses), restricted to the given package

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

PKG=""
PREFIX="$PROJECT_DIR/frontend"

while [ $# -gt 0 ]; do
    case "$1" in
        --prefix)   PREFIX="$2"; shift 2 ;;
        --prefix=*) PREFIX="${1#*=}"; shift ;;
        -h|--help)  sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)         echo "Unknown option: $1" >&2; exit 2 ;;
        *)          PKG="$1"; shift ;;
    esac
done

if [ -z "$PKG" ]; then
    echo "Usage: $0 <package> [--prefix <dir>]" >&2
    exit 2
fi
if [ ! -f "$PREFIX/package.json" ]; then
    echo "No package.json in $PREFIX" >&2
    exit 2
fi

echo "== Installed versions of '$PKG' ($PREFIX)"
if ! npm ls --prefix "$PREFIX" "$PKG" --all 2>/dev/null; then
    echo "(not installed in this project)"
fi

echo
echo "== Latest version in registry"
npm view "$PKG" version 2>/dev/null || echo "(registry lookup failed)"

echo
echo "== Security advisories (npm audit)"
# npm audit exits non-zero when vulnerabilities exist; that is not an error here
AUDIT_JSON="$(npm audit --prefix "$PREFIX" --json 2>/dev/null || true)"
if [ -z "$AUDIT_JSON" ]; then
    echo "(npm audit returned no data - network or registry problem?)"
    exit 1
fi

PKG="$PKG" PREFIX="$PREFIX" node -e '
const pkg = process.env.PKG;
let data;
try { data = JSON.parse(require("fs").readFileSync(0, "utf8")); }
catch { console.log("(could not parse npm audit output)"); process.exit(1); }
if (data.error) { console.log("npm audit error: " + (data.error.summary || data.error.code || data.error.message || "unknown (network or registry problem?)")); process.exit(1); }

const v = (data.vulnerabilities || {})[pkg];
if (!v) { console.log("No known vulnerabilities for this package."); process.exit(0); }

console.log(`Severity:       ${v.severity}`);
if (v.range) console.log(`Affected range: ${v.range}`);
console.log(`Direct dep:     ${v.isDirect ? "yes" : "no"}`);
console.log(`Locations:      ${(v.nodes || []).join(", ")}`);

// A string entry in "via" means: vulnerable only through that dependency.
// Follow these chains to the packages that carry the actual advisories.
const vulns = data.vulnerabilities;
function advisories(name, chain, seen) {
    if (seen.has(name)) return [];
    seen.add(name);
    const out = [];
    for (const via of (vulns[name] || {}).via || []) {
        if (typeof via === "string") out.push(...advisories(via, [...chain, via], seen));
        else out.push({ ...via, chain });
    }
    return out;
}

const found = new Map();
for (const a of advisories(pkg, [pkg], new Set())) if (!found.has(a.url)) found.set(a.url, a);

for (const via of found.values()) {
    console.log(`\n- [${via.severity}] ${via.title}`);
    if (via.chain.length > 1) console.log(`  Via:       ${via.chain.join(" -> ")}`);
    console.log(`  Package:   ${via.name}`);
    console.log(`  Affected:  ${via.range}`);
    if (via.cvss && via.cvss.score) console.log(`  CVSS:      ${via.cvss.score}`);
    if (via.cwe && via.cwe.length) console.log(`  CWE:       ${via.cwe.join(", ")}`);
    console.log(`  Advisory:  ${via.url}`);
}

if (v.effects && v.effects.length) console.log(`\nAlso affects: ${v.effects.join(", ")}`);

const fix = v.fixAvailable;
if (fix === true) console.log(`\nFix: available via \`npm audit fix --prefix ${process.env.PREFIX}\``);
else if (fix && typeof fix === "object")
    console.log(`\nFix: update ${fix.name} to ${fix.version}` + (fix.isSemVerMajor ? " (semver major!)" : ""));
else console.log("\nFix: npm audit fix cannot resolve this automatically (no patched release, or a parent package pins the vulnerable range)");
' <<< "$AUDIT_JSON"
