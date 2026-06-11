#!/bin/bash
# get-hugo.sh — Lädt den Hugo-Static-Site-Generator (extended) nach bin/hugo/.
#
# Das Hugo-Binary wird NICHT versioniert (siehe .gitignore) und nicht im
# Auslieferungspaket mitgeliefert. bin/install.sh holt es bei der Einrichtung
# über dieses Skript; ein manueller Aufruf ist ebenfalls möglich:
#
#     bin/get-hugo.sh [--force]
#
#   --force   Vorhandenes Binary neu herunterladen (sonst wird übersprungen,
#             wenn bereits die passende Version vorliegt).

set -euo pipefail

# Gepinnte Version — extended-Variante (SCSS/SASS). Beim Anheben hier ändern.
HUGO_VERSION="0.125.1"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HUGO_DIR="$SCRIPT_DIR/hugo"
HUGO_BIN="$HUGO_DIR/hugo"

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

# Bereits vorhanden und passende Version? Dann nichts tun.
if [ "$FORCE" -eq 0 ] && [ -x "$HUGO_BIN" ] \
   && "$HUGO_BIN" version 2>/dev/null | grep -q "v${HUGO_VERSION}"; then
    echo "Hugo v${HUGO_VERSION} bereits vorhanden: $HUGO_BIN"
    exit 0
fi

# Plattform bestimmen.
case "$(uname -s)" in
    Linux)  hugo_os="linux" ;;
    Darwin) hugo_os="darwin" ;;
    *) echo "❌ Nicht unterstütztes Betriebssystem: $(uname -s)" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64|amd64)  hugo_arch="amd64" ;;
    aarch64|arm64) hugo_arch="arm64" ;;
    *) echo "❌ Nicht unterstützte Architektur: $(uname -m)" >&2; exit 1 ;;
esac

# macOS wird als universelles Binary ausgeliefert.
if [ "$hugo_os" = "darwin" ]; then
    asset="hugo_extended_${HUGO_VERSION}_darwin-universal.tar.gz"
else
    asset="hugo_extended_${HUGO_VERSION}_${hugo_os}-${hugo_arch}.tar.gz"
fi
checks="hugo_${HUGO_VERSION}_checksums.txt"
base="https://github.com/gohugoio/hugo/releases/download/v${HUGO_VERSION}"

# Download-Helfer (curl bevorzugt, sonst wget).
fetch() {
    if command -v curl >/dev/null 2>&1; then curl -fsSL "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then wget -qO "$2" "$1"
    else echo "❌ Weder curl noch wget vorhanden." >&2; exit 1; fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "Lade Hugo v${HUGO_VERSION} (${asset}) …"
fetch "$base/$asset"  "$tmp/$asset"
fetch "$base/$checks" "$tmp/$checks"

# Prüfsumme verifizieren (Format der checksums.txt: "<sha256>  <dateiname>").
expected="$(awk -v f="$asset" '$2 == f {print $1}' "$tmp/$checks")"
if [ -z "$expected" ]; then
    echo "❌ Keine Prüfsumme für $asset gefunden." >&2; exit 1
fi
actual="$(sha256sum "$tmp/$asset" | awk '{print $1}')"
if [ "$expected" != "$actual" ]; then
    echo "❌ Prüfsumme stimmt nicht (erwartet $expected, erhalten $actual)." >&2; exit 1
fi

# Entpacken (Archiv enthält hugo, LICENSE, README.md).
mkdir -p "$HUGO_DIR"
tar -xzf "$tmp/$asset" -C "$HUGO_DIR"
chmod +x "$HUGO_BIN"

echo "✓ $("$HUGO_BIN" version)"
