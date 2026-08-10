# Template de la configuration statique Traefik.
#
# Le fichier réellement monté dans le conteneur, traefik/traefik.yml, est GÉNÉRÉ depuis celui-ci
# par scripts/gen-traefik-config.sh et n'est pas commité : il contient le token du provider HTTP.
#
# Éditer CE fichier, jamais le rendu.
#
# Pourquoi un template plutôt que des variables d'environnement : Traefik n'interpole aucune
# variable dans sa configuration statique, et fichier / arguments CLI / variables d'environnement
# sont trois sources mutuellement exclusives. Une variable laissée telle quelle dans ce fichier
# partirait littéralement chez Let's Encrypt. La substitution se fait donc avant le démarrage, à la
# génération.

api:
  dashboard: true
  insecure: true   # dashboard sur http://localhost:8080/dashboard/

entryPoints:
  web:
    address: ":80"
    http:
      redirections:
        entryPoint:
          to: websecure
          scheme: https
  websecure:
    address: ":443"
{{AP_ENTRYPOINTS}}

providers:
  docker:
    exposedByDefault: false
    network: archilan-proxy
  file:
    directory: /etc/traefik/dynamic
    watch: true
  # Routeurs des serveurs Archipelago, produits par l'API (un routeur par session en cours).
  # L'endpoint est joint par le réseau interne archilan-proxy : passer par le nom public ferait
  # dépendre Traefik de son propre routage au démarrage.
  http:
    endpoint: "${TRAEFIK_HTTP_PROVIDER_ENDPOINT}"
    # Délai maximum entre le passage d'une session à `running` et le moment où elle devient
    # joignable. 5s est le défaut Traefik : assez court pour un lancement de partie, assez long
    # pour ne pas marteler l'API en permanence.
    pollInterval: "${TRAEFIK_HTTP_PROVIDER_POLL_INTERVAL}"
    pollTimeout: "${TRAEFIK_HTTP_PROVIDER_POLL_TIMEOUT}"
    headers:
      X-Traefik-Token: "${TRAEFIK_TOKEN}"

certificatesResolvers:
  letsencrypt:
    acme:
      email: "${ACME_EMAIL}"
      storage: /certs/acme.json
      dnsChallenge:
        provider: ovh
        resolvers:
          - "213.186.33.99:53"   # DNS OVH pour propagation rapide

log:
  level: INFO

accessLog: {}
