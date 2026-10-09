# Story 41.33: Une hebdo compte pour les quêtes quand elle est terminée

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-08

## Story

En tant que membre,
je veux que l'objectif « hebdos » demande de terminer l'hebdo,
afin qu'une quête « Faire une hebdo » récompense une partie jouée jusqu'au bout, pas un simple lancement.

## Contexte

Depuis la 41.19, une tentative d'hebdo compte pour l'objectif `weeklies` dès son premier check (ou son goal),
dans la semaine de son lancement. Jean (2026-10-08) : il faut atteindre le goal de l'hebdo. Une quête paie
toujours une fois par semaine et par membre (clé `quest:{semaine}:{quête}:{membre}`), rien ne change de ce côté.

## Critères d'acceptation

1. **Goal requis** : l'objectif `weeklies` compte les tentatives d'hebdo dont le goal est atteint. Une tentative
   avec des checks mais sans goal ne compte plus.
2. **Semaine du goal** : une tentative compte dans la semaine où son goal est atteint (`goal_reached_at`), comme
   l'objectif `goals`, et non plus dans celle de son lancement. Une tentative lancée la semaine précédente et
   terminée cette semaine compte cette semaine ; une tentative ne compte jamais deux fois.
3. **Libellé** : la métrique se nomme « Hebdos terminées » (éditeur de quêtes admin).
4. **Inchangés** : l'objectif `checks` (checks d'hebdo lus au fil, 41.19), les membres actifs de l'annonce du
   lundi (un check suffit) et l'étape d'accueil « Jouer ta première hebdo » (un check suffit).
5. Gates verts ; tests (tentative avec checks sans goal, tentative à cheval sur deux semaines).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - `DbalWeeklyQuestsQuery::weeklies()` sur `goal_reached_at` ; doc de l'interface.
- [x] **Task 2** (AC 3) - Libellé de `QuestMetric::Weeklies`.
- [x] **Task 3** (AC 5) - Tests fonctionnels ; gates.

## Dev Agent Record

### Notes

- Le titre de la quête (« Faire une hebdo ») est une donnée éditable par les admins, pas du code : à renommer
  depuis l'admin si souhaité.
- Le calcul se fait à la lecture : un membre dont l'hebdo de la semaine est commencée mais pas terminée repasse
  de 1 à 0. Les pelles déjà versées restent acquises.

### File List

- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- `api/src/Wallet/Application/Query/WeeklyQuestsQueryInterface.php`
- `api/src/Wallet/Domain/Enum/QuestMetric.php`
- `api/tests/Functional/WeeklyQuestsTest.php`
