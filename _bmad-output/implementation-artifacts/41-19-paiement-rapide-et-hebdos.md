# Story 41.19: Quêtes payées en quelques minutes, checks d'hebdo exacts

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux être payé vite quand je termine une quête, et que mes checks d'hebdo comptent vraiment,
afin de voir le résultat de ma partie sans attendre et sans perdre des checks.

## Contexte

Dernière des améliorations du système de quêtes proposées après la 41.15 (« Go faire tes propals », Jean,
2026-10-06). En préparant la story, un défaut de la 41.6 est apparu : `weekly_entries.checks_total` n'est rempli
qu'au goal (`RecordSlotGoal`). Une tentative d'hebdo sans goal n'avait donc jamais de check aux yeux des quêtes :
« Faire une hebdo » (au moins un check) ne payait que ceux qui la finissaient, et l'objectif « checks » ignorait
les hebdos en cours. Les sessions d'hebdo écrivent pourtant leur fil (`session_feed_event`, par l'identifiant de
session de la tentative) : il suffit d'attribuer ces checks au joueur de la tentative.

## Critères d'acceptation

1. **Paiement en quelques minutes** : la tâche qui paie les quêtes (et le coffre, et annonce la semaine) passe
   toutes les 5 minutes au lieu d'une fois par heure. Le portefeuille dit « créditée dans quelques minutes ».
2. **Checks d'hebdo exacts** : les checks d'une tentative d'hebdo se lisent dans le fil de sa session, à leur date
   (une tentative lancée la semaine précédente compte ses checks de cette semaine) et sont attribués au joueur de
   la tentative. Repli : une tentative sans aucun fil enregistré compte son `checks_total`, comme avant.
3. **« Faire une hebdo »** : une tentative lancée dans la semaine compte dès son premier check (fil) ou son goal.
   Même règle pour les membres actifs de l'annonce du lundi.
4. Gates verts ; tests (tentative sans goal avec checks au fil, tentative à cheval sur deux semaines, repli sans
   fil, planification toutes les 5 minutes).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Planification ; libellé du portefeuille ; test.
- [x] **Task 2** (AC 2, 3) - Checks et hebdos par le fil de session ; tests.
- [x] **Task 3** (AC 4) - Gates.

## Dev Agent Record

### Notes

- Paiement toutes les 5 minutes plutôt qu'un déclenchement à chaque check : la tâche relit la semaine entière, ce
  qui reste léger à l'échelle de l'association, sans câbler Sessions vers Wallet.
- Une tentative d'hebdo lancée jusqu'à deux semaines avant compte encore ses checks de la période (une hebdo peut
  être jouée à cheval sur deux semaines de quêtes).
- Non vérifiable en local : la base de dev n'a qu'une tentative d'hebdo, sans fil. À confirmer en prod que les
  sessions d'hebdo poussent bien leur fil (sinon le repli `checks_total` s'applique, comme avant).

### File List

- `api/src/Schedule.php`, `api/tests/Unit/ScheduleTest.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`, `api/tests/Functional/WeeklyQuestsTest.php`
- `frontend/src/features/wallet/weekly-quests.tsx` (+ test)
