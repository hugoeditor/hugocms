#!/bin/bash
# dev.sh - Startet die Entwicklungsumgebung (PHP-Connector + Vite-Dev-Server)

# === KONFIGURATION ===
TERMINAL_WIDTH=250
TERMINAL_HEIGHT=40

PHP_HOST="127.0.0.1"
PHP_PORT="8765"
VITE_PORT="5173"

# Projektverzeichnis = eine Ebene über /scripts
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR" || exit 1

# Beim Selbstaufruf im neuen Terminal-Fenster entfallen alle Vorarbeiten
# (Prüfungen, Git-Abgleich, Statistiken, npm) — sie liefen bereits im Elternlauf.
IS_CHILD=0
[ "$1" = "--run-servers" ] && IS_CHILD=1

# === PHP prüfen (mindestens 8.1) ===
if [ $IS_CHILD -eq 0 ] && ! command -v php &> /dev/null; then
    echo "ERROR: PHP nicht gefunden. Bitte installieren:"
    echo "  sudo apt install php-cli php-mbstring php-xml php-gd"
    exit 1
fi
PHP_VERSION=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 0.0)
PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
PHP_MINOR=$(echo "$PHP_VERSION" | cut -d. -f2)
if [ $IS_CHILD -eq 0 ] && { [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 1 ]; }; }; then
    echo "ERROR: PHP 8.1 oder höher erforderlich, gefunden: $PHP_VERSION"
    exit 1
fi

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
        echo "  Installation z. B. über nvm: https://github.com/nvm-sh/nvm"
        exit 1
    fi
    echo "Using Node: $(node --version)"
}

# === Frontend-Abhängigkeiten (nur bei Änderung installieren) ===
# npm install fragt sonst bei jedem Start die Registry ab und braucht Minuten,
# obwohl alles aktuell ist. Der Fingerabdruck aus package.json + package-lock.json
# im Stempel entscheidet, ob wirklich etwas zu tun ist.
deps_fingerprint() {
    cat frontend/package.json frontend/package-lock.json 2>/dev/null | sha256sum | cut -d' ' -f1
}

ensure_deps() {
    local stamp="frontend/node_modules/.hugocms-deps-stamp"
    if [ -d frontend/node_modules ] && [ -f "$stamp" ] \
       && [ "$(cat "$stamp" 2>/dev/null)" = "$(deps_fingerprint)" ]; then
        echo "Frontend dependencies up to date (skipping npm install)."
        return 0
    fi
    echo "Installing frontend dependencies..."
    (cd frontend && npm install --no-audit --no-fund --prefer-offline) || return 1
    # Nach der Installation neu stempeln — npm schreibt das Lockfile mitunter um.
    deps_fingerprint > "$stamp"
}

# === Optionaler Git-Abgleich (nur falls Repo) ===
if [ $IS_CHILD -eq 0 ] && [ -d .git ]; then
    echo "=== Updating from Git ==="
    git pull || echo "WARNUNG: git pull fehlgeschlagen, fahre fort."
    echo ""
fi

ensure_node

if [ $IS_CHILD -eq 0 ]; then
    echo "Using PHP: $(php -v | head -n 1)"

    # === Projekt-Statistiken ===
    echo ""
    echo "=== Projekt-Statistiken ==="
    FE_FILES=$(find frontend/src \( -name '*.vue' -o -name '*.js' \) 2>/dev/null | wc -l)
    FE_LINES=$(find frontend/src \( -name '*.vue' -o -name '*.js' \) 2>/dev/null | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
    BE_FILES=$(find backend -name '*.php' 2>/dev/null | wc -l)
    BE_LINES=$(find backend -name '*.php' 2>/dev/null | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
    echo "  Frontend (Vue/JS): $FE_FILES Dateien, $FE_LINES Zeilen"
    echo "  Backend (PHP):     $BE_FILES Dateien, $BE_LINES Zeilen"
    echo ""

    ensure_deps
fi

# === Server-Start-Funktion ===
run_servers() {
    echo "=== HugoCMS Development Environment ==="
    echo ""

    # Stoppe eventuell laufende Server
    echo "Stopping old servers..."
    pkill -f "php -S $PHP_HOST:$PHP_PORT" 2>/dev/null
    pkill -f "vite" 2>/dev/null
    sleep 1

    # Anwendungslog vorbereiten und mitlesen
    mkdir -p log
    touch log/hugocms.log 2>/dev/null || true
    tail -n 0 -f log/hugocms.log 2>/dev/null | while read -r line; do
        echo "[LOG] $line"
    done &
    TAIL_PID=$!

    # Starte PHP-Connector im Hintergrund
    echo "Starting PHP Connector on http://$PHP_HOST:$PHP_PORT ..."
    php -d post_max_size=64M -d upload_max_filesize=64M -S "$PHP_HOST:$PHP_PORT" index.php 2>&1 | while read -r line; do
        echo "[PHP] $line"
    done &
    PHP_PID=$!

    sleep 1

    # Starte Vite-Dev-Server
    echo "Starting Vite Dev Server on http://localhost:$VITE_PORT ..."
    (cd frontend && npm run dev) 2>&1 | while read -r line; do
        echo "[VITE] $line"
    done &
    NPM_PID=$!

    CLEANING_UP=0
    cleanup() {
        [ $CLEANING_UP -eq 1 ] && return
        CLEANING_UP=1
        echo ""
        echo "=== Stopping Servers ==="
        kill $PHP_PID $NPM_PID $TAIL_PID 2>/dev/null
        pkill -f "php -S $PHP_HOST:$PHP_PORT" 2>/dev/null
        pkill -f "vite" 2>/dev/null
        exit 0
    }
    trap cleanup SIGINT SIGTERM

    echo ""
    echo "=== Bereit ==="
    echo "  Oberfläche:  http://localhost:$VITE_PORT"
    echo "  Anmeldung:   admin / geheim (aus der Beispiel-index.php)"
    echo "  Beenden mit Strg+C"
    echo ""

    wait
}

# Starte Server — in gnome-terminal falls GUI vorhanden, sonst direkt im Terminal
if [ "$1" = "--run-servers" ]; then
    run_servers
elif command -v gnome-terminal &>/dev/null && [ -n "$DISPLAY" ]; then
    gnome-terminal --title="HugoCMS" --geometry=${TERMINAL_WIDTH}x${TERMINAL_HEIGHT} -- bash -c "cd $PROJECT_DIR && bash scripts/dev.sh --run-servers; exec bash"
else
    run_servers
fi
