# Story 43.8: Classements entre amis

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur,
je veux filtrer les classements sur mes amis,
afin de me comparer aux gens que je connais plutôt qu'aux meilleurs du site.

## Contexte

Le classement communautaire (`LeaderboardController`, paramètres `axis`, `page`, `limit`, `eventId`) n'a pas de
filtre amis. L'annuaire en a un (`CommunityDirectory`, `friendsOnly`), avec la règle « anonyme + amis = vide ».
Les hebdos sont des courses solo (`WeeklyRunEntry` : `completionTimeSeconds`, `goalReachedAt`) : la comparaison
entre amis y a tout son sens.

## Critères d'acceptation

1. Le classement communautaire accepte `friendsOnly=1` : le viewer et ses amis acceptés seulement, rangs
   recalculés dans ce sous-ensemble. Anonyme + `friendsOnly` = liste vide (même règle que l'annuaire).
2. La page classement a un interrupteur « Mes amis » (visible connecté), mémorisé dans l'URL.
3. La page de l'hebdo en cours affiche un bloc « Tes amis cette semaine » : amis ayant une entrée, statut
   (lancé / objectif atteint), temps, classés par temps, avec le viewer inclus.
4. Blocages exclus ; comptes bannis exclus.
5. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application/Infrastructure** : paramètre `friendIds` dans `LeaderboardQueryInterface` / `DbalLeaderboardQuery`.
- [ ] **Présentation** : `friendsOnly` dans `LeaderboardController`.
- [ ] **WeeklyRuns** : `GET /weekly-runs/current/friends` (lecture via interface Application des amitiés).
- [ ] **Front** : interrupteur sur la page classement, bloc sur la page hebdo.
- [ ] Tests fonctionnels (rangs recalculés, anonyme, blocage, hebdo) et gates.

## Notes

- La branche `feature/epic-30-story-47-avatar-encadre-partout` touche déjà `LeaderboardQuery` : partir de
  `develop` une fois cette story mergée pour éviter les conflits.
