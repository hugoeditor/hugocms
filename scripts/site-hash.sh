#!/bin/bash
# site-hash.sh — ermittelt Hash und Dateinamen der host-spezifischen
# Mount-Konfiguration für eine Webseite.
#
# Eingabe: die kanonische Site-Kennung = Host + Endpunkt-Pfad, z. B.
#     kunde-a.example.com/cms-api
# Genau diesen Wert nennt auch die 404-/Warnmeldung des Backends; zur
# Einrichtung vor dem ersten Aufruf lässt er sich hiermit vorab berechnen.
#
# Der Hash entspricht hash('sha256', $siteKey) in backend/core/SiteKey.php.

set -euo pipefail

key="${1:-}"
if [ -z "$key" ]; then
    echo "Aufruf: $0 <host><endpunkt-pfad>" >&2
    echo "  z. B. $0 kunde-a.example.com/cms-api" >&2
    exit 1
fi

hash="$(printf '%s' "$key" | sha256sum | cut -d' ' -f1)"
echo "Kennung: $key"
echo "Datei:   backend/mounts/${hash}.ini"
