#!/bin/bash
# sites.sh — Shows the configured sites (tenants) of a HugoCMS installation.
# Each site is a mount file backend/mounts/<hash>.ini (hash as in
# scripts/site-hash.sh); the fallback backend/mounts.ini counts as its own
# "site" without host binding.
#
# Usage:
#     bin/sites.sh -l                 list all configured sites
#     bin/sites.sh -i <identifier>    detailed info about ONE site
#     bin/sites.sh -h | --help        this help
#
#   <identifier> for -i is one of:
#     • the domain/host           e.g.  kunde-a.example.com
#     • the full site identifier  e.g.  kunde-a.example.com/cms-api
#     • the hash or filename      e.g.  3f2a…  or  3f2a….ini
#     • mounts (or mounts.ini)    for the fallback backend/mounts.ini
#   The host is read from the mount file's header comment (install.sh writes
#   there "; HugoCMS – Mounts für <HOST> …"); the hash in the filename is not
#   reversible.
#
# The detail view lists the mounts (path, permissions, extensions), the active
# extra features (Hugo publishing, PageSpeed, live analysis, auto-improve, cron
# pauses, Git) and WHICH keys are configured — the values themselves are NEVER
# printed (password hash, license, API and SMTP keys stay hidden).
#
# The script lives in the bin/ of the release repo and determines its root
# relative to itself — the repo may reside anywhere.

set -euo pipefail

# --- Release root relative to the script -----------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND_DIR="$PKG_ROOT/backend"
# MOUNTS_DIR/CONFIG_FILE can be overridden via the environment for tests;
# otherwise the release layout applies.
MOUNTS_DIR="${MOUNTS_DIR:-$BACKEND_DIR/mounts}"
CONFIG_FILE="${CONFIG_FILE:-$BACKEND_DIR/hugocms.ini}"
# The fallback mounts.ini sits next to the mounts/ directory (default:
# backend/mounts.ini); moves along when MOUNTS_DIR is overridden.
FALLBACK_MOUNTS="${FALLBACK_MOUNTS:-$(dirname "$MOUNTS_DIR")/mounts.ini}"

# read_ini_value from the same library as install.sh/update.sh (no duplicate
# INI reader). API_DIR provides the default endpoint path for the hash
# verification. deploy_app is not used here.
source "$SCRIPT_DIR/lib/deploy.sh"

usage() { sed -n '2,24p' "$0" | sed 's/^#\( \|$\)//'; }

# Reserved sections (NOT mounts) — see mounts.ini.beispiel.
is_reserved() {
    case "$1" in
        hugo|license|pagespeed|live_analysis|seo_report|improve|cron|git) return 0 ;;
        *) return 1 ;;
    esac
}

# Read the host from the mount file's header comment (install.sh format). That
# header line is German by design — it is the parsed contract with the file.
read_mount_host() {
    sed -n 's/.*Mounts für \(.*\) (von install\.sh.*/\1/p' "$1" | head -n1
}

# Print all section names of an INI file (in file order).
ini_sections() {
    awk '/^[[:space:]]*\[/ { s=$0; sub(/^[[:space:]]*\[/,"",s); sub(/\].*/,"",s); print s }' "$1"
}

# Number of mount sections (all except the reserved ones).
count_mounts() {
    local n=0 s
    while IFS= read -r s; do
        [ -z "$s" ] && continue
        is_reserved "$s" || n=$((n + 1))
    done < <(ini_sections "$1")
    printf '%s' "$n"
}

# Status line for a key: configured (with length) OR not set — the value itself
# is never printed.
key_status() {
    if [ -n "$1" ]; then printf '✓ configured (%d chars)' "${#1}"
    else printf '– not set'; fi
}

# License status of a mount file as a word.
license_word() {
    if [ -n "$(read_ini_value "$1" license key)" ]; then printf 'Pro'; else printf 'Community'; fi
}

# --- Listing (-l) ----------------------------------------------------------
list_sites() {
    shopt -s nullglob
    local mounts=("$MOUNTS_DIR"/*.ini)
    local have=0
    if [ "${#mounts[@]}" -eq 0 ] && [ ! -f "$FALLBACK_MOUNTS" ]; then
        echo "No site configured (no mount file in $MOUNTS_DIR" >&2
        echo "and no fallback $FALLBACK_MOUNTS)." >&2
        return 1
    fi

    printf 'HugoCMS – Configured sites\n'
    printf 'Directory: %s\n\n' "$MOUNTS_DIR"
    printf '%-34s %-10s %6s  %s\n' "HOST/DOMAIN" "LICENSE" "MOUNTS" "FILE"
    printf '%-34s %-10s %6s  %s\n' "----------------------------------" "----------" "------" "----------------------------------"

    local f host lic n
    for f in "${mounts[@]}"; do
        host="$(read_mount_host "$f")"
        lic="$(license_word "$f")"
        n="$(count_mounts "$f")"
        printf '%-34s %-10s %6s  %s\n' "${host:-(host unknown)}" "$lic" "$n" "$(basename "$f")"
        have=1
    done

    if [ -f "$FALLBACK_MOUNTS" ]; then
        lic="$(license_word "$FALLBACK_MOUNTS")"
        n="$(count_mounts "$FALLBACK_MOUNTS")"
        printf '%-34s %-10s %6s  %s\n' "(fallback, no host)" "$lic" "$n" "mounts.ini"
        have=1
    fi

    echo ""
    if [ "$have" = 1 ]; then
        printf 'Details:  bin/sites.sh -i <host>\n'
    fi
}

# --- Resolve a mount file from an identifier -------------------------------
# On success prints the file path on stdout, otherwise an empty string.
resolve_site() {
    local q="$1"

    # 1. Fallback addressed explicitly.
    case "$q" in
        mounts|mounts.ini) [ -f "$FALLBACK_MOUNTS" ] && { printf '%s' "$FALLBACK_MOUNTS"; return 0; }; return 1 ;;
    esac

    # 2. Filename/hash directly.
    local cand="$MOUNTS_DIR/${q%.ini}.ini"
    [ -f "$cand" ] && { printf '%s' "$cand"; return 0; }

    # 3. Full site identifier with endpoint path → compute hash.
    if printf '%s' "$q" | grep -q '/'; then
        local h; h="$(printf '%s' "$q" | sha256sum | cut -d' ' -f1)"
        cand="$MOUNTS_DIR/${h}.ini"
        [ -f "$cand" ] && { printf '%s' "$cand"; return 0; }
    fi

    # 4. Host from the header comment — exact (case-insensitive).
    shopt -s nullglob
    local f host ql; ql="$(printf '%s' "$q" | tr '[:upper:]' '[:lower:]')"
    for f in "$MOUNTS_DIR"/*.ini; do
        host="$(read_mount_host "$f")"
        [ -z "$host" ] && continue
        [ "$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')" = "$ql" ] && { printf '%s' "$f"; return 0; }
    done

    # 5. Host as substring (convenience on a unique match).
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
        echo "Ambiguous: '$q' matches multiple sites. Please give the full host." >&2
        return 2
    fi
    return 1
}

# --- Detail output (-i) ----------------------------------------------------
show_site() {
    local file="$1" host site_key h expect
    host="$(read_mount_host "$file")"

    printf '═══ Site: %s ═══\n' "${host:-(host not determinable from the mount file)}"
    printf 'File: %s\n' "$file"

    # Verify site identifier/hash, provided the host is known and the filename
    # follows the default endpoint (API_DIR) or the root endpoint.
    if [ -n "$host" ] && printf '%s' "$(basename "$file")" | grep -qE '^[0-9a-f]{64}\.ini$'; then
        local fhash; fhash="$(basename "$file" .ini)"
        local k
        for k in "$host/$API_DIR" "$host"; do
            expect="$(printf '%s' "$k" | sha256sum | cut -d' ' -f1)"
            if [ "$expect" = "$fhash" ]; then
                printf 'Site identifier: %s   (hash confirmed ✓)\n' "$k"
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
        exist="(path missing!)"; [ -d "$path" ] && exist="(present)"
        printf '  [%s]%s\n' "$s" "${label:+  – $label}"
        printf '      Path:      %s  %s\n' "${path:-(no path set)}" "$exist"
        printf '      Rights:    %s\n' "${perms:-(all)}"
        printf '      Accept:    %s\n' "${accept:-(all)}"
        [ -n "$ro" ] && printf '      readonly:  %s\n' "$ro"
    done < <(ini_sections "$file")
    [ "$any" = 1 ] || printf '  (no mount sections)\n'

    # --- Publishing (Hugo) -------------------------------------------------
    local hsrc hdst
    hsrc="$(read_ini_value "$file" hugo source)"
    if [ -n "$hsrc" ]; then
        hdst="$(read_ini_value "$file" hugo destination)"
        local hmin hcln
        hmin="$(read_ini_value "$file" hugo minify)"
        hcln="$(read_ini_value "$file" hugo clean)"
        printf '\n── Publishing (Hugo) ──\n'
        printf '      source:      %s\n' "$hsrc"
        printf '      destination: %s\n' "${hdst:-$hsrc/public}"
        printf '      minify:      %s\n' "${hmin:-off}"
        printf '      clean:       %s\n' "${hcln:-off}"
    fi

    # --- Extra features ----------------------------------------------------
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

    printf '\n── Extra features ──\n'
    printf '      PageSpeed URL:     %s\n' "${ps:-– not configured}"
    printf '      Live analysis URL: %s\n' "${la:-– not configured}"
    if [ -n "$sr_p$sr_f" ]; then
        printf '      SEO report:        exclude_prefixes=%s  exclude_files=%s\n' "${sr_p:-–}" "${sr_f:-–}"
    fi
    if [ -n "$imp_auto" ]; then
        local iws iwe ipd isw
        iws="$(read_ini_value "$file" improve window_start)"
        iwe="$(read_ini_value "$file" improve window_end)"
        ipd="$(read_ini_value "$file" improve per_day)"
        isw="$(read_ini_value "$file" improve skip_weekends)"
        printf '      Auto-improve:      auto=%s  window=%s–%s  max=%s/day  skip-weekends=%s\n' \
            "$imp_auto" "${iws:-07:00}" "${iwe:-16:00}" "${ipd:-3}" "${isw:-on}"
    fi
    if [ -n "$cb_b$cb_i$cb_h" ]; then
        printf '      Cron pauses:       build=%s  improve=%s  healthcheck=%s\n' \
            "${cb_b:-off}" "${cb_i:-off}" "${cb_h:-off}"
    fi
    if [ -n "$git_ac" ]; then
        printf '      Git auto-commit:   %s\n' "$git_ac"
    fi

    # --- Configured keys (values hidden) -----------------------------------
    printf '\n── Configured keys (values hidden) ──\n'
    printf '%-33s%s\n' '  Pro license  [license] key:' "$(key_status "$(read_ini_value "$file" license key)")"

    printf '  Installation-wide (%s):\n' "$(basename "$CONFIG_FILE")"
    if [ -f "$CONFIG_FILE" ]; then
        local svc
        svc="$(read_ini_value "$CONFIG_FILE" services service_key)"
        [ -z "$svc" ] && svc="$(read_ini_value "$CONFIG_FILE" services speech_key)"
        printf '%-33s%s\n' '    [auth] password_hash:' "$(key_status "$(read_ini_value "$CONFIG_FILE" auth password_hash)")"
        printf '%-33s%s\n' '    [ai] api_key:' "$(key_status "$(read_ini_value "$CONFIG_FILE" ai api_key)")"
        printf '%-33s%s\n' '    [services] service_key:' "$(key_status "$svc")"
        printf '%-33s%s\n' '    [services] pagespeed_key:' "$(key_status "$(read_ini_value "$CONFIG_FILE" services pagespeed_key)")"
        printf '%-33s%s\n' '    [mail] smtp_pass:' "$(key_status "$(read_ini_value "$CONFIG_FILE" mail smtp_pass)")"
    else
        printf '    (hugocms.ini not found: %s)\n' "$CONFIG_FILE"
    fi
}

# --- Parameters ------------------------------------------------------------
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
                echo "❌ -i expects an identifier (host, site identifier or hash)." >&2
                echo "   e.g. bin/sites.sh -i kunde-a.example.com" >&2
                exit 1
            fi
            ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; echo "  (bin/sites.sh --help)" >&2; exit 1 ;;
    esac
    shift
done

case "$MODE" in
    list) list_sites ;;
    info)
        file="$(resolve_site "$TARGET")" || {
            rc=$?
            [ "$rc" -eq 2 ] && exit 2
            echo "❌ No site found for: '$TARGET'" >&2
            echo "   List available sites with:  bin/sites.sh -l" >&2
            exit 1
        }
        show_site "$file"
        ;;
    *) usage; exit 1 ;;
esac
