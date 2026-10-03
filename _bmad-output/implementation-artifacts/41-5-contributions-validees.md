# Story 41.5: Contributions validées

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que membre qui écrit ou corrige un tutoriel d'installation,
je veux recevoir des pelles en or quand ma contribution est validée,
afin que l'aide apportée à l'association compte.

## Contexte

Les contributions de tutoriels (epic 31, story 31.7) sont validées par un admin depuis
`/admin/moderation/contributions` (`ModerateGameTutorialContribution::approve`). **Décision 4 de l'epic (Jean,
2026-10-03)** : le montant est libre, choisi par l'admin à chaque validation.

## Critères d'acceptation

1. La validation d'une contribution accepte un montant de pelles en or pour son auteur, de 0 à 1 000 ; 0 ou
   absent : pas de pelles (une petite correction peut ne rien rapporter).
2. Le crédit (motif `contribution_reward`, auteur = l'admin, clé `contribution:{id}`) et la validation sont écrits
   dans la même transaction : pas de contribution validée sans ses pelles, ni l'inverse. Le libellé nomme le jeu.
3. Un admin ne peut pas se payer sa propre contribution (403, comme pour un crédit admin, 41.1 AC9) ; il peut la
   valider sans pelles.
4. L'auteur est notifié du crédit après la transaction (la notification de validation existante ne change pas).
5. Front : la fenêtre de confirmation « Approuver » propose le montant (vide = 0) ; le motif a un libellé.
6. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4) - Montant dans la commande de modération et l'endpoint ; tests fonctionnels.
- [x] **Task 2** (AC 5) - Front ; tests.
- [x] **Task 3** (AC 6) - Gates.

## Notes techniques

- GameSelection dépend de Wallet (`RecordPelleMovement`), jamais l'inverse.
- Le crédit passe avec `byAdmin` : c'est une décision d'admin, comme un crédit depuis la fiche membre.

## Dev Agent Record

- `ModerateGameTutorialContribution::approve()` prend `$pelles` (0 à 1 000) ; le crédit `contribution_reward` et la
  sauvegarde de la contribution passent dans la même transaction (fermeture de `RecordPelleMovement`).
- `POST /api/v1/admin/game-contributions/{id}/approve` accepte `pelles` ; absent = 0.
- Front : champ « Pelles en or pour l'auteur » dans la fenêtre « Approuver ».
