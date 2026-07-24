#!/bin/bash
# crontab-entries.sh — Gibt für ALLE eingerichteten Webseiten die passenden
# Crontab-Zeilen aus (bauen, verbessern, Gesundheitscheck) — analog zu den
# Beispielen in der Hilfe (Systemstatus → Cron-Aufgaben) und, wie update.sh,
# über alle Webseiten in einem Durchgang.
#
# Hintergrund: Jeder Cron-Aufruf betrifft genau EINE Webseite (kein Lauf über
# alle Projekte). Bei mehreren Webseiten braucht daher jede eigene Einträge mit
# ihrer Mount-Datei (--mounts) und — für die Pro-Skripte — ihrer lizenzierten
# Domain (--host). Dieses Skript liest jede Mount-Datei backend/mounts/<hash>.ini,
# entnimmt Host (aus dem Kopfkommentar von install.sh) und Lizenzstatus
# ([license] key) und setzt die Zeilen zusammen.
#
# Das Skript ÄNDERT NICHTS an der Crontab — es gibt die Zeilen nur aus. Prüfen,
# dann von Hand übernehmen:
#     bin/crontab-entries.sh                       Zeilen anzeigen
#     bin/crontab-entries.sh --limit=5             --limit der Verbesserung setzen (Standard 3)
#     bin/crontab-entries.sh --php=/usr/bin/php8.2  PHP-Pfad vorgeben
#     bin/crontab-entries.sh > cron.txt            zum Prüfen speichern, dann via 'crontab -e' übernehmen
#
# Zeitplan (je Webseite versetzt, damit nicht alle gleichzeitig laufen):
#   bauen            alle 15 Minuten (baut nur bei fälligen Freigaben;
#                    für Hugos Front-Matter-publishDate zusätzlich --force)
#   verbessern       nachts ~3 Uhr, je Seite um 5 Minuten versetzt (Pro)
#   Gesundheitscheck morgens ~6 Uhr, ebenso versetzt (Pro)
#
# Das Skript liegt im bin/ des Release-Repos und ermittelt dessen Wurzel
# relativ zu sich selbst — das Repo darf an beliebiger Stelle liegen.

set -euo pipefail

# --- Release-Wurzel relativ zum Skript -------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND_DIR="$PKG_ROOT/backend"
MOUNTS_DIR="$BACKEND_DIR/mounts"
CLI_DIR="$BACKEND_DIR/cli"

# read_ini_value aus derselben Bibliothek wie install.sh/update.sh (kein
# doppelter INI-Leser). deploy_app wird hier nicht benutzt.
source "$SCRIPT_DIR/lib/deploy.sh"

usage() {
    sed -n '2,26p' "$0" | sed 's/^#\( \|$\)//'
}

# --- Parameter -------------------------------------------------------------
LIMIT=3
PHP_BIN=""
for arg in "$@"; do
    case "$arg" in
        --limit=*) LIMIT="${arg#*=}" ;;
        --php=*)   PHP_BIN="${arg#*=}" ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unbekannte Option: $arg" >&2; echo "  (bin/crontab-entries.sh --help)" >&2; exit 1 ;;
    esac
done

if ! printf '%s' "$LIMIT" | grep -qE '^[1-9][0-9]*$'; then
    echo "❌ --limit muss eine positive Zahl sein: '$LIMIT'" >&2
    exit 1
fi

# PHP-Programm: vorgegeben, sonst aus dem PATH, sonst /usr/bin/php. In der
# Crontab MUSS der volle Pfad stehen — dort ist der Suchpfad minimal.
if [ -z "$PHP_BIN" ]; then
    PHP_BIN="$(command -v php || true)"
    [ -z "$PHP_BIN" ] && PHP_BIN="/usr/bin/php"
fi

# Host aus dem Kopfkommentar der Mount-Datei lesen. install.sh schreibt dort
# "; HugoCMS – Mounts für <HOST> (von install.sh erzeugt)."; der Hash im
# Dateinamen ist nicht umkehrbar, deshalb ist der Kommentar die Quelle.
read_mount_host() {
    sed -n 's/.*Mounts für \(.*\) (von install\.sh.*/\1/p' "$1" | head -n1
}

# Eine Crontab-Zeile ausrichten: Zeitplan | php | cli/<skript> | Rest. Der
# Skriptname wird auf feste Breite gepolstert, damit --mounts untereinander steht.
emit() {
    local sched="$1" script="$2" rest="$3"
    printf '%-13s %s %s/%-20s %s\n' "$sched" "$PHP_BIN" "$CLI_DIR" "$script" "$rest"
}

shopt -s nullglob
mounts=("$MOUNTS_DIR"/*.ini)
if [ "${#mounts[@]}" -eq 0 ]; then
    echo "# Keine Mount-Dateien in $MOUNTS_DIR — keine Webseite eingerichtet." >&2
    exit 0
fi

# --- Kopf ------------------------------------------------------------------
cat <<EOF
# ===========================================================================
# HugoCMS – Crontab-Einträge (${#mounts[@]} Webseite(n))
# Erzeugt von bin/crontab-entries.sh · PHP: $PHP_BIN
#
# Ein Aufruf betrifft immer nur EINE Webseite. Diese Zeilen prüfen und dann
# übernehmen (z. B. via 'crontab -e'). Die Pro-Skripte (verbessern,
# Gesundheitscheck) erscheinen nur für Webseiten mit hinterlegter Lizenz.
# ===========================================================================
EOF

idx=0
for f in "${mounts[@]}"; do
    host="$(read_mount_host "$f")"
    license="$(read_ini_value "$f" license key)"
    min=$(( (idx * 5) % 60 ))   # Pro-Skripte je Seite um 5 Minuten versetzen
    idx=$((idx + 1))

    echo ""
    echo "# ── ${host:-$(basename "$f")} ──  ($(basename "$f"))"

    # Bauen: keine Lizenz nötig, für jede Webseite.
    emit "*/15 * * * *" "cron-build.php" "--mounts=$f --quiet"

    # Verbessern und Gesundheitscheck sind Pro — nur mit hinterlegter Lizenz.
    if [ -z "$license" ]; then
        echo "#   (keine Lizenz in [license] key — verbessern/Gesundheitscheck übersprungen)"
        continue
    fi
    if [ -z "$host" ]; then
        echo "#   ⚠ Host nicht aus der Mount-Datei ermittelbar — --host von Hand eintragen:"
        host="BITTE-DOMAIN-EINTRAGEN"
    fi

    emit "$min 3 * * *" "cron-improve.php"     "--mounts=$f --host=$host --limit=$LIMIT"
    emit "$min 6 * * *" "cron-healthcheck.php" "--mounts=$f --host=$host"
done

echo ""
