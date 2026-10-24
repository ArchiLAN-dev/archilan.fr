# Story 27.13: Le push du suivi en direct ne dévoile plus le contenu des emplacements

**Status:** review
**Epic:** 27 - Configuration des sessions
**Date:** 2026-10-24
**Origine :** point ouvert relevé pendant la story 27.12 ; traité à la demande de Jean le 2026-10-24.

## Story

En tant que joueur,
je veux que le suivi en direct d'un slot ne me montre que ce que l'API me montre déjà,
afin que personne ne lise le contenu des emplacements (ni d'un autre slot) en écoutant Mercure.

## Contexte

- `GET .../slots/{n}/reachable` retire `item` de chaque emplacement pour un joueur et retire les sphères quand la
  config ne les montre pas (27.12). Le push (`ReachablePushController`, appelé par le bridge) publiait au contraire le
  résultat brut du bridge sur `runs/{session}/slots/{n}/reachable`.
- Le jeton d'abonnement à ce topic est délivré à tout participant de la session (`isAuthorized`), pour n'importe
  quel slot, et côté hebdo à tout inscrit de l'entrée. Un joueur pouvait donc lire, pour chaque slot, quel objet
  se trouve à quel emplacement, et les sphères même désactivées.

## Critères d'acceptation

1. Le push publie trois variantes : `.../reachable/full` (brute, admin), `.../reachable/spheres` (sans `item`,
   sphères gardées) et `.../reachable` (sans `item`, sans sphères).
2. Le jeton d'abonnement donne `full` à un admin, `spheres` à un joueur qui voit les sphères selon la règle de
   27.12 (propriétaire d'une partie privée, ou config qui les montre), `reachable` sinon. Même règle côté hebdo
   (réglage du modèle ; l'admin y garde la vue joueur, comme l'API REST hebdo).
3. Le retrait des récompenses vit dans une seule classe applicative, partagée par l'API REST (sessions et hebdos)
   et le push.
4. Aucune modification du bridge ni du front : le front s'abonne au topic que lui renvoie l'API.

## Tâches

- [x] `ReachabilityForPlayer` (Application/Support) : retrait des `item` et des sphères, utilisé par
      `PlayerStateController`, `WeeklyRunSlotStateController` et le push.
- [x] `ReachablePushController` : trois publications.
- [x] Jetons : topic choisi selon l'utilisateur, sessions et hebdos.
- [x] Tests : variantes publiées, topic du jeton (admin, joueur avec et sans sphères), unitaire du retrait.
