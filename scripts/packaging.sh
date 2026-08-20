#!/bin/bash
# packaging.sh - Aktualisiert das Auslieferungs-Repo unter ./hugocms-release/
# mit der neusten Version von Client (Frontend-Build) und Backend.
#
# Erzeugt/erneuert die Struktur direkt im Repo-Wurzelverzeichnis:
#   hugocms-release/
#   ├── app/                 (gebautes Frontend = Client; inkl. .htaccess für
#   │                         Caching und CSP der App — eine im Wurzel-
#   │                         verzeichnis, eine in assets/; URL-Pfad via
#   │                         Installationsroutine)
#   ├── bin/
#   │     ├── get-hugo.sh          (lädt das Hugo-Binary nach bin/hugo/)
#   │     ├── install.sh           (richtet eine Webseite ein: Kopie + mounts/<hash>.ini)
#   │     ├── update.sh            (erneuert alle Webseiten nach einem Release)
#   │     ├── crontab-entries.sh   (gibt die Crontab-Zeilen für alle Webseiten aus)
#   │     └── lib/                 (gemeinsame Bash-Bibliothek: deploy.sh)
#   │     (hugo/ wird NICHT mitgeliefert — install.sh holt es per get-hugo.sh;
#   │      hooks/ ebenso wenig — Git-Hooks gehören zur Entwicklung an HugoCMS)
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
#   packaging.sh              bauen, im Release-Repo committen UND pushen
#                             (Standard; Commit-Message aus dem Quell-Commit).
#   packaging.sh --no-push    bauen und committen, aber NICHT pushen.
#   packaging.sh --no-commit  nur bauen; weder committen noch pushen (danach
#                             'git status' des Release-Repos).
# Ohne Flag wird also committet UND gepusht.
#
# Zum Schluss wird zusätzlich die vom Release-Build hochgezählte Buildnummer
# (frontend/build-number.json) im QUELL-Repo mit der Message
# "Neues Build erzeugt #<nummer>" committet und gepusht — gesteuert über
# dieselben Flags (--no-commit unterdrückt beides, --no-push nur den Push).

set -euo pipefail

# --- Argumente -------------------------------------------------------------
# Standard: committen und pushen. --no-push unterdrückt nur den Push,
# --no-commit unterdrückt beides.
DO_COMMIT=1
DO_PUSH=1
for arg in "$@"; do
    case "$arg" in
        --no-commit) DO_COMMIT=0; DO_PUSH=0 ;;
        --no-push)   DO_PUSH=0 ;;
        -h|--help) echo "Aufruf: $0 [--no-commit | --no-push]"; exit 0 ;;
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
#    HUGOCMS_RELEASE=1 markiert den Lauf als Release-Build: Nur dann zählt Vite
#    die Buildnummer hoch (frontend/build-number.json). Reine Dev-/Test-Builds
#    ohne diese Variable lassen den Zähler unverändert.
echo "1. Frontend bauen..."
HUGOCMS_RELEASE=1 "$SCRIPT_DIR/build.sh"
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

# 2b. Header-Steuerung fürs App-Verzeichnis (Apache). Die App-Hülle wird unter
#     /edit/ ausgeliefert; install.sh kopiert app/ dorthin (cp -a, inkl. Dot-
#     dateien). Zwei Dateien, weil zwei Regeln gelten: im Wurzelverzeichnis der
#     App die CSP und der no-cache für index.html, in assets/ das dauerhafte
#     Zwischenspeichern der gehashten Dateien. Nginx wertet beide NICHT aus —
#     dort die location-Blöcke aus beispiel-konfigurationen/nginx.conf
#     verwenden.
echo "2b. Header-.htaccess -> $PKG/app/.htaccess + $PKG/app/assets/.htaccess"
cat > "$PKG/app/.htaccess" <<'HT'
# App-Hülle (URL /edit/) — Caching und Content-Security-Policy.
#
# Nginx wertet diese Datei NICHT aus — dort die location-Blöcke aus
# beispiel-konfigurationen/nginx.conf verwenden.
#
# ----------------------------------------------------------------------------
# Warum jede Regel hier mit "unset" beginnt
# ----------------------------------------------------------------------------
# Apache führt zwei Header-Tabellen: "Header set" schreibt in die eine (nur bei
# erfolgreichen Antworten), "Header always set" in die andere (bei allen).
# Beide werden bei einer 200er-Antwort gesendet. Setzt die Webseite denselben
# Header in der anderen Tabelle als diese Datei, bekommt der Browser ZWEI
# Header — bei der CSP gilt dann deren Schnittmenge, die Ausnahme für die App
# bliebe wirkungslos.
#
# Deshalb werden beide Tabellen zuerst geleert und der Wert dann neu gesetzt.
# Das Ergebnis hängt damit NICHT davon ab, wie die Webseite ihre eigenen Header
# setzt: Diese Datei ist für alle Installationen identisch und muss nie
# angepasst werden, wenn sich die Konfiguration der Webseite ändert.
<IfModule mod_headers.c>
    # index.html bei jedem Laden revalidieren (der Browser prüft per ETag),
    # damit ein neuer Build sofort erscheint. Ohne diese Regel erbt die Datei
    # die Cache-Vorgabe der Webseite — steht dort ein langes max-age, erreicht
    # ein Update den Browser praktisch nie. Winzige Datei, vernachlässigbare
    # Kosten. Die gehashten Dateien regelt assets/.htaccess.
    <Files "index.html">
        Header unset Cache-Control
        Header always unset Cache-Control
        Header always set Cache-Control "no-cache"
    </Files>

    # Die App ist ein eigenständiges Programm, keine Seite der Webseite — ihre
    # CSP steht deshalb hier und nicht in der Webseiten-Konfiguration.
    # Zu den beiden Lockerungen:
    #   'unsafe-inline' bei style-src — Vuetify und eigene :style-Bindungen
    #   setzen laufend style-Attribute.
    #   'unsafe-eval' bei script-src — der Nachrichten-Compiler von vue-i18n
    #   erzeugt die Übersetzungsfunktionen zur Laufzeit über Function().
    # Setzt die Webseite "require-trusted-types-for 'script'", wird das hier
    # mit ersetzt — die App könnte sonst nicht laufen.
    Header unset Content-Security-Policy
    Header always unset Content-Security-Policy
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
</IfModule>
HT

mkdir -p "$PKG/app/assets"
cat > "$PKG/app/assets/.htaccess" <<'HT'
# Gebaute Dateien der App (URL /edit/assets/) — dauerhaft zwischenspeichern.
#
# Jede Datei hier trägt den Hash ihres Inhalts im Namen (index-Cp6X38FY.js);
# ändert sich der Inhalt, ändert sich der Name, und die stets frisch geladene
# index.html verweist auf den neuen. Ein Update kann also nicht hängen bleiben,
# und der Browser spart sich bei jedem Start die Rückfragen.
#
# Bewusst dieses Verzeichnis statt einer Regel über Dateiendungen im
# übergeordneten Verzeichnis: Nur was hier liegt, ist gehasht. Eine Datei aus
# frontend/public/ landet ungehasht im Wurzelverzeichnis der App und würde von
# einer Endungsregel ein Jahr lang festgehalten.
#
# Zum "unset" siehe die Erläuterung in ../.htaccess. Die dortigen CSP-Regeln
# gelten hier mit; nur Cache-Control wird überschrieben.
<IfModule mod_headers.c>
    Header unset Cache-Control
    Header always unset Cache-Control
    Header always set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
HT

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
#     Produktivsystem nach (bin/hugo/ ist in beiden Repos ignoriert). Die
#     Git-Hooks bleiben ebenfalls draußen: Sie gehören zur Entwicklung an
#     HugoCMS, im Auslieferungs-Repo wird nicht committet.
echo "5b. bin -> $PKG/bin (ohne Hugo-Binary und Git-Hooks)"
rm -rf "$PKG/bin"
cp -r "$PROJECT_DIR/bin" "$PKG/bin"
rm -rf "$PKG/bin/hugo" "$PKG/bin/hooks"
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
    # Die vom Release-Build selbst hochgezählte Buildnummer (build-number.json)
    # ist dabei zu erwarten und gilt nicht als "unsauber" — sie wird mit dem
    # nächsten regulären Commit übernommen.
    SRC_REV="$(git -C "$PROJECT_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
    SRC_SUBJ="$(git -C "$PROJECT_DIR" log -1 --pretty=%s 2>/dev/null || echo 'Release')"
    if ! git -C "$PROJECT_DIR" diff --quiet HEAD -- . ':(exclude)frontend/build-number.json' 2>/dev/null; then
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
    echo "Kein Commit (--no-commit). Zum manuellen Übernehmen:"
    echo "  git -C '$PKG_REPO' add -A && git -C '$PKG_REPO' commit"
fi

# 7. Hochgezählte Buildnummer im QUELL-Repo festschreiben. Der Release-Build
#    (Schritt 1) erhöht die versionierte Datei frontend/build-number.json; sie
#    bleibt sonst als loser Arbeitsbaum-Rest liegen (deshalb wird sie oben aus
#    der Sauberkeitsprüfung ausgenommen). Hier bekommt nur diese Datei einen
#    eigenen Commit — bewusst NACH dem Release-Commit, damit dessen Betreff aus
#    dem echten Feature-Commit stammt, nicht aus dieser Buildnummer. Gleiche
#    Flags wie das Release-Repo: --no-commit unterdrückt beides, --no-push den Push.
BUILD_FILE="$PROJECT_DIR/frontend/build-number.json"
if [ "$DO_COMMIT" = 1 ] && [ -f "$BUILD_FILE" ]; then
    if git -C "$PROJECT_DIR" diff --quiet HEAD -- frontend/build-number.json; then
        echo "Quell-Repo: Buildnummer unverändert — kein Commit nötig."
    else
        BUILD_NO="$(grep -oE '[0-9]+' "$BUILD_FILE" | head -n1)"
        # Nur diese eine Datei festschreiben (Pathspec), unabhängig von anderen
        # Änderungen im Arbeitsbaum oder Index.
        git -C "$PROJECT_DIR" commit -q -m "Neues Build erzeugt #${BUILD_NO}" -- frontend/build-number.json
        echo "Quell-Repo: Buildnummer #${BUILD_NO} committet."
        if [ "$DO_PUSH" = 1 ]; then
            echo "Push (Quell-Repo)…"
            git -C "$PROJECT_DIR" push
        fi
    fi
fi
echo "========================================="
