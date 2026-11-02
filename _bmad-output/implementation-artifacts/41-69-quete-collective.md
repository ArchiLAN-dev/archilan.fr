# Story 41.69: Quête collective de la semaine

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant que membre,
je veux un objectif commun à toute la communauté (« 500 checks à nous tous »),
afin de jouer pour le groupe et pas seulement pour moi.

## Contexte

Suite de la 41.64. Proche de l'idée en réserve « Cagnotte communautaire » de l'epic 41, mais sans dépense : on
additionne ce que la communauté joue. Dépend de l'instantané de la 41.67.

## Critères d'acceptation

1. **Quête collective** : une quête peut être marquée collective. Elle n'entre jamais dans le tirage : l'admin
   l'épingle à une semaine, une au plus par semaine. Elle ne compte ni pour le coffre (41.16), ni dans le nombre de
   quêtes de la semaine, ni dans `maxPelles` ; la notification et Discord la présentent à part.
2. **Compte commun** : son objectif unique porte sur une métrique additive (goals, checks, parties, temps de jeu,
   items envoyés, primes remportées), comptée **sans doublon** : un check, un goal ou une minute d'un slot joué à
   plusieurs compte une fois, une partie compte une fois quel que soit le nombre de joueurs. Ce n'est donc pas la
   somme des compteurs par membre, mais une requête agrégée dédiée. Le jeu d'un membre banni ou supprimé compte
   dans la jauge (il a eu lieu) ; ce membre n'est pas payé.
3. **Récompense** : si l'objectif commun est atteint dans la semaine, chaque membre dont la part atteint le seuil
   fixé par l'admin reçoit la récompense une fois (clé `quest-group:{semaine}:{quête}:{membre}`, motif
   `quest_reward`, notification). Le seuil est obligatoire, sans défaut à 1 : l'aide du formulaire propose environ
   2 % de l'objectif, pour qu'un compte bis qui fait un check ne touche rien.
4. **Jauge membre** : sur la page des quêtes et le portefeuille, « 312 / 500 checks », la part du membre (« dont 24
   de toi ») et le seuil s'il ne l'a pas atteint.
5. **Jauge publique** : sur la page communauté (`/communaute`), sans nom de membre. Elle lit une valeur calculée
   par la tâche horaire des quêtes et stockée ; aucune lecture publique ne déclenche le tirage
   (`QuestWeekPlanner::served()` écrit) ni le calcul des compteurs.
6. **Lecteurs du registre** : `payments()` (stats admin) et `earnedBy` (portefeuille) reconnaissent la clé
   `quest-group:`.
7. **Stats admin** : semaines servies, réussie ou non, total atteint, contributeurs payés.
8. Gates verts ; tests (dédoublonnage d'un slot partagé, seuil, paiement unique, échec, banni compté et non payé,
   lecture publique sans écriture, exclusion du coffre et du tirage) et des écrans.

## Notes techniques

- `quest_definition` : `collective` (bool, faux par défaut) et `min_contribution` (int) ; table ou réglage pour la
  valeur de la jauge (semaine, quête, total, mis à jour à chaque passage de la tâche). Migration.
- `QuestDraw` ignore les quêtes collectives ; le coffre et le compte des quêtes de la semaine aussi.
- La part d'un membre reprend les compteurs par membre existants ; seul le total commun a sa requête dédiée.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Domaine, migration, requête agrégée, service hors tirage ; tests.
- [x] **Task 2** (AC 3, 6) - Paiement des contributeurs, lecteurs du registre ; tests.
- [x] **Task 3** (AC 4, 5, 7) - Jauge stockée, jauges membre et publique, admin, Discord ; tests jest.
- [ ] **Task 4** (AC 8) - Gates, vérification dans l'app.

## Dev Agent Record

### Implementation

- `QuestDefinition.collective` (bool) et `min_contribution`, `QuestWeekEntry.min_contribution` (gelé avec la
  quête), `group_total` et `group_counted_at` (migration `Version20261031120003`). Une quête collective a un seul
  objectif sur une métrique additive (`QuestMetric::isCollective()` : goals, checks, parties, temps de jeu, items
  envoyés, primes), sans palier, et une part minimale obligatoire entre 1 et la cible (`quest_collective_invalid`,
  `quest_contribution_invalid`). Le caractère collectif se choisit à la création et ne change plus (les semaines
  servies le lisent ainsi) ; elle n'est jamais dans le tirage (`isDrawable()` faux, `inDraw` forcé à faux).
- `QuestWeekPlanner` : `served()` laisse la quête collective de côté, `collective()` la donne, le tirage ne la compte
  pas dans le nombre de quêtes de la semaine ; `recordGroupTotal()` garde le total sur l'entrée. `pin` refuse une
  deuxième quête collective dans la même semaine (`quest_collective_taken`), sauf en remplacement.
- Total commun : `WeeklyQuestsQueryInterface::groupTotal()`, une requête par métrique sans passer par les joueurs
  (un check, un goal, une minute ou une partie compte une fois ; un item entre deux membres une fois). Le jeu d'un
  membre banni ou supprimé compte. La part d'un membre reprend `counts()`.
- Paiement (`AwardWeeklyQuests::awardCollective`) à chaque passage horaire, semaine en cours et précédente : total
  recalculé et stocké, puis si l'objectif est atteint, `quest-group:{semaine}:{quête}:{membre}` pour chaque membre
  dont la part atteint le seuil (motif `quest_reward`, notification). Hors coffre et hors `maxPelles`.
- Registre : `payments()` compte `quest-group:` comme une quête (contributeurs payés, pelles) ; `earnedBy` compte
  ses pelles dans la semaine ; nouveau `groupPaid()`. Le succès « Quêtes hebdo réussies » ne lit que `quest:`.
- Notification `quests_renewed` : champ `collective` (titre) ; Discord : champ « Quête collective · titre · +N
  pelles » après le coffre, avec la part qui paie.
- Membre (`/me/quests`) : `collective` (total en direct, part du membre, seuil, payé) ; bloc « Quête collective »
  après le coffre. Historique : la quête collective ne compte pas dans les quêtes servies.
- Public : `GET /api/v1/community/collective-quest` (`CommunityQuestGauge`), lit le total stocké de la semaine en
  cours, sans tirage ni gel ni comptage, sans nom de membre ; section sur `/communaute`.
- Admin : case « Quête collective » à la création (métriques restreintes, un objectif, pas de paliers ni de
  tirage), part minimale avec suggestion d'environ 2 % de la cible ; badge « Collective » ; total commun et cible
  dans les semaines (en cours, réussie, pas atteinte) ; « N contributeurs récompensés » dans les stats.

### Écarts

- La jauge membre lit le total en direct (comme sa part), la jauge publique le total stocké par la tâche horaire.
- Les stats admin donnent le total atteint dans l'onglet des semaines (4 semaines passées), pas dans la ligne de
  stats de la quête.

### Tests

- `QuestCollectiveTest` : slot partagé compté une fois et seuil, paiement unique, coffre et nombre de quêtes sans la
  quête collective, échec sans paiement, lecture publique sans tirage ni gel, banni compté et non payé,
  notification et admin, validation et épinglage HTTP, total par métrique et par cible.
- `DiscordBotQuestAnnouncementChannelTest::testTheCollectiveQuestComesApartAndOutOfTheMostToEarn`.
- jest : `collective-quest.test.tsx`, `notification-center.test.ts`, `notification-content.test.tsx`.
