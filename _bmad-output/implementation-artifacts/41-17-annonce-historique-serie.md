# Story 41.17: Annonce du lundi, historique et série des quêtes

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux savoir quand les nouvelles quêtes arrivent, revoir mes semaines passées, et être récompensé quand
j'enchaîne les semaines complètes,
afin de garder le rythme d'une semaine à l'autre.

## Contexte

Deuxième des améliorations du système de quêtes proposées après la 41.15 (« Go faire tes propals », Jean,
2026-10-06). La 41.16 a ajouté le coffre de la semaine (toutes les quêtes faites).

## Critères d'acceptation

1. **Annonce du lundi** : une fois par semaine, quand la semaine a ses quêtes, les membres actifs (au moins un
   check, ou une tentative d'hebdo, dans les 4 semaines précédentes) reçoivent une notification « Nouvelles quêtes
   de la semaine » (nombre de quêtes, gain maximal coffre compris) qui mène au portefeuille. Envoyée par la tâche
   horaire des quêtes ; une semaine n'est annoncée qu'une fois ; pas d'annonce pour une semaine sans quête. Pas de
   push navigateur (la liste des notifications poussées reste un choix explicite, story 40.2).
2. **Historique** (portefeuille) : les 4 semaines précédentes, chacune avec le nombre de quêtes faites sur le
   nombre servi, le coffre ouvert ou non, et les pelles gagnées par les quêtes.
3. **Série** (succès) : deux nouveaux faits pour les règles de succès : « Quêtes hebdo réussies (total) » et
   « Plus longue série de semaines avec le coffre ». Les succès sont recalculés par la tâche horaire existante.
4. Gates verts ; tests de l'annonce (une fois, membres actifs seulement, rien sans quête), de l'historique, des
   faits de succès et des écrans.

## Notes techniques

- Annonce : `AwardWeeklyQuests` annonce la semaine en cours après l'avoir payée ; la semaine annoncée est retenue
  dans `wallet_setting` (`quests_announced_week`). Nouveau type de notification `quests_renewed`.
- Membres actifs et historique se lisent en SQL (fil de session, hebdos, registre), comme les compteurs.
- Faits de succès : un fournisseur de faits dans Community (`AchievementMetricProviderInterface`) lit le registre
  (`pelle_movement`, clés `quest:` et `quest-chest:`) par une requête DBAL de Community, sans dépendre de Wallet.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Membres actifs, annonce dans la tâche horaire, type de notification ; tests.
- [x] **Task 2** (AC 2) - Historique dans `GET /api/v1/me/quests` ; affichage ; tests.
- [x] **Task 3** (AC 3) - Faits de succès ; tests.
- [x] **Task 4** (AC 4) - Front (notification, historique), gates, vérification dans l'app.

## Dev Agent Record

### Notes

- **Correctif de la 41.15** trouvé par les tests de l'annonce : une semaine atteinte alors qu'aucune quête n'est
  tirable était figée vide, et une quête créée ensuite ne la remplissait jamais. Le planificateur ne réclame plus
  le tirage tant qu'aucune quête n'est tirable (une semaine épinglée seulement reste servie).
- L'annonce est marquée avant l'envoi des notifications : un échec en cours de route ne renvoie jamais deux fois.
- Membres actifs : un check dans une session, ou une tentative d'hebdo avec un check ou le goal, dans les 4
  semaines avant le lundi.
- Faits de succès : `questsCompleted` et `questChestStreak` (semaines ISO consécutives, passage d'année compris).
  Les succès eux-mêmes se créent dans l'admin des succès avec ces faits ; le recalcul horaire (:45) passe après le
  paiement des quêtes (:40).

### File List

- `api/src/Community/Domain/Entity/Notification.php`, `api/src/Community/Domain/AchievementMetricCatalog.php`
- `api/src/Community/Application/Query/QuestAchievementsQueryInterface.php`,
  `api/src/Community/Application/Support/QuestMetricProvider.php`,
  `api/src/Community/Infrastructure/Dbal/DbalQuestAchievementsQuery.php`
- `api/src/Wallet/Domain/Entity/WalletSetting.php`, `api/src/Wallet/Domain/Repository/QuestRepositoryInterface.php`
- `api/src/Wallet/Infrastructure/Doctrine/DoctrineQuestRepository.php`, `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- `api/src/Wallet/Application/Command/AwardWeeklyQuests.php`, `api/src/Wallet/Application/Query/MyWeeklyQuests.php`,
  `api/src/Wallet/Application/Query/WeeklyQuestsQueryInterface.php`, `api/src/Wallet/Application/Service/QuestWeekPlanner.php`
- `api/tests/Functional/WeeklyQuestsTest.php`, `api/tests/Unit/Community/QuestMetricProviderTest.php`
- `frontend/src/features/community/notification-center.tsx` (+ test), `frontend/src/features/wallet/weekly-quests.tsx` (+ test)
