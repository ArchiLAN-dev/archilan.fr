# Story 28.17 - Mettre en avant les jeux récemment ajoutés sur /jeux

## Status

review

## Story

**En tant que** joueur qui revient sur la bibliothèque,
**je veux** voir d'un coup d'oeil les jeux ajoutés depuis ma dernière visite,
**afin de** découvrir les nouveautés sans relire tout le catalogue.

## Contexte

`/jeux` ne propose que deux tris, « Nom A→Z » et « Nom Z→A », appliqués côté client par
`filterAndSortGames` sur la liste complète (`GET /games?all=1`, ISR 5 min). Le tri vit dans l'URL
(`?tri=`, story 28.12).

La table `game` porte déjà `created_at`, mais `DbalGameCatalogQuery` ne l'expose pas dans la liste.
Les imports initiaux (début juin 2026) ont créé plusieurs centaines de jeux le même jour : ils
partagent quasiment la même date, ce qui est sans gêne pour un tri (départage par nom) mais impose
une fenêtre courte pour le badge.

Limite connue : il n'existe pas de date de passage en catalogue public. Un jeu créé masqué puis
publié plus tard apparaît à sa date de création. Accepté pour cette story.

## Acceptance Criteria

**AC1 - L'API expose la date d'ajout.** Chaque jeu de `GET /games` (paginé et `all=1`) porte
`addedAt` (ISO 8601, depuis `game.created_at`). Le détail n'est pas concerné.

**AC2 - Tri « Ajouts récents ».** Le sélecteur de tri propose « Ajouts récents » : du plus récent au
plus ancien, départage par nom A→Z. Il se reflète dans l'URL en `?tri=recents` et se restaure au
retour arrière, comme les autres tris. « Nom A→Z » reste le tri par défaut.

**AC3 - Badge « Nouveau ».** Une carte de jeu ajouté depuis moins de 30 jours porte un badge
« Nouveau », quel que soit le tri ou le filtre actif.

**AC4 - Compatibilité.** Un jeu sans `addedAt` (ancienne réponse d'API) reste affiché : il est
classé en dernier par « Ajouts récents » et n'a pas de badge.

**AC5 - Tests et gates.** Test fonctionnel API (`addedAt` présent), tests unitaires front (ordre du
tri, départage, `?tri=recents` aller-retour, seuil du badge, jeu sans date). `composer gates` et
`pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC1) - `DbalGameCatalogQuery::selectGames` + `mapRow` : `addedAt` ; test fonctionnel.
- [x] **Task 2** (AC2, AC4) - `SortOrder` gagne `added-desc` ; `filterAndSortGames` ;
  `catalog-url-filters` (slug `recents`) ; option du `<select>` dans `games-catalog.tsx`.
- [x] **Task 3** (AC3) - `isRecentlyAdded(game, now)` dans `games-filter.ts` ; badge dans `game-card.tsx`.
- [x] **Task 4** (AC5) - Tests et gates.

## Dev Agent Record

- API : `DbalGameCatalogQuery` sélectionne `game.created_at` et l'expose en `addedAt` (ATOM) dans la liste
  paginée et `all=1` ; test `PublicGameCatalogAddedAtTest`.
- Front : `SortOrder` `added-desc` (plus récent d'abord, départage par nom, sans date en dernier), écrit
  `tri=recents` dans l'URL ; `isRecentlyAdded` (30 jours, `RECENTLY_ADDED_DAYS`) ; badge « Nouveau » dans
  `GameCard`, calculé par le catalogue avec une seule date de référence ; `addedAt` optionnel dans `PublicGame`.
- Tests : `games-filter.test.ts`, `catalog-url-filters.test.ts`.
