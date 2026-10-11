# Story 43.8: Classements entre amis

**Status:** review
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

- [x] **Application/Infrastructure** : paramètre `friendIds` dans `LeaderboardQueryInterface` / `DbalLeaderboardQuery`.
- [x] **Présentation** : `friendsOnly` dans `LeaderboardController`.
- [x] **WeeklyRuns** : `GET /weekly-runs/current/friends` (lecture via interface Application des amitiés).
- [x] **Front** : interrupteur sur la page classement, bloc sur la page hebdo.
- [x] Tests fonctionnels (rangs recalculés, anonyme, blocage, hebdo) et gates.

## Notes

- La branche `feature/epic-30-story-47-avatar-encadre-partout` touche déjà `LeaderboardQuery` : partir de
  `develop` une fois cette story mergée pour éviter les conflits.

## Dev Notes (2026-10-09)

- `FriendCircleQuery` (Community Application) : le viewer puis ses amis acceptés ayant une carte listable (bannis,
  suspendus et sans slug exclus). Un blocage supprime l'amitié (`FriendshipService::block`), donc un bloqué n'y est
  jamais.
- Classement : `LeaderboardQueryInterface` prend `?list $userIds` ; `DbalLeaderboardQuery` restreint les totaux à
  ces membres avant le tri, donc les rangs et le total sont recalculés dans le sous-ensemble. `LeaderboardQuery`
  résout le cercle (`friendsOnly`, anonyme = vide). `GET /leaderboard?friendsOnly=1` répond en
  `Cache-Control: private, no-store` (le classement public reste `public, max-age=60`).
- **Écart** : le bloc hebdo est servi par `GET /weekly-runs/{weeklyRunId}/friends` et non `/weekly-runs/current/friends` :
  plusieurs hebdos tournent en même temps (une par jeu), la page de l'hebdo en cours connaît déjà son id.
  Meilleure tentative par membre : objectif avant lancée avant inscrit, puis le temps le plus court.
- Front : bouton « Mes amis » (connecté) dans le classement de `/communaute`, mémorisé dans l'URL (`?amis=1`), la
  requête passe alors par `apiFetch` (cookie). L'URL est lue via `useSyncExternalStore` sur `window.location` et
  écrite par `history.replaceState` : `useSearchParams` sortait `/communaute` du prérendu (échec du build). Bloc « Tes amis cette semaine » sous le classement de la page d'une
  hebdo, affiché s'il y a au moins un ami inscrit.
- Tests : `FriendsLeaderboardTest`, `leaderboard-client.test.tsx`, `weekly-run-friends.test.tsx`.
