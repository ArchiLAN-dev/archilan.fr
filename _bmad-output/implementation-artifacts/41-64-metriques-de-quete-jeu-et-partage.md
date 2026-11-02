# Story 41.64: Métriques de quête - partage et temps de jeu

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant qu'admin,
je veux écrire des quêtes sur les items envoyés, le temps de jeu et la découverte de jeux,
afin de varier les quêtes de la semaine au-delà des goals et des checks.

## Contexte

Demande de Jean (2026-11-01) : « créer plus de quêtes hebdos ». Les quêtes (41.15) se limitent à 6 métriques
(`QuestMetric`), alors que les succès en lisent une vingtaine. Cette story ajoute celles qui se lisent dans les
sessions jouées et qui ont déjà une requête côté succès (contexte Community), bornées à la semaine.

Comme pour les métriques existantes, les hebdos ne comptent pas dans un objectif ciblé (41.18), et un compte bis
qui joue vraiment reste un risque accepté, comme pour `newPartners` : ces métriques ne lisent que du jeu réel.

## Critères d'acceptation

1. **`itemsSent` « Items envoyés à d'autres membres »** : événements `item-received` du flux
   (`session_feed_event`) dont l'expéditeur est un slot du membre et le destinataire un slot réclamé par un autre
   membre ; hors rafales de release et de collect (mêmes règles que `DbalItemsFromOthersQuery`, vues côté
   expéditeur). Un item entre deux slots du même membre, ou vers un slot non réclamé, ne compte pas.
2. **`itemsReceived` « Items reçus d'autres membres »** : le pendant exact de `itemsSent` (expéditeur = slot
   réclamé par un autre membre). Différence assumée avec le succès `FACT_ITEMS_FROM_OTHERS`, qui compte aussi les
   slots non réclamés : la quête ne paye que les échanges entre membres.
3. **`playMinutes` « Temps de jeu »** : minutes connectées sur la semaine (`session_slot_play_span`), une plage à
   cheval sur deux semaines comptant pour sa part dans chacune. Garde-fou contre l'AFK : seules comptent les
   plages d'un slot qui a fait au moins un check dans la semaine. Un slot joué à plusieurs compte son temps pour
   chacun de ses joueurs (même règle que le succès). Les hebdos n'ont pas de plages : elles ne comptent pas, et
   l'aide du formulaire le dit.
4. **`newGames` « Jeux jamais joués »** : jeux différents sur lesquels le membre a fait au moins un check dans la
   semaine (slot de session, ou hebdo via `weekly_entries` → `weekly_runs` → `weekly_templates.game_id`) et sur
   lesquels il n'avait jamais fait de check avant le début de la semaine.
5. **Fenêtre** : chaque métrique ne compte que la semaine de la quête (`QuestWeek`, lundi 00:00 au lundi suivant).
6. **Ciblage** : `itemsSent`, `itemsReceived` et `playMinutes` acceptent une cible jeu ou événement (41.18). Le jeu
   d'un objectif sur les items est celui **du slot du membre** (l'expéditeur pour `itemsSent`, le destinataire
   pour `itemsReceived`). `newGames` n'accepte pas de cible.
7. **Admin** : les nouvelles métriques apparaissent dans le formulaire avec une aide d'une ligne ; `playMinutes` se
   saisit en heures (pas de 0,5) et se stocke en minutes.
8. **Affichage** : barre de progression, libellés et unités dans le portefeuille, la page des quêtes, la
   notification de renouvellement et l'annonce Discord ; le temps s'affiche en heures (« 2 h 30 / 6 h »), y compris
   dans `QuestAnnouncement::describe`.
9. Gates verts ; tests de chaque compteur (fenêtre, release et collect, slot du même membre, slot non réclamé,
   plage à cheval, plage sans check, jeu déjà joué avant la semaine, hebdo pour `newGames`), du formulaire et de
   l'affichage.

## Notes techniques

- Ajouter les cas dans `QuestMetric`, `QuestObjective::SCOPABLE`, `ManageQuests::objectives` et le `match` de
  `DbalWeeklyQuestsQuery::counts`.
- La cible d'un objectif sur les items porte sur l'alias du slot du membre : `scope()` est aujourd'hui câblé sur
  `slot.` ; le paramétrer par alias.
- Le Wallet n'importe pas Community : les règles de release et de collect de `DbalItemsFromOthersQuery` passent
  dans un fragment partagé (`Shared/Infrastructure/Dbal/DbalFoundItemSource`), lu par le succès et par la quête.
- `playMinutes` : `LEAST(last_seen_at, fin) - GREATEST(started_at, début)` sur les plages qui chevauchent la semaine.
- `MAX_TARGET` (10 000) suffit en minutes (166 h). Pas de migration : les objectifs sont du JSON.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5) - Cas `QuestMetric` et compteurs SQL ; test de parité ; tests fonctionnels.
- [x] **Task 2** (AC 6) - Ciblage par alias de slot, validation ; tests.
- [x] **Task 3** (AC 7, 8) - Formulaire (heures), affichages membre, notification, Discord ; tests jest et unitaires.
- [x] **Task 4** (AC 9) - Gates, vérification dans l'app avec une quête de chaque sorte.

## Dev Agent Record

### Notes

- `QuestMetric` gagne `itemsSent`, `itemsReceived`, `playMinutes`, `newGames`, plus `help()` (aide du formulaire,
  pour toutes les métriques), `isDuration()`, `describe()` et `hours()` (« 2 h 30 »).
- Règles release / collect extraites dans `DbalFoundItemSource` (fragment SQL partagé, comme `DbalSlotCheckSource`)
  au lieu d'une copie avec test de parité : `DbalItemsFromOthersQuery` le lit aussi, comportement inchangé (ses
  tests passent).
- Items : le slot du membre est aliasé `slot`, donc la cible jeu ou événement porte sur le slot du membre sans
  toucher à `scope()`. L'autre slot doit avoir un joueur qui n'est pas le membre.
- Temps de jeu : part de chaque plage dans la semaine, plages dédoublonnées par (plage, membre), seulement pour un
  slot qui a un check dans la semaine.
- Jeux jamais joués : premier check à vie par (membre, jeu), sessions par le flux, hebdos par le flux de leur
  session ou, sans flux, par `launched_at` quand `checks_total > 0`.
- Payloads : `help` et `duration` sur les métriques admin, `duration` sur les objectifs membre ; Discord passe par
  `QuestMetric::describe`.
- Front : `quest-amount.ts` (`formatHours`), barre « 2 h 30 / 6 h de jeu », saisie en heures par demi-heures dans le
  formulaire (changer entre durée et compte remet la cible à 1 h ou 1), aide sous chaque objectif.

### File List

- `api/src/Shared/Infrastructure/Dbal/DbalFoundItemSource.php` (nouveau)
- `api/src/Community/Infrastructure/Dbal/DbalItemsFromOthersQuery.php`
- `api/src/Wallet/Domain/Enum/QuestMetric.php`, `api/src/Wallet/Domain/ValueObject/QuestObjective.php`
- `api/src/Wallet/Application/Query/QuestAdminQuery.php`, `api/src/Wallet/Application/Query/MyWeeklyQuests.php`,
  `api/src/Wallet/Application/Support/QuestAnnouncement.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- `api/tests/Functional/QuestTradeAndPlayMetricsTest.php` (nouveau), `api/tests/Functional/WeeklyQuestsTest.php`,
  `api/tests/Unit/Wallet/QuestMetricTest.php` (nouveau), `api/tests/Unit/Wallet/DiscordBotQuestAnnouncementChannelTest.php`
- `frontend/src/features/wallet/quest-amount.ts` (+ test, nouveaux), `admin-quests-api.ts`, `admin-quests-page.tsx`
  (+ test), `weekly-quests.tsx` (+ test)
