# Story 43.15: Duel hebdo entre amis

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.8 (bloc « Tes amis cette semaine »)

## Story

En tant que joueur,
je veux défier un ou plusieurs amis sur l'hebdo de la semaine,
afin d'avoir une raison de jouer cette semaine et de comparer nos résultats.

## Contexte

Inspiré des *Friend Quests* Duolingo et des défis de clubs Strava. Les hebdos sont des courses solo :
`WeeklyRunEntry` (`completionTimeSeconds`, `goalReachedAt`), inscription `OptInToWeeklyRun`, objectif
`RecordWeeklyGoal`, fin de semaine `StopWeeklyRunsMessageHandler`. Le fil d'activité ne connaît que deux types
(`ActivityEntry::TYPE_RUN_FINISHED`, `TYPE_FRIENDSHIP`).

## Critères d'acceptation

1. Depuis la page de l'hebdo en cours, défier un ou plusieurs amis acceptés (5 max) ; chacun reçoit une
   notification `weekly_duel` et accepte ou refuse. Accepter ne l'inscrit pas à l'hebdo : la page le propose.
2. Le duel accepté affiche un mini-classement des participants (statut, temps) sur la page de l'hebdo et dans
   `/compte` jusqu'à la fin de la semaine ; gagne le meilleur temps à objectif atteint ; personne à l'objectif =
   pas de gagnant.
3. À la fin de l'hebdo (`StopWeeklyRunsMessageHandler`, après commit), chaque participant reçoit le résultat
   (« Tu bats *X* de 12 min ») et une entrée d'activité `weekly_duel` est publiée (audience : amis).
4. Un blocage annule le duel pour la paire concernée.
5. Plafond : 3 duels créés par joueur et par semaine.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Domaine/Migration** : `WeeklyDuel` (hebdo, créateur, participants et réponses, résultat).
- [ ] **Application** : création, réponse, résolution branchée sur la fin de l'hebdo.
- [ ] **Community** : nouveau type `ActivityEntry` `weekly_duel` et son rendu dans le fil.
- [ ] **Front** : bouton « Défier », mini-classement, résultat, `messageFor` / `hrefFor`.
- [ ] Tests (gagnant, égalité, aucun objectif, refus, blocage, plafond) et gates.

## Notes

- Variante coopérative écartée de cette story : « objectif commun de *N* checks à deux sur la semaine ».
  Agréger les checks de plusieurs parties est coûteux ; à reprendre en story séparée si les duels prennent.
