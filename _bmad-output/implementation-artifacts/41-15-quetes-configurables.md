# Story 41.15: Quêtes hebdomadaires configurables

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-05

## Story

En tant qu'admin,
je veux créer nos propres quêtes hebdo, les laisser tourner au hasard chaque semaine ou les imposer à une semaine
précise,
afin que les quêtes changent et collent à la vie de l'association.

En tant que membre,
je veux voir où j'en suis de chaque quête,
afin de savoir ce qu'il me reste à faire.

## Contexte

La 41.6 a livré trois quêtes codées en dur (`WeeklyQuest`), les mêmes chaque semaine, plafond 100 pelles. Décisions
de Jean (2026-10-05) :

- une quête se compose de **plusieurs objectifs**, tous à remplir, chacun avec sa barre de progression ;
- les objectifs portent sur des **types configurables pris dans un catalogue**, comme les faits des succès ;
- chaque lundi, un **tirage aléatoire** parmi les quêtes « dans le tirage » ; le **nombre de quêtes par semaine
  est réglable** (3 par défaut) ;
- une quête peut être **hors tirage** et **épinglée à une semaine précise** ; une quête épinglée **prend une des
  places** de la semaine, le tirage complète le reste ;
- l'admin peut aussi **imposer** une quête à la semaine en cours ou à venir, à la place d'une quête tirée ;
- le **plafond de 100 pelles par semaine est supprimé** : la récompense de chaque quête suffit (décision 4 de
  l'epic 41 révisée).

## Critères d'acceptation

1. **Catalogue des types d'objectifs** (code, comme `AchievementMetricCatalog`), chacun compté sur la semaine de
   Paris et seulement sur des parties réellement jouées :
   - `goals` : goals atteints (slots joués, propriétaire ou co-joueur, en session ou en hebdo) ;
   - `checks` : checks faits (fil de session, plus le total des tentatives d'hebdo lancées dans la semaine, qui
     n'ont pas de fil) ;
   - `weeklies` : tentatives d'hebdo lancées avec au moins un check ou le goal ;
   - `newPartners` : membres différents avec qui on joue pour la première fois (règle de la 41.6) ;
   - `sessions` : sessions différentes où l'on fait au moins un check ;
   - `distinctGames` : jeux différents où l'on fait au moins un check.
2. **Quête** : titre, description courte facultative, récompense en pelles en or (1 à 1 000), 1 à 5 objectifs
   (type + cible, cible de 1 à 10 000, un type au plus une fois), « dans le tirage » oui/non, retirée ou non. Une
   quête retirée ne sort plus au tirage et ne s'épingle plus ; les semaines où elle a déjà été servie restent
   intactes.
3. **Semaine** : ses quêtes sont figées dans la base au premier besoin (lecture ou paiement) : d'abord les
   épinglées, puis un tirage au hasard sans doublon parmi les quêtes actives dans le tirage, jusqu'au nombre réglé.
   Le tirage d'une semaine a lieu une fois ; s'il n'y a pas assez de quêtes, la semaine en a moins.
4. **Page admin `/admin/quetes`** :
   - liste des quêtes (titre, objectifs, récompense, tirage, retirée), création, modification, retrait et
     rétablissement ; formulaire des objectifs avec le catalogue ;
   - réglage du nombre de quêtes par semaine (1 à 10) ;
   - la semaine en cours et les 8 suivantes : leurs quêtes (épinglées, tirées, ou « tirage à venir ») ; épingler
     une quête à une semaine, retirer une quête d'une semaine (en cours ou à venir), la remplacer. Une modale
     confirme tout changement sur la semaine en cours.
5. **Paiement** : la tâche horaire paie chaque quête dont tous les objectifs sont remplis, une fois par semaine et
   par membre (clé `quest:{semaine}:{quête}:{membre}`), en or, motif `quest_reward`, avec notification. Libellé
   du mouvement : « Quête : {titre} ». Un compte banni ou supprimé ne reçoit rien. La semaine écoulée reste payée
   comme avant.
6. **Reprise de l'existant** : les trois quêtes actuelles deviennent des quêtes en base (mêmes identifiants que les
   clés de la 41.6, pour ne pas repayer la semaine en cours), dans le tirage ; la semaine en cours (2026-W41) est
   figée avec elles.
7. **Membre** (portefeuille, « Quêtes de la semaine ») : chaque quête avec sa récompense, son état (à faire,
   accomplie, payée) et **une barre de progression par objectif** (« 1 / 2 goals »), plafonnée à la cible.
8. Gates `composer gates` et `pnpm gates` verts ; tests du tirage, de l'épinglage, de la progression, du paiement
   et de la page admin.

## Notes techniques

- Contexte `Wallet`. `QuestDefinition` (entité) : objectifs en JSON validés par le domaine (`QuestObjective` :
  type du catalogue + cible). `QuestWeekEntry` (semaine, quête, origine `pinned|drawn`, position), unique
  (semaine, quête). Réglage du nombre : table clé/valeur `wallet_setting`.
- Le tirage est une fonction pure (`QuestDraw`) qui reçoit les candidates et un générateur aléatoire injecté
  (pas de `rand()` dans le domaine) ; la persistance garantit qu'une semaine n'est tirée qu'une fois (contrainte
  unique + relecture).
- `WeeklyQuestsQueryInterface` devient une lecture de **compteurs** par (type, membre) sur la semaine ; accompli =
  chaque objectif atteint. La même lecture sert au paiement et aux barres.
- `WeeklyQuest` (enum) disparaît ; la migration crée les trois quêtes avec les identifiants `reach_a_goal`,
  `play_with_someone_new`, `play_a_weekly`.
- Ancien plafond : rien à faire en code (il découlait des trois récompenses fixes).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 3) - Domaine : catalogue, `QuestDefinition`, `QuestObjective`, `QuestDraw` ; tests unitaires.
- [x] **Task 2** (AC 3, 6) - Migration (tables, reprise des 3 quêtes, semaine W41), repositories, figeage d'une
  semaine.
- [x] **Task 3** (AC 1, 5, 7) - Compteurs hebdo en DBAL, paiement, `GET /api/v1/me/quests` avec progression ; tests.
- [x] **Task 4** (AC 4) - API admin (quêtes, réglage, semaines, épinglage) ; tests fonctionnels.
- [x] **Task 5** (AC 4, 7) - Front : page `/admin/quetes`, lien du menu admin, barres du portefeuille ; tests.
- [x] **Task 6** (AC 8) - Gates verts (vérification dans l'app non faite : serveurs de dev arrêtés).

## Dev Agent Record

### Notes

- Une semaine n'est tirée qu'une fois : `quest_week_draw` (clé primaire = la semaine) est réclamée par un
  `INSERT ... ON CONFLICT DO NOTHING`, que seul le premier lecteur gagne. Les semaines à venir ne sont tirées qu'à
  leur début ; avant, la page admin montre leurs épinglées et le nombre de places laissées au tirage.
- Le tirage (`QuestDraw`) mélange les candidates avec un `Random\Randomizer` injecté (moteur sûr par défaut, graine
  fixe dans les tests).
- L'admin planifie la semaine en cours et les 8 suivantes (`QuestCalendar`) ; retirer une quête retire aussi ses
  épinglages sur les semaines pas encore tirées. Remplacer = épingler à la place d'une quête (même position).
- La migration reprend les trois quêtes de la 41.6 sous leurs anciens identifiants et fige 2026-W40 et 2026-W41 avec
  elles : rien n'est repayé ni retiré.

### File List

- `api/migrations/Version20261005200000.php`
- `api/config/services.yaml`
- `api/src/Wallet/Domain/` : `Entity/QuestDefinition.php`, `Entity/QuestWeekEntry.php`, `Entity/QuestWeekDraw.php`,
  `Entity/WalletSetting.php`, `Enum/QuestMetric.php`, `Enum/QuestWeekOrigin.php`, `ValueObject/QuestObjective.php`,
  `ValueObject/QuestWeek.php`, `Service/QuestDraw.php`, `Repository/QuestRepositoryInterface.php` ; `Enum/WeeklyQuest.php`
  supprimé
- `api/src/Wallet/Application/` : `Command/AwardWeeklyQuests.php`, `Command/ManageQuests.php`,
  `Command/WrittenQuest.php`, `Query/MyWeeklyQuests.php`, `Query/QuestAdminQuery.php`,
  `Query/WeeklyQuestsQueryInterface.php`, `Service/QuestWeekPlanner.php`, `Support/QuestCalendar.php`
- `api/src/Wallet/Infrastructure/` : `Doctrine/DoctrineQuestRepository.php`, `Query/DbalWeeklyQuestsQuery.php`
- `api/src/Wallet/Presentation/Controller/AdminQuestController.php`
- `api/tests/` : `Functional/WeeklyQuestsTest.php`, `Functional/AdminQuestTest.php`, `Unit/Wallet/QuestWeekTest.php`,
  `Unit/Wallet/QuestDefinitionTest.php`
- `frontend/src/features/wallet/` : `weekly-quests.tsx` (+ test), `admin-quests-api.ts`, `admin-quests-page.tsx` (+ test)
- `frontend/src/app/(admin)/admin/quetes/page.tsx`, `frontend/src/components/admin-shell.tsx`
