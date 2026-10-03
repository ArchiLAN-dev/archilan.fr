# Story 41.6: Quêtes hebdomadaires

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que membre,
je veux des objectifs de la semaine qui rapportent des pelles,
afin d'avoir une raison de revenir jouer, sans que le site paie chaque check.

## Contexte

**Décision 4 de l'epic (Jean, 2026-10-03)** : trois quêtes par semaine, plafond 100 pelles par semaine.
Le « point dur » de la revue s'applique : une quête ne compte que des parties réellement jouées (checks, goal),
pour qu'un second compte n'en tire rien.

## Critères d'acceptation

1. Trois quêtes, les mêmes pour tous, renouvelées chaque lundi 00:00 (heure de Paris) :
   - **Atteindre un goal** (40 pelles) : un slot que le membre joue (propriétaire ou co-joueur) atteint son goal
     dans la semaine, en session ou en hebdo ;
   - **Jouer avec quelqu'un de nouveau** (30 pelles) : le membre fait au moins un check dans une session où un
     autre membre, avec qui il n'avait jamais fait de check dans une même session, en fait aussi un dans la semaine ;
   - **Faire une hebdo** (30 pelles) : le membre lance une tentative d'hebdo dans la semaine et y fait au moins un
     check (ou atteint le goal).
2. Chaque quête rapporte une fois par semaine au plus (clé `quest:{semaine}:{quête}:{membre}`) : 100 pelles par
   semaine au maximum, en or (motif `quest_reward`).
3. Une tâche planifiée (toutes les heures) crédite les quêtes accomplies ; un compte banni ou supprimé ne reçoit rien.
   Le membre est notifié de chaque quête accomplie.
4. Page « Mon portefeuille » : les quêtes de la semaine, faites ou non, avec leur gain et la date du prochain
   renouvellement.
5. Le motif `quest_reward` a un libellé.
6. Gates : `composer gates` et `pnpm gates` verts.

## Notes techniques

- Les quêtes sont lues en SQL (fil de session, slots, hebdos), comme les statistiques (epic 42) : requête dans
  Wallet (Application/Query + DBAL en Infrastructure), qui lit les tables de Sessions et WeeklyRuns sans en
  dépendre.
- « Jamais joué ensemble » : aucune session antérieure à la semaine où les deux ont chacun fait un check.
- La semaine des quêtes est celle de Paris (les membres la vivent ainsi), contrairement aux tranches UTC des
  statistiques.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - `WeeklyQuest`, `QuestWeek`, requête des quêtes accomplies ; tests.
- [x] **Task 2** (AC 3) - `AwardWeeklyQuests` (semaine en cours et précédente), message planifié à :40 ; tests.
- [x] **Task 3** (AC 4, 5) - `GET /api/v1/me/quests`, panneau « Quêtes de la semaine » du portefeuille, libellé.
- [x] **Task 4** (AC 6) - Gates.

## Dev Agent Record

- `WeeklyQuest` (barème 40/30/30), `QuestWeek` (lundi 00:00 Paris, clé `2026-W40`), `WeeklyQuestsQueryInterface` /
  `DbalWeeklyQuestsQuery`, `AwardWeeklyQuests` (clé `quest:{semaine}:{quête}:{membre}`), `MyWeeklyQuests`.
- `DbalSlotCheckSource` est repris à l'identique de la story 42.2 (pile de l'epic 42) : les deux branches portent
  le même fichier, la fusion ne fera pas de conflit.
