# Story 40.5: La notification de déblocage mène à la progression du slot

**Status:** review
**Epic:** 40 - Déblocage BK notifié et notifications push
**Date:** 2026-10-08

## Story

En tant que joueur d'une partie privée,
je veux que la notification « Tu n'es plus bloqué dans ... » m'ouvre la progression du slot débloqué,
afin de voir tout de suite quels checks sont redevenus faisables.

## Contexte

Depuis la 40.1, la notification (cloche et push navigateur) mène à la page de la partie, `/runs/{runId}`. La
progression d'un slot est à `/runs/{runId}/progression/{slotIndex}`, où `slotIndex` est le numéro Archipelago du
slot (clé de l'état des joueurs du bridge). `SlotBlockTracker` le connaît au moment du déblocage mais ne le
transmettait pas.

## Critères d'acceptation

1. **Numéro du slot** : le job de déblocage et la charge utile de la notification portent `slotIndex`, le numéro
   Archipelago du slot débloqué.
2. **Cloche** : le clic mène à `/runs/{runId}/progression/{slotIndex}`.
3. **Push navigateur** : même lien.
4. **Rétrocompatibilité** : une notification ou un job sans `slotIndex` (envoyés avant la mise en prod) mène à
   `/runs/{runId}` comme avant ; sans `runId`, à `/compte/parties`.
5. Les co-joueurs vont sur la même page : la progression du slot débloqué, qu'ils jouent aussi.
6. Gates verts ; tests (tracker, handler, push, `hrefFor`).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `NotifySlotUnblockedJob::slotIndex` (nullable, en dernier) ; tracker ; handler.
- [x] **Task 2** (AC 2, 3, 4) - `hrefFor` ; `PushMessageFactory`.
- [x] **Task 3** (AC 6) - Tests ; gates.

## Dev Agent Record

### File List

- `api/src/Sessions/Application/Message/NotifySlotUnblockedJob.php`
- `api/src/Sessions/Application/Service/SlotBlockTracker.php`
- `api/src/Sessions/Application/Handler/NotifySlotUnblockedJobHandler.php`
- `api/src/Community/Application/Support/PushMessageFactory.php`
- `api/tests/Unit/Sessions/SlotBlockTrackerTest.php`, `api/tests/Unit/Sessions/NotifySlotUnblockedJobHandlerTest.php`,
  `api/tests/Unit/Community/PushMessageFactoryTest.php`, `api/tests/Functional/SlotUnblockedNotificationTest.php`
- `frontend/src/features/community/notification-center.tsx` (+ test)
