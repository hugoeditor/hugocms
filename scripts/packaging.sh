#!/bin/bash
# packaging.sh - Aktualisiert das Auslieferungs-Repo unter ./packaging/
# mit der neusten Version von Client (Frontend-Build) und Backend.
#
# Erzeugt/erneuert die Struktur direkt im Repo-Wurzelverzeichnis:
#   packaging/
#   ├── app/                 (gebautes Frontend = Client; URL-Pfad via Installationsroutine)
#   ├── backend/
#   │     ├── core/                (Kern-Bibliothek inkl. autoload.php + hugocms.php)
#   │     ├── custom/
#   │     │     └── custom.php.beispiel  (Vorlage: anwenderspezifischer Bootstrap)
#   │     ├── mounts/                    (host-spezifische mounts/<hash>.ini; .gitkeep)
#   │     ├── log/                       (Laufzeit; .htaccess + .gitkeep)
#   │     ├── var/sessions/              (Laufzeit; .gitkeep)
#   │     ├── hugocms.ini.beispiel       (Vorlage: Anmeldung, Session, Logging)
#   │     └── mounts.ini.beispiel        (Vorlage: Mount-Konfiguration, Rückfall)
#   └── index.php            (dünner Einstiegspunkt; bindet backend/core/hugocms.php ein)
#
# Es wird NICHT committet — nach dem Lauf zeigt das Skript 'git status' des
# Paket-Repos an; Commit und Push bleiben dir überlassen.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PKG_REPO="$PROJECT_DIR/packaging"
# Die Paketdateien liegen direkt im Repo-Wurzelverzeichnis (kein hugocms/).
PKG="$PKG_REPO"

echo "========================================="
echo "HugoCMS - Packaging"
echo "========================================="
echo "Projektverzeichnis: $PROJECT_DIR"
echo "Paketverzeichnis:   $PKG"
echo ""

# 0. Voraussetzungen prüfen
if [ ! -d "$PKG_REPO/.git" ]; then
    echo "❌ '$PKG_REPO' ist kein Git-Repo (.git fehlt). Abbruch."
    echo "   Repo anlegen mit:  git -C '$PKG_REPO' init"
    exit 1
fi

# 0b. Alte Struktur (hugocms/-Zwischenordner) entfernen, falls noch vorhanden.
if [ -d "$PKG_REPO/hugocms" ]; then
    echo "Alte Struktur packaging/hugocms/ wird entfernt."
    rm -rf "$PKG_REPO/hugocms"
fi

# 1. Frontend bauen (erzeugt frontend/dist über das vorhandene build.sh)
echo "1. Frontend bauen..."
"$SCRIPT_DIR/build.sh"
if [ ! -f "$PROJECT_DIR/frontend/dist/index.html" ]; then
    echo "❌ Build-Ergebnis 'frontend/dist' fehlt. Abbruch."
    exit 1
fi
echo ""

# 2. Client übernehmen (app/) — alte (gehashte) Assets vollständig ersetzen.
#    Der Build setzt base=/edit/ (Asset-Referenzen); das Auslieferungs-
#    verzeichnis heißt app/ und wird im Produktivsystem über die
#    Installationsroutine auf den URL-Pfad abgebildet. Frühere edit/ bzw.
#    hugocms-app/ werden mit entfernt.
echo "2. Client  -> $PKG/app"
rm -rf "$PKG/edit" "$PKG/hugocms-app" "$PKG/app"
mkdir -p "$PKG/app"
cp -r "$PROJECT_DIR/frontend/dist/." "$PKG/app/"

# 3. Backend übernehmen (backend/) — vollständig ersetzen.
#    Enthält die Kern-Bibliothek unter core/ (inkl. autoload.php + hugocms.php),
#    die Bootstrap-Vorlage unter custom/custom.php.beispiel sowie die ini-
#    Vorlagen direkt in backend/ — alles kommt automatisch mit und wird nicht
#    mehr separat kopiert.
echo "3. Backend -> $PKG/backend"
rm -rf "$PKG/backend"
mkdir -p "$PKG/backend"
cp -r "$PROJECT_DIR/backend/." "$PKG/backend/"

# 4. Laufzeit-/Installationsverzeichnisse unter backend/ sicherstellen (Inhalt
#    unversioniert). Beim Backend-Kopieren (Schritt 3) mitgewanderte
#    Entwicklungsinhalte (Logdatei, Session-Dateien, site-spezifische
#    mounts/<hash>.ini) sowie aus dem früheren Layout verbliebene log/ und var/
#    im Paket-Wurzelverzeichnis werden entfernt. Die Rückfall-mounts.ini bleibt
#    (liegt direkt in backend/, nicht in backend/mounts/).
echo "4. Laufzeitverzeichnisse sicherstellen (backend/log/, backend/var/sessions/, backend/mounts/)"
rm -rf "$PKG/backend/log" "$PKG/backend/var" "$PKG/log" "$PKG/var"
rm -f "$PKG/backend/mounts/"*.ini
mkdir -p "$PKG/backend/log" "$PKG/backend/var/sessions" "$PKG/backend/mounts"
touch "$PKG/backend/log/.gitkeep" "$PKG/backend/var/sessions/.gitkeep" "$PKG/backend/mounts/.gitkeep"
cat > "$PKG/backend/log/.htaccess" <<'HT'
# Apache: kein direkter Zugriff auf Logdateien.
# Nginx wertet diese Datei NICHT aus — dort den location-Block aus
# beispiel-konfigurationen/nginx.conf verwenden oder das Log-Verzeichnis
# außerhalb des Web-Wurzelverzeichnisses ablegen.
Require all denied
HT

# 5. Dünner Einstiegspunkt (index.php) — bindet backend/core/hugocms.php ein,
#    gehört zum Backend, nicht anpassen.
#    Aus dem früheren Paket-Layout im Wurzelverzeichnis verbliebene Dateien
#    entfernen: index.php.beispiel sowie die nach backend/ gewanderten Vorlagen.
echo "5. Einstiegspunkt -> $PKG/index.php"
rm -f "$PKG/index.php.beispiel" \
      "$PKG/custom.php.beispiel" "$PKG/hugocms.ini.beispiel" "$PKG/mounts.ini.beispiel"
cp "$PROJECT_DIR/index.php" "$PKG/index.php"

# 6. Berechtigungen vereinheitlichen
find "$PKG/app" "$PKG/backend" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "$PKG/app" "$PKG/backend" -type f -exec chmod 664 {} \; 2>/dev/null || true

echo ""
echo "========================================="
echo "Paket aktualisiert."
echo "========================================="
echo ""
echo "Änderungen im Paket-Repo ($PKG_REPO):"
echo "-----------------------------------------"
git -C "$PKG_REPO" status -s
echo "-----------------------------------------"
echo "Zum Übernehmen z. B.:"
echo "  git -C '$PKG_REPO' add -A && git -C '$PKG_REPO' commit -m 'Release: aktueller Client + Backend'"
echo "========================================="
