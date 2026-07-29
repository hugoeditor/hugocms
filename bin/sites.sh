#!/bin/bash
# sites.sh — Zeigt die eingerichteten Webseiten (Mandanten) einer HugoCMS-
# Installation. Jede Webseite ist eine Mount-Datei backend/mounts/<hash>.ini
# (Hash wie scripts/site-hash.sh); der Rückfall backend/mounts.ini gilt als
# eigene "Webseite" ohne Host-Bindung.
#
# Aufruf:
#     bin/sites.sh -l                 alle eingerichteten Webseiten auflisten
#     bin/sites.sh -i <kennung>       Detailangaben zu EINER Webseite
#     bin/sites.sh -h | --help        diese Hilfe
#
#   <kennung> bei -i ist wahlweise:
#     • die Domain/der Host        z. B.  kunde-a.example.com
#     • die volle Site-Kennung     z. B.  kunde-a.example.com/cms-api
#     • der Hash bzw. Dateiname    z. B.  3f2a…  oder  3f2a….ini
#     • mounts (bzw. mounts.ini)   für den Rückfall backend/mounts.ini
#   Der Host wird aus dem Kopfkommentar der Mount-Datei gelesen (install.sh
#   schreibt dort "; HugoCMS – Mounts für <HOST> …"); der Hash im Dateinamen
#   ist nicht umkehrbar.
#
# Die Detailansicht nennt die Mounts (Pfad, Rechte, Endungen), die aktiven
# Zusatzfunktionen (Hugo-Veröffentlichung, PageSpeed, Live-Analyse, Auto-
# Verbessern, Cron-Pausen, Git) sowie WELCHE Schlüssel hinterlegt sind — die
# Werte selbst werden NIE ausgegeben (Passwort-Hash, Lizenz-, API- und
# SMTP-Schlüssel bleiben verborgen).
#
# Das Skript liegt im bin/ des Release-Repos und ermittelt dessen Wurzel
# relativ zu sich selbst — das Repo darf an beliebiger Stelle liegen.

set -euo pipefail

# --- Release-Wurzel relativ zum Skript -------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND_DIR="$PKG_ROOT/backend"
# MOUNTS_DIR/CONFIG_FILE lassen sich für Tests über die Umgebung vorgeben;
# sonst gilt das Release-Layout.
MOUNTS_DIR="${MOUNTS_DIR:-$BACKEND_DIR/mounts}"
CONFIG_FILE="${CONFIG_FILE:-$BACKEND_DIR/hugocms.ini}"
# Rückfall mounts.ini liegt neben dem mounts/-Verzeichnis (Standard:
# backend/mounts.ini); zieht bei einer MOUNTS_DIR-Vorgabe mit um.
FALLBACK_MOUNTS="${FALLBACK_MOUNTS:-$(dirname "$MOUNTS_DIR")/mounts.ini}"

# read_ini_value aus derselben Bibliothek wie install.sh/update.sh (kein
# doppelter INI-Leser). API_DIR liefert den Standard-Endpunktpfad für die
# Hash-Verifikation. deploy_app wird hier nicht benutzt.
source "$SCRIPT_DIR/lib/deploy.sh"

usage() { sed -n '2,34p' "$0" | sed 's/^#\( \|$\)//'; }

# Reservierte Sektionen (KEINE Mounts) — siehe mounts.ini.beispiel.
is_reserved() {
    case "$1" in
        hugo|license|pagespeed|live_analysis|seo_report|improve|cron|git) return 0 ;;
        *) return 1 ;;
    esac
}

# Host aus dem Kopfkommentar der Mount-Datei lesen (install.sh-Format).
read_mount_host() {
    sed -n 's/.*Mounts für \(.*\) (von install\.sh.*/\1/p' "$1" | head -n1
}

# Alle Sektionsnamen einer INI-Datei ausgeben (Reihenfolge wie in der Datei).
ini_sections() {
    awk '/^[[:space:]]*\[/ { s=$0; sub(/^[[:space:]]*\[/,"",s); sub(/\].*/,"",s); print s }' "$1"
}

# Zahl der Mount-Sektionen (alle außer den reservierten).
count_mounts() {
    local n=0 s
    while IFS= read -r s; do
        [ -z "$s" ] && continue
        is_reserved "$s" || n=$((n + 1))
    done < <(ini_sections "$1")
    printf '%s' "$n"
}

# Statuszeile eines Schlüssels: hinterlegt (mit Länge) ODER nicht gesetzt —
# der Wert selbst wird nie ausgegeben.
key_status() {
    if [ -n "$1" ]; then printf '✓ hinterlegt (%d Zeichen)' "${#1}"
    else printf '– nicht gesetzt'; fi
}

# Lizenzstatus einer Mount-Datei als Wort.
license_word() {
    if [ -n "$(read_ini_value "$1" license key)" ]; then printf 'Pro'; else printf 'Community'; fi
}

# --- Auflistung (-l) -------------------------------------------------------
list_sites() {
    shopt -s nullglob
    local mounts=("$MOUNTS_DIR"/*.ini)
    local have=0
    if [ "${#mounts[@]}" -eq 0 ] && [ ! -f "$FALLBACK_MOUNTS" ]; then
        echo "Keine Webseite eingerichtet (keine Mount-Datei in $MOUNTS_DIR" >&2
        echo "und kein Rückfall $FALLBACK_MOUNTS)." >&2
        return 1
    fi

    printf 'HugoCMS – Eingerichtete Webseiten\n'
    printf 'Verzeichnis: %s\n\n' "$MOUNTS_DIR"
    printf '%-34s %-10s %6s  %s\n' "HOST/DOMAIN" "LIZENZ" "MOUNTS" "DATEI"
    printf '%-34s %-10s %6s  %s\n' "----------------------------------" "----------" "------" "----------------------------------"

    local f host lic n
    for f in "${mounts[@]}"; do
        host="$(read_mount_host "$f")"
        lic="$(license_word "$f")"
        n="$(count_mounts "$f")"
        printf '%-34s %-10s %6s  %s\n' "${host:-(Host unbekannt)}" "$lic" "$n" "$(basename "$f")"
        have=1
    done

    if [ -f "$FALLBACK_MOUNTS" ]; then
        lic="$(license_word "$FALLBACK_MOUNTS")"
        n="$(count_mounts "$FALLBACK_MOUNTS")"
        printf '%-34s %-10s %6s  %s\n' "(Rückfall, ohne Host)" "$lic" "$n" "mounts.ini"
        have=1
    fi

    echo ""
    if [ "$have" = 1 ]; then
        printf 'Detailangaben:  bin/sites.sh -i <host>\n'
    fi
}

# --- Mount-Datei zu einer Kennung finden -----------------------------------
# Gibt bei Erfolg den Dateipfad auf stdout aus, sonst leere Zeichenkette.
resolve_site() {
    local q="$1"

    # 1. Rückfall ausdrücklich angesprochen.
    case "$q" in
        mounts|mounts.ini) [ -f "$FALLBACK_MOUNTS" ] && { printf '%s' "$FALLBACK_MOUNTS"; return 0; }; return 1 ;;
    esac

    # 2. Dateiname/Hash direkt.
    local cand="$MOUNTS_DIR/${q%.ini}.ini"
    [ -f "$cand" ] && { printf '%s' "$cand"; return 0; }

    # 3. Volle Site-Kennung mit Endpunktpfad → Hash berechnen.
    if printf '%s' "$q" | grep -q '/'; then
        local h; h="$(printf '%s' "$q" | sha256sum | cut -d' ' -f1)"
        cand="$MOUNTS_DIR/${h}.ini"
        [ -f "$cand" ] && { printf '%s' "$cand"; return 0; }
    fi

    # 4. Host aus dem Kopfkommentar — exakt (ohne Groß-/Kleinschreibung).
    shopt -s nullglob
    local f host ql; ql="$(printf '%s' "$q" | tr '[:upper:]' '[:lower:]')"
    for f in "$MOUNTS_DIR"/*.ini; do
        host="$(read_mount_host "$f")"
        [ -z "$host" ] && continue
        [ "$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')" = "$ql" ] && { printf '%s' "$f"; return 0; }
    done

    # 5. Host als Teilzeichenkette (Komfort bei eindeutigem Treffer).
    local match="" cnt=0
    for f in "$MOUNTS_DIR"/*.ini; do
        host="$(read_mount_host "$f")"
        [ -z "$host" ] && continue
        if printf '%s' "$host" | tr '[:upper:]' '[:lower:]' | grep -qF "$ql"; then
            match="$f"; cnt=$((cnt + 1))
        fi
    done
    if [ "$cnt" -eq 1 ]; then printf '%s' "$match"; return 0; fi
    if [ "$cnt" -gt 1 ]; then
        echo "Mehrdeutig: '$q' passt auf mehrere Webseiten. Bitte den vollen Host angeben." >&2
        return 2
    fi
    return 1
}

# --- Detailausgabe (-i) ----------------------------------------------------
show_site() {
    local file="$1" host site_key h expect
    host="$(read_mount_host "$file")"

    printf '═══ Webseite: %s ═══\n' "${host:-(Host aus der Mount-Datei nicht ermittelbar)}"
    printf 'Datei: %s\n' "$file"

    # Site-Kennung/Hash verifizieren, sofern der Host bekannt ist und der
    # Dateiname dem Standard-Endpunkt (API_DIR) oder dem Wurzel-Endpunkt folgt.
    if [ -n "$host" ] && printf '%s' "$(basename "$file")" | grep -qE '^[0-9a-f]{64}\.ini$'; then
        local fhash; fhash="$(basename "$file" .ini)"
        local k
        for k in "$host/$API_DIR" "$host"; do
            expect="$(printf '%s' "$k" | sha256sum | cut -d' ' -f1)"
            if [ "$expect" = "$fhash" ]; then
                printf 'Site-Kennung: %s   (Hash bestätigt ✓)\n' "$k"
                break
            fi
        done
    fi

    # --- Mounts ------------------------------------------------------------
    printf '\n── Mounts ──\n'
    local s path label perms accept ro exist
    local any=0
    while IFS= read -r s; do
        [ -z "$s" ] && continue
        is_reserved "$s" && continue
        any=1
        path="$(read_ini_value "$file" "$s" path)"
        label="$(read_ini_value "$file" "$s" label)"
        perms="$(read_ini_value "$file" "$s" permissions)"
        accept="$(read_ini_value "$file" "$s" accept)"
        ro="$(read_ini_value "$file" "$s" readonly)"
        exist="(Pfad fehlt!)"; [ -d "$path" ] && exist="(vorhanden)"
        printf '  [%s]%s\n' "$s" "${label:+  – $label}"
        printf '      Pfad:     %s  %s\n' "${path:-(kein path gesetzt)}" "$exist"
        printf '      Rechte:   %s\n' "${perms:-(alle)}"
        printf '      Endungen: %s\n' "${accept:-(alle)}"
        [ -n "$ro" ] && printf '      readonly: %s\n' "$ro"
    done < <(ini_sections "$file")
    [ "$any" = 1 ] || printf '  (keine Mount-Sektionen)\n'

    # --- Veröffentlichung (Hugo) ------------------------------------------
    local hsrc hdst
    hsrc="$(read_ini_value "$file" hugo source)"
    if [ -n "$hsrc" ]; then
        hdst="$(read_ini_value "$file" hugo destination)"
        local hmin hcln
        hmin="$(read_ini_value "$file" hugo minify)"
        hcln="$(read_ini_value "$file" hugo clean)"
        printf '\n── Veröffentlichung (Hugo) ──\n'
        printf '      source:      %s\n' "$hsrc"
        printf '      destination: %s\n' "${hdst:-$hsrc/public}"
        printf '      minify:      %s\n' "${hmin:-aus}"
        printf '      clean:       %s\n' "${hcln:-aus}"
    fi

    # --- Zusatzfunktionen --------------------------------------------------
    local ps la sr_p sr_f imp_auto cb_b cb_i cb_h git_ac
    ps="$(read_ini_value "$file" pagespeed url)"
    la="$(read_ini_value "$file" live_analysis url)"
    sr_p="$(read_ini_value "$file" seo_report exclude_prefixes)"
    sr_f="$(read_ini_value "$file" seo_report exclude_files)"
    imp_auto="$(read_ini_value "$file" improve auto)"
    cb_b="$(read_ini_value "$file" cron pause_build)"
    cb_i="$(read_ini_value "$file" cron pause_improve)"
    cb_h="$(read_ini_value "$file" cron pause_healthcheck)"
    git_ac="$(read_ini_value "$file" git auto_commit)"

    printf '\n── Zusatzfunktionen ──\n'
    printf '      PageSpeed-URL:    %s\n' "${ps:-– nicht konfiguriert}"
    printf '      Live-Analyse-URL: %s\n' "${la:-– nicht konfiguriert}"
    if [ -n "$sr_p$sr_f" ]; then
        printf '      SEO-Bericht:      exclude_prefixes=%s  exclude_files=%s\n' "${sr_p:-–}" "${sr_f:-–}"
    fi
    if [ -n "$imp_auto" ]; then
        local iws iwe ipd isw
        iws="$(read_ini_value "$file" improve window_start)"
        iwe="$(read_ini_value "$file" improve window_end)"
        ipd="$(read_ini_value "$file" improve per_day)"
        isw="$(read_ini_value "$file" improve skip_weekends)"
        printf '      Auto-Verbessern:  auto=%s  Fenster=%s–%s  max=%s/Tag  Wochenende-aus=%s\n' \
            "$imp_auto" "${iws:-07:00}" "${iwe:-16:00}" "${ipd:-3}" "${isw:-an}"
    fi
    if [ -n "$cb_b$cb_i$cb_h" ]; then
        printf '      Cron-Pausen:      build=%s  improve=%s  healthcheck=%s\n' \
            "${cb_b:-aus}" "${cb_i:-aus}" "${cb_h:-aus}"
    fi
    if [ -n "$git_ac" ]; then
        printf '      Git-Auto-Commit:  %s\n' "$git_ac"
    fi

    # --- Hinterlegte Schlüssel (Werte verborgen) --------------------------
    printf '\n── Hinterlegte Schlüssel (Werte verborgen) ──\n'
    printf '  Pro-Lizenz  [license] key:        %s\n' "$(key_status "$(read_ini_value "$file" license key)")"

    printf '  Installationsweit (%s):\n' "$(basename "$CONFIG_FILE")"
    if [ -f "$CONFIG_FILE" ]; then
        local svc
        svc="$(read_ini_value "$CONFIG_FILE" services service_key)"
        [ -z "$svc" ] && svc="$(read_ini_value "$CONFIG_FILE" services speech_key)"
        printf '    [auth] password_hash:           %s\n' "$(key_status "$(read_ini_value "$CONFIG_FILE" auth password_hash)")"
        printf '    [ai] api_key:                   %s\n' "$(key_status "$(read_ini_value "$CONFIG_FILE" ai api_key)")"
        printf '    [services] service_key:         %s\n' "$(key_status "$svc")"
        printf '    [services] pagespeed_key:       %s\n' "$(key_status "$(read_ini_value "$CONFIG_FILE" services pagespeed_key)")"
        printf '    [mail] smtp_pass:               %s\n' "$(key_status "$(read_ini_value "$CONFIG_FILE" mail smtp_pass)")"
    else
        printf '    (hugocms.ini nicht gefunden: %s)\n' "$CONFIG_FILE"
    fi
}

# --- Parameter -------------------------------------------------------------
MODE=""
TARGET=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        -l|--list) MODE="list" ;;
        -i|--info)
            MODE="info"
            shift || true
            TARGET="${1:-}"
            if [ -z "$TARGET" ]; then
                echo "❌ -i erwartet eine Kennung (Host, Site-Kennung oder Hash)." >&2
                echo "   z. B. bin/sites.sh -i kunde-a.example.com" >&2
                exit 1
            fi
            ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unbekannte Option: $1" >&2; echo "  (bin/sites.sh --help)" >&2; exit 1 ;;
    esac
    shift
done

case "$MODE" in
    list) list_sites ;;
    info)
        file="$(resolve_site "$TARGET")" || {
            rc=$?
            [ "$rc" -eq 2 ] && exit 2
            echo "❌ Keine Webseite gefunden für: '$TARGET'" >&2
            echo "   Verfügbare Webseiten mit:  bin/sites.sh -l" >&2
            exit 1
        }
        show_site "$file"
        ;;
    *) usage; exit 1 ;;
esac
