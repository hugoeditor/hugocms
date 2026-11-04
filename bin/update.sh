#!/bin/bash
# update.sh — Brings ALL sites managed by HugoCMS up to the latest state of the
# release repo and/or upgrades the Hugo generator.
#
# Background: install.sh places the frontend (edit/) and the generated
# API index.php (cms-api/index.php) as a COPY in two locations of every site
# (publish directory and Hugo static/). After a new release version (git pull in
# the release repo) these copies are outdated and must be refreshed. This script
# does that for all sites in one pass: it reads every mount file
# backend/mounts/<hash>.ini, takes the publish directory from the [hugo] section
# and delivers app + endpoint freshly — identical to install.sh, only without
# having to know the host and path of each individual site.
#
# The Hugo binary (bin/hugo/hugo) is maintained separately: install.sh only
# fetches it when missing. With --hugo or --all this script asks GitHub for the
# newest release and replaces the binary when that release is newer than the
# installed one (get-hugo.sh; a specific version: bin/get-hugo.sh --version).
#
# Usage (on the hosting via SSH, in the release repo):
#     bin/update.sh              git pull in the release repo, then refresh all sites
#     bin/update.sh --hugo       only upgrade the Hugo generator (no pull, no sites)
#     bin/update.sh --all        git pull, refresh all sites AND upgrade Hugo
#     bin/update.sh --no-pull    skip the git pull (combinable with --all)
#     bin/update.sh --dry-run    only show what would happen (no changes)
#
# The script lives in the bin/ of the release repo and determines its root
# relative to itself — the repo may reside anywhere.

set -euo pipefail

# --- Release root relative to the script -----------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$PKG_ROOT/app"
BACKEND_DIR="$PKG_ROOT/backend"
MOUNTS_DIR="$BACKEND_DIR/mounts"

# Shared deployment logic (deploy_app, EDIT_DIR/API_DIR, read_ini_value) — the
# same source as install.sh, so the two do not drift apart.
source "$SCRIPT_DIR/lib/deploy.sh"

# --- Parameters ------------------------------------------------------------
DO_PULL=1
DRY_RUN=0
DO_SITES=1
DO_HUGO=0
for arg in "$@"; do
    case "$arg" in
        --pull)        DO_PULL=1 ;;
        --no-pull)     DO_PULL=0 ;;
        --dry-run|-n)  DRY_RUN=1 ;;
        --hugo)        DO_SITES=0; DO_HUGO=1 ;;
        --all)         DO_SITES=1; DO_HUGO=1 ;;
        -h|--help)
            echo "Usage: $0 [--hugo | --all] [--no-pull] [--dry-run]"
            echo "  (without options: git pull in the release repo, then refresh all sites)"
            echo "  --hugo     only upgrade the Hugo generator to the newest GitHub release"
            echo "  --all      refresh all sites AND upgrade the Hugo generator"
            exit 0
            ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done
# --hugo alone touches nothing in the release repo — no pull needed.
[ "$DO_SITES" = 0 ] && DO_PULL=0

# --- Prerequisites ---------------------------------------------------------
for d in "$APP_DIR" "$BACKEND_DIR"; do
    if [ ! -d "$d" ]; then
        echo "❌ Expected directory missing: $d" >&2
        echo "   Does update.sh really live in the bin/ of the release repo?" >&2
        exit 1
    fi
done

# --- 1. Update the release repo --------------------------------------------
# By default the script first fetches the latest release state. Afterwards it
# re-executes itself (without pulling again): the pull may have replaced
# update.sh/lib itself, and Bash would otherwise keep reading a script swapped
# out during execution inconsistently.
if [ "$DO_PULL" = 1 ]; then
    if [ -d "$PKG_ROOT/.git" ]; then
        echo "→ git pull in the release repo ($PKG_ROOT) …"
        git -C "$PKG_ROOT" pull --ff-only
        echo ""
        REEXEC_ARGS=(--no-pull)
        [ "$DRY_RUN" = 1 ] && REEXEC_ARGS+=(--dry-run)
        [ "$DO_HUGO" = 1 ] && REEXEC_ARGS+=(--all)
        exec "$0" "${REEXEC_ARGS[@]}"
    else
        echo "ℹ No git repo at $PKG_ROOT — git pull skipped." >&2
        echo ""
    fi
fi

# --- 2. Refresh all sites --------------------------------------------------
refresh_sites() {
    shopt -s nullglob
    local mounts=("$MOUNTS_DIR"/*.ini)
    if [ "${#mounts[@]}" -eq 0 ]; then
        echo "No mount files in $MOUNTS_DIR — no site configured, nothing to refresh."
        return 0
    fi

    echo "========================================="
    echo "HugoCMS – Refresh sites"
    echo "========================================="
    echo "Release repo: $PKG_ROOT"
    echo "Mounts:       $MOUNTS_DIR (${#mounts[@]} file(s))"
    if [ "$DRY_RUN" = 1 ]; then
        echo "Mode:         dry run (--dry-run) — no changes"
    fi
    echo ""

    local total=0 updated=0 skipped=0 f name publish hugo_root static_dir
    for f in "${mounts[@]}"; do
        total=$((total + 1))
        name="$(basename "$f")"

        # Publish directory = [hugo] destination; from it (as in install.sh) the
        # Hugo project directory and static/ are derived.
        publish="$(read_ini_value "$f" hugo destination)"
        if [ -z "$publish" ]; then
            echo "⏭  $name — no [hugo] destination found; skipped."
            echo "    (Run 'bin/install.sh <host> <publish>' once for this site.)"
            skipped=$((skipped + 1)); continue
        fi
        if [ ! -d "$publish" ]; then
            echo "⏭  $name — publish directory missing: $publish; skipped."
            skipped=$((skipped + 1)); continue
        fi

        hugo_root="$(dirname "$publish")"
        static_dir="$hugo_root/static"

        echo "● $name"
        echo "    Publish: $publish"
        echo "    static:  $static_dir"

        if [ "$DRY_RUN" = 1 ]; then
            updated=$((updated + 1)); continue
        fi

        # Refresh both copies — exactly like install.sh: directly in the publish
        # directory (immediately reachable) and in static/ (survives
        # 'hugo --cleanDestinationDir').
        deploy_app "$publish"
        mkdir -p "$static_dir"
        deploy_app "$static_dir"
        updated=$((updated + 1))
    done

    echo ""
    echo "========================================="
    if [ "$DRY_RUN" = 1 ]; then
        echo "Dry run finished: $updated site(s) would be refreshed, $skipped skipped (of $total)."
    else
        echo "✓ Done: $updated site(s) refreshed, $skipped skipped (of $total)."
    fi
    echo "========================================="
}

# --- 3. Upgrade the Hugo generator -----------------------------------------
# Delegates to get-hugo.sh: it asks GitHub for the newest release, compares it
# with the installed binary and downloads (checksum-verified) only when the
# release is newer.
upgrade_hugo() {
    echo "========================================="
    echo "HugoCMS – Upgrade Hugo generator"
    echo "========================================="
    local args=()
    [ "$DRY_RUN" = 1 ] && args+=(--dry-run)
    "$SCRIPT_DIR/get-hugo.sh" ${args[@]+"${args[@]}"}
    echo "========================================="
}

[ "$DO_SITES" = 1 ] && refresh_sites
if [ "$DO_HUGO" = 1 ]; then
    [ "$DO_SITES" = 1 ] && echo ""
    upgrade_hugo
fi
exit 0
