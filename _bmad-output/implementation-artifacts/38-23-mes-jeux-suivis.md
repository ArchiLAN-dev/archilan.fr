# Story 38.23: La liste des jeux que je suis

**Status:** review
**Epic:** 38 - Santé des apworlds
**Date:** 2026-10-24
**Origine :** suite de la 38.22, dont elle était hors périmètre ; demandée par Jean le 2026-10-24.

## Story

En tant que membre connecté,
je veux voir les jeux que je suis et arrêter d'en suivre un,
afin de gérer mes alertes d'apworld sans repasser par la page de chaque jeu.

## Critères d'acceptation

1. `GET /api/v1/me/followed-games` (auth) : `[{gameId, gameName, gameSlug}]` triés par nom ; un jeu supprimé n'y
   figure pas ; 401 pour un visiteur.
2. Sur `/compte/notifications`, sous les préférences, une carte « Jeux que je suis » : chaque jeu mène à sa page et a
   un bouton « Ne plus suivre » (le `DELETE` de la 38.22). Liste vide : une phrase explique comment suivre un jeu.
3. Pas de suivi depuis le catalogue ni d'image de jeu (hors périmètre).

## Tâches

- [x] Repository `followedGameIds`, `GameFollowQuery::followedBy`, route `me/followed-games`.
- [x] Front : `fetchFollowedGames`, `FollowedGamesCard`, page notifications.
- [x] Tests : fonctionnels (liste triée, visiteur), front (rendu, liste vide, garde de type).
