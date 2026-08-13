# Déploiement de production - état réel de la machine

Ce document existe parce que le dépôt a décrit pendant longtemps une infrastructure qui n'était pas
celle qui tourne. Deux stories de l'epic 37 ont été construites sur ces hypothèses fausses avant
qu'on s'en aperçoive. **Quand ce document et la machine divergent, c'est la machine qui a raison, et
c'est ce document qui doit bouger.**

Aligné sur la machine le **2026-08-13**.

## Topologie

- Le **reverse proxy n'est pas dans ce dépôt** : il sert plusieurs projets de l'hôte et vit dans son
  propre compose. Voir [`traefik-runs-archipelago.md`](traefik-runs-archipelago.md).
- Le réseau partagé avec lui s'appelle **`traefik`** (externe, créé par le compose du proxy). Les
  services qui doivent être routés le rejoignent et portent `traefik.docker.network=traefik`.
- Le certresolver déclaré dans le proxy s'appelle **`https`** (challenge TLS-ALPN sur le 443), pas
  `letsencrypt`. Toute étiquette ou configuration qui nomme un autre resolver est silencieusement
  inopérante : Traefik sert son certificat par défaut.
- Les images sont **épinglées par version**, pas suivies en `latest` :
  `ARCHILAN_VERSION`, `ORCHESTRATEUR_VERSION`, `ARCHIPELAGO_VERSION`, `BRIDGE_VERSION`.

## Déployer une version

```bash
# .env de production
ARCHILAN_VERSION=0.14.0
ORCHESTRATEUR_VERSION=...

docker compose pull
docker compose --profile images pull      # images archipelago et bridge
docker compose up -d
```

`api-migrations` joue les migrations Doctrine et s'arrête ; c'est un service à cycle court, pas un
démon.

## Écarts connus entre le dépôt et la machine

À arbitrer, pas à oublier. L'état ci-dessous est celui constaté le 2026-08-13.

| Point | Dépôt | Machine | Conséquence |
|---|---|---|---|
| Bucket `media-public` + accès anonyme | créé par `createbuckets` | **absent** | Les URLs publiques d'images de l'epic 34 retombent sur des liens présignés qui tournent. C'est le handoff ops de cet epic, toujours ouvert. |
| Volume `api-logs` | monté sur api-web et api-worker | absent | Les logs applicatifs restent dans la couche du conteneur et disparaissent à chaque `up -d`. |
| `extra_hosts: host.docker.internal` | sur api-web et api-worker | absent | À vérifier : `BRIDGE_HTTP_HOST` doit alors pointer ailleurs, sinon l'API ne joint pas le bridge d'une session. |
| `group_add` de l'orchestrateur | `${DOCKER_GID}` | `994` en dur | Le dépôt utilise désormais `${DOCKER_GID:-994}` : les deux fonctionnent. |
| Postgres | non publié | **`5434:5432` publié sur l'hôte** | La base est joignable depuis l'extérieur si le pare-feu ne la filtre pas. À confirmer. |
| MinIO | ports 9000/9001 publiés | routé par le proxy sur deux domaines | La machine est plus propre que le dépôt ne l'était. |

## Pourquoi cette dérive coûte cher

Trois hypothèses fausses ont été écrites dans des stories et du code avant d'être découvertes :

1. un fichier `traefik/traefik.yml` versionné qu'aucun processus n'a jamais lu - ce qui a masqué un
   `${ACME_EMAIL}` jamais interpolé ;
2. un certresolver nommé `letsencrypt`, inexistant sur la machine - panne silencieuse garantie côté
   navigateur ;
3. un réseau `archilan-proxy` que le proxy n'a jamais rejoint.

Aucune de ces erreurs n'aurait été rattrapée par les tests : elles portent sur ce qui tourne, pas
sur ce qui se compile. La seule parade est de tenir ce document à jour au moment où la machine
change, et de le lire avant toute story qui touche à l'infrastructure.
