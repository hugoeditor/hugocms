#!/bin/bash
# packaging.sh - Aktualisiert das Auslieferungs-Repo unter ./packaging/
# mit der neusten Version von Client (Frontend-Build) und Backend.
#
# Erzeugt/erneuert die Struktur:
#   packaging/hugocms/
#   ├── edit/               (gebautes Frontend = Client, läuft unter /edit/)
#   ├── backend/            (PHP-Quellen)
#   ├── index.php.beispiel  (Bootstrap-Vorlage, ohne echte Geheimnisse)
#   ├── log/                (Laufzeit; .htaccess + .gitkeep)
#   └── var/sessions/       (Laufzeit; .gitkeep)
#
# Es wird NICHT committet — nach dem Lauf zeigt das Skript 'git status' des
# Paket-Repos an; Commit und Push bleiben dir überlassen.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PKG_REPO="$PROJECT_DIR/packaging"
PKG="$PKG_REPO/hugocms"

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

# 1. Frontend bauen (erzeugt frontend/dist über das vorhandene build.sh)
echo "1. Frontend bauen..."
"$SCRIPT_DIR/build.sh"
if [ ! -f "$PROJECT_DIR/frontend/dist/index.html" ]; then
    echo "❌ Build-Ergebnis 'frontend/dist' fehlt. Abbruch."
    exit 1
fi
echo ""

# 2. Client übernehmen (edit/) — alte (gehashte) Assets vollständig ersetzen.
#    Der Build setzt base=/edit/, daher trägt das Verzeichnis denselben Namen
#    wie der URL-Pfad. Frühere app/ bzw. hugocms-app/ werden mit entfernt.
echo "2. Client  -> $PKG/edit"
rm -rf "$PKG/app" "$PKG/hugocms-app" "$PKG/edit"
mkdir -p "$PKG/edit"
cp -r "$PROJECT_DIR/frontend/dist/." "$PKG/edit/"

# 3. Backend übernehmen (backend/) — vollständig ersetzen
echo "3. Backend -> $PKG/backend"
rm -rf "$PKG/backend"
mkdir -p "$PKG/backend"
cp -r "$PROJECT_DIR/backend/." "$PKG/backend/"

# 4. Laufzeitverzeichnisse sicherstellen (Inhalt bleibt unversioniert)
echo "4. Laufzeitverzeichnisse sicherstellen (log/, var/sessions/)"
mkdir -p "$PKG/log" "$PKG/var/sessions"
[ -e "$PKG/log/.gitkeep" ] || touch "$PKG/log/.gitkeep"
[ -e "$PKG/var/sessions/.gitkeep" ] || touch "$PKG/var/sessions/.gitkeep"
if [ ! -e "$PKG/log/.htaccess" ]; then
    cat > "$PKG/log/.htaccess" <<'HT'
# Apache: kein direkter Zugriff auf Logdateien.
# Nginx wertet diese Datei NICHT aus — dort den location-Block aus
# beispiel-konfigurationen/nginx.conf verwenden oder das Log-Verzeichnis
# außerhalb des Web-Wurzelverzeichnisses ablegen.
Require all denied
HT
fi

# 5. Bootstrap-Vorlage schreiben (ohne echte Geheimnisse)
echo "5. Vorlage  -> $PKG/index.php.beispiel"
cat > "$PKG/index.php.beispiel" <<'PHP'
<?php
/**
 * Bootstrap-Vorlage für den HugoCMS-Connector (Auslieferungspaket).
 *
 * Diese Datei NICHT unverändert verwenden:
 *   1. Nach index.php kopieren.
 *   2. hugocms.ini.beispiel nach hugocms.ini kopieren und dort Anmeldung,
 *      Sitzungsverzeichnis und Logging eintragen (Passwort als HASH).
 *   3. Mounts festlegen — wahlweise über mounts.ini (siehe mounts.ini.beispiel)
 *      oder programmatisch. Pfade möglichst AUSSERHALB des Web-Wurzelver-
 *      zeichnisses.
 *
 * Erwartete Paketstruktur (Document-Root = hugocms/):
 *   hugocms/
 *   ├── edit/                (Client; erreichbar unter /edit/)
 *   ├── backend/             (PHP-Quellen)
 *   ├── log/
 *   ├── var/sessions/
 *   ├── hugocms.ini.beispiel (Vorlage: Anmeldung, Session, Logging)
 *   ├── mounts.ini.beispiel  (Vorlage: Mount-Konfiguration)
 *   └── index.php            (diese Datei; Endpunkt /cms-api/)
 *
 * Der Webserver muss den Endpunkt /cms-api/ auf diese Datei lenken und den
 * direkten Zugriff auf backend/, log/ und var/ unterbinden (Vorlagen für
 * Apache und Nginx im Quell-Repository unter beispiel-konfigurationen/).
 */

declare(strict_types=1);

require __DIR__ . '/backend/autoload.php';

use HugoCMS\FileManager\Connector;

// Anmeldung, Sitzungsverzeichnis und Logging stammen aus hugocms.ini. Der
// Connector liest die Datei, setzt das Sitzungsverzeichnis und erzeugt die
// Authentifizierung (driver-abhängig).
$connector = new Connector([
    'config' => __DIR__ . '/hugocms.ini',
    //
    // Eigene AuthInterface-Implementierungen registrieren und in hugocms.ini
    // per [auth] driver = ... auswählen:
    // 'authDrivers' => [
    //     'ldap' => fn (array $cfg) => new \Meine\LdapAuth($cfg['host']),
    // ],
]);

// Mounts festlegen — zwei Wege, beliebig kombinierbar:
//
// A) Aus einer Konfigurationsdatei (gut lesbar, ohne Code-Änderung pflegbar).
//    mounts.ini.beispiel nach mounts.ini kopieren und anpassen:
//
//        $connector->mountsFromFile(__DIR__ . '/mounts.ini');
//
// B) Programmatisch — Pfade möglichst außerhalb des Web-Wurzelverzeichnisses:
$connector->mount('inhalte', __DIR__ . '/daten/inhalte', [
    'label' => 'Inhalte',
    'accept' => ['md', 'markdown', 'html', 'htm', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
]);

$connector->mount('vorlagen', __DIR__ . '/daten/vorlagen', [
    'label' => 'Vorlagen',
    'permissions' => ['read', 'write'],
]);

$connector->mount('medien', __DIR__ . '/daten/medien', [
    'label' => 'Medien',
]);

$connector->run();
PHP

# 5b. Konfigurationsvorlagen mitliefern
echo "5b. Vorlagen -> $PKG/hugocms.ini.beispiel, $PKG/mounts.ini.beispiel"
cp "$PROJECT_DIR/hugocms.ini.beispiel" "$PKG/hugocms.ini.beispiel"
cp "$PROJECT_DIR/mounts.ini.beispiel" "$PKG/mounts.ini.beispiel"

# 6. Berechtigungen vereinheitlichen
find "$PKG/edit" "$PKG/backend" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "$PKG/edit" "$PKG/backend" -type f -exec chmod 664 {} \; 2>/dev/null || true

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
