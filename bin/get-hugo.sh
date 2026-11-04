#!/bin/bash
# get-hugo.sh — Downloads the Hugo static site generator (extended) into bin/hugo/.
#
# The Hugo binary is NOT versioned (see .gitignore) and is not shipped in the
# release package. bin/install.sh fetches it during setup via this script
# (when missing); bin/update.sh --hugo / --all upgrades it; a manual
# invocation is possible as well:
#
#     bin/get-hugo.sh [--version <x.y.z>] [--force] [--dry-run]
#
#   (none)            Ask GitHub for the newest release and install it when it
#                     is newer than the installed binary (or none is present).
#   --version <x.y.z> Install exactly this version instead (skipped when that
#                     exact version is already present). Use it to roll back
#                     to a known-good release.
#   --force           Download even when the target version is already
#                     present or the installed one is newer.
#   --dry-run         Only report installed and target version; download
#                     nothing.
#
# Always the extended variant (SCSS/SASS).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HUGO_DIR="$SCRIPT_DIR/hugo"
HUGO_BIN="$HUGO_DIR/hugo"
GITHUB_REPO="gohugoio/hugo"

FORCE=0; DRY_RUN=0; PINNED=""
while [ $# -gt 0 ]; do
    case "$1" in
        --force)       FORCE=1 ;;
        --dry-run|-n)  DRY_RUN=1 ;;
        --version)
            [ $# -ge 2 ] || { echo "--version needs a value (e.g. 0.164.0)" >&2; exit 1; }
            PINNED="${2#v}"; shift ;;
        --version=*)   PINNED="${1#--version=}"; PINNED="${PINNED#v}" ;;
        -h|--help)
            echo "Usage: $0 [--version <x.y.z>] [--force] [--dry-run]"
            exit 0
            ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
    shift
done
if [ -n "$PINNED" ] && ! printf '%s' "$PINNED" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "❌ Invalid version '$PINNED' — expected x.y.z (e.g. 0.164.0)." >&2
    exit 1
fi

# Download helper (prefers curl, falls back to wget).
fetch() {
    if command -v curl >/dev/null 2>&1; then curl -fsSL "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then wget -qO "$2" "$1"
    else echo "❌ Neither curl nor wget available." >&2; exit 1; fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Installed version ("0.169.0") or empty when no usable binary is present.
installed_version() {
    [ -x "$HUGO_BIN" ] || return 0
    "$HUGO_BIN" version 2>/dev/null \
        | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | head -n1 | sed 's/^v//' || true
}

# Newest release tag on GitHub ("0.165.2"), resolved via the releases API.
latest_version() {
    fetch "https://api.github.com/repos/${GITHUB_REPO}/releases/latest" "$tmp/latest.json"
    grep -oE '"tag_name"[[:space:]]*:[[:space:]]*"v[0-9]+\.[0-9]+\.[0-9]+"' "$tmp/latest.json" \
        | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1
}

# "$1 is newer than $2" by semantic version order.
version_gt() {
    [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$1" ]
}

INSTALLED="$(installed_version)"

if [ -n "$PINNED" ]; then
    TARGET="$PINNED"
    echo "Installed: ${INSTALLED:+v}${INSTALLED:-none}   requested: v${TARGET}"
else
    echo "Querying GitHub for the newest Hugo release …"
    TARGET="$(latest_version)"
    if [ -z "$TARGET" ]; then
        echo "❌ Could not determine the newest Hugo release from GitHub." >&2
        exit 1
    fi
    echo "Installed: ${INSTALLED:+v}${INSTALLED:-none}   newest: v${TARGET}"
fi

# Decide whether a download is necessary.
NEED=1
if [ "$FORCE" -eq 0 ] && [ -n "$INSTALLED" ]; then
    if [ "$INSTALLED" = "$TARGET" ]; then
        NEED=0
    elif [ -z "$PINNED" ] && ! version_gt "$TARGET" "$INSTALLED"; then
        # Installed one is newer than the newest release (e.g. a pre-release
        # or a manual install) — leave it alone unless forced.
        NEED=0
    fi
fi

if [ "$NEED" -eq 0 ]; then
    echo "Hugo v${INSTALLED} is up to date: $HUGO_BIN"
    exit 0
fi

if [ "$DRY_RUN" -eq 1 ]; then
    if [ -n "$INSTALLED" ]; then
        echo "Dry run: would download Hugo v${TARGET} (replacing v${INSTALLED})."
    else
        echo "Dry run: would download Hugo v${TARGET} (fresh install)."
    fi
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
    asset="hugo_extended_${TARGET}_darwin-universal.tar.gz"
else
    asset="hugo_extended_${TARGET}_${hugo_os}-${hugo_arch}.tar.gz"
fi
checks="hugo_${TARGET}_checksums.txt"
base="https://github.com/${GITHUB_REPO}/releases/download/v${TARGET}"

echo "Downloading Hugo v${TARGET} (${asset}) …"
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

# Unpack into a staging directory, copy the binary next to its destination
# and swap it in with one rename (same file system) so a Hugo run that starts
# in between never sees a half-written file.
mkdir -p "$HUGO_DIR" "$tmp/unpack"
tar -xzf "$tmp/$asset" -C "$tmp/unpack"
for f in LICENSE README.md; do
    [ -f "$tmp/unpack/$f" ] && cp "$tmp/unpack/$f" "$HUGO_DIR/$f"
done
cp "$tmp/unpack/hugo" "$HUGO_BIN.new"
chmod +x "$HUGO_BIN.new"
mv -f "$HUGO_BIN.new" "$HUGO_BIN"

echo "✓ $("$HUGO_BIN" version)"
