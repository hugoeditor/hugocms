#!/bin/bash
# install.sh — Richtet eine HugoCMS-Webseite im Produktivsystem ein.
#
# Aufruf:
#     bin/install.sh <host> <hugo-publish-ordner>
#
#   <host>                 Hostname der Webseite OHNE Schema/Port/Pfad,
#                          z. B. kunde-a.example.com (so wie der Browser ihn
#                          sendet). Den Endpunkt-Pfad (/cms-api) ergänzt das
#                          Skript selbst — passend zum Verzeichnisnamen unten.
#   <hugo-publish-ordner>  Veröffentlichungsverzeichnis von Hugo; hier werden
#                          edit/ (Frontend) und cms-api/ (Endpunkt) angelegt.
#
# Wirkung:
#   1. Erzeugt — falls noch nicht vorhanden — die host-spezifische Mount-Datei
#        backend/mounts/<hash>.ini   (Hash wie scripts/site-hash.sh)
#      mit dem gesamten Hugo-Projektverzeichnis als erstem Mount (projekt,
#      Zugriff auf ALLE Dateien inkl. config.* und Theme) sowie content/,
#      layouts/ und static/ darin (Elternverzeichnis des Publish-Ordners).
#      Pfade bei Bedarf anpassen.
#   2. Richtet die App ohne Symlinks ein — funktioniert damit auch auf Hostings,
#      deren Webserver Symlinks nicht folgt (z. B. Shared Hosting):
#        edit/             KOPIE von <hugocms-release>/app  (Frontend, URL /edit/)
#        cms-api/          (Endpunkt, URL /cms-api/)
#          └── index.php   (erzeugt; bindet das Release-backend/ per require ein)
#      Der PHP-Code bleibt im Release-Repo; nur das Frontend wird kopiert.
#      Die App wird an ZWEI Stellen abgelegt: direkt im Publish-Ordner (sofort
#      erreichbar) und im Hugo-static-Verzeichnis. Da Hugo static/ bei jedem
#      Build in den Publish-Ordner spiegelt, übersteht die App so auch ein
#      'hugo --cleanDestinationDir'. Nach einem Update (git pull im Release-Repo)
#      das Skript erneut ausführen, damit beide Kopien aktualisiert werden.
#
# Das Skript liegt im bin/ des Release-Repos und ermittelt dessen Wurzel
# relativ zu sich selbst — das Repo darf an beliebiger Stelle liegen.

set -euo pipefail

# --- Release-Wurzel relativ zum Skript -----------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$PKG_ROOT/app"
BACKEND_DIR="$PKG_ROOT/backend"
MOUNTS_DIR="$BACKEND_DIR/mounts"

# Gemeinsame Auslieferungslogik (deploy_app sowie die Verzeichnisnamen
# EDIT_DIR/API_DIR/BACKEND_LINK) — dieselbe Quelle wie update.sh, damit
# Ersteinrichtung und Aktualisierung nicht auseinanderdriften.
source "$SCRIPT_DIR/lib/deploy.sh"

# --- Parameter prüfen ------------------------------------------------------
ARGS=()
for arg in "$@"; do
    case "$arg" in
        -h|--help)
            echo "Aufruf: $0 <host> <hugo-publish-ordner>"
            exit 0
            ;;
        *) ARGS+=("$arg") ;;
    esac
done

if [ "${#ARGS[@]}" -ne 2 ]; then
    echo "Aufruf: $0 <host> <hugo-publish-ordner>" >&2
    echo "  z. B. $0 kunde-a.example.com /var/www/kunde-a/public" >&2
    exit 1
fi

HOST="${ARGS[0]}"
PUBLISH="${ARGS[1]}"

# Host grob prüfen: kein Schema, kein Port, kein Pfad, kein Leerzeichen.
if printf '%s' "$HOST" | grep -qE '[/:[:space:]]'; then
    echo "❌ <host> darf nur den Hostnamen enthalten (kein Schema/Port/Pfad): '$HOST'" >&2
    exit 1
fi

# Voraussetzungen: Release-Struktur und Publish-Ordner.
for d in "$APP_DIR" "$BACKEND_DIR"; do
    if [ ! -d "$d" ]; then
        echo "❌ Erwartetes Verzeichnis fehlt: $d" >&2
        echo "   Liegt install.sh wirklich im bin/ des Release-Repos?" >&2
        exit 1
    fi
done
if [ ! -d "$PUBLISH" ]; then
    echo "❌ Publish-Ordner existiert nicht: $PUBLISH" >&2
    exit 1
fi
PUBLISH_ABS="$(cd "$PUBLISH" && pwd)"

echo "========================================="
echo "HugoCMS - Webseite einrichten"
echo "========================================="
echo "Release-Repo: $PKG_ROOT"
echo "Webseite:       $HOST"
echo "Publish-Ordner: $PUBLISH_ABS"
echo ""

# --- 0. Hugo bereitstellen -------------------------------------------------
# Das Hugo-Binary wird nicht mitausgeliefert (bin/hugo/ ist ignoriert); beim
# ersten Lauf per get-hugo.sh nachladen.
if [ ! -x "$SCRIPT_DIR/hugo/hugo" ]; then
    echo "0. Hugo-Binary nicht vorhanden — wird heruntergeladen …"
    "$SCRIPT_DIR/get-hugo.sh"
    echo ""
fi

# --- 1. Host-spezifische Mount-Datei (Hugo-Struktur) -----------------------
# Site-Kennung = Host + Endpunkt-Pfad; Hash identisch zu backend/core/SiteKey.php.
SITE_KEY="${HOST}/${API_DIR}"
HASH="$(printf '%s' "$SITE_KEY" | sha256sum | cut -d' ' -f1)"
MOUNT_FILE="$MOUNTS_DIR/${HASH}.ini"

# Hugo-Projektverzeichnis = Elternverzeichnis des Publish-Ordners; dort liegen
# content/, layouts/ und static/. ABSOLUTE Pfade verwenden — relative Pfade in
# der Mount-Datei gälten sonst relativ zu backend/mounts/, nicht zum Projekt.
HUGO_ROOT="$(dirname "$PUBLISH_ABS")"

# Standard-Sektionen als gemeinsame Quelle für Neuanlage UND Nachrüstung, damit
# beide Pfade nicht auseinanderdriften. source/destination der [hugo]-Sektion
# werden wie die Mount-Pfade aus dem Hugo-Projektverzeichnis bzw. dem
# übergebenen Publish-Ordner abgeleitet.

# Projekt-Mount: das gesamte Hugo-Projektverzeichnis. Ohne accept-Beschränkung,
# damit der Benutzer auf ALLE Dateien zugreifen kann (config.toml/hugo.yaml,
# Theme-Ordner u. a.) — nicht nur auf content/layouts/static. Steht bewusst als
# ERSTER Mount.
PROJEKT_BLOCK="[projekt]
path = $HUGO_ROOT
label = Projekt"

CONTENT_BLOCK="[content]
path = $HUGO_ROOT/content
label = Inhalt
accept = md, markdown, html, htm, png, jpg, jpeg, gif, webp, svg"

LAYOUTS_BLOCK="[layouts]
path = $HUGO_ROOT/layouts
label = Vorlagen
permissions = read, write"

STATIC_BLOCK="[static]
path = $HUGO_ROOT/static
label = Medien"

HUGO_BLOCK="; Hugo-Aufruf für den Veröffentlichen-Knopf (Befehl \"build\"). Das Hugo-
; Programm (bin) steht zentral in der hugocms.ini, nicht hier.
[hugo]
source = $HUGO_ROOT
destination = $PUBLISH_ABS"

# Hängt einen Sektionsblock an die bestehende Mount-Datei an, falls die Sektion
# fehlt, und legt ein optional angegebenes Verzeichnis an.
# $1 = Sektionsname, $2 = Sektionsblock, $3 = optionales Verzeichnis.
append_section_if_missing() {
    local name="$1" block="$2" dir="${3:-}"
    if grep -q "^\[${name}\]" "$MOUNT_FILE"; then
        return
    fi
    [ -n "$dir" ] && mkdir -p "$dir"
    printf '\n%s\n' "$block" >> "$MOUNT_FILE"
    echo "     [${name}] ergänzt${dir:+  -> $dir}"
}

# Wie append_section_if_missing, fügt den Block aber VOR der ersten Sektion ein
# (nach einem etwaigen Kopf-Kommentar) — für Mounts, die als erster Eintrag
# erscheinen sollen.
prepend_section_if_missing() {
    local name="$1" block="$2" dir="${3:-}"
    if grep -q "^\[${name}\]" "$MOUNT_FILE"; then
        return
    fi
    [ -n "$dir" ] && mkdir -p "$dir"
    local tmp
    tmp="$(mktemp "$(dirname "$MOUNT_FILE")/.mnt.XXXXXX")"
    awk -v block="$block" '
        seen == 0 && /^\[/ { print block "\n"; seen = 1 }
        { print }
        END { if (seen == 0) printf "\n%s\n", block }
    ' "$MOUNT_FILE" > "$tmp" && mv "$tmp" "$MOUNT_FILE"
    echo "     [${name}] als erster Mount ergänzt${dir:+  -> $dir}"
}

mkdir -p "$MOUNTS_DIR"
echo "1. Mount-Konfiguration (Hugo-Projekt: $HUGO_ROOT)"
echo "   Site-Kennung: $SITE_KEY"
echo "   Datei:        $MOUNT_FILE"
if [ -e "$MOUNT_FILE" ]; then
    # Bestehende Datei nicht überschreiben (mögliche manuelle Anpassungen),
    # aber fehlende Standard-Sektionen nachrüsten — z. B. bei Dateien, die eine
    # ältere install.sh-Version ohne [hugo] erzeugt hat. Vorhandene Sektionen
    # (auch umbenannte Mounts) bleiben unangetastet.
    echo "   → existiert bereits; fehlende Standard-Sektionen werden ergänzt."
    prepend_section_if_missing projekt "$PROJEKT_BLOCK" "$HUGO_ROOT"
    append_section_if_missing content "$CONTENT_BLOCK" "$HUGO_ROOT/content"
    append_section_if_missing layouts "$LAYOUTS_BLOCK" "$HUGO_ROOT/layouts"
    append_section_if_missing static  "$STATIC_BLOCK"  "$HUGO_ROOT/static"
    append_section_if_missing hugo    "$HUGO_BLOCK"
else
    # Mount-Zielverzeichnisse sicherstellen — das Backend verweigert Mounts
    # auf nicht existierende Verzeichnisse.
    mkdir -p "$HUGO_ROOT/content" "$HUGO_ROOT/layouts" "$HUGO_ROOT/static"
    cat > "$MOUNT_FILE" <<EOF
; HugoCMS – Mounts für $HOST (von install.sh erzeugt).
; Hugo-Projektstruktur im Elternverzeichnis des Publish-Ordners.
; Absolute Pfade; bei Bedarf anpassen.

$PROJEKT_BLOCK

$CONTENT_BLOCK

$LAYOUTS_BLOCK

$STATIC_BLOCK

$HUGO_BLOCK
EOF
    echo "   → erzeugt:  projekt -> $HUGO_ROOT (alle Dateien)"
    echo "               content -> $HUGO_ROOT/content"
    echo "               layouts -> $HUGO_ROOT/layouts"
    echo "               static  -> $HUGO_ROOT/static"
    echo "               [hugo]  -> source $HUGO_ROOT -> destination $PUBLISH_ABS"
fi
echo ""

# --- 1b. Zentralen Hugo-Programmpfad in die hugocms.ini eintragen ----------
# Das Hugo-Binary wird installationsweit einmal konfiguriert: [hugo] bin in
# der hugocms.ini. Existiert die Datei bereits (Einrichtung gelaufen) und hat
# noch keine [hugo]-Sektion, ergänzen wir sie; sonst nur ein Hinweis, da die
# hugocms.ini erst das Einrichtungs-Setup beim ersten Aufruf erzeugt.
CONFIG_FILE="$BACKEND_DIR/hugocms.ini"
HUGO_BIN="$SCRIPT_DIR/hugo/hugo"
echo "1b. Zentraler Hugo-Programmpfad (hugocms.ini, [hugo] bin)"
if [ -f "$CONFIG_FILE" ]; then
    if grep -q '^\[hugo\]' "$CONFIG_FILE"; then
        echo "    → [hugo]-Sektion vorhanden, bleibt unverändert (bin ggf. prüfen: $HUGO_BIN)."
    else
        printf '\n[hugo]\nbin = %s\n' "$HUGO_BIN" >> "$CONFIG_FILE"
        echo "    → ergänzt: bin = $HUGO_BIN"
    fi
else
    echo "    → hugocms.ini fehlt noch (Einrichtung folgt im Browser)."
    echo "      Danach in die [hugo]-Sektion eintragen:  bin = $HUGO_BIN"
fi
echo ""

# --- 2. App einrichten -----------------------------------------------------
# Ohne Symlinks: Das Frontend wird kopiert, der API-Endpunkt erhält eine
# erzeugte index.php mit absolutem require auf das Release-backend/. So
# funktioniert es auch auf Hostings, deren Webserver Symlinks nicht folgt;
# PHP liest per require ohnehin direkt im Release-Repo.
#
# Die App wird an ZWEI Stellen abgelegt:
#   • direkt im Publish-Ordner  — sofort erreichbar (vor dem ersten Hugo-Lauf),
#   • im Hugo-static-Verzeichnis — Hugo spiegelt static/ bei jedem Build in den
#     Publish-Ordner; dadurch übersteht die App ein 'hugo --cleanDestinationDir'.
# Nach einem Update (git pull) dieses Skript erneut ausführen, damit beide
# Kopien aktualisiert werden.

# deploy_app() — legt Frontend (edit/) und API-Endpunkt (cms-api/index.php) im
# übergebenen Basisverzeichnis ab. Definiert in bin/lib/deploy.sh (oben per
# `source` eingebunden), damit install.sh und update.sh identisch ausliefern.

echo "2. App einrichten (Frontend + API-Endpunkt)"
STATIC_DIR="$HUGO_ROOT/static"

deploy_app "$PUBLISH_ABS"
echo "   Publish-Ordner: $EDIT_DIR/ + $API_DIR/index.php  ($PUBLISH_ABS)"

deploy_app "$STATIC_DIR"
echo "   Hugo-static:    $EDIT_DIR/ + $API_DIR/index.php  ($STATIC_DIR)"
echo "   → Hugo spiegelt static/ bei jedem Build — die App übersteht damit"
echo "     'hugo --cleanDestinationDir'."
echo ""

echo "========================================="
echo "✓ Einrichtung abgeschlossen für $HOST"
echo "========================================="
