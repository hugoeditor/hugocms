#!/bin/bash
# build.sh - Baut das Frontend für den Produktivbetrieb (frontend/dist)

# Projektverzeichnis = eine Ebene über /scripts
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "========================================="
echo "HugoCMS - Build Script"
echo "========================================="
echo ""
echo "Projektverzeichnis: $PROJECT_DIR"
echo ""

cd "$PROJECT_DIR" || exit 1

# === Node sicherstellen (mindestens 18; bei zu altem System-Node nvm laden) ===
ensure_node() {
    if command -v node &>/dev/null && [ "$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)" -ge 18 ]; then
        echo "Using Node: $(node --version)"
        return
    fi
    export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"
    if [ -s "$NVM_DIR/nvm.sh" ]; then
        # shellcheck disable=SC1091
        . "$NVM_DIR/nvm.sh"
        nvm use --lts >/dev/null 2>&1 || nvm use node >/dev/null 2>&1
    fi
    if ! command -v node &>/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)" -lt 18 ]; then
        local latest
        latest=$(ls -d "$NVM_DIR"/versions/node/v* 2>/dev/null | sort -V | tail -1)
        [ -n "$latest" ] && export PATH="$latest/bin:$PATH"
    fi
    if ! command -v node &>/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)" -lt 18 ]; then
        echo "ERROR: Node.js 18 oder höher erforderlich, nicht gefunden."
        exit 1
    fi
    echo "Using Node: $(node --version)"
}

# === Frontend-Abhängigkeiten (nur bei Änderung installieren) ===
# npm install fragt sonst bei jedem Build die Registry ab und braucht Minuten,
# obwohl alles aktuell ist. Der Fingerabdruck aus package.json + package-lock.json
# im Stempel entscheidet, ob wirklich etwas zu tun ist.
deps_fingerprint() {
    cat frontend/package.json frontend/package-lock.json 2>/dev/null | sha256sum | cut -d' ' -f1
}

ensure_deps() {
    local stamp="frontend/node_modules/.hugocms-deps-stamp"
    if [ -d frontend/node_modules ] && [ -f "$stamp" ] \
       && [ "$(cat "$stamp" 2>/dev/null)" = "$(deps_fingerprint)" ]; then
        echo "Dependencies unverändert — npm install übersprungen."
        return 0
    fi
    (cd frontend && npm install --no-audit --no-fund --prefer-offline) || return 1
    # Nach der Installation neu stempeln — npm schreibt das Lockfile mitunter um.
    deps_fingerprint > "$stamp"
}

# === Optionaler Git-Abgleich (nur falls Repo) ===
if [ -d .git ]; then
    echo "0. Aktuelles Repository aktualisieren..."
    git pull
    echo ""
fi

ensure_node

# === Projekt-Statistiken ===
echo ""
echo "=== Projekt-Statistiken ==="
FE_FILES=$(find frontend/src \( -name '*.vue' -o -name '*.js' \) 2>/dev/null | wc -l)
FE_LINES=$(find frontend/src \( -name '*.vue' -o -name '*.js' \) 2>/dev/null | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
echo "  Frontend (Vue/JS): $FE_FILES Dateien, $FE_LINES Zeilen"
echo ""

echo "1. Dependencies prüfen..."
ensure_deps || { echo "❌ npm install fehlgeschlagen!"; exit 1; }

echo ""
echo "2. Frontend bauen..."
(cd frontend && npm run build)
if [ $? -ne 0 ]; then
    echo ""
    echo "❌ Build fehlgeschlagen!"
    exit 1
fi

echo ""
echo "3. Berechtigungen setzen..."
find frontend/dist -type d -exec chmod 775 {} \; 2>/dev/null
find frontend/dist -type f -exec chmod 664 {} \; 2>/dev/null

echo ""
echo "========================================="
echo "Build erfolgreich!"
echo "========================================="
echo "Ergebnis: frontend/dist/"
echo ""
echo "Auslieferung: frontend/dist/ und index.php (samt backend/) auf den"
echo "Server legen, Datenverzeichnis möglichst außerhalb des Web-Wurzel-"
echo "verzeichnisses. VITE_API_BASE in frontend/.env.production auf den"
echo "Pfad der Connector-index.php setzen (siehe README.md)."
echo "Server-Vorlagen: beispiel-konfigurationen/"
echo "========================================="
