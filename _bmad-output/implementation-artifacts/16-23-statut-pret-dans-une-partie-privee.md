# Story 16.23: Statut « Prêt » des joueurs d'une partie privée

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-10-17

## Story

En tant que joueur d'une partie privée pas encore lancée,
je veux pouvoir indiquer que je suis prêt,
afin que le propriétaire sache quand tout le monde a fini de choisir ses jeux et sa config, sans avoir à demander.

En tant que propriétaire,
je veux voir qui est prêt et être prévenu quand tout le monde l'est,
afin de lancer la génération au bon moment.

## Contexte

Demande de Jean (2026-10-17). Aujourd'hui le propriétaire n'a aucun signal : il voit les jeux choisis (« N jeux »,
notification `run_games_chosen` de la story 43.28) mais pas si le joueur a fini sa config. Le statut est
**indicatif** : il ne bloque jamais le lancement.

À ne pas confondre avec `!ready` d'Archipelago (commande du serveur de jeu, refusée par le bridge depuis la story
9.56) : ce statut vit uniquement sur le site, avant la génération.

## Critères d'acceptation

1. **Se dire prêt.** Tant que la partie n'est pas lancée, chaque participant (propriétaire compris) peut passer
   « Prêt » puis revenir en arrière, pour lui seul. Personne ne peut changer le statut d'un autre.
2. **Qui peut se dire prêt.** Un participant qui a au moins un jeu, **ou** qui est co-joueur d'un slot d'un autre
   participant de cette partie. Un participant sans jeu et sans co-jeu ne voit pas le bouton (ou le voit
   désactivé avec la raison) ; l'API refuse (422).
3. **Perte du statut.** Le statut « Prêt » d'un participant retombe quand :
   - il ajoute ou retire un jeu de sa sélection (le simple réordonnancement ne compte pas) ;
   - il modifie la config YAML d'un de ses slots ;
   - il n'a plus ni jeu ni co-jeu (retiré comme co-joueur du dernier slot qu'il partageait).
   Les actions qui ne viennent pas du joueur ne le font pas retomber : réglages de la partie par le propriétaire,
   mise à jour d'apworld appliquée par le système (story 38.7).
4. **Affichage dans « Participants ».** Le propriétaire **et** tous les joueurs voient, à côté de chaque
   participant prêt, un badge « Prêt ». Un participant qui ne l'est pas n'a **aucun** badge (jamais de
   « Pas prêt »).
5. **Compteur.** Près du bouton de lancement (et en tête de l'onglet Participants), un résumé « 3 / 4 prêts »,
   puis « Tout le monde est prêt » quand le compte est plein. Le dénominateur compte les participants qui peuvent
   se dire prêts (AC2) ; les autres n'y figurent pas.
6. **Notification au propriétaire.** Quand le dernier participant comptabilisé passe « Prêt » (le compteur atteint
   le total), le propriétaire reçoit une notification `run_all_ready` (« Tout le monde est prêt dans « <partie> » »),
   cloche + Web Push par défaut, réglable dans ses préférences comme les autres notifications de partie (épique 43).
   Pas de notification si le propriétaire est lui-même ce dernier joueur. Pas de nouvelle notification tant que le
   compteur n'est pas retombé puis remonté.
7. **Lancement.** Le lancement reste possible quel que soit le compteur. Une fois la partie lancée, le bouton
   disparaît, ainsi que le compteur. Si la génération échoue et que la partie revient en brouillon, les statuts sont
   conservés (les jeux n'ont pas changé).
8. **Temps réel.** Le badge et le compteur se mettent à jour pour les autres participants sans recharger la page
   (même mécanisme que les jeux choisis).
9. Gates verts (`composer gates`, `pnpm gates`).

## Tâches

- [x] **Task 1** (AC1, AC3) - Domaine : `readyAt` (nullable) sur `RunParticipant` + migration ; méthodes
  `markReady(now)` / `clearReady()` ; `replaceSlots` remet `readyAt` à null si l'ensemble des `slotId`/`gameId`
  change (pas sur un simple réordonnancement) ; `submitSlotPlayerYaml` le remet à null.
- [x] **Task 2** (AC1, AC2) - Application : commande `MarkRunParticipantReady` / `ClearRunParticipantReady`
  (draft seulement, participant de la partie, éligibilité AC2 via les co-joueurs `SlotCoPlayer`), route
  `PUT/DELETE /api/runs/{id}/ready`.
- [x] **Task 3** (AC3) - Retrait de co-joueur (`RunSlotCoPlayers`) : retombée du statut du co-joueur retiré s'il
  n'a plus ni jeu ni co-jeu.
- [x] **Task 4** (AC4, AC5) - Lecture : `ready: bool` et `canBeReady: bool` par participant dans la réponse de la
  partie ; `readyCount` / `readyTotal`.
- [x] **Task 5** (AC6) - Notification `run_all_ready` : message dispatché après commit quand le compteur atteint le
  total, préférence par défaut cloche + push (`NotificationPreferenceService`), texte dans `PushMessageFactory`,
  libellé dans l'écran des préférences.
- [x] **Task 6** (AC4, AC5, AC7, AC8) - Front : badge « Prêt » dans `ParticipantList`
  (`personal-run-detail-page.tsx`), bouton « Je suis prêt » / « Plus prêt » pour soi, compteur près du
  lancement et en tête de l'onglet, invalidation temps réel.
- [x] **Task 7** (AC9) - Tests : domaine (retombée sur ajout/retrait/YAML, pas sur réordonnancement), commande
  (draft seulement, éligibilité co-joueur, 422), notification (une seule fois, pas pour soi-même), front (badge
  absent si pas prêt, compteur).

## Dev Notes

- `RunParticipant` (`api/src/PersonalRuns/Domain/Entity/RunParticipant.php`) : le propriétaire est lui aussi un
  participant. `replaceSlots` conserve déjà ce qui ne doit pas bouger sur un réordonnancement (preflight,
  `needsReview`) : même logique pour `readyAt`.
- Co-joueurs : `SlotCoPlayer` (`api/src/Sessions/Domain/Entity/SlotCoPlayer.php`), service
  `RunSlotCoPlayers` (`api/src/PersonalRuns/Application/Service/RunSlotCoPlayers.php`).
- Notifications de partie existantes : `run_games_chosen` (`NotifyRunGamesChosenJobHandler`), préférences dans
  `NotificationPreferenceService`, push dans `PushMessageFactory`. Effets de bord après commit (règle racine).
- Front : `ParticipantList` affiche déjà `PlayerBadges` et « N jeux » ; le badge « Prêt » s'ajoute à côté, sans
  équivalent négatif.

## Dev Agent Record

### Completion Notes

- `run_participant.ready_at` (migration `Version20261017100000`). `replaceSlots` le remet à null quand l'ensemble
  des slots change (ajout ou retrait), pas sur un réordonnancement ; `submitSlotPlayerYaml` quand le YAML change.
- Éligibilité : `RunReadiness` (domaine, pur) nourri par `RunReadinessReader` (co-joueurs des slots de la partie).
  La lecture n'affiche « prêt » que pour un joueur éligible, donc badge et compteur concordent toujours ; un
  co-joueur retiré de son dernier slot perd en plus son statut (`RunSlotCoPlayers::replace` et `forget`).
- `PUT|DELETE /api/v1/runs/{id}/ready` : 404 hors participant, 409 partie lancée, 422 rien à jouer.
- Notification `run_all_ready` (cloche + push, Discord par défaut comme les autres moments de partie) : envoyée
  seulement sur le passage qui complète le compte, jamais quand c'est le propriétaire qui le complète ; le
  handler relit le compte (un joueur revenu en arrière entre-temps la fait taire).
- Front : `run-readiness.tsx` (badge, compteur, bouton) ; compteur près du lancement et dans l'en-tête des
  Participants ; la page d'une partie en brouillon à plusieurs se rafraîchit toutes les 15 s.
- Non vérifié dans le navigateur : session de test non connectée. Route vérifiée sur l'API locale (401 sans
  session), migration appliquée à la base de dev.

### File List

- `api/migrations/Version20261017100000.php`
- `api/src/PersonalRuns/Domain/Entity/RunParticipant.php`
- `api/src/PersonalRuns/Domain/Service/RunReadiness.php`
- `api/src/PersonalRuns/Application/Support/RunReadinessReader.php`
- `api/src/PersonalRuns/Application/Command/SetRunParticipantReady.php`, `SetRunParticipantReadyOutcome.php`
- `api/src/PersonalRuns/Application/Message/NotifyRunAllReadyJob.php`
- `api/src/PersonalRuns/Application/Handler/NotifyRunAllReadyJobHandler.php`
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php`, `RunSlotCoPlayers.php`, `PersonalRunGameSelection.php`
- `api/src/PersonalRuns/Presentation/Controller/RunReadyController.php`
- `api/src/Community/Application/Service/NotificationPreferenceService.php`
- `api/src/Community/Application/Support/PushMessageFactory.php`
- `api/config/packages/messenger.yaml`
- `api/tests/Functional/RunReadyTest.php`, `api/tests/Unit/PersonalRuns/RunReadinessTest.php` (+ constructeurs
  mis à jour dans `PersonalRunDraftsGetTest`, `PersonalRunDraftsListMineTest`, `PersonalRunGameSelectionPreflightTest`,
  liste des préférences dans `FriendActivityTest`)
- `frontend/src/features/personal-runs/run-readiness.tsx`, `run-readiness.test.tsx`, `types.ts`, `personal-runs-api.ts`,
  `personal-run-detail-page.tsx`
- `frontend/src/features/auth/notification-preferences-card.tsx`
- `frontend/src/features/community/notification-center.tsx`, `notification-content.ts`

## Change Log

| Date | Changement |
|---|---|
| 2026-10-17 | Créée et implémentée (demande de Jean). Status: review. |
