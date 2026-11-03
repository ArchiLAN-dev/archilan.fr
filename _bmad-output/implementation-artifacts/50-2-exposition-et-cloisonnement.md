# Story 50.2 - Exposition et cloisonnement des sessions

Status: review

## Contexte

Durcissement issu de l'audit de sécurité du 2026-11-03.

## Acceptance Criteria

1. Postgres n'écoute que sur `127.0.0.1` de l'hôte.
2. L'orchestrateur et la console MinIO n'ont plus de route publique.
3. Les identifiants MinIO n'ont plus de valeur par défaut côté API.
4. Le bucket `media-public` autorise la lecture d'objets, pas leur liste.
5. Un serveur Archipelago ne rejoint que `archilan-sessions` et `archilan-ap-edge`, un bridge que
   `archilan-sessions` et `archilan-callbacks`, et ces réseaux n'ont pas accès à Internet.
6. Les conteneurs de session perdent leurs capacités inutiles, n'obtiennent pas de nouveaux
   privilèges et ont une limite de processus.
7. L'authentification de l'orchestrateur compare la clé en temps constant et protège `/docs`.

## Tasks

- [x] compose, exemples d'env, doc de déploiement
- [x] orchestrateur : `BRIDGE_CALLBACK_NETWORK`, limites des conteneurs, auth

## Notes

- La bascule réseau suppose quatre commandes sur l'hôte et une ligne dans le compose du proxy, hors de
  ce dépôt : voir `docs/deploiement-production.md`.
- Validé en local : réseau interne (DNS ok, pas d'accès sortant), serveur Archipelago réel sous les
  nouvelles limites. Non validé : une run complète derrière le vrai Traefik.
