# Story 17.30: Le nom du slot sur la page d'un jeu hebdo

**Status:** review
**Epic:** 17 - Parties privées et connexion
**Date:** 2026-10-09

## Story

En tant que joueur de la run hebdo,
je veux voir le nom de mon slot dans les infos de connexion de la page du jeu hebdo,
afin de me connecter sans le chercher, comme sur les autres pages.

## Contexte

La story 17.29 a mis le nom du slot en tête des infos de connexion via `ConnectionFields` (carte hebdo, page de
progression hebdo, parties privées, événements). La page d'un jeu hebdo (`/runs-hebdo/jeu/{slug}`,
`weekly-run-game-client.tsx`) avait son propre bloc écrit à la main (Host, Port, Password) et n'a pas été
touchée : pas de nom du slot, pas d'adresse complète, mot de passe affiché en clair. Jean (2026-10-09) : les
weekly n'ont pas l'info de connexion au slot.

## Critères d'acceptation

1. La page d'un jeu hebdo affiche les infos de connexion avec `ConnectionFields`, comme la carte hebdo : nom du
   slot en tête (`myEntry.slotName`), adresse complète, hôte, port, mot de passe masqué.
2. Le reste du bloc est inchangé : affiché quand le serveur tourne, relance quand il est en pause.
3. Plus aucun bloc de connexion écrit à la main dans le front.
4. Gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 3) - `ConnectionFields` dans `weekly-run-game-client.tsx` ; retrait du `CopyButton` local.
- [x] **Task 2** (AC 4) - Gates.

## Dev Agent Record

### File List

- `frontend/src/features/weekly-runs/weekly-run-game-client.tsx`
