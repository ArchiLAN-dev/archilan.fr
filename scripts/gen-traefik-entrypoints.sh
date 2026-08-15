#!/usr/bin/env bash
#
# Produit le fragment de configuration Traefik qui expose les serveurs Archipelago.
#
#   ./scripts/gen-traefik-entrypoints.sh              # affiche le fragment
#   ./scripts/gen-traefik-entrypoints.sh --env-file /chemin/.env
#   ./scripts/gen-traefik-entrypoints.sh --ports      # seulement la liste des ports a publier
#
# Le reverse proxy de production n'est pas versionne dans ce depot : il sert plusieurs projets et
# vit ailleurs, configure en arguments de ligne de commande. Ce script ne modifie donc aucun
# fichier - il imprime ce qu'il faut coller dans ce compose, et rien d'autre.
#
# Source de verite unique de la plage : PORT_RANGE_START, PORT_RANGE_END et AP_SERVER_PORT_OFFSET,
# les memes variables que celles lues par l'orchestrateur. Le port d'un serveur Archipelago vaut
# `port du pool + offset` (orchestrateur, internal/service/session.go), donc la plage a ouvrir est
# decalee de l'offset - c'est la raison d'etre de ce script : personne ne recalcule cette plage a
# la main.
#
# Convention de nommage des entrypoints : `ap-{port}` (ex. `ap-35042`).
# CONTRAT AVEC L'API : TraefikConfigBuilder construit le meme nom depuis le port de la session.
# Changer la convention ici impose de la changer la-bas, et rien ne le detectera automatiquement.
# Symptome d'une divergence : la poignee TLS reussit et Traefik repond un HTTP 404 lui-meme.
#
# ATTENTION : les entrypoints sont de la configuration STATIQUE. Les ajouter impose un REDEMARRAGE
# du proxy, qui porte tout le trafic entrant de l'hote. Voir docs/traefik-runs-archipelago.md pour
# l'ordre de bascule, qui n'est pas negociable.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${repo_root}/.env.prod"
ports_only=0

usage() {
    sed -n '2,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
    case "$1" in
        --ports) ports_only=1; shift ;;
        --env-file) env_file="${2:?--env-file attend un chemin}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "option inconnue : $1" >&2; usage >&2; exit 2 ;;
    esac
done

# Lecture litterale, sans interpretation shell : c'est la semantique de `env_file` chez docker
# compose. Un `. fichier` traiterait `$` et `&` d'un secret comme du code.
load_env_file() {
    local file="$1" line key value
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in ''|'#'*) continue ;; esac
        case "$line" in *=*) ;; *) continue ;; esac
        key="${line%%=*}"
        value="${line#*=}"
        key="${key#"${key%%[![:space:]]*}"}"
        key="${key%"${key##*[![:space:]]}"}"
        case "$key" in export' '*) key="${key#export }" ;; esac
        case "$key" in ''|*[!A-Za-z0-9_]*) continue ;; esac
        case "$value" in
            \"*\") value="${value#\"}"; value="${value%\"}" ;;
            \'*\') value="${value#\'}"; value="${value%\'}" ;;
        esac
        printf -v "$key" '%s' "$value"
        export "${key?}"
    done < "$file"
}

[ -f "$env_file" ] && load_env_file "$env_file"

: "${PORT_RANGE_START:=25000}"
: "${PORT_RANGE_END:=25099}"
: "${AP_SERVER_PORT_OFFSET:=10000}"

for var in PORT_RANGE_START PORT_RANGE_END AP_SERVER_PORT_OFFSET; do
    case "${!var}" in
        ''|*[!0-9]*) echo "erreur : $var doit etre un entier (valeur : « ${!var} »)" >&2; exit 1 ;;
    esac
done

ap_start=$((PORT_RANGE_START + AP_SERVER_PORT_OFFSET))
ap_end=$((PORT_RANGE_END + AP_SERVER_PORT_OFFSET))
ap_count=$((ap_end - ap_start + 1))

if [ "$ap_start" -gt "$ap_end" ]; then
    echo "erreur : plage vide ou inversee ($ap_start > $ap_end)" >&2
    exit 1
fi

# Garde-fou : une faute de frappe sur le pool ouvrirait des milliers de ports en production.
if [ "$ap_count" -gt 512 ]; then
    echo "erreur : $ap_count ports demandes ($ap_start-$ap_end), au-dela du garde-fou de 512." >&2
    exit 1
fi

if [ "$ports_only" -eq 1 ]; then
    printf '            - "%s-%s:%s-%s"\n' "$ap_start" "$ap_end" "$ap_start" "$ap_end"
    exit 0
fi

cat <<EOF
# ─────────────────────────────────────────────────────────────────────────────
# Serveurs Archipelago : $ap_count entrypoints, ports $ap_start a $ap_end.
# Genere par scripts/gen-traefik-entrypoints.sh - ne pas editer a la main.
#
# A coller dans la section « command: » du service traefik.
# ─────────────────────────────────────────────────────────────────────────────
EOF

for ((port = ap_start; port <= ap_end; port++)); do
    printf '            - "--entrypoints.ap-%s.address=:%s"\n' "$port" "$port"
done

cat <<EOF

# Provider HTTP : les routeurs des runs, produits par l'API. Le token est le meme que TRAEFIK_TOKEN
# cote API. L'endpoint est joint par le reseau interne : passer par le nom public ferait dependre
# Traefik de son propre routage pour aller chercher sa configuration.
            - "--providers.http.endpoint=http://archilan-api-web/api/v1/internal/traefik"
            - "--providers.http.headers.X-Traefik-Token=\${TRAEFIK_TOKEN}"
            - "--providers.http.pollinterval=5s"

# L'option headers du provider HTTP n'existe qu'a partir de Traefik v3. En v2.11, Traefik REFUSE
# DE DEMARRER (verifie le 2026-08-14) :
#   command traefik error: failed to decode configuration from flags: field not found, node: headers
# Le proxy porte tout le trafic entrant de l'hote : coller ce bloc sans avoir migre en v3 met tout
# l'hote a terre, pas seulement les runs. VERIFIER LA VERSION AVANT.

# A ajouter dans la section « ports: » du meme service :
$(printf '            - "%s-%s:%s-%s"\n' "$ap_start" "$ap_end" "$ap_start" "$ap_end")

# Le conteneur traefik doit aussi partager un reseau avec les conteneurs de session
# (PROXY_NETWORK cote orchestrateur), sans quoi il ne peut pas joindre ap-server-{sessionId}:38281.
EOF
