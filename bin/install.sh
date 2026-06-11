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
#                          edit/ (Symlink) und cms-api/ (Endpunkt) angelegt.
#
# Wirkung:
#   1. Erzeugt — falls noch nicht vorhanden — die host-spezifische Mount-Datei
#        backend/mounts/<hash>.ini   (Hash wie scripts/site-hash.sh)
#      mit Hugo-Struktur: content/, layouts/ und static/ im Hugo-Projekt-
#      verzeichnis (Elternverzeichnis des Publish-Ordners). Pfade bei Bedarf
#      anpassen.
#   2. Richtet den Publish-Ordner ein:
#        edit/             -> <hugocms-release>/app       (Symlink; Frontend, URL /edit/)
#        cms-api/          (echtes Verzeichnis;     API-Endpunkt, URL /cms-api/)
#          ├── index.php   (Kopie der Release-index.php)
#          └── backend     -> <hugocms-release>/backend   (Symlink)
#      So bleibt der Code im Release; nur die kleine index.php wird kopiert.
#
# Das Skript liegt im bin/ des Release-Repos und ermittelt dessen Wurzel
# relativ zu sich selbst — das Repo darf an beliebiger Stelle liegen.

set -euo pipefail

# --- Namen im Publish-Ordner -----------------------------------------------
EDIT_LINK="edit"        # Symlink auf app/ (Frontend, URL /edit/)
API_DIR="cms-api"       # Endpunkt-Verzeichnis = Endpunkt-Pfad für den Hash
BACKEND_LINK="backend"  # Symlink in cms-api/ auf das Release-backend/. Der Name
                        # muss zu require '…/backend/core/hugocms.php' in der
                        # kopierten index.php passen.

# --- Release-Wurzel relativ zum Skript -----------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$PKG_ROOT/app"
BACKEND_DIR="$PKG_ROOT/backend"
ENTRY="$PKG_ROOT/index.php"   # Release-Einstiegspunkt (wird nach cms-api/ kopiert)
MOUNTS_DIR="$BACKEND_DIR/mounts"

# --- Parameter prüfen ------------------------------------------------------
if [ "$#" -ne 2 ]; then
    echo "Aufruf: $0 <host> <hugo-publish-ordner>" >&2
    echo "  z. B. $0 kunde-a.example.com /var/www/kunde-a/public" >&2
    exit 1
fi

HOST="$1"
PUBLISH="$2"

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
if [ ! -f "$ENTRY" ]; then
    echo "❌ Release-Einstiegspunkt fehlt: $ENTRY" >&2
    exit 1
fi
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

mkdir -p "$MOUNTS_DIR"
echo "1. Mount-Konfiguration (Hugo-Projekt: $HUGO_ROOT)"
echo "   Site-Kennung: $SITE_KEY"
echo "   Datei:        $MOUNT_FILE"
if [ -e "$MOUNT_FILE" ]; then
    echo "   → existiert bereits, bleibt unverändert."
else
    # Mount-Zielverzeichnisse sicherstellen — das Backend verweigert Mounts
    # auf nicht existierende Verzeichnisse.
    mkdir -p "$HUGO_ROOT/content" "$HUGO_ROOT/layouts" "$HUGO_ROOT/static"
    cat > "$MOUNT_FILE" <<EOF
; HugoCMS – Mounts für $HOST (von install.sh erzeugt).
; Hugo-Projektstruktur im Elternverzeichnis des Publish-Ordners.
; Absolute Pfade; bei Bedarf anpassen.

[content]
path = $HUGO_ROOT/content
label = Inhalt
accept = md, markdown, html, htm, png, jpg, jpeg, gif, webp, svg

[layouts]
path = $HUGO_ROOT/layouts
label = Vorlagen
permissions = read, write

[static]
path = $HUGO_ROOT/static
label = Medien
EOF
    echo "   → erzeugt:  content -> $HUGO_ROOT/content"
    echo "               layouts -> $HUGO_ROOT/layouts"
    echo "               static  -> $HUGO_ROOT/static"
fi
echo ""

# --- 2. Publish-Ordner einrichten ------------------------------------------
# ln -sfn: vorhandenen Symlink ersetzen (-f), bestehenden Verzeichnis-Symlink
#          nicht dereferenzieren (-n; sonst landete der Link IM Zielverzeichnis).
echo "2. Publish-Ordner"

# Frontend: direkter Symlink genügt (rein statische Dateien).
ln -sfn "$APP_DIR" "$PUBLISH_ABS/$EDIT_LINK"
echo "   $EDIT_LINK/ -> $APP_DIR"

# API-Endpunkt: echtes Verzeichnis cms-api/ mit kopierter index.php und einem
# Symlink auf das Release-backend/. Die index.php bindet backend/core/
# hugocms.php ein — der Code bleibt im Release, nur index.php wird kopiert.
API_ENDPOINT="$PUBLISH_ABS/$API_DIR"
mkdir -p "$API_ENDPOINT"
cp "$ENTRY" "$API_ENDPOINT/index.php"
ln -sfn "$BACKEND_DIR" "$API_ENDPOINT/$BACKEND_LINK"
echo "   $API_DIR/index.php   (kopiert aus dem Release)"
echo "   $API_DIR/$BACKEND_LINK -> $BACKEND_DIR"
echo ""

echo "========================================="
echo "✓ Einrichtung abgeschlossen für $HOST"
echo "========================================="
