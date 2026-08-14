#!/usr/bin/env bash
#
# Pre-vol avant le passage du reverse proxy de Traefik v2 a v3.
#
#   ./scripts/traefik-v3-preflight.sh            # conteneurs en cours ET arretes
#   ./scripts/traefik-v3-preflight.sh --running   # seulement ceux en cours
#
# A executer SUR L'HOTE du proxy, avant de toucher a quoi que ce soit.
#
# Le proxy sert plusieurs projets. Ce depot ne voit que ses propres libelles ; les autres peuvent
# utiliser des formes que la v3 ne comprend plus, et un routeur qui cesse de correspondre ne
# provoque aucune erreur - le site concerne renvoie simplement 404. Ce script inventorie les
# libelles Traefik de TOUS les conteneurs de l'hote et signale les formes connues pour changer.
#
# Il ne modifie rien. Il lit et il rapporte.

set -euo pipefail

ps_args="-a"
case "${1:-}" in
    --running) ps_args="" ;;
    "") ;;
    *) echo "option inconnue : $1" >&2; exit 2 ;;
esac

if ! command -v docker >/dev/null 2>&1; then
    echo "erreur : docker est introuvable. Ce script doit tourner sur l'hote du proxy." >&2
    exit 1
fi

findings=0

section() {
    printf '\n\033[1m%s\033[0m\n' "$1"
}

# Un libelle par ligne : "<conteneur>\t<cle>=<valeur>"
#
# Le filtrage se fait en shell et non dans le template : le moteur de Docker n'expose pas
# `hasPrefix`, et un template invalide echoue en silence - le script rapporterait alors
# « aucun libelle » sur un hote qui en est plein.
all_labels() {
    # Les conteneurs ARRETES sont inspectes eux aussi : un service relance apres la bascule avec un
    # libelle obsolete casserait en silence, des semaines plus tard, sans lien apparent.
    docker ps $ps_args --format '{{.Names}}' | while IFS= read -r name; do
        docker inspect "$name" --format \
            '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}
{{end}}' 2>/dev/null | while IFS= read -r label; do
            case "$label" in
                traefik.*) printf '%s\t%s\n' "$name" "$label" ;;
            esac
        done
    done
}

labels="$(all_labels)"

section "1. Conteneurs portant des libelles Traefik (arretes compris)"
if [ -z "$labels" ]; then
    echo "  aucun - verifie que tu es bien sur l'hote du proxy"
    exit 1
fi
printf '%s\n' "$labels" | cut -f1 | sort -u | sed 's/^/  /'

# ─── Formes qui changent en v3 ───────────────────────────────────────────────────
#
# Sources : guide de migration officiel v2 -> v3. On ne signale que du mecanique ; le reste
# reste a la lecture humaine.

report() {
    local title="$1" pattern="$2" advice="$3" hits
    hits="$(printf '%s\n' "$labels" | grep -E "$pattern" || true)"
    if [ -n "$hits" ]; then
        findings=$((findings + 1))
        section "$title"
        printf '%s\n' "$hits" | sed 's/^/  /'
        printf '  \033[33m=> %s\033[0m\n' "$advice"
    fi
}

report "2. PathPrefix avec expression reguliere" \
    'PathPrefix\(`[^`]*[\\^$*+?()|[]' \
    "En v3 PathPrefix n'interprete plus les regex. Utiliser PathRegexp, ou garder la syntaxe v2 via --core.defaultRuleSyntax=v2."

report "3. Placeholders de chemin ({id}, {slug}...)" \
    'Path[A-Za-z]*\(`[^`]*\{[a-zA-Z]' \
    "Non supportes en v3. Migrer vers PathRegexp avec la syntaxe Go."

report "4. Matchers Headers / HeadersRegexp" \
    '(Headers|HeadersRegexp)\(' \
    "Renommes en Header / HeaderRegexp en v3."

report "5. Middleware IPWhiteList" \
    'ipwhitelist' \
    "Renomme en IPAllowList en v3."

report "6. Options d'en-tetes supprimees" \
    'headers\.(sslRedirect|sslTemporaryRedirect|sslHost|sslForceHost|featurePolicy)' \
    "Supprimees en v3. Remplacer avant la bascule."

report "7. StripPrefix forceSlash / ForwardAuth caOptional" \
    '(forceSlash|caOptional)' \
    "Options supprimees en v3."

report "8. Matcher HostHeader" \
    'HostHeader\(' \
    "Supprime en v3, remplacer par Host."

# ─── Verdict ────────────────────────────────────────────────────────────────────

section "Verdict"
if [ "$findings" -eq 0 ]; then
    cat <<'EOF'
  Aucune forme connue pour changer en v3 n'a ete trouvee dans les libelles des conteneurs
  en cours d'execution.

  Deux reserves, a ne pas confondre avec un feu vert :
    - les conteneurs ARRETES ne sont pas inspectes ; un service relance apres la bascule peut
      porter une forme obsolete ;
    - la configuration dynamique par fichier du proxy, si elle existe, n'est pas lue ici.

  Filet recommande quand meme : --core.defaultRuleSyntax=v2 au moment de la bascule.
EOF
else
    printf '  \033[31m%d categorie(s) a traiter avant la bascule.\033[0m\n' "$findings"
    cat <<'EOF'
  Deux voies :
    - corriger les libelles concernes, projet par projet ;
    - ou ajouter --core.defaultRuleSyntax=v2 au proxy, qui laisse la syntaxe v2 comprise, et
      migrer plus tard. Ne couvre PAS les middlewares renommes (IPWhiteList, options d'en-tetes) :
      ceux-la doivent etre corriges.
EOF
    exit 1
fi
