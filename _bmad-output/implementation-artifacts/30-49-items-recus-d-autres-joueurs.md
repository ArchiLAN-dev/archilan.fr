# Story 30.49: Critère « Items reçus d'autres joueurs »

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-07

## Story

En tant qu'admin,
je veux un critère de succès qui ne compte que les items trouvés par un autre joueur,
afin que le succès « Je t'aime, je t'aime, je t'aime… » (cadre Mains de l'Envie) récompense ce qu'on reçoit des
autres, pas ce qu'on se donne à soi-même.

## Contexte

« Items reçus (total) » vient du compteur d'Archipelago par slot : il inclut les items de son propre monde et ceux
d'un autre slot que l'on joue. Demandé par Jean le 2026-10-07 : ne compter que les items venant d'un autre joueur, ni
du même slot ni d'un autre slot où l'on est ; puis, le même jour, sans les release ni les collect.

Le feed des parties (epic 32) garde chaque item avec son slot d'envoi et de réception depuis le 2026-07-26 ; chaque
slot mène à ses joueurs (propriétaire et co-joueurs, `DbalSlotPlayerSource`). Une release ou un collect ne
laissait qu'un booléen `was_released` sur le slot, sans date ni nature.

## Critères d'acceptation

1. **Nouveau critère** `itemsFromOthers`, « Items reçus d'autres joueurs (hors release et collect) », choisissable
   dans l'admin des succès comme les autres.
2. **Compte** un item reçu dans un slot que le membre joue (propriétaire ou co-joueur), envoyé par un slot qu'il ne
   joue pas. Le même slot, ou un autre de ses slots, ne compte pas. Une tentative hebdo (tous les slots sont les
   siens) ne compte pas.
3. **Release et collect** : un slot garde désormais la date de sa première release (ou abandon) et de son premier
   collect. Ne comptent pas les items envoyés par un slot depuis sa release, ni ceux reçus par un slot depuis son
   collect (marge de deux secondes, l'annonce pouvant arriver après les premiers items).
4. **Historique** : pour un slot sans ces dates (relâché avant), une rafale de 10 items ou plus dans la même seconde ne
   compte pas seulement si elle part d'un slot qui pouvait relâcher (marqué relâché, ou objectif déjà atteint : la
   release à l'objectif) ou arrive dans un slot qui pouvait collecter (objectif déjà atteint). Dix vrais checks dans
   la même seconde en cours de partie comptent (remarque de Jean).
5. Gates verts ; tests.

## Tasks / Subtasks

- [x] **Task 1** (AC 3) - `SessionSlot::recordHandOver()`, colonnes `released_at` / `collected_at` (migration),
  appel depuis `RecordSessionFeedEvent`.
- [x] **Task 2** (AC 1, 2, 4) - `AchievementMetricCatalog::FACT_ITEMS_FROM_OTHERS`, `ItemsFromOthersQueryInterface`,
  `DbalItemsFromOthersQuery`, `ItemsFromOthersMetricProvider`.
- [x] **Task 3** (AC 5) - `ItemsFromOthersTest` ; gates.

## Dev Agent Record

- Le critère ne voit que les parties depuis le 2026-07-26 (début du feed par item) : à garder en tête pour le seuil.
- Migration `Version20261007180000`.
