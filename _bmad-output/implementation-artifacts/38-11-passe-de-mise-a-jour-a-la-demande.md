# Story 38.11: Passe de mise à jour à la demande

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-27
**Origine :** demande de Jean le 2026-09-27 - pouvoir lancer la chaîne de mise à jour (soumission, test,
promotion) sans attendre la nuit, pour profiter d'un serveur peu utilisé.

## Story

En tant qu'admin d'ArchiLAN,
je veux lancer à la demande la passe de mise à jour automatique des apworlds, avec le nombre de mises à jour de
mon choix,
afin d'écouler les mises à jour en attente quand le serveur est libre, plutôt que dix par nuit.

## Contexte

La mise à jour automatique (stories 38.5 et 38.6) ne soumet ses candidats que depuis la passe de nuit (04:00), au
plus `APWORLD_AUTO_UPDATE_BATCH_SIZE` (10) par nuit. `app:check-apworld-updates` ne faisait que vérifier. En prod
le 2026-09-27 : 223 mises à jour disponibles, 10 soumises et promues la nuit, 225 reportées, soit plus de trois
semaines au rythme de la nuit.

`app:apworlds:incidents-reconcile` décidait déjà à la demande des candidats en test ; il manquait la soumission.
`GITHUB_TOKEN`, sans lequel la vérification est entièrement sautée, n'était pas documenté dans
`envs/api.env.example`.

## Critères d'acceptation

1. **`app:check-apworld-updates --submit`** vérifie puis soumet les mises à jour trouvées exactement comme la
   passe de nuit (même service, mêmes exclusions : candidat déjà en test, version déjà rejetée, release ambiguë).
2. **`--limit=N`** (1 à 200, avec `--submit` seulement) remplace le plafond de la nuit pour ce lancement ;
   sans lui, le plafond `APWORLD_AUTO_UPDATE_BATCH_SIZE` s'applique. Hors bornes ou sans `--submit` : refusé.
3. **Sans `--submit`**, rien n'est soumis ; la sortie rappelle comment appliquer.
4. La sortie donne le bilan (soumises, reportées, sautées, en échec, ambiguës) et rappelle que la promotion
   se fait par la réconciliation (toutes les 5 minutes, ou `app:apworlds:incidents-reconcile`).
5. **`envs/api.env.example`** documente `GITHUB_TOKEN`, `APWORLD_AUTO_UPDATE_BATCH_SIZE`,
   `APWORLD_SWEEP_BATCH_SIZE` et `DISCORD_STAFF_WEBHOOK_URL`.
6. `composer gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - `SubmitAvailableApworldUpdates::submit(..., ?int $batchSize)`.
- [x] **Task 2** (AC 1-4) - Options `--submit` et `--limit` de la commande.
- [x] **Task 3** (AC 5) - `envs/api.env.example`.
- [x] **Task 4** (AC 6) - Gates.

## Dev Agent Record

- La passe de nuit (`CheckApworldUpdatesHandler`) est inchangée ; la commande appelle les mêmes services.
- Borne haute à 200, comme le test tournant. Chaque candidat est une génération de test sur l'orchestrateur,
  deux à la fois par défaut, et un candidat sans verdict après 30 minutes expire (il sera retenté) : quelques
  dizaines par lancement est le bon ordre de grandeur.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `CheckApworldUpdatesCommandTest` : sans `--submit` rien n'est soumis ; `--submit` applique le plafond de la nuit ; `--limit` le remplace ; limite hors bornes ou sans `--submit` refusée | 5 échecs (options absentes) | verts, avec `SubmitAvailableApworldUpdatesTest` et `CheckApworldUpdatesHandlerTest` |

### Utilisation en prod

```
docker compose exec api-worker php bin/console app:check-apworld-updates --submit --limit=40
# puis, une fois les tests passés (ou attendre la réconciliation de 5 minutes) :
docker compose exec api-worker php bin/console app:apworlds:incidents-reconcile
```
