# Story 41.16: Coffre de la semaine et statistiques des quêtes

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux un bonus quand je termine toutes les quêtes de la semaine,
afin d'avoir une raison de finir la dernière.

En tant qu'admin,
je veux voir combien de membres réussissent chaque quête et ce qu'elle coûte,
afin de régler leur difficulté et leur récompense.

## Contexte

Suite de la 41.15 (quêtes configurables). Propositions validées par Jean le 2026-10-06 (« Go faire tes propals ») :
d'abord le coffre hebdo et les statistiques par quête, les autres pistes viennent dans les stories 41.17 à 41.19.

## Critères d'acceptation

1. **Coffre de la semaine** : un membre qui accomplit toutes les quêtes servies dans une semaine (au moins une)
   reçoit en plus la récompense du coffre, une fois par semaine (clé `quest-chest:{semaine}:{membre}`), en or, motif
   `quest_reward`, libellé « Coffre de la semaine », avec notification. Payé par la même tâche horaire que les
   quêtes, pour la semaine en cours et la précédente. Un compte banni ou supprimé ne reçoit rien.
2. **Réglage** : la récompense du coffre se règle dans l'onglet Semaines (0 à 1 000 pelles, 0 = pas de coffre ;
   50 par défaut).
3. **Portefeuille** : sous les quêtes, le coffre avec sa récompense et sa progression (« 2 / 3 quêtes »), payé ou
   non ; rien quand le coffre vaut 0.
4. **Statistiques par quête** (onglet Types) : nombre de semaines où la quête a été servie, membres qui l'ont
   réussie la dernière fois qu'elle a été servie, pelles versées au total.
5. **Semaines passées** (onglet Semaines) : les 4 semaines précédentes, chacune avec ses quêtes, le nombre de
   membres qui ont réussi chacune, le nombre de coffres et les pelles versées.
6. Gates verts ; tests du coffre (payé une fois, pas de coffre sans toutes les quêtes, réglage à 0), des
   statistiques et des écrans.

## Notes techniques

- Le coffre se calcule dans `AwardWeeklyQuests` à partir des mêmes compteurs que les quêtes : accomplir chaque
  quête servie, pas « avoir été payé de chaque quête » (une quête ajoutée en cours de semaine compte aussi).
- Les statistiques se lisent dans le registre (`pelle_movement`), par les clés `quest:{semaine}:{quête}:{membre}` et
  `quest-chest:{semaine}:{membre}` : rien de nouveau à stocker.
- Réglage dans `wallet_setting` (clé `quest_chest_reward`).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Réglage du coffre, paiement dans `AwardWeeklyQuests` ; tests.
- [x] **Task 2** (AC 3) - `GET /api/v1/me/quests` avec le coffre ; panneau du portefeuille ; tests.
- [x] **Task 3** (AC 4, 5) - Lecture des paiements par semaine et par quête ; vue admin ; tests.
- [x] **Task 4** (AC 6) - Gates, vérification dans l'app.

## Dev Agent Record

### Notes

- Le coffre réutilise le motif `quest_reward` (libellé « Coffre de la semaine ») avec sa propre clé
  `quest-chest:{semaine}:{membre}` : aucun nouveau motif ni libellé côté front, et `rewardedQuests` (préfixe
  `quest:`) ne le confond pas avec une quête.
- Réglages : `PUT /api/v1/admin/quests-settings` accepte `questsPerWeek` et/ou `chestReward` (un seul appel
  applicatif `ManageQuests::changeSettings`, chaque valeur facultative, toutes vérifiées avant d'écrire).
- Semaines passées payées avant la 41.15 : sans entrée servie, leurs quêtes reviennent du registre (les anciennes
  clés portent les identifiants repris par la migration de la 41.15).
- « Cette semaine » affiche le gain maximal coffre compris.

### File List

- `api/src/Wallet/Domain/Entity/WalletSetting.php`, `api/src/Wallet/Domain/Repository/QuestRepositoryInterface.php`
- `api/src/Wallet/Infrastructure/Doctrine/DoctrineQuestRepository.php`, `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- `api/src/Wallet/Application/Command/AwardWeeklyQuests.php`, `api/src/Wallet/Application/Command/ManageQuests.php`
- `api/src/Wallet/Application/Query/MyWeeklyQuests.php`, `api/src/Wallet/Application/Query/QuestAdminQuery.php`,
  `api/src/Wallet/Application/Query/WeeklyQuestsQueryInterface.php`
- `api/src/Wallet/Presentation/Controller/AdminQuestController.php`
- `api/tests/Functional/WeeklyQuestsTest.php`, `api/tests/Functional/AdminQuestTest.php`
- `frontend/src/features/wallet/` : `weekly-quests.tsx` (+ test), `admin-quests-api.ts`, `admin-quest-weeks.tsx`,
  `admin-quests-page.tsx` (+ test)
