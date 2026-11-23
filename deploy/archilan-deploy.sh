#!/usr/bin/env bash
# Story 33.34 - déploiement continu du site archilan.fr.
#
# Tourne sur la machine de production, à côté de docker-compose.yaml et de son .env (timer systemd, voir
# docs/deploiement-continu.md). À chaque passage :
#   1. lit la dernière release GitHub et la compare à ARCHILAN_VERSION du .env ; rien à faire = rien d'écrit ;
#   2. refuse et prévient si la release porte le marqueur « Déploiement manuel », change de majeure, ou tombe
#      entre 2 jours avant et 3 jours après le début d'un event ;
#   3. sinon : tire les images, sauvegarde la base, met à jour ARCHILAN_VERSION, joue les migrations puis relance
#      api-web, api-worker et frontend ;
#   4. vérifie pendant 5 minutes au plus que l'API et le site servent la nouvelle version ;
#   5. en cas d'échec, remet l'ancienne version et relance ; la sauvegarde reste pour restaurer la base.
# Les alertes passent par le bot Discord du site (DISCORD_BOT_TOKEN de envs/api.env, salon DEPLOY_CHANNEL_ID).
#
# Usage : archilan-deploy.sh [--dry-run] [--dir <dossier du compose>]
# Dépendances : bash, curl, jq, docker (compose v2), flock, gzip.

set -uo pipefail

REPO="ArchiLAN-dev/archilan.fr"
SITE_SERVICES=(api-web api-worker frontend)
VERIFY_TIMEOUT=300
MIGRATIONS_TIMEOUT=900
BACKUP_RETENTION_DAYS=14
FREEZE_BEFORE_SECONDS=$((2 * 86400))
FREEZE_AFTER_SECONDS=$((3 * 86400))

DRY_RUN=0
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --dir) shift; DEPLOY_DIR="${1:?--dir attend un dossier}" ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) echo "Option inconnue : $1" >&2; exit 2 ;;
    esac
    shift
done

ENV_FILE="$DEPLOY_DIR/.env"
API_ENV_FILE="$DEPLOY_DIR/envs/api.env"
POSTGRES_ENV_FILE="$DEPLOY_DIR/envs/postgres.env"
STATE_FILE="$DEPLOY_DIR/.deploy-state"
LOCK_FILE="$DEPLOY_DIR/.deploy.lock"
BACKUP_DIR="$DEPLOY_DIR/backups"

# Sur stderr : la sortie standard des fonctions sert à rendre une valeur (id d'un message, fichier de sauvegarde).
log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; }

# Lit une valeur KEY=... d'un fichier d'environnement sans l'exécuter (dernière occurrence, guillemets retirés).
env_get() {
    local file="$1" key="$2" line value
    [ -f "$file" ] || return 0
    line=$(grep -E "^[[:space:]]*${key}=" "$file" | tail -n 1) || return 0
    value="${line#*=}"
    value="${value%$'\r'}"
    value="${value#\"}"; value="${value%\"}"
    value="${value#\'}"; value="${value%\'}"
    printf '%s' "$value"
}

is_semver() { [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; }

# Vrai si $1 est strictement plus récente que $2.
is_newer() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" = "$1" ]; }

compose() { (cd "$DEPLOY_DIR" && docker compose "$@"); }

# ─── Discord (bot du site) ───────────────────────────────────────────────────

DISCORD_API="https://discord.com/api/v10"

# discord_payload <texte> <mentionner : 0|1>
discord_payload() {
    local role=""
    [ "$2" = "1" ] && role="${DEPLOY_ALERT_ROLE_ID:-}"
    if [ -n "$role" ]; then
        jq -n --arg c "<@&$role> $1" --arg r "$role" '{content: $c, allowed_mentions: {roles: [$r]}}'
    else
        jq -n --arg c "$1" '{content: $c, allowed_mentions: {parse: []}}'
    fi
}

# discord_post <texte> <mentionner> : écrit l'id du message sur la sortie, vide si rien n'est parti.
# Un envoi raté n'arrête jamais le déploiement : il est seulement écrit dans le journal.
discord_post() {
    if [ "$DRY_RUN" = "1" ]; then log "[dry-run] Discord : $1"; return 0; fi
    if [ -z "${DISCORD_BOT_TOKEN:-}" ] || [ -z "${DEPLOY_CHANNEL_ID:-}" ]; then log "Discord non configuré : $1"; return 0; fi
    local response
    response=$(discord_payload "$1" "$2" | curl -sS --max-time 15 -X POST "$DISCORD_API/channels/$DEPLOY_CHANNEL_ID/messages" \
        -H "Authorization: Bot $DISCORD_BOT_TOKEN" -H "Content-Type: application/json" --data-binary @- 2>&1) \
        || { log "Discord : envoi impossible ($response)"; return 0; }
    printf '%s' "$response" | jq -r '.id // empty' 2>/dev/null || log "Discord : réponse illisible ($response)"
}

# discord_edit <id> <texte> <mentionner> : remplace le message de départ ; en poste un nouveau sans id.
discord_edit() {
    local id="$1"
    if [ -z "$id" ] || [ "$DRY_RUN" = "1" ]; then discord_post "$2" "$3" >/dev/null; return 0; fi
    discord_payload "$2" "$3" | curl -sS --max-time 15 -o /dev/null -X PATCH "$DISCORD_API/channels/$DEPLOY_CHANNEL_ID/messages/$id" \
        -H "Authorization: Bot $DISCORD_BOT_TOKEN" -H "Content-Type: application/json" --data-binary @- \
        || log "Discord : modification impossible du message $id"
    # Une modification ne notifie pas : la mention d'un échec part dans un message à elle.
    [ "$3" = "1" ] && [ -n "${DEPLOY_ALERT_ROLE_ID:-}" ] && discord_post "Voir le message ci-dessus." 1 >/dev/null
    return 0
}

# ─── État : une alerte par version, pas une toutes les 15 minutes ───────────

state_get() { [ -f "$STATE_FILE" ] && cat "$STATE_FILE" || true; }
state_set() { [ "$DRY_RUN" = "1" ] || printf '%s\n' "$1" > "$STATE_FILE"; }

# ─── Contrôles ───────────────────────────────────────────────────────────────

has_manual_marker() { printf '%s\n' "$1" | grep -qE '^[[:space:]]*([-*][[:space:]]+)?\*\*Déploiement manuel\*\*'; }

# Écrit le titre et la date de l'event qui gèle le déploiement, rien sinon. Code 2 si les events sont illisibles.
freezing_event() {
    local api_domain="$1" events now
    events=$(curl -sS --fail --max-time 20 "https://$api_domain/api/v1/events") || return 2
    now=$(date -u +%s)
    printf '%s' "$events" | jq -r --argjson now "$now" --argjson before "$FREEZE_BEFORE_SECONDS" --argjson after "$FREEZE_AFTER_SECONDS" '
        def epoch: sub("\\.[0-9]+"; "") | sub("\\+00:00$"; "Z") | fromdateiso8601;
        [.data[]? | select(.startsAt != null) | . as $e | ($e.startsAt | epoch) as $s
         | select($now >= $s - $before and $now <= $s + $after) | "\($e.title) (\($e.startsAt))"] | first // empty
    ' || return 2
}

# GET de l'URL, et sa version servie : écrit « <code HTTP> <version> ».
served_api_version() {
    local body code
    body=$(curl -sS --max-time 10 -w '\n%{http_code}' "https://$1/api/v1/version" 2>/dev/null) || { echo "000 -"; return; }
    code="${body##*$'\n'}"
    printf '%s %s' "$code" "$(printf '%s' "${body%$'\n'*}" | jq -r '.data.version // "-"' 2>/dev/null || echo -)"
}

served_front_version() {
    local body code version
    body=$(curl -sS --max-time 15 -w '\n%{http_code}' "https://$1/" 2>/dev/null) || { echo "000 -"; return; }
    code="${body##*$'\n'}"
    version=$(printf '%s' "${body%$'\n'*}" | grep -oE '<meta name="archilan-version" content="[^"]*"' | head -n 1 | sed -E 's/.*content="([^"]*)"/\1/')
    printf '%s %s' "$code" "${version:--}"
}

# ─── Étapes ──────────────────────────────────────────────────────────────────

backup_database() {
    local old="$1" user db file
    user=$(env_get "$POSTGRES_ENV_FILE" POSTGRES_USER); user="${user:-archilan}"
    db=$(env_get "$POSTGRES_ENV_FILE" POSTGRES_DB); db="${db:-archilan}"
    mkdir -p "$BACKUP_DIR" || return 1
    file="$BACKUP_DIR/archilan-${old}-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
    # --clean --if-exists : la sauvegarde se rejoue telle quelle sur la base en place (docs/deploiement-continu.md).
    if ! compose exec -T postgres pg_dump -U "$user" -d "$db" --no-owner --clean --if-exists | gzip > "$file"; then
        rm -f "$file"
        return 1
    fi
    find "$BACKUP_DIR" -name 'archilan-*.sql.gz' -mtime +"$BACKUP_RETENTION_DAYS" -delete 2>/dev/null || true
    printf '%s' "$file"
}

set_version() {
    local version="$1" tmp
    tmp="$ENV_FILE.tmp.$$"
    if grep -qE '^ARCHILAN_VERSION=' "$ENV_FILE"; then
        sed -E "s/^ARCHILAN_VERSION=.*/ARCHILAN_VERSION=$version/" "$ENV_FILE" > "$tmp" || return 1
    else
        { cat "$ENV_FILE"; printf 'ARCHILAN_VERSION=%s\n' "$version"; } > "$tmp" || return 1
    fi
    cat "$tmp" > "$ENV_FILE" && rm -f "$tmp"
}

run_migrations() {
    local code
    compose up -d --no-deps --force-recreate api-migrations || return 1
    code=$(timeout "$MIGRATIONS_TIMEOUT" docker wait archilan-api-migrations) || return 1
    [ "$code" = "0" ]
}

# Attend que l'API et le site servent $1 ; écrit la dernière observation si le délai passe.
verify() {
    local version="$1" api front deadline=$(( $(date +%s) + VERIFY_TIMEOUT ))
    while :; do
        api=$(served_api_version "$API_DOMAIN")
        front=$(served_front_version "$FRONTEND_DOMAIN")
        if [ "$api" = "200 $version" ] && [ "$front" = "200 $version" ]; then return 0; fi
        if [ "$(date +%s)" -ge "$deadline" ]; then
            printf 'API %s, site %s (code HTTP et version servie)' "$api" "$front"
            return 1
        fi
        sleep 10
    done
}

rollback() {
    local old="$1"
    set_version "$old" || { log "Retour en arrière : .env non réécrit"; return 1; }
    compose up -d --no-deps "${SITE_SERVICES[@]}"
}

# ─── Programme ───────────────────────────────────────────────────────────────

main() {
    [ -f "$ENV_FILE" ] || { log "Pas de .env dans $DEPLOY_DIR"; exit 1; }
    for tool in curl jq docker flock gzip; do
        command -v "$tool" >/dev/null || { log "Outil manquant : $tool"; exit 1; }
    done

    exec 9>"$LOCK_FILE"
    flock -n 9 || { log "Un autre passage est en cours"; exit 0; }

    if [ "$(env_get "$ENV_FILE" DEPLOY_PAUSED)" = "1" ]; then log "Déploiement automatique en pause (DEPLOY_PAUSED=1)"; exit 0; fi

    DISCORD_BOT_TOKEN=$(env_get "$API_ENV_FILE" DISCORD_BOT_TOKEN)
    DEPLOY_CHANNEL_ID=$(env_get "$ENV_FILE" DEPLOY_CHANNEL_ID)
    DEPLOY_ALERT_ROLE_ID=$(env_get "$ENV_FILE" DEPLOY_ALERT_ROLE_ID)
    API_DOMAIN=$(env_get "$ENV_FILE" API_DOMAIN)
    FRONTEND_DOMAIN=$(env_get "$ENV_FILE" FRONTEND_DOMAIN)
    [ -n "$API_DOMAIN" ] && [ -n "$FRONTEND_DOMAIN" ] || { log "API_DOMAIN ou FRONTEND_DOMAIN absent du .env"; exit 1; }

    local current release tag new body state
    current=$(env_get "$ENV_FILE" ARCHILAN_VERSION)
    if ! is_semver "$current"; then log "ARCHILAN_VERSION n'est pas une version X.Y.Z ($current) : rien à faire seul"; exit 0; fi

    release=$(curl -sS --fail --max-time 20 -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/releases/latest") \
        || { log "Release GitHub illisible, nouvel essai au prochain passage"; exit 0; }
    tag=$(printf '%s' "$release" | jq -r '.tag_name // empty')
    body=$(printf '%s' "$release" | jq -r '.body // ""')
    new="${tag#v}"
    if ! is_semver "$new"; then log "Dernière release sans version lisible ($tag)"; exit 0; fi
    if ! is_newer "$new" "$current"; then log "À jour ($current)"; exit 0; fi

    state=$(state_get)
    case "$state" in
        "refused $new"|"failed $new") log "v$new déjà écartée ($state), rien de plus sans intervention"; exit 0 ;;
    esac

    # Refus définitifs pour cette version : on prévient une fois.
    local reason=""
    if has_manual_marker "$body"; then
        reason="la release demande un déploiement manuel (voir sa section Déploiement)"
    elif [ "${new%%.*}" != "${current%%.*}" ]; then
        reason="changement de version majeure ($current vers $new)"
    fi
    if [ -n "$reason" ]; then
        log "Refus de v$new : $reason"
        discord_post "🛑 **archilan.fr v$new** n'est pas déployée automatiquement : $reason. Site toujours en v$current. https://github.com/$REPO/releases/tag/$tag" 1 >/dev/null
        state_set "refused $new"
        exit 0
    fi

    # Gel autour d'un event : on prévient une fois, puis on réessaie en silence jusqu'à la fin du gel.
    local event
    event=$(freezing_event "$API_DOMAIN"); local code=$?
    if [ "$code" = "2" ]; then log "Events illisibles, nouvel essai au prochain passage"; exit 0; fi
    if [ -n "$event" ]; then
        log "Gel : $event"
        if [ "$state" != "frozen $new" ]; then
            discord_post "⏸️ **archilan.fr v$new** attend : gel autour de l'event $event. Déploiement automatique à la fin du gel." 0 >/dev/null
            state_set "frozen $new"
        fi
        exit 0
    fi

    if [ "$DRY_RUN" = "1" ]; then
        log "[dry-run] Déploierait v$new (en place : v$current) : pull, sauvegarde dans $BACKUP_DIR, migrations, ${SITE_SERVICES[*]}, vérification de https://$API_DOMAIN/api/v1/version et https://$FRONTEND_DOMAIN/"
        exit 0
    fi

    # Images pas encore publiées (le workflow de release les construit après le tag) : nouvel essai plus tard.
    if ! (cd "$DEPLOY_DIR" && ARCHILAN_VERSION="$new" docker compose pull --quiet api-migrations "${SITE_SERVICES[@]}"); then
        log "Images v$new pas encore disponibles, nouvel essai au prochain passage"
        exit 0
    fi

    log "Déploiement de v$new (en place : v$current)"
    local message backup failure=""
    message=$(discord_post "🚀 Déploiement de **archilan.fr v$new** en cours (en place : v$current)…" 0)

    if ! backup=$(backup_database "$current"); then
        log "Sauvegarde de la base impossible : rien n'a changé"
        discord_edit "$message" "❌ **archilan.fr v$new** non déployée : la sauvegarde de la base a échoué. Rien n'a changé, site en v$current." 1
        state_set "failed $new"
        exit 1
    fi
    log "Base sauvegardée : $backup"

    if ! set_version "$new"; then
        failure="le .env n'a pas pu être mis à jour"
    elif ! run_migrations; then
        failure="les migrations ont échoué (docker compose logs api-migrations)"
    elif ! compose up -d --no-deps "${SITE_SERVICES[@]}"; then
        failure="le redémarrage des services a échoué"
    else
        local seen
        if ! seen=$(verify "$new"); then failure="vérification en échec après ${VERIFY_TIMEOUT} s : $seen"; fi
    fi

    if [ -z "$failure" ]; then
        log "v$new déployée et vérifiée"
        discord_edit "$message" "✅ **archilan.fr v$new** déployée et vérifiée (avant : v$current). https://github.com/$REPO/releases/tag/$tag" 0
        state_set "deployed $new"
        exit 0
    fi

    log "Échec de v$new : $failure. Retour à v$current"
    local back="site revenu en v$current"
    rollback "$current" || back="**retour en arrière lui-même en échec, intervention nécessaire**"
    discord_edit "$message" "❌ **archilan.fr v$new** : $failure. $back. Si une migration est passée, la base d'avant est dans \`$backup\`." 1
    state_set "failed $new"
    exit 1
}

main
