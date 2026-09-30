# Story 40.1: Déblocage BK détecté et notifié dans le site

**Status:** review
**Epic:** 40 - Déblocage BK notifié et notifications push
**Date:** 2026-09-29

## Story

En tant que joueur d'une partie privée,
je veux être prévenu quand mon slot sort d'un BK,
afin de reprendre la partie dès qu'un check redevient faisable, sans surveiller la page.

## Contexte

Voir `_bmad-output/planning-artifacts/epics/epic-40-deblocage-bk-et-notifications-push.md`. Le BK n'existe
aujourd'hui que dans le front. Le `players-push` du bridge arrive à chaque changement d'état et après chaque
recalcul d'atteignabilité ; `RecordPlayersSnapshot` a l'ancien et le nouvel état sous la main.

## Critères d'acceptation

1. **BK** (règle unique, testée, identique au badge du front) : `reachable_now === 0`, `checks_done <
   checks_total`, objectif non atteint (`client_status !== 30`), slot ni released ni collecté, slot joueur (le
   slot observateur « Bridge » est ignoré). `reachable_now` absent ou `null` : état inconnu, ni BK ni déblocage.
2. **Début de BK** : au premier `players-push` où un slot est BK, le site retient l'heure de début (horloge
   injectée), par session et par slot ; un push suivant toujours BK ne la change pas.
3. **Déblocage** : quand un slot retenu comme BK revient avec `reachable_now > 0`, il est débloqué ; si le BK a
   duré **au moins 2 minutes**, une notification part, sinon rien. Dans les deux cas l'épisode est clos. Un état
   inconnu garde l'épisode ouvert ; un objectif atteint, un release ou la fin de la partie le clôt sans
   notification.
4. **Une seule notification par épisode**, même si des pushs se répètent ou arrivent en double.
5. **Parties privées seulement** : la session doit appartenir à une run privée qui n'est pas une seed importée ;
   ailleurs (événement, hebdo) aucune donnée de BK n'est retenue.
6. **Destinataires** : le joueur du slot et ses co-joueurs, chacun une fois.
7. **Notification du site** de type `slot_unblocked` (payload : run, titre de la run, nom du slot, nombre de
   checks accessibles) : dans la cloche « Tu n'es plus bloqué dans *Titre* (*Slot*) : N checks accessibles »,
   lien vers la page de la run privée.
8. **Jamais bloquant** : la détection ne fait qu'écrire l'état de BK ; la notification part après commit, en
   asynchrone. Une erreur de notification n'empêche jamais l'enregistrement du snapshot.
9. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Domaine : règle `SlotBlockState` (BK / débloqué / inconnu) depuis une entrée du
      payload `players`, testée en unitaire.
- [x] **Task 2** (AC 2, 3, 4) - Entité + migration de l'épisode de BK par (session, index de slot) avec son
      début ; transitions dans le domaine (ouvrir, garder, clore avec ou sans notification), testées.
- [x] **Task 3** (AC 5, 8) - Branchement dans `RecordPlayersSnapshot` : sessions de runs privées non importées,
      message Messenger `NotifySlotUnblocked` dispatché après commit.
- [x] **Task 4** (AC 6, 7) - Handler : résolution slot vers utilisateur + co-joueurs, `Notifier::notify()`.
- [x] **Task 5** (AC 7) - Front : `messageFor` / `hrefFor` du type `slot_unblocked`, test.
- [x] **Task 6** (AC 9) - Tests fonctionnels (push BK, push débloqué, doublons, BK trop court, session d'événement)
      et gates.

## Dev Agent Record

- **Domaine** (`Sessions`) : `SlotBlockState` (Blocked, Unblocked, Unknown, Settled), `SlotBlockDecision`,
  `SlotBlockRule` (`stateOf`, `isPlayerSlot`, `decide`, seuil `MIN_BLOCKED_SECONDS = 120`), entité
  `SlotBlockEpisode` (table `session_slot_block`, clé `session_id` + `slot_index`, `blocked_since`) et son
  dépôt. Migration `Version20260929100000`.
- **Application** : `SlotBlockTracker::track()` appelé par `RecordPlayersSnapshot` après l'enregistrement du
  snapshot (erreur journalisée, jamais bloquante) ; runs privées non importées seulement ; les slots
  released viennent de `SessionSlot::isWasReleased()`. Les épisodes sont écrits puis les
  `NotifySlotUnblockedJob` dispatchés (transport `async`).
- **Handler** `NotifySlotUnblockedJobHandler` : joueur du slot (`registrationId`) + co-joueurs
  (`SlotCoPlayer`), dédoublonnés ; notification `slot_unblocked` (`runId`, `runTitle`, `slotName`,
  `reachableNow`) ; erreur journalisée.
- **Front** : `messageFor` / `hrefFor` du type `slot_unblocked` (lien `/runs/{runId}`, repli `/compte/parties`).
- Aucun changement du bridge : le `players-push` part déjà après chaque recalcul (story 9.23).

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `SlotBlockRuleTest`, `SlotBlockTrackerTest`, `NotifySlotUnblockedJobHandlerTest` | classes absentes | 20 verts |
| `SlotUnblockedNotificationTest` (fonctionnel, via `players-push`) | - | 2 verts |
| `notification-center.test.ts` (4 cas `slot_unblocked`) | 4 échecs | verts |

Gates : `composer gates` (2482 tests), `pnpm gates` (602 tests, build) verts.
