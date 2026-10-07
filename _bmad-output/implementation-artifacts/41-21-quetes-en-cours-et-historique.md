# Story 41.21: Quêtes en cours et historique séparés

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux voir mes quêtes en cours et l'historique de mes semaines dans deux blocs distincts,
afin de lire l'un sans l'autre.

## Contexte

La 41.17 a ajouté l'historique des 4 semaines précédentes, replié sous les quêtes de la semaine. Jean (2026-10-06) :
« Tu peux séparer les quêtes en cours et historiques stp ? »

## Critères d'acceptation

1. Le portefeuille montre « Quêtes de la semaine » (quêtes et coffre), puis un bloc à part « Historique des
   quêtes », ouvert : chaque semaine (quêtes faites sur servies, coffre ouvert, pelles gagnées) et le total des
   pelles gagnées sur la période.
2. Pas de bloc d'historique tant qu'aucune semaine passée n'a servi de quête.
3. `pnpm gates` vert ; vérifié dans l'app.

## Dev Agent Record

- `frontend/src/features/wallet/weekly-quests.tsx` (+ test) : `WeeklyQuestsPanel` rend les deux blocs ;
  `QuestHistory` devient une section. Aucun changement d'API.
