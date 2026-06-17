#!/bin/bash
# packaging.sh - Aktualisiert das Auslieferungs-Repo unter ./hugocms-release/
# mit der neusten Version von Client (Frontend-Build) und Backend.
#
# Erzeugt/erneuert die Struktur direkt im Repo-Wurzelverzeichnis:
#   hugocms-release/
#   ├── app/                 (gebautes Frontend = Client; URL-Pfad via Installationsroutine)
#   ├── bin/
#   │     ├── get-hugo.sh          (lädt das Hugo-Binary nach bin/hugo/)
#   │     └── install.sh           (richtet eine Webseite ein: Kopie + mounts/<hash>.ini)
#   │     (hugo/ wird NICHT mitgeliefert — install.sh holt es per get-hugo.sh)
#   ├── backend/
#   │     ├── core/                (Kern-Bibliothek inkl. autoload.php + hugocms.php)
#   │     ├── custom/
#   │     │     └── custom.php.beispiel  (Vorlage: anwenderspezifischer Bootstrap)
#   │     ├── mounts/                    (host-spezifische mounts/<hash>.ini; .gitkeep)
#   │     ├── log/                       (Laufzeit; .htaccess + .gitkeep)
#   │     ├── var/sessions/              (Laufzeit; .gitkeep)
#   │     ├── hugocms.ini.beispiel       (Vorlage: Anmeldung, Session, Logging)
#   │     └── mounts.ini.beispiel        (Vorlage: Mount-Konfiguration, Rückfall)
#   └── index.php            (Einstiegspunkt; bindet backend/core/hugocms.php ein.
#                             install.sh erzeugt im Endpunkt <publish>/cms-api/
#                             index.php eine eigene Fassung mit absolutem require
#                             auf das Release-backend/ — ohne Symlink.)
#
# Aufruf:
#   packaging.sh            nur bauen; danach 'git status' des Release-Repos.
#   packaging.sh --commit   zusätzlich im Release-Repo committen (Commit-
#                           Message wird aus dem Quell-Commit abgeleitet).
#   packaging.sh --push     committen UND pushen.
# Ohne Flag wird NICHT committet — Commit/Push bleiben dir überlassen.

set -euo pipefail

# --- Argumente -------------------------------------------------------------
DO_COMMIT=0
DO_PUSH=0
for arg in "$@"; do
    case "$arg" in
        --commit) DO_COMMIT=1 ;;
        --push)   DO_COMMIT=1; DO_PUSH=1 ;;
        -h|--help) echo "Aufruf: $0 [--commit] [--push]"; exit 0 ;;
        *) echo "Unbekannte Option: $arg" >&2; exit 1 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PKG_REPO="$PROJECT_DIR/hugocms-release"
# Die Paketdateien liegen direkt im Repo-Wurzelverzeichnis (kein hugocms/).
PKG="$PKG_REPO"

echo "========================================="
echo "HugoCMS - Packaging"
echo "========================================="
echo "Projektverzeichnis: $PROJECT_DIR"
echo "Paketverzeichnis:   $PKG"
echo ""

# 0. Auslieferungs-Repo muss als eigenes Git-Repo unter hugocms-release/
#    vorliegen. Das Skript klont es bewusst NICHT selbst — die Repo-URL ist
#    installationsspezifisch (siehe README.md).
if [ ! -d "$PKG_REPO/.git" ]; then
    echo "❌ Auslieferungs-Repo fehlt: $PKG_REPO"
    echo "   Das Repo 'hugocms-release' muss vorhanden sein, bevor das Paket"
    echo "   gebaut werden kann."
    echo "   Einrichtung siehe README.md, Abschnitt 'Auslieferungs-Repo'."
    exit 1
fi

# 0b. Alte Struktur (hugocms/-Zwischenordner) entfernen, falls noch vorhanden.
if [ -d "$PKG_REPO/hugocms" ]; then
    echo "Alte Struktur hugocms-release/hugocms/ wird entfernt."
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
#    im Paket-Wurzelverzeichnis werden entfernt.
echo "4. Laufzeitverzeichnisse sicherstellen (backend/log/, backend/var/sessions/, backend/mounts/)"
rm -rf "$PKG/backend/log" "$PKG/backend/var" "$PKG/log" "$PKG/var"
rm -f "$PKG/backend/mounts/"*.ini
mkdir -p "$PKG/backend/log" "$PKG/backend/var/sessions" "$PKG/backend/mounts"
touch "$PKG/backend/log/.gitkeep" "$PKG/backend/var/sessions/.gitkeep" "$PKG/backend/mounts/.gitkeep"

# 4b. Live-Instanzkonfiguration darf NIEMALS ins Release: hugocms.ini enthält
#     den Passwort-Hash, mounts.ini die instanzspezifischen Pfade. Beim
#     vollständigen Kopieren (Schritt 3) wandern sie mit, obwohl sie im Quell-
#     Repo als *.ini/*.bak gitignored sind. Es bleiben ausschließlich die
#     *.beispiel-Vorlagen. Eine frische Installation läuft so ins Setup statt
#     fremde Konfiguration zu erben.
echo "4b. Instanzkonfiguration entfernen (nur *.beispiel bleibt)"
rm -f "$PKG/backend/hugocms.ini" "$PKG/backend/mounts.ini"
find "$PKG/backend" -name '*.bak' -type f -delete
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

# 5b. bin/ ausliefern — nur die Skripte (install.sh, get-hugo.sh). Das Hugo-
#     Binary wird NICHT mitgeliefert; install.sh lädt es per get-hugo.sh im
#     Produktivsystem nach (bin/hugo/ ist in beiden Repos ignoriert).
echo "5b. bin -> $PKG/bin (ohne Hugo-Binary)"
rm -rf "$PKG/bin"
cp -r "$PROJECT_DIR/bin" "$PKG/bin"
rm -rf "$PKG/bin/hugo"
chmod +x "$PKG/bin/"*.sh 2>/dev/null || true

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

if [ "$DO_COMMIT" = 1 ]; then
    # Commit-Message aus dem Quell-Commit ableiten (Rückverfolgbarkeit). Ist der
    # Arbeitsbaum des Quell-Repos nicht sauber, wird der Stand als -dev markiert.
    SRC_REV="$(git -C "$PROJECT_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
    SRC_SUBJ="$(git -C "$PROJECT_DIR" log -1 --pretty=%s 2>/dev/null || echo 'Release')"
    if ! git -C "$PROJECT_DIR" diff --quiet HEAD 2>/dev/null; then
        SRC_REV="${SRC_REV}-dev"
        # Der Quell-Arbeitsbaum ist nicht sauber — der Commit würde als -dev
        # markiert. Vor dem Erzeugen bestätigen lassen (Default: Ja). Ohne
        # interaktives Terminal (z. B. CI) gilt der Default ohne Nachfrage.
        if [ -t 0 ]; then
            printf 'Quell-Arbeitsbaum nicht sauber — Commit als "%s" erzeugen? [J/n] ' "$SRC_REV"
            read -r reply || reply=""
            case "$reply" in
                [nN]*) echo "Abgebrochen — kein Commit erzeugt."; exit 0 ;;
            esac
        fi
    fi
    MSG="Release aus ${SRC_REV}: ${SRC_SUBJ}"

    git -C "$PKG_REPO" add -A
    if git -C "$PKG_REPO" diff --cached --quiet; then
        echo "Release-Repo unverändert — kein Commit nötig."
    else
        git -C "$PKG_REPO" commit -q -m "$MSG"
        echo "Commit erstellt: $MSG"
    fi
    if [ "$DO_PUSH" = 1 ]; then
        echo "Push…"
        git -C "$PKG_REPO" push
    fi
else
    echo "Zum Übernehmen: git -C '$PKG_REPO' add -A && git -C '$PKG_REPO' commit"
    echo "  oder bequemer: scripts/packaging.sh --commit   (bzw. --push)"
fi
echo "========================================="
