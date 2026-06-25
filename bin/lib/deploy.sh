#!/bin/bash
# deploy.sh — Gemeinsame Auslieferungslogik für install.sh und update.sh.
#
# Per `source` einbinden (kein eigenständiger Aufruf). Der Aufrufer muss vor
# dem Aufruf von deploy_app die Pfade APP_DIR und BACKEND_DIR auf das Release-
# Repo gesetzt haben.
#
# Stellt bereit:
#   EDIT_DIR / API_DIR / BACKEND_LINK    Verzeichnis- bzw. Pfadnamen im Publish-Ordner
#   deploy_app <basis>                   Frontend + erzeugte API-index.php ablegen
#   read_ini_value <datei> <sekt> <key>  Wert eines Schlüssels einer INI-Sektion lesen
#
# Die Logik ist bewusst hier zentralisiert, damit install.sh (Ersteinrichtung)
# und update.sh (Aktualisierung aller Seiten) Bit für Bit dasselbe ausliefern.

# Verzeichnis- bzw. Pfadnamen im Publish-Ordner (Vorgaben überschreibbar).
EDIT_DIR="${EDIT_DIR:-edit}"             # Kopie von app/ (Frontend, URL /edit/)
API_DIR="${API_DIR:-cms-api}"            # Endpunkt-Verzeichnis = Endpunkt-Pfad für den Hash
BACKEND_LINK="${BACKEND_LINK:-backend}"  # Alter Symlink-Name in cms-api/ (frühere Symlink-
                                         # Installation); wird, falls vorhanden, aufgeräumt.

# Legt Frontend (edit/) und API-Endpunkt (cms-api/index.php) im Basisverzeichnis
# $1 an. Vorhandener Stand (Kopie oder früherer Symlink) wird ersetzt. Erwartet
# APP_DIR und BACKEND_DIR aus dem Aufrufer.
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

    # Symlink-Reste einer früheren Symlink-Installation entfernen.
    if [ -L "$base/$API_DIR/$BACKEND_LINK" ]; then
        rm "$base/$API_DIR/$BACKEND_LINK"
    fi
}

# Liest den Wert eines Schlüssels innerhalb einer INI-Sektion. Kommentare (;)
# und umschließende Leerzeichen werden entfernt. Gibt eine leere Zeichenkette
# aus, wenn Sektion oder Schlüssel fehlen.
#   $1 = Datei, $2 = Sektionsname (ohne Klammern), $3 = Schlüssel.
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
            sub(/;.*$/, "", line)               # Zeilenkommentar entfernen
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
