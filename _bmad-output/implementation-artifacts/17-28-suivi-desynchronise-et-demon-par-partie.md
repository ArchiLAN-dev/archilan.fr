# Story 17.28: Suivi des slots - démon désynchronisé, un démon par partie, calcul asynchrone

**Status:** review
**Epic:** 17 - Sessions et serveurs de partie
**Date:** 2026-10-07

## Story

En tant que joueur d'une grosse partie,
je veux que la page de mon slot affiche toujours ce que je peux faire,
afin de ne plus tomber sur « Impossible de contacter l'API » ni attendre un calcul qui n'aboutit pas.

## Contexte

Signalé par Jean (2026-10-07) sur une partie de 18 slots : « Impossible de contacter l'API » sur certains slots,
redémarrer le serveur n'en répare qu'une partie, et « Réessayer » renvoie aussitôt
`{data: {ready: true, cached: true, player: "kionx_C"}}`.

**Cause** : `item-locations` (bridge) attendait le calcul de chaque slot non calculé 8 s puis l'**annulait**. Depuis
le suivi exact (archipelago v0.16.2, fin septembre), chaque démon régénère tout le multiworld : sur 18 joueurs, son
démarrage dépasse largement 8 s. L'annulation tombait après l'enregistrement du démon mais avant la lecture de sa
ligne `{"ready": true}` ; la requête suivante lisait cette ligne comme résultat, le bridge la mettait en cache
(clé : checks et items du slot) jusqu'au prochain check, et le front plantait dessus (`items_received` absent),
ce que son `catch` affichait en « Impossible de contacter l'API ».

Jean a demandé de réduire au maximum le CPU et la mémoire (serveur modeste), et de ne plus attendre le calcul
(« on reçoit l'info quand elle est prête »). Il a refusé d'arrêter le calcul des slots qui ont atteint leur goal :
ils peuvent encore bloquer d'autres jeux.

## Critères d'acceptation

1. **Désynchronisation** (bridge) : un démon n'est confié qu'une fois sa ligne `ready` lue ; un appelant annulé
   ferme le démon ; une réponse sans `counts` n'est jamais mise en cache et relance le démon.
2. **Un démon par partie** (archipelago + bridge) : `reachable.py --daemon --session` régénère le monde une fois et
   répond pour tous les slots (la requête porte son `slot`) ; un slot en repli construit son monde à un joueur à la
   demande ; un slot en échec garde son erreur sans bloquer les autres. Résultats identiques au mode par slot.
3. **Libération** : le démon d'une partie sans requête depuis 30 min est fermé.
4. **Asynchrone** : le bridge répond sous 3 s, sinon 202 `{computing, previous}` ; le calcul continue et son résultat
   est poussé au site une fois prêt. `item-locations` n'attend plus. L'API relaie le 202 (`{data: previous|null,
   computing: true}`, sans les récompenses pour un joueur).
5. **Front** (3 pages de slot) : « calcul en cours » (ou l'ancien résultat avec « Actualisation… ») remplacé par le
   push ; nouvel essai toutes les 15 s si le push ne vient pas ; une réponse invalide dit « le calcul n'a pas
   abouti », plus jamais « Impossible de contacter l'API ».
6. Gates verts dans les trois dépôts ; tests de non-régression.

## Tasks / Subtasks

- [x] **Task 1** (AC 2) - archipelago : `_SlotTracker`, `_SessionTrackers`, `_serve`, mode `--session` ; banc.
- [x] **Task 2** (AC 1, 3, 4) - bridge : démon de partie, garde-fous, libération, 202, publication, item-locations.
- [x] **Task 3** (AC 4, 5) - API : relais du 202 ; front : `reachableAnswerOf`, état de calcul, nouvel essai.
- [x] **Task 4** (AC 6) - Gates des trois dépôts.

## Dev Agent Record

- **archipelago** (PR archilan-archipelago #31) : `reachable.py` découpé en `_SlotTracker` (données et calcul d'un
  slot, inchangé) et `_SessionTrackers` (monde exact chargé une fois, suivi par slot à la demande, échecs gardés) ;
  `_serve` route chaque requête vers son slot. Mode par slot et mode ponctuel conservés. Banc dans l'image sur une
  partie de 6 joueurs (chemin exact) : résultats identiques slot par slot, 2,2 s pour le démon de partie contre
  11,3 s pour un processus par slot, ~200 Mo au pic chacun (donc ~200 Mo en tout au lieu de ~200 Mo par slot).
- **bridge** (PR Archipelago-Bridge #17) : `DockerRuntimeAdapter` un démon par partie (`--daemon --session`),
  verrou de démarrage, démon confié une fois prêt, fermé sur annulation ou réponse décalée (`reset_reachable`),
  `release_idle` (30 min) appelé par la boucle ; `_result_error` refuse une réponse sans `counts` (`OUT_OF_TURN`) ;
  `start_reachable` / `compute_reachable_shared` (calcul partagé, jamais annulé par un appelant) ; publication
  (`make_reachable_publisher` : diffusion, `reachable-push`, grille) d'un calcul lancé hors de la boucle ;
  `GET /slots/{n}/reachable` 200 sous 3 s ou 202 ; `item-locations` sans attente. `BRIDGE_API.md` à jour.
- **API** : `PlayerStateController::slotReachable` et `WeeklyRunSlotStateController::reachable` relaient le 202.
- **Front** : `reachableAnswerOf`, `REACHABILITY_INVALID_MESSAGE`, `REACHABILITY_RETRY_MS` ; les trois pages de slot
  (run perso, hebdo, admin) gèrent le calcul en cours et le nouvel essai.
- Gates : archipelago 150, bridge 215 (ruff, mypy), `composer gates` 2 797, `pnpm gates` 858.

## Déploiement (ordre)

1. Image **archipelago** (mode `--session`, rétrocompatible).
2. **Site** (API qui sait relayer le 202, front qui sait l'afficher).
3. **Bridge** (démon de partie, 202) : il exige les deux précédents.
