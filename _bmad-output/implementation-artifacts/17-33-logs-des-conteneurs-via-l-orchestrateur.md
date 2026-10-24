# Story 17.33: Logs des conteneurs d'une session via l'orchestrateur, fin de l'onglet Container

**Status:** review
**Epic:** 17 - Sessions et suivi des slots
**Date:** 2026-10-24

## Story

En tant qu'admin d'une session d'event,
je veux lire les logs des conteneurs de la session (serveur AP, bridge, génération),
afin de diagnostiquer une session sans passer par le serveur, sans boutons qui ne marchent pas.

## Contexte

L'onglet « Container » de la page admin d'une session date de l'époque du runner et n'a jamais été rebranché
sur l'orchestrateur :

- l'API vise un conteneur `archipelago-run-{sessionId}` ; l'orchestrateur crée `ap-server-{id}`,
  `archilan-bridge-{id}` et `archilan-gen-{id}` ;
- `api-web` ne monte pas le socket Docker en prod : l'état répond toujours « not_found », start, stop,
  restart et rm échouent ;
- les logs passent par `FetchLogsJob`, qui lance la commande `docker logs` dans le worker (absente de l'image)
  sur le mauvais nom, puis renvoie le résultat par le callback runner `status: logs` ;
- s'ils marchaient, ces boutons contourneraient l'orchestrateur (pool de ports, SQLite, sweeper).

Les actions de cycle de vie utiles (arrêt, relance, fin forcée) existent déjà et passent par l'orchestrateur.
Ce qui manque est la lecture des logs, que seul l'orchestrateur peut faire (il a le socket et les noms).

Décision Jean (2026-10-24) : version complète, trois dépôts (orchestrateur, client PHP, monorepo). Le manque
dans `archilan/orchestrateur-client` (aucune méthode de logs) est validé par cette décision (règle
`packages/CLAUDE.md`).

## Critères d'acceptation

### Orchestrateur (v0.23.0)

1. `GET /sessions/{sessionId}/logs?container=ap|bridge|gen&tail=N`, derrière la clé d'API.
   - `container` obligatoire, sinon 400 `invalid_container`.
   - `tail` par défaut 200, borné à 1..2000 (hors bornes ou non entier : 400 `invalid_tail`).
   - Réponse 200 `{"container": "<nom docker>", "found": true|false, "logs": "<texte>"}` : stdout et stderr
     dans l'ordre d'émission, horodatés par Docker. Conteneur absent : `found: false`, `logs: ""` (pas de 404,
     qu'un client lirait comme une session absente).
2. Les noms suivent ceux que l'orchestrateur crée (`ap-server-`, `archilan-bridge-`, `archilan-gen-`).
3. Documenté en swagger, testé (handler et démultiplexage des trames Docker).

### Client PHP (v1.16.0)

4. `SessionsClient::logs(string $sessionId, string $container, int $tail = 200): SessionLogs`
   (`container`, `found`, `logs`). Un orchestrateur sans la route (404) lève `NotFoundException`, pas
   `SessionNotFoundException`. Version du `composer.json` bumpée avant le tag.

### API

5. `GET /api/v1/admin/sessions/{id}/logs?container=ap|bridge|gen` (admin) : session inconnue 404, conteneur
   invalide 400, orchestrateur injoignable ou trop ancien 503 ; sinon `{data: {container, found, logs,
   fetched_at}}`.
6. Supprimés : `ContainerController` (GET/POST `/container`), `FetchLogsJob` et son handler, la branche
   `status: logs` du callback runner et `SessionLifecycleManager::storeLogs`, `DockerSocketClient` (plus aucun
   usage). `Session.lastLogs` reste (journal d'échec de génération).

### Front

7. L'onglet « Container » devient « Logs » : choix du conteneur (Serveur AP, Bridge, Génération), bouton
   Rafraîchir, rafraîchissement auto toutes les 5 s quand la session tourne, message clair si le conteneur
   n'existe pas ou si l'orchestrateur ne répond pas. Plus de carte d'état ni de boutons start/stop/rm.

## Tâches

- [x] Orchestrateur : `docker.Client.Logs(ctx, name, tail)` (stdout+stderr), `Service.SessionLogs`, handler,
      route, swagger, tests ; PR + tag v0.23.0 (sur accord).
- [x] Client : `SessionLogs`, `SessionsClient::logs`, tests, version 1.16.0 ; PR + tag (sur accord).
- [x] API : port `SessionContainerLogsInterface` + adaptateur orchestrateur, query, contrôleur ; suppressions ;
      tests fonctionnels ; montée du client dans `composer.lock`.
- [x] Front : onglet Logs, API, suppressions, tests.

## Déploiement

Orchestrateur v0.23.0 avant l'API : sans lui, l'onglet affiche « orchestrateur trop ancien » (503), rien ne
casse ailleurs.

## Dev Agent Record

- PRs : orchestrateur ArchiLAN-dev/archilan-orchestrateur#37, client ArchiLAN-dev/archilan-orchestrateur-client#18, monorepo (cette branche).
- L'API lit 500 lignes (`SessionContainerLogsQuery::TAIL`), le front rafraîchit toutes les 5 s quand la session tourne.
- « Créer » (`POST /force-launch`) passait déjà par l'orchestrateur et n'existait que dans cet onglet : gardé sous
  « Forcer le lancement », le fetch déplacé dans `admin-sessions-api.ts` (`forceLaunchSession`).
- `composer.lock` de l'API à monter sur le client v1.16.0 une fois tagué : d'ici là la CI backend échoue sur
  `SessionsClient::logs` absent. Vérifié en local avec le client de la branche copié dans `vendor` (phpstan,
  cs-fixer, ddd, rector, unitaires verts) ; fonctionnels laissés à la CI (Postgres local éteint).
- `DOCKER_HOST` (`.env`, `docker-compose.yml`) et le montage du socket sur `api-worker` et `api-migrations` ne
  servent plus à ces usages ; laissés en place, à retirer dans un ménage d'infra séparé.
