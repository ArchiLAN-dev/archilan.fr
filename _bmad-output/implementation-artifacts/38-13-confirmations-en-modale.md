# Story 38.13: Confirmations de la santé des apworlds en fenêtre modale

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-29

## Story

En tant qu'administrateur d'ArchiLAN,
je veux que « Ignorer » un incident et « Forcer » une version candidate demandent confirmation dans une fenêtre,
afin de ne plus avoir un bloc de confirmation qui se déplie dans la carte, encadré dans l'encadré.

## Contexte

Constat de Jean (2026-09-29) sur `/admin/sante-apworlds` : « Ignorer » déplie un faux dialogue
(`role="dialog"` sans en être un : pas de focus piégé, pas d'Échap) dans la carte de l'incident, bordé dans la
carte bordée. Même motif pour « Forcer » une version candidate sur la page d'un jeu
(`apworld-candidate-status.tsx`). La story 39.11 a posé le socle `ConfirmDialog` (Radix AlertDialog).

## Critères d'acceptation

1. « Ignorer » un incident ouvre une `ConfirmDialog` (titre, explication inchangée : l'incident ne se rouvre pas
   tant que le jeu sert cette version, une nouvelle version sera surveillée ; bouton « Ignorer quand même »). Plus
   aucun bloc de confirmation dans la carte.
2. « Forcer » une version candidate ouvre une `ConfirmDialog` (texte inchangé, bouton « Forcer la mise en
   service », ton danger). Plus aucun bloc de confirmation dans l'encadré.
3. Annuler ou Échap ferme sans rien faire ; confirmer ferme la fenêtre et lance l'action.
4. `pnpm gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - `apworld-incident-list.tsx` sur `ConfirmDialog`, test.
- [x] **Task 2** (AC 2, 3) - `apworld-candidate-status.tsx` sur `ConfirmDialog`, test.
- [x] **Task 3** (AC 4) - Gates.

## Dev Agent Record

- `apworld-incident-list.tsx` et `apworld-candidate-status.tsx` : le bloc `role="dialog"` déplié dans la carte
  est remplacé par la `ConfirmDialog` partagée (story 39.11) ; textes inchangés, « Forcer » en ton danger.
- Tests : `ConfirmDialog` remplacée par un stub visible (le vrai rend dans un portail, absent du rendu
  statique) pour vérifier titre, texte, bouton, ton, fermée par défaut, et plus aucun `role="dialog"` en ligne.
  Rouges (2) puis verts.

Gates : `pnpm gates` vert (603 tests, build).
