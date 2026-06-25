#!/bin/bash
# update.sh — Bringt ALLE mit HugoCMS verwalteten Webseiten auf den neusten
# Stand des Release-Repos.
#
# Hintergrund: install.sh legt das Frontend (edit/) und die erzeugte
# API-index.php (cms-api/index.php) als KOPIE an zwei Stellen jeder Webseite ab
# (Publish-Ordner und Hugo-static/). Nach einer neuen Release-Version (git pull
# im Release-Repo) sind diese Kopien veraltet und müssen erneuert werden. Dieses
# Skript erledigt das für alle Webseiten in einem Durchgang: Es liest jede
# Mount-Datei backend/mounts/<hash>.ini, entnimmt der [hugo]-Sektion den
# Publish-Ordner und liefert App + Endpunkt frisch aus — identisch zu
# install.sh, nur ohne dass man Host und Pfad jeder einzelnen Seite kennen muss.
#
# Aufruf (auf dem Hosting per SSH, im Release-Repo):
#     bin/update.sh              git pull im Release-Repo, danach alle Seiten erneuern
#     bin/update.sh --no-pull    nur die Seiten erneuern (kein git pull)
#     bin/update.sh --dry-run    nur anzeigen, was geschähe (keine Änderungen)
#
# Das Skript liegt im bin/ des Release-Repos und ermittelt dessen Wurzel
# relativ zu sich selbst — das Repo darf an beliebiger Stelle liegen.

set -euo pipefail

# --- Release-Wurzel relativ zum Skript -------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$PKG_ROOT/app"
BACKEND_DIR="$PKG_ROOT/backend"
MOUNTS_DIR="$BACKEND_DIR/mounts"

# Gemeinsame Auslieferungslogik (deploy_app, EDIT_DIR/API_DIR, read_ini_value) —
# dieselbe Quelle wie install.sh, damit beide nicht auseinanderdriften.
source "$SCRIPT_DIR/lib/deploy.sh"

# --- Parameter -------------------------------------------------------------
DO_PULL=1
DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --pull)        DO_PULL=1 ;;
        --no-pull)     DO_PULL=0 ;;
        --dry-run|-n)  DRY_RUN=1 ;;
        -h|--help)
            echo "Aufruf: $0 [--no-pull] [--dry-run]"
            echo "  (ohne Option: git pull im Release-Repo, danach alle Webseiten erneuern)"
            exit 0
            ;;
        *) echo "Unbekannte Option: $arg" >&2; exit 1 ;;
    esac
done

# --- Voraussetzungen -------------------------------------------------------
for d in "$APP_DIR" "$BACKEND_DIR"; do
    if [ ! -d "$d" ]; then
        echo "❌ Erwartetes Verzeichnis fehlt: $d" >&2
        echo "   Liegt update.sh wirklich im bin/ des Release-Repos?" >&2
        exit 1
    fi
done

# --- 1. Release-Repo aktualisieren -----------------------------------------
# Standardmäßig holt das Skript zuerst den neusten Release-Stand. Danach startet
# es sich selbst neu (ohne erneuten Pull): Der Pull kann update.sh/lib selbst
# ersetzt haben, und Bash liest ein während der Ausführung ausgetauschtes Skript
# sonst inkonsistent weiter.
if [ "$DO_PULL" = 1 ]; then
    if [ -d "$PKG_ROOT/.git" ]; then
        echo "→ git pull im Release-Repo ($PKG_ROOT) …"
        git -C "$PKG_ROOT" pull --ff-only
        echo ""
        REEXEC_ARGS=(--no-pull)
        [ "$DRY_RUN" = 1 ] && REEXEC_ARGS+=(--dry-run)
        exec "$0" "${REEXEC_ARGS[@]}"
    else
        echo "ℹ Kein Git-Repo unter $PKG_ROOT — git pull wird übersprungen." >&2
        echo ""
    fi
fi

# --- 2. Alle Webseiten erneuern --------------------------------------------
shopt -s nullglob
mounts=("$MOUNTS_DIR"/*.ini)
if [ "${#mounts[@]}" -eq 0 ]; then
    echo "Keine Mount-Dateien in $MOUNTS_DIR — keine Webseite eingerichtet, nichts zu tun."
    exit 0
fi

echo "========================================="
echo "HugoCMS – Webseiten aktualisieren"
echo "========================================="
echo "Release-Repo: $PKG_ROOT"
echo "Mounts:       $MOUNTS_DIR (${#mounts[@]} Datei(en))"
if [ "$DRY_RUN" = 1 ]; then
    echo "Modus:        Probelauf (--dry-run) — keine Änderungen"
fi
echo ""

total=0; updated=0; skipped=0
for f in "${mounts[@]}"; do
    total=$((total + 1))
    name="$(basename "$f")"

    # Publish-Ordner = [hugo] destination; daraus leiten sich (wie in install.sh)
    # Hugo-Projektverzeichnis und static/ ab.
    publish="$(read_ini_value "$f" hugo destination)"
    if [ -z "$publish" ]; then
        echo "⏭  $name — keine [hugo] destination gefunden; übersprungen."
        echo "    (Einmalig 'bin/install.sh <host> <publish>' für diese Seite nachholen.)"
        skipped=$((skipped + 1)); continue
    fi
    if [ ! -d "$publish" ]; then
        echo "⏭  $name — Publish-Ordner fehlt: $publish; übersprungen."
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

    # Beide Kopien erneuern — genau wie install.sh: direkt im Publish-Ordner
    # (sofort erreichbar) und in static/ (übersteht 'hugo --cleanDestinationDir').
    deploy_app "$publish"
    mkdir -p "$static_dir"
    deploy_app "$static_dir"
    updated=$((updated + 1))
done

echo ""
echo "========================================="
if [ "$DRY_RUN" = 1 ]; then
    echo "Probelauf beendet: $updated Webseite(n) würden erneuert, $skipped übersprungen (von $total)."
else
    echo "✓ Fertig: $updated Webseite(n) erneuert, $skipped übersprungen (von $total)."
fi
echo "========================================="
