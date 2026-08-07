#!/bin/bash
# get-hugo.sh — Downloads the Hugo static site generator (extended) into bin/hugo/.
#
# The Hugo binary is NOT versioned (see .gitignore) and is not shipped in the
# release package. bin/install.sh fetches it during setup via this script; a
# manual invocation is possible as well:
#
#     bin/get-hugo.sh [--force]
#
#   --force   Re-download an existing binary (otherwise it is skipped when the
#             matching version is already present).

set -euo pipefail

# Pinned version — extended variant (SCSS/SASS). Change here when bumping.
HUGO_VERSION="0.164.0"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HUGO_DIR="$SCRIPT_DIR/hugo"
HUGO_BIN="$HUGO_DIR/hugo"

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

# Already present and matching version? Then do nothing.
if [ "$FORCE" -eq 0 ] && [ -x "$HUGO_BIN" ] \
   && "$HUGO_BIN" version 2>/dev/null | grep -q "v${HUGO_VERSION}"; then
    echo "Hugo v${HUGO_VERSION} already present: $HUGO_BIN"
    exit 0
fi

# Determine platform.
case "$(uname -s)" in
    Linux)  hugo_os="linux" ;;
    Darwin) hugo_os="darwin" ;;
    *) echo "❌ Unsupported operating system: $(uname -s)" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64|amd64)  hugo_arch="amd64" ;;
    aarch64|arm64) hugo_arch="arm64" ;;
    *) echo "❌ Unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac

# macOS ships as a universal binary.
if [ "$hugo_os" = "darwin" ]; then
    asset="hugo_extended_${HUGO_VERSION}_darwin-universal.tar.gz"
else
    asset="hugo_extended_${HUGO_VERSION}_${hugo_os}-${hugo_arch}.tar.gz"
fi
checks="hugo_${HUGO_VERSION}_checksums.txt"
base="https://github.com/gohugoio/hugo/releases/download/v${HUGO_VERSION}"

# Download helper (prefers curl, falls back to wget).
fetch() {
    if command -v curl >/dev/null 2>&1; then curl -fsSL "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then wget -qO "$2" "$1"
    else echo "❌ Neither curl nor wget available." >&2; exit 1; fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "Downloading Hugo v${HUGO_VERSION} (${asset}) …"
fetch "$base/$asset"  "$tmp/$asset"
fetch "$base/$checks" "$tmp/$checks"

# Verify checksum (checksums.txt format: "<sha256>  <filename>").
expected="$(awk -v f="$asset" '$2 == f {print $1}' "$tmp/$checks")"
if [ -z "$expected" ]; then
    echo "❌ No checksum found for $asset." >&2; exit 1
fi
actual="$(sha256sum "$tmp/$asset" | awk '{print $1}')"
if [ "$expected" != "$actual" ]; then
    echo "❌ Checksum mismatch (expected $expected, got $actual)." >&2; exit 1
fi

# Unpack (archive contains hugo, LICENSE, README.md).
mkdir -p "$HUGO_DIR"
tar -xzf "$tmp/$asset" -C "$HUGO_DIR"
chmod +x "$HUGO_BIN"

echo "✓ $("$HUGO_BIN" version)"
