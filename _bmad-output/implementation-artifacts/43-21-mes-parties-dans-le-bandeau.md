# Story 43.21: « Mes parties » dans le bandeau du site

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que membre connecté,
je veux atteindre mes parties en un clic depuis n'importe quelle page,
afin de ne pas devoir les chercher dans le menu de mon avatar.

## Contexte

Retour de Jean (2026-10-11). La liste des parties n'est qu'au fond du menu de l'avatar (bureau) ou en bas du menu
(téléphone) : deux clics, et peu visible, alors que c'est la page la plus utile à un joueur.

## Critères d'acceptation

1. **Bureau** : un membre connecté voit « Mes parties » en tête du bandeau, avant « Runs hebdos », vers `/runs`.
   L'entrée est allumée sur `/runs` et ses pages, et sur `/compte/parties`. Un visiteur ne la voit pas.
2. **Compteur** : à côté du libellé, le nombre de parties en cours (ni terminées, ni annulées, ni rangées dans les
   archives), sous la clé de requête de la liste (`personal-runs`, `mine`) ; rien quand il n'y en a aucune.
3. **Téléphone** : « Mes parties » ouvre la liste du menu, avec le même compteur ; le bouton en bas du menu disparaît.
4. Le menu de l'avatar garde son entrée.
5. Gates verts ; tests (compte des parties en cours, entrée allumée).

## Tasks / Subtasks

- [x] **Task 1** (AC 2) - `liveRunCount()` et `MyRunsCount` ; tests.
- [x] **Task 2** (AC 1, 3, 4) - Entrée « Mes parties » du bandeau et du menu téléphone ; tests.
- [x] **Task 3** (AC 5) - Gates ; vérification en local.

## Dev Agent Record

- `personal-runs/my-runs-count.tsx` : `liveRunCount()` (parties possédées et rejointes, hors terminées, annulées et
  archivées) et `MyRunsCount`, la pastille, sous la clé `["personal-runs", "mine"]` de la liste.
- `public-shell.tsx` : `MyRunsNavLink` (membre connecté seulement) en tête du bandeau et du menu téléphone ; le bouton
  « Mes parties » quitte le bas du menu téléphone.
