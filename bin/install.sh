#!/bin/bash
# install.sh — Sets up a HugoCMS site on a production system.
#
# Usage:
#     bin/install.sh [--lang=en|de] <host> <hugo-publish-directory>
#
#   <host>                    Hostname of the site WITHOUT scheme/port/path,
#                             e.g. kunde-a.example.com (as the browser sends it).
#                             The script appends the endpoint path (/cms-api)
#                             itself — matching the directory name below.
#   <hugo-publish-directory>  Hugo's publish directory; edit/ (frontend) and
#                             cms-api/ (endpoint) are created here.
#   --lang=en|de              Language of the human-readable text in the
#                             generated mount file: the section labels shown in
#                             the CMS file tree, the header lines and the [hugo]
#                             comment. Default: en. The section IDs and the
#                             script's own output are always English.
#
# Effect:
#   1. Creates — if not already present — the host-specific mount file
#        backend/mounts/<hash>.ini   (hash as in scripts/site-hash.sh)
#      with the entire Hugo project directory as the first mount (projekt,
#      access to ALL files incl. config.* and theme) plus content/, layouts/
#      and static/ within it (parent directory of the publish directory).
#      Adjust paths as needed.
#   2. Sets up the app without symlinks — so it also works on hostings whose
#      web server does not follow symlinks (e.g. shared hosting):
#        edit/             COPY of <hugocms-release>/app  (frontend, URL /edit/)
#        cms-api/          (endpoint, URL /cms-api/)
#          └── index.php   (generated; includes the release backend/ via require)
#      The PHP code stays in the release repo; only the frontend is copied.
#      The app is placed in TWO locations: directly in the publish directory
#      (immediately reachable) and in the Hugo static/ directory. Because Hugo
#      mirrors static/ into the publish directory on every build, the app
#      survives a 'hugo --cleanDestinationDir'. After an update (git pull in the
#      release repo) run the script again so both copies are refreshed.
#
# The script lives in the bin/ of the release repo and determines its root
# relative to itself — the repo may reside anywhere.

set -euo pipefail

# --- Release root relative to the script ---------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$PKG_ROOT/app"
BACKEND_DIR="$PKG_ROOT/backend"
MOUNTS_DIR="$BACKEND_DIR/mounts"

# Shared deployment logic (deploy_app as well as the directory names
# EDIT_DIR/API_DIR/BACKEND_LINK) — the same source as update.sh, so that initial
# setup and update do not drift apart.
source "$SCRIPT_DIR/lib/deploy.sh"

# --- Check parameters ------------------------------------------------------
LANG_SEL="en"   # language of the generated mount file's text (labels/comments)
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --lang=*) LANG_SEL="${arg#*=}" ;;
        -h|--help)
            echo "Usage: $0 [--lang=en|de] <host> <hugo-publish-directory>"
            exit 0
            ;;
        *) ARGS+=("$arg") ;;
    esac
done

case "$LANG_SEL" in
    en|de) ;;
    *) echo "❌ --lang must be 'en' or 'de': '$LANG_SEL'" >&2; exit 1 ;;
esac

if [ "${#ARGS[@]}" -ne 2 ]; then
    echo "Usage: $0 [--lang=en|de] <host> <hugo-publish-directory>" >&2
    echo "  e.g. $0 kunde-a.example.com /var/www/kunde-a/public" >&2
    exit 1
fi

HOST="${ARGS[0]}"
PUBLISH="${ARGS[1]}"

# Rough host check: no scheme, no port, no path, no whitespace.
if printf '%s' "$HOST" | grep -qE '[/:[:space:]]'; then
    echo "❌ <host> must contain only the hostname (no scheme/port/path): '$HOST'" >&2
    exit 1
fi

# Prerequisites: release structure and publish directory.
for d in "$APP_DIR" "$BACKEND_DIR"; do
    if [ ! -d "$d" ]; then
        echo "❌ Expected directory missing: $d" >&2
        echo "   Does install.sh really live in the bin/ of the release repo?" >&2
        exit 1
    fi
done
if [ ! -d "$PUBLISH" ]; then
    echo "❌ Publish directory does not exist: $PUBLISH" >&2
    exit 1
fi
PUBLISH_ABS="$(cd "$PUBLISH" && pwd)"

echo "========================================="
echo "HugoCMS - Set up site"
echo "========================================="
echo "Release repo:      $PKG_ROOT"
echo "Site:              $HOST"
echo "Publish directory: $PUBLISH_ABS"
echo "Language (labels): $LANG_SEL"
echo ""

# --- 0. Provide Hugo -------------------------------------------------------
# The Hugo binary is not shipped (bin/hugo/ is ignored); fetch it via
# get-hugo.sh on first run.
if [ ! -x "$SCRIPT_DIR/hugo/hugo" ]; then
    echo "0. Hugo binary not present — downloading …"
    "$SCRIPT_DIR/get-hugo.sh"
    echo ""
fi

# --- 1. Host-specific mount file (Hugo structure) --------------------------
# Site identifier = host + endpoint path; hash identical to backend/core/SiteKey.php.
SITE_KEY="${HOST}/${API_DIR}"
HASH="$(printf '%s' "$SITE_KEY" | sha256sum | cut -d' ' -f1)"
MOUNT_FILE="$MOUNTS_DIR/${HASH}.ini"

# Hugo project directory = parent directory of the publish directory; content/,
# layouts/ and static/ live there. Use ABSOLUTE paths — relative paths in the
# mount file would otherwise be relative to backend/mounts/, not the project.
HUGO_ROOT="$(dirname "$PUBLISH_ABS")"

# Default sections as a shared source for both creation AND retrofit, so the two
# paths do not drift apart. source/destination of the [hugo] section are derived
# — like the mount paths — from the Hugo project directory and the given publish
# directory.
#
# The human-readable text of the generated mount file — the section labels shown
# in the CMS file tree, the header lines and the [hugo] comment — follows
# --lang. ONE translation table (below) feeds the single set of block templates
# that follows, so the block-building code exists only once. The section IDs
# ([content] etc.) are internal and stay the same in every language.
case "$LANG_SEL" in
    de)
        LBL_PROJEKT="Alle Dateien"; LBL_CONTENT="Inhalt"
        LBL_LAYOUTS="Vorlagen";     LBL_STATIC="Medien"
        HDR_LINE1="; HugoCMS – Mounts für $HOST (von install.sh erzeugt)."
        HDR_STRUCT="; Hugo-Projektstruktur im Elternverzeichnis des Publish-Ordners."
        HDR_PATHS="; Absolute Pfade; bei Bedarf anpassen."
        HUGO_CMT="; Hugo-Aufruf für den Veröffentlichen-Knopf (Befehl \"build\"). Das Hugo-
; Programm (bin) steht zentral in der hugocms.ini, nicht hier."
        ;;
    en|*)
        LBL_PROJEKT="All files";  LBL_CONTENT="Content"
        LBL_LAYOUTS="Templates";  LBL_STATIC="Media"
        HDR_LINE1="; HugoCMS – Mounts for $HOST (generated by install.sh)."
        HDR_STRUCT="; Hugo project structure in the parent directory of the publish directory."
        HDR_PATHS="; Absolute paths; adjust as needed."
        HUGO_CMT="; Hugo invocation for the publish button (command \"build\"). The Hugo
; program (bin) is configured centrally in hugocms.ini, not here."
        ;;
esac

# Project mount: the entire Hugo project directory. Without an accept restriction
# so the user can access ALL files (config.toml/hugo.yaml, theme directory etc.)
# — not just content/layouts/static. Deliberately placed as the LAST mount.
PROJEKT_BLOCK="[projekt]
path = $HUGO_ROOT
label = $LBL_PROJEKT"

CONTENT_BLOCK="[content]
path = $HUGO_ROOT/content
label = $LBL_CONTENT
accept = md, markdown, html, htm, png, jpg, jpeg, gif, webp, svg"

LAYOUTS_BLOCK="[layouts]
path = $HUGO_ROOT/layouts
label = $LBL_LAYOUTS
permissions = read, write"

STATIC_BLOCK="[static]
path = $HUGO_ROOT/static
label = $LBL_STATIC"

HUGO_BLOCK="$HUGO_CMT
[hugo]
source = $HUGO_ROOT
destination = $PUBLISH_ABS"

# Appends a section block to the existing mount file if the section is missing,
# and creates an optionally given directory.
# $1 = section name, $2 = section block, $3 = optional directory.
append_section_if_missing() {
    local name="$1" block="$2" dir="${3:-}"
    if grep -q "^\[${name}\]" "$MOUNT_FILE"; then
        return
    fi
    [ -n "$dir" ] && mkdir -p "$dir"
    printf '\n%s\n' "$block" >> "$MOUNT_FILE"
    echo "     [${name}] added${dir:+  -> $dir}"
}

mkdir -p "$MOUNTS_DIR"
echo "1. Mount configuration (Hugo project: $HUGO_ROOT)"
echo "   Site identifier: $SITE_KEY"
echo "   File:            $MOUNT_FILE"
if [ -e "$MOUNT_FILE" ]; then
    # Do not overwrite an existing file (possible manual adjustments), but
    # retrofit missing default sections — e.g. for files created by an older
    # install.sh version without [hugo]. Existing sections (including renamed
    # mounts) stay untouched.
    echo "   → already exists; missing default sections will be added."
    append_section_if_missing content "$CONTENT_BLOCK" "$HUGO_ROOT/content"
    append_section_if_missing layouts "$LAYOUTS_BLOCK" "$HUGO_ROOT/layouts"
    append_section_if_missing static  "$STATIC_BLOCK"  "$HUGO_ROOT/static"
    append_section_if_missing projekt "$PROJEKT_BLOCK" "$HUGO_ROOT"
    append_section_if_missing hugo    "$HUGO_BLOCK"
else
    # Ensure the mount target directories — the backend refuses mounts on
    # non-existent directories.
    mkdir -p "$HUGO_ROOT/content" "$HUGO_ROOT/layouts" "$HUGO_ROOT/static"
    cat > "$MOUNT_FILE" <<EOF
$HDR_LINE1
$HDR_STRUCT
$HDR_PATHS

$CONTENT_BLOCK

$LAYOUTS_BLOCK

$STATIC_BLOCK

$PROJEKT_BLOCK

$HUGO_BLOCK
EOF
    echo "   → created:  content -> $HUGO_ROOT/content"
    echo "               layouts -> $HUGO_ROOT/layouts"
    echo "               static  -> $HUGO_ROOT/static"
    echo "               projekt -> $HUGO_ROOT (all files)"
    echo "               [hugo]  -> source $HUGO_ROOT -> destination $PUBLISH_ABS"
fi
echo ""

# --- 1b. Register the central Hugo program path in hugocms.ini -------------
# The Hugo binary is configured once per installation: [hugo] bin in the
# hugocms.ini. If the file already exists (setup has run) and has no [hugo]
# section yet, we add it; otherwise only a hint, since hugocms.ini is created by
# the setup on first access.
CONFIG_FILE="$BACKEND_DIR/hugocms.ini"
HUGO_BIN="$SCRIPT_DIR/hugo/hugo"
echo "1b. Central Hugo program path (hugocms.ini, [hugo] bin)"
if [ -f "$CONFIG_FILE" ]; then
    if grep -q '^\[hugo\]' "$CONFIG_FILE"; then
        echo "    → [hugo] section present, stays unchanged (verify bin if needed: $HUGO_BIN)."
    else
        printf '\n[hugo]\nbin = %s\n' "$HUGO_BIN" >> "$CONFIG_FILE"
        echo "    → added: bin = $HUGO_BIN"
    fi
else
    echo "    → hugocms.ini not present yet (setup follows in the browser)."
    echo "      Afterwards add to the [hugo] section:  bin = $HUGO_BIN"
fi
echo ""

# --- 2. Set up the app -----------------------------------------------------
# Without symlinks: the frontend is copied, the API endpoint gets a generated
# index.php with an absolute require on the release backend/. This works even on
# hostings whose web server does not follow symlinks; PHP reads directly in the
# release repo via require anyway.
#
# The app is placed in TWO locations:
#   • directly in the publish directory — immediately reachable (before the first
#     Hugo run),
#   • in the Hugo static directory — Hugo mirrors static/ into the publish
#     directory on every build; this way the app survives a
#     'hugo --cleanDestinationDir'.
# After an update (git pull) run this script again so both copies are refreshed.

# deploy_app() — places frontend (edit/) and API endpoint (cms-api/index.php) in
# the given base directory. Defined in bin/lib/deploy.sh (included above via
# `source`), so install.sh and update.sh deliver identically.

echo "2. Set up the app (frontend + API endpoint)"
STATIC_DIR="$HUGO_ROOT/static"

deploy_app "$PUBLISH_ABS"
echo "   Publish directory: $EDIT_DIR/ + $API_DIR/index.php  ($PUBLISH_ABS)"

deploy_app "$STATIC_DIR"
echo "   Hugo static:       $EDIT_DIR/ + $API_DIR/index.php  ($STATIC_DIR)"
echo "   → Hugo mirrors static/ on every build — the app thus survives"
echo "     'hugo --cleanDestinationDir'."
echo ""

echo "========================================="
echo "✓ Setup complete for $HOST"
echo "========================================="
