#!/usr/bin/env bash
#
# Génère la configuration Traefik dérivée de la plage de ports des serveurs Archipelago.
#
#   ./scripts/gen-traefik-config.sh              # génère
#   ./scripts/gen-traefik-config.sh --check      # n'écrit rien, sort 1 si un fichier a dérivé
#   ./scripts/gen-traefik-config.sh --env-file /chemin/.env
#
# Deux sorties :
#
#   traefik/traefik.yml            rendu depuis traefik/traefik.yml.tpl (contient le token du
#                                  provider HTTP, donc NON commité - voir .gitignore)
#   traefik/docker-compose.yml     bloc de publication de ports réécrit entre marqueurs (commité,
#                                  aucun secret)
#
# Source de vérité unique de la plage : PORT_RANGE_START, PORT_RANGE_END et AP_SERVER_PORT_OFFSET,
# les mêmes variables que celles lues par l'orchestrateur. Le port d'un serveur Archipelago vaut
# `port du pool + offset` (orchestrateur, internal/service/session.go), donc la plage à ouvrir ici
# est décalée de l'offset - c'est volontaire, et c'est la raison d'être de ce script : personne ne
# recalcule cette plage à la main.
#
# Convention de nommage des entrypoints : `ap-{port}` (ex. `ap-35042`).
# CONTRAT AVEC L'API : TraefikConfigBuilder (story 37.2) construit le même nom depuis le port de la
# session. Changer la convention ici impose de la changer là-bas, et rien ne le détectera
# automatiquement - les deux vivent dans des couches différentes.
#
# ATTENTION : les entrypoints sont de la configuration STATIQUE Traefik. Toute régénération impose
# un REDÉMARRAGE de Traefik, qui porte aussi le site, l'API, Mercure, MinIO et l'orchestrateur :
# toutes les connexions en cours tombent, parties Archipelago comprises. Créneau calme obligatoire.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/traefik/.env"
check_only=0

usage() {
    sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --check) check_only=1; shift ;;
        --env-file) env_file="${2:?--env-file attend un chemin}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "option inconnue : $1" >&2; usage >&2; exit 2 ;;
    esac
done

# ─── Entrées ────────────────────────────────────────────────────────────────────

# Lecture littérale, sans interprétation shell : c'est la sémantique de `env_file` chez docker
# compose, qui lit ce même fichier. Un `. fichier` traiterait `$` et `&` d'un token comme du code.
load_env_file() {
    local file="$1" line key value
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"                       # fichiers édités sous Windows
        case "$line" in ''|'#'*) continue ;; esac
        case "$line" in *=*) ;; *) continue ;; esac
        key="${line%%=*}"
        value="${line#*=}"
        key="${key#"${key%%[![:space:]]*}"}"       # trim gauche
        key="${key%"${key##*[![:space:]]}"}"       # trim droite
        case "$key" in
            export' '*) key="${key#export }" ;;
        esac
        case "$key" in ''|*[!A-Za-z0-9_]*) continue ;; esac
        # Guillemets englobants retirés, contenu laissé tel quel.
        case "$value" in
            \"*\") value="${value#\"}"; value="${value%\"}" ;;
            \'*\') value="${value#\'}"; value="${value%\'}" ;;
        esac
        printf -v "$key" '%s' "$value"
        export "${key?}"
    done < "$file"
}

if [ -f "$env_file" ]; then
    load_env_file "$env_file"
elif [ "$check_only" -eq 0 ]; then
    echo "erreur : fichier d'environnement introuvable : $env_file" >&2
    echo "        (en production c'est traefik/.env, non commité)" >&2
    exit 1
fi

# Valeurs par défaut alignées sur .env.prod.example et sur les défauts de l'orchestrateur.
: "${PORT_RANGE_START:=25000}"
: "${PORT_RANGE_END:=25099}"
: "${AP_SERVER_PORT_OFFSET:=10000}"
: "${ACME_EMAIL:=}"
: "${TRAEFIK_TOKEN:=}"
: "${TRAEFIK_HTTP_PROVIDER_ENDPOINT:=http://archilan-api-web/api/v1/internal/traefik}"
: "${TRAEFIK_HTTP_PROVIDER_POLL_INTERVAL:=5s}"
: "${TRAEFIK_HTTP_PROVIDER_POLL_TIMEOUT:=5s}"

for var in PORT_RANGE_START PORT_RANGE_END AP_SERVER_PORT_OFFSET; do
    case "${!var}" in
        ''|*[!0-9]*) echo "erreur : $var doit être un entier (valeur : « ${!var} »)" >&2; exit 1 ;;
    esac
done

ap_start=$((PORT_RANGE_START + AP_SERVER_PORT_OFFSET))
ap_end=$((PORT_RANGE_END + AP_SERVER_PORT_OFFSET))
ap_count=$((ap_end - ap_start + 1))

if [ "$ap_start" -gt "$ap_end" ]; then
    echo "erreur : plage vide ou inversée ($ap_start > $ap_end)" >&2
    exit 1
fi

# Garde-fou : une faute de frappe sur le pool ouvrirait des milliers de ports en production.
if [ "$ap_count" -gt 512 ]; then
    echo "erreur : $ap_count ports demandés ($ap_start-$ap_end), au-delà du garde-fou de 512." >&2
    echo "        Si la plage doit vraiment grandir à ce point, relever le garde-fou sciemment." >&2
    exit 1
fi

if [ "$check_only" -eq 0 ]; then
    [ -n "$ACME_EMAIL" ]    || { echo "erreur : ACME_EMAIL est vide" >&2; exit 1; }
    [ -n "$TRAEFIK_TOKEN" ] || { echo "erreur : TRAEFIK_TOKEN est vide (provider HTTP non authentifié)" >&2; exit 1; }
fi

# ─── Rendu ──────────────────────────────────────────────────────────────────────

# Substitution en bash pur : pas de sed, donc aucune question d'échappement sur un token qui peut
# contenir n'importe quel caractère, et aucune dépendance à envsubst sur le serveur.
#
# Découpe explicite plutôt que `${line//motif/valeur}` : depuis bash 5.2, un `&` dans la valeur de
# remplacement désigne le texte apparié, ce qui corrompt silencieusement tout token contenant `&`.
# Ici la valeur est recopiée telle quelle et n'est jamais réanalysée.
subst_one() {
    local text="$1" placeholder="$2" value="$3" out='' prefix
    while [ "${text#*"$placeholder"}" != "$text" ]; do
        prefix="${text%%"$placeholder"*}"
        out="${out}${prefix}${value}"
        text="${text#*"$placeholder"}"
    done
    printf '%s' "${out}${text}"
}

render_template() {
    local template="$1" line var
    local vars=(
        ACME_EMAIL
        TRAEFIK_TOKEN
        TRAEFIK_HTTP_PROVIDER_ENDPOINT
        TRAEFIK_HTTP_PROVIDER_POLL_INTERVAL
        TRAEFIK_HTTP_PROVIDER_POLL_TIMEOUT
    )

    printf '# FICHIER GÉNÉRÉ par scripts/gen-traefik-config.sh depuis traefik/traefik.yml.tpl.\n'
    printf '# Ne pas éditer : toute modification est écrasée à la prochaine génération.\n'
    printf '# Non commité (il contient le token du provider HTTP) - voir traefik/README.md.\n'

    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        if [ "${line#*'{{AP_ENTRYPOINTS}}'}" != "$line" ]; then
            emit_entrypoints
            continue
        fi
        for var in "${vars[@]}"; do
            line="$(subst_one "$line" "\${$var}" "${!var}")"
        done
        printf '%s\n' "$line"
    done < "$template"
}

emit_entrypoints() {
    local port
    printf '  # >>> entrypoints Archipelago générés (%s-%s) - ne pas éditer à la main\n' "$ap_start" "$ap_end"
    for ((port = ap_start; port <= ap_end; port++)); do
        printf '  ap-%s:\n    address: ":%s"\n' "$port" "$port"
    done
    printf '  # <<< entrypoints Archipelago générés\n'
}

# Réécrit le bloc de ports de la compose entre ses marqueurs, sans toucher au reste du fichier.
render_compose() {
    local source="$1" line in_block=0
    while IFS= read -r line || [ -n "$line" ]; do
        if [ "${line#*'>>> plage Archipelago générée'}" != "$line" ]; then
            in_block=1
            printf '      # >>> plage Archipelago générée par scripts/gen-traefik-config.sh - ne pas éditer\n'
            printf '      - "%s-%s:%s-%s"\n' "$ap_start" "$ap_end" "$ap_start" "$ap_end"
            printf '      # <<< plage Archipelago générée\n'
            continue
        fi
        if [ "$in_block" -eq 1 ]; then
            if [ "${line#*'<<< plage Archipelago générée'}" != "$line" ]; then
                in_block=0
            fi
            continue
        fi
        printf '%s\n' "$line"
    done < "$source"
}

tpl="${repo_root}/traefik/traefik.yml.tpl"
out_yml="${repo_root}/traefik/traefik.yml"
compose="${repo_root}/traefik/docker-compose.yml"

[ -f "$tpl" ] || { echo "erreur : template introuvable : $tpl" >&2; exit 1; }
[ -f "$compose" ] || { echo "erreur : compose introuvable : $compose" >&2; exit 1; }

if ! grep -q '>>> plage Archipelago générée' "$compose"; then
    echo "erreur : marqueurs de plage absents de $compose" >&2
    echo "        Réinsérer le bloc « >>> plage Archipelago générée » / « <<< » dans ports:." >&2
    exit 1
fi

tmp_yml="$(mktemp)"
tmp_compose="$(mktemp)"
trap 'rm -f "$tmp_yml" "$tmp_compose"' EXIT

render_template "$tpl" > "$tmp_yml"
render_compose "$compose" > "$tmp_compose"

# ─── Sortie ─────────────────────────────────────────────────────────────────────

if [ "$check_only" -eq 1 ]; then
    status=0
    # traefik.yml n'est comparé que s'il existe : sur un poste de dev sans traefik/.env, il n'a
    # aucune raison d'être là, et son absence n'est pas une dérive.
    if [ -f "$out_yml" ] && ! diff -q "$out_yml" "$tmp_yml" >/dev/null; then
        echo "dérive : traefik/traefik.yml ne correspond plus au template et à l'environnement" >&2
        status=1
    fi
    if ! diff -q "$compose" "$tmp_compose" >/dev/null; then
        echo "dérive : le bloc de ports de traefik/docker-compose.yml ne correspond plus à la plage $ap_start-$ap_end" >&2
        status=1
    fi
    [ "$status" -eq 0 ] && echo "à jour : $ap_count entrypoints, plage $ap_start-$ap_end"
    exit "$status"
fi

cp "$tmp_yml" "$out_yml"
cp "$tmp_compose" "$compose"

echo "généré : $ap_count entrypoints ap-$ap_start .. ap-$ap_end"
echo "         traefik/traefik.yml (non commité) et le bloc de ports de traefik/docker-compose.yml"
echo
echo "REDÉMARRAGE REQUIS : les entrypoints sont de la configuration statique."
echo "  cd traefik && docker compose up -d"
echo "Toutes les connexions en cours tombent, parties Archipelago comprises."
