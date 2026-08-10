# Traefik - reverse proxy de production

Traefik porte **tout** le trafic entrant : site, API, Mercure, MinIO, orchestrateur, et depuis
l'epic 37 les serveurs Archipelago de chaque run. Le redémarrer coupe tout, simultanément.

## Fichiers

| Fichier | Nature |
|---|---|
| `traefik.yml.tpl` | **Source**, commitée. C'est ce fichier qu'on édite. |
| `traefik.yml` | **Généré**, non commité (il contient le token du provider HTTP). |
| `docker-compose.yml` | Commité. Son bloc `ports` est réécrit par le générateur entre marqueurs. |
| `.env` | Secrets et plage de ports, non commité. Modèle : `.env.example`. |
| `dynamic/` | Configuration dynamique par fichier (rechargée à chaud, sans redémarrage). |

Pourquoi un template : Traefik **n'interpole pas** `${VAR}` dans sa configuration statique, et
fichier / CLI / variables d'environnement sont trois sources mutuellement exclusives. Un
`${ACME_EMAIL}` laissé dans le fichier part littéralement chez Let's Encrypt. La substitution se
fait donc avant le démarrage.

## Générer la configuration

```bash
./scripts/gen-traefik-config.sh              # rend traefik.yml + réécrit le bloc de ports
./scripts/gen-traefik-config.sh --check      # ne rien écrire, sortir 1 si dérive
```

Le script dérive la plage des serveurs Archipelago de `PORT_RANGE_START`, `PORT_RANGE_END` et
`AP_SERVER_PORT_OFFSET` - les mêmes variables que celles de l'orchestrateur. Avec les valeurs de
production, il ouvre **35000-35099**, soit cent runs simultanés. Un entrypoint par port, nommé
`ap-{port}` : c'est la convention sur laquelle l'API construit ses routeurs (story 37.2).

**Ne jamais écrire ces entrypoints à la main.** Deux sources de vérité pour une plage de ports,
c'est une désynchronisation silencieuse qui attend le jour où quelqu'un élargit le pool.

## Déployer un changement de configuration

```bash
git pull
./scripts/gen-traefik-config.sh
cd traefik && docker compose up -d
```

- `git pull` **supprime** `traefik.yml` s'il était encore suivi par git (il ne l'est plus depuis
  l'epic 37) : générer avant de redémarrer, sinon le conteneur repart sans configuration.
- `docker compose up -d` **recrée** le conteneur quand la liste des ports a changé. Les entrypoints
  sont de la configuration statique : aucun rechargement à chaud n'est possible.
- **Toutes les connexions en cours tombent**, y compris les parties Archipelago. Créneau calme,
  et prévenir.

Élargir le pool de runs suit exactement le même chemin : modifier `PORT_RANGE_*` dans `.env` (des
deux côtés, orchestrateur et Traefik), régénérer, redémarrer.

## Vérifier après redémarrage

```bash
docker compose logs traefik | grep -iE "error|certificate"
curl -sI https://archilan.fr | head -1                     # le site répond
ss -lntp | grep -c ':350[0-9][0-9]'                        # la plage écoute
```

Le provider HTTP est visible dans le dashboard (`http://localhost:8080/dashboard/`) : un routeur
`run-{sessionId}` doit apparaître par session en cours.
