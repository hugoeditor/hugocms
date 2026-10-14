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
#   packaging.sh --skip-audit Sicherheitsprüfung der Abhängigkeiten auslassen
#                             (mit den anderen Flags kombinierbar).
# Ohne Flag wird also committet UND gepusht.
#
# Vor dem Build prüft 'npm audit' die Laufzeit-Abhängigkeiten des Frontends
# (nur 'dependencies' — sie landen im ausgelieferten app/; Build-Werkzeuge aus
# 'devDependencies' nicht). Bei Funden jeder Stufe (low bis critical) fragt das
# Skript nach, ob trotzdem gebaut werden soll (Default: Nein). Ohne Nachfrage wird
# NICHTS behoben: 'npm audit fix' ändert die Lock-Datei, und das Release
# entspräche keinem Commit mehr. Sind Funde automatisch behebbar, bietet die
# Nachfrage [f] an — der Fix läuft dann nur auf ausdrücklichen Wunsch.
# Nennt npm als Fix ein Update auf eine neue Hauptversion, bietet die Nachfrage
# [u] an: Die betroffenen Pakete werden installiert, das Release wird aber
# NICHT gebaut — Codeanpassung, Test und Commit folgen von Hand.
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
DO_AUDIT=1
for arg in "$@"; do
    case "$arg" in
        --no-commit)  DO_COMMIT=0; DO_PUSH=0 ;;
        --no-push)    DO_PUSH=0 ;;
        --skip-audit) DO_AUDIT=0 ;;
        -h|--help) echo "Aufruf: $0 [--no-commit | --no-push] [--skip-audit]"; exit 0 ;;
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

# 0c. Sicherheitsprüfung der Laufzeit-Abhängigkeiten (nur lesend, siehe Kopf).
#     Jeder Fund (auch low/moderate) erfordert eine Bestätigung; ohne
#     interaktives Terminal wird abgebrochen. Ist die Registry nicht erreichbar,
#     gibt es nur eine Warnung — ein Release soll daran nicht scheitern.
#     Einzelne Pakete genauer untersuchen: scripts/pkg-check.sh <paket>
#     Ist mindestens ein Fund per 'npm audit fix' behebbar, bietet die Nachfrage
#     zusätzlich [f] an: Fix ausführen, danach erneut prüfen. Das ändert
#     package-lock.json — der Release-Commit wird dann als -dev markiert.
#     Verlangt ein Fund ein Update auf eine neue Hauptversion (npm meldet
#     dann 'fixAvailable' als Objekt), bietet die Nachfrage [u] an: Die
#     betroffenen direkten Abhängigkeiten werden per 'npm install' auf die von
#     npm genannte Version gehoben. Danach wird der Release-Lauf BEWUSST
#     abgebrochen — ein Hauptversionssprung verlangt in aller Regel
#     Codeanpassungen, Tests und einen eigenen Commit. Pakete, die selbst nicht
#     verwundbar sind, aber zum Verbund gehören (z. B. @tiptap/pm), kennt das
#     Audit nicht; run_upgrade ermittelt sie über die Abhängigkeiten der
#     Zielversionen (npm view) und hebt sie mit. 'npm ls' prüft zum Schluss
#     den Baum auf Reste.

# Setzt AUDIT_STATUS: 0 = keine Funde, 1 = Funde ohne automatischen Fix,
# 2 = keine Daten, 3 = Funde, davon mindestens einer per 'npm audit fix'
# behebbar. Alle Schweregrade (low bis critical) zählen gleichermaßen.
# Füllt zusätzlich AUDIT_UPGRADES (Array "paket@version", eine Zeile je
# direkter Abhängigkeit) mit den Updates, die npm als Fix nennt, und
# AUDIT_UPGRADES_MAJOR=1, wenn darunter ein Hauptversionssprung ist.
run_audit() {
    AUDIT_UPGRADES=()
    AUDIT_UPGRADES_MAJOR=0
    if ! command -v npm &>/dev/null; then
        echo "⚠️  npm nicht gefunden — Prüfung übersprungen."
        AUDIT_STATUS=0
    else
        # npm audit endet bei Funden mit Exit-Code ≠ 0 — das ist hier kein Fehler.
        AUDIT_JSON="$(npm audit --prefix "$PROJECT_DIR/frontend" --omit=dev --json 2>/dev/null || true)"
        AUDIT_STATUS=0
        # Die Update-Liste wandert über eine Datei zurück nach Bash — die
        # Standardausgabe des Node-Skripts ist für den Menschen bestimmt.
        local upgrade_file
        upgrade_file="$(mktemp)"
        AUDIT_UPGRADE_FILE="$upgrade_file" node -e '
let data;
try { data = JSON.parse(require("fs").readFileSync(0, "utf8")); } catch { process.exit(2); }
if (data.error || !data.metadata) process.exit(2);
const c = data.metadata.vulnerabilities;
console.log(`   Gefunden: ${c.critical} critical, ${c.high} high, ${c.moderate} moderate, ${c.low} low`);
// Alle Schweregrade zählen — auch low und moderate blockieren das Release.
const hits = Object.values(data.vulnerabilities || {});
if (!hits.length) process.exit(0);
console.log("");
// Updates, die npm als Fix nennt (immer eine direkte Abhängigkeit), je Paket
// einmal. Format je Zeile: "<major|minor> <paket>@<version>".
const upgrades = new Map();
for (const v of hits) {
    const own = (v.via || []).find(x => typeof x === "object");
    const why = own ? own.title : "über " + v.via.join(", ");
    const fix = v.fixAvailable === true ? "behebbar per: npm audit fix --prefix frontend"
        : v.fixAvailable ? `Update ${v.fixAvailable.name}@${v.fixAvailable.version}`
        : "kein automatischer Fix";
    console.log(`   [${v.severity}] ${v.name} — ${why} (${fix})`);
    if (v.fixAvailable && typeof v.fixAvailable === "object") {
        const f = v.fixAvailable;
        upgrades.set(f.name, `${f.isSemVerMajor ? "major" : "minor"} ${f.name}@${f.version}`);
    }
}
require("fs").writeFileSync(process.env.AUDIT_UPGRADE_FILE, [...upgrades.values()].join("\n"));
process.exit(hits.some(v => v.fixAvailable === true) ? 3 : 1);
' <<< "$AUDIT_JSON" || AUDIT_STATUS=$?
        local kind spec
        # '|| [ -n "$spec" ]': auch die letzte Zeile ohne Zeilenumbruch lesen.
        while read -r kind spec || [ -n "$spec" ]; do
            [ -n "$spec" ] || continue
            AUDIT_UPGRADES+=("$spec")
            [ "$kind" = major ] && AUDIT_UPGRADES_MAJOR=1
        done < "$upgrade_file"
        rm -f "$upgrade_file"
    fi
}

# Hebt die vom Audit genannten direkten Abhängigkeiten auf die Zielversion
# (AUDIT_UPGRADES) und beendet das Skript — siehe Kommentar zu [u] oben.
run_upgrade() {
    # Zugehörige Pakete: Die Zielpakete verlangen — auch über Zwischenstufen,
    # z. B. starter-kit → core → pm — bestimmte Versionen anderer Pakete. Ist
    # so ein Paket im Projekt eine DIREKTE Abhängigkeit, die die verlangte
    # Spanne nicht erfüllt, muss es im selben Install-Aufruf mitgehoben werden,
    # sonst scheitert npm mit ERESOLVE. Nicht-direkte Pakete installiert npm
    # von selbst passend.
    # Durchlaufen werden Peer-Abhängigkeiten immer, reguläre Abhängigkeiten nur
    # innerhalb derselben Paketfamilie (gleicher Scope wie das Elternpaket oder
    # direkte Projektabhängigkeit) — sonst liefe die Suche durch den gesamten
    # Baum (ProseMirror, linkify, …), und dort nistet npm Versionen ohnehin.
    # Die Prüfung "erfüllt die Spanne?" läuft ohne semver-Bibliothek: npm view
    # liefert alle Versionen der Spanne; steht die installierte darunter, passt sie.
    echo ""
    echo "Zugehörige Pakete über Abhängigkeiten der Zielversionen ermitteln (npm view, dauert etwas)..."
    local spec reason
    while IFS=$'\t' read -r spec reason || [ -n "$spec" ]; do
        [ -n "$spec" ] || continue
        AUDIT_UPGRADES+=("$spec")
        echo "   + $spec ($reason)"
    done < <(FRONTEND_DIR="$PROJECT_DIR/frontend" node -e '
const { execFileSync } = require("child_process");
const fs = require("fs");
const dir = process.env.FRONTEND_DIR;
const direct = JSON.parse(fs.readFileSync(dir + "/package.json", "utf8")).dependencies || {};
let installed = {};
try { installed = JSON.parse(fs.readFileSync(dir + "/package-lock.json", "utf8")).packages || {}; } catch {}
// npm view <spec> [feld] --json; null bei Fehler oder leerer Antwort. Trifft
// die Spezifikation mehrere Versionen, antwortet npm mit einem Array — dann
// zählt der letzte (höchste) Eintrag. Ergebnisse werden je Aufruf gemerkt.
const cache = new Map();
const view = (...args) => {
    const key = args.join(" ");
    if (cache.has(key)) return cache.get(key);
    let result = null;
    try {
        const out = execFileSync("npm", ["view", ...args, "--json"],
            { stdio: ["ignore", "pipe", "ignore"], encoding: "utf8" }).trim();
        if (out) result = JSON.parse(out);
    } catch {}
    cache.set(key, result);
    return result;
};
const last = (v) => Array.isArray(v) ? v[v.length - 1] : v;
const isExact = (range) => /^\d+\.\d+\.\d+(-[\w.]+)?$/.test(range);
const scopeOf = (name) => name.startsWith("@") ? name.split("/")[0] : null;
const split = (s) => { const i = s.lastIndexOf("@"); return [s.slice(0, i), s.slice(i + 1)]; };

const targets = new Map(process.argv.slice(1).map(split));
const queue = [...targets];
const seen = new Set();
while (queue.length) {
    const [name, version] = queue.shift();
    const key = `${name}@${version}`;
    if (seen.has(key)) continue;
    seen.add(key);
    const manifest = last(view(key));
    if (!manifest || typeof manifest !== "object") continue;
    const edges = [
        ...Object.entries(manifest.peerDependencies || {}).map(([n, r]) => [n, r, true]),
        ...Object.entries(manifest.dependencies || {}).map(([n, r]) => [n, r, false]),
    ];
    for (const [dep, range, isPeer] of edges) {
        const isDirect = dep in direct;
        if (!isPeer && !isDirect && scopeOf(dep) !== scopeOf(name)) continue;
        const current = installed["node_modules/" + dep]?.version;
        let resolved, satisfied;
        if (isExact(range)) {
            resolved = range;
            satisfied = current === range;
        } else {
            let matching = view(`${dep}@${range}`, "version");
            if (matching == null) continue;
            if (!Array.isArray(matching)) matching = [matching];
            resolved = matching[matching.length - 1];
            satisfied = matching.includes(current);
        }
        if (isDirect && !targets.has(dep) && !satisfied) {
            targets.set(dep, resolved);
            process.stdout.write(`${dep}@${resolved}\t${isPeer ? "Peer-Abhängigkeit" : "Abhängigkeit"} von ${name}\n`);
        }
        queue.push([dep, isDirect && satisfied ? current : resolved]);
    }
}
' "${AUDIT_UPGRADES[@]}")

    echo ""
    echo "Folgende Pakete werden auf die von npm genannte Version gehoben:"
    local spec
    for spec in "${AUDIT_UPGRADES[@]}"; do
        echo "   $spec"
    done
    if [ "$AUDIT_UPGRADES_MAJOR" = 1 ]; then
        echo "   Mindestens ein Hauptversionssprung — Codeanpassungen sind zu erwarten."
    fi
    echo "   Das ändert package.json und package-lock.json; der Release-Lauf"
    echo "   wird danach beendet."
    printf 'Update ausführen? [j/N] '
    local reply
    read -r reply || reply=""
    case "$reply" in
        [jJyY]*) ;;
        *) echo "Abgebrochen — kein Update, kein Release."; exit 1 ;;
    esac

    # Zwei Schritte statt eines 'npm install': Wechselt eine Familie über Peer-
    # Abhängigkeiten gekoppelter Pakete gemeinsam die Hauptversion, prüft npm
    # jedes neue Paket gegen den noch alten Baum und scheitert mit ERESOLVE —
    # auch wenn alle Zielversionen zusammen angegeben sind. Werden die alten
    # Versionen erst entfernt, löst npm die neuen widerspruchsfrei auf; alle
    # übrigen Pakete behalten ihren Stand aus package-lock.json.
    local names=()
    for spec in "${AUDIT_UPGRADES[@]}"; do
        names+=("${spec%@*}")
    done
    echo ""
    echo "npm uninstall --prefix frontend ${names[*]} ..."
    if ! npm uninstall --prefix "$PROJECT_DIR/frontend" "${names[@]}"; then
        echo "❌ npm uninstall endete mit Fehler — Stand wiederherstellen:"
        echo "   git checkout -- frontend/package.json frontend/package-lock.json"
        echo "   npm ci --prefix frontend"
        exit 1
    fi
    echo ""
    echo "npm install --prefix frontend ${AUDIT_UPGRADES[*]} ..."
    if ! npm install --prefix "$PROJECT_DIR/frontend" "${AUDIT_UPGRADES[@]}"; then
        echo "❌ npm install endete mit Fehler — Stand wiederherstellen:"
        echo "   git checkout -- frontend/package.json frontend/package-lock.json"
        echo "   npm ci --prefix frontend"
        exit 1
    fi

    # Sicherheitsnetz: Verbundpakete, die die Suche oben nicht erfasst hat
    # (etwa außerhalb des Scopes), fallen hier als unerfüllte Peer-Abhängigkeit
    # oder ungültige Version auf.
    echo ""
    echo "Prüfung des Abhängigkeitsbaums (npm ls)..."
    local ls_out
    if ls_out="$(npm ls --prefix "$PROJECT_DIR/frontend" --omit=dev --depth=0 2>&1)"; then
        echo "   Abhängigkeitsbaum ist konsistent."
    else
        echo "⚠️  npm ls meldet Probleme — vermutlich fehlen zugehörige Pakete, die"
        echo "   die Suche oben nicht erfasst hat:"
        echo "$ls_out" | grep -iE "invalid|missing|peer|ERESOLVE|ELSPROBLEMS" | sed 's/^/   /' || true
        echo "   Diese Pakete von Hand auf dieselbe Hauptversion heben:"
        echo "   npm install --prefix frontend <paket>@<version>"
    fi

    echo ""
    echo "Update eingespielt — kein Release erzeugt. Nächste Schritte:"
    echo "   1. Code an die neue Version anpassen (bei Hauptversionssprung die"
    echo "      Migrationshinweise des Pakets beachten)."
    echo "   2. scripts/build.sh ausführen und die Anwendung testen."
    echo "   3. Änderungen committen."
    echo "   4. $0 erneut starten."
    exit 1
}

if [ "$DO_AUDIT" = 1 ]; then
    echo "0c. Sicherheitsprüfung der Laufzeit-Abhängigkeiten (npm audit --omit=dev)..."
    FIX_TRIED=0
    while true; do
        run_audit
        case "$AUDIT_STATUS" in
            0) echo "   Keine Sicherheitsfunde."; break ;;
            2) echo "⚠️  npm audit lieferte keine Daten (Registry nicht erreichbar?) — Prüfung übersprungen."; break ;;
        esac

        echo ""
        echo "   Details je Paket: scripts/pkg-check.sh <paket>"
        if [ ! -t 0 ]; then
            echo "❌ Sicherheitsfunde und kein interaktives Terminal — Abbruch."
            echo "   Bewusst trotzdem bauen: $0 --skip-audit"
            exit 1
        fi
        # [f] nur anbieten, wenn ein Fund automatisch behebbar ist und der Fix
        # in diesem Lauf noch nicht versucht wurde (sonst Endlosschleife).
        # [u] nur, wenn npm ein konkretes Update einer direkten Abhängigkeit
        # nennt (siehe run_upgrade).
        OFFER_FIX=0
        OFFER_UPGRADE=0
        PROMPT='Sicherheitsfunde — trotzdem bauen'
        KEYS='j'
        if [ "$AUDIT_STATUS" = 3 ] && [ "$FIX_TRIED" = 0 ]; then
            OFFER_FIX=1
            PROMPT="$PROMPT, f = npm audit fix ausführen"
            KEYS="$KEYS/f"
        fi
        if [ "${#AUDIT_UPGRADES[@]}" -gt 0 ]; then
            OFFER_UPGRADE=1
            PROMPT="$PROMPT, u = Pakete auf die genannte Version heben (danach Abbruch)"
            KEYS="$KEYS/u"
        fi
        printf '%s? [%s/N] ' "$PROMPT" "$KEYS"
        read -r reply || reply=""
        case "$reply" in
            [jJyY]*) echo "Fortgesetzt trotz Sicherheitsfunden."; break ;;
            [fF]*)
                if [ "$OFFER_FIX" = 1 ]; then
                    FIX_TRIED=1
                    echo "npm audit fix --prefix frontend ..."
                    if ! npm audit fix --prefix "$PROJECT_DIR/frontend"; then
                        echo "⚠️  npm audit fix endete mit Fehler — es wird erneut geprüft."
                    fi
                    echo ""
                    echo "Erneute Sicherheitsprüfung nach dem Fix..."
                    continue
                fi
                echo "Abgebrochen — kein Release erzeugt."; exit 1 ;;
            [uU]*)
                if [ "$OFFER_UPGRADE" = 1 ]; then
                    run_upgrade
                fi
                echo "Abgebrochen — kein Release erzeugt."; exit 1 ;;
            *) echo "Abgebrochen — kein Release erzeugt."; exit 1 ;;
        esac
    done
    echo ""
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
    #
    # Der Client kommt ohne 'unsafe-eval' aus: Die einzige Stelle, die Code zur
    # Laufzeit erzeugt hätte, war der Nachrichten-Compiler von vue-i18n — er ist
    # durch src/i18n/messageCompiler.js ersetzt und gar nicht erst im Bündel.
    # Einzige Lockerung ist 'unsafe-inline' bei style-src: Vuetify und eigene
    # :style-Bindungen setzen laufend style-Attribute.
    #
    # Setzt die Webseite "require-trusted-types-for 'script'", wird das hier
    # mit ersetzt — die App könnte sonst nicht laufen.
    Header unset Content-Security-Policy
    Header always unset Content-Security-Policy
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
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
echo "4. Laufzeitverzeichnisse sicherstellen (backend/log/, backend/var/sessions/, backend/mounts/; .htaccess für log/ und var/)"
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

cat > "$PKG/backend/var/.htaccess" <<'HT'
# Apache: kein direkter Zugriff auf die Laufzeitdaten. Hier liegen Sitzungen,
# Freigabe-Entwürfe, Berichte und die gebauten Seitenvorschauen — letztere
# zeigen unveröffentlichte Stände und dürfen weder abrufbar noch auffindbar
# sein. Normalerweise liegt backend/ ohnehin außerhalb des Web-Wurzel-
# verzeichnisses; diese Datei sichert die Fälle ab, in denen das nicht so ist.
# Nginx wertet sie NICHT aus — dort den location-Block aus
# beispiel-konfigurationen/nginx.conf verwenden.
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
