# Story 17.34: L'API ne monte plus le socket Docker

**Status:** review
**Epic:** 17 - Sessions et orchestrateur
**Date:** 2026-10-24
**Origine :** reste de la story 17.33 (le code qui lisait les logs par le socket Docker a été retiré) ; traité à la
demande de Jean le 2026-10-24.

## Story

En tant qu'exploitant,
je veux que seuls les conteneurs qui pilotent Docker aient accès à son socket,
afin qu'une faille dans l'API ou son worker ne donne pas la main sur l'hôte.

## Contexte

- Depuis la 17.33, plus aucun code de l'API n'appelle Docker : seul l'orchestrateur pilote le démon.
- `api-migrations` et `api-worker` montaient encore `/var/run/docker.sock` en prod, et `DOCKER_HOST` traînait dans
  `api/.env` et `envs/api.env.example`. Monter le socket équivaut à un accès root à l'hôte.

## Critères d'acceptation

1. `docker-compose.prod.yml` : plus de montage du socket hors `orchestrateur`.
2. `DOCKER_HOST` retiré de `api/.env` et de `envs/api.env.example` ; le bloc API commenté de `docker-compose.yml`
   ne le cite plus.
3. L'orchestrateur garde son socket et son `group_add`.
4. Mise en prod : recréer `api-worker` (et `api-migrations` au prochain lancement) ; retirer `DOCKER_HOST` de
   `envs/api.env` sur le serveur (sans effet s'il reste).

## Tâches

- [x] Compose prod et dev, fichiers d'env.
- [x] Vérification : YAML valide, seul `orchestrateur` monte le socket ; aucun usage de `DOCKER_HOST` dans `api/src`
      ni `api/config`.
