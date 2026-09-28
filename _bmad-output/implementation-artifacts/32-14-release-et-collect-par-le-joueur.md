# Story 32.14: Release et collect faits par le joueur, exclus des stats

**Status:** review
**Epic:** 32 - Récaps et stats
**Date:** 2026-09-28
**Dépôts :** `archilan.fr` (API), `bridge`

## Story

En tant que joueur ou admin d'ArchiLAN,
je veux qu'un slot released ou collected avant son objectif soit exclu des stats, que la commande vienne d'un
admin ou du joueur lui-même,
afin que les stats (profil, classement, achievements, niveaux, résultats de run) ne comptent pas une partie
abandonnée en cours de route.

## Contexte

Les stats excluent déjà un slot `was_released` sans objectif atteint, slot par slot (le reste de la run reste
compté). Mais le drapeau n'est posé que par `SendBridgeCommand`, quand un **admin** envoie depuis le site
`!admin /collect|release|forfeit <slot>`. Un `!release` ou `!collect` tapé par le joueur dans son client, quand
le mode de release le permet avant l'objectif, n'est pas détecté.

Le serveur Archipelago annonce pourtant chaque release et collect à tous (`MultiServer.release_player` /
`collect_player` : `PrintJSON` de type `Release` / `Collect` avec `team` et `slot`, texte « <nom> (Team #1) has
released all remaining items from their world. » / « … has collected their items from other worlds. »). Le
bridge le relaie déjà au site (`feed-push`, types `release` / `collect`), sans dire de quel slot il s'agit.

## Critères d'acceptation

1. Un événement de fil `release`, `collect` ou `forfeit` reçu du bridge pose `was_released` sur le slot concerné,
   avec la règle existante (`SessionSlot::markAsReleased` : sans effet si l'objectif est déjà atteint).
2. Le slot est identifié par `sender.name` de l'événement ; à défaut (bridge pas encore à jour), par le nom en
   tête du texte (« <nom> (Team #N) has released… » / « … has collected… »). Un nom inconnu de la session ne
   change rien.
3. Le **bridge** joint `sender` (`slot`, `name`, `game`) aux événements `release`, `collect` et `forfeit`, comme
   pour `goal`.
4. Un release automatique à l'objectif (le serveur release après le `Goal`) ne retire rien : l'objectif est déjà
   enregistré.
5. Une erreur dans ce marquage ne casse jamais la publication du fil.
6. Gates des deux dépôts verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 4, 5) - API : marquage dans `RecordSessionFeedEvent`, tests.
- [x] **Task 2** (AC 3) - Bridge : `sender` sur release / collect / forfeit, tests.
- [x] **Task 3** (AC 6) - Gates, PR.

## Dev Agent Record

- **API** : `RecordSessionFeedEvent` reçoit `SessionSlotRepositoryInterface` ; un événement `release`,
  `collect` ou `forfeit` appelle `markAsReleased()` sur le slot nommé par `sender.name`, sinon par le texte
  (`/^(.+?) \(Team #\d+\) has /`), puis `flush()`. Ces événements restent hors `PERSISTED_TYPES` (pas de ligne
  de fil). Le contrôleur rattrape déjà toute exception de `record()` : le fil est publié quoi qu'il arrive.
  Couvre les runs d'événement et les parties personnelles (même table `session_slot`) ; les runs hebdo, en solo,
  ont leur propre table et ne sont pas concernées.
- **bridge** : `_build_feed_event` joint `sender` à `release`, `collect`, `forfeit` comme à `goal` ;
  `BRIDGE_API.md` le documente.
- Le cas « release automatique après l'objectif » est couvert par la règle existante de `markAsReleased`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `RecordSessionFeedEventReleaseTest` (6 cas : sender, texte, forfeit, après objectif, inconnu, autre type) | 6 erreurs | verts |
| `tests/test_feed.py` (bridge, release / collect / forfeit) | 3 échecs | 204 passés |

Gates : `composer gates` (2452 tests) ; bridge : ruff, pytest, mypy verts.

### Déploiement

Indépendant : le repli sur le texte fait fonctionner l'API avant la mise à jour du bridge.
