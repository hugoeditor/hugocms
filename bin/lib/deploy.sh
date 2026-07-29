#!/bin/bash
# deploy.sh — Shared deployment logic for install.sh and update.sh.
#
# Include via `source` (not a standalone invocation). Before calling deploy_app
# the caller must have set the paths APP_DIR and BACKEND_DIR to the release repo.
#
# Provides:
#   EDIT_DIR / API_DIR / BACKEND_LINK    directory/path names in the publish directory
#   deploy_app <base>                    place frontend + generated API index.php
#   read_ini_value <file> <sect> <key>   read the value of a key in an INI section
#
# The logic is deliberately centralized here so that install.sh (initial setup)
# and update.sh (refresh of all sites) deliver bit for bit the same output.

# Directory/path names in the publish directory (defaults overridable).
EDIT_DIR="${EDIT_DIR:-edit}"             # copy of app/ (frontend, URL /edit/)
API_DIR="${API_DIR:-cms-api}"            # endpoint directory = endpoint path for the hash
BACKEND_LINK="${BACKEND_LINK:-backend}"  # old symlink name in cms-api/ (former symlink
                                         # install); cleaned up if present.

# Places frontend (edit/) and API endpoint (cms-api/index.php) in base directory
# $1. Any existing state (copy or former symlink) is replaced. Expects APP_DIR
# and BACKEND_DIR from the caller.
deploy_app() {
    local base="$1"
    if [ -L "$base/$EDIT_DIR" ] || [ -d "$base/$EDIT_DIR" ]; then
        rm -rf "$base/$EDIT_DIR"
    fi
    mkdir -p "$base/$EDIT_DIR"
    cp -a "$APP_DIR/." "$base/$EDIT_DIR/"

    mkdir -p "$base/$API_DIR"
    cat > "$base/$API_DIR/index.php" <<PHP
<?php

/**
 * HugoCMS – API-Endpunkt (von install.sh/update.sh erzeugt).
 * Bindet das Backend direkt im Release-Repo ein; nach einem Update dort
 * (git pull) ist dieser Endpunkt ohne weiteres Zutun aktuell.
 */

declare(strict_types=1);

require '$BACKEND_DIR/core/hugocms.php';
PHP

    # Remove leftovers of a former symlink install.
    if [ -L "$base/$API_DIR/$BACKEND_LINK" ]; then
        rm "$base/$API_DIR/$BACKEND_LINK"
    fi
}

# Reads the value of a key within an INI section. Comments (;) and surrounding
# whitespace are stripped. Prints an empty string if the section or key is
# missing.
#   $1 = file, $2 = section name (without brackets), $3 = key.
read_ini_value() {
    local file="$1" section="$2" key="$3"
    awk -v section="$section" -v key="$key" '
        /^[[:space:]]*\[/ {
            cur = $0
            sub(/^[[:space:]]*\[/, "", cur); sub(/\].*$/, "", cur)
            next
        }
        cur == section {
            line = $0
            sub(/;.*$/, "", line)               # strip trailing comment
            if (line ~ /=/) {
                k = line; sub(/=.*/, "", k); gsub(/[[:space:]]/, "", k)
                if (k == key) {
                    v = line; sub(/^[^=]*=/, "", v)
                    sub(/^[[:space:]]+/, "", v); sub(/[[:space:]]+$/, "", v)
                    print v; exit
                }
            }
        }
    ' "$file"
}
